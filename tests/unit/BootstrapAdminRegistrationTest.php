<?php
use PHPUnit\Framework\TestCase;

/**
 * Protected first-administrator bootstrap.
 *
 * These tests drive the real service against an
 * in-memory database double, so the decision (count, lock, re-check, insert)
 * is exercised rather than just grepped for.
 */
class BootstrapAdminRegistrationTest extends TestCase
{
    /** @var BootstrapRegDb */
    private $db;
    /** @var BootstrapRegCI */
    private $ci;

    public static function setUpBeforeClass(): void
    {
        $root = dirname(dirname(__DIR__));
        if (!defined('BASEPATH')) define('BASEPATH', $root.'/system/');
        if (!class_exists('CI_Model')) eval('class CI_Model {}');
        if (!function_exists('log_message')) eval('function log_message($l,$m){}');
        if (!function_exists('get_instance')) {
            eval('function &get_instance() { return $GLOBALS["__fake_ci"]; }');
        }
        if (!function_exists('marvy_public_id')) {
            require_once $root.'/application/helpers/marvy_helper.php';
        }
        require_once $root.'/application/libraries/SignedToken.php';
        require_once $root.'/application/libraries/Totp.php';
        require_once $root.'/application/libraries/EncryptionService.php';
        require_once $root.'/application/core/Env.php';
        require_once $root.'/application/libraries/AuthService.php';
    }

    protected function setUp(): void
    {
        // Skip the config/database lookup inside marvy_base_currency().
        $GLOBALS['__marvy_base_currency'] = 'NGN';
        putenv('VP_SETUP_TOKEN=0123456789abcdef0123456789abcdef');
        $this->db = new BootstrapRegDb();
        $this->ci = new BootstrapRegCI($this->db);
        $GLOBALS['__fake_ci'] = $this->ci;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__marvy_base_currency']);
        putenv('VP_SETUP_TOKEN');
    }

    private function service(array $settings = array())
    {
        $this->ci->settings = array_merge(array(
            'email_verification_required' => false,
            'pin_issue_at_signup'         => false,
        ), $settings);
        return new AuthService();
    }

    private function data($username = 'ada', $email = 'ada@example.test')
    {
        return array(
            'username' => $username,
            'email'    => $email,
            'password' => 'Correct-Horse-9!',
            'ip'       => '203.0.113.7',
        );
    }

    private function seedUser($role, $status = 'ACTIVE')
    {
        $this->db->seed_user(array('role' => $role, 'status' => $status));
    }

    private function claim($service, $data) {
        return $service->register_first_admin($data, '0123456789abcdef0123456789abcdef');
    }

    public function testCustomerCannotPromoteEvenOnEmptyDatabase() {
        $data = $this->data(); $data['role'] = 'SUPER_ADMIN';
        $res = $this->service()->register($data);
        $this->assertTrue($res['ok']);
        $this->assertSame('CUSTOMER', $this->db->users[0]['role']);
        $this->assertTrue($this->service()->bootstrap_admin_open());
    }

    public function testProtectedBootstrapIsAuditedAndClosesPermanently() {
        $svc = $this->service(array('email_verification_required' => true));
        $res = $this->claim($svc, $this->data());
        $this->assertTrue($res['ok']);
        $this->assertTrue($res['bootstrap']);
        $this->assertSame('SUPER_ADMIN', $this->db->users[0]['role']);
        $this->assertNotNull($this->db->users[0]['email_verified_at']);
        $this->assertCount(1, $this->db->wallets);
        $this->assertSame(1, $this->db->lock_taken);
        $this->assertSame(1, $this->db->lock_released);
        $this->assertContains('auth.bootstrap_admin', array_column($this->db->audit, 'action'));
        $this->assertFalse($this->claim($svc, $this->data('grace', 'grace@example.test'))['ok']);
        $this->db->users = array();
        $this->assertFalse($svc->bootstrap_admin_open());
    }

    public function testMissingOrWrongSecretRejected() {
        $svc = $this->service();
        $this->assertSame('BOOTSTRAP_DENIED', $svc->register_first_admin($this->data(), 'wrong')['error']);
        putenv('VP_SETUP_TOKEN=short');
        $this->assertSame('BOOTSTRAP_DENIED', $svc->register_first_admin($this->data(), 'short')['error']);
        $this->assertCount(0, $this->db->users);
    }

    public function testStaffAnyStatusClosesSetup() {
        foreach (array('ADMIN', 'STAFF', 'SUPER_ADMIN') as $role) {
            $this->db->users = array(); $this->seedUser($role, 'SUSPENDED');
            $res = $this->claim($this->service(), $this->data());
            $this->assertSame('BOOTSTRAP_CLOSED', $res['error']);
            $this->assertCount(1, $this->db->users);
        }
    }

    public function testConcurrentWinnerIsRespected() {
        $this->db->on_lock = function ($db) { $db->seed_user(array('role' => 'SUPER_ADMIN', 'status' => 'ACTIVE')); };
        $res = $this->claim($this->service(), $this->data());
        $this->assertSame('BOOTSTRAP_CLOSED', $res['error']);
        $this->assertCount(1, $this->db->users);
        $this->assertSame(1, $this->db->lock_released);
    }

    public function testBusyAndFailedTransactionsReleaseLocksCorrectly() {
        $this->db->lock_free = false;
        $this->assertSame('REGISTRATION_BUSY', $this->claim($this->service(), $this->data())['error']);
        $this->assertSame(0, $this->db->lock_released);
        $this->db->lock_free = true; $this->db->fail_transaction = true;
        $this->assertSame('REGISTRATION_FAILED', $this->claim($this->service(), $this->data())['error']);
        $this->assertSame(1, $this->db->lock_released);
    }

    public function testDuplicateCustomerEmailRejected() {
        $svc = $this->service(); $svc->register($this->data());
        $res = $svc->register($this->data('other', 'ada@example.test'));
        $this->assertSame('EMAIL_TAKEN', $res['error']);
    }

}

/** Result set double: row() / result(). */
class BootstrapRegResult
{
    private $rows;
    public function __construct(array $rows) { $this->rows = array_values($rows); }
    public function row() { return $this->rows[0] ?? null; }
    public function result() { return $this->rows; }
}

/** In-memory double for the query-builder subset register() touches. */
class BootstrapRegDb
{
    public $conn_id = 'fake';
    public $users = array();
    public $wallets = array();
    public $settings_rows = array();
    public $audit = array();
    public $lock_free = true;
    public $lock_taken = 0;
    public $lock_released = 0;
    public $fail_transaction = false;
    /** @var callable|null run when the bootstrap lock is granted */
    public $on_lock = null;

    private $wheres = array();
    private $in = array();
    private $last_id = 0;

    public function seed_user(array $row)
    {
        $row += array('id' => count($this->users) + 1, 'email' => 'seed'.count($this->users).'@example.test',
            'username' => 'seed'.count($this->users), 'user_code' => null, 'referral_code' => 'SEED'.count($this->users),
            'public_id' => 'seed-'.count($this->users), 'email_verified_at' => null);
        $this->users[] = $row;
        return $row['id'];
    }

    public function where($k, $v = null) { $this->wheres[] = array($k, $v); return $this; }
    public function where_in($k, $vals) { $this->in[$k] = $vals; return $this; }
    public function limit($n, $o = 0) { return $this; }
    public function order_by($c, $d = '') { return $this; }

    public function get($table)
    {
        $rows = $this->select($table);
        $this->reset();
        return new BootstrapRegResult($rows);
    }

    public function count_all_results($table = '')
    {
        $n = count($this->select($table));
        $this->reset();
        return $n;
    }

    public function insert($table, $data)
    {
        if ($table === 'users') {
            $data['id'] = count($this->users) + 1;
            $this->users[] = $data;
            $this->last_id = $data['id'];
        } elseif ($table === 'settings') {
            $this->settings_rows[] = $data;
        } elseif ($table === 'wallets') {
            $this->wallets[] = $data;
        }
        return true;
    }

    public function update($table, $data)
    {
        $source =& $this->rows_for($table);
        foreach ($source as $i => $r) {
            if ($this->matches($r)) {
                $source[$i] = array_merge($r, $data);
            }
        }
        $this->reset();
        return true;
    }

    public function insert_id() { return $this->last_id; }
    public function trans_start($test_mode = false) {}
    public function trans_complete() {}
    public function trans_status() { return !$this->fail_transaction; }

    public function query($sql, $binds = array())
    {
        if (strpos($sql, 'GET_LOCK') !== false) {
            if (!$this->lock_free) return new BootstrapRegResult(array((object)array('got' => 0)));
            $this->lock_free = false;
            $this->lock_taken++;
            if ($this->on_lock) {
                $cb = $this->on_lock;
                $this->on_lock = null;
                $cb($this);
            }
            return new BootstrapRegResult(array((object)array('got' => 1)));
        }
        if (strpos($sql, 'RELEASE_LOCK') !== false) {
            $this->lock_free = true;
            $this->lock_released++;
            return new BootstrapRegResult(array());
        }
        throw new RuntimeException('unexpected query: '.$sql);
    }

    /** Record an audit row the way Audit_log_model::record() would. */
    public function audit_row($action, $after) { $this->audit[] = array('action' => $action, 'after' => $after); }

    private function &rows_for($table)
    {
        if ($table === 'users') return $this->users;
        if ($table === 'wallets') return $this->wallets;
        if ($table === 'settings') return $this->settings_rows;
        $none = array();
        return $none;
    }

    private function matches(array $r)
    {
        foreach ($this->wheres as $pair) {
            $col = trim(preg_replace('/\s.*$/', '', (string)$pair[0]));
            if (!array_key_exists($col, $r) || (string)$r[$col] !== (string)$pair[1]) return false;
        }
        foreach ($this->in as $col => $vals) {
            if (!in_array($r[$col] ?? null, $vals, true)) return false;
        }
        return true;
    }

    private function select($table)
    {
        $out = array();
        foreach ($this->rows_for($table) as $r) {
            if ($this->matches($r)) $out[] = (object)$r;
        }
        return $out;
    }

    private function reset()
    {
        $this->wheres = array();
        $this->in = array();
    }
}

/** Stands in for CI's $this->load: assigns models/libraries as properties. */
class BootstrapRegLoader
{
    private $ci;
    public function __construct($ci) { $this->ci = $ci; }

    public function library($names, $params = null, $object_name = null)
    {
        foreach ((array)$names as $name) {
            $prop = strtolower($object_name ?: $name);
            if (isset($this->ci->$prop)) continue;
            if ($name === 'SignedToken') $this->ci->$prop = new SignedToken();
            elseif ($name === 'Totp') $this->ci->$prop = new Totp();
            elseif ($name === 'EncryptionService') $this->ci->$prop = new EncryptionService();
            else $this->ci->$prop = new stdClass();
        }
        return $this;
    }

    public function model($names)
    {
        foreach ((array)$names as $name) {
            if (isset($this->ci->$name)) continue;
            if ($name === 'User_model') $this->ci->$name = new BootstrapRegUserModel($this->ci->db);
            elseif ($name === 'Setting_model') $this->ci->$name = new BootstrapRegSettingModel($this->ci);
            elseif ($name === 'Audit_log_model') $this->ci->$name = new BootstrapRegAuditModel($this->ci->db);
            else $this->ci->$name = new stdClass();
        }
        return $this;
    }
}

class BootstrapRegCI
{
    public $db;
    public $load;
    public $input;
    public $settings = array();
    public $User_model;
    public $Setting_model;
    public $Audit_log_model;
    public $Permission_model;
    public $Role_model;
    public $session;
    public $signedtoken;
    public $totp;
    public $encryptionservice;
    public $pinservice;

    public function __construct($db)
    {
        $this->db = $db;
        $this->load = new BootstrapRegLoader($this);
        $this->input = new BootstrapRegInput();
    }
}

class BootstrapRegInput
{
    public function ip_address() { return '203.0.113.7'; }
    public function user_agent() { return 'PHPUnit'; }
}

class BootstrapRegSettingModel
{
    private $ci;
    public function __construct($ci) { $this->ci = $ci; }
    public function get($key, $default = null)
    {
        return array_key_exists($key, $this->ci->settings) ? $this->ci->settings[$key] : $default;
    }
}

class BootstrapRegAuditModel
{
    private $db;
    public function __construct($db) { $this->db = $db; }
    public function record($actor_id, $action, $resource, $resource_id = null, $before = null, $after = null,
                           $ip = null, $user_agent = null, $request_id = null)
    {
        $this->db->audit_row($action, $after);
        return true;
    }
}

class BootstrapRegUserModel
{
    private $db;
    public function __construct($db) { $this->db = $db; }

    public function find_by_email($email)
    {
        foreach ($this->db->users as $u) {
            if (strtolower((string)$u['email']) === strtolower((string)$email)) return (object)$u;
        }
        return null;
    }

    public function find_by_username($username)
    {
        foreach ($this->db->users as $u) {
            if ((string)$u['username'] === (string)$username) return (object)$u;
        }
        return null;
    }

    public function find_by_id($id)
    {
        foreach ($this->db->users as $u) {
            if ((int)$u['id'] === (int)$id) return (object)$u;
        }
        return null;
    }

    public function find_by_referral_code($code) { return null; }
}

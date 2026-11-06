<?php
use PHPUnit\Framework\TestCase;

/**
 * VTU catalogue sync (the "Sync services" button).
 *
 * The registry registers three VTU adapters, but only VTPASS used to answer
 * variations(). A STANDARD_VTU (or MOCK) provider therefore hit a dead-end
 * `This VTU adapter cannot list products.` before a single HTTP call was made,
 * from the admin button and from the cron's provider_sync job alike. Every
 * adapter now implements the catalogue contract, and these tests drive the
 * real sync path (ProviderSyncService → adapter → vtu_products) with a
 * scripted HTTP double — no network and no credentials.
 */
class VtuCatalogueSyncTest extends TestCase
{
    private static $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
        if (!defined('BASEPATH')) define('BASEPATH', self::$root.'/system/');
        if (!class_exists('CI_Model')) {
            // A separate line keeps the attribute a comment on PHP 7.4.
            eval("#[AllowDynamicProperties]\nclass CI_Model { public \$db; }");
        }
        if (!function_exists('get_instance')) {
            eval('function &get_instance() { return $GLOBALS["__fake_ci"]; }');
        }
        if (!function_exists('log_message')) eval('function log_message($l,$m){}');

        require_once self::$root.'/application/libraries/ProviderAdapterInterface.php';
        require_once self::$root.'/application/libraries/VtuProviderInterface.php';
        require_once self::$root.'/application/libraries/SecureHttpClient.php';
        require_once self::$root.'/application/libraries/MockVtuAdapter.php';
        require_once self::$root.'/application/libraries/StandardVtuAdapter.php';
        require_once self::$root.'/application/libraries/VtpassAdapter.php';
        require_once self::$root.'/application/libraries/Provider_manager.php';
        require_once self::$root.'/application/libraries/ProviderSyncService.php';
    }

    /** A provider row shaped like the one the admin create form produces. */
    private function provider($type = 'STANDARD_VTU', $retry_policy = null, $key = 'test-key')
    {
        return (object)array(
            'id'                => 7,
            'public_id'         => 'PROV0000000000000000000007',
            'name'              => 'VTU vendor',
            'api_url'           => 'https://vendor.test/api',
            'api_key_encrypted' => $key,
            'api_type'          => $type,
            'status'            => 'ACTIVE',
            'currency'          => 'NGN',
            'timeout_ms'        => 15000,
            'retry_policy'      => $retry_policy,
        );
    }

    private function body(array $variations, array $extra = array())
    {
        return json_encode(array_merge(array(
            'code'                => '000',
            'response_description'=> '000',
            'content'             => array('variations' => $variations),
        ), $extra));
    }

    private function variation_rows()
    {
        return array(
            array('variation_code' => 'mtn-1gb', 'name' => 'MTN 1GB / 30 days',
                  'variation_amount' => '1000.00', 'fixedPrice' => 'Yes'),
            array('variation_code' => 'mtn-2gb', 'name' => 'MTN 2GB / 30 days',
                  'variation_amount' => '2000', 'fixedPrice' => 'No'),
        );
    }

    /* -------------------- the capability contract -------------------- */

    public function testEveryRegisteredVtuAdapterCanListProducts()
    {
        foreach (array('MOCK', 'STANDARD_VTU', 'VTPASS') as $type) {
            $this->assertContains($type, Provider_manager::supported_types(Provider_manager::FAMILY_VTU));
        }
        foreach (array('MockVtuAdapter', 'StandardVtuAdapter', 'VtpassAdapter') as $class) {
            $this->assertTrue(method_exists($class, 'variations'),
                $class.' must implement variations()');
            $this->assertTrue(method_exists($class, 'catalogue_types'),
                $class.' must declare which types it can list');
            $types = $class::catalogue_types();
            $this->assertContains('DATA', $types);
            $this->assertContains('CABLE', $types);
            $this->assertContains('EXAM_PIN', $types);
            $this->assertTrue(is_subclass_of($class, 'VtuProviderInterface'));
        }
    }

    public function testTheDeadEndErrorMessageIsGone()
    {
        $src = file_get_contents(self::$root.'/application/libraries/ProviderSyncService.php');
        $this->assertStringNotContainsString('This VTU adapter cannot list products.', $src);
        $this->assertStringContainsString('variations()', $src,
            'the remaining guard must name the missing capability');
    }

    public function testTheVtuProviderInterfaceDocumentsTheCatalogueContract()
    {
        $src = file_get_contents(self::$root.'/application/libraries/VtuProviderInterface.php');
        $this->assertStringContainsString('catalogue_types()', $src);
        $this->assertStringContainsString('variations($network_code)', $src);
    }

    /* -------------------------- mock adapter ------------------------- */

    public function testMockVariationsAreDeterministicAndNormalised()
    {
        $adapter = new MockVtuAdapter();
        $first = $adapter->variations('DSTV');
        $second = $adapter->variations('DSTV');

        $this->assertTrue($first['ok']);
        $this->assertSame($first, $second, 'a sync must be repeatable');
        $this->assertCount(2, $first['variations']);
        $this->assertSame('dstv-padi', $first['variations'][0]['variation_code']);
        $this->assertSame('2150.00000000', $first['variations'][0]['amount']);
        $this->assertSame('dstv', $first['service_id']);
        // An unknown network is still answerable: adding a network must not
        // break the offline demo sync.
        $this->assertTrue($adapter->variations('SOME-NEW-NETWORK')['ok']);
    }

    /* ---------------------- standard VTU adapter --------------------- */

    public function testStandardVtuAdapterReadsAVtpassStyleVariationList()
    {
        $http = new VtuCatalogueSyncFakeHttp($this->body($this->variation_rows()));
        $adapter = new StandardVtuAdapter($this->provider(), $http);
        $res = $adapter->variations('MTN-DATA');

        $this->assertTrue($res['ok'], json_encode($res));
        $this->assertSame('MTN-DATA', $res['service_id']);
        $this->assertCount(2, $res['variations']);
        $this->assertSame('mtn-1gb', $res['variations'][0]['variation_code']);
        $this->assertSame('1000.00000000', $res['variations'][0]['amount']);
        $this->assertSame('MTN 1GB / 30 days', $res['variations'][0]['name']);
        $this->assertTrue($res['variations'][0]['fixed_price']);
        $this->assertFalse($res['variations'][1]['fixed_price']);
        $this->assertSame('GET', $http->calls[0]['method']);
        $this->assertStringContainsString('/service-variations', $http->calls[0]['path']);
        $this->assertSame('serviceID=MTN-DATA', $http->calls[0]['query']);
    }

    public function testStandardVtuAdapterHonoursTheConfiguredEndpointAndServiceIds()
    {
        $retry = json_encode(array('vtu' => array(
            'variations'        => '/catalogue?x=1',
            'variations_param'  => 'service',
            'variations_method' => 'POST',
            'service_ids'       => array('MTN-DATA' => 'mtn-data'),
        )));
        $http = new VtuCatalogueSyncFakeHttp($this->body($this->variation_rows()));
        $adapter = new StandardVtuAdapter($this->provider('STANDARD_VTU', $retry), $http);
        $res = $adapter->variations('MTN-DATA');

        $this->assertTrue($res['ok']);
        $this->assertSame('mtn-data', $res['service_id']);
        $this->assertSame('POST', $http->calls[0]['method']);
        $this->assertStringContainsString('/catalogue', $http->calls[0]['path']);
        $this->assertSame(array('service' => 'mtn-data'), $http->calls[0]['data']);
    }

    public function testStandardVtuAdapterToleratesAlternateVariationShapes()
    {
        $body = json_encode(array('variations' => array(
            array('code' => 'waec-pin', 'variation_name' => 'WAEC PIN',
                  'amount' => '3500', 'fixedPrice' => false),
            array('id' => 'neco-pin', 'title' => 'NECO PIN', 'price' => '2000'),
            array('name' => 'no code at all'),
        )));
        $http = new VtuCatalogueSyncFakeHttp($body);
        $adapter = new StandardVtuAdapter($this->provider(), $http);
        $res = $adapter->variations('WAEC');

        $this->assertTrue($res['ok']);
        $this->assertCount(2, $res['variations'], 'rows without a code are ignored');
        $this->assertSame('waec-pin', $res['variations'][0]['variation_code']);
        $this->assertSame('3500.00000000', $res['variations'][0]['amount']);
        $this->assertFalse($res['variations'][0]['fixed_price']);
        $this->assertSame('neco-pin', $res['variations'][1]['variation_code']);
        $this->assertTrue($res['variations'][1]['fixed_price']);
    }

    public function testStandardVtuAdapterReportsVendorErrorsWithoutThrowing()
    {
        $body = json_encode(array(
            'code' => '012',
            'response_description' => 'The provider does not offer this product',
            'content' => array(),
        ));
        $http = new VtuCatalogueSyncFakeHttp($body);
        $adapter = new StandardVtuAdapter($this->provider(), $http);
        $res = $adapter->variations('MTN-DATA');

        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('does not offer', $res['error']);
    }

    public function testStandardVtuAdapterReportsTransportFailuresAsSkips()
    {
        $http = new VtuCatalogueSyncFakeHttp('', 0);
        $adapter = new StandardVtuAdapter($this->provider(), $http);
        $res = $adapter->variations('MTN-DATA');

        $this->assertFalse($res['ok']);
        $this->assertNotEmpty($res['error']);
    }

    /* ------------------------ the real sync path --------------------- */

    /** Build ProviderSyncService with a stub registry returning $adapter. */
    private function sync_world($adapter, $type = 'STANDARD_VTU', $retry = null, $key = 'test-key')
    {
        $ci = new VtuCatalogueSyncFakeCI($adapter);
        $svc = new ProviderSyncService();
        $provider = $this->provider($type, $retry, $key);
        return array($svc, $provider, $ci);
    }

    public function testSyncServicesForStandardVtuImportsProductsInsteadOfFailing()
    {
        $http = new VtuCatalogueSyncFakeHttp($this->body($this->variation_rows()));
        $adapter = new StandardVtuAdapter($this->provider(), $http);
        list($svc, $provider, $ci) = $this->sync_world($adapter);

        $res = $svc->sync_services($provider);

        $this->assertTrue($res['ok'], json_encode($res));
        $this->assertGreaterThan(0, $res['total']);
        $this->assertSame($res['total'], $res['inserted']);
        $this->assertCount(10, $http->calls, 'one call per active DATA/CABLE/EXAM_PIN network');
        $this->assertCount(20, $ci->Vtu_product_model->rows);
        $this->assertSame('SUCCESS', $ci->Provider_model->syncs[0]['status']);
        $this->assertSame($res['total'], $ci->Provider_model->syncs[0]['items']);
    }

    public function testSyncServicesForVtpassStillImportsItsCatalogue()
    {
        $key = json_encode(array(
            'api_key' => 'test-api-key', 'public_key' => 'PK_test', 'secret_key' => 'SK_test',
        ));
        $http = new VtuCatalogueSyncFakeHttp($this->body($this->variation_rows()));
        $adapter = new VtpassAdapter($this->provider('VTPASS', null, $key), $http);
        list($svc, $provider, $ci) = $this->sync_world($adapter, 'VTPASS', null, $key);

        $res = $svc->sync_services($provider);

        $this->assertTrue($res['ok'], json_encode($res));
        $this->assertGreaterThan(0, $res['total']);
        $this->assertSame('SUCCESS', $ci->Provider_model->syncs[0]['status']);
        $this->assertStringContainsString('serviceID=', (string)$http->calls[0]['query']);
    }

    public function testSyncServicesForTheMockAdapterWorksOffline()
    {
        list($svc, $provider, $ci) = $this->sync_world(new MockVtuAdapter());
        $res = $svc->sync_services($provider);

        $this->assertTrue($res['ok'], json_encode($res));
        $this->assertGreaterThan(0, $res['total']);
        $this->assertSame('SUCCESS', $ci->Provider_model->syncs[0]['status']);
    }

    public function testAnAdapterWithNoProductListGetsAnActionableError()
    {
        // A third-party adapter written against the old eight-method contract:
        // no variations(), so there is genuinely nothing to sync.
        list($svc, $provider, $ci) = $this->sync_world(new VtuCatalogueSyncLegacyAdapter());

        $res = $svc->sync_services($provider);

        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('STANDARD_VTU', $res['error']);
        $this->assertStringContainsString('variations()', $res['error']);
        $this->assertStringNotContainsString('cannot list products', $res['error']);
        $this->assertSame('FAILED', $ci->Provider_model->syncs[0]['status']);
    }

    public function testAVendorThatCarriesNoNetworksIsReportedNotCrashed()
    {
        $body = json_encode(array(
            'code' => '012', 'response_description' => 'service not available', 'content' => array(),
        ));
        $http = new VtuCatalogueSyncFakeHttp($body);
        $adapter = new StandardVtuAdapter($this->provider(), $http);
        list($svc, $provider, $ci) = $this->sync_world($adapter);

        $res = $svc->sync_services($provider);

        $this->assertFalse($res['ok'], 'nothing came back and every network errored');
        $this->assertStringContainsString('service not available', $res['error']);
        $this->assertSame('FAILED', $ci->Provider_model->syncs[0]['status']);
        $this->assertSame(array(), $ci->Vtu_product_model->rows);
    }
}

/* ------------------------------ doubles ------------------------------ */

#[AllowDynamicProperties]
class VtuCatalogueSyncFakeCI
{
    public $load, $Provider_model, $Provider_service_model;
    public $Vtu_network_model, $Vtu_product_model, $provider_manager, $encryptionservice;

    public function __construct($adapter)
    {
        $GLOBALS['__fake_ci'] = $this;
        $this->load = new VtuCatalogueSyncFakeLoader();
        $this->Provider_model = new VtuCatalogueSyncLog();
        $this->Provider_service_model = new stdClass();
        $this->Vtu_network_model = new VtuCatalogueSyncNetworks();
        $this->Vtu_product_model = new VtuCatalogueSyncProducts();
        $this->provider_manager = new VtuCatalogueSyncManager($adapter);
        $this->encryptionservice = new VtuCatalogueSyncPassthroughEncryption();
    }
}

class VtuCatalogueSyncFakeLoader
{
    public function library($n, $p = null, $o = null) { return $this; }
    public function model($n, $a = null, $d = false) { return $this; }
    public function helper($n = '') { return $this; }
}

class VtuCatalogueSyncLog
{
    public $syncs = array();
    public function record_sync($id, $type, $status, $message = null, $items = 0, $ms = null, $meta = null)
    {
        $this->syncs[] = array(
            'id' => $id, 'type' => $type, 'status' => $status,
            'message' => $message, 'items' => $items,
        );
    }
    public function record_health() {}
}

class VtuCatalogueSyncNetworks
{
    public function active($service_type = null)
    {
        $codes = array(
            'DATA' => array('MTN-DATA', 'GLO-DATA', 'AIRTEL-DATA', '9MOBILE-DATA'),
            'CABLE' => array('DSTV', 'GOTV', 'STARTIMES'),
            'EXAM_PIN' => array('WAEC', 'NECO', 'JAMB'),
        );
        $out = array();
        $i = 1;
        foreach (isset($codes[$service_type]) ? $codes[$service_type] : array() as $code) {
            $out[] = (object)array('id' => $i++, 'code' => $code);
        }
        return $out;
    }
}

class VtuCatalogueSyncProducts
{
    public $rows = array();
    public function upsert_from_provider($provider_id, $network_id, $service_type, array $v, $sorting = 0)
    {
        $this->rows[] = array(
            'provider_id' => $provider_id, 'network_id' => $network_id,
            'service_type' => $service_type, 'variation' => $v,
        );
        return 'inserted';
    }
}

class VtuCatalogueSyncManager
{
    private $adapter;
    public function __construct($adapter) { $this->adapter = $adapter; }
    public function adapter($provider, $family) { return $this->adapter; }
}

class VtuCatalogueSyncPassthroughEncryption
{
    public function decrypt($blob) { return (string)$blob; }
    public function encrypt($plain) { return (string)$plain; }
}

class VtuCatalogueSyncFakeHttp
{
    public $calls = array();
    private $body;
    private $http_code;

    public function __construct($body, $http_code = 200)
    {
        $this->body = $body;
        $this->http_code = $http_code;
    }

    public function get($url, $headers = array(), $options = array())
    {
        return $this->record('GET', $url, null, $headers);
    }

    public function post($url, $data = null, $headers = array(), $options = array())
    {
        return $this->record('POST', $url, is_array($data) ? $data : array(), $headers);
    }

    private function record($method, $url, $data, $headers)
    {
        $this->calls[] = array(
            'method' => $method, 'url' => $url,
            'path' => (string)parse_url($url, PHP_URL_PATH),
            'query' => (string)parse_url($url, PHP_URL_QUERY),
            'data' => $data, 'headers' => $headers,
        );
        return array('http_code' => $this->http_code, 'body' => $this->body, 'error' => null);
    }
}

/** Stands in for a pre-catalogue third-party adapter. */
class VtuCatalogueSyncLegacyAdapter
{
    public function balance() { return array('ok' => true, 'balance' => '0.00'); }
}

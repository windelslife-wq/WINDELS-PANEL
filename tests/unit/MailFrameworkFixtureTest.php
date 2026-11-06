<?php
use PHPUnit\Framework\TestCase;

/**
 * Guards the offline mail-test bootstrap.
 *
 * MailDeliveryTransportTest and MailGreetingTest exercise the real CI3 SMTP
 * handshake through MY_Email extends CI_Email. They used to fatal in
 * setUpBeforeClass() on a fresh clone because BASEPATH pointed at the
 * gitignored ./system framework that only `composer install` +
 * tools/link_system.php create. tests/bootstrap.php now falls back to a
 * byte-for-byte copy of the framework shipped in application-deployment.zip.
 *
 * These tests pin that the fallback is real CI3 code, not a stub, and that
 * the constants CI3's front controller normally supplies are present —
 * otherwise the suite would go back to "2 failures, 0 assertions" for the
 * mail classes.
 */
class MailFrameworkFixtureTest extends TestCase
{
    private static $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
        if (!defined('BASEPATH')) define('BASEPATH', self::$root.'/system/');
        if (!defined('APPPATH'))  define('APPPATH',  self::$root.'/application/');
        require_once BASEPATH.'libraries/Email.php';
        require_once APPPATH.'libraries/MY_Email.php';
    }

    public function testTheMailTestsLoadTheRealCi3EmailClass()
    {
        $file = BASEPATH.'libraries/Email.php';
        $this->assertFileExists($file,
            'the CI3 Email library must be reachable from BASEPATH without Composer');
        $src = file_get_contents($file);
        $this->assertStringContainsString('class CI_Email', $src);
        $this->assertStringContainsString('CodeIgniter Email Class', $src,
            'a stubbed CI_Email would not exercise the real SMTP handshake');
        $this->assertTrue(class_exists('CI_Email'));
        $this->assertTrue(is_subclass_of('MY_Email', 'CI_Email'),
            'the hardened greeting must still extend the real framework class');
    }

    public function testTheBareTestProcessSuppliesCi3FrameworkConstants()
    {
        // CI_Email::initialize() runs strtoupper(config_item('charset')), and
        // its Q-encoding path reads ICONV_ENABLED / MB_ENABLED. In index.php
        // CI3's front controller sets all three; without them PHP 8.1+ emits
        // deprecations or fatals on an undefined constant.
        $this->assertSame('UTF-8', config_item('charset'));
        $this->assertTrue(defined('ICONV_ENABLED'));
        $this->assertTrue(defined('MB_ENABLED'));
    }

    public function testTheFixtureIsTheFrameworkTheDeploymentPackageShips()
    {
        $fixture = self::$root.'/tests/fixtures/ci3/libraries/Email.php';
        $this->assertFileExists($fixture,
            'the tracked fallback must survive a fresh clone');
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('ZipArchive is unavailable in this PHP build');
        }
        $zip = new ZipArchive();
        $this->assertTrue($zip->open(self::$root.'/application-deployment.zip') === true);
        $shipped = $zip->getFromName('system/libraries/Email.php');
        $zip->close();
        $this->assertNotFalse($shipped, 'the deployment package must ship CI3');
        $this->assertSame(sha1($shipped), sha1_file($fixture),
            'refresh the fixture whenever the bundled CodeIgniter is upgraded');
    }
}

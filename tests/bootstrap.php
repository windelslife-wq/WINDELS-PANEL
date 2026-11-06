<?php
/**
 * Test bootstrap.
 *
 * index.php defines ENVIRONMENT for web and CLI requests; the test suite never
 * boots index.php, so without this the constant is missing. Code that has to
 * fail closed — EncryptionService::resolve_key() refuses to run production
 * with a placeholder key — then treats the whole suite as production and
 * throws. Declare the environment the tests actually run in.
 */
if (!defined('ENVIRONMENT')) {
    define('ENVIRONMENT', getenv('CI_ENV') ?: 'testing');
}

// CI3 defines these in index.php. Config files use APPPATH to pull in the
// helper that supplies env_bool(), so the suite needs them too.
if (!defined('APPPATH'))  define('APPPATH', dirname(__DIR__).'/application/');
if (!defined('BASEPATH')) {
    // The framework is a Composer dependency exposed as ./system by
    // tools/link_system.php; both are gitignored, so a fresh clone has
    // neither. Two mail test classes extend CI_Email and load the real
    // library from BASEPATH. Point them at the exact MIT-licensed copy
    // shipped in the tracked deployment package (tests/fixtures/ci3/) when
    // the framework is absent, so the SMTP handshake is still tested for
    // real instead of being skipped or stubbed out. A normal install with
    // system/ present is completely unaffected.
    $framework = dirname(__DIR__).'/system/';
    if (!is_file($framework.'libraries/Email.php')) {
        $fixture = __DIR__.'/fixtures/ci3/';
        if (is_file($fixture.'libraries/Email.php')) $framework = $fixture;
    }
    define('BASEPATH', $framework);
}

// Deterministic timestamps: the app stores UTC everywhere.
date_default_timezone_set('UTC');

// CI3's CI_Email constructor reads config_item('charset'), then initialize()
// runs strtoupper() on it. A bare test process has no config loaded, so the
// null shim the mail tests used to install made PHP 8.1+ emit a deprecation
// and turned the charset into ''. Mirror the real application/config/config.php
// value instead: the framework behaves exactly as it does in production.
if (!function_exists('config_item')) {
    function config_item($item)
    {
        return $item === 'charset' ? 'UTF-8' : null;
    }
}
// system/core/CodeIgniter.php defines these before any library loads; the
// bare test process has to supply them for CI_Email's Q-encoding path.
if (!defined('MB_ENABLED'))    define('MB_ENABLED', extension_loaded('mbstring'));
if (!defined('ICONV_ENABLED')) define('ICONV_ENABLED', extension_loaded('iconv'));

if (is_file(__DIR__.'/../vendor/autoload.php')) {
    require_once __DIR__.'/../vendor/autoload.php';
}

/**
 * True when the suite executes on a PHP-WASM (emscripten) build — the offline
 * audit harness runs tests that way when no native PHP binary exists.
 *
 * Some kernel primitives behave differently there: notably flock(), whose
 * emscripten emulation aliases lock state between two handles that share an
 * open file description inside one process, so LOCK_EX|LOCK_NB wrongly
 * succeeds for a second in-process JobRunner. Tests that exist specifically
 * to pin such a primitive scope themselves to native runtimes with
 * markTestSkipped() — loudly, never silently passing — while every
 * platform-independent assertion in the same class keeps running here.
 * Production and CI cron always execute on native PHP, where the pinned
 * behaviour is the behaviour the OS provides.
 */
if (!function_exists('marvy_runtime_is_wasm')) {
    function marvy_runtime_is_wasm()
    {
        return php_sapi_name() === 'wasm'
            || stripos((string)php_uname('s'), 'Emscripten') === 0
            || stripos((string)php_uname('m'), 'wasm') === 0;
    }
}

<?php
use PHPUnit\Framework\TestCase;

/** Shared asset-delivery budgets for production pages. */
class AssetDeliveryTest extends TestCase
{
    private static $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
    }

    public function testBothNginxProfilesCompressTextAndCacheStaticAssets()
    {
        foreach (array('nginx.conf', 'nginx.prod.conf') as $file) {
            $src = file_get_contents(self::$root.'/docker/nginx/'.$file);
            $this->assertStringContainsString('gzip on;', $src, $file.' must compress HTML/CSS/JS responses');
            $this->assertStringContainsString('gzip_vary on;', $src);
            $this->assertStringContainsString('text/css', $src);
            $this->assertStringContainsString('application/javascript', $src);
            $this->assertStringContainsString('expires 7d;', $src,
                $file.' must cache versioned-by-deploy static assets');
        }
    }

    public function testGoogleFontsNeverBlockTheLocalPageStylesheets()
    {
        $fonts = file_get_contents(self::$root.'/application/views/partials/web_fonts.php');
        foreach (array('document.createElement', "link.media = 'print'", 'csp_nonce_attr()', 'display=swap') as $needle) {
            $this->assertStringContainsString($needle, $fonts);
        }
        $this->assertStringNotContainsString('<link rel="stylesheet" href="https://fonts.googleapis.com', $fonts,
            'the external font stylesheet must be inserted asynchronously, not render-blocking');
        $this->assertStringNotContainsString('onload=', $fonts,
            'inline HTML event handlers do not satisfy the nonce-based CSP');
    }

    public function testTailwindBuildActuallyMinifiesItsTrackedArtifact()
    {
        $package = json_decode(file_get_contents(self::$root.'/package.json'), true);
        $this->assertSame(
            'tailwindcss -i ./assets/css/app.css -o ./assets/css/tailwind.css --minify',
            $package['scripts']['build:css']
        );
        $css = file_get_contents(self::$root.'/assets/css/tailwind.css');
        $this->assertLessThan(20000, strlen($css),
            'a production build must keep the generated Tailwind payload minified');
    }
}

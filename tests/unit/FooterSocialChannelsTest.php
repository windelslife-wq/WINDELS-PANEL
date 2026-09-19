<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/_support/FakeDb.php';
require_once dirname(__DIR__).'/_support/IntegrationHarness.php';

/**
 * Footer social channels — the seven buttons (Facebook, Instagram, WhatsApp,
 * X, LinkedIn, Telegram, YouTube) added to the site footer.
 *
 * The feature is deliberately small, but every seam in it is one that has
 * broken before elsewhere in this codebase, so each is pinned here:
 *
 *   - the footer renders from settings, not hardcoded URLs, and an operator
 *     who clears a channel's URL must see the button disappear (the url
 *     setting type's "leave blank to disable" contract);
 *   - a fresh install, or a deployment whose migration has not run, must
 *     still show all seven buttons — falling back row-by-row to the
 *     platform home pages rather than rendering a half-empty row;
 *   - the seeder, the migration and the helper all ship the same defaults;
 *     three copies of the same list is exactly how one of them drifts;
 *   - the buttons are external links opened in a new tab, so they carry
 *     rel="noopener" and aria-labels (the icons alone are not accessible
 *     names);
 *   - the icons are glyphs from partials/icon — a typo in a glyph key
 *     renders nothing at all, silently.
 */
class FooterSocialChannelsTest extends TestCase
{
    private static $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
        if (!defined('BASEPATH')) define('BASEPATH', self::$root.'/system/');
        if (!class_exists('CI_Model')) {
            eval('#[AllowDynamicProperties] class CI_Model { public $db; }');
        }
        if (!class_exists('CI_Migration')) {
            eval('class CI_Migration { public $db; public $dbforge; }');
        }
        if (!function_exists('get_instance')) {
            eval('function &get_instance(){ return $GLOBALS["__fake_ci"]; }');
        }
        if (!function_exists('log_message')) eval('function log_message($l,$m){}');
        require_once self::$root.'/application/core/MY_Model.php';
        require_once self::$root.'/application/helpers/marvy_helper.php';
        require_once self::$root.'/application/migrations/043_footer_social_channels.php';
        require_once self::$root.'/application/libraries/Seeder.php';
        require_once self::$root.'/application/seeds/Core_seeder.php';
    }

    protected function setUp(): void
    {
        // The settings model memoises per request; each test is a new request.
        if (class_exists('Setting_model')) Setting_model::flush_cache();
    }

    private function view($rel)
    {
        $path = self::$root.'/application/views/'.$rel;
        $this->assertFileExists($path, $rel.' must exist');
        return file_get_contents($path);
    }

    private static function channels()
    {
        return array('facebook', 'instagram', 'whatsapp', 'x', 'linkedin', 'telegram', 'youtube');
    }

    /* ====================== the footer partial itself ==================== */

    public function testTheFooterRendersTheSevenChannelButtons()
    {
        $footer = $this->view('partials/footer.php');

        $this->assertStringContainsString('ws-social', $footer, 'the social row must use the ws-social component');
        foreach (self::channels() as $channel) {
            $this->assertStringContainsString("'".$channel."'", $footer,
                'the footer must declare the '.$channel.' channel');
        }
        // External links in a new tab: never without rel="noopener", and the
        // icon is aria-hidden so the link needs its own accessible name.
        $this->assertStringContainsString('target="_blank" rel="noopener"', $footer,
            'social buttons open externally and must be cut off from window.opener');
        $this->assertStringContainsString('aria-label=', $footer,
            'an icon-only link has no accessible name unless it carries aria-label');
        $this->assertStringContainsString("array('name'=>\$meta[0], 'class'=>'ws-social-icon')", $footer,
            'the buttons render their icons through the shared icon partial');
    }

    public function testEveryChannelIconShipsInTheIconSet()
    {
        $icons = $this->view('partials/icon.php');
        // facebook, instagram, youtube, twitter (the X mark) and send (the
        // paper plane) predate this feature; whatsapp, linkedin and telegram
        // were added with it.
        foreach (array('facebook', 'instagram', 'whatsapp', 'twitter', 'linkedin', 'send', 'telegram', 'youtube') as $glyph) {
            $this->assertMatchesRegularExpression('/\''.$glyph.'\'\s*=>/', $icons,
                'the icon set must ship the '.$glyph.' glyph');
        }
    }

    public function testTheStylesheetCarriesTheSocialButtonStyles()
    {
        $css = file_get_contents(self::$root.'/assets/css/design-system.css');
        $this->assertFileExists(self::$root.'/assets/css/design-system.css');

        $this->assertMatchesRegularExpression('/\.ws-social-btn\s*\{/', $css,
            'the base button rules must exist');
        $this->assertMatchesRegularExpression('/\.ws-footer\s+\.ws-social-btn\s*\{[^}]*--ws-navy-line/', $css,
            'the footer is navy on every shell: the button must be restated on the navy palette');
        $this->assertMatchesRegularExpression('/\.ws-social-btn\s+\.ws-social-icon\s*\{[^}]*18px/', $css,
            'the icon is sized once, in the stylesheet');
    }

    /* ======================= marvy_social_links() ======================== */

    /** A database with no social rows yet (fresh, or pre-migration) shows all
     *  seven buttons on the platforms' home pages — never a half-empty row. */
    public function testTheHelperFallsBackToThePlatformHomePages()
    {
        $app = new IntegrationHarness();
        $app->seed_minimal();
        $app->model('Setting_model');

        $links = marvy_social_links();

        $this->assertSame(array(
            'facebook'  => 'https://www.facebook.com/',
            'instagram' => 'https://www.instagram.com/',
            'whatsapp'  => 'https://www.whatsapp.com/',
            'x'         => 'https://x.com/',
            'linkedin'  => 'https://www.linkedin.com/',
            'telegram'  => 'https://t.me/',
            'youtube'   => 'https://www.youtube.com/',
        ), $links, 'with no settings rows, every channel falls back to its platform home page');
    }

    public function testAStoredUrlWinsAndAnEmptyRowHidesTheChannel()
    {
        $app = new IntegrationHarness();
        $app->seed_minimal();
        $app->model('Setting_model');
        $app->Setting_model->set('social_facebook_url', 'https://www.facebook.com/MarvySocials', 'social');
        $app->Setting_model->set('social_telegram_url', 'https://t.me/marvysocials', 'social');
        // The documented way to drop a channel the operator does not use.
        $app->Setting_model->set('social_youtube_url', '', 'social');
        Setting_model::flush_cache();

        $links = marvy_social_links();

        $this->assertSame('https://www.facebook.com/MarvySocials', $links['facebook'],
            'a stored URL wins over the fallback');
        $this->assertSame('https://t.me/marvysocials', $links['telegram']);
        $this->assertArrayNotHasKey('youtube', $links,
            'an explicitly cleared row must hide the channel, not fall back to the home page');
        $this->assertCount(6, $links);
    }

    /* ==================== settings, seeder and migration ================= */

    public function testTheSettingsSchemaExposesTheChannelsAsEditableUrls()
    {
        require_once self::$root.'/application/libraries/SettingsService.php';
        $schema = SettingsService::schema();

        foreach (self::channels() as $channel) {
            $key = 'social_'.$channel.'_url';
            $this->assertArrayHasKey($key, $schema, 'the schema must expose '.$key);
            $this->assertSame('url', $schema[$key][0], $key.' must be a url setting (validated http(s), blank disables)');
            $this->assertSame('social', $schema[$key][1], $key.' belongs to the social category');
            $this->assertNotSame('', (string)$schema[$key][4], $key.' needs a default so the admin form shows one');
        }
        // The card title: grouped() renders by category, and an unlabelled
        // category would headline as "Social" with no context.
        $admin = $this->view('admin/settings/index.php');
        $this->assertStringContainsString("'social'", $admin,
            'the admin settings view must label the social category');
    }

    public function testTheSeederAndTheMigrationShipTheSameDefaults()
    {
        $seeded = array();
        foreach (Core_seeder::default_settings() as $s) {
            if (strpos($s[0], 'social_') === 0) $seeded[$s[0]] = array($s[1], $s[2]);
        }
        $this->assertCount(7, $seeded, 'the core seed must ship all seven social channels');

        foreach (Migration_Footer_social_channels::channels() as $key => $url) {
            $this->assertArrayHasKey($key, $seeded, 'the seeder must also ship '.$key);
            $this->assertSame($url, $seeded[$key][0],
                $key.' default differs between the seeder and the migration — three copies of one list is how they drift');
            $this->assertSame('social', $seeded[$key][1]);
        }
    }

    public function testTheMigrationInsertsTheRowsForAlreadyDeployedPanels()
    {
        $app = new IntegrationHarness();
        $app->seed_minimal();
        $app->model('Setting_model');

        $mig = new Migration_Footer_social_channels();
        $mig->db = $app->db;
        $mig->up();

        $this->assertSame('https://www.facebook.com/', $app->Setting_model->get('social_facebook_url'));
        $this->assertSame('https://www.youtube.com/', $app->Setting_model->get('social_youtube_url'));

        // Re-running is safe, and an operator-edited value is never reclaimed.
        $app->Setting_model->set('social_x_url', 'https://x.com/marvysocials', 'social');
        Setting_model::flush_cache();
        $mig->up();
        $this->assertSame('https://x.com/marvysocials', $app->Setting_model->get('social_x_url'),
            'up() inserts-or-leaves; it must not overwrite an existing row');

        $mig->down();
        Setting_model::flush_cache();
        $this->assertNull($app->Setting_model->get('social_facebook_url'),
            'down() removes the channels it added');
    }

    /* ===================== the static preview mirror ===================== */

    public function testTheStaticPreviewMirrorsTheSocialRow()
    {
        // tools/public_preview_server.js mirrors the PHP views so the design
        // can be looked at without a PHP runtime; a footer it does not mirror
        // previews a footer that no longer exists.
        $src = file_get_contents(self::$root.'/tools/public_preview_server.js');
        $this->assertStringContainsString('ws-social', $src,
            'the static preview must carry the social row too');
    }
}

<?php
use PHPUnit\Framework\TestCase;

/**
 * VTU catalogue sync (the "Sync services" button).
 *
 * The registry used to hold three VTU adapters, but only VTPASS answered
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
        require_once self::$root.'/application/libraries/BestdataAdapter.php';
        require_once self::$root.'/application/core/MY_Model.php';
        require_once self::$root.'/application/helpers/marvy_helper.php';
        require_once dirname(__DIR__).'/_support/FakeDb.php';
        require_once dirname(__DIR__).'/_support/IntegrationHarness.php';
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
        foreach (array('MOCK', 'STANDARD_VTU', 'VTPASS', 'BESTDATA') as $type) {
            $this->assertContains($type, Provider_manager::supported_types(Provider_manager::FAMILY_VTU));
        }
        foreach (array('MockVtuAdapter', 'StandardVtuAdapter', 'VtpassAdapter',
                       'BestdataAdapter') as $class) {
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

    /* ---------------- inactive at cost & safe re-syncs ---------------- */

    /**
     * Boot a real FakeDb + real Vtu_product_model + real ProviderSyncService
     * wired to a scripted BestdataAdapter so every column and re-sync rule is
     * verified end-to-end against the shipped schema.
     */
    private function real_bestdata_sync_app(VtuCatalogueSyncRoutedHttp $http)
    {
        $app = new IntegrationHarness();
        $app->seed_minimal();
        $now = gmdate('Y-m-d H:i:s');

        $app->db->insert('providers', array(
            'public_id'         => 'PROV0000000000000000000099',
            'name'              => 'BestData Live',
            'api_url'           => 'https://bestdatanig.com.ng',
            'api_key_encrypted' => 'test-token',
            'api_type'          => 'BESTDATA',
            'status'            => 'ACTIVE',
            'currency'          => 'NGN',
            'timeout_ms'        => 15000,
            'created_at'        => $now,
            'updated_at'        => $now,
        ));
        $provider_id = $app->db->insert_id();

        $nets = array(
            array('MTN',      'MTN Airtime',    'AIRTIME'),
            array('MTN-DATA', 'MTN Data',       'DATA'),
            array('DSTV',     'DSTV',           'CABLE'),
            array('IKEDC',    'Ikeja Electric', 'ELECTRICITY'),
            array('WAEC',     'WAEC',           'EXAM_PIN'),
            array('NECO',     'NECO',           'EXAM_PIN'),
        );
        $net_ids = array();
        foreach ($nets as $i => $n) {
            $app->db->insert('vtu_networks', array(
                'public_id'    => 'VNET'.str_pad((string)($i + 10), 22, '0', STR_PAD_LEFT),
                'code'         => $n[0],
                'name'         => $n[1],
                'service_type' => $n[2],
                'is_active'    => 1,
                'sorting'      => $i,
                'created_at'   => $now,
                'updated_at'   => $now,
            ));
            $net_ids[$n[0]] = $app->db->insert_id();
        }

        // Hand-configured variable-amount airtime & electricity rows.
        $app->db->insert('vtu_products', array(
            'public_id' => 'VPRD0000000000000000000101',
            'network_id' => $net_ids['MTN'], 'provider_id' => $provider_id,
            'service_type' => 'AIRTIME', 'code' => 'MTN-AIRTIME',
            'name' => 'MTN Airtime', 'price' => null, 'face_value' => null,
            'discount_percent' => '2.0000', 'min_amount' => '50', 'max_amount' => '50000',
            'is_active' => 1, 'sorting' => 0, 'created_at' => $now, 'updated_at' => $now,
        ));
        $app->db->insert('vtu_products', array(
            'public_id' => 'VPRD0000000000000000000102',
            'network_id' => $net_ids['IKEDC'], 'provider_id' => $provider_id,
            'service_type' => 'ELECTRICITY', 'code' => 'IKEDC-PREPAID',
            'name' => 'Ikeja Prepaid', 'price' => null, 'face_value' => null,
            'discount_percent' => '0.0000', 'min_amount' => '1000', 'max_amount' => '100000',
            'is_active' => 1, 'sorting' => 0, 'created_at' => $now, 'updated_at' => $now,
        ));

        $app->model(array('Provider_model', 'Vtu_network_model', 'Vtu_product_model'));
        $app->securehttpclient = $http;
        $app->encryptionservice = new VtuCatalogueSyncPassthroughEncryption();
        $app->provider_manager = new Provider_manager();
        // Replace HarnessProviderSync stub with the real ProviderSyncService.
        unset($app->providersyncservice);
        $svc = new ProviderSyncService();
        $provider = $app->Provider_model->find_by_id($provider_id);

        return array($app, $svc, $provider, $net_ids);
    }

    public function testNewSyncedProductsArriveInactiveAtCostAndRepeatedSyncIsIdempotent()
    {
        $http = new VtuCatalogueSyncRoutedHttp(array(
            '/api/network/' => array('MTN_PLAN' => array(
                array('id' => 12, 'plan' => '1GB', 'plan_type' => 'SME',
                      'month_validate' => '30 Days', 'plan_amount' => '700'),
            )),
            '/api/cable/'   => array('DSTVPLAN' => array(
                array('id' => 31, 'package' => 'Padi', 'plan_amount' => '2150'),
            )),
            '/api/epin/'    => array(
                array('exam_name' => 'WAEC', 'amount' => '3500'),
                array('exam_name' => 'NECO', 'amount' => '2000'),
            ),
        ));
        list($app, $svc, $provider, $net_ids) = $this->real_bestdata_sync_app($http);

        $first = $svc->sync_services($provider);
        $this->assertTrue($first['ok'], json_encode($first));
        $this->assertSame(4, $first['inserted']);
        $this->assertSame(0, $first['updated']);
        $this->assertSame(0, $first['unchanged']);
        $this->assertSame(0, $first['paused']);

        $mtn = $app->Vtu_product_model->find_by_code($net_ids['MTN-DATA'], 'DATA', '12');
        $this->assertNotNull($mtn);
        $this->assertSame(0, (int)$mtn->is_active, 'new synced products must start inactive');
        $this->assertSame('700.00000000', $mtn->price);
        $this->assertSame('700.00000000', $mtn->provider_cost);
        $this->assertSame('700.00000000', $mtn->face_value);
        $this->assertSame('SME', $mtn->product_type);
        $this->assertSame('30 Days', $mtn->validity);

        $waec = $app->Vtu_product_model->find_by_code($net_ids['WAEC'], 'EXAM_PIN', 'WAEC');
        $this->assertNotNull($waec);
        $this->assertSame(0, (int)$waec->is_active);
        $this->assertSame('3500.00000000', $waec->price);
        $this->assertSame('3500.00000000', $waec->provider_cost);

        // Variable-amount airtime & electricity stay hand-configured.
        $air = $app->Vtu_product_model->find_by_code($net_ids['MTN'], 'AIRTIME', 'MTN-AIRTIME');
        $this->assertSame(1, (int)$air->is_active);
        $this->assertNull($air->price);

        // Immediate re-sync with identical vendor data is a safe no-op.
        $second = $svc->sync_services($provider);
        $this->assertTrue($second['ok'], json_encode($second));
        $this->assertSame(0, $second['inserted']);
        $this->assertSame(0, $second['updated']);
        $this->assertSame(4, $second['unchanged']);
        $this->assertSame(4, $second['total']);
    }

    public function testUnreviewedInactiveRowsTrackCostChangesOnResync()
    {
        $http = new VtuCatalogueSyncRoutedHttp(array(
            '/api/network/' => array('MTN_PLAN' => array(
                array('id' => 12, 'plan' => '1GB', 'plan_amount' => '700'),
            )),
            '/api/cable/'   => array('DSTVPLAN' => array()),
            '/api/epin/'    => array(array('exam_name' => 'WAEC', 'amount' => '3500')),
        ));
        list($app, $svc, $provider, $net_ids) = $this->real_bestdata_sync_app($http);
        $svc->sync_services($provider);

        // Vendor raises MTN 1GB cost (700 -> 850) and lowers WAEC (3500 -> 3200)
        // before any admin has priced or activated either row.
        $http->set('/api/network/', array('MTN_PLAN' => array(
            array('id' => 12, 'plan' => '1GB', 'plan_amount' => '850'),
        )));
        $http->set('/api/epin/', array(array('exam_name' => 'WAEC', 'amount' => '3200')));

        $res = $svc->sync_services($provider);
        $this->assertTrue($res['ok']);
        $this->assertSame(2, $res['updated']);

        $mtn = $app->Vtu_product_model->find_by_code($net_ids['MTN-DATA'], 'DATA', '12');
        $this->assertSame(0, (int)$mtn->is_active);
        $this->assertSame('850.00000000', $mtn->provider_cost);
        $this->assertSame('850.00000000', $mtn->price,
            'an unreviewed inactive row must stay at cost rather than drifting below cost');
        $this->assertSame('850.00000000', $mtn->face_value);

        $waec = $app->Vtu_product_model->find_by_code($net_ids['WAEC'], 'EXAM_PIN', 'WAEC');
        $this->assertSame(0, (int)$waec->is_active);
        $this->assertSame('3200.00000000', $waec->price);
    }

    public function testResyncPreservesAdminPriceCustomNameAndDeactivation()
    {
        $http = new VtuCatalogueSyncRoutedHttp(array(
            '/api/network/' => array('MTN_PLAN' => array(
                array('id' => 12, 'plan' => '1GB', 'plan_amount' => '700'),
                array('id' => 13, 'plan' => '2GB', 'plan_amount' => '1400'),
            )),
            '/api/cable/'   => array('DSTVPLAN' => array()),
            '/api/epin/'    => array(array('exam_name' => 'WAEC', 'amount' => '3500')),
        ));
        list($app, $svc, $provider, $net_ids) = $this->real_bestdata_sync_app($http);
        $svc->sync_services($provider);

        // Admin prices + renames + activates plan 12; prices plan 13 while keeping it off sale.
        $p12 = $app->Vtu_product_model->find_by_code($net_ids['MTN-DATA'], 'DATA', '12');
        $app->Vtu_product_model->update_fields($p12->id, array(
            'name' => 'MTN 1GB Monthly Promo', 'price' => '950.00000000', 'is_active' => 1,
        ));
        $p13 = $app->Vtu_product_model->find_by_code($net_ids['MTN-DATA'], 'DATA', '13');
        $app->Vtu_product_model->update_fields($p13->id, array(
            'price' => '1800.00000000', 'is_active' => 0,
        ));

        // Vendor nudges costs within margin (700 -> 780, 1400 -> 1500).
        $http->set('/api/network/', array('MTN_PLAN' => array(
            array('id' => 12, 'plan' => '1GB', 'plan_amount' => '780'),
            array('id' => 13, 'plan' => '2GB', 'plan_amount' => '1500'),
        )));

        $res = $svc->sync_services($provider);
        $this->assertTrue($res['ok']);
        $this->assertSame(0, $res['paused']);

        $after12 = $app->Vtu_product_model->find_by_id($p12->id);
        $this->assertSame('780.00000000', $after12->provider_cost);
        $this->assertSame('950.00000000', $after12->price, 'admin selling price must not be overwritten');
        $this->assertSame('MTN 1GB Monthly Promo', $after12->name, 'admin custom title must survive re-sync');
        $this->assertSame(1, (int)$after12->is_active);

        $after13 = $app->Vtu_product_model->find_by_id($p13->id);
        $this->assertSame('1500.00000000', $after13->provider_cost);
        $this->assertSame('1800.00000000', $after13->price, 'pre-priced inactive row keeps admin price');
        $this->assertSame(0, (int)$after13->is_active, 'inactive row must stay inactive');
    }

    public function testResyncPausesActiveRowWhenVendorCostRisesAboveSellingPrice()
    {
        $http = new VtuCatalogueSyncRoutedHttp(array(
            '/api/network/' => array('MTN_PLAN' => array(
                array('id' => 12, 'plan' => '1GB', 'plan_amount' => '700'),
            )),
            '/api/cable/'   => array('DSTVPLAN' => array()),
            '/api/epin/'    => array(),
        ));
        list($app, $svc, $provider, $net_ids) = $this->real_bestdata_sync_app($http);
        $svc->sync_services($provider);

        $p12 = $app->Vtu_product_model->find_by_code($net_ids['MTN-DATA'], 'DATA', '12');
        $app->Vtu_product_model->update_fields($p12->id, array(
            'price' => '850.00000000', 'is_active' => 1,
        ));

        // Vendor spikes cost to 920 (> 850 selling price).
        $http->set('/api/network/', array('MTN_PLAN' => array(
            array('id' => 12, 'plan' => '1GB', 'plan_amount' => '920'),
        )));

        $res = $svc->sync_services($provider);
        $this->assertTrue($res['ok']);
        $this->assertSame(1, $res['paused']);

        $after = $app->Vtu_product_model->find_by_id($p12->id);
        $this->assertSame('920.00000000', $after->provider_cost);
        $this->assertSame('850.00000000', $after->price, 'must not invent a new customer price');
        $this->assertSame(0, (int)$after->is_active, 'must take underwater row off sale to prevent a loss');

        $logs = $app->Provider_model->recent_sync_logs($provider->id, 1);
        $this->assertStringContainsString('Paused 1 active product', (string)$logs[0]->message);
    }

    public function testSyncingSecondProviderDoesNotHijackFirstProvidersProduct()
    {
        $http = new VtuCatalogueSyncRoutedHttp(array(
            '/api/network/' => array(),
            '/api/cable/'   => array(),
            '/api/epin/'    => array(array('exam_name' => 'WAEC', 'amount' => '3300')),
        ));
        list($app, $svc, $provider2, $net_ids) = $this->real_bestdata_sync_app($http);

        // Provider #1 (from seed_minimal) already owns an active WAEC product.
        $p1_id = $app->Vtu_product_model->create(array(
            'network_id'    => $net_ids['WAEC'],
            'provider_id'   => 1,
            'service_type'  => 'EXAM_PIN',
            'code'          => 'WAEC',
            'provider_code' => 'waec-direct',
            'name'          => 'WAEC Instant PIN',
            'face_value'    => '4000.00000000',
            'price'         => '4000.00000000',
            'provider_cost' => '3400.00000000',
            'is_active'     => 1,
            'sorting'       => 0,
        ));

        $res = $svc->sync_services($provider2);
        $this->assertTrue($res['ok']);
        $this->assertSame(1, $res['inserted']);

        $first = $app->Vtu_product_model->find_by_id($p1_id);
        $this->assertSame(1, (int)$first->provider_id, 'first provider ownership must remain intact');
        $this->assertSame('4000.00000000', $first->price);
        $this->assertSame(1, (int)$first->is_active);

        $scoped = $app->Vtu_product_model->find_by_code(
            $net_ids['WAEC'], 'EXAM_PIN', 'WAEC-P'.$provider2->id);
        $this->assertNotNull($scoped);
        $this->assertSame((int)$provider2->id, (int)$scoped->provider_id);
        $this->assertSame('WAEC', $scoped->provider_code, 'upstream variation code stays intact');
        $this->assertSame(0, (int)$scoped->is_active);
        $this->assertSame('3300.00000000', $scoped->price);
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
    public function open($blob) { return (string)$blob; }
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


/** Route-keyed HTTP fake for end-to-end BestData sync tests. */
class VtuCatalogueSyncRoutedHttp
{
    private $routes = array();

    public function __construct(array $routes = array()) { $this->routes = $routes; }
    public function set($path, $payload) { $this->routes[$path] = $payload; }

    public function get($url, $headers = array(), $options = array())
    {
        $path = (string)parse_url($url, PHP_URL_PATH);
        if (array_key_exists($path, $this->routes)) {
            return array('http_code' => 200, 'body' => json_encode($this->routes[$path]), 'error' => null);
        }
        return array('http_code' => 200, 'body' => '{}', 'error' => null);
    }

    public function post($url, $data = null, $headers = array(), $options = array())
    {
        return array('http_code' => 201, 'body' => '{}', 'error' => null);
    }
}

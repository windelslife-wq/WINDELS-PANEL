<?php
use PHPUnit\Framework\TestCase;

/**
 * Bestdatanig / AdaData provider (the "Connection failed: HTTP 404" report).
 *
 * The reported 404 came from pointing a VTpass-shaped STANDARD_VTU adapter at
 * the Next.js marketing site app.bestdatanig.com.ng. The real API is a Django
 * REST service on bestdatanig.com.ng/api with Token auth, JSON bodies and a
 * 201 response for purchases. These tests drive the real adapter, the
 * registry's legacy-row route and ProviderSyncService's connection/catalogue
 * paths with a scripted HTTP double — no network, no credentials.
 *
 * Sources for every endpoint/field asserted here are recorded in
 * docs/module-bestdata-provider.md.
 */
class BestdataAdapterTest extends TestCase
{
    private static $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
        if (!defined('BASEPATH')) define('BASEPATH', self::$root.'/system/');
        if (!class_exists('CI_Model')) {
            eval("#[AllowDynamicProperties]\nclass CI_Model { public \$db; }");
        }
        if (!function_exists('get_instance')) {
            eval('function &get_instance() { return $GLOBALS["__fake_ci"]; }');
        }
        if (!function_exists('log_message')) eval('function log_message($l,$m){}');
        require_once self::$root.'/application/libraries/ProviderAdapterInterface.php';
        require_once self::$root.'/application/libraries/VtuProviderInterface.php';
        require_once self::$root.'/application/libraries/SecureHttpClient.php';
        require_once self::$root.'/application/libraries/StandardVtuAdapter.php';
        require_once self::$root.'/application/libraries/BestdataAdapter.php';
        require_once self::$root.'/application/libraries/Provider_manager.php';
        require_once self::$root.'/application/libraries/ProviderSyncService.php';
    }

    private function provider($overrides = array())
    {
        return (object)array_merge(array(
            'id'                => 9,
            'public_id'         => 'PROV0000000000000000000009',
            'name'              => 'Bestdatanig',
            'api_url'           => 'https://app.bestdatanig.com.ng/',
            'api_key_encrypted' => 'Token abc123',
            'api_type'          => 'BESTDATA',
            'status'            => 'ACTIVE',
            'health_status'     => 'UNKNOWN',
            'currency'          => 'NGN',
            'timeout_ms'        => 15000,
            'retry_policy'      => null,
        ), $overrides);
    }

    private function resp($code, $data)
    {
        return array('http_code' => $code, 'body' => json_encode($data), 'error' => null);
    }

    private function http($default = null)
    {
        return new BestdataFakeHttp($default === null ? $this->resp(200, array()) : $default);
    }

    private function ci($http, $adapter_provider = null)
    {
        return new BestdataFakeCI($http, $adapter_provider ?: $this->provider());
    }

    private function adapter($http = null, $overrides = array())
    {
        $http = $http ?: $this->http();
        $this->ci($http, $this->provider($overrides));
        return new BestdataAdapter($this->provider($overrides), $http);
    }

    /* -------------------------- registry / routing --------------------- */

    public function testBestdataIsRegisteredAsAVtuAdapter()
    {
        $this->assertContains('BESTDATA', Provider_manager::supported_types(Provider_manager::FAMILY_VTU));
        $this->assertContains('BESTDATA', ProviderSyncService::supported_types());
        $this->assertSame(Provider_manager::FAMILY_VTU,
            ProviderSyncService::family($this->provider()));
        $this->assertTrue(method_exists('BestdataAdapter', 'variations'));
        $this->assertTrue(method_exists('BestdataAdapter', 'catalogue_types'));
        $this->assertTrue(is_subclass_of('BestdataAdapter', 'VtuProviderInterface'));
        foreach (array('airtime','data','cable','electricity','education','verify','status','balance') as $m) {
            $this->assertTrue(method_exists('BestdataAdapter', $m), $m.'() must exist');
        }
    }

    public function testAProviderSavedBeforeTheAdapterExistedIsRoutedToIt()
    {
        $http = $this->http();
        $ci = $this->ci($http, $this->provider(array(
            'api_type' => 'STANDARD_VTU', 'api_url' => 'https://app.bestdatanig.com.ng',
        )));
        $manager = new Provider_manager();
        $legacy = $this->provider(array(
            'api_type' => 'STANDARD_VTU', 'api_url' => 'https://app.bestdatanig.com.ng',
        ));
        $adapter = $manager->adapter($legacy, Provider_manager::FAMILY_VTU);
        $this->assertInstanceOf('BestdataAdapter', $adapter);
        $this->assertSame('https://bestdatanig.com.ng', $adapter->base_url());

        // A normal STANDARD_VTU vendor is untouched.
        $other = $this->provider(array('api_type' => 'STANDARD_VTU', 'api_url' => 'https://vendor.test/api'));
        $this->assertInstanceOf('StandardVtuAdapter',
            $manager->adapter($other, Provider_manager::FAMILY_VTU));
    }

    public function testTheWebsiteHostIsNormalisedToTheLiveApiOrigin()
    {
        $this->assertSame('https://bestdatanig.com.ng',
            BestdataAdapter::normalize_base_url('https://app.bestdatanig.com.ng/'));
        $this->assertSame('https://bestdatanig.com.ng',
            BestdataAdapter::normalize_base_url('https://bestdatanig.com.ng/api'));
        $this->assertSame('https://bestdatanig.com.ng',
            BestdataAdapter::normalize_base_url('https://api.adadata.com.ng'));
        $this->assertSame('https://adadata.com.ng',
            BestdataAdapter::normalize_base_url('https://adadata.com.ng/api/'));
        $this->assertSame('https://proxy.example/v1',
            BestdataAdapter::normalize_base_url('https://proxy.example/v1'));
        $this->assertSame(BestdataAdapter::API_BASE, BestdataAdapter::normalize_base_url(''));
        $this->assertTrue(BestdataAdapter::is_bestdata_url('https://app.bestdatanig.com.ng/api'));
        $this->assertTrue(BestdataAdapter::is_bestdata_url('adadata.com.ng'));
        $this->assertFalse(BestdataAdapter::is_bestdata_url('https://vendor.test/api'));
    }

    /* ----------------------------- the 404 ---------------------------- */

    public function testTheOriginalBare404IsNowActionable()
    {
        $http = $this->http(array('http_code' => 404, 'body' => '', 'error' => null));
        $this->ci($http);
        $standard = new StandardVtuAdapter($this->provider(array(
            'api_type' => 'STANDARD_VTU', 'api_url' => 'https://app.bestdatanig.com.ng',
        )), $http);
        $res = $standard->balance();
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('HTTP 404 at /balance', $res['error']);
        $this->assertStringContainsString('website', $res['error']);
        $this->assertStringContainsString('BESTDATA', $res['error']);
        $this->assertSame(404, $res['http_code']);
    }

    public function testABestdata404NamesTheWebsiteAndTheCorrectApiUrl()
    {
        $http = $this->http(array('http_code' => 404, 'body' => '', 'error' => null));
        $res = $this->adapter($http)->balance();
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('HTTP 404 at /api/user/', $res['error']);
        $this->assertStringContainsString('app.bestdatanig.com.ng is the website', $res['error']);
        $this->assertStringContainsString('https://bestdatanig.com.ng', $res['error']);
    }

    public function testTheMarketingSiteIsNeverCalled()
    {
        $http = $this->http($this->resp(200, array('user' => array('Account_Balance' => '1000'))));
        $this->adapter($http)->balance();
        $this->assertStringStartsWith('https://bestdatanig.com.ng/api/user/', $http->calls[0]['url']);
        $this->assertStringNotContainsString('app.bestdatanig.com.ng', $http->calls[0]['url']);
    }

    /* ------------------------- balance / connection -------------------- */

    public function testBalanceUsesTheDocumentedUserEndpointAndTokenHeader()
    {
        $http = $this->http($this->resp(200, array(
            'user' => array('username' => 'reseller', 'Account_Balance' => '12,500.75'),
            'notification' => array('message' => ''),
        )));
        $res = $this->adapter($http)->balance();
        $this->assertTrue($res['ok']);
        $this->assertSame('12500.75', $res['balance']);
        $this->assertSame('NGN', $res['currency']);
        $this->assertSame('GET', $http->calls[0]['method']);
        $this->assertStringEndsWith('/api/user/', $http->calls[0]['url']);
        $this->assertContains('Authorization: Token abc123', $http->calls[0]['headers']);
        $this->assertContains('Accept: application/json', $http->calls[0]['headers']);
    }

    public function testA401RetriesWithTheOtherAuthKeyword()
    {
        $http = $this->http();
        $http->route('/api/user/', array(
            array('http_code' => 401, 'body' => '{"detail":"Invalid token."}', 'error' => null),
            $this->resp(200, array('user' => array('wallet_balance' => '250'))),
        ));
        $res = $this->adapter($http)->balance();
        $this->assertTrue($res['ok']);
        $this->assertSame('250', $res['balance']);
        $this->assertSame(2, count($http->calls));
        $this->assertContains('Authorization: Token abc123', $http->calls[0]['headers']);
        $this->assertContains('Authorization: Bearer abc123', $http->calls[1]['headers']);
    }

    public function testAValidUserReplyProvesTheConnectionEvenWithoutABalanceField()
    {
        $http = $this->http($this->resp(200, array('user' => array('username' => 'reseller'))));
        $res = $this->adapter($http)->balance();
        $this->assertTrue($res['ok']);
        $this->assertNull($res['balance']);
    }

    public function testTheFullConnectionTestOnTheLegacyRowNowSucceeds()
    {
        $http = $this->http($this->resp(200, array(
            'user' => array('Account_Balance' => '4200.00'),
        )));
        $provider = $this->provider(array(
            'api_type' => 'STANDARD_VTU', 'api_url' => 'https://app.bestdatanig.com.ng',
        ));
        $ci = new BestdataFakeCI($http, $provider);
        $service = new ProviderSyncService();
        $res = $service->test_connection($provider);

        $this->assertTrue($res['ok'], $res['error'] ?? 'still failing');
        $this->assertStringNotContainsString('HTTP 404', (string)($res['error'] ?? ''));
        $this->assertSame('4200', $res['balance']);
        $this->assertSame('ONLINE', $ci->Provider_model->health[0]['status']);
        $this->assertSame('4200.00000000', $ci->Provider_model->updates[0]['balance']);
        $this->assertStringEndsWith('/api/user/', $http->calls[0]['url']);
    }

    /* ----------------------------- purchases --------------------------- */

    public function testDataPurchaseMatchesThePublishedApiSampleAndAccepts201()
    {
        $http = $this->http($this->resp(201, array(
            'ident' => 'ADA-D4A81B', 'plan_name' => '1GB',
            'plan_network' => 'MTN', 'Status' => 'successful',
        )));
        $res = $this->adapter($http)->data(array(
            'network_code'   => 'MTN-DATA',
            'msisdn'         => '09095263835',
            'variation_code' => '12',
            'reference'      => 'TX-PUBLIC-1',
        ));
        $this->assertTrue($res['ok'], $res['error'] ?? 'purchase failed');
        $this->assertSame('ADA-D4A81B', $res['reference']);
        $this->assertSame('SUCCESSFUL', $res['status']);
        $call = $http->calls[0];
        $this->assertSame('POST', $call['method']);
        $this->assertStringEndsWith('/api/data/', $call['url']);
        $this->assertContains('Authorization: Token abc123', $call['headers']);
        $this->assertContains('Content-Type: application/json', $call['headers']);
        $this->assertSame(0, $call['options']['max_retries'],
            'a mutating call must never be retried into a second top-up');
        $this->assertIsString($call['body'], 'JSON must be sent raw, not form-encoded');
        $this->assertSame(array(
            'network' => 1, 'mobile_number' => '09095263835',
            'plan' => '12', 'Ported_number' => false,
        ), json_decode($call['body'], true));
    }

    public function testAirtimeUsesTopupWithTheVendorFieldNames()
    {
        $http = $this->http($this->resp(201, array('ident' => 'AIR-1', 'Status' => 'successful')));
        $res = $this->adapter($http)->airtime(array(
            'network_code' => 'GLO', 'msisdn' => '08051234567',
            'amount' => '500', 'reference' => 'TX-2',
        ));
        $this->assertTrue($res['ok']);
        $this->assertStringEndsWith('/api/topup/', $http->calls[0]['url']);
        $this->assertSame(array(
            'network' => 2, 'mobile_number' => '08051234567', 'amount' => '500',
            'airtime_type' => 'VTU', 'Ported_number' => false,
        ), json_decode($http->calls[0]['body'], true));
    }

    public function testCablePurchaseResolvesTheVendorNameAndPlanIds()
    {
        $http = $this->http($this->resp(201, array('ident' => 'CAB-1', 'Status' => 'successful')));
        $http->route('/api/cable/', $this->resp(200, array(
            'cablename' => array(
                array('id' => 4, 'name' => 'DSTV'), array('id' => 5, 'name' => 'GOTV'),
            ),
            'DSTVPLAN' => array(array('id' => 17, 'package' => 'Compact', 'plan_amount' => '9900')),
        )));
        $res = $this->adapter($http)->cable(array(
            'provider_code' => 'DSTV', 'variation_code' => 'Compact',
            'smartcard' => '1234567890', 'reference' => 'TX-3',
        ));
        $this->assertTrue($res['ok'], $res['error'] ?? 'cable purchase failed');
        $this->assertSame(array(
            'cablename' => 4, 'cableplan' => 17, 'smart_card_number' => '1234567890',
        ), json_decode($http->calls[count($http->calls) - 1]['body'], true));
        $this->assertStringEndsWith('/api/cablesub/', $http->calls[count($http->calls) - 1]['url']);
    }

    public function testElectricityPurchaseMapsTheDiscoAndMeterType()
    {
        $http = $this->http($this->resp(201, array(
            'ident' => 'BILL-1', 'Status' => 'successful', 'token' => '1234-5678',
        )));
        $http->route('/api/disco/', $this->resp(200, array(
            'disko' => array(array('id' => 6, 'name' => 'Ikeja Electric')),
        )));
        $res = $this->adapter($http)->electricity(array(
            'disco_code' => 'IKEDC', 'meter' => '45001234567',
            'meter_type' => 'POSTPAID', 'amount' => '3000',
            'phone' => '08031112222', 'reference' => 'TX-4',
        ));
        $this->assertTrue($res['ok'], $res['error'] ?? 'bill payment failed');
        $this->assertSame(array('token' => '1234-5678'), $res['detail']);
        $this->assertSame(array(
            'disco_name' => 6, 'amount' => '3000', 'Customer_Phone' => '08031112222',
            'meter_number' => '45001234567', 'MeterType' => 'Postpaid',
        ), json_decode($http->calls[count($http->calls) - 1]['body'], true));
    }

    public function testExamPinPurchaseUsesTheEpinEndpoint()
    {
        $http = $this->http($this->resp(201, array('ident' => 'PIN-1', 'Status' => 'successful')));
        $res = $this->adapter($http)->education(array(
            'exam_code' => 'WAEC', 'quantity' => 2, 'reference' => 'TX-5',
        ));
        $this->assertTrue($res['ok']);
        $this->assertStringEndsWith('/api/epin/', $http->calls[0]['url']);
        $this->assertSame(array('exam_name' => 'WAEC', 'quantity' => 2),
            json_decode($http->calls[0]['body'], true));
    }

    public function testVendorRejectionAndDrfFieldErrorsAreReadable()
    {
        $http = $this->http($this->resp(400, array('network' => array('This field is required.'))));
        $res = $this->adapter($http)->data(array(
            'network_code' => 'MTN', 'msisdn' => '08030000000', 'variation_code' => '12',
        ));
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('network: This field is required.', $res['error']);

        $http2 = $this->http($this->resp(201, array('Status' => 'failed', 'detail' => 'insufficient balance')));
        $res2 = $this->adapter($http2)->data(array(
            'network_code' => 'MTN', 'msisdn' => '08030000000', 'variation_code' => '12',
        ));
        $this->assertFalse($res2['ok']);
        $this->assertStringContainsString('insufficient balance', $res2['error']);
    }

    public function testARejectedTokenProducesAnAuthHint()
    {
        $http = $this->http(array(
            'http_code' => 403, 'body' => '{"detail":"Invalid token."}', 'error' => null,
        ));
        $res = $this->adapter($http)->balance();
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('API token was rejected', $res['error']);
        $this->assertStringContainsString('Invalid token.', $res['error']);
        $this->assertStringNotContainsString('abc123', $res['error'],
            'the decrypted key must never be echoed into an error message');
    }

    /* ------------------------- lookups / catalogue --------------------- */

    public function testStatusReadsTheHistoryList()
    {
        $http = $this->http($this->resp(200, array(
            'data' => array(array('ident' => 'ADA-XYZ', 'Status' => 'successful')),
            'topup' => array(),
        )));
        $res = $this->adapter($http)->status('ADA-XYZ');
        $this->assertTrue($res['ok']);
        $this->assertSame('SUCCESSFUL', $res['status']);
        $this->assertStringEndsWith('/api/history/', $http->calls[0]['url']);
    }

    public function testMeterAndSmartcardVerificationUseTheVendorLookups()
    {
        $http = $this->http($this->resp(200, array(
            'invalid' => false, 'name' => 'CHIDI OKAFOR', 'address' => '12 Marina',
        )));
        $res = $this->adapter($http)->verify(array(
            'meter' => '45001234567', 'disco_code' => 'IKEDC', 'meter_type' => 'PREPAID',
        ));
        $this->assertTrue($res['ok']);
        $this->assertSame('CHIDI OKAFOR', $res['name']);
        $this->assertStringEndsWith('/api/validatemeter/', $http->calls[0]['path']);
        $this->assertStringContainsString('meternumber=45001234567', $http->calls[0]['query']);
        $this->assertStringContainsString('mtype=Prepaid', $http->calls[0]['query']);
        $this->assertStringContainsString('disconame=Ikeja+Electric', $http->calls[0]['query'],
            'the meter lookup needs the vendor display name, not our IKEDC code');

        $http2 = $this->http($this->resp(200, array('invalid' => true, 'name' => 'INVALID IUC')));
        $res2 = $this->adapter($http2)->verify(array(
            'smartcard' => '1234567890', 'disco_code' => 'DSTV',
        ));
        $this->assertFalse($res2['ok']);
        $this->assertStringEndsWith('/api/validateiuc/', $http2->calls[0]['path']);
    }

    public function testVariationsMirrorTheGroupedDataAndCablePriceLists()
    {
        $http = $this->http();
        $http->route('/api/network/', $this->resp(200, array(
            'MTN_PLAN' => array(
                array('id' => 12, 'plan' => '1GB', 'plan_amount' => '1000.00'),
                array('id' => 13, 'package' => '2GB', 'plan_amount' => '₦2,000'),
            ),
        )));
        $http->route('/api/cable/', $this->resp(200, array(
            'DSTVPLAN' => array(array('id' => 17, 'package' => 'Compact', 'plan_amount' => '9900')),
        )));
        $http->route('/api/epin/', $this->resp(200, array(
            array('exam_name' => 'WAEC', 'amount' => '3500'),
            array('exam_name' => 'NECO', 'amount' => '2000'),
        )));
        $adapter = $this->adapter($http);

        $data = $adapter->variations('MTN-DATA');
        $this->assertTrue($data['ok']);
        $this->assertSame('MTN_PLAN', $data['service_id']);
        $this->assertSame(2, count($data['variations']));
        $this->assertSame('12', $data['variations'][0]['variation_code']);
        $this->assertSame('1GB', $data['variations'][0]['name']);
        $this->assertSame('1000.00000000', $data['variations'][0]['amount']);
        $this->assertSame('2000.00000000', $data['variations'][1]['amount']);
        $this->assertTrue($data['variations'][0]['fixed_price']);

        $cable = $adapter->variations('DSTV');
        $this->assertTrue($cable['ok']);
        $this->assertSame('17', $cable['variations'][0]['variation_code']);
        $this->assertSame('Compact', $cable['variations'][0]['name']);

        // Exam PINs come from the flat /api/epin/ price list, filtered to the
        // requested exam body; the variation code is the exam name, because
        // the purchase endpoint takes exam_name, not a numeric plan id.
        $exam = $adapter->variations('WAEC');
        $this->assertTrue($exam['ok'], json_encode($exam));
        $this->assertSame('WAEC', $exam['service_id']);
        $this->assertCount(1, $exam['variations']);
        $this->assertSame('WAEC', $exam['variations'][0]['variation_code']);
        $this->assertSame('WAEC Result Checker PIN', $exam['variations'][0]['name']);
        $this->assertSame('3500.00000000', $exam['variations'][0]['amount']);

        $neco = $adapter->variations('NECO');
        $this->assertTrue($neco['ok']);
        $this->assertSame('NECO', $neco['variations'][0]['variation_code']);

        // Each GET document is fetched once, not once per network.
        $paths = array();
        foreach ($http->calls as $call) $paths[] = $call['path'];
        $this->assertSame(1, count(array_keys($paths, '/api/network/', true)));
        $this->assertSame(1, count(array_keys($paths, '/api/cable/', true)));
        $this->assertSame(1, count(array_keys($paths, '/api/epin/', true)));
    }

    public function testCatalogueTypesClaimEveryPublishedPriceList()
    {
        $types = BestdataAdapter::catalogue_types();
        $this->assertContains('DATA', $types);
        $this->assertContains('CABLE', $types);
        $this->assertContains('EXAM_PIN', $types);
    }

    public function testExamPinVariationsFallBackToUserDocumentWhenEpinIsOrderHistory()
    {
        // Stock MSORG/LegitData returns DRF paginated order history on
        // GET /api/epin/ (with total order amount = unit * quantity) and
        // publishes unit exam prices under GET /api/user/ -> "Exam".
        $http = $this->http();
        $http->route('/api/epin/', $this->resp(200, array(
            'count'    => 1,
            'next'     => null,
            'previous' => null,
            'results'  => array(
                array(
                    'id' => 9, 'exam_name' => 'WAEC', 'quantity' => 2,
                    'amount' => '6800', 'Status' => 'successful',
                    'previous_balance' => '10000', 'after_balance' => '3200',
                    'create_date' => '2026-01-01T00:00:00Z',
                ),
            ),
        )));
        $http->route('/api/user/', $this->resp(200, array(
            'user' => array('Account_Balance' => '3200'),
            'Exam' => array(
                'WAEC' => array('amount' => 3400),
                'NECO' => array('amount' => 1200),
            ),
        )));
        $adapter = $this->adapter($http);

        $waec = $adapter->variations('WAEC');
        $this->assertTrue($waec['ok'], json_encode($waec));
        $this->assertCount(1, $waec['variations']);
        $this->assertSame('WAEC', $waec['variations'][0]['variation_code']);
        $this->assertSame('3400.00000000', $waec['variations'][0]['amount'],
            'must use the unit price from /api/user/, never the multi-quantity order row');
        $this->assertTrue($waec['variations'][0]['fixed_price']);

        $neco = $adapter->variations('NECO');
        $this->assertTrue($neco['ok']);
        $this->assertSame('1200.00000000', $neco['variations'][0]['amount']);

        $jamb = $adapter->variations('JAMB');
        $this->assertTrue($jamb['ok']);
        $this->assertSame(array(), $jamb['variations']);
    }

    public function testSubGroupedDataplansAndStartimeplanShapesAreSupported()
    {
        $http = $this->http();
        $http->route('/api/network/', $this->resp(200, array(
            'Dataplans' => array(
                'MTN_PLAN' => array(
                    'SME' => array(
                        array('id' => 101, 'plan' => '1.0GB', 'plan_type' => 'SME',
                              'month_validate' => '30 Days', 'plan_amount' => '260'),
                    ),
                    'CORPORATE' => array(
                        array('id' => 102, 'plan' => '1.0GB', 'plan_type' => 'CORPORATE GIFTING',
                              'month_validate' => '30 Days', 'plan_amount' => '275'),
                    ),
                ),
            ),
        )));
        $http->route('/api/cable/', $this->resp(200, array(
            'Cableplan' => array(
                'STARTIMEPLAN' => array(
                    array('id' => 55, 'package' => 'Nova', 'plan_amount' => '1500'),
                ),
            ),
        )));
        $adapter = $this->adapter($http);

        $mtn = $adapter->variations('MTN-DATA');
        $this->assertTrue($mtn['ok']);
        $this->assertCount(2, $mtn['variations']);
        $this->assertSame('1.0GB SME - 30 Days', $mtn['variations'][0]['name']);
        $this->assertSame('1.0GB CORPORATE GIFTING - 30 Days', $mtn['variations'][1]['name']);

        $star = $adapter->variations('STARTIMES');
        $this->assertTrue($star['ok']);
        $this->assertSame('55', $star['variations'][0]['variation_code']);
        $this->assertSame('Nova', $star['variations'][0]['name']);
    }

    public function testSyncServicesWritesTheBestdataPriceList()
    {
        $http = $this->http();
        $http->route('/api/network/', $this->resp(200, array(
            'MTN_PLAN' => array(array('id' => 12, 'plan' => '1GB', 'plan_amount' => '1000')),
            'GLO_PLAN' => array(array('id' => 22, 'plan' => '1GB', 'plan_amount' => '950')),
        )));
        $http->route('/api/cable/', $this->resp(200, array(
            'DSTVPLAN' => array(array('id' => 17, 'package' => 'Compact', 'plan_amount' => '9900')),
        )));
        $http->route('/api/epin/', $this->resp(200, array(
            array('exam_name' => 'WAEC', 'amount' => '3500'),
            array('exam_name' => 'NECO', 'amount' => '2000'),
        )));
        $provider = $this->provider();
        $ci = new BestdataFakeCI($http, $provider);
        $service = new ProviderSyncService();
        $res = $service->sync_services($provider);
        $this->assertTrue($res['ok'], $res['error'] ?? 'sync failed');
        // 2 data plans + 1 cable package + 2 exam PINs (JAMB has no row;
        // airtime and electricity stay variable-amount in Admin > Catalogue).
        $this->assertSame(5, $res['inserted']);
        $this->assertSame('SUCCESS', $ci->Provider_model->syncs[0]['status']);
        $this->assertSame(5, count($ci->Vtu_product_model->rows));
    }

    /* ---------------------------- configuration ------------------------ */

    public function testRetryPolicyCanPinTheHostPathsAndAuthScheme()
    {
        $retry = json_encode(array('bestdata' => array(
            'base_url' => 'https://reseller.example/v1',
            'auth_scheme' => 'Bearer',
            'paths' => array('user' => '/account/', 'data' => '/buy/data/'),
            'network_ids' => array('MTN' => 91),
            'ported_number' => true,
        )));
        $http = $this->http($this->resp(200, array('user' => array('balance' => '7'))));
        $adapter = $this->adapter($http, array('retry_policy' => $retry));
        $res = $adapter->balance();
        $this->assertTrue($res['ok']);
        $this->assertSame('https://reseller.example/v1/account/', $http->calls[0]['url']);
        $this->assertContains('Authorization: Bearer abc123', $http->calls[0]['headers']);

        $http2 = $this->http($this->resp(201, array('ident' => 'X', 'Status' => 'successful')));
        $adapter2 = $this->adapter($http2, array('retry_policy' => $retry));
        $adapter2->data(array('network_code' => 'MTN', 'msisdn' => '08030000000',
                              'variation_code' => '5'));
        $this->assertSame('https://reseller.example/v1/buy/data/', $http2->calls[0]['url']);
        $body = json_decode($http2->calls[0]['body'], true);
        $this->assertSame(91, $body['network']);
        $this->assertTrue($body['Ported_number']);
    }

    public function testTheApiDocumentationIsRecordedInTheRepository()
    {
        $doc = file_get_contents(self::$root.'/docs/module-bestdata-provider.md');
        foreach (array('https://app.bestdatanig.com.ng/api', '/api/user/', '/api/network/',
                       '/api/data/', 'Authorization: Token', 'Ported_number',
                       'bestdatanig.com.ng/api', 'HTTP 404') as $needle) {
            $this->assertStringContainsString($needle, $doc, 'missing docs detail: '.$needle);
        }
    }
}

/* ------------------------------ doubles ------------------------------ */

#[AllowDynamicProperties]
class BestdataFakeCI
{
    public $load, $db, $Provider_model, $Provider_service_model;
    public $Vtu_network_model, $Vtu_product_model, $provider_manager, $encryptionservice;
    public $securehttpclient;

    public function __construct($http, $provider)
    {
        $GLOBALS['__fake_ci'] = $this;
        $this->load = new BestdataFakeLoader();
        $this->db = new BestdataFakeDb();
        $this->Provider_model = new BestdataProviderLog();
        $this->Provider_service_model = new stdClass();
        $this->Vtu_network_model = new BestdataNetworks();
        $this->Vtu_product_model = new BestdataProducts();
        $this->encryptionservice = new BestdataPassthroughEncryption();
        $this->securehttpclient = $http;
        $this->provider_manager = new BestdataManager(new BestdataAdapter($provider, $http));
    }
}

class BestdataFakeLoader
{
    public function library($n, $p = null, $o = null) { return $this; }
    public function model($n, $a = null, $d = false) { return $this; }
    public function helper($n = '') { return $this; }
}

class BestdataFakeDb
{
    public function trans_start() {}
    public function trans_complete() {}
}

class BestdataProviderLog
{
    public $health = array(), $syncs = array(), $updates = array();
    public function record_health($id, $status, $ms, $error = null)
    {
        $this->health[] = array('status' => $status, 'ms' => $ms, 'error' => $error);
    }
    public function update_provider($id, array $data) { $this->updates[] = $data; }
    public function record_sync($id, $type, $status, $message = null, $items = 0, $ms = null, $meta = null)
    {
        $this->syncs[] = array('status' => $status, 'items' => $items, 'message' => $message);
    }
}

class BestdataNetworks
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

class BestdataProducts
{
    public $rows = array();
    public function upsert_from_provider($provider_id, $network_id, $service_type, array $v, $sorting = 0)
    {
        $this->rows[] = array('service_type' => $service_type, 'variation' => $v);
        return 'inserted';
    }
}

class BestdataManager
{
    private $adapter;
    public function __construct($adapter) { $this->adapter = $adapter; }
    public function adapter($provider, $family) { return $this->adapter; }
}

class BestdataPassthroughEncryption
{
    public function decrypt($blob) { return (string)$blob; }
    public function open($blob) { return (string)$blob; }
    public function encrypt($plain) { return (string)$plain; }
}

class BestdataFakeHttp
{
    public $calls = array();
    private $routes = array();
    private $default;

    public function __construct($default)
    {
        $this->default = $default;
    }

    /** $response may be one response or a queue of responses. */
    public function route($path, $responses)
    {
        if (isset($responses['http_code']) || isset($responses['body']) || isset($responses['error'])) {
            $responses = array($responses);
        }
        $this->routes[$path] = array_values($responses);
        return $this;
    }

    public function get($url, $headers = array(), $options = array())
    {
        return $this->record('GET', $url, null, $headers, $options);
    }

    public function post($url, $data = null, $headers = array(), $options = array())
    {
        return $this->record('POST', $url, $data, $headers, $options);
    }

    private function record($method, $url, $body, $headers, $options)
    {
        $path = (string)parse_url($url, PHP_URL_PATH);
        $this->calls[] = array(
            'method'  => $method,
            'url'     => $url,
            'path'    => $path,
            'query'   => (string)parse_url($url, PHP_URL_QUERY),
            'body'    => $body,
            'headers' => $headers,
            'options' => $options,
        );
        if (!empty($this->routes[$path])) {
            $response = array_shift($this->routes[$path]);
            if (!$this->routes[$path]) $this->routes[$path] = array($response);
            return $response;
        }
        return $this->default;
    }
}

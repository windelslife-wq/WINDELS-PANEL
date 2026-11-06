<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once __DIR__.'/VtuProviderInterface.php';

/**
 * MockVtuAdapter — offline VTU provider for development and tests.
 *
 * Behaves like a well-mannered real provider: returns references, resolves
 * meter/smartcard names, and rejects rather than throws. Recognises a few
 * trigger values so failure paths can be exercised without network access:
 *
 *   recipient ending 0000  → provider rejection
 *   recipient ending 9999  → async PROCESSING
 */
class MockVtuAdapter implements VtuProviderInterface {

    /** Service types the mock publishes a price list for. */
    private static $catalogue_types = array('DATA', 'CABLE', 'EXAM_PIN');

    /**
     * A deterministic product list, so a MOCK provider can be synced offline
     * and the sync itself can be exercised without a vendor. Codes match the
     * seeded vtu_networks; unknown codes get two generic variations rather
     * than an error, so adding a network never breaks the demo sync.
     */
    private static $variations = array(
        'MTN-DATA' => array(
            array('mtn-500mb', 'MTN 500MB / 30 days', '350.00'),
            array('mtn-1gb',   'MTN 1GB / 30 days',   '700.00'),
        ),
        'GLO-DATA' => array(
            array('glo-1gb', 'Glo 1GB / 30 days', '550.00'),
        ),
        'AIRTEL-DATA' => array(
            array('airtel-1gb', 'Airtel 1GB / 30 days', '650.00'),
        ),
        '9MOBILE-DATA' => array(
            array('9mobile-1gb', '9mobile 1GB / 30 days', '600.00'),
        ),
        'DSTV' => array(
            array('dstv-padi',    'DStv Padi',    '2150.00'),
            array('dstv-compact', 'DStv Compact', '7900.00'),
        ),
        'GOTV' => array(
            array('gotv-smallie', 'GOtv Smallie', '1100.00'),
        ),
        'STARTIMES' => array(
            array('startimes-basic', 'StarTimes Basic', '1500.00'),
        ),
        'WAEC' => array(
            array('waec-pin', 'WAEC Result Checker PIN', '3500.00'),
        ),
        'NECO' => array(
            array('neco-pin', 'NECO Result Checker PIN', '2000.00'),
        ),
        'JAMB' => array(
            array('jamb-pin', 'JAMB Registration PIN', '4500.00'),
        ),
    );

    private $provider;

    public function __construct($provider = null) { $this->provider = $provider; }

    public function airtime(array $p)     { return $this->purchase($p, 'airtime'); }
    public function data(array $p)        { return $this->purchase($p, 'data'); }
    public function cable(array $p)       { return $this->purchase($p, 'cable'); }
    public function electricity(array $p) {
        $res = $this->purchase($p, 'electricity');
        if (!empty($res['ok'])) {
            $res['detail'] = array(
                'token' => sprintf('%04d-%04d-%04d-%04d',
                    rand(1000,9999), rand(1000,9999), rand(1000,9999), rand(1000,9999)),
                'units' => number_format((float)($p['amount'] ?? 0) / 50, 1).' kWh',
            );
        }
        return $res;
    }
    public function education(array $p) {
        $res = $this->purchase($p, 'education');
        if (!empty($res['ok'])) {
            $res['detail'] = array('token' => strtoupper(bin2hex(random_bytes(6))));
        }
        return $res;
    }

    public function verify(array $p) {
        $target = (string)($p['meter'] ?? $p['smartcard'] ?? '');
        if ($target === '' || substr($target, -4) === '0000') {
            return array('ok' => false, 'error' => 'Not found');
        }
        return array('ok' => true, 'name' => 'MOCK CUSTOMER', 'address' => '1 Test Road');
    }

    public function status($reference) {
        return array('ok' => true, 'status' => 'SUCCESSFUL', 'reference' => $reference);
    }

    public function balance() {
        return array('ok' => true, 'balance' => '100000.00', 'currency' => 'NGN');
    }

    /* ---------------------------- catalogue ------------------------------ */

    /** Service types this adapter can mirror into vtu_products. */
    public static function catalogue_types() { return self::$catalogue_types; }

    /**
     * Offline stand-in for a vendor's variation list. Never random: a sync is
     * idempotent and its results are assertable.
     *
     * @return array{ok:bool,variations:array,service_id:string}
     */
    public function variations($network_code) {
        $code = strtoupper((string)$network_code);
        $rows = isset(self::$variations[$code]) ? self::$variations[$code] : array(
            array('mock-basic',  'Mock Basic '.$code,  '500.00'),
            array('mock-premium', 'Mock Premium '.$code, '1500.00'),
        );

        $out = array();
        foreach ($rows as $row) {
            $out[] = array(
                'variation_code' => $row[0],
                'name'           => $row[1],
                'amount'         => number_format((float)$row[2], 8, '.', ''),
                'fixed_price'    => true,
            );
        }
        return array(
            'ok'         => true,
            'service_id' => strtolower($code),
            'variations' => $out,
        );
    }

    private function purchase(array $p, $kind) {
        $target = (string)($p['msisdn'] ?? $p['meter'] ?? $p['smartcard'] ?? '');
        if ($target !== '' && substr($target, -4) === '0000') {
            return array('ok' => false, 'error' => 'Provider declined the transaction');
        }
        $out = array(
            'ok'        => true,
            'reference' => 'MOCK-'.strtoupper($kind).'-'.strtoupper(bin2hex(random_bytes(4))),
        );
        if ($target !== '' && substr($target, -4) === '9999') {
            $out['status'] = 'PROCESSING';
        }
        return $out;
    }
}

<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// Instantiated with `new` below; CI3 does not autoload plain library
// classes, so require the dependency explicitly.
require_once __DIR__.'/SecureHttpClient.php';

require_once __DIR__.'/VtuProviderInterface.php';

/**
 * StandardVtuAdapter — HTTP adapter for the common Nigerian VTU API shape
 * (VTpass-style: POST with a request_id, `code` 000 for success).
 *
 * Endpoint paths and field names vary between vendors, so they come from the
 * provider row's `retry_policy` JSON blob under a `vtu` key rather than being
 * hardcoded. A provider whose API differs structurally gets its own adapter
 * class registered in Provider_manager; this one covers the common case.
 *
 * The same blob configures the catalogue/product-list call used by the admin
 * "Sync services" action: `variations` (path), `variations_param` (query or
 * POST field, default `serviceID`), `variations_method` (`GET`, the default,
 * or `POST`) and `service_ids` (our network code → vendor serviceID). Every
 * registered VTU adapter answers `variations()`/`catalogue_types()`, so a
 * STANDARD_VTU provider can sync its catalogue like VTPASS does.
 *
 * All traffic goes through SecureHttpClient, which enforces the SSRF and
 * protocol restrictions from Session 17. The API key is decrypted per call and
 * never logged.
 */
class StandardVtuAdapter implements VtuProviderInterface {

    /** Provider result codes that mean "accepted, still settling". */
    private static $pending_codes = array('099', 'pending', 'PENDING', 'processing');

    /** Service types this adapter can pull a price list for. */
    private static $catalogue_types = array('DATA', 'CABLE', 'EXAM_PIN');

    private $provider;
    private $http;
    private $paths;
    private $service_map;
    private $variations_param;
    private $variations_method;

    public function __construct($provider_row, $http = null) {
        $this->provider = $provider_row;
        $timeout = isset($provider_row->timeout_ms) ? $provider_row->timeout_ms / 1000 : 15;
        $this->http = $http ?: new SecureHttpClient(array('timeout' => $timeout));

        $cfg = array();
        if (!empty($provider_row->retry_policy)) {
            $decoded = json_decode($provider_row->retry_policy, true);
            // Only the `vtu` block is ours. Other retry settings (maxRetries,
            // backoffMs) belong to SecureHttpClient and must never be mistaken
            // for endpoint paths.
            if (is_array($decoded) && isset($decoded['vtu']) && is_array($decoded['vtu'])) {
                $cfg = $decoded['vtu'];
            }
        }
        $this->paths = array_merge(array(
            'airtime'     => '/pay',
            'data'        => '/pay',
            'cable'       => '/pay',
            'electricity' => '/pay',
            'education'   => '/pay',
            'verify'      => '/merchant-verify',
            'status'      => '/requery',
            'balance'     => '/balance',
            // The common VTpass-style product list. Override per vendor with
            // retry_policy -> vtu -> variations.
            'variations'  => '/service-variations',
        ), $cfg);

        // Purchases pass our network code straight through as serviceID; a
        // vendor with its own ids can map them here without a code change.
        $this->service_map = array();
        if (isset($cfg['service_ids']) && is_array($cfg['service_ids'])) {
            foreach ($cfg['service_ids'] as $code => $id) {
                $this->service_map[strtoupper((string)$code)] = (string)$id;
            }
        }
        $param = isset($cfg['variations_param']) ? trim((string)$cfg['variations_param']) : '';
        $this->variations_param = $param !== '' ? $param : 'serviceID';
        $method = strtoupper(trim((string)($cfg['variations_method'] ?? 'GET')));
        $this->variations_method = $method === 'POST' ? 'POST' : 'GET';
    }

    /* ----------------------------- purchases ---------------------------- */

    public function airtime(array $p) {
        return $this->purchase('airtime', array(
            'serviceID' => $this->service_id($p, 'network_code'),
            'amount'    => $p['amount'] ?? null,
            'phone'     => $p['msisdn'] ?? null,
        ), $p);
    }

    public function data(array $p) {
        return $this->purchase('data', array(
            'serviceID'       => $this->service_id($p, 'network_code'),
            'billersCode'     => $p['msisdn'] ?? null,
            'variation_code'  => $p['variation_code'] ?? null,
            'phone'           => $p['msisdn'] ?? null,
        ), $p);
    }

    public function cable(array $p) {
        return $this->purchase('cable', array(
            'serviceID'      => $this->service_id($p, 'provider_code'),
            'billersCode'    => $p['smartcard'] ?? null,
            'variation_code' => $p['variation_code'] ?? null,
            'phone'          => $p['phone'] ?? null,
        ), $p);
    }

    public function electricity(array $p) {
        return $this->purchase('electricity', array(
            'serviceID'      => $this->service_id($p, 'disco_code'),
            'billersCode'    => $p['meter'] ?? null,
            'variation_code' => $p['meter_type'] ?? null,
            'amount'         => $p['amount'] ?? null,
            'phone'          => $p['phone'] ?? null,
        ), $p);
    }

    public function education(array $p) {
        return $this->purchase('education', array(
            'serviceID'      => $this->service_id($p, 'exam_code'),
            'variation_code' => $p['variation_code'] ?? null,
            'quantity'       => $p['quantity'] ?? 1,
            'phone'          => $p['phone'] ?? null,
        ), $p);
    }

    /* ------------------------------ lookups ----------------------------- */

    public function verify(array $p) {
        $res = $this->call($this->paths['verify'], array(
            'serviceID'   => $this->service_id($p, 'disco_code'),
            'billersCode' => $p['meter'] ?? $p['smartcard'] ?? null,
            'type'        => $p['meter_type'] ?? null,
        ));
        if (empty($res['ok'])) return $res;

        $data = $res['data'];
        $inner = isset($data['content']) && is_array($data['content']) ? $data['content'] : $data;
        $name = $inner['Customer_Name'] ?? $inner['customer_name'] ?? $inner['name'] ?? null;
        if (!$name) {
            return array('ok' => false, 'error' => 'Could not resolve that account');
        }
        return array(
            'ok'      => true,
            'name'    => $name,
            'address' => $inner['Address'] ?? $inner['address'] ?? null,
            'raw'     => $inner,
        );
    }

    public function status($reference) {
        $res = $this->call($this->paths['status'], array('request_id' => $reference));
        if (empty($res['ok'])) return $res;
        $data = $res['data'];
        $inner = isset($data['content']['transactions']) ? $data['content']['transactions'] : $data;
        $raw = (string)($inner['status'] ?? '');
        return array(
            'ok'        => true,
            'status'    => $this->map_status($raw),
            'reference' => $reference,
            'raw'       => $raw,
        );
    }

    public function balance() {
        $res = $this->call($this->paths['balance'], array(), 'GET');
        if (empty($res['ok'])) return $res;
        $data = $res['data'];
        $balance = $data['contents']['balance'] ?? $data['balance'] ?? null;
        if ($balance === null) return array('ok' => false, 'error' => 'No balance in response');
        return array(
            'ok'       => true,
            'balance'  => (string)$balance,
            'currency' => $this->provider->currency ?? 'NGN',
        );
    }

    /* ---------------------------- catalogue ------------------------------ */

    /** Service types this adapter can mirror into vtu_products. */
    public static function catalogue_types() { return self::$catalogue_types; }

    /**
     * Variations (data bundles, cable packages, exam PIN types) for one of our
     * network codes. The admin catalogue sync calls this once per active
     * network; a vendor that does not carry a network answers ok:false and the
     * sync skips it rather than aborting the other twenty.
     *
     * Response shapes vary, so the row list is found in any of the places the
     * common Nigerian VTU APIs put it (content.variations, the VTpass
     * `varations` typo, data.variations, a bare list, ...) and normalised into
     * the one shape Vtu_product_model::upsert_from_provider() reads.
     *
     * @return array{ok:bool,variations?:array,service_id?:string,error?:string}
     */
    public function variations($network_code) {
        $service_id = $this->catalogue_service_id($network_code);
        $param = $this->variations_param;
        $path  = isset($this->paths['variations']) && is_scalar($this->paths['variations'])
            ? (string)$this->paths['variations'] : '/service-variations';

        if ($this->variations_method === 'POST') {
            $res = $this->call($path, array($param => $service_id), 'POST');
        } else {
            // GET carries no body in SecureHttpClient, so the id goes on the
            // query string. Respect a configured path that already has one.
            $join = strpos($path, '?') === false ? '?' : '&';
            $res = $this->call(
                $path.$join.rawurlencode($param).'='.rawurlencode($service_id),
                array(), 'GET'
            );
        }
        if (empty($res['ok'])) {
            return array('ok' => false, 'error' => $res['error'] ?? 'Could not list products');
        }

        $rows = $this->variation_rows($res['data']);
        if ($rows === null) {
            $data = $res['data'];
            $message = null;
            foreach (array('response_description', 'message', 'error', 'detail') as $key) {
                if (isset($data[$key]) && is_scalar($data[$key]) && trim((string)$data[$key]) !== '') {
                    $message = (string)$data[$key];
                    break;
                }
            }
            $code = isset($data['code']) && is_scalar($data['code']) ? (string)$data['code'] : '';
            // A numeric-only description (VTpass puts "000" there) is a code,
            // not an explanation; don't surface it as an error message.
            if ($message !== null && preg_match('/^\d{3}$/', trim($message))) $message = null;
            if ($message === null) {
                $message = in_array(strtolower(trim($code)), array('', '000', '0', '1', '200'), true)
                    ? 'The provider returned no variations for '.$service_id
                    : 'Provider code '.$code;
            }
            return array('ok' => false, 'error' => $message);
        }

        $out = array();
        foreach ($rows as $row) {
            $normalized = $this->normalize_variation($row);
            if ($normalized) $out[] = $normalized;
        }
        return array('ok' => true, 'service_id' => $service_id, 'variations' => $out);
    }

    /** Our network code translated into the vendor's serviceID. */
    private function catalogue_service_id($network_code) {
        $code = (string)$network_code;
        $key = strtoupper($code);
        return isset($this->service_map[$key]) ? $this->service_map[$key] : $code;
    }

    /** @return array|null the variation rows, or null when the reply has none. */
    private function variation_rows(array $data) {
        // Named lists: an empty one is a real (empty) answer, not an error.
        $named = array(
            $data['content']['variations'] ?? null,
            $data['content']['varations'] ?? null,   // VTpass ships this typo
            $data['contents']['variations'] ?? null,
            $data['data']['variations'] ?? null,
            $data['variations'] ?? null,
            $data['products'] ?? null,
        );
        foreach ($named as $rows) {
            if (is_array($rows) && $this->is_row_list($rows)) return array_values($rows);
        }
        // Some vendors omit the wrapper and return the rows as `content` or
        // `data` directly. An *empty* wrapper here means a rejected request
        // ({"code":"012","content":{}}), not an empty price list.
        foreach (array($data['content'] ?? null, $data['data'] ?? null) as $rows) {
            if (is_array($rows) && $rows && $this->is_row_list($rows)) return array_values($rows);
        }
        return null;
    }

    /** A variation list is a list of arrays, not a keyed object like `content`. */
    private function is_row_list(array $rows) {
        if (!$rows) return true;                 // an empty list is still a list
        foreach ($rows as $row) {
            if (!is_array($row)) return false;
        }
        return true;
    }

    /** One vendor row → the shape ProviderSyncService writes to vtu_products. */
    private function normalize_variation($row) {
        if (!is_array($row)) return null;
        $code = $row['variation_code'] ?? ($row['code'] ?? ($row['product_code'] ?? ($row['id'] ?? null)));
        if (!is_scalar($code) || trim((string)$code) === '') return null;

        $name = $row['name'] ?? ($row['variation_name'] ?? ($row['title'] ?? null));
        if (!is_scalar($name)) $name = null;
        $amount = $row['variation_amount'] ?? ($row['amount'] ?? ($row['price'] ?? ($row['cost'] ?? null)));
        $out = array(
            'variation_code' => (string)$code,
            'name'           => (string)($name ?? $code),
            'amount'         => $amount !== null && is_numeric($amount)
                ? number_format((float)$amount, 8, '.', '') : null,
            'fixed_price'    => !isset($row['fixedPrice']) || !$this->is_false($row['fixedPrice']),
        );
        return $out;
    }

    private function is_false($value) {
        if (is_bool($value)) return !$value;
        if (is_numeric($value)) return (float)$value === 0.0;
        return in_array(strtolower(trim((string)$value)), array('no', 'false', 'off', '0', ''), true);
    }

    /* ------------------------------ internals ---------------------------- */

    /**
     * A purchase always carries the engine's reference as request_id, which is
     * what makes a provider-side retry idempotent.
     */
    private function purchase($kind, array $fields, array $p) {
        $fields['request_id'] = $p['reference'] ?? null;
        $res = $this->call($this->paths[$kind], array_filter($fields, function ($v) {
            return $v !== null && $v !== '';
        }));
        if (empty($res['ok'])) return $res;

        $data = $res['data'];
        $code = (string)($data['code'] ?? '');
        $inner = isset($data['content']['transactions']) ? $data['content']['transactions'] : array();

        if ($code !== '' && $code !== '000') {
            return array(
                'ok'    => false,
                'error' => $data['response_description'] ?? ('Provider code '.$code),
            );
        }

        $status = $this->map_status((string)($inner['status'] ?? 'delivered'));
        $out = array(
            'ok'        => true,
            'reference' => (string)($inner['transactionId'] ?? $data['requestId'] ?? $fields['request_id']),
            'status'    => $status,
        );
        if (isset($inner['amount'])) $out['cost'] = (string)$inner['amount'];

        // Electricity tokens and exam PINs arrive inline.
        $detail = array();
        $content = $data['content'] ?? array();
        foreach (array('token' => array('token', 'Token', 'purchased_code'),
                       'units' => array('units', 'Units')) as $key => $candidates) {
            foreach ($candidates as $c) {
                if (!empty($content[$c])) { $detail[$key] = (string)$content[$c]; break; }
                if (!empty($data[$c]))    { $detail[$key] = (string)$data[$c]; break; }
            }
        }
        if ($detail) $out['detail'] = $detail;

        return $out;
    }

    private function map_status($raw) {
        $raw = strtolower(trim($raw));
        if (in_array($raw, array('delivered', 'successful', 'success', 'completed'), true)) {
            return 'SUCCESSFUL';
        }
        if (in_array(strtolower($raw), array_map('strtolower', self::$pending_codes), true)
            || in_array($raw, array('initiated', 'pending', 'processing'), true)) {
            return 'PROCESSING';
        }
        if (in_array($raw, array('failed', 'reversed', 'declined'), true)) {
            return 'FAILED';
        }
        return 'PROCESSING';
    }

    private function service_id(array $p, $key) {
        return $p[$key] ?? $p['network_code'] ?? null;
    }

    private function api_key() {
        $ci =& get_instance();
        $ci->load->library('EncryptionService');
        return $ci->encryptionservice->decrypt($this->provider->api_key_encrypted);
    }

    /**
     * @return array{ok:bool,data?:array,error?:string,http_code?:int}
     */
    private function call($path, array $payload, $method = 'POST') {
        $url = rtrim($this->provider->api_url, '/').'/'.ltrim($path, '/');
        $headers = array(
            'Authorization: Bearer '.$this->api_key(),
            'Accept: application/json',
        );
        try {
            $res = $method === 'GET'
                ? $this->http->get($url, $headers)
                : $this->http->post($url, $payload, $headers);
        } catch (Exception $e) {
            // Never leak the key or URL into logs.
            log_message('error', 'VTU adapter transport error: '.$e->getMessage());
            return array('ok' => false, 'error' => 'Provider unreachable');
        }

        $code = isset($res['http_code']) ? (int)$res['http_code'] : 0;
        if ($code === 0) {
            return array(
                'ok' => false,
                'error' => $res['error'] ?? 'Provider unreachable',
                'http_code' => $code,
            );
        }
        if ($code < 200 || $code >= 300) {
            // A bare "HTTP 404" left operators staring at the wrong problem.
            // In practice it means the base URL is a dashboard/marketing site
            // rather than the API, or the path belongs to another vendor.
            if ($code === 404) {
                $error = 'HTTP 404 at '.$path.' — the API URL is a website or the '
                        .'wrong path. Check the provider dashboard for its API base '
                        .'(Bestdatanig: https://bestdatanig.com.ng with type BESTDATA).';
            } elseif ($code === 401 || $code === 403) {
                $error = 'HTTP '.$code.' — the provider rejected the API key.';
            } else {
                $error = $res['error'] ?? ('HTTP '.$code);
            }
            return array('ok' => false, 'error' => $error, 'http_code' => $code);
        }
        $data = json_decode(isset($res['body']) ? $res['body'] : '', true);
        if (!is_array($data)) {
            return array('ok' => false, 'error' => 'Malformed provider response');
        }
        return array('ok' => true, 'data' => $data);
    }
}

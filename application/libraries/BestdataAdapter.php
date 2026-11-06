<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// Instantiated with `new` by Provider_manager; CI3 does not autoload plain
// library classes, so the dependencies are required explicitly.
require_once __DIR__.'/SecureHttpClient.php';
require_once __DIR__.'/VtuProviderInterface.php';

/**
 * BestdataAdapter — Bestdatanig / AdaData VTU API (Django REST framework).
 *
 * Why this adapter exists. An operator pointed a STANDARD_VTU provider at
 * https://app.bestdatanig.com.ng/ and pressed "Test connection". That host is
 * the Next.js marketing site, not the API, and the VTpass-shaped
 * StandardVtuAdapter asked it for GET /balance — it answered HTTP 404, which
 * surfaced as "Connection failed: HTTP 404 (nnn ms)." The published developer
 * page (https://app.bestdatanig.com.ng/api) documents a completely different
 * protocol: Authorization: Token <key>, JSON bodies, POST /api/data/ with
 * {network, mobile_number, plan, Ported_number} and a 201 response carrying
 * {ident, plan_name, plan_network, Status}. /api-docs is login-gated, but the
 * live endpoints were confirmed against https://bestdatanig.com.ng/api/ and
 * the vendor's public API reference (see docs/module-bestdata-provider.md).
 *
 * Confirmed endpoints (all relative to the API origin, https://bestdatanig.com.ng):
 *
 *   GET  /api/user/             account + wallet balance
 *   GET  /api/get/network/      network names and numeric network ids
 *   GET  /api/network/          data plans, grouped MTN_PLAN / GLO_PLAN / ...
 *   GET  /api/cable/            cable names and packages
 *   GET  /api/disco/            electricity distribution companies
 *   GET  /api/history/          last 10 transactions of each service
 *   GET  /api/data/             data transactions   POST /api/data/  buy data
 *   GET  /api/topup/            airtime transactions POST /api/topup/ buy airtime
 *   GET  /api/cablesub/         cable transactions  POST /api/cablesub/
 *   GET  /api/billpayment/      bill transactions   POST /api/billpayment/
 *   GET  /api/epin/             result checker pins POST /api/epin/
 *   GET  /api/validatemeter/    meter lookup        GET /api/validateiuc/
 *
 * All traffic goes through SecureHttpClient (TLS verify ON, SSRF guards). The
 * API key is decrypted per call and never logged. Purchase calls are sent with
 * max_retries=0 so a lost response can never turn into a second top-up.
 *
 * Endpoint paths, ids and the auth keyword can be overridden per row from
 * `retry_policy` JSON under a `bestdata` key, so a reseller running the same
 * Django script on another host needs configuration, not a code change:
 *
 *   {"bestdata": {
 *      "base_url": "https://reseller.example",
 *      "auth_scheme": "Bearer",           // Token | Bearer (default: try both)
 *      "paths": {"user": "/api/user/"},
 *      "network_ids": {"MTN": 1},
 *      "cable_ids": {"DSTV": 1},
 *      "disco_ids": {"IKEDC": 2},
 *      "disco_names": {"IKEDC": "Ikeja Electric"},
 *      "airtime_type": "VTU",
 *      "ported_number": false
 *   }}
 */
class BestdataAdapter implements VtuProviderInterface {

    /** The live Django REST origin. The `app.` host is a website, not an API. */
    const API_BASE = 'https://bestdatanig.com.ng';

    /** Fixed-price lists this vendor publishes via its price-list endpoints. */
    private static $catalogue_types = array('DATA', 'CABLE', 'EXAM_PIN');

    /** Network ids from GET /api/get/network/ (MTN=1 in the published sample). */
    private static $default_network_ids = array(
        'MTN' => 1, 'GLO' => 2, '9MOBILE' => 3, 'AIRTEL' => 4, 'SMILE' => 5,
    );

    /** Our network codes -> the vendor's plan-list keys. */
    private static $data_groups = array(
        'MTN' => 'MTN_PLAN', 'GLO' => 'GLO_PLAN',
        'AIRTEL' => 'AIRTEL_PLAN', '9MOBILE' => '9MOBILE_PLAN',
        'SMILE' => 'SMILE_PLAN',
    );

    /** Our cable codes -> the vendor's package-list keys. */
    private static $cable_groups = array(
        'DSTV' => 'DSTVPLAN', 'GOTV' => 'GOTVPLAN',
        'STARTIMES' => 'STARTIME', 'STARTIME' => 'STARTIME',
    );

    /** Our exam-body codes -> the vendor's exam_name values (sent back on purchase). */
    private static $exam_groups = array(
        'WAEC' => 'WAEC', 'NECO' => 'NECO', 'JAMB' => 'JAMB',
        'NABTEB' => 'NABTEB', 'NBAIS' => 'NBAIS',
    );

    /** Words used to match our disco abbreviations against /api/disco/ names. */
    private static $disco_aliases = array(
        'IKEDC' => 'IKEJA', 'EKEDC' => 'EKO', 'AEDC' => 'ABUJA',
        'PHED' => 'PORT HARCOURT', 'IBEDC' => 'IBADAN', 'KEDCO' => 'KANO',
        'JED' => 'JOS', 'BEDC' => 'BENIN', 'KAEDCO' => 'KADUNA',
    );

    /**
     * The meter lookup does a `Disco_provider_name.objects.get(name=...)`, so
     * it needs the vendor's display name, not our abbreviation or its DB id.
     */
    private static $disco_names = array(
        'IKEDC' => 'Ikeja Electric', 'EKEDC' => 'Eko Electric',
        'AEDC' => 'Abuja Electric', 'PHED' => 'Port Harcourt Electric',
        'IBEDC' => 'Ibadan Electric', 'KEDCO' => 'Kano Electric',
        'JED' => 'Jos Electric', 'KAEDCO' => 'Kaduna Electric',
    );

    private static $default_paths = array(
        'user'            => '/api/user/',
        'networks'        => '/api/get/network/',
        'plans'           => '/api/network/',
        'cable'           => '/api/cable/',
        'disco'           => '/api/disco/',
        'history'         => '/api/history/',
        'validate_meter'  => '/api/validatemeter/',
        'validate_iuc'    => '/api/validateiuc/',
        'airtime'         => '/api/topup/',
        'data'            => '/api/data/',
        'cablesub'        => '/api/cablesub/',
        'billpayment'     => '/api/billpayment/',
        'epin'            => '/api/epin/',
    );

    private $provider;
    private $http;
    private $base;
    private $paths;
    private $cfg;
    private $network_ids;
    private $auth_schemes;
    private $documents = array();

    public function __construct($provider_row, $http = null) {
        $this->provider = $provider_row;
        $timeout = isset($provider_row->timeout_ms) ? $provider_row->timeout_ms / 1000 : 15;
        $this->http = $http ?: new SecureHttpClient(array('timeout' => $timeout));
        $this->cfg = self::read_config($provider_row);
        $raw = isset($this->cfg['base_url']) && is_string($this->cfg['base_url'])
                && trim($this->cfg['base_url']) !== ''
            ? trim($this->cfg['base_url'])
            : (isset($provider_row->api_url) ? (string)$provider_row->api_url : '');
        $this->base = self::normalize_base_url($raw);

        $this->paths = self::$default_paths;
        if (isset($this->cfg['paths']) && is_array($this->cfg['paths'])) {
            foreach ($this->cfg['paths'] as $key => $path) {
                if (is_scalar($path) && trim((string)$path) !== '') {
                    $this->paths[$key] = trim((string)$path);
                }
            }
        }

        $this->network_ids = self::$default_network_ids;
        foreach (array('network_ids', 'cable_ids', 'disco_ids') as $key) {
            if (!isset($this->cfg[$key]) || !is_array($this->cfg[$key])) continue;
            foreach ($this->cfg[$key] as $code => $id) {
                $this->network_ids[strtoupper((string)$code)] = $id;
            }
        }

        // The published docs say `Authorization: Token <key>`; the live DRF
        // deployment advertises `Bearer realm="api"`. Most installs accept the
        // token keyword from their dashboard, so try that first and fall back
        // on a 401 — which is always safe: DRF rejects before the view runs.
        $this->auth_schemes = array('Token', 'Bearer');
        if (isset($this->cfg['auth_scheme']) && is_string($this->cfg['auth_scheme'])) {
            $pinned = trim($this->cfg['auth_scheme']);
            if ($pinned === 'Token' || $pinned === 'Bearer') {
                $this->auth_schemes = array($pinned);
            }
        }
    }

    /**
     * True when a provider row clearly points at a Bestdatanig/AdaData host.
     * Provider_manager uses this to upgrade STANDARD_VTU rows saved before
     * this adapter existed, instead of asking the marketing site for /balance.
     */
    public static function is_bestdata_url($url) {
        $url = strtolower(trim((string)$url));
        if ($url === '') return false;
        $parts = parse_url($url);
        $host = is_array($parts) && !empty($parts['host']) ? $parts['host'] : $url;
        return preg_match('/(?:^|\.)bestdatanig\.com\.ng$/', $host) === 1
            || preg_match('/(?:^|\.)adadata\.com\.ng$/', $host) === 1;
    }

    /**
     * Turn a stored URL into the API origin paths below are relative to.
     *
     * app.bestdatanig.com.ng (the website) and api.adadata.com.ng (the dead
     * placeholder in the marketing sample, which does not resolve) both become
     * the live Django host. A trailing /api is dropped because every default
     * path already starts with /api; any other path is kept as a proxy prefix.
     */
    public static function normalize_base_url($url) {
        $url = trim((string)$url);
        if ($url === '') return self::API_BASE;
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) return rtrim($url, '/');
        $host = strtolower($parts['host']);
        $scheme = strtolower(isset($parts['scheme']) ? $parts['scheme'] : 'https');
        if ($scheme !== 'http' && $scheme !== 'https') $scheme = 'https';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        if (preg_match('/(?:^|\.)bestdatanig\.com\.ng$/', $host) || $host === 'api.adadata.com.ng') {
            $host = 'bestdatanig.com.ng';
            $port = '';
        }
        $path = isset($parts['path']) ? rtrim($parts['path'], '/') : '';
        $path = preg_replace('#/api$#i', '', $path);
        return rtrim($scheme.'://'.$host.$port.$path, '/');
    }

    /** The base actually used — handy for the admin hint and for tests. */
    public function base_url() { return $this->base; }

    /* ----------------------------- purchases ---------------------------- */

    /** VTpass-style adapters use /pay; this vendor uses POST /api/topup/. */
    public function airtime(array $p) {
        $network = $this->network_id($p['network_code'] ?? null);
        if ($network === null) return $this->unknown_network($p['network_code'] ?? null);
        return $this->purchase($this->paths['airtime'], array(
            'network'       => $network,
            'mobile_number' => $p['msisdn'] ?? null,
            'amount'        => $p['amount'] ?? null,
            'airtime_type'  => isset($this->cfg['airtime_type']) && is_scalar($this->cfg['airtime_type'])
                ? (string)$this->cfg['airtime_type'] : 'VTU',
            'Ported_number' => $this->ported($p),
        ), $p);
    }

    /** Published sample: {network:1, mobile_number:"09095263835", plan:12, Ported_number:false}. */
    public function data(array $p) {
        $network = $this->network_id($p['network_code'] ?? null);
        if ($network === null) return $this->unknown_network($p['network_code'] ?? null);
        return $this->purchase($this->paths['data'], array(
            'network'       => $network,
            'mobile_number' => $p['msisdn'] ?? null,
            'plan'          => $p['variation_code'] ?? null,
            'Ported_number' => $this->ported($p),
        ), $p);
    }

    public function cable(array $p) {
        $name = $this->resolve_name_id(
            $this->paths['cable'], 'cablename', $p['provider_code'] ?? null,
            'cable_ids', self::$cable_groups
        );
        $plan = $this->resolve_plan_id($this->paths['cable'], $p['variation_code'] ?? null);
        if ($name === null) return $this->unknown_code('cable provider', $p['provider_code'] ?? null);
        if ($plan === null) return $this->unknown_code('cable package', $p['variation_code'] ?? null);
        return $this->purchase($this->paths['cablesub'], array(
            'cablename'         => $name,
            'cableplan'         => $plan,
            'smart_card_number' => $p['smartcard'] ?? null,
        ), $p);
    }

    public function electricity(array $p) {
        $disco = $this->resolve_disco_id($p['disco_code'] ?? null);
        if ($disco === null) return $this->unknown_code('distribution company', $p['disco_code'] ?? null);
        $type = strtoupper((string)($p['meter_type'] ?? 'PREPAID')) === 'POSTPAID'
            ? 'Postpaid' : 'Prepaid';
        return $this->purchase($this->paths['billpayment'], array(
            'disco_name'     => $disco,
            'amount'         => $p['amount'] ?? null,
            'Customer_Phone' => $p['phone'] ?? null,
            'meter_number'   => $p['meter'] ?? null,
            'MeterType'      => $type,
        ), $p);
    }

    public function education(array $p) {
        $exam = $this->resolve_exam_name($p);
        if ($exam === null) {
            return $this->unknown_code('exam body', $p['exam_code'] ?? ($p['variation_code'] ?? null));
        }
        $qty = isset($p['quantity']) && is_numeric($p['quantity']) ? max(1, (int)$p['quantity']) : 1;
        return $this->purchase($this->paths['epin'], array(
            'exam_name' => $exam,
            'quantity'  => $qty,
        ), $p);
    }

    /* ------------------------------ lookups ----------------------------- */

    public function verify(array $p) {
        $card = $p['smartcard'] ?? null;
        if ($card !== null && trim((string)$card) !== '') {
            $path = $this->paths['validate_iuc'].$this->query(array(
                'smart_card_number' => $card,
                'cablename'         => $p['disco_code'] ?? null,
            ), $this->paths['validate_iuc']);
        } else {
            $path = $this->paths['validate_meter'].$this->query(array(
                'meternumber' => $p['meter'] ?? null,
                'disconame'   => $this->disco_name($p['disco_code'] ?? null),
                'mtype'       => strtoupper((string)($p['meter_type'] ?? 'PREPAID')) === 'POSTPAID'
                    ? 'Postpaid' : 'Prepaid',
            ), $this->paths['validate_meter']);
        }
        $res = $this->call($path, null, 'GET');
        if (empty($res['ok'])) return $res;
        $data = $res['data'];
        if (!empty($data['invalid'])) {
            return array('ok' => false, 'error' => (string)($data['name'] ?? 'That account could not be verified'));
        }
        $name = $data['name'] ?? ($data['Customer_Name'] ?? ($data['customer_name'] ?? null));
        if (!is_scalar($name) || trim((string)$name) === '') {
            return array('ok' => false, 'error' => 'Could not resolve that account');
        }
        return array(
            'ok'      => true,
            'name'    => (string)$name,
            'address' => isset($data['address']) && is_scalar($data['address']) ? (string)$data['address'] : null,
            'raw'     => $data,
        );
    }

    /**
     * GET /api/history/ returns the last ten data/topup/cablesub transactions.
     * Exam-PIN (`Result_Checker_Pin_order`) and electricity (`BillPayment`)
     * orders are omitted from /api/history/ on stock nelly_api installs and
     * instead live under /api/epin/<id> (or /api/epin/) and /api/billpayment/<id>.
     */
    public function status($reference) {
        $reference = trim((string)$reference);
        if ($reference === '') {
            return array('ok' => false, 'error' => 'Transaction reference is empty');
        }
        $res = $this->call($this->paths['history'], null, 'GET');
        if (empty($res['ok'])) return $res;
        foreach ($res['data'] as $list) {
            if (!is_array($list)) continue;
            if (isset($list['results']) && is_array($list['results'])) $list = $list['results'];
            foreach ($list as $row) {
                if (!is_array($row)) continue;
                $ident = $row['ident'] ?? ($row['id'] ?? null);
                if ($ident === null || (string)$ident !== $reference) continue;
                return $this->status_from_row($reference, $row);
            }
        }

        if (ctype_digit($reference)) {
            foreach (array('epin', 'billpayment') as $kind) {
                $item_path = rtrim($this->paths[$kind], '/').'/'.$reference;
                $item = $this->call($item_path, null, 'GET');
                if (!empty($item['ok']) && is_array($item['data'])) {
                    $ident = $item['data']['ident'] ?? ($item['data']['id'] ?? null);
                    if ($ident !== null && (string)$ident === $reference) {
                        return $this->status_from_row($reference, $item['data']);
                    }
                }
            }
            $epin_list = $this->call($this->paths['epin'], null, 'GET');
            if (!empty($epin_list['ok']) && is_array($epin_list['data'])) {
                $rows = isset($epin_list['data']['results']) && is_array($epin_list['data']['results'])
                    ? $epin_list['data']['results'] : $epin_list['data'];
                foreach ($rows as $row) {
                    if (!is_array($row)) continue;
                    $ident = $row['ident'] ?? ($row['id'] ?? null);
                    if ($ident !== null && (string)$ident === $reference) {
                        return $this->status_from_row($reference, $row);
                    }
                }
            }
        }

        return array('ok' => false, 'error' => 'Transaction not found in the provider history');
    }

    private function status_from_row($reference, array $row) {
        $out = array(
            'ok'        => true,
            'status'    => $this->map_status((string)($row['Status'] ?? ($row['status'] ?? 'processing'))),
            'reference' => (string)$reference,
            'raw'       => $row,
        );
        $detail = $this->extract_detail($row);
        if ($detail) $out['detail'] = $detail;
        return $out;
    }

    /** GET /api/user/ doubles as the connection/auth probe. */
    public function balance() {
        $res = $this->call($this->paths['user'], null, 'GET');
        if (empty($res['ok'])) return $res;
        $data = $res['data'];
        $user = isset($data['user']) && is_array($data['user']) ? $data['user'] : $data;
        $balance = null;
        foreach (array('Account_Balance', 'account_balance', 'wallet_balance',
                       'balance', 'Balance', 'bonus_balance') as $key) {
            if (array_key_exists($key, $user)) $balance = $this->money($user[$key]);
            if ($balance === null && array_key_exists($key, $data)) $balance = $this->money($data[$key]);
            if ($balance !== null) break;
        }
        return array(
            'ok'       => true,
            // A 200 from /api/user/ proves the token even when an install
            // renamed the balance field — do not fail the connection for that.
            'balance'  => $balance,
            'currency' => $this->cfg['currency'] ?? ($this->provider->currency ?? 'NGN'),
        );
    }

    /* ---------------------------- catalogue ------------------------------ */

    public static function catalogue_types() { return self::$catalogue_types; }

    /**
     * Mirror one network's fixed-price list into vtu_products.
     *
     * Primary price-list documents:
     *   - DATA:     GET /api/network/ ({MTN_PLAN:[...]} or {MTN_PLAN:{ALL:[...]}})
     *   - CABLE:    GET /api/cable/   ({DSTVPLAN:[...], GOTVPLAN:[...], STARTIME:[...]})
     *   - EXAM_PIN: GET /api/epin/    ([{exam_name, amount}] or {WAEC:[...]})
     *
     * On stock MSORG/LegitData deployments, GET /api/epin/ is the user's
     * paginated order history ({count, results:[...]}) while GET /api/user/
     * embeds the account's tier-priced catalogue under "Exam"
     * ({WAEC:{amount}, NECO:{amount}}), "Dataplans" and "Cableplan". When a
     * primary document carries no price list (or answers 404 because one
     * network row was renamed), we fall back to GET /api/user/. Every GET
     * document is cached per adapter instance so a full sync fetches each
     * document at most once.
     */
    public function variations($network_code) {
        $code = strtoupper(trim((string)$network_code));
        $group = $this->catalogue_group($code);
        if ($group === null) {
            return array(
                'ok'    => false,
                'error' => 'Bestdatanig does not publish a '.$code.' price list through its API. '
                          .'Configure these products by hand in Admin > Catalogue.',
            );
        }
        $resolved = $this->resolve_price_rows($group);
        if (empty($resolved['ok'])) {
            return array('ok' => false, 'error' => $resolved['error']);
        }
        $out = array();
        $seen = array();
        foreach ($resolved['rows'] as $row) {
            $normalized = $group['kind'] === 'EXAM_PIN'
                ? $this->normalize_exam_variation($row, $group['key'])
                : $this->normalize_variation($row);
            if (!$normalized) continue;
            $vcode = $normalized['variation_code'];
            if (isset($seen[$vcode])) continue;
            $seen[$vcode] = true;
            $out[] = $normalized;
        }
        return array('ok' => true, 'service_id' => $group['key'], 'variations' => $out);
    }

    /**
     * Resolve a group's raw rows from its primary endpoint, falling back to
     * GET /api/user/ when the primary endpoint carries no price list (e.g.
     * /api/epin/ returning paginated order history) or fails when /api/user/
     * embeds Dataplans/Cableplan/Exam.
     */
    private function resolve_price_rows(array $group) {
        $path = $group['kind'] === 'DATA' ? $this->paths['plans']
              : ($group['kind'] === 'CABLE' ? $this->paths['cable'] : $this->paths['epin']);
        $doc = $this->document($path);
        if (!empty($doc['ok'])) {
            $rows = $this->price_rows($doc['data'], $group);
            if (is_array($rows)) {
                return array('ok' => true, 'rows' => $rows);
            }
            if ($group['kind'] !== 'EXAM_PIN'
                && $this->has_catalogue_kind($doc['data'], $group['kind'])) {
                return array(
                    'ok'    => false,
                    'error' => 'No '.$group['key'].' price list in the provider response',
                );
            }
        }

        if ($path !== $this->paths['user']) {
            $user = $this->document($this->paths['user']);
            if (!empty($user['ok'])) {
                $rows = $this->price_rows($user['data'], $group);
                if (is_array($rows)) {
                    return array('ok' => true, 'rows' => $rows);
                }
            }
        }

        if (empty($doc['ok'])) {
            return array('ok' => false, 'error' => $doc['error']);
        }
        return array(
            'ok'    => false,
            'error' => 'No '.$group['key'].' price list in the provider response',
        );
    }

    /**
     * Pull one group's rows out of a price-list document across all shapes
     * seen in Bestdatanig / MSORG / LegitData deployments.
     */
    private function price_rows($data, array $group) {
        if (!is_array($data) || $data === array()) return null;
        if ($group['kind'] === 'EXAM_PIN') {
            return $this->exam_price_rows($data, $group['key']);
        }
        if ($group['kind'] === 'DATA') {
            return $this->data_price_rows($data, $group);
        }
        return $this->cable_price_rows($data, $group['key']);
    }

    private function data_price_rows(array $data, array $group) {
        $containers = array();
        foreach (array('Dataplans', 'dataplans', 'data') as $wrap) {
            if (isset($data[$wrap]) && is_array($data[$wrap])) $containers[] = $data[$wrap];
        }
        $containers[] = $data;

        foreach ($containers as $c) {
            if (isset($c[$group['key']]) && is_array($c[$group['key']])) {
                $unwrapped = $this->unwrap_plan_bucket($c[$group['key']]);
                if (is_array($unwrapped)) return $unwrapped;
            }
            $base = $group['base'] ?? '';
            if ($base !== '' && isset($c[$base]) && is_array($c[$base])
                && isset($c[$base]['data_plans']) && is_array($c[$base]['data_plans'])) {
                return $c[$base]['data_plans'];
            }
        }
        return null;
    }

    private function cable_price_rows(array $data, $key) {
        $aliases = array($key);
        if ($key === 'STARTIME') {
            $aliases = array('STARTIME', 'STARTIMEPLAN', 'STARTIMES', 'STARTIMESPLAN');
        } elseif ($key === 'DSTVPLAN') {
            $aliases = array('DSTVPLAN', 'DSTV');
        } elseif ($key === 'GOTVPLAN') {
            $aliases = array('GOTVPLAN', 'GOTV');
        }
        $containers = array();
        foreach (array('Cableplan', 'cableplan', 'data') as $wrap) {
            if (isset($data[$wrap]) && is_array($data[$wrap])) $containers[] = $data[$wrap];
        }
        $containers[] = $data;

        foreach ($containers as $c) {
            foreach ($aliases as $alias) {
                if (!isset($c[$alias]) || !is_array($c[$alias])) continue;
                $bucket = $c[$alias];
                if (isset($bucket['plans']) && is_array($bucket['plans'])) return $bucket['plans'];
                if ($this->is_list_array($bucket)) return $bucket;
            }
        }
        return null;
    }

    private function exam_price_rows(array $data, $exam_key) {
        $c = null;
        foreach (array('Exam', 'exam', 'exams', 'epin', 'result_checker') as $wrap) {
            if (isset($data[$wrap]) && is_array($data[$wrap])) { $c = $data[$wrap]; break; }
        }
        if ($c === null) {
            if (isset($data['data']) && is_array($data['data'])) {
                $c = $data['data'];
            } elseif (isset($data['results']) && is_array($data['results'])) {
                // DRF PageNumberPagination on /api/epin/ with 0 orders is
                // empty order history, not an empty price list.
                if ($data['results'] === array()
                    && (array_key_exists('count', $data) || array_key_exists('next', $data))) {
                    return null;
                }
                $c = $data['results'];
            } else {
                $c = $data;
            }
        }
        if (!is_array($c) || $c === array()) return null;

        // Shape 1: flat list of exam price rows ([{"exam_name":"WAEC","amount":"3500"}, ...]).
        if ($this->is_list_array($c)) {
            $priceRows = array();
            foreach ($c as $row) {
                if (!is_array($row) || $this->is_order_history_row($row)) continue;
                if (isset($row['exam_name']) || isset($row['exam']) || isset($row['code'])
                    || isset($row['amount']) || isset($row['price']) || isset($row['plan_amount'])) {
                    $priceRows[] = $row;
                }
            }
            if (!$priceRows) return null;
            $out = array();
            foreach ($priceRows as $row) {
                $exam = strtoupper(trim((string)($row['exam_name'] ?? ($row['exam'] ?? ($row['code'] ?? '')))));
                if ($exam === '' || $exam === $exam_key) $out[] = $row;
            }
            return $out;
        }

        // Shape 2: associative map keyed by exam body — either UserAPIView's
        // {"WAEC":{"amount":3500},"NECO":{"amount":2000}} or {"WAEC":[...]}.
        $hasKnownExam = false;
        $matched = null;
        foreach ($c as $k => $v) {
            $uk = strtoupper(trim((string)$k));
            if (isset(self::$exam_groups[$uk])) $hasKnownExam = true;
            if ($uk === $exam_key) $matched = $v;
        }
        if (!$hasKnownExam) return null;
        if ($matched === null) return array();
        if (is_scalar($matched)) {
            return array(array('exam_name' => $exam_key, 'amount' => $matched));
        }
        if (!is_array($matched)) return array();
        if ($this->is_list_array($matched)) {
            $out = array();
            foreach ($matched as $row) {
                if (is_array($row) && !$this->is_order_history_row($row)) $out[] = $row;
            }
            return $out;
        }
        if ($this->is_order_history_row($matched)) return null;
        return array(array_merge(array('exam_name' => $exam_key), $matched));
    }

    /** True when $doc already contains at least one group of $kind. */
    private function has_catalogue_kind($data, $kind) {
        if (!is_array($data)) return false;
        if ($kind === 'DATA') {
            foreach (self::$data_groups as $base => $key) {
                if ($this->data_price_rows($data, array('kind' => 'DATA', 'key' => $key, 'base' => $base)) !== null) {
                    return true;
                }
            }
            return false;
        }
        if ($kind === 'CABLE') {
            foreach (array_unique(array_values(self::$cable_groups)) as $key) {
                if ($this->cable_price_rows($data, $key) !== null) return true;
            }
        }
        return false;
    }

    /**
     * Unwrap either a flat plan list ([row, ...]) or UserAPIView's sub-grouped
     * plan map ({"CORPORATE":[...], "SME":[...], "GIFTING":[...], "ALL":[...]}).
     */
    private function unwrap_plan_bucket(array $bucket) {
        if ($this->is_list_array($bucket)) return $bucket;
        if (isset($bucket['ALL']) && is_array($bucket['ALL']) && $this->is_list_array($bucket['ALL'])) {
            return $bucket['ALL'];
        }
        if (isset($bucket['data_plans']) && is_array($bucket['data_plans'])) {
            return $bucket['data_plans'];
        }
        $merged = array();
        $foundSublist = false;
        foreach ($bucket as $sub) {
            if (!is_array($sub) || !$this->is_list_array($sub)) continue;
            $foundSublist = true;
            foreach ($sub as $row) {
                if (is_array($row)) $merged[] = $row;
            }
        }
        return $foundSublist ? $merged : null;
    }

    private function is_list_array(array $arr) {
        if ($arr === array()) return true;
        return array_keys($arr) === range(0, count($arr) - 1);
    }

    /** Order-history rows from Result_Checker_Pin_order carry ledger/pin fields. */
    private function is_order_history_row(array $row) {
        foreach (array('previous_balance', 'after_balance', 'balance_before',
                       'balance_after', 'create_date', 'Status', 'pins', 'purchase_pin') as $k) {
            if (array_key_exists($k, $row)) return true;
        }
        return false;
    }

    /**
     * One exam-PIN price row -> the sync shape. The variation code IS the
     * exam name, because the purchase endpoint takes exam_name, not a
     * numeric plan id.
     */
    private function normalize_exam_variation($row, $exam) {
        if (!is_array($row)) return null;
        $code = strtoupper(trim((string)($row['exam_name'] ?? ($row['exam'] ?? ($row['code'] ?? '')))));
        if ($code === '') $code = strtoupper(trim((string)$exam));
        if ($code === '') return null;
        $amount = $this->money($row['amount'] ?? ($row['api_price'] ?? ($row['price'] ?? ($row['plan_amount'] ?? null))));
        if ($amount === null || (float)$amount <= 0) return null;
        $label = isset($row['name']) && is_scalar($row['name']) && strtoupper(trim((string)$row['name'])) !== $code
            ? trim((string)$row['name']) : '';
        return array(
            'variation_code' => $code,
            'name'           => $label !== '' ? $label : $code.' Result Checker PIN',
            'amount'         => number_format((float)$amount, 8, '.', ''),
            'fixed_price'    => true,
        );
    }

    /** Our network code -> {kind, key, base?}. Unknown codes are skipped by the sync. */
    private function catalogue_group($code) {
        $base = preg_replace('/-(DATA|AIRTIME)$/', '', $code);
        if (isset(self::$data_groups[$base])) {
            return array('kind' => 'DATA', 'key' => self::$data_groups[$base], 'base' => $base);
        }
        if (isset(self::$cable_groups[$code])) {
            return array('kind' => 'CABLE', 'key' => self::$cable_groups[$code]);
        }
        $exam_base = preg_replace('/[-_](PIN|PINS|RESULT|CHECKER)$/', '', $code);
        if (isset($this->cfg['exam_names']) && is_array($this->cfg['exam_names'])) {
            foreach (array($code, $exam_base) as $ek) {
                if (isset($this->cfg['exam_names'][$ek]) && is_scalar($this->cfg['exam_names'][$ek])
                    && trim((string)$this->cfg['exam_names'][$ek]) !== '') {
                    return array('kind' => 'EXAM_PIN', 'key' => strtoupper(trim((string)$this->cfg['exam_names'][$ek])));
                }
            }
        }
        if (isset(self::$exam_groups[$code])) {
            return array('kind' => 'EXAM_PIN', 'key' => self::$exam_groups[$code]);
        }
        if (isset(self::$exam_groups[$exam_base])) {
            return array('kind' => 'EXAM_PIN', 'key' => self::$exam_groups[$exam_base]);
        }
        // A numeric network id is a valid vendor id: translate it back to a name.
        if (ctype_digit($base)) {
            $name = array_search((int)$base, self::$default_network_ids, true);
            if ($name !== false && isset(self::$data_groups[$name])) {
                return array('kind' => 'DATA', 'key' => self::$data_groups[$name], 'base' => $name);
            }
        }
        return null;
    }

    /** One vendor plan/package row -> the shape Vtu_product_model reads. */
    private function normalize_variation($row) {
        if (!is_array($row)) return null;
        $code = $row['id'] ?? ($row['dataplan_id'] ?? ($row['cableplan_id'] ??
               ($row['variation_code'] ?? ($row['code'] ?? null))));
        if (!is_scalar($code) || trim((string)$code) === '') return null;
        $planType = isset($row['plan_type']) && is_scalar($row['plan_type'])
            ? trim((string)$row['plan_type']) : '';
        $validity = isset($row['month_validate']) && is_scalar($row['month_validate'])
            ? trim((string)$row['month_validate'])
            : (isset($row['validity']) && is_scalar($row['validity']) ? trim((string)$row['validity']) : '');
        $name = $row['plan'] ?? ($row['package'] ?? ($row['plan_name'] ??
               ($row['name'] ?? null)));
        if (!is_scalar($name) || trim((string)$name) === '') {
            $name = (string)$code;
        } else {
            $name = trim((string)$name);
            if ($planType !== '' && stripos($name, $planType) === false) {
                $name .= ' '.$planType;
            }
            if ($validity !== '' && stripos($name, $validity) === false) {
                $name .= ' - '.$validity;
            }
        }
        $amount = $this->money($row['plan_amount'] ?? ($row['amount'] ?? ($row['price'] ?? null)));
        if ($amount === null || (float)$amount <= 0) return null;
        $out = array(
            'variation_code' => (string)$code,
            'name'           => $name,
            'amount'         => number_format((float)$amount, 8, '.', ''),
            'fixed_price'    => true,
        );
        if ($planType !== '') $out['product_type'] = $planType;
        if ($validity !== '') $out['validity'] = $validity;
        return $out;
    }

    /* ------------------------------ internals ---------------------------- */

    /** A purchase accepted with 200/201; never retry a mutating call. */
    private function purchase($path, array $fields, array $p) {
        $fields = array_filter($fields, function ($v) { return $v !== null && $v !== ''; });
        $res = $this->call($path, $fields, 'POST', $p['reference'] ?? null);
        if (empty($res['ok'])) return $res;
        $data = $res['data'];
        if ($this->is_rejection($data)) {
            return array('ok' => false, 'error' => $this->vendor_message($data) ?: 'Provider rejected the purchase');
        }
        $reference = $data['ident'] ?? ($data['id'] ?? ($p['reference'] ?? null));
        $out = array(
            'ok'        => true,
            'reference' => $reference !== null ? (string)$reference : null,
            'status'    => $this->map_status((string)($data['Status'] ?? ($data['status'] ?? 'processing'))),
        );
        $cost = $data['plan_amount'] ?? ($data['paid_amount'] ?? ($data['amount'] ?? null));
        if ($this->money($cost) !== null) $out['cost'] = $this->money($cost);
        $detail = $this->extract_detail($data);
        if ($detail) $out['detail'] = $detail;
        return $out;
    }

    /**
     * Resolve the upstream `exam_name` expected by POST /api/epin/.
     *
     * Synced variations carry the vendor's exact exam_name in `variation_code`
     * (e.g. "WAEC", "NECO", "NABTEB", "WAEC_GCE"), whereas seeded catalogue
     * rows carry generic slugs like "waec-pin" or provider-scoped codes like
     * "WAEC-P2". Prefer a specific vendor variation when present; otherwise
     * normalise `exam_code`.
     */
    private function resolve_exam_name(array $p) {
        $overrides = (isset($this->cfg['exam_names']) && is_array($this->cfg['exam_names']))
            ? array_change_key_case($this->cfg['exam_names'], CASE_UPPER) : array();

        $variation = trim((string)($p['variation_code'] ?? ''));
        $exam_code = trim((string)($p['exam_code'] ?? ''));

        $var_upper = strtoupper(preg_replace('/-P\d+$/i', '', $variation));
        $is_generic_seed = ($var_upper === '' || preg_match('/^[A-Z0-9]+[-_](PIN|PINS)$/', $var_upper) === 1);

        $raw = (!$is_generic_seed) ? $var_upper : strtoupper(preg_replace('/-P\d+$/i', '', $exam_code));
        if ($raw === '' && $var_upper !== '') {
            $raw = preg_replace('/[-_](PIN|PINS|RESULT|CHECKER)$/', '', $var_upper);
        }
        if ($raw === '') return null;

        if (isset($overrides[$raw]) && is_scalar($overrides[$raw]) && trim((string)$overrides[$raw]) !== '') {
            return trim((string)$overrides[$raw]);
        }
        $stripped = preg_replace('/[-_](PIN|PINS|RESULT|CHECKER)$/', '', $raw);
        if (isset($overrides[$stripped]) && is_scalar($overrides[$stripped]) && trim((string)$overrides[$stripped]) !== '') {
            return trim((string)$overrides[$stripped]);
        }
        if (isset(self::$exam_groups[$raw])) return self::$exam_groups[$raw];
        if (isset(self::$exam_groups[$stripped])) return self::$exam_groups[$stripped];
        return $raw;
    }

    /**
     * Extract electricity tokens/units and exam-checker PINs from a purchase
     * or status payload.
     *
     * VtuService persists `detail['token']` and `detail['units']` onto
     * `vtu_transactions`, and the customer receipt renders `detail->token`.
     * On nelly_api (`POST /api/epin/`), exam PINs arrive in `pins`,
     * `purchase_pin`, or `data` (plain string from VTpass, JSON object from
     * EasyAccess, token list from MobileNig, or Django `serializers.serialize`
     * JSON from the local `exam_pin` table), never in `token` — so extracted
     * PINs populate both `detail['pins']` and `detail['token']`.
     */
    private function extract_detail(array $data) {
        $detail = array();
        foreach (array('token' => array('token', 'Token', 'purchased_code'),
                       'units' => array('units', 'Units')) as $key => $candidates) {
            foreach ($candidates as $candidate) {
                if (isset($data[$candidate]) && is_scalar($data[$candidate])
                    && trim((string)$data[$candidate]) !== '') {
                    $limit = $key === 'units' ? 64 : 128;
                    $detail[$key] = mb_substr(trim((string)$data[$candidate]), 0, $limit);
                    break;
                }
            }
        }

        $pins = $this->extract_pins($data);
        if ($pins) {
            $joined = implode(', ', $pins);
            $detail['pins'] = $joined;
            if (count($pins) > 1) {
                $detail['cards'] = $pins;
            }
            if (!isset($detail['token']) || $detail['token'] === '') {
                $detail['token'] = mb_substr($joined, 0, 128);
            }
        }

        return $detail;
    }

    /** Return a clean list of formatted PIN strings from any nelly_api epin payload. */
    private function extract_pins(array $data) {
        foreach (array('pins', 'purchase_pin', 'cards', 'tokens', 'pin', 'Pin') as $field) {
            if (!array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') continue;
            $parsed = $this->parse_pin_value($data[$field], true);
            if ($parsed) return $parsed;
        }

        // Result_Checker_Pin_orderSerializer writes upstream output into `data`.
        if (array_key_exists('data', $data) && $data['data'] !== null && $data['data'] !== '') {
            $is_epin_row = isset($data['exam_name'])
                || array_key_exists('pins', $data)
                || array_key_exists('purchase_pin', $data);
            $parsed = $this->parse_pin_value($data['data'], $is_epin_row);
            if ($parsed) return $parsed;
        }

        return array();
    }

    private function parse_pin_value($val, $allow_bare_scalar) {
        if (is_scalar($val)) {
            $str = trim((string)$val);
            if ($str === '' || strtolower($str) === 'null' || strtolower($str) === 'none') {
                return array();
            }
            if ($str[0] === '[' || $str[0] === '{') {
                $decoded = json_decode($str, true);
                if (is_array($decoded)) {
                    $from_json = $this->parse_pin_array($decoded);
                    if ($from_json) return $from_json;
                    return array();
                }
            }
            return $allow_bare_scalar ? array($str) : array();
        }
        if (is_array($val)) {
            return $this->parse_pin_array($val);
        }
        return array();
    }

    private function parse_pin_array(array $arr) {
        if ($arr === array()) return array();

        // Nested wrappers such as {"details":{"tokens":[...]}} or {"cards":[...]}.
        foreach (array('tokens', 'pins', 'cards') as $wrap) {
            if (isset($arr[$wrap]) && is_array($arr[$wrap])) {
                $inner = $this->parse_pin_array($arr[$wrap]);
                if ($inner) return $inner;
            }
            if (isset($arr['details'][$wrap]) && is_array($arr['details'][$wrap])) {
                $inner = $this->parse_pin_array($arr['details'][$wrap]);
                if ($inner) return $inner;
            }
        }

        // EasyAccess associative object: {"success":"true","pin":"...","pin2":"..."}.
        if (!$this->is_list_array($arr)) {
            if (isset($arr['fields']) && is_array($arr['fields'])) {
                $one = $this->format_pin_object($arr['fields']);
                return $one !== null ? array($one) : array();
            }
            $multi = array();
            foreach ($arr as $k => $v) {
                if (preg_match('/^pin\d*$/i', (string)$k) && is_scalar($v) && trim((string)$v) !== '') {
                    $multi[] = trim((string)$v);
                }
            }
            if ($multi) return $multi;
            $single = $this->format_pin_object($arr);
            return $single !== null ? array($single) : array();
        }

        $out = array();
        foreach ($arr as $item) {
            if (is_scalar($item) && trim((string)$item) !== '') {
                $out[] = trim((string)$item);
            } elseif (is_array($item)) {
                $target = (isset($item['fields']) && is_array($item['fields'])) ? $item['fields'] : $item;
                $formatted = $this->format_pin_object($target);
                if ($formatted !== null) $out[] = $formatted;
            }
        }
        return $out;
    }

    private function format_pin_object(array $obj) {
        $pin = null;
        foreach (array('pin', 'Pin', 'PIN', 'token', 'Token', 'purchased_code') as $pk) {
            if (isset($obj[$pk]) && is_scalar($obj[$pk]) && trim((string)$obj[$pk]) !== '') {
                $pin = trim((string)$obj[$pk]);
                break;
            }
        }
        if ($pin === null) return null;
        $serial = null;
        foreach (array('serial', 'Serial', 'serial_number', 'serialNo', 'Serial_Number') as $sk) {
            if (isset($obj[$sk]) && is_scalar($obj[$sk]) && trim((string)$obj[$sk]) !== '') {
                $serial = trim((string)$obj[$sk]);
                break;
            }
        }
        return ($serial !== null && stripos($pin, $serial) === false)
            ? $serial.':'.$pin : $pin;
    }

    private function is_rejection(array $data) {
        $status = strtolower((string)($data['Status'] ?? ($data['status'] ?? '')));
        if (in_array($status, array('failed', 'declined', 'reversed'), true)) return true;
        if (!empty($data['error']) || !empty($data['errors'])) return true;
        $detail = strtolower((string)($data['detail'] ?? ''));
        return $detail !== '' && (strpos($detail, 'insufficient') !== false
            || strpos($detail, 'authentication') !== false);
    }

    private function map_status($raw) {
        $raw = strtolower(trim((string)$raw));
        if (in_array($raw, array('successful', 'success', 'delivered', 'completed'), true)) return 'SUCCESSFUL';
        if (in_array($raw, array('failed', 'declined', 'reversed'), true)) return 'FAILED';
        return 'PROCESSING';
    }

    /** Numeric network id for our network code, resolved live when unknown. */
    private function network_id($code) {
        $key = strtoupper(trim((string)$code));
        $key = preg_replace('/-(DATA|AIRTIME)$/', '', $key);
        if ($key === '') return null;
        if (ctype_digit($key)) return (int)$key;
        if (isset($this->network_ids[$key]) && is_numeric($this->network_ids[$key])) {
            return (int)$this->network_ids[$key];
        }
        $doc = $this->document($this->paths['networks']);
        if (!empty($doc['ok'])) {
            foreach ($doc['data'] as $name => $info) {
                if (!is_array($info)) continue;
                $id = $info['network_info']['id'] ?? null;
                if ($id !== null && strtoupper((string)$name) === $key) {
                    $this->network_ids[$key] = $id;
                    return (int)$id;
                }
            }
        }
        return null;
    }

    /** Cable/disco names are DB primary keys: resolve by name from the live list. */
    private function resolve_name_id($path, $list_key, $code, $config_key = '', $groups = array()) {
        $key = strtoupper(trim((string)$code));
        if ($key === '') return null;
        if (ctype_digit($key)) return (int)$key;
        if (isset($this->cfg[$config_key]) && is_array($this->cfg[$config_key])
            && isset($this->cfg[$config_key][$key])) {
            return (int)$this->cfg[$config_key][$key];
        }
        $doc = $this->document($path);
        if (empty($doc['ok'])) return null;
        $rows = $doc['data'][$list_key] ?? null;
        if (!is_array($rows)) return null;
        $aliases = array($key);
        if (isset($groups[$key])) $aliases[] = $groups[$key];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $name = strtoupper((string)($row['name'] ?? ''));
            $id = $row['id'] ?? null;
            if ($id === null || $name === '') continue;
            foreach ($aliases as $alias) {
                if ($name === $alias || strpos($name, $alias) !== false || strpos($alias, $name) !== false) {
                    return (int)$id;
                }
            }
        }
        return null;
    }

    /** A cable package may arrive as our product code rather than a vendor id. */
    private function resolve_plan_id($path, $code) {
        $code = trim((string)$code);
        if ($code === '') return null;
        if (ctype_digit($code)) return (int)$code;
        $doc = $this->document($path);
        if (empty($doc['ok'])) return null;
        foreach (self::$cable_groups as $group) {
            $rows = $doc['data'][$group] ?? null;
            if (!is_array($rows)) continue;
            foreach ($rows as $row) {
                if (!is_array($row)) continue;
                $name = strtoupper((string)($row['package'] ?? ($row['plan'] ?? '')));
                if ($name !== '' && ($name === strtoupper($code) || strpos($name, strtoupper($code)) !== false)) {
                    return isset($row['id']) ? (int)$row['id'] : null;
                }
            }
        }
        return $code; // let the vendor reject it with a readable 400
    }

    private function disco_name($code) {
        $key = strtoupper(trim((string)$code));
        if (isset($this->cfg['disco_names']) && is_array($this->cfg['disco_names'])
            && isset($this->cfg['disco_names'][$key])) {
            return (string)$this->cfg['disco_names'][$key];
        }
        return isset(self::$disco_names[$key]) ? self::$disco_names[$key] : (string)$code;
    }

    private function resolve_disco_id($code) {
        $key = strtoupper(trim((string)$code));
        if ($key === '') return null;
        if (ctype_digit($key)) return (int)$key;
        if (isset($this->cfg['disco_ids']) && is_array($this->cfg['disco_ids'])
            && isset($this->cfg['disco_ids'][$key])) {
            return (int)$this->cfg['disco_ids'][$key];
        }
        $doc = $this->document($this->paths['disco']);
        if (empty($doc['ok'])) return null;
        $rows = $doc['data']['disko'] ?? ($doc['data']['disco'] ?? null);
        if (!is_array($rows)) return null;
        $alias = isset(self::$disco_aliases[$key]) ? self::$disco_aliases[$key] : $key;
        $aliases = array($alias);
        if (isset(self::$disco_names[$key])) $aliases[] = strtoupper(self::$disco_names[$key]);
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $name = strtoupper((string)($row['name'] ?? ''));
            if (isset($row['id']) && $name !== '') {
                foreach ($aliases as $candidate) {
                    if (strpos($name, $candidate) !== false || strpos($candidate, $name) !== false) {
                        return (int)$row['id'];
                    }
                }
            }
        }
        return null;
    }

    /** GET documents are cached per instance (one /api/network/ per sync). */
    private function document($path) {
        if (!array_key_exists($path, $this->documents)) {
            $this->documents[$path] = $this->call($path, null, 'GET');
        }
        return $this->documents[$path];
    }

    private function ported(array $p) {
        if (array_key_exists('ported_number', $p)) return (bool)$p['ported_number'];
        return isset($this->cfg['ported_number']) ? (bool)$this->cfg['ported_number'] : false;
    }

    private function unknown_network($code) {
        return $this->unknown_code('network', $code);
    }

    private function unknown_code($what, $code) {
        return array(
            'ok'    => false,
            'error' => 'Unknown '.$what.' "'.(string)$code.'" for Bestdatanig. '
                      .'Map it in retry_policy.bestdata.network_ids / cable_ids / disco_ids / exam_names.',
        );
    }

    private function money($value) {
        if (!is_scalar($value)) return null;
        $clean = preg_replace('/[^0-9.\-]/', '', trim((string)$value));
        if ($clean === '' || !is_numeric($clean)) return null;
        return (string)(float)$clean;
    }

    private function query(array $args, $path) {
        $join = strpos($path, '?') === false ? '?' : '&';
        return $join.http_build_query(array_filter($args, function ($v) {
            return $v !== null && $v !== '';
        }));
    }

    private function url($path) {
        if (preg_match('#^https?://#i', (string)$path)) return (string)$path;
        return $this->base.'/'.ltrim((string)$path, '/');
    }

    /**
     * New provider rows are always encrypted, so this adapter uses the
     * fail-closed open() rather than decrypt()'s legacy plaintext fallback.
     * Never log the result.
     */
    private function api_key() {
        $ci =& get_instance();
        if (!isset($ci->encryptionservice)) $ci->load->library('EncryptionService');
        $raw = $ci->encryptionservice->open($this->provider->api_key_encrypted);
        if ($raw === null || trim((string)$raw) === '') return null;
        $decoded = json_decode((string)$raw, true);
        if (is_array($decoded) && isset($decoded['api_key'])) $raw = (string)$decoded['api_key'];
        // Operators often paste the whole "Authorization" value from the docs.
        return trim(preg_replace('/^(Token|Bearer)\s+/i', '', trim((string)$raw)));
    }

    private static function read_config($row) {
        if (empty($row->retry_policy)) return array();
        $decoded = json_decode($row->retry_policy, true);
        if (!is_array($decoded)) return array();
        foreach (array('bestdata', 'best_data', 'bestdatanig') as $key) {
            if (isset($decoded[$key]) && is_array($decoded[$key])) return $decoded[$key];
        }
        // Legacy STANDARD_VTU rows keep their overrides under `vtu`.
        if (isset($decoded['vtu']) && is_array($decoded['vtu'])) return $decoded['vtu'];
        return array();
    }

    /**
     * @return array{ok:bool,data?:array,error?:string,http_code?:int}
     */
    private function call($path, $payload, $method) {
        $method = strtoupper($method);
        $url = $this->url($path);
        $key = $this->api_key();
        if ($key === null || $key === '') {
            return array('ok' => false,
                'error' => 'The provider API key could not be decrypted. Re-save it from Admin > Providers.');
        }
        $last = null;
        $schemes = $this->auth_schemes;
        $count = count($schemes);
        foreach ($schemes as $i => $scheme) {
            $headers = array(
                'Authorization: '.$scheme.' '.$key,
                'Accept: application/json',
            );
            $body = null;
            if ($method === 'POST') {
                $headers[] = 'Content-Type: application/json';
                // SecureHttpClient sends strings verbatim; arrays become a
                // form-encoded body, which DRF's JSON parser does not accept.
                $body = $payload === null ? '{}' : json_encode($payload);
            }
            $options = array('max_retries' => $method === 'POST' ? 0 : 2);
            try {
                $last = $method === 'POST'
                    ? $this->http->post($url, $body, $headers, $options)
                    : $this->http->get($url, $headers, $options);
            } catch (Exception $e) {
                log_message('error', 'Bestdata transport error: '.$e->getMessage());
                return array('ok' => false, 'error' => 'Provider unreachable');
            }
            $code = isset($last['http_code']) ? (int)$last['http_code'] : 0;
            // A 401 means DRF refused the credential before running the view:
            // retrying with the other keyword cannot double-charge anything.
            if ($code === 401 && $i < $count - 1) continue;
            break;
        }
        if (!is_array($last)) return array('ok' => false, 'error' => 'Provider unreachable');
        $code = isset($last['http_code']) ? (int)$last['http_code'] : 0;
        if ($code === 0) {
            return array('ok' => false, 'error' => $last['error'] ?? 'Provider unreachable', 'http_code' => 0);
        }
        $data = json_decode(isset($last['body']) ? (string)$last['body'] : '', true);
        if (!is_array($data)) $data = array();
        if ($code < 200 || $code >= 300) {
            return array(
                'ok'        => false,
                'error'     => $this->http_error($code, $path, $data),
                'http_code' => $code,
                'data'      => $data,
            );
        }
        return array('ok' => true, 'data' => $data, 'http_code' => $code);
    }

    /** Turn a bare "HTTP 404" into something an operator can act on. */
    private function http_error($code, $path, array $data) {
        $detail = $this->vendor_message($data);
        if ($code === 404) {
            return 'HTTP 404 at '.$path.' — '.$this->base.' is not serving the API there. '
                  .'Use https://bestdatanig.com.ng (app.bestdatanig.com.ng is the website, not the API) '
                  .'or set retry_policy.bestdata.base_url / paths for a custom deployment.'
                  .($detail !== null ? ' Provider said: '.$detail : '');
        }
        if ($code === 401 || $code === 403) {
            return 'HTTP '.$code.' — the API token was rejected. Paste the token from your '
                  .'Bestdatanig dashboard (Authorization: Token <key>); some installs use Bearer.'
                  .($detail !== null ? ' Provider said: '.$detail : '');
        }
        if ($detail !== null) return $detail;
        return 'HTTP '.$code;
    }

    /** DRF answers {"detail": "..."} or {"field": ["..."]}. */
    private function vendor_message(array $data) {
        foreach (array('detail', 'error', 'message', 'response_description') as $key) {
            if (!isset($data[$key])) continue;
            $value = $data[$key];
            if (is_array($value)) {
                foreach ($value as $item) {
                    if (is_scalar($item) && trim((string)$item) !== '') return trim((string)$item);
                }
            } elseif (is_scalar($value) && trim((string)$value) !== '') {
                return trim((string)$value);
            }
        }
        foreach ($data as $key => $value) {
            if (!is_array($value)) continue;
            foreach ($value as $item) {
                if (is_scalar($item) && trim((string)$item) !== '') return $key.': '.trim((string)$item);
            }
        }
        return null;
    }
}

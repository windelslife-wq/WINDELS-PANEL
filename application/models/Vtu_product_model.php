<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Vtu_product_model extends MY_Model {
    protected $table = 'vtu_products';

    /** True when the most recent upsert_from_provider() paused an underwater active row. */
    public $last_upsert_paused = false;

    /** Products a customer may buy, for one network and type. */
    public function active_for($network_id, $service_type){
        return $this->db->where('network_id',$network_id)
                        ->where('service_type',$service_type)
                        ->where('is_active',1)
                        ->order_by('sorting','ASC')
                        ->get($this->table)->result();
    }

    public function find_active($id){
        return $this->db->where('id',$id)->where('is_active',1)->get($this->table)->row();
    }

    /**
     * Other active rows for the same (network, service_type), excluding one id.
     *
     * VtuService::variable_product() takes active_for(...)[0] for airtime and
     * electricity, so a second active row for the same network silently
     * decides the discount and the bounds by sort order. The catalogue screen
     * refuses to create that situation; this is the query that detects it.
     */
    public function other_active($network_id, $service_type, $exclude_id = null){
        $this->db->where('network_id',$network_id)
                 ->where('service_type',$service_type)
                 ->where('is_active',1);
        if ($exclude_id) $this->db->where('id !=', (int)$exclude_id);
        return $this->db->order_by('sorting','ASC')->get($this->table)->result();
    }

    public function find_by_code($network_id, $service_type, $code){
        return $this->db->where('network_id',$network_id)
                        ->where('service_type',$service_type)
                        ->where('code',$code)->get($this->table)->row();
    }

    /**
     * The admin catalogue grid: every row, priced or not, active or not.
     *
     * Deliberately unscoped by is_active — the whole point of the screen is
     * the rows customers cannot see yet. Only ever reached behind
     * `services.view`, the same contract as the other admin queues.
     *
     * @param array $filters network_id|service_type|status|pricing|search
     */
    public function admin_search(array $filters, $limit = 25, $offset = 0){
        $this->admin_filters($filters);
        return $this->db
            ->select('vtu_products.*, vtu_networks.name AS network_name,
                      vtu_networks.code AS network_code', false)
            ->join('vtu_networks', 'vtu_networks.id = vtu_products.network_id', 'left')
            ->order_by('vtu_products.service_type','ASC')
            ->order_by('vtu_products.sorting','ASC')
            ->order_by('vtu_products.name','ASC')
            ->limit($limit, $offset)
            ->get()->result();
    }

    public function admin_count(array $filters){
        $this->admin_filters($filters);
        return (int)$this->db->count_all_results();
    }

    /** Shared WHERE builder so the grid and its count can never disagree. */
    private function admin_filters(array $f){
        $this->db->from($this->table);
        if (!empty($f['network_id']))   $this->db->where('vtu_products.network_id', (int)$f['network_id']);
        if (!empty($f['service_type'])) $this->db->where('vtu_products.service_type', $f['service_type']);
        if (isset($f['status']) && $f['status'] !== '' && $f['status'] !== null) {
            $this->db->where('vtu_products.is_active', $f['status'] === 'active' ? 1 : 0);
        }
        // "Needs a price" is the working queue of this screen: a sync imports
        // rows unpriced, and an unpriced row is the one thing that cannot be
        // sold at all.
        if (!empty($f['pricing']) && $f['pricing'] === 'unpriced') {
            $this->db->where('vtu_products.price IS NULL', null, false);
        }
        if (!empty($f['pricing']) && $f['pricing'] === 'priced') {
            $this->db->where('vtu_products.price IS NOT NULL', null, false);
        }
        if (!empty($f['search'])) {
            $term = trim((string)$f['search']);
            $this->db->group_start()
                ->like('vtu_products.code', $term)
                ->or_like('vtu_products.name', $term)
                ->group_end();
        }
    }

    public function create(array $data){
        if (empty($data['public_id'])) $data['public_id'] = marvy_public_id();
        $now = $this->now_utc();
        $data += array('created_at'=>$now, 'updated_at'=>$now);
        $this->db->insert($this->table, $data);
        return (int)$this->db->insert_id();
    }

    public function update_fields($id, array $fields){
        $fields['updated_at'] = $this->now_utc();
        return $this->db->where('id',$id)->update($this->table, $fields);
    }

    /** Catalogue rows sourced from one provider, for the admin detail page. */
    public function paginated_for_provider($provider_id, $limit, $offset){
        return $this->db->where('provider_id',$provider_id)
                        ->order_by('service_type','ASC')->order_by('sorting','ASC')
                        ->limit($limit,$offset)->get($this->table)->result();
    }

    public function count_for_provider($provider_id){
        return (int)$this->db->where('provider_id',$provider_id)->count_all_results($this->table);
    }

    /**
     * Upsert one product from a vendor's price list.
     * Returns 'inserted'|'updated'|'unchanged'.
     *
     * Inactive at cost, safe re-syncs:
     *   - New rows arrive switched off (`is_active = 0`) and priced at vendor
     *     cost (`price = face_value = provider_cost = $cost`), so unreviewed
     *     variations never go on sale automatically.
     *   - Untouched inactive rows (`is_active = 0` whose `price` still equals
     *     the previous `provider_cost`, or is NULL) follow vendor cost updates
     *     on re-sync so they remain at cost until an admin prices or activates
     *     them.
     *   - Once an admin sets a custom `price`, customises `name` on an active
     *     row, or deactivates a product, re-syncs never overwrite `price`,
     *     never clobber `name`, and never switch a deactivated row on.
     *   - Margin guard: if vendor `provider_cost` rises strictly above an
     *     active product's selling `price`, `is_active` drops to `0`
     *     (`$last_upsert_paused = true`) rather than selling at a loss or
     *     silently changing the customer price.
     *   - Cross-provider isolation: if `(network_id, service_type, code)` is
     *     already owned by a different provider, a provider-scoped code
     *     (`CODE-P<id>`) is used so syncing a second vendor never hijacks a
     *     live product owned by the first.
     *
     * @param array $v variation_code, name, amount (vendor price), fixed_price, validity?, product_type?
     */
    public function upsert_from_provider($provider_id, $network_id, $service_type, array $v, $sorting = 0){
        $this->last_upsert_paused = false;

        $variation = trim((string)($v['variation_code'] ?? ''));
        if ($variation === '') return 'unchanged';

        $base_code = strtoupper(preg_replace('/[^A-Za-z0-9._-]/', '-', $variation));
        $code = mb_substr($base_code, 0, 64);
        $cost = (isset($v['amount']) && $v['amount'] !== null && $v['amount'] !== '' && is_numeric($v['amount']))
            ? number_format((float)$v['amount'], 8, '.', '') : null;

        $vendor_name = mb_substr(trim((string)($v['name'] ?? $variation)), 0, 128);
        if ($vendor_name === '') $vendor_name = mb_substr($variation, 0, 128);

        $validity = (isset($v['validity']) && is_scalar($v['validity']) && trim((string)$v['validity']) !== '')
            ? mb_substr(trim((string)$v['validity']), 0, 32) : null;
        $product_type = (isset($v['product_type']) && is_scalar($v['product_type']) && trim((string)$v['product_type']) !== '')
            ? mb_substr(trim((string)$v['product_type']), 0, 32) : null;

        $existing = $this->find_by_code($network_id, $service_type, $code);
        if ($existing && $existing->provider_id !== null && (int)$existing->provider_id !== (int)$provider_id) {
            $suffix = '-P'.(int)$provider_id;
            $code = mb_substr($base_code, 0, 64 - strlen($suffix)).$suffix;
            $existing = $this->find_by_code($network_id, $service_type, $code);
        }

        $now = $this->now_utc();

        if (!$existing) {
            $insert = array(
                'public_id'     => marvy_public_id(),
                'network_id'    => $network_id,
                'provider_id'   => $provider_id,
                'service_type'  => $service_type,
                'code'          => $code,
                'provider_code' => $variation,
                'name'          => $vendor_name,
                'face_value'    => $cost,
                // New rows arrive inactive and priced at vendor cost: visible
                // zero-margin baseline in Admin > Catalogue, never live until
                // reviewed and switched on.
                'price'         => $cost,
                'provider_cost' => $cost,
                'is_active'     => 0,
                'sorting'       => (int)$sorting,
                'created_at'    => $now,
                'updated_at'    => $now,
            );
            if ($validity !== null) $insert['validity'] = $validity;
            if ($product_type !== null) $insert['product_type'] = $product_type;
            $this->db->insert($this->table, $insert);
            return 'inserted';
        }

        $was_active = (int)$existing->is_active === 1;
        $unreviewed_at_cost = !$was_active && (
            $existing->price === null
            || ($existing->provider_cost !== null
                && $this->same_decimal($existing->price, $existing->provider_cost))
        );

        $new_cost = $cost !== null ? $cost : $existing->provider_cost;
        $new_price = $existing->price;
        if ($cost !== null && ($existing->price === null || $unreviewed_at_cost)) {
            $new_price = $cost;
        }

        $new_face = $existing->face_value;
        if ($cost !== null && ($existing->face_value === null
            || (!$was_active && $existing->provider_cost !== null
                && $this->same_decimal($existing->face_value, $existing->provider_cost)))) {
            $new_face = $cost;
        }

        $new_active = $was_active ? 1 : 0;
        if ($was_active && $new_cost !== null && $new_price !== null
            && bccomp((string)$new_cost, (string)$new_price, 8) > 0) {
            $new_active = 0;
            $this->last_upsert_paused = true;
        }

        $existing_name = (string)($existing->name ?? '');
        if ($unreviewed_at_cost
            || trim($existing_name) === ''
            || $existing_name === (string)$existing->code
            || $existing_name === (string)($existing->provider_code ?? '')) {
            $new_name = $vendor_name;
        } else {
            $new_name = $existing_name;
        }

        $existing_validity = $existing->validity ?? null;
        $new_validity = ($validity !== null && ($unreviewed_at_cost || $existing_validity === null || $existing_validity === ''))
            ? $validity : $existing_validity;

        $existing_ptype = $existing->product_type ?? null;
        $new_ptype = ($product_type !== null && ($unreviewed_at_cost || $existing_ptype === null || $existing_ptype === ''))
            ? $product_type : $existing_ptype;

        $changed = ($existing->provider_id === null || (int)$existing->provider_id !== (int)$provider_id)
            || (string)($existing->provider_code ?? '') !== $variation
            || $existing_name !== $new_name
            || !$this->same_decimal($existing->provider_cost ?? null, $new_cost)
            || !$this->same_decimal($existing->price ?? null, $new_price)
            || !$this->same_decimal($existing->face_value ?? null, $new_face)
            || (int)$existing->is_active !== $new_active
            || $existing_validity !== $new_validity
            || $existing_ptype !== $new_ptype;

        if (!$changed) {
            return 'unchanged';
        }

        $fields = array(
            'provider_id'   => $provider_id,
            'provider_code' => $variation,
            'name'          => $new_name,
            'provider_cost' => $new_cost,
            'price'         => $new_price,
            'face_value'    => $new_face,
            'is_active'     => $new_active,
            'updated_at'    => $now,
        );
        if ($new_validity !== $existing_validity) $fields['validity'] = $new_validity;
        if ($new_ptype !== $existing_ptype) $fields['product_type'] = $new_ptype;

        $this->db->where('id', $existing->id)->update($this->table, $fields);
        return 'updated';
    }

    private function same_decimal($a, $b){
        if ($a === null || $a === '') return ($b === null || $b === '');
        if ($b === null || $b === '') return false;
        return bccomp((string)$a, (string)$b, 8) === 0;
    }
}

<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Migration 044 — seed fixed-price VTU products (data bundles, cable
 * packages, exam PINs).
 *
 * ## The gap this closes
 *
 * The VTU catalogue seeded networks for all five service types but products
 * only for AIRTIME and ELECTRICITY (the two variable-amount types). On the
 * Data, Cable TV and Exam PINs pages the Bundle / Package / PIN-type
 * dropdowns therefore rendered empty on a fresh install, and VtuService
 * rejects any purchase whose product row has no price ("That bundle has no
 * price") — the forms looked broken even though the networks were there.
 *
 * Core_seeder::seed_vtu_catalogue() now seeds these rows (and the generated
 * SQL dump has them), so this migration exists for panels already deployed:
 * it inserts the same list from Core_seeder::vtu_fixed_products(), resolving
 * each network by code.
 *
 * Insert-or-leave, never insert-or-overwrite: a row that already exists —
 * a half-applied run, a provider sync that imported the same variation, or
 * an operator who priced it by hand — is operator-owned data. A sync that
 * finds the row keeps the operator's price; this migration never touches it.
 * Codes equal the uppercased provider variation code, so a later sync
 * updates these rows in place instead of creating duplicates.
 */
class Migration_Vtu_fixed_products extends CI_Migration {

    /** Data-only migration: no DDL for the schema exporter to diff. */
    public static function tables() {
        return array();
    }

    public static function statements() {
        return array();
    }

    public function up() {
        if (!$this->table_exists('vtu_products') || !$this->table_exists('vtu_networks')) return;

        require_once APPPATH.'libraries/Seeder.php';
        require_once APPPATH.'seeds/Core_seeder.php';

        $sorting = (int)$this->db->select_max('sorting')->get('vtu_products')->row()->sorting + 1;

        foreach (Core_seeder::vtu_fixed_products() as $p) {
            list($network_code, $service_type, $code, $provider_code, $name, $validity, $face) = $p;

            $network = $this->db->where('code', $network_code)->get('vtu_networks')->row();
            if (!$network) continue;

            $exists = $this->db->where('network_id', $network->id)
                               ->where('service_type', $service_type)
                               ->where('code', $code)
                               ->get('vtu_products')->row();
            if ($exists) continue;

            $now = gmdate('Y-m-d H:i:s');
            $this->db->insert('vtu_products', array(
                'public_id'     => marvy_public_id(),
                'network_id'    => (int)$network->id,
                'service_type'  => $service_type,
                'code'          => $code,
                'provider_code' => $provider_code,
                'name'          => $name,
                'validity'      => $validity,
                // Priced at face value (zero margin), the same rule the
                // catalogue sync applies to a vendor's amount; an admin
                // sets the real margin under Admin → Catalogue.
                'face_value'    => $face,
                'price'         => $face,
                'provider_cost' => $face,
                'is_active'     => 1,
                'sorting'       => $sorting++,
                'created_at'    => $now,
                'updated_at'    => $now,
            ));
        }
    }

    public function down() {
        if (!$this->table_exists('vtu_products')) return;

        require_once APPPATH.'libraries/Seeder.php';
        require_once APPPATH.'seeds/Core_seeder.php';

        foreach (Core_seeder::vtu_fixed_products() as $p) {
            $this->db->where('service_type', $p[1])->where('code', $p[2])->delete('vtu_products');
        }
    }

    /* CI_Migration has no table_exists; portable across MySQL and SQLite. */
    private function table_exists($table) {
        try {
            return (bool)$this->db->table_exists($table);
        } catch (Exception $e) {
            return false;
        }
    }
}

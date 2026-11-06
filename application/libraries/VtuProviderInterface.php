<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * VtuProviderInterface — contract for VTU providers (§14).
 *
 * Deliberately a sibling of ProviderAdapterInterface rather than an extension:
 * that one is SMM-shaped (createOrder/requestRefill/getRefillStatus) and none
 * of those verbs mean anything for airtime. Provider_manager registers both.
 *
 * Every method returns an array with at least ok:bool, and on failure an
 * error:string. Implementations must never throw for a provider-side rejection
 * — a rejection is a normal result the engine refunds.
 *
 * Catalogue capability. ProviderSyncService::sync_vtu_catalogue() mirrors the
 * vendor's price list into vtu_products, so every VTU adapter registered in
 * Provider_manager also answers:
 *
 * The capability contract, in signature form:
 *
 *     public static function catalogue_types();
 *     public function variations($network_code);
 *
 * `catalogue_types()` returns the service types it can list (DATA, CABLE and
 * EXAM_PIN for fixed-price products; AIRTIME and ELECTRICITY are
 * variable-amount and stay hand-configured). `variations($network_code)`
 * returns `{ok:true, service_id, variations:[{variation_code, name, amount,
 * fixed_price}]}`, or `{ok:false, error}` when the vendor does not carry that
 * network. The sync skips those, but the method must exist: an adapter with no
 * variations() makes "Sync services" fail before it can do any work. All
 * built-in VTU adapters (MOCK, STANDARD_VTU, VTPASS, BESTDATA) implement it,
 * declaring DATA, CABLE and EXAM_PIN; new ones must too.
 *
 * These two are documented here rather than added to the interface method list
 * so a third-party adapter written against the original eight purchase/lookup
 * methods still loads; the sync checks the capability at runtime.
 */
interface VtuProviderInterface {

    /** Airtime top-up. $payload: msisdn, amount, network_code, reference. */
    public function airtime(array $payload);

    /** Data bundle. $payload: msisdn, network_code, variation_code, reference. */
    public function data(array $payload);

    /** Cable TV. $payload: smartcard, provider_code, variation_code, reference. */
    public function cable(array $payload);

    /** Electricity. $payload: meter, disco_code, meter_type, amount, reference. */
    public function electricity(array $payload);

    /** Exam PIN. $payload: variation_code, quantity, reference. */
    public function education(array $payload);

    /**
     * Resolve a customer name before purchase, where the provider supports it
     * (meter and smartcard lookups). Returns ok + name, or ok:false.
     */
    public function verify(array $payload);

    /** Re-check an async purchase. Returns ok + status + reference. */
    public function status($reference);

    /** Provider float, for the balance monitor. */
    public function balance();
}

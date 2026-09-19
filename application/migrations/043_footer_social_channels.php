<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Migration 043 — footer social channels.
 *
 * Adds the seven social_*_url settings the site footer renders as channel
 * buttons (Facebook, Instagram, WhatsApp, X, LinkedIn, Telegram, YouTube).
 *
 * Data-only: no tables, no columns. Fresh installs and the generated
 * production SQL get the same rows from Core_seeder::seed_settings(); this
 * migration exists for panels already deployed, whose settings table has no
 * social_* rows yet — the footer tolerates that (marvy_social_links() falls
 * back row-by-row to the platform home pages), but the operator would have
 * nothing to edit under Admin → Settings → Social channels until the rows
 * exist.
 *
 * The shipped default for every channel is the platform's own home page, not
 * a guessed profile URL: a button that opens facebook.com is honest, a button
 * that 404s on a made-up handle is not.
 */
class Migration_Footer_social_channels extends CI_Migration {

    /** setting key => shipped default URL. Mirrors Core_seeder::default_settings(). */
    public static function channels() {
        return array(
            'social_facebook_url'  => 'https://www.facebook.com/',
            'social_instagram_url' => 'https://www.instagram.com/',
            'social_whatsapp_url'  => 'https://www.whatsapp.com/',
            'social_x_url'         => 'https://x.com/',
            'social_linkedin_url'  => 'https://www.linkedin.com/',
            'social_telegram_url'  => 'https://t.me/',
            'social_youtube_url'   => 'https://www.youtube.com/',
        );
    }

    /** Creates no tables — declared for the schema linter. */
    public static function tables() {
        return array();
    }

    /** Data-only migration: no DDL for the schema exporter to diff. */
    public static function statements() {
        return array();
    }

    public function up() {
        if (!$this->table_exists('settings')) return;

        foreach (self::channels() as $key => $url) {
            // Insert-or-leave, never insert-or-overwrite: a row that already
            // exists (a half-applied run, or an operator who added the key by
            // hand) is operator-owned data, not a default to reclaim.
            $exists = $this->db->where('setting_key', $key)->get('settings')->row();
            if ($exists) continue;
            $this->db->insert('settings', array(
                'setting_key'   => $key,
                'setting_value' => json_encode(array('value' => $url)),
                'category'      => 'social',
                'is_public'     => 1,
            ));
        }
    }

    public function down() {
        if (!$this->table_exists('settings')) return;
        $this->db->where_in('setting_key', array_keys(self::channels()))->delete('settings');
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

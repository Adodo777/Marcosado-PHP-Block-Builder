<?php
namespace Marcosado\BlockBuilder;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Marcosado_DB
{
    public static function init(): void
    {
        add_action('plugins_loaded', [self::class, 'maybe_setup_tables']);
    }

    public static function activate(): void
    {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}marcosado_blocks (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            slug VARCHAR(191) NOT NULL,
            code LONGTEXT NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug)
        ) $charset");

        $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}marcosado_blocks_history (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            slug VARCHAR(191) NOT NULL,
            code LONGTEXT NOT NULL,
            saved_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY slug_idx (slug)
        ) $charset");

        $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}marcosado_block_attributes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            block_slug VARCHAR(191) NOT NULL,
            field_key VARCHAR(100) NOT NULL,
            field_label VARCHAR(255) NOT NULL DEFAULT '',
            field_type VARCHAR(50) NOT NULL DEFAULT 'text',
            field_default TEXT NOT NULL,
            field_section VARCHAR(255) NOT NULL DEFAULT 'Général',
            field_sub_fields LONGTEXT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY block_slug (block_slug)
        ) $charset");

    }

    public static function maybe_setup_tables(): void
    {
        global $wpdb;
        $exists = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}marcosado_blocks'");
        if (!$exists) {
            self::activate();
        }

        $bm_attr_exists = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}marcosado_block_attributes'");
        if (!$bm_attr_exists) {
            self::activate();
        } else {
            $col_exists = $wpdb->get_results("SHOW COLUMNS FROM {$wpdb->prefix}marcosado_block_attributes LIKE 'field_section'");
            if (empty($col_exists)) {
                $wpdb->query("ALTER TABLE {$wpdb->prefix}marcosado_block_attributes ADD COLUMN field_section VARCHAR(255) NOT NULL DEFAULT 'Général' AFTER field_default");
            }
            $col_sub = $wpdb->get_results("SHOW COLUMNS FROM {$wpdb->prefix}marcosado_block_attributes LIKE 'field_sub_fields'");
            if (empty($col_sub)) {
                $wpdb->query("ALTER TABLE {$wpdb->prefix}marcosado_block_attributes ADD COLUMN field_sub_fields LONGTEXT NULL AFTER field_section");
            }
        }
    }

    public static function get_attrs_map(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;
        global $wpdb;
        $all = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}marcosado_block_attributes ORDER BY sort_order ASC"
        );
        $cache = [];
        foreach ($all as $attr) {
            $cache[$attr->block_slug][] = $attr;
        }
        return $cache;
    }
}

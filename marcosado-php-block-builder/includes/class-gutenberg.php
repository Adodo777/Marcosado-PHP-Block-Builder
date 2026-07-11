<?php
namespace Marcosado\BlockBuilder;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Marcosado_Gutenberg
{
    public static function init(): void
    {
        add_action('init', [self::class, 'register_all_blocks']);
        add_action('enqueue_block_editor_assets', [self::class, 'enqueue_editor_assets']);
        add_action('enqueue_block_assets', [self::class, 'load_tailwind']);
        add_action('wp_enqueue_scripts', [self::class, 'load_lucide']);
        add_action('admin_enqueue_scripts', [self::class, 'load_lucide']);
    }

    private static function map_field_type(string $type): string
    {
        return match($type) {
            'number'  => 'number',
            'boolean' => 'boolean',
            'image'   => 'string',
            'repeater'=> 'array',
            default   => 'string',
        };
    }

    private static function cast_default(string $type, string $default): mixed
    {
        if ($type === 'repeater') return [];
        if ($default === '') return '';
        return match($type) {
            'number'  => (float) $default,
            'boolean' => ($default === 'true' || $default === '1'),
            default   => $default,
        };
    }

    public static function register_all_blocks(): void
    {
        global $wpdb;

        $blocks = $wpdb->get_results(
            "SELECT slug, name, code FROM {$wpdb->prefix}marcosado_blocks"
        );
        if (empty($blocks)) return;

        $all_attrs = $wpdb->get_results("SELECT block_slug, field_key, field_label, field_type, field_default, field_section, field_sub_fields FROM {$wpdb->prefix}marcosado_block_attributes ORDER BY sort_order ASC");
        $attrs_by_slug = [];
        if ($all_attrs) {
            foreach ($all_attrs as $attr) {
                $attrs_by_slug[$attr->block_slug][] = $attr;
            }
        }

        $block_errors = get_option('marcosado_block_errors', []);

        foreach ($blocks as $block) {
            if (isset($block_errors[$block->slug])) {
                continue;
            }

            $block_attrs = $attrs_by_slug[$block->slug] ?? [];
            $gutenberg_attrs = [];
            foreach ($block_attrs as $attr) {
                $mapped_type = self::map_field_type($attr->field_type);
                $gutenberg_attrs[$attr->field_key] = [
                    'type'    => $mapped_type,
                    'default' => self::cast_default($attr->field_type, $attr->field_default),
                ];
                if ($mapped_type === 'array') {
                    $gutenberg_attrs[$attr->field_key]['items'] = ['type' => 'object'];
                }
            }

            register_block_type('marcosado-block-builder/' . $block->slug, [
                'attributes'      => $gutenberg_attrs,
                'render_callback' => function ($attributes, $content) use ($block) {
                    $slug_clean = sanitize_key($block->slug);
                    $_bm_file_to_include = "bmcode://" . $slug_clean;
                    extract($attributes, EXTR_SKIP);
                    ob_start();
                    include $_bm_file_to_include;
                    return ob_get_clean();
                },
                'category' => 'design',
                'title'    => $block->name,
            ]);
        }
    }

    public static function enqueue_editor_assets(): void
    {
        global $wpdb;

        $js_path = MARCOSADO_PLUGIN_URL . 'editor-blocks.js';
        $js_file = MARCOSADO_PLUGIN_DIR . 'editor-blocks.js';

        wp_enqueue_script(
            'marcosado-block-builder-editor',
            $js_path,
            ['wp-blocks', 'wp-element', 'wp-editor', 'wp-components', 'wp-server-side-render'],
            file_exists($js_file) ? filemtime($js_file) : '3.0'
        );

        $bm_blocks_config = [];
        $blocks = $wpdb->get_results("SELECT slug, name FROM {$wpdb->prefix}marcosado_blocks");
        if (empty($blocks)) return;
        
        $all_attrs = $wpdb->get_results("SELECT block_slug, field_key, field_label, field_type, field_default, field_section, field_sub_fields FROM {$wpdb->prefix}marcosado_block_attributes ORDER BY sort_order ASC");
        
        $attrs_by_slug = [];
        foreach ($all_attrs as $attr) {
            $attrs_by_slug[$attr->block_slug][] = $attr;
        }

        foreach ($blocks as $b) {
            $bm_blocks_config[$b->slug] = [
                'name'       => 'marcosado-block-builder/' . $b->slug,
                'title'      => $b->name,
                'attributes' => $attrs_by_slug[$b->slug] ?? []
            ];
        }
        
        wp_localize_script('marcosado-block-builder-editor', 'marcosado_blocks_config', $bm_blocks_config);
    }

    public static function load_tailwind(): void
    {
        wp_enqueue_script(
            'marcosado-block-builder-tailwind',
            MARCOSADO_PLUGIN_URL . 'tailwind.min.js',
            [], '3.4.17', false
        );
        wp_add_inline_script('marcosado-block-builder-tailwind', '
            tailwind.config = {
                prefix: "tw-",
                corePlugins: { preflight: false }
            }
        ');
    }

    public static function load_lucide(): void
    {
        wp_enqueue_script(
            'lucide-icons',
            MARCOSADO_PLUGIN_URL . 'assets/lucide.min.js',
            [],
            '1.8.0',
            true
        );
        wp_add_inline_script(
            'lucide-icons',
            'document.addEventListener("DOMContentLoaded", function() { if (window.lucide) { lucide.createIcons(); } });',
            'after'
        );
    }
}

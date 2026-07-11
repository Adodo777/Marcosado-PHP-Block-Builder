<?php
namespace Marcosado\BlockBuilder;

if (!defined('ABSPATH')) exit;

class Marcosado_Sync
{
    private static bool $is_syncing = false;

    public static function init(): void
    {
        add_action('save_post', [self::class, 'sync_gutenberg_to_elementor'], 20, 2);
        add_action('elementor/document/after_save', [self::class, 'sync_elementor_to_gutenberg'], 10, 2);
    }

    private static function normalize_for_gutenberg(array $el_settings, array $field_defs): array
    {
        $result = [];
        foreach ($field_defs as $def) {
            $key = $def->field_key;
            $val = $el_settings[$key] ?? null;
            switch ($def->field_type) {
                case 'boolean':
                    $result[$key] = ($val === 'yes' || $val === true || $val === '1' || $val === 1);
                    break;
                case 'image':
                    if (is_array($val)) {
                        $url = $val['url'] ?? '';
                        $result[$key] = (is_string($url) && $url !== '') ? $url : ($def->field_default ?? '');
                    } else {
                        $result[$key] = is_string($val) ? $val : ($def->field_default ?? '');
                    }
                    break;
                case 'number':
                    $result[$key] = is_numeric($val) ? (float) $val : (float) ($def->field_default ?: 0);
                    break;
                case 'repeater':
                    $items = is_array($val) ? $val : [];
                    $sub_defs = [];
                    $raw_sub_fields = $def->field_sub_fields ?? '';
                    $parsed = !empty($raw_sub_fields) ? json_decode($raw_sub_fields, true) : [];
                    if (!is_array($parsed)) $parsed = [];
                    
                    foreach ($parsed as $sub_key => $sub_def) {
                        $obj = new \stdClass();
                        $obj->field_key = $sub_key;
                        $obj->field_type = $sub_def['type'] ?? 'text';
                        $obj->field_default = $sub_def['default'] ?? '';
                        $sub_defs[] = $obj;
                    }
                    
                    $clean_items = [];
                    if (!empty($sub_defs)) {
                        foreach ($items as $item) {
                            if (is_array($item)) {
                                foreach ($item as $k => $v) {
                                    if (is_array($v) && isset($v['url'])) {
                                        $item[$k] = $v['url'];
                                    }
                                }
                                $clean_items[] = self::normalize_for_gutenberg($item, $sub_defs);
                            }
                        }
                    }
                    $result[$key] = $clean_items;
                    break;
                default:
                    $result[$key] = $val !== null ? $val : ($def->field_default ?? '');
                    if (is_array($result[$key])) {
                        if (isset($result[$key]['url'])) {
                            $result[$key] = $result[$key]['url'];
                        } else {
                            $result[$key] = '';
                        }
                    }
                    break;
            }
        }
        return $result;
    }

    private static function extract_elementor_bm_widgets_flat(array $elements): array
    {
        $list = [];
        foreach ($elements as $element) {
            if (
                !empty($element['elType']) && $element['elType'] === 'widget' &&
                !empty($element['widgetType']) && str_starts_with($element['widgetType'], 'bm-')
            ) {
                $list[] = [
                    'slug'     => substr($element['widgetType'], 3),
                    'settings' => $element['settings'] ?? []
                ];
            }
            if (!empty($element['elements']) && is_array($element['elements'])) {
                $children = self::extract_elementor_bm_widgets_flat($element['elements']);
                foreach ($children as $child) {
                    $list[] = $child;
                }
            }
        }
        return $list;
    }

    private static function update_elementor_widgets_from_gut(array &$elements, array &$gut_queue, array $attrs_map): void
    {
        foreach ($elements as $key => &$element) {
            if (
                !empty($element['elType']) && $element['elType'] === 'widget' &&
                !empty($element['widgetType']) && str_starts_with($element['widgetType'], 'bm-')
            ) {
                $slug = substr($element['widgetType'], 3);
                if (!empty($gut_queue[$slug])) {
                    $attrs = array_shift($gut_queue[$slug]);
                    $element['settings'] = self::normalize_for_elementor(
                        $attrs,
                        $attrs_map[$slug] ?? []
                    );
                } else {
                    unset($elements[$key]);
                    continue;
                }
            }
            if (!empty($element['elements']) && is_array($element['elements'])) {
                self::update_elementor_widgets_from_gut($element['elements'], $gut_queue, $attrs_map);
            }
        }
        unset($element);
        $elements = array_values($elements);
    }

    private static function normalize_for_elementor(array $gut_attrs, array $field_defs): array
    {
        $result = $gut_attrs;
        foreach ($field_defs as $def) {
            $key = $def->field_key;
            $val = $gut_attrs[$key] ?? null;
            switch ($def->field_type) {
                case 'boolean':
                    $result[$key] = ($val === true || $val === 'true' || $val === 1 || $val === '1') ? 'yes' : '';
                    break;
                case 'image':
                    $url = is_string($val) ? $val : '';
                    $result[$key] = ['url' => $url, 'id' => 0];
                    break;
                case 'repeater':
                    $items = is_array($val) ? $val : [];
                    $sub_defs = [];
                    if (!empty($def->field_sub_fields)) {
                        $parsed = json_decode($def->field_sub_fields, true) ?? [];
                        foreach ($parsed as $sub_key => $sub_def) {
                            $obj = new \stdClass();
                            $obj->field_key = $sub_key;
                            $obj->field_type = $sub_def['type'] ?? 'text';
                            $obj->field_default = $sub_def['default'] ?? '';
                            $sub_defs[] = $obj;
                        }
                    }
                    if (!empty($sub_defs)) {
                        foreach ($items as &$item) {
                            if (is_array($item)) {
                                $item = self::normalize_for_elementor($item, $sub_defs);
                            }
                        }
                        unset($item);
                    }
                    $result[$key] = $items;
                    break;
            }
        }
        return $result;
    }

    private static function build_elementor_widget(string $slug, array $settings): array
    {
        return [
            'id'         => substr(md5(uniqid('bm_' . $slug, true)), 0, 8),
            'elType'     => 'widget',
            'widgetType' => 'bm-' . $slug,
            'settings'   => $settings,
            'elements'   => [],
        ];
    }

    private static function elementor_uses_containers(): bool
    {
        if (!defined('ELEMENTOR_VERSION')) return true;
        return version_compare(ELEMENTOR_VERSION, '3.16.0', '>=');
    }

    private static function wrap_in_elementor_container(array $widget): array
    {
        $id1 = substr(md5(uniqid('bm_wrap', true)), 0, 8);
        if (self::elementor_uses_containers()) {
            return [
                'id'       => $id1,
                'elType'   => 'container',
                'settings' => [],
                'elements' => [$widget],
            ];
        }
        $id2 = substr(md5(uniqid('bm_col', true)), 0, 8);
        return [
            'id'       => $id1,
            'elType'   => 'section',
            'settings' => [],
            'elements' => [[
                'id'       => $id2,
                'elType'   => 'column',
                'settings' => ['_column_size' => 100],
                'elements' => [$widget],
            ]],
        ];
    }

    public static function sync_gutenberg_to_elementor(int $post_id, \WP_Post $post): void
    {
        if (self::$is_syncing) return;
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) return;
        if (defined('ELEMENTOR_SAVE_IN_PROGRESS') && ELEMENTOR_SAVE_IN_PROGRESS) return;

        $blocks = parse_blocks($post->post_content);

        $gut_queue = [];
        foreach ($blocks as $block) {
            if (!empty($block['blockName']) && str_starts_with($block['blockName'], 'marcosado-block-builder/')) {
                $slug = substr($block['blockName'], strlen('marcosado-block-builder/'));
                $gut_queue[$slug][] = $block['attrs'] ?? [];
            }
        }

        if (empty($gut_queue)) return;

        $attrs_map      = Marcosado_DB::get_attrs_map();
        $raw            = get_post_meta($post_id, '_elementor_data', true);
        $elementor_data = ($raw && is_string($raw)) ? json_decode($raw, true) : [];
        if (!is_array($elementor_data)) $elementor_data = [];

        self::update_elementor_widgets_from_gut($elementor_data, $gut_queue, $attrs_map);

        foreach ($gut_queue as $slug => $remaining) {
            foreach ($remaining as $attrs) {
                $settings = self::normalize_for_elementor($attrs, $attrs_map[$slug] ?? []);
                $widget   = self::build_elementor_widget($slug, $settings);
                $elementor_data[] = self::wrap_in_elementor_container($widget);
            }
        }

        update_post_meta($post_id, '_elementor_data', wp_slash(json_encode($elementor_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
    }

    public static function sync_elementor_to_gutenberg($doc, array $data): void
    {
        if (self::$is_syncing) return;

        $post_id = $doc->get_main_id();

        $raw            = get_post_meta($post_id, '_elementor_data', true);
        $elementor_data = ($raw && is_string($raw)) ? json_decode($raw, true) : [];
        if (!is_array($elementor_data)) return;

        $flat_list = self::extract_elementor_bm_widgets_flat($elementor_data);

        $attrs_map = Marcosado_DB::get_attrs_map();
        $new_blocks = [];

        foreach ($flat_list as $item) {
            $slug = $item['slug'];
            $settings = $item['settings'];
            $new_blocks[] = [
                'blockName'    => 'marcosado-block-builder/' . $slug,
                'attrs'        => self::normalize_for_gutenberg($settings, $attrs_map[$slug] ?? []),
                'innerBlocks'  => [],
                'innerHTML'    => '',
                'innerContent' => [],
            ];
        }

        self::$is_syncing = true;
        wp_update_post([
            'ID'           => $post_id,
            'post_content' => serialize_blocks($new_blocks),
        ]);
        self::$is_syncing = false;
    }
}

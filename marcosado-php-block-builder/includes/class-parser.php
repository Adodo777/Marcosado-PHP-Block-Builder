<?php
if (!defined('ABSPATH')) exit;

class Marcosado_Parser
{
    public static function sync_attributes_from_code(string $slug, string $code): bool
    {
        global $wpdb;

        $file = MARCOSADO_BLOCKS_DIR . $slug . '.php';
        if (!file_exists($file)) {
            return false;
        }

        ob_start();
        $old_error_level = error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
        try {
            include $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            error_reporting($old_error_level);
            error_log("MarcosadoPHPBlockBuilder Parser Include Error (bloc \"$slug\") : " . $e->getMessage());
            return false;
        }
        ob_end_clean();
        error_reporting($old_error_level);

        if (!isset($bm_attributes) || !is_array($bm_attributes) || empty($bm_attributes)) {
            return false;
        }

        $extracted = $bm_attributes;

        $attr_table = $wpdb->prefix . 'marcosado_block_attributes';
        $wpdb->delete($attr_table, ['block_slug' => $slug]);

        $sort_order = 10;
        foreach ($extracted as $key => $options) {
            $key = sanitize_key((string) $key);
            if (empty($key)) continue;

            $sub_fields = isset($options['fields']) && is_array($options['fields']) ? wp_json_encode($options['fields']) : null;

            $wpdb->insert($attr_table, [
                'block_slug'       => $slug,
                'field_key'        => $key,
                'field_label'      => sanitize_text_field($options['label'] ?? ucfirst($key)),
                'field_type'       => sanitize_text_field($options['type']  ?? 'text'),
                'field_default'    => sanitize_text_field($options['default'] ?? ''),
                'field_section'    => sanitize_text_field($options['section'] ?? 'Général'),
                'field_sub_fields' => $sub_fields,
                'sort_order'       => $sort_order,
            ]);
            $sort_order += 10;
        }

        return true;
    }

    public static function inject_bm_attributes_from_db(string $slug, string $code): string
    {
        global $wpdb;

        if (preg_match('/<\?php\s*(.*?)\?>/s', $code, $m) && strpos($m[1], '$bm_attributes') !== false) {
            return $code;
        }

        $attrs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}marcosado_block_attributes WHERE block_slug = %s ORDER BY sort_order ASC",
            $slug
        ));

        if (empty($attrs)) {
            return $code;
        }

        $lines = [];
        foreach ($attrs as $attr) {
            $type    = addslashes($attr->field_type);
            $label   = addslashes($attr->field_label);
            $default = addslashes($attr->field_default);
            $section = addslashes($attr->field_section);
            $sub_fields = '';
            if (!empty($attr->field_sub_fields)) {
                $decoded = json_decode($attr->field_sub_fields, true);
                if (is_array($decoded)) {
                    $sub_fields = ", 'fields' => " . var_export($decoded, true);
                }
            }
            $lines[] = "    '{$attr->field_key}' => ['type' => '{$type}', 'label' => '{$label}', 'default' => '{$default}', 'section' => '{$section}'{$sub_fields}],";
        }

        $declaration = "<?php\n"
            . "/**\n"
            . " * Attributs du bloc — migrés automatiquement depuis la base de données.\n"
            . " * Modifiez ce tableau pour changer la configuration des champs.\n"
            . " */\n"
            . "\$bm_attributes = [\n"
            . implode("\n", $lines) . "\n"
            . "];\n"
            . "?>\n";

        return $declaration . $code;
    }
}

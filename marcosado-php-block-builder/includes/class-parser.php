<?php
namespace Marcosado\BlockBuilder;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Marcosado_Parser
{
    public static function sync_attributes_from_code(string $slug, string $code): bool
    {
        global $wpdb;

        $slug_clean = sanitize_key($slug);
        $_bm_file_to_include = "bmcode://" . $slug_clean;

        if (!function_exists('token_get_all')) {
            return false;
        }

        // Reconstruction statique déterministe (sans eval ni include)
        $tokens = token_get_all($code);
        $attr_tokens = [];
        $in_attr = false;
        $bracket_level = 0;
        $has_array = false;

        foreach ($tokens as $token) {
            $is_array = is_array($token);
            $id = $is_array ? $token[0] : null;
            $text = $is_array ? $token[1] : $token;

            if (!$in_attr) {
                if ($id === T_VARIABLE && $text === '$bm_attributes') {
                    $in_attr = true;
                }
                continue;
            }

            if ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT || $text === '=') {
                continue;
            }

            if ($text === '[' || $id === T_ARRAY) {
                $bracket_level++;
                $has_array = true;
            } elseif ($text === ']' || $text === ')') {
                $bracket_level--;
            } elseif ($text === ';') {
                if ($bracket_level === 0) break;
            }

            $attr_tokens[] = $token;

            if ($has_array && $bracket_level === 0) {
                break;
            }
        }

        if (empty($attr_tokens)) {
            return false;
        }

        // Parseur AST maison pour tableau littéral
        $bm_attributes = self::parse_literal_array($attr_tokens);

        if (!is_array($bm_attributes) || empty($bm_attributes)) {
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

    private static function parse_literal_array(array $tokens, &$index = 0)
    {
        $result = [];
        $current_key = null;
        $is_associative = false;

        while ($index < count($tokens)) {
            $token = $tokens[$index];
            $index++;

            $is_array = is_array($token);
            $id = $is_array ? $token[0] : null;
            $text = $is_array ? $token[1] : $token;

            if ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT) {
                continue;
            }

            if ($text === '[' || $id === T_ARRAY || $text === '(') {
                if ($id === T_ARRAY) continue; // skip 'array' keyword, next should be '('
                
                $value = self::parse_literal_array($tokens, $index);
                if ($value === null) return null; // bubble up failure

                if ($current_key !== null) {
                    $result[$current_key] = $value;
                    $current_key = null;
                } else {
                    $result[] = $value;
                }
            } elseif ($text === ']' || $text === ')') {
                return $result;
            } elseif ($id === T_CONSTANT_ENCAPSED_STRING) {
                $val = stripcslashes(substr($text, 1, -1));
                
                // Peek next meaningful token to see if it's =>
                $next_is_arrow = false;
                $peek = $index;
                while ($peek < count($tokens)) {
                    $n_token = $tokens[$peek];
                    $n_id = is_array($n_token) ? $n_token[0] : null;
                    if ($n_id === T_WHITESPACE || $n_id === T_COMMENT || $n_id === T_DOC_COMMENT) {
                        $peek++; continue;
                    }
                    if ($n_id === T_DOUBLE_ARROW) {
                        $next_is_arrow = true;
                        $index = $peek + 1;
                    }
                    break;
                }

                if ($next_is_arrow) {
                    $current_key = $val;
                    $is_associative = true;
                } else {
                    if ($current_key !== null) {
                        $result[$current_key] = $val;
                        $current_key = null;
                    } else {
                        $result[] = $val;
                    }
                }
            } elseif ($id === T_LNUMBER || $id === T_DNUMBER) {
                $val = $text + 0;
                
                $next_is_arrow = false;
                $peek = $index;
                while ($peek < count($tokens)) {
                    $n_token = $tokens[$peek];
                    $n_id = is_array($n_token) ? $n_token[0] : null;
                    if ($n_id === T_WHITESPACE || $n_id === T_COMMENT || $n_id === T_DOC_COMMENT) {
                        $peek++; continue;
                    }
                    if ($n_id === T_DOUBLE_ARROW) {
                        $next_is_arrow = true;
                        $index = $peek + 1;
                    }
                    break;
                }

                if ($next_is_arrow) {
                    $current_key = $val;
                    $is_associative = true;
                } else {
                    if ($current_key !== null) {
                        $result[$current_key] = $val;
                        $current_key = null;
                    } else {
                        $result[] = $val;
                    }
                }
            } elseif ($text === ',') {
                continue;
            } else {
                // Invalid token (variable, function call, operation, etc.)
                // Reject immediately
                return null;
            }
        }

        return $result;
    }
}

<?php
if (!defined('ABSPATH')) exit;

class Marcosado_Elementor
{
    public static function init(): void
    {
        add_action('elementor/elements/categories_registered', [self::class, 'register_elementor_category']);
        add_action('elementor/widgets/register', [self::class, 'register_elementor_widgets']);
    }

    public static function register_elementor_category($manager): void {
        $manager->add_category('marcosado', [
            'title' => 'Marcosado Blocks',
            'icon'  => 'eicon-code',
        ]);
    }

    public static function register_elementor_widgets($manager): void {
        if (!did_action('elementor/loaded') || !class_exists('\Elementor\Widget_Base')) return;

        global $wpdb;

        $blocks = $wpdb->get_results("SELECT slug, name, code FROM {$wpdb->prefix}marcosado_blocks");
        if (empty($blocks)) return;

        $all_attrs = $wpdb->get_results("SELECT block_slug, field_key, field_label, field_type, field_default, field_section, field_sub_fields FROM {$wpdb->prefix}marcosado_block_attributes ORDER BY sort_order ASC");
        
        $attrs_by_slug = [];
        if ($all_attrs) {
            foreach ($all_attrs as $attr) {
                $attrs_by_slug[$attr->block_slug][] = $attr;
            }
        }

        foreach ($blocks as $bloc) {
            $bm_attributes = $attrs_by_slug[$bloc->slug] ?? [];
            if (class_exists('Marcosado_Dynamic_Widget')) {
                $manager->register(new \Marcosado_Dynamic_Widget([], [
                    'bloc' => $bloc,
                    'bm_attributes' => $bm_attributes
                ]));
            }
        }
    }
}

add_action('elementor/init', function() {
    if (!class_exists('\Elementor\Widget_Base')) return;

    class Marcosado_Dynamic_Widget extends \Elementor\Widget_Base {
        private object $bloc;
        private array $bm_attributes;

        public function __construct(array $data = [], ?array $args = null) {
            $this->bloc = $args['bloc'];
            $this->bm_attributes = $args['bm_attributes'] ?? [];
            parent::__construct($data, $args);
        }

        public function get_name():       string { return 'bm-' . $this->bloc->slug; }
        public function get_title():      string { return $this->bloc->name; }
        public function get_icon():       string { return 'eicon-code'; }
        public function get_categories(): array  { return ['marcosado']; }
        public function is_dynamic_content(): bool { return true; }

        protected function register_controls(): void {
            $sections = [];
            foreach ($this->bm_attributes as $attr) {
                if (empty($attr->field_key)) continue;
                $sec = $attr->field_section ?? 'Général';
                $sections[$sec][] = $attr;
            }

            if (empty($sections)) {
                $this->start_controls_section('content_section', [
                    'label' => 'Paramètres (' . $this->bloc->name . ')',
                    'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
                ]);
                $this->end_controls_section();
                return;
            }

            $i = 0;
            foreach ($sections as $sec_name => $attrs) {
                $this->start_controls_section('section_' . $i, [
                    'label' => $sec_name,
                    'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
                ]);

                foreach ($attrs as $attr) {
                    if ($attr->field_type === 'repeater') {
                        $repeater = new \Elementor\Repeater();
                        $sub_fields_def = !empty($attr->field_sub_fields) && is_string($attr->field_sub_fields) ? json_decode($attr->field_sub_fields, true) : [];
                        if (!is_array($sub_fields_def)) $sub_fields_def = [];
                        
                        $title_field = '';
                        foreach ($sub_fields_def as $sub_key => $sub_def) {
                            $sub_type = $sub_def['type'] ?? 'text';
                            $sub_args = [
                                'label' => $sub_def['label'] ?? $sub_key,
                                'type'  => $this->map_type($sub_type),
                                'default' => $this->cast_default_elementor($sub_type, $sub_def['default'] ?? ''),
                            ];
                            if ($sub_type === 'boolean') $sub_args['return_value'] = 'yes';
                            if ($sub_type === 'select') {
                                $sub_args['options'] = $this->parse_select_options($sub_def['default'] ?? '');
                                $first = array_key_first($sub_args['options']);
                                $sub_args['default'] = $first ?? '';
                            }
                            if ($sub_type === 'text' && empty($title_field)) $title_field = '{{{ ' . $sub_key . ' }}}';
                            $repeater->add_control($sub_key, $sub_args);
                        }
                        $rep_args = [
                            'label' => $attr->field_label ?: $attr->field_key,
                            'type' => \Elementor\Controls_Manager::REPEATER,
                            'fields' => $repeater->get_controls(),
                            'default' => [],
                        ];
                        if ($title_field) $rep_args['title_field'] = $title_field;
                        $this->add_control($attr->field_key, $rep_args);
                        continue;
                    }

                    $control_args = [
                        'label'   => $attr->field_label ?: $attr->field_key,
                        'type'    => $this->map_type($attr->field_type),
                        'default' => $this->cast_default_elementor($attr->field_type, $attr->field_default),
                    ];

                    if ($attr->field_type === 'boolean') {
                        $control_args['return_value'] = 'yes';
                    }

                    if ($attr->field_type === 'select') {
                        $control_args['options'] = $this->parse_select_options($attr->field_default);
                        $first = array_key_first($control_args['options']);
                        $control_args['default'] = $first ?? '';
                    }

                    $this->add_control($attr->field_key, $control_args);
                }

                $this->end_controls_section();
                $i++;
            }
        }

        private function parse_select_options(string $raw): array {
            $options = [];
            foreach (explode(',', $raw) as $pair) {
                $pair = trim($pair);
                if ($pair === '') continue;
                if (strpos($pair, ':') !== false) {
                    [$val, $label] = explode(':', $pair, 2);
                    $options[trim($val)] = trim($label);
                } else {
                    $options[$pair] = $pair;
                }
            }
            return $options ?: ['' => '— choisir —'];
        }

        protected function render(): void {
            $attributes = $this->get_settings_for_display();

            foreach ($this->bm_attributes as $attr) {
                if ($attr->field_type === 'boolean') {
                    $attributes[$attr->field_key] = ($attributes[$attr->field_key] ?? '') === 'yes';
                } elseif ($attr->field_type === 'image') {
                    $media_val = $attributes[$attr->field_key] ?? [];
                    $attributes[$attr->field_key] = is_array($media_val) && isset($media_val['url']) ? $media_val['url'] : '';
                } elseif ($attr->field_type === 'repeater') {
                    $items = $attributes[$attr->field_key] ?? [];
                    if (is_array($items)) {
                        $sub_fields_def = !empty($attr->field_sub_fields) ? json_decode($attr->field_sub_fields, true) : [];
                        if (!is_array($sub_fields_def)) $sub_fields_def = [];

                        foreach ($items as &$item) {
                            foreach ($item as $k => $v) {
                                if (is_array($v) && isset($v['url'])) {
                                    $item[$k] = $v['url'];
                                }
                            }
                            foreach ($sub_fields_def as $sub_key => $sub_def) {
                                $sub_type = $sub_def['type'] ?? 'text';
                                if ($sub_type === 'boolean') {
                                    $item[$sub_key] = ($item[$sub_key] ?? '') === 'yes';
                                } elseif ($sub_type === 'image') {
                                    $media_val = $item[$sub_key] ?? [];
                                    if (is_array($media_val) && isset($media_val['url'])) {
                                        $item[$sub_key] = $media_val['url'];
                                    } elseif (!is_string($item[$sub_key] ?? null)) {
                                        $item[$sub_key] = '';
                                    }
                                }
                            }
                        }
                        unset($item);
                    }
                    $attributes[$attr->field_key] = $items;
                }
            }

            $file = MARCOSADO_BLOCKS_DIR . $this->bloc->slug . '.php';

            if (!file_exists($file)) {
                $header = "<?php\nif (!defined('ABSPATH')) exit;\n/**\n * Block Name: " . $this->bloc->name . "\n */\n?>\n";
                file_put_contents($file, $header . $this->bloc->code);
            }

            $_bm_file_to_include = $file;
            extract($attributes, EXTR_SKIP);
            ob_start();
            if (file_exists($_bm_file_to_include)) {
                include $_bm_file_to_include;
            }
            echo ob_get_clean();
        }

        private function map_type(string $type): string {
            return match($type) {
                'number'   => \Elementor\Controls_Manager::NUMBER,
                'boolean'  => \Elementor\Controls_Manager::SWITCHER,
                'color'    => \Elementor\Controls_Manager::COLOR,
                'select'   => \Elementor\Controls_Manager::SELECT,
                'textarea' => \Elementor\Controls_Manager::TEXTAREA,
                'image'    => \Elementor\Controls_Manager::MEDIA,
                default    => \Elementor\Controls_Manager::TEXT,
            };
        }

        private function cast_default_elementor(string $type, string $default): mixed {
            return match($type) {
                'number'  => (int) $default,
                'boolean' => ($default === 'true' || $default === '1') ? 'yes' : '',
                'image'   => ['url' => $default, 'id' => ''],
                'select'  => '',
                default   => $default,
            };
        }
    }
});

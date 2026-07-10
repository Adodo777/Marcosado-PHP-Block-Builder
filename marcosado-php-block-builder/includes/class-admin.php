<?php
if (!defined('ABSPATH')) exit;

class Marcosado_Admin
{
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'create_menu']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_admin_assets']);
        
        // Retirer le footer WordPress sur l'administration (Blocks Lab)
        if (is_admin()) {
            add_filter('admin_footer_text', '__return_empty_string', 999);
            add_filter('update_footer', '__return_empty_string', 999);
        }
    }

    public static function create_menu(): void
    {
        add_menu_page(
            'Marcosado PHP Block Builder',
            'Marcosado PHP Block Builder',
            'install_plugins',
            'marcosado-php-block-builder',
            [self::class, 'admin_page'],
            'dashicons-editor-code'
        );

        add_submenu_page(
            'marcosado-php-block-builder',
            'Marcosado PHP Block Builder',
            'Blocks Lab',
            'install_plugins',
            'marcosado-php-block-builder',
            [self::class, 'admin_page']
        );
    }

    public static function enqueue_admin_assets(string $hook): void
    {
        if ($hook === 'toplevel_page_marcosado-php-block-builder') {
            $settings = wp_enqueue_code_editor(['type' => 'text/x-php']);

            wp_enqueue_script(
                'marcosado-block-builder-admin-js',
                MARCOSADO_PLUGIN_URL . 'admin-lab.js',
                ['jquery'], '2.0', true
            );
            wp_localize_script('marcosado-block-builder-admin-js', 'mpbbSettings', $settings);

            wp_add_inline_style('common', '
                .marcosado-php-block-builder-container { display: flex; gap: 20px; margin-top: 20px; max-width: 100%; overflow: hidden; }
                .marcosado-block-builder-editor { flex: 2; background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); min-width: 0; }
                .marcosado-block-builder-sidebar { flex: 1; background: #f0f0f1; padding: 20px; border-radius: 8px; min-width: 0; }
                .marcosado-block-builder-list-item { display: flex; justify-content: space-between; padding: 10px; border-bottom: 1px solid #ddd; align-items: center; }
                .marcosado-block-builder-list-item:last-child { border-bottom: none; }
                .btn-del { color: #d63638; text-decoration: none; font-size: 12px; }
                .btn-del:hover { color: #b32d2e; }
                .CodeMirror { height: 500px; border: 1px solid #ddd; border-radius: 4px; }
                #wpfooter { display: none !important; }
            ');
        }
    }

    private static function strip_block_header(string $raw): string
    {
        return preg_replace('/<\?php\s*(if\s*\(!defined\(\'ABSPATH\'\)\)\s*exit;)?\s*\/\*\*.*?\*\/\s*\?>\n/s', '', $raw);
    }

    public static function write_block_file(string $slug, string $name, string $code): void
    {
        $blocks_dir = MARCOSADO_BLOCKS_DIR;
        $header = "<?php\nif (!defined('ABSPATH')) exit;\n/**\n * Block Name: " . $name . "\n */\n?>\n";
        file_put_contents($blocks_dir . $slug . '.php', $header . $code);
    }

    public static function migrate_files_to_db(): void
    {
        global $wpdb;
        $blocks_dir = MARCOSADO_PLUGIN_DIR . 'blocks/';
        if (!file_exists($blocks_dir)) return;

        foreach (glob($blocks_dir . '*.php') ?: [] as $file) {
            $slug = basename($file, '.php');
            if (str_starts_with($slug, 'marcosado-')) continue;

            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}marcosado_blocks WHERE slug = %s",
                $slug
            ));
            if (!$exists) {
                $raw  = file_get_contents($file);
                $code = self::strip_block_header($raw);
                $name = ucwords(str_replace('-', ' ', $slug));
                $wpdb->insert($wpdb->prefix . 'marcosado_blocks', [
                    'name'       => $name,
                    'slug'       => $slug,
                    'code'       => $code,
                    'updated_at' => current_time('mysql'),
                ]);
            }
        }
    }

    private static function save_block(string $name, string $code): void
    {
        global $wpdb;
        $slug        = sanitize_title($name);
        $table       = $wpdb->prefix . 'marcosado_blocks';
        $table_hist  = $wpdb->prefix . 'marcosado_blocks_history';

        $current = $wpdb->get_var($wpdb->prepare(
            "SELECT code FROM $table WHERE slug = %s", $slug
        ));
        if ($current !== null) {
            $wpdb->insert($table_hist, [
                'slug'     => $slug,
                'code'     => $current,
                'saved_at' => current_time('mysql'),
            ]);
            $count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table_hist WHERE slug = %s", $slug
            ));
            if ($count > 5) {
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM $table_hist WHERE slug = %s ORDER BY saved_at ASC LIMIT %d",
                    $slug, $count - 5
                ));
            }
        }

        $wpdb->query($wpdb->prepare(
            "INSERT INTO $table (name, slug, code, updated_at)
             VALUES (%s, %s, %s, %s)
             ON DUPLICATE KEY UPDATE name = VALUES(name), code = VALUES(code), updated_at = VALUES(updated_at)",
            $name, $slug, $code, current_time('mysql')
        ));

        self::write_block_file($slug, $name, $code);
        Marcosado_Parser::sync_attributes_from_code($slug, $code);

        error_log(sprintf(
            'MarcosadoPHPBlockBuilder: Bloc "%s" modifié par l\'utilisateur #%d (%s) le %s',
            $slug,
            get_current_user_id(),
            wp_get_current_user()->user_login,
            current_time('mysql')
        ));
    }

    private static function load_block(string $slug): string
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT code FROM {$wpdb->prefix}marcosado_blocks WHERE slug = %s", $slug
        ));
        return $row ? $row->code : '';
    }

    private static function load_block_name(string $slug): string
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT name FROM {$wpdb->prefix}marcosado_blocks WHERE slug = %s", $slug
        ));
        return $row ? $row->name : ucwords(str_replace('-', ' ', $slug));
    }

    private static function delete_block(string $slug): void
    {
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'marcosado_blocks_history', ['slug' => $slug]);
        $wpdb->delete($wpdb->prefix . 'marcosado_blocks', ['slug' => $slug]);
        $file = MARCOSADO_BLOCKS_DIR . $slug . '.php';
        if (file_exists($file)) {
            unlink($file);
        }
    }

    private static function regenerate_all_files(): int
    {
        global $wpdb;
        $blocks = $wpdb->get_results("SELECT slug, name, code FROM {$wpdb->prefix}marcosado_blocks");
        foreach ($blocks as $b) {
            self::write_block_file($b->slug, $b->name, $b->code);
        }
        return count($blocks);
    }

    public static function admin_page(): void
    {
        global $wpdb;

        if (defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT) {
            wp_die('L\'édition de code est désactivée sur ce serveur.');
        }

        if (isset($_GET['delete'])) {
            $delete_slug = sanitize_title(wp_unslash($_GET['delete']));
            if (check_admin_referer('bm_delete_' . $delete_slug)) {
                self::delete_block($delete_slug);
                echo '<div class="updated"><p>Bloc supprimé.</p></div>';
            }
        }

        if (isset($_GET['regenerate']) && check_admin_referer('bm_regenerate')) {
            $count = self::regenerate_all_files();
            echo '<div class="updated"><p>' . $count . ' fichier(s) régénéré(s) avec succès.</p></div>';
        }

        if (isset($_POST['save_block']) && check_admin_referer('bm_save')) {
            $name = sanitize_text_field($_POST['block_name']);
            $code = wp_unslash($_POST['block_code']);
            self::save_block($name, $code);
            echo '<div class="updated"><p>Bloc "' . esc_html($name) . '" enregistré avec succès !</p></div>';
        }

        $edit_slug = '';
        $edit_name = '';
        $edit_code = '';

        if (isset($_GET['edit'])) {
            $edit_slug = sanitize_title($_GET['edit']);
            $edit_code = self::load_block($edit_slug);
            $edit_name = self::load_block_name($edit_slug);

            if (isset($_GET['restore'])) {
                $history_id = (int) $_GET['restore'];
                $hist_row   = $wpdb->get_row($wpdb->prepare(
                    "SELECT code FROM {$wpdb->prefix}marcosado_blocks_history WHERE id = %d AND slug = %s",
                    $history_id, $edit_slug
                ));
                if ($hist_row) {
                    $edit_code = $hist_row->code;
                }
            }
        }

        $history = [];
        if ($edit_slug) {
            $edit_code = Marcosado_Parser::inject_bm_attributes_from_db($edit_slug, $edit_code);

            $history = $wpdb->get_results($wpdb->prepare(
                "SELECT id, saved_at FROM {$wpdb->prefix}marcosado_blocks_history
                 WHERE slug = %s ORDER BY saved_at DESC LIMIT 5",
                $edit_slug
            ));
        }

        $all_blocks = $wpdb->get_results(
            "SELECT slug, name FROM {$wpdb->prefix}marcosado_blocks ORDER BY name ASC"
        );

        ?>
        <div class="wrap">
            <h1>Marcosado PHP Block Builder</h1>

            <div class="marcosado-php-block-builder-container">

                <div class="marcosado-block-builder-editor">
                    <h3><?php echo $edit_name ? 'Modifier : ' . esc_html($edit_name) : 'Créer un nouveau bloc'; ?></h3>

                    <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=marcosado-php-block-builder')); ?>">
                        <?php wp_nonce_field('bm_save'); ?>

                        <label>Nom du bloc :</label>
                        <input type="text" name="block_name" value="<?php echo esc_attr($edit_name); ?>" required
                            style="width:100%; margin-bottom:15px; padding:8px;" placeholder="Ex: Hero Section">

                        <label>Code PHP du bloc :</label>
                        <textarea name="block_code" id="block-code-editor" rows="20"
                            style="width:100%; font-family:Consolas, Monaco, monospace; background:#1d2327; color:#f0f0f1; padding:15px; line-height:1.5; border-radius:4px;"><?php echo esc_textarea($edit_code); ?></textarea>

                        <div style="margin-top: 20px; background: #f0f6fc; border-left: 4px solid #2271b1; padding: 14px 18px; border-radius: 0 4px 4px 0;">
                            <div style="margin-bottom: 15px;">
                                <label style="display:block; margin-bottom: 5px; font-weight: 600; color: #2271b1; font-size: 14px;">&#9432; Générer avec une IA</label>
                                <textarea id="bm-ai-description" rows="2" style="width: 100%; margin-bottom: 10px; padding: 8px; border-radius: 4px; border: 1px solid #ccd0d4;" placeholder="Description optionnelle de votre bloc (ex: Je veux un Hero avec un grand titre, un sous-titre et deux boutons d'action)"></textarea>
                                <button type="button" id="bm-copy-ai-prompt" class="button button-secondary button-small" style="display: inline-flex; align-items: center; gap: 4px;">
                                    📋 Copier le Prompt IA
                                </button>
                            </div>
                            
                            <p style="margin: 0 0 10px; font-size: 13px; color: #444;">
                                <strong>Optionnel :</strong> Déclarez <code>$bm_attributes</code> dans le <strong>premier bloc PHP</strong> de votre code pour créer automatiquement des champs dans la barre latérale si votre bloc en a besoin.
                            </p>

                            <details style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 10px; margin-bottom: 5px;">
                                <summary style="font-weight: 600; color: #2271b1; cursor: pointer; user-select: none;">Voir l'exemple et les types autorisés</summary>
                                <div style="margin-top: 10px;">
                                    <pre style="background:#1d2327;color:#f0f0f1;padding:12px;border-radius:4px;font-size:11px;line-height:1.6;margin:0 0 10px;overflow-x:auto;"><?php
                                    $bm_example = '<?php' . "\n"
                                        . '// (Optionnel) Déclarez les attributs dynamiques dont vous avez besoin' . "\n"
                                        . '$bm_attributes = [' . "\n"
                                        . "    'titre'   => ['type' => 'text',    'label' => 'Titre',  'default' => 'Mon titre']," . "\n"
                                        . "    'couleur' => ['type' => 'color',   'label' => 'Couleur','default' => '#3B82F6']," . "\n"
                                        . "    'visible' => ['type' => 'boolean', 'label' => 'Bouton', 'default' => 'true']," . "\n"
                                        . "    'photo'   => ['type' => 'image',   'label' => 'Image',  'default' => '']," . "\n"
                                        . "    'choix'   => ['type' => 'select',  'label' => 'Mode',   'default' => 'opt1:Option 1,opt2:Option 2']," . "\n"
                                        . "    'items'   => ['type' => 'repeater','label' => 'Liste',  'sub_fields' => json_encode(['texte' => ['type' => 'text', 'label' => 'Texte']])]," . "\n"
                                        . ']' . ";\n"
                                        . '?' . ">\n"
                                        . '<h1 style="color: <?php echo $couleur; ?>"><?php echo esc_html($titre); ?></h1>';
                                    echo esc_html($bm_example);
                                    ?></pre>
                                    <p style="margin:0; font-size:12px; color:#555; line-height: 1.5;">
                                        <strong>Types disponibles :</strong> <code>text</code> · <code>textarea</code> · <code>number</code> · <code>boolean</code> · <code>color</code> · <code>image</code> · <code>select</code> · <code>repeater</code>.<br>
                                        <strong>Stylisation :</strong> Utilisez les classes Tailwind avec le préfixe <code>tw-</code> (ex: <code>tw-text-white</code>). Pour les couleurs dynamiques, préférez l'attribut <code>style=""</code>.
                                    </p>
                                </div>
                            </details>
                        </div>

                        <p style="margin-top: 20px;">
                            <input type="submit" name="save_block" class="button button-primary button-large"
                                value="Enregistrer le Bloc">
                        </p>
                    </form>
                </div>

                <div class="marcosado-block-builder-sidebar">
                    <h3>Mes Blocs</h3>
                    <?php if (empty($all_blocks)) : ?>
                        <p>Aucun bloc créé.</p>
                    <?php endif; ?>

                    <?php foreach ($all_blocks as $block) :
                        $del_url = wp_nonce_url(
                            admin_url('admin.php?page=marcosado-php-block-builder&delete=' . $block->slug),
                            'bm_delete_' . $block->slug
                        );
                        $edit_url = admin_url('admin.php?page=marcosado-php-block-builder&edit=' . $block->slug);
                    ?>
                        <div class="marcosado-block-builder-list-item">
                            <span><strong><?php echo esc_html($block->name); ?></strong></span>
                            <div>
                                <a href="<?php echo esc_url($edit_url); ?>">Éditer</a> |
                                <a href="<?php echo esc_url($del_url); ?>"
                                   class="btn-del"
                                   onclick="return confirm('Supprimer ce bloc ?')">Supprimer</a>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <div style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #ddd;">
                        <?php
                        $regen_url = wp_nonce_url(
                            admin_url('admin.php?page=marcosado-php-block-builder&regenerate=1'),
                            'bm_regenerate'
                        );
                        ?>
                        <a href="<?php echo esc_url($regen_url); ?>"
                           class="button button-secondary"
                           onclick="return confirm('Régénérer tous les fichiers PHP depuis la base de données ?')"
                           title="Recrée tous les fichiers .php dans /blocks/ depuis la DB">
                            🔄 Régénérer tous les fichiers
                        </a>
                    </div>

                    <?php if ($edit_slug && !empty($history)) : ?>
                        <div style="margin-top: 25px; padding-top: 15px; border-top: 1px solid #ddd;">
                            <h4>📋 Historique des versions</h4>
                            <?php foreach ($history as $i => $version) :
                                $restore_url = admin_url(
                                    'admin.php?page=marcosado-php-block-builder&edit=' . $edit_slug . '&restore=' . $version->id
                                );
                                $label = 'v' . (count($history) - $i);
                                $date  = date_i18n('d M Y H:i', strtotime($version->saved_at));
                            ?>
                                <div class="marcosado-block-builder-list-item">
                                    <span style="font-size: 13px;">
                                        <strong><?php echo esc_html($label); ?></strong>
                                        — <?php echo esc_html($date); ?>
                                    </span>
                                    <a href="<?php echo esc_url($restore_url); ?>"
                                       class="button button-small"
                                       style="font-size: 11px;">Restaurer</a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php elseif ($edit_slug) : ?>
                        <div style="margin-top: 25px; padding-top: 15px; border-top: 1px solid #ddd;">
                            <h4>📋 Historique des versions</h4>
                            <p style="font-size: 13px; color: #666;">Aucune version sauvegardée.</p>
                        </div>
                    <?php endif; ?>

                </div>
            </div>
        </div>
        <?php
    }
}

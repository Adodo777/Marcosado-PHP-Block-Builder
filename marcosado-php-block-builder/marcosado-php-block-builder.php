<?php
/**
 * Plugin Name: Marcosado PHP Block Builder
 * Description: Create and manage custom Gutenberg blocks using PHP, Tailwind CSS, and dynamic attributes, with Elementor support.
 * Version: 1.0.0
 * Author: marcosado
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) exit;

// ─────────────────────────────────────────────────────────────────────────────
// Hooks d'activation et de désinstallation
// ─────────────────────────────────────────────────────────────────────────────
register_activation_hook(__FILE__, ['MarcosadoPHPBlockBuilder', 'activate']);

// ─────────────────────────────────────────────────────────────────────────────
// Classe principale
// ─────────────────────────────────────────────────────────────────────────────
class MarcosadoPHPBlockBuilder
{
    // Flag anti-boucle infinie pour le pont Gutenberg ↔ Elementor
    private static bool $_bm_syncing = false;


    // ──────────────────────────────────────────────────────────────────────────
    // ACTIVATION — création des tables + migration des fichiers existants
    // ──────────────────────────────────────────────────────────────────────────

    public static function activate(): void
    {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        // ── Tables originales Marcosado PHP Block Builder ──────────────────────────────────

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

        // Attributs dynamiques de blocs (Marcosado PHP Block Builder)
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

        // Migration des blocs fichiers -> DB
        self::migrate_files_to_db();
    }



    // ──────────────────────────────────────────────────────────────────────────
    // CONSTRUCTEUR — enregistrement des hooks WordPress
    // ──────────────────────────────────────────────────────────────────────────

    public function __construct()
    {
        // Securite : verification/creation des tables si upload FTP
        add_action('plugins_loaded', [$this, 'maybe_setup_tables']);

        add_action('admin_menu',                  [$this, 'create_menu']);
        add_action('init',                        [$this, 'register_all_blocks']);
        add_action('admin_enqueue_scripts',       [$this, 'enqueue_admin_assets']);
        add_action('enqueue_block_editor_assets', [$this, 'enqueue_editor_assets']);
        add_action('enqueue_block_assets', [$this, 'load_tailwind']);

        // Lucide Icons - hooks corrects pour wp_enqueue_script
        add_action('wp_enqueue_scripts',   [$this, 'load_lucide']);
        add_action('admin_enqueue_scripts', [$this, 'load_lucide']);

        // Elementor Support
        add_action('elementor/elements/categories_registered', [$this, 'register_elementor_category']);
        add_action('elementor/widgets/register',               [$this, 'register_elementor_widgets']);

        // ── Pont bidirectionnel Gutenberg ↔ Elementor ──────────────────────
        add_action('save_post',                     [$this, 'bm_sync_gutenberg_to_elementor'], 20, 2);
        add_action('elementor/document/after_save', [$this, 'bm_sync_elementor_to_gutenberg'], 10, 2);

        // Retirer le footer WordPress sur l'administration (Blocks Lab)
        if (is_admin()) {
            add_filter('admin_footer_text', '__return_empty_string', 999);
            add_filter('update_footer', '__return_empty_string', 999);
        }
    }
    
    /**
     * Vérifie si les tables existent et les crée si besoin.
     * Appelé sur 'plugins_loaded' pour couvrir les uploads FTP et MAJ.
     */
    public function maybe_setup_tables(): void
    {
        global $wpdb;
        $exists = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}marcosado_blocks'");
        if (!$exists) {
            self::activate();
        }

        $sm_attr_exists = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}marcosado_block_attributes'");
        if (!$sm_attr_exists) {
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

    // ──────────────────────────────────────────────────────────────────────────
    // UTILITAIRES BLOCS (privés)
    // ──────────────────────────────────────────────────────────────────────────

    public static function get_blocks_dir(): string
    {
        $upload_dir = wp_upload_dir();
        $dir = $upload_dir['basedir'] . '/marcosado-php-block-builder/';
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
            file_put_contents($dir . 'index.php', '<?php // Silence is golden');
            file_put_contents($dir . '.htaccess', "<Files *.php>\n    deny from all\n</Files>");
        }
        return $dir;
    }

    /**
     * Retire l'en-tête PHP auto-généré du code stocké en fichiers.
     */
    private static function strip_block_header(string $raw): string
    {
        return preg_replace('/<\?php\s*(if\s*\(!defined\(\'ABSPATH\'\)\)\s*exit;)?\s*\/\*\*.*?\*\/\s*\?>\n/s', '', $raw);
    }

    /**
     * Écrit (ou réécrit) le fichier PHP d'un bloc sur le disque.
     */
    private static function write_block_file(string $slug, string $name, string $code): void
    {
        $blocks_dir = self::get_blocks_dir();
        $header = "<?php\nif (!defined('ABSPATH')) exit;\n/**\n * Block Name: " . $name . "\n */\n?>\n";
        file_put_contents($blocks_dir . $slug . '.php', $header . $code);
    }

    /**
     * Migration one-shot : importe en DB les fichiers .php présents dans /blocks/.
     * Ignore les fichiers SiteMaster (marcosado-*).
     */
    private static function migrate_files_to_db(): void
    {
        global $wpdb;
        $blocks_dir = plugin_dir_path(__FILE__) . 'blocks/';
        if (!file_exists($blocks_dir)) return;

        foreach (glob($blocks_dir . '*.php') ?: [] as $file) {
            $slug = basename($file, '.php');

            // Ignorer les blocs SiteMaster intégrés
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

    // ──────────────────────────────────────────────────────────────────────────
    // PARSING DES ATTRIBUTS INLINE ($bm_attributes dans le premier bloc PHP)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Détecte $bm_attributes dans le PREMIER bloc PHP du code du bloc,
     * l'évalue de façon isolée et synchronise la table marcosado_block_attributes (cache DB).
     *
     * Structure attendue dans le code du bloc :
     *
     *   $bm_attributes = [
     *       'titre' => ['type' => 'text', 'label' => 'Titre', 'default' => 'Mon titre'],
     *   ];
     *
     * @return bool  true si des attributs ont été parsés et sauvés, false sinon
     */
    private function sm_sync_attributes_from_code(string $slug, string $code): bool
    {
        global $wpdb;

        $file = self::get_blocks_dir() . $slug . '.php';
        if (!file_exists($file)) {
            return false;
        }

        // Exécuter le fichier PHP dans une fonction tampon pour capturer $bm_attributes
        // et jeter les sorties HTML/textes
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

        // Si le fichier inclus a déclaré la variable $bm_attributes
        if (!isset($bm_attributes) || !is_array($bm_attributes) || empty($bm_attributes)) {
            return false;
        }

        $extracted = $bm_attributes;

        // Synchronisation du cache DB
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

    /**
     * Génère et injecte le bloc $bm_attributes en tête du code affiché dans l'éditeur
     * pour les anciens blocs dont les attributs sont en DB mais pas encore inline.
     */
    private function inject_bm_attributes_from_db(string $slug, string $code): string
    {
        global $wpdb;

        // Si $bm_attributes est déjà présent dans le premier bloc PHP, rien à faire
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

    // ──────────────────────────────────────────────────────────────────────────
    // OPÉRATIONS MÉTIER — BLOCS UTILISATEUR
    // ──────────────────────────────────────────────────────────────────────────

    private function save_block(string $name, string $code): void
    {
        global $wpdb;
        $slug        = sanitize_title($name);
        $table       = $wpdb->prefix . 'marcosado_blocks';
        $table_hist  = $wpdb->prefix . 'marcosado_blocks_history';

        // Archiver la version actuelle
        $current = $wpdb->get_var($wpdb->prepare(
            "SELECT code FROM $table WHERE slug = %s", $slug
        ));
        if ($current !== null) {
            $wpdb->insert($table_hist, [
                'slug'     => $slug,
                'code'     => $current,
                'saved_at' => current_time('mysql'),
            ]);
            // Limiter l'historique à 5 versions
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

        // Upsert en DB
        $wpdb->query($wpdb->prepare(
            "INSERT INTO $table (name, slug, code, updated_at)
             VALUES (%s, %s, %s, %s)
             ON DUPLICATE KEY UPDATE name = VALUES(name), code = VALUES(code), updated_at = VALUES(updated_at)",
            $name, $slug, $code, current_time('mysql')
        ));

        // Écrire le fichier PHP sur le disque
        self::write_block_file($slug, $name, $code);

        // Synchroniser les attributs depuis $bm_attributes (parsing inline)
        $this->sm_sync_attributes_from_code($slug, $code);

        error_log(sprintf(
            'MarcosadoPHPBlockBuilder: Bloc "%s" modifié par l\'utilisateur #%d (%s) le %s',
            $slug,
            get_current_user_id(),
            wp_get_current_user()->user_login,
            current_time('mysql')
        ));
    }

    private function load_block(string $slug): string
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT code FROM {$wpdb->prefix}marcosado_blocks WHERE slug = %s", $slug
        ));
        return $row ? $row->code : '';
    }

    private function load_block_name(string $slug): string
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT name FROM {$wpdb->prefix}marcosado_blocks WHERE slug = %s", $slug
        ));
        return $row ? $row->name : ucwords(str_replace('-', ' ', $slug));
    }

    private function delete_block(string $slug): void
    {
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'marcosado_blocks_history', ['slug' => $slug]);
        $wpdb->delete($wpdb->prefix . 'marcosado_blocks', ['slug' => $slug]);
        $file = self::get_blocks_dir() . $slug . '.php';
        if (file_exists($file)) {
            unlink($file);
        }
    }

    private function regenerate_all_files(): int
    {
        global $wpdb;
        $blocks = $wpdb->get_results("SELECT slug, name, code FROM {$wpdb->prefix}marcosado_blocks");
        foreach ($blocks as $b) {
            self::write_block_file($b->slug, $b->name, $b->code);
        }
        return count($blocks);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // MENU ADMIN
    // ──────────────────────────────────────────────────────────────────────────

    public function create_menu(): void
    {
        // Menu parent
        add_menu_page(
            'Marcosado PHP Block Builder',
            'Marcosado PHP Block Builder',
            'install_plugins',
            'marcosado-php-block-builder',
            [$this, 'admin_page'],
            'dashicons-editor-code'
        );

        // Sous-menu principal (Lab)
        add_submenu_page(
            'marcosado-php-block-builder',
            'Marcosado PHP Block Builder',
            'Blocks Lab',
            'install_plugins',
            'marcosado-php-block-builder',
            [$this, 'admin_page']
        );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // PAGE ADMIN — BLOCKS LAB (existante, inchangée)
    // ──────────────────────────────────────────────────────────────────────────

    public function admin_page(): void
    {
        global $wpdb;

        if (defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT) {
            wp_die('L\'édition de code est désactivée sur ce serveur.');
        }

        // ── Action : Suppression ───────────────────────────────────────────
        if (isset($_GET['delete'])) {
            $delete_slug = sanitize_title(wp_unslash($_GET['delete']));
            if (check_admin_referer('bm_delete_' . $delete_slug)) {
                $this->delete_block($delete_slug);
                echo '<div class="updated"><p>Bloc supprimé.</p></div>';
            }
        }

        // ── Action : Régénération globale ──────────────────────────────────
        if (isset($_GET['regenerate']) && check_admin_referer('bm_regenerate')) {
            $count = $this->regenerate_all_files();
            echo '<div class="updated"><p>' . $count . ' fichier(s) régénéré(s) avec succès.</p></div>';
        }

        // ── Action : Sauvegarde ────────────────────────────────────────────
        if (isset($_POST['save_block']) && check_admin_referer('bm_save')) {
            $name = sanitize_text_field($_POST['block_name']);
            $code = wp_unslash($_POST['block_code']);
            $this->save_block($name, $code);
            echo '<div class="updated"><p>Bloc "' . esc_html($name) . '" enregistré avec succès !</p></div>';
        }

        // ── Chargement du bloc en édition ──────────────────────────────────
        $edit_slug = '';
        $edit_name = '';
        $edit_code = '';

        if (isset($_GET['edit'])) {
            $edit_slug = sanitize_title($_GET['edit']);
            $edit_code = $this->load_block($edit_slug);
            $edit_name = $this->load_block_name($edit_slug);

            // Restauration d'une version historique
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

        // ── Historique du bloc en édition ──────────────────────────────────
        $history = [];
        if ($edit_slug) {
            // Rétrocompat : injecter $bm_attributes si le code ne l'a pas mais la DB en a
            $edit_code = $this->inject_bm_attributes_from_db($edit_slug, $edit_code);

            $history = $wpdb->get_results($wpdb->prepare(
                "SELECT id, saved_at FROM {$wpdb->prefix}marcosado_blocks_history
                 WHERE slug = %s ORDER BY saved_at DESC LIMIT 5",
                $edit_slug
            ));
        }

        // ── Liste de tous les blocs ────────────────────────────────────────
        $all_blocks = $wpdb->get_results(
            "SELECT slug, name FROM {$wpdb->prefix}marcosado_blocks ORDER BY name ASC"
        );

        ?>
        <div class="wrap">
            <h1>Marcosado PHP Block Builder</h1>

            <div class="marcosado-php-block-builder-container">

                <!-- ══ ÉDITEUR ══════════════════════════════════════════════ -->
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
                                Déclarez <code>$bm_attributes</code> dans le <strong>premier bloc PHP</strong> de votre code pour créer automatiquement des champs dans la barre latérale.
                            </p>

                            <details style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 10px; margin-bottom: 5px;">
                                <summary style="font-weight: 600; color: #2271b1; cursor: pointer; user-select: none;">Voir l'exemple et les types autorisés</summary>
                                <div style="margin-top: 10px;">
                                    <pre style="background:#1d2327;color:#f0f0f1;padding:12px;border-radius:4px;font-size:11px;line-height:1.6;margin:0 0 10px;overflow-x:auto;"><?php
                                    $bm_example = '<?php' . "\n"
                                        . '$bm_attributes = [' . "\n"
                                        . "    'titre'   => ['type' => 'text',    'label' => 'Titre',  'default' => 'Mon titre']," . "\n"
                                        . "    'couleur' => ['type' => 'color',   'label' => 'Couleur','default' => '#3B82F6']," . "\n"
                                        . "    'visible' => ['type' => 'boolean', 'label' => 'Bouton', 'default' => 'true']," . "\n"
                                        . "    'photo'   => ['type' => 'image',   'label' => 'Image',  'default' => '']," . "\n"
                                        . "    'choix'   => ['type' => 'select',  'label' => 'Mode',   'default' => 'opt1:Option 1,opt2:Option 2']," . "\n"
                                        . ']' . ";\n"
                                        . '?' . ">\n"
                                        . '<h1 style="color: <?php echo $couleur; ?>"><?php echo esc_html($titre); ?></h1>';
                                    echo esc_html($bm_example);
                                    ?></pre>
                                    <p style="margin:0; font-size:12px; color:#555; line-height: 1.5;">
                                        <strong>Types disponibles :</strong> <code>text</code> · <code>textarea</code> · <code>number</code> · <code>boolean</code> · <code>color</code> · <code>image</code> · <code>select</code>.<br>
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


                <!-- ══ SIDEBAR ═══════════════════════════════════════════════ -->
                <div class="marcosado-block-builder-sidebar">

                    <!-- Liste des blocs -->
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

                    <!-- Bouton régénération globale -->
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

                    <!-- Historique des versions -->
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

                </div><!-- /.marcosado-block-builder-sidebar -->
            </div><!-- /.marcosado-php-block-builder-container -->
        </div><!-- /.wrap -->
        <?php
    }

    // ──────────────────────────────────────────────────────────────────────────
    // ENREGISTREMENT ELEMENTOR
    // ──────────────────────────────────────────────────────────────────────────

    public function register_elementor_category($manager): void {
        $manager->add_category('sitemaster', [
            'title' => 'SiteMaster',
            'icon'  => 'eicon-code',
        ]);
    }

    public function register_elementor_widgets($manager): void {
        if (!did_action('elementor/loaded') || !class_exists('\Elementor\Widget_Base')) return;

        global $wpdb;

        // Recuperer tous les blocs
        $blocks = $wpdb->get_results("SELECT slug, name, code FROM {$wpdb->prefix}marcosado_blocks");
        if (empty($blocks)) return;

        // Recuperer tous les attributs en une requete
        $all_attrs = $wpdb->get_results("SELECT block_slug, field_key, field_label, field_type, field_default, field_section, field_sub_fields FROM {$wpdb->prefix}marcosado_block_attributes ORDER BY sort_order ASC");
        
        $attrs_by_slug = [];
        if ($all_attrs) {
            foreach ($all_attrs as $attr) {
                $attrs_by_slug[$attr->block_slug][] = $attr;
            }
        }

        foreach ($blocks as $bloc) {
            $sm_attributes = $attrs_by_slug[$bloc->slug] ?? [];
            $manager->register(new \Marcosado_Dynamic_Widget([], [
                'bloc' => $bloc,
                'sm_attributes' => $sm_attributes
            ]));
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // ENREGISTREMENT DES BLOCS GUTENBERG
    // ──────────────────────────────────────────────────────────────────────────

    private static function map_field_type(string $type): string {
        return match($type) {
            'number'  => 'number',
            'boolean' => 'boolean',
            'image'   => 'string',
            'repeater'=> 'array',
            default   => 'string',
        };
    }

    private static function cast_default(string $type, string $default): mixed {
        if ($type === 'repeater') return [];
        if ($default === '') return '';
        return match($type) {
            'number'  => (float) $default,
            'boolean' => ($default === 'true' || $default === '1'),
            default   => $default,
        };
    }

    public function register_all_blocks(): void
    {
        global $wpdb;
        $blocks_dir = self::get_blocks_dir();
        $plugin_dir = plugin_dir_path(__FILE__);

        // ── Blocs utilisateur (depuis la DB) ────────────────────────────────
        $blocks = $wpdb->get_results(
            "SELECT slug, name, code FROM {$wpdb->prefix}marcosado_blocks"
        );

        $all_attrs = $wpdb->get_results("SELECT block_slug, field_key, field_label, field_type, field_default, field_section, field_sub_fields FROM {$wpdb->prefix}marcosado_block_attributes ORDER BY sort_order ASC");
        $attrs_by_slug = [];
        if ($all_attrs) {
            foreach ($all_attrs as $attr) {
                $attrs_by_slug[$attr->block_slug][] = $attr;
            }
        }

        foreach ($blocks as $block) {
            $file = $blocks_dir . $block->slug . '.php';

            if (!file_exists($file)) {
                self::write_block_file($block->slug, $block->name, $block->code);
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
                'render_callback' => function ($attributes, $content) use ($file) {
                    $_bm_file_to_include = $file;
                    extract($attributes, EXTR_SKIP);
                    ob_start();
                    if (file_exists($_bm_file_to_include)) {
                        include $_bm_file_to_include;
                    }
                    return ob_get_clean();
                },
                'category' => 'design',
                'title'    => $block->name,
            ]);
        }


    }

    // ──────────────────────────────────────────────────────────────────────────
    // ASSETS ADMIN
    // ──────────────────────────────────────────────────────────────────────────

    public function enqueue_admin_assets(string $hook): void
    {
        // ── Page Blocks Lab ────────────────────────────────────────────────
        if ($hook === 'toplevel_page_marcosado-php-block-builder') {
            $settings = wp_enqueue_code_editor(['type' => 'text/x-php']);

            wp_enqueue_script(
                'marcosado-block-builder-admin-js',
                plugin_dir_url(__FILE__) . 'admin-lab.js',
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


    // -------------------------------------------------------------------------
    // LUCIDE ICONS — chargement local (assets/lucide.min.js)
    // -------------------------------------------------------------------------

    /**
     * Enqueue Lucide Icons et initialise automatiquement les elements [data-lucide].
     *
     * Usage dans les blocs PHP :
     *   <i data-lucide="heart"></i>
     *   <i data-lucide="star" class="w-5 h-5 text-yellow-400"></i>
     *
     * Hooks : wp_footer + admin_footer
     */
    public function load_lucide(): void
    {
        wp_enqueue_script(
            'lucide-icons',
            plugin_dir_url(__FILE__) . 'assets/lucide.min.js',
            [],
            '1.8.0',
            true  // in_footer = true
        );
        // Initialise toutes les icones APRES chargement du script (position 'after')
        wp_add_inline_script(
            'lucide-icons',
            'document.addEventListener("DOMContentLoaded", function() { if (window.lucide) { lucide.createIcons(); } });',
            'after'
        );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // ASSETS ÉDITEUR GUTENBERG
    // ──────────────────────────────────────────────────────────────────────────

    public function enqueue_editor_assets(): void
    {
        global $wpdb;

        $js_path = plugin_dir_url(__FILE__)  . 'editor-blocks.js';
        $js_file = plugin_dir_path(__FILE__) . 'editor-blocks.js';

        wp_enqueue_script(
            'marcosado-block-builder-editor',
            $js_path,
            ['wp-blocks', 'wp-element', 'wp-editor', 'wp-components', 'wp-server-side-render'],
            file_exists($js_file) ? filemtime($js_file) : '3.0'
        );

        // Blocs utilisateur avec attributs (Configuration JS)
        $sm_blocks_config = [];
        $blocks = $wpdb->get_results("SELECT slug, name FROM {$wpdb->prefix}marcosado_blocks");
        
        $all_attrs = $wpdb->get_results("SELECT block_slug, field_key, field_label, field_type, field_default, field_section, field_sub_fields FROM {$wpdb->prefix}marcosado_block_attributes ORDER BY sort_order ASC");
        
        // Grouper les attributs par slug
        $attrs_by_slug = [];
        foreach ($all_attrs as $attr) {
            $attrs_by_slug[$attr->block_slug][] = $attr;
        }

        foreach ($blocks as $b) {
            $sm_blocks_config[$b->slug] = [
                'name'       => 'marcosado-block-builder/' . $b->slug,
                'title'      => $b->name,
                'attributes' => $attrs_by_slug[$b->slug] ?? []
            ];
        }
        
        wp_localize_script('marcosado-block-builder-editor', 'SMBlocksConfig', $sm_blocks_config);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // TAILWIND (front + iframe Gutenberg)
    // ──────────────────────────────────────────────────────────────────────────

    public function load_tailwind(): void
    {
        wp_enqueue_script(
            'marcosado-block-builder-tailwind',
            plugin_dir_url(__FILE__) . 'tailwind.min.js',
            [], '3.4.17', false
        );
        wp_add_inline_script('marcosado-block-builder-tailwind', '
            tailwind.config = {
                prefix: "tw-",
                corePlugins: { preflight: false }
            }
        ');
    }


    // ══════════════════════════════════════════════════════════════════════════
    // PONT BIDIRECTIONNEL GUTENBERG ↔ ELEMENTOR
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Charge depuis la DB tous les attributs groupés par slug.
     * Cache statique : une seule requête par requête HTTP.
     *
     * @return array<string, object[]>  [slug => [field_def, ...]]
     */
    private function bm_get_attrs_map(): array
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

    /**
     * Convertit les attributs Gutenberg en settings Elementor.
     * Booleans : true/false → 'yes'/''
     * Images   : 'https://...' → ['url'=>..., 'id'=>0]
     */
    private function bm_normalize_for_elementor(array $gut_attrs, array $field_defs): array
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
                                $item = $this->bm_normalize_for_elementor($item, $sub_defs);
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

    /**
     * Convertit les settings Elementor en attributs Gutenberg.
     * Booleans : 'yes'/'' → true/false
     * Images   : ['url'=>...] ou ['url'=>'','id'=>''] → string URL
     */
    private function bm_normalize_for_gutenberg(array $el_settings, array $field_defs): array
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
                                // Fallback générique de sécurité avant normalisation
                                foreach ($item as $k => $v) {
                                    if (is_array($v) && isset($v['url'])) {
                                        $item[$k] = $v['url'];
                                    }
                                }
                                $clean_items[] = $this->bm_normalize_for_gutenberg($item, $sub_defs);
                            }
                        }
                    }
                    $result[$key] = $clean_items;
                    break;
                default:
                    $result[$key] = $val !== null ? $val : ($def->field_default ?? '');
                    // Sécurité pour s'assurer qu'aucun tableau n'est renvoyé accidentellement (sauf si c'est normalisé)
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

    /**
     * Parcourt récursivement l'arbre JSON Elementor et retourne
     * toutes les instances de nos widgets sm-* dans leur ordre d'apparition.
     *
     * @return array<string, array[]>  [slug => [settings_instance1, settings_instance2, ...]]
     */
    private function bm_extract_elementor_bm_widgets(array $elements): array
    {
        $widgets = [];
        foreach ($elements as $element) {
            if (
                !empty($element['elType']) && $element['elType'] === 'widget' &&
                !empty($element['widgetType']) && str_starts_with($element['widgetType'], 'sm-')
            ) {
                $slug = substr($element['widgetType'], 3); // retire 'sm-'
                $widgets[$slug][] = $element['settings'] ?? [];
            }
            if (!empty($element['elements']) && is_array($element['elements'])) {
                $children = $this->bm_extract_elementor_bm_widgets($element['elements']);
                foreach ($children as $slug => $instances) {
                    foreach ($instances as $instance) {
                        $widgets[$slug][] = $instance;
                    }
                }
            }
        }
        return $widgets;
    }

    /**
     * Parcourt récursivement l'arbre Elementor et met à jour en place
     * les widgets sm-* avec les attrs Gutenberg, en respectant l'ordre
     * d'apparition (array_shift sur la queue par slug).
     */
    private function bm_update_elementor_widgets_from_gut(array &$elements, array &$gut_queue, array $attrs_map): void
    {
        foreach ($elements as $key => &$element) {
            if (
                !empty($element['elType']) && $element['elType'] === 'widget' &&
                !empty($element['widgetType']) && str_starts_with($element['widgetType'], 'sm-')
            ) {
                $slug = substr($element['widgetType'], 3);
                if (!empty($gut_queue[$slug])) {
                    $attrs = array_shift($gut_queue[$slug]);
                    $element['settings'] = $this->bm_normalize_for_elementor(
                        $attrs,
                        $attrs_map[$slug] ?? []
                    );
                } else {
                    unset($elements[$key]);
                    continue;
                }
            }
            if (!empty($element['elements']) && is_array($element['elements'])) {
                $this->bm_update_elementor_widgets_from_gut($element['elements'], $gut_queue, $attrs_map);
            }
        }
        unset($element);
        $elements = array_values($elements);
    }
    
    /**
     * Retourne une liste plate de tous les widgets Marcosado dans Elementor, en préservant l'ordre.
     */
    private function bm_extract_elementor_bm_widgets_flat(array $elements): array
    {
        $list = [];
        foreach ($elements as $element) {
            if (
                !empty($element['elType']) && $element['elType'] === 'widget' &&
                !empty($element['widgetType']) && str_starts_with($element['widgetType'], 'sm-')
            ) {
                $list[] = [
                    'slug'     => substr($element['widgetType'], 3),
                    'settings' => $element['settings'] ?? []
                ];
            }
            if (!empty($element['elements']) && is_array($element['elements'])) {
                $children = $this->bm_extract_elementor_bm_widgets_flat($element['elements']);
                foreach ($children as $child) {
                    $list[] = $child;
                }
            }
        }
        return $list;
    }

    /**
     * Construit la structure d'un widget Elementor pour un de nos blocs.
     */
    private function bm_build_elementor_widget(string $slug, array $settings): array
    {
        return [
            'id'         => substr(md5(uniqid('bm_' . $slug, true)), 0, 8),
            'elType'     => 'widget',
            'widgetType' => 'sm-' . $slug,
            'settings'   => $settings,
            'elements'   => [],
        ];
    }

    /**
     * Détecte si Elementor >= 3.16 (Containers) ou utilise l'ancien modèle Section+Column.
     */
    private function bm_elementor_uses_containers(): bool
    {
        // Si Elementor n'est pas encore installé, on prépare le terrain pour une version moderne (Containers)
        if (!defined('ELEMENTOR_VERSION')) return true;
        return version_compare(ELEMENTOR_VERSION, '3.16.0', '>=');
    }

    /**
     * Enveloppe un widget dans un conteneur Elementor (Container ou Section+Column selon version).
     */
    private function bm_wrap_in_elementor_container(array $widget): array
    {
        $id1 = substr(md5(uniqid('bm_wrap', true)), 0, 8);
        if ($this->bm_elementor_uses_containers()) {
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

    // ──────────────────────────────────────────────────────────────────────────
    // PONT — Sens 1 : Gutenberg → Elementor
    // Déclenché par save_post (sauvegarde depuis l'éditeur Gutenberg)
    // ──────────────────────────────────────────────────────────────────────────

    public function bm_sync_gutenberg_to_elementor(int $post_id, \WP_Post $post): void
    {
        // Guards
        if (self::$_bm_syncing) return;
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) return;
        
        // Ne pas tourner si c'est Elementor qui déclenche save_post via notre propre hook
        if (defined('ELEMENTOR_SAVE_IN_PROGRESS') && ELEMENTOR_SAVE_IN_PROGRESS) return;

        $blocks = parse_blocks($post->post_content);

        // Construire la queue : [slug => [attrs1, attrs2, ...]] dans l'ordre d'apparition
        $gut_queue = [];
        foreach ($blocks as $block) {
            if (!empty($block['blockName']) && str_starts_with($block['blockName'], 'marcosado-block-builder/')) {
                $slug = substr($block['blockName'], strlen('marcosado-block-builder/'));
                $gut_queue[$slug][] = $block['attrs'] ?? [];
            }
        }

        if (empty($gut_queue)) return;

        $attrs_map      = $this->bm_get_attrs_map();
        $raw            = get_post_meta($post_id, '_elementor_data', true);
        $elementor_data = ($raw && is_string($raw)) ? json_decode($raw, true) : [];
        if (!is_array($elementor_data)) $elementor_data = [];

        // Mettre à jour en place les widgets existants (ordre d'apparition préservé)
        $this->bm_update_elementor_widgets_from_gut($elementor_data, $gut_queue, $attrs_map);

        // Ajouter les nouveaux blocs qui n'avaient pas encore de widget Elementor correspondant
        foreach ($gut_queue as $slug => $remaining) {
            foreach ($remaining as $attrs) {
                $settings = $this->bm_normalize_for_elementor($attrs, $attrs_map[$slug] ?? []);
                $widget   = $this->bm_build_elementor_widget($slug, $settings);
                $elementor_data[] = $this->bm_wrap_in_elementor_container($widget);
            }
        }

        update_post_meta($post_id, '_elementor_data', wp_slash(json_encode($elementor_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
        // On ne force plus le '_elementor_edit_mode' ici. 
        // Si Elementor est installé plus tard, il s'activera tout seul quand l'utilisateur cliquera sur "Modifier avec Elementor".
    }

    // ──────────────────────────────────────────────────────────────────────────
    // PONT — Sens 2 : Elementor → Gutenberg
    // Déclenché par elementor/document/after_save (sauvegarde Elementor)
    // ──────────────────────────────────────────────────────────────────────────

    public function bm_sync_elementor_to_gutenberg($doc, array $data): void
    {
        if (self::$_bm_syncing) return;

        $post_id = $doc->get_main_id();

        $raw            = get_post_meta($post_id, '_elementor_data', true);
        $elementor_data = ($raw && is_string($raw)) ? json_decode($raw, true) : [];
        if (!is_array($elementor_data)) return;

        $flat_list = $this->bm_extract_elementor_bm_widgets_flat($elementor_data);

        $attrs_map = $this->bm_get_attrs_map();
        $new_blocks = [];

        foreach ($flat_list as $item) {
            $slug = $item['slug'];
            $settings = $item['settings'];
            $new_blocks[] = [
                'blockName'    => 'marcosado-block-builder/' . $slug,
                'attrs'        => $this->bm_normalize_for_gutenberg($settings, $attrs_map[$slug] ?? []),
                'innerBlocks'  => [],
                'innerHTML'    => '',
                'innerContent' => [],
            ];
        }

        // Marque pour éviter que save_post ne re-déclenche la synchro El→Gut
        self::$_bm_syncing = true;
        wp_update_post([
            'ID'           => $post_id,
            'post_content' => serialize_blocks($new_blocks),
        ]);
        self::$_bm_syncing = false;
    }
}


new MarcosadoPHPBlockBuilder();

// ──────────────────────────────────────────────────────────────────────────
// ELEMENTOR WIDGET DYNAMIQUE SITEMASTER
// ──────────────────────────────────────────────────────────────────────────

add_action('elementor/init', function() {
    if (!class_exists('\Elementor\Widget_Base')) return;

    class Marcosado_Dynamic_Widget extends \Elementor\Widget_Base {
        private object $bloc;
        private array $sm_attributes;

        public function __construct(array $data = [], ?array $args = null) {
            $this->bloc = $args['bloc'];
            $this->sm_attributes = $args['sm_attributes'] ?? [];
            parent::__construct($data, $args);
        }

        public function get_name():       string { return 'sm-' . $this->bloc->slug; }
        public function get_title():      string { return $this->bloc->name; }
        public function get_icon():       string { return 'eicon-code'; }
        public function get_categories(): array  { return ['sitemaster']; }
        public function is_dynamic_content(): bool { return true; }

        protected function register_controls(): void {
            $sections = [];
            foreach ($this->sm_attributes as $attr) {
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

        /**
         * Parse une chaîne "val1:Label1,val2:Label2" en tableau Elementor options.
         * Si le séparateur ':' est absent, la valeur sert aussi de label.
         */
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

            // Mapper les types Elementor vers les types attendus par le bloc (booleans, images)
            foreach ($this->sm_attributes as $attr) {
                if ($attr->field_type === 'boolean') {
                    $attributes[$attr->field_key] = ($attributes[$attr->field_key] ?? '') === 'yes';
                } elseif ($attr->field_type === 'image') {
                    $media_val = $attributes[$attr->field_key] ?? [];
                    // En Elementor, un champ media est un tableau. On extrait l'URL pour correspondre à Gutenberg.
                    $attributes[$attr->field_key] = is_array($media_val) && isset($media_val['url']) ? $media_val['url'] : '';
                } elseif ($attr->field_type === 'repeater') {
                    $items = $attributes[$attr->field_key] ?? [];
                    if (is_array($items)) {
                        $sub_fields_def = !empty($attr->field_sub_fields) ? json_decode($attr->field_sub_fields, true) : [];
                        if (!is_array($sub_fields_def)) $sub_fields_def = [];

                        foreach ($items as &$item) {
                            // 1. Fallback générique : si une valeur est un tableau avec 'url', on l'extrait
                            foreach ($item as $k => $v) {
                                if (is_array($v) && isset($v['url'])) {
                                    $item[$k] = $v['url'];
                                }
                            }
                            // 2. Traitement spécifique basé sur les sous-champs
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

            // Utiliser include (comme Gutenberg) — propre, scope correct, pas d'eval()
            $file = MarcosadoPHPBlockBuilder::get_blocks_dir() . $this->bloc->slug . '.php';

            // Sécurité : recréer le fichier depuis la DB s'il a été supprimé
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
                // MEDIA control requiert un tableau, jamais une string
                'image'   => ['url' => $default, 'id' => ''],
                // SELECT : le default est la 1ère valeur de la liste d'options
                // (géré directement dans register_controls via parse_select_options)
                'select'  => '',
                default   => $default,
            };
        }
    }
});

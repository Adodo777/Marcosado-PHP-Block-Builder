<?php
/**
 * Plugin Name: Marcosado PHP Block Builder
 * Description: Create and manage custom Gutenberg blocks using PHP, Tailwind CSS, and dynamic attributes, with Elementor support.
 * Version: 1.2
 * Author: marcosado
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) exit;

// ─────────────────────────────────────────────────────────────────────────────
// Constantes du plugin
// ─────────────────────────────────────────────────────────────────────────────
define('MARCOSADO_PLUGIN_FILE', __FILE__);
define('MARCOSADO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MARCOSADO_PLUGIN_URL', plugin_dir_url(__FILE__));

// Initialisation différée des dossiers upload (wp_upload_dir ne doit pas être appelé trop tôt)
add_action('plugins_loaded', function() {
    $upload_dir = wp_upload_dir();
    $dir = $upload_dir['basedir'] . '/marcosado-php-block-builder/';
    if (!file_exists($dir)) {
        wp_mkdir_p($dir);
        file_put_contents($dir . 'index.php', '<?php // Silence is golden');
        file_put_contents($dir . '.htaccess', "<Files *.php>\n    deny from all\n</Files>");
    }
    define('MARCOSADO_BLOCKS_DIR', $dir);
}, 5);

// ─────────────────────────────────────────────────────────────────────────────
// Chargement des classes
// ─────────────────────────────────────────────────────────────────────────────
require_once MARCOSADO_PLUGIN_DIR . 'includes/class-db.php';
require_once MARCOSADO_PLUGIN_DIR . 'includes/class-admin.php';
require_once MARCOSADO_PLUGIN_DIR . 'includes/class-parser.php';
require_once MARCOSADO_PLUGIN_DIR . 'includes/class-gutenberg.php';
require_once MARCOSADO_PLUGIN_DIR . 'includes/class-elementor.php';
require_once MARCOSADO_PLUGIN_DIR . 'includes/class-sync.php';

// ─────────────────────────────────────────────────────────────────────────────
// Initialisation
// ─────────────────────────────────────────────────────────────────────────────
Marcosado_DB::init();
Marcosado_Admin::init();
Marcosado_Gutenberg::init();
Marcosado_Elementor::init();
Marcosado_Sync::init();

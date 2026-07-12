<?php
/**
 * Plugin Name: Marcosado PHP Block Builder
 * Description: Create and manage custom Gutenberg blocks using PHP, Tailwind CSS, and dynamic attributes, with Elementor support.
 * Version: 1.0.0
 * Requires at least: 5.9
 * Requires PHP: 7.4
 * Author: marcosado
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace Marcosado\BlockBuilder;

if (!defined('ABSPATH')) exit;

// ─────────────────────────────────────────────────────────────────────────────
// Constantes du plugin
// ─────────────────────────────────────────────────────────────────────────────
define('MARCOSADO_PLUGIN_FILE', __FILE__);
define('MARCOSADO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MARCOSADO_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MARCOSADO_VERSION', '1.0.0');


// ─────────────────────────────────────────────────────────────────────────────
// Chargement des classes
// ─────────────────────────────────────────────────────────────────────────────
require_once MARCOSADO_PLUGIN_DIR . 'includes/polyfill.php';
require_once MARCOSADO_PLUGIN_DIR . 'includes/class-db.php';
require_once MARCOSADO_PLUGIN_DIR . 'includes/class-admin.php';
require_once MARCOSADO_PLUGIN_DIR . 'includes/class-parser.php';
require_once MARCOSADO_PLUGIN_DIR . 'includes/class-gutenberg.php';
require_once MARCOSADO_PLUGIN_DIR . 'includes/class-elementor.php';
require_once MARCOSADO_PLUGIN_DIR . 'includes/class-sync.php';
require_once MARCOSADO_PLUGIN_DIR . 'includes/class-security.php';
require_once MARCOSADO_PLUGIN_DIR . 'includes/class-stream-wrapper.php';

function activate_plugin() {
    Marcosado_DB::activate();
}

register_activation_hook(__FILE__, __NAMESPACE__ . '\\activate_plugin');

class MarcosadoPHPBlockBuilder {
    public static function init() {
        if (!in_array('bmcode', stream_get_wrappers(), true)) {
            stream_wrapper_register('bmcode', __NAMESPACE__ . '\\Marcosado_Stream_Wrapper');
        }

        Marcosado_DB::init();
        Marcosado_Admin::init();
        Marcosado_Gutenberg::init();
        
        if (did_action('elementor/loaded') || class_exists('\Elementor\Plugin')) {
            Marcosado_Elementor::init();
        }

        Marcosado_Sync::init();
    }
}

add_action('plugins_loaded', [MarcosadoPHPBlockBuilder::class, 'init']);

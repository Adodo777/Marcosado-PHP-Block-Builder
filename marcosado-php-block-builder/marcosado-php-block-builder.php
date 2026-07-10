<?php
/**
 * Plugin Name: Marcosado PHP Block Builder
 * Description: Create and manage custom Gutenberg blocks using PHP, Tailwind CSS, and dynamic attributes, with Elementor support.
 * Version: 1.0.0
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
require_once MARCOSADO_PLUGIN_DIR . 'includes/class-db.php';
require_once MARCOSADO_PLUGIN_DIR . 'includes/class-admin.php';
require_once MARCOSADO_PLUGIN_DIR . 'includes/class-parser.php';
require_once MARCOSADO_PLUGIN_DIR . 'includes/class-gutenberg.php';
require_once MARCOSADO_PLUGIN_DIR . 'includes/class-elementor.php';
require_once MARCOSADO_PLUGIN_DIR . 'includes/class-sync.php';
require_once MARCOSADO_PLUGIN_DIR . 'includes/class-security.php';
require_once MARCOSADO_PLUGIN_DIR . 'includes/class-stream-wrapper.php';

stream_wrapper_register("bmcode", "\Marcosado\BlockBuilder\Marcosado_Stream_Wrapper");

class MarcosadoPHPBlockBuilder {
    public static function init() {
        Marcosado_DB::init();
        Marcosado_Admin::init();
        Marcosado_Gutenberg::init();
        Marcosado_Elementor::init();
        Marcosado_Sync::init();
    }
}

MarcosadoPHPBlockBuilder::init();

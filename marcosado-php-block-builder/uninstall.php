<?php
/**
 * Blocks Master Lab — Uninstall
 *
 * Exécuté par WordPress lors de la SUPPRESSION du plugin (pas la désactivation).
 * Supprime toutes les tables du Blocks Master Lab et les fichiers générés.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// ── Tables Marcosado PHP Block Builder ───────────────────────────────────────────
// Par sécurité, nous conservons les blocs et attributs de l'utilisateur.
// Nous ne supprimons que l'historique pour nettoyer la base.
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}marcosado_blocks_history");

// ── Supprimer tous les fichiers PHP générés dans /uploads/ ─────────────────
$upload_dir = wp_upload_dir();
$blocks_dir = $upload_dir['basedir'] . '/marcosado-php-block-builder/';
if (is_dir($blocks_dir)) {
    foreach (glob($blocks_dir . '*.php') ?: [] as $file) {
        unlink($file);
    }
    // Supprimer aussi index.php et .htaccess
    @unlink($blocks_dir . 'index.php');
    @unlink($blocks_dir . '.htaccess');
    // Supprimer le dossier
    @rmdir($blocks_dir);
}

<?php
/**
 * Blocks Master Lab — Uninstall
 *
 * Exécuté par WordPress lors de la SUPPRESSION du plugin (pas la désactivation).
 * Supprime toutes les tables du Blocks Master Lab et les fichiers générés.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Par sécurité, nous ne supprimons que l'historique pour nettoyer la base.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}marcosado_blocks_history" );

// Supprimer le dossier d'uploads s'il existait dans les anciennes versions
$marcosado_upload_dir = wp_upload_dir();
$marcosado_blocks_dir = $marcosado_upload_dir['basedir'] . '/marcosado-php-block-builder/';

global $wp_filesystem;
if ( empty( $wp_filesystem ) ) {
    require_once ABSPATH . '/wp-admin/includes/file.php';
    WP_Filesystem();
}
if ( $wp_filesystem && is_dir( $marcosado_blocks_dir ) ) {
    $wp_filesystem->delete( $marcosado_blocks_dir, true );
}

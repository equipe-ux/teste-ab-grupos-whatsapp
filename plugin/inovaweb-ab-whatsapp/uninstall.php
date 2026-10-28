<?php
/**
 * Ao excluir o plugin (Plugins → Excluir): apaga as tabelas e a opção. Só toca no que é do iwab_;
 * nada mais.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;
foreach (['people', 'groups', 'versions', 'tests'] as $table) {
    $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'iwab_' . $table);
}
delete_option('iwab_db_version');
delete_option('iwab_api_key');

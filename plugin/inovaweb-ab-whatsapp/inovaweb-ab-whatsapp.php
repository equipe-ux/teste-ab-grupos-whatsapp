<?php
/**
 * Plugin Name: INOVAWEB Teste A/B WhatsApp
 * Description: Teste A/B de grupos de WhatsApp com um link só: distribui as pessoas entre os grupos e mantém cada IP sempre no mesmo grupo, sem expor os links dos outros.
 * Version:     0.2.1
 * Requires at least: 5.9
 * Requires PHP: 7.4
 * Author:      Inova Web Design
 * Author URI:  https://inovawebdesign.com.br
 * License:     GPLv2 or later
 * Text Domain: inovaweb-ab-whatsapp
 */

if (!defined('ABSPATH')) {
    exit;
}

define('IWAB_VERSION', '0.2.1');
define('IWAB_FILE', __FILE__);
define('IWAB_DIR', plugin_dir_path(__FILE__));
define('IWAB_URL', plugin_dir_url(__FILE__));

require_once IWAB_DIR . 'includes/class-iwab-db.php';
require_once IWAB_DIR . 'includes/class-iwab-ip.php';
require_once IWAB_DIR . 'includes/class-iwab-tests.php';
require_once IWAB_DIR . 'includes/class-iwab-router.php';
require_once IWAB_DIR . 'includes/class-iwab-api.php';
require_once IWAB_DIR . 'includes/class-iwab-admin.php';

register_activation_hook(__FILE__, ['IWAB_DB', 'install']);

add_action('plugins_loaded', ['IWAB_DB', 'maybe_upgrade'], 1);
// Antes do tema e do init: o link responde rápido e não depende de nada do site.
add_action('plugins_loaded', ['IWAB_Router', 'maybe_handle'], 2);
IWAB_API::boot();

if (is_admin()) {
    IWAB_Admin::boot();
}

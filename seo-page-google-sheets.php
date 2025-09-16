<?php
/**
 * Plugin Name: Sheets to Pages
 * Description: Import data from Google Sheets to create pages with Gutenberg blocks using ACF PRO.
 * Version: 1.2
 * Author: Artilab
 * License: GPL2
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

define('ARTI_SEO_PAGE_PLUGIN', __FILE__);
define('ARTI_SEO_PAGE_PLUGIN_PATH', plugin_dir_path(__FILE__));

// Path to Composer autoloader
require_once 'googlesheets/vendor/autoload.php';

function arti_seo_page_init() {
    
    require_once ARTI_SEO_PAGE_PLUGIN_PATH . 'inc/register-menu.php';
    require_once ARTI_SEO_PAGE_PLUGIN_PATH . 'inc/display-results.php';
    require_once ARTI_SEO_PAGE_PLUGIN_PATH . 'inc/import-sheet-data.php';
    require_once ARTI_SEO_PAGE_PLUGIN_PATH . 'inc/create-pages.php';
    
}
add_action('plugins_loaded', 'arti_seo_page_init');
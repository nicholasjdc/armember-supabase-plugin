<?php
/**
 * Plugin Name: Supabase ARMember Sync
 * Description: Syncs ARMember users with Supabase and displays member-only data
 * Version: 1.0.0
 * Author: Your Name
 * Text Domain: supabase-armember-sync
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('SUPABASE_ARMEMBER_VERSION', '1.0.0');
define('SUPABASE_ARMEMBER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SUPABASE_ARMEMBER_PLUGIN_URL', plugin_dir_url(__FILE__));

// Require dependencies
require_once SUPABASE_ARMEMBER_PLUGIN_DIR . 'includes/class-supabase-client.php';
require_once SUPABASE_ARMEMBER_PLUGIN_DIR . 'includes/class-sync-handler.php';
require_once SUPABASE_ARMEMBER_PLUGIN_DIR . 'includes/class-data-display.php';
require_once SUPABASE_ARMEMBER_PLUGIN_DIR . 'includes/class-library-manager.php';
require_once SUPABASE_ARMEMBER_PLUGIN_DIR . 'includes/class-library-display.php';
require_once SUPABASE_ARMEMBER_PLUGIN_DIR . 'includes/class-librarian-display.php';
require_once SUPABASE_ARMEMBER_PLUGIN_DIR . 'admin/class-admin-page.php';

/**
 * Initialize the plugin
 */
function supabase_armember_init() {
    // Initialize sync handler (hooks into ARMember events)
    new Supabase_Sync_Handler();

    // Initialize data display (shortcodes)
    new Supabase_Data_Display();

    // Initialize library display (library catalog shortcode)
    new Supabase_Library_Display();

    // Initialize librarian display (librarian CRUD interface)
    new Supabase_Librarian_Display();

    // Initialize unified admin page (only in admin)
    if (is_admin()) {
        new Supabase_Admin_Page();
    }
}
add_action('plugins_loaded', 'supabase_armember_init');

/**
 * Activation hook - run on plugin activation
 */
function supabase_armember_activate() {
    // Set default options
    if (!get_option('supabase_project_url')) {
        add_option('supabase_project_url', '');
    }
    if (!get_option('supabase_service_key')) {
        add_option('supabase_service_key', '');
    }
    if (!get_option('supabase_access_plans')) {
        add_option('supabase_access_plans', 'Plan (Paid),dual Plan');
    }

    // Flush rewrite rules
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'supabase_armember_activate');

/**
 * Deactivation hook
 */
function supabase_armember_deactivate() {
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'supabase_armember_deactivate');

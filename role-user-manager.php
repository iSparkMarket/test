<?php
declare(strict_types=1);
/**
 * Plugin Name: Role User Manager
 * Plugin URI: https://example.com/role-user-manager
 * Description: Adds custom user roles and restricts access to user data by program/site with comprehensive audit logging.
 * Version: 2.0.0
 * Author: Neo Kun
 * Text Domain: role-user-manager
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * 
 * PHP Compatibility: This plugin is compatible with PHP 7.4+ and PHP 8.x
 * Uses conditional code to provide optimal features for each PHP version.
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

// --- Robust error handling for required files ---
function rum_require_file(string $file): void {
    if (file_exists($file)) {
        require_once $file;
    } else {
        error_log('Role User Manager: Required file missing: ' . $file);
    }
}

// --- Include the functionality files with error handling ---
rum_require_file(plugin_dir_path(__FILE__) . 'includes/csv-uploader.php');
rum_require_file(plugin_dir_path(__FILE__) . 'includes/role-manager.php');
rum_require_file(plugin_dir_path(__FILE__) . 'includes/dashboard.php');
rum_require_file(plugin_dir_path(__FILE__) . 'includes/user-meta.php');
rum_require_file(plugin_dir_path(__FILE__) . 'includes/role-workflow.php');

// --- Plugin activation hook ---
register_activation_hook(__FILE__, 'rum_plugin_activate');
function rum_plugin_activate(): void {
    // Create necessary options, flush rewrite rules, etc.
    if (!get_option('arc_role_hierarchy')) {
        add_option('arc_role_hierarchy', []);
    }
    
    // Create database tables for role workflow
    global $wpdb;
    $table_name = $wpdb->prefix . 'role_promotion_requests';
    
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        requester_id bigint(20) NOT NULL,
        user_id bigint(20) NOT NULL,
        `current_role` varchar(50) NOT NULL,
        `requested_role` varchar(50) NOT NULL,
        reason text,
        status varchar(20) DEFAULT 'pending',
        admin_notes text,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY requester_id (requester_id),
        KEY user_id (user_id),
        KEY status (status)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    
    flush_rewrite_rules();
}

// --- Plugin deactivation hook ---
register_deactivation_hook(__FILE__, 'rum_plugin_deactivate');
function rum_plugin_deactivate(): void {
    // Clean up transients, flush rewrite rules, etc.
    delete_transient('arc_all_capabilities');
    flush_rewrite_rules();
}

// --- Add settings link to plugin list ---
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'rum_plugin_action_links');
function rum_plugin_action_links(array $links): array {
    $settings_link = '<a href="' . admin_url('users.php?page=role-capabilities') . '">' . __('Settings', 'role-user-manager') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}

// --- Load plugin textdomain on init ---
add_action('init', function() {
    load_plugin_textdomain('role-user-manager', false, dirname(plugin_basename(__FILE__)) . '/languages');
    
    // Ensure tables exist (fallback for cases where activation didn't work)
    rum_create_tables_manually();
});

add_action('admin_enqueue_scripts', 'arc_enqueue_admin_assets');

function arc_enqueue_admin_assets(string $hook): void {
    // List of plugin page slugs where you want to load assets
    $allowed_pages = [
        'upload-csv',
        'role-capabilities',
    ];

    if (!in_array($hook, $allowed_pages, true)) {
        return; // Don't enqueue outside our plugin pages
    }

    $plugin_url = plugin_dir_url(__FILE__) . 'assets/';

    // Enqueue CSS
    wp_enqueue_style('arc-admin-css', $plugin_url . 'admin.css', [], '1.0');
    wp_enqueue_style('csv-css', $plugin_url . 'csv-style.css', [], '1.0');
    wp_enqueue_style('Role-manager-css', $plugin_url . 'role-manager-style.css', [], '1.0');

    // Enqueue JS
    wp_enqueue_script('arc-admin-custom-js', $plugin_url . 'custom.js', ['jquery'], '1.0', true);


}
add_action('admin_enqueue_scripts', 'cram_enqueue_csv_assets');

function cram_enqueue_csv_assets(): void {
    // Get plugin URL
    $plugin_url = plugin_dir_url(__FILE__);
    $plugin_path = plugin_dir_path(__FILE__);
    // Enqueue CSS file with correct versioning
    wp_enqueue_style('cram-csv-style', $plugin_url . 'assets/css/csv-style.css', [], filemtime($plugin_path . 'assets/css/csv-style.css'));
    wp_enqueue_style('cram-role-manager', $plugin_url . 'assets/css/role-manager-style.css', [], filemtime($plugin_path . 'assets/css/role-manager-style.css'));
}

// --- Multisite support: show a notice if running in multisite ---
add_action('admin_notices', function(): void {
    if (is_multisite() && function_exists('is_plugin_active_for_network') && is_plugin_active_for_network(plugin_basename(__FILE__))) {
        echo '<div class="notice notice-info"><p>' . __('Role User Manager is running in a WordPress Multisite network. All roles, capabilities, and program/site mappings are managed per site. For network-wide management, contact the plugin author.', 'role-user-manager') . '</p></div>';
    }
    
    // Check if tables exist and show notice if they don't
    global $wpdb;
    $table_name = $wpdb->prefix . 'role_promotion_requests';
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
    
    if (!$table_exists && current_user_can('manage_options')) {
        echo '<div class="notice notice-warning"><p>' . 
             __('Role User Manager: Database tables are missing. ', 'role-user-manager') .
             '<a href="' . admin_url('admin-post.php?action=create_role_tables') . '">' . 
             __('Click here to create them now', 'role-user-manager') . '</a>.</p></div>';
    }
});

// All options and user meta are site-specific by default in WordPress multisite. No changes needed unless network-wide management is required.

// Function to manually create tables (can be called without reinstalling)
function rum_create_tables_manually(): void {
    global $wpdb;
    $table_name = $wpdb->prefix . 'role_promotion_requests';
    
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        requester_id bigint(20) NOT NULL,
        user_id bigint(20) NOT NULL,
        `current_role` varchar(50) NOT NULL,
        `requested_role` varchar(50) NOT NULL,
        reason text,
        status varchar(20) DEFAULT 'pending',
        admin_notes text,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY requester_id (requester_id),
        KEY user_id (user_id),
        KEY status (status)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    
    // Log the table creation
    error_log('Role User Manager: Tables created/verified successfully');
}

// Add admin action to create tables manually
add_action('admin_post_create_role_tables', 'rum_create_tables_manually');

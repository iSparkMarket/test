<?php
declare(strict_types=1);

namespace RoleUserManager;

/**
 * Assets management class
 */
class Assets {
    
    /**
     * Enqueue admin assets
     */
    public static function enqueue_admin_assets(string $hook): void {
        $plugin_url = RUM_PLUGIN_URL . 'assets/';
        $plugin_path = RUM_PLUGIN_DIR . 'assets/';
        
        // Enqueue CSS files with versioning
        wp_enqueue_style(
            'rum-admin-css', 
            $plugin_url . 'css/admin.css', 
            [], 
            filemtime($plugin_path . 'css/admin.css')
        );
        
        wp_enqueue_style(
            'rum-csv-css', 
            $plugin_url . 'css/csv-style.css', 
            [], 
            filemtime($plugin_path . 'css/csv-style.css')
        );
        
        wp_enqueue_style(
            'rum-role-manager-css', 
            $plugin_url . 'css/role-manager-style.css', 
            [], 
            filemtime($plugin_path . 'css/role-manager-style.css')
        );
        
        // Enqueue JavaScript files
        wp_enqueue_script(
            'rum-admin-js', 
            $plugin_url . 'js/admin.js', 
            ['jquery'], 
            filemtime($plugin_path . 'js/admin.js'), 
            true
        );
        
        wp_enqueue_script(
            'rum-workflow-admin-js', 
            $plugin_url . 'js/workflow-admin.js', 
            ['jquery'], 
            filemtime($plugin_path . 'js/workflow-admin.js'), 
            true
        );
        
        // Localize scripts
        wp_localize_script('rum-admin-js', 'rum_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('rum_nonce'),
            'admin_url' => admin_url(),
        ]);
        
        wp_localize_script('rum-workflow-admin-js', 'workflow_vars', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('workflow_nonce'),
        ]);
    }
    
    /**
     * Enqueue frontend assets
     */
    public static function enqueue_frontend_assets(): void {
        $plugin_url = RUM_PLUGIN_URL . 'assets/';
        $plugin_path = RUM_PLUGIN_DIR . 'assets/';
        
        // Enqueue Bootstrap
        wp_enqueue_style(
            'bootstrap-css', 
            'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css'
        );
        
        wp_enqueue_script(
            'bootstrap-js', 
            'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js', 
            ['jquery'], 
            null, 
            true
        );
        
        // Enqueue dashboard CSS
        wp_enqueue_style(
            'rum-dashboard-css', 
            $plugin_url . 'css/admin.css', 
            [], 
            filemtime($plugin_path . 'css/admin.css')
        );
        
        // Enqueue dashboard JS
        wp_enqueue_script(
            'rum-dashboard-js', 
            $plugin_url . 'js/admin.js', 
            ['jquery'], 
            filemtime($plugin_path . 'js/admin.js'), 
            true
        );
        
        // Localize dashboard script
        wp_localize_script('rum-dashboard-js', 'rum_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('dashboard_nonce'),
            'admin_url' => admin_url(),
        ]);
        
        // Additional localization for compatibility with old dashboard
        wp_localize_script('jquery', 'arc_dashboard_vars', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('dashboard_nonce')
        ]);
        
        wp_localize_script('rum-dashboard-js', 'dashboard_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('dashboard_nonce'),
            'admin_url' => admin_url(),
        ]);
    }
} 
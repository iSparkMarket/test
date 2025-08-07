<?php
declare(strict_types=1);

namespace RoleUserManager;

/**
 * Admin class
 */
class Admin {
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->init_hooks();
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks(): void {
        add_action('admin_init', [$this, 'init']);
        add_action('admin_notices', [$this, 'admin_notices']);
    }
    
    /**
     * Initialize admin
     */
    public function init(): void {
        // Initialize AJAX handlers
        Ajax::init();
    }
    
    /**
     * Show admin notices
     */
    public function admin_notices(): void {
        // Show success/error messages
        if (isset($_GET['message'])) {
            $message = sanitize_text_field($_GET['message']);
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html($message)
            );
        }
        
        if (isset($_GET['error'])) {
            $error = sanitize_text_field($_GET['error']);
            printf(
                '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
                esc_html($error)
            );
        }
    }
} 
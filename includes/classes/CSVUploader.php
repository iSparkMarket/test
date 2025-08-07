<?php
declare(strict_types=1);

namespace RoleUserManager;

/**
 * CSV Uploader class
 */
class CSVUploader {
    
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
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        
        // Form processing
        add_action('admin_post_upload_csv', [$this, 'process_csv_upload']);
        add_action('admin_post_add_program_site', [$this, 'add_program_site']);
        add_action('admin_post_edit_program_site', [$this, 'edit_program_site']);
        add_action('admin_post_remove_program_site', [$this, 'remove_program_site']);
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu(): void {
        add_submenu_page(
            'role-capabilities',
            __('Upload CSV', 'role-user-manager'),
            __('Upload CSV', 'role-user-manager'),
            'manage_options',
            'upload-csv',
            [$this, 'admin_page']
        );
    }
    
    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts(string $hook): void {
        if ($hook !== 'role-capabilities_page_upload-csv') {
            return;
        }
        
        Assets::enqueue_admin_assets($hook);
    }
    
    /**
     * Admin page
     */
    public function admin_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }
        
        $program_sites = $this->get_program_sites();
        
        include RUM_PLUGIN_DIR . 'templates/csv-uploader.php';
    }
    
    /**
     * Process CSV upload
     */
    public function process_csv_upload(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to perform this action.'));
        }
        
        if (!wp_verify_nonce($_POST['csv_upload_nonce'], 'csv_upload_action')) {
            wp_die(__('Security check failed.'));
        }
        
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            wp_redirect(admin_url('admin.php?page=upload-csv&error=upload_failed'));
            exit;
        }
        
        $file = $_FILES['csv_file'];
        $errors = Validator::validate_file_upload($file);
        
        if (!empty($errors)) {
            wp_redirect(admin_url('admin.php?page=upload-csv&error=' . urlencode(implode(', ', $errors))));
            exit;
        }
        
        $data = $this->parse_csv_file($file['tmp_name']);
        if (empty($data)) {
            wp_redirect(admin_url('admin.php?page=upload-csv&error=invalid_csv'));
            exit;
        }
        
        $imported = 0;
        $errors = [];
        
        foreach ($data as $row) {
            $validation_errors = Validator::validate_csv_data($row);
            if (!empty($validation_errors)) {
                $errors[] = 'Row ' . ($imported + 1) . ': ' . implode(', ', $validation_errors);
                continue;
            }
            
            if ($this->add_program_site_data($row['program'], $row['site'])) {
                $imported++;
            } else {
                $errors[] = 'Row ' . ($imported + 1) . ': Failed to import';
            }
        }
        
        $message = "Imported {$imported} records successfully.";
        if (!empty($errors)) {
            $message .= ' Errors: ' . implode('; ', $errors);
        }
        
        Logger::log("CSV import completed: {$imported} records imported");
        
        wp_redirect(admin_url('admin.php?page=upload-csv&message=' . urlencode($message)));
        exit;
    }
    
    /**
     * Parse CSV file
     */
    private function parse_csv_file(string $file_path): array {
        $data = [];
        
        if (($handle = fopen($file_path, 'r')) === false) {
            return $data;
        }
        
        // Skip header row
        fgetcsv($handle);
        
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) >= 2) {
                $data[] = [
                    'program' => trim($row[0]),
                    'site' => trim($row[1]),
                ];
            }
        }
        
        fclose($handle);
        return $data;
    }
    
    /**
     * Add program site data
     */
    public function add_program_site(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to perform this action.'));
        }
        
        if (!wp_verify_nonce($_POST['add_program_site_nonce'], 'add_program_site_action')) {
            wp_die(__('Security check failed.'));
        }
        
        $program = Validator::sanitize_text($_POST['program'] ?? '');
        $site = Validator::sanitize_text($_POST['site'] ?? '');
        
        if (empty($program) || empty($site)) {
            wp_redirect(admin_url('admin.php?page=upload-csv&error=missing_fields'));
            exit;
        }
        
        if ($this->add_program_site_data($program, $site)) {
            Logger::log("Program site added: {$program} - {$site}");
            wp_redirect(admin_url('admin.php?page=upload-csv&message=Program site added successfully'));
        } else {
            wp_redirect(admin_url('admin.php?page=upload-csv&error=add_failed'));
        }
        exit;
    }
    
    /**
     * Add program site data to database
     */
    private function add_program_site_data(string $program, string $site): bool {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'rum_program_sites';
        
        $result = $wpdb->insert(
            $table_name,
            [
                'program' => $program,
                'site' => $site,
                'created_at' => current_time('mysql'),
            ],
            ['%s', '%s', '%s']
        );
        
        return $result !== false;
    }
    
    /**
     * Edit program site
     */
    public function edit_program_site(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to perform this action.'));
        }
        
        if (!wp_verify_nonce($_POST['edit_program_site_nonce'], 'edit_program_site_action')) {
            wp_die(__('Security check failed.'));
        }
        
        $id = intval($_POST['id'] ?? 0);
        $program = Validator::sanitize_text($_POST['program'] ?? '');
        $site = Validator::sanitize_text($_POST['site'] ?? '');
        
        if ($id <= 0 || empty($program) || empty($site)) {
            wp_redirect(admin_url('admin.php?page=upload-csv&error=invalid_data'));
            exit;
        }
        
        if ($this->update_program_site_data($id, $program, $site)) {
            Logger::log("Program site updated: ID {$id} - {$program} - {$site}");
            wp_redirect(admin_url('admin.php?page=upload-csv&message=Program site updated successfully'));
        } else {
            wp_redirect(admin_url('admin.php?page=upload-csv&error=update_failed'));
        }
        exit;
    }
    
    /**
     * Update program site data
     */
    private function update_program_site_data(int $id, string $program, string $site): bool {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'rum_program_sites';
        
        $result = $wpdb->update(
            $table_name,
            [
                'program' => $program,
                'site' => $site,
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $id],
            ['%s', '%s', '%s'],
            ['%d']
        );
        
        return $result !== false;
    }
    
    /**
     * Remove program site
     */
    public function remove_program_site(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to perform this action.'));
        }
        
        if (!wp_verify_nonce($_POST['remove_program_site_nonce'], 'remove_program_site_action')) {
            wp_die(__('Security check failed.'));
        }
        
        $id = intval($_POST['id'] ?? 0);
        
        if ($id <= 0) {
            wp_redirect(admin_url('admin.php?page=upload-csv&error=invalid_id'));
            exit;
        }
        
        if ($this->delete_program_site_data($id)) {
            Logger::log("Program site removed: ID {$id}");
            wp_redirect(admin_url('admin.php?page=upload-csv&message=Program site removed successfully'));
        } else {
            wp_redirect(admin_url('admin.php?page=upload-csv&error=delete_failed'));
        }
        exit;
    }
    
    /**
     * Delete program site data
     */
    private function delete_program_site_data(int $id): bool {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'rum_program_sites';
        
        $result = $wpdb->delete(
            $table_name,
            ['id' => $id],
            ['%d']
        );
        
        return $result !== false;
    }
    
    /**
     * Get program sites
     */
    private function get_program_sites(): array {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'rum_program_sites';
        
        return $wpdb->get_results(
            "SELECT * FROM {$table_name} ORDER BY program, site",
            ARRAY_A
        );
    }
    
    /**
     * Export program sites to CSV
     */
    public function export_program_sites_csv(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to perform this action.'));
        }
        
        $program_sites = $this->get_program_sites();
        
        $filename = 'program_sites_' . date('Y-m-d_H-i-s') . '.csv';
        
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        $output = fopen('php://output', 'w');
        
        // Header row
        fputcsv($output, ['Program', 'Site']);
        
        // Data rows
        foreach ($program_sites as $row) {
            fputcsv($output, [$row['program'], $row['site']]);
        }
        
        fclose($output);
        exit;
    }
} 
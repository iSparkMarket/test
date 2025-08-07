<?php
declare(strict_types=1);

namespace RoleUserManager;

/**
 * AJAX handler class
 */
class Ajax {
    
    /**
     * Initialize AJAX hooks
     */
    public static function init(): void {
        // Role management AJAX
        add_action('wp_ajax_rum_update_role_capabilities', [self::class, 'update_role_capabilities']);
        add_action('wp_ajax_rum_create_role', [self::class, 'create_role']);
        add_action('wp_ajax_rum_delete_role', [self::class, 'delete_role']);
        add_action('wp_ajax_rum_get_role_capabilities', [self::class, 'get_role_capabilities']);
        add_action('wp_ajax_rum_inherit_parent_capabilities', [self::class, 'inherit_parent_capabilities']);
        add_action('wp_ajax_rum_get_parent_options', [self::class, 'get_parent_options']);
        
        // Dashboard AJAX
        add_action('wp_ajax_rum_get_user_details', [self::class, 'get_user_details']);
        add_action('wp_ajax_rum_delete_user', [self::class, 'delete_user']);
        add_action('wp_ajax_rum_get_sites_for_program', [self::class, 'get_sites_for_program']);
        add_action('wp_ajax_rum_promote_user_direct', [self::class, 'promote_user_direct']);
        add_action('wp_ajax_rum_submit_promotion_request', [self::class, 'submit_promotion_request']);
        
        // Additional dashboard AJAX handlers
        add_action('wp_ajax_rum_get_user_ld_data', [self::class, 'get_user_ld_data']);
        add_action('wp_ajax_rum_remove_user_from_course', [self::class, 'remove_user_from_course']);
        add_action('wp_ajax_rum_bulk_user_action', [self::class, 'bulk_user_action']);
        add_action('wp_ajax_rum_export_users', [self::class, 'export_users']);
        
        // Workflow AJAX
        add_action('wp_ajax_rum_approve_promotion_request', [self::class, 'approve_promotion_request']);
        add_action('wp_ajax_rum_reject_promotion_request', [self::class, 'reject_promotion_request']);
        add_action('wp_ajax_rum_get_promotion_requests', [self::class, 'get_promotion_requests']);
    }
    
    /**
     * Send JSON response
     */
    private static function send_response(bool $success, $data = null, string $message = ''): void {
        wp_send_json([
            'success' => $success,
            'data' => $data,
            'message' => $message,
        ]);
    }
    
    /**
     * Send error response
     */
    private static function send_error(string $message, $data = null): void {
        self::send_response(false, $data, $message);
    }
    
    /**
     * Send success response
     */
    private static function send_success($data = null, string $message = ''): void {
        self::send_response(true, $data, $message);
    }
    
    /**
     * Verify nonce
     */
    private static function verify_nonce(string $nonce, string $action): bool {
        return Validator::validate_nonce($nonce, $action);
    }
    
    /**
     * Update role capabilities
     */
    public static function update_role_capabilities(): void {
        if (!current_user_can('manage_options')) {
            self::send_error('Insufficient permissions');
        }
        
        $nonce = $_POST['nonce'] ?? '';
        if (!self::verify_nonce($nonce, 'rum_nonce')) {
            self::send_error('Invalid nonce');
        }
        
        $role = Validator::sanitize_role($_POST['role'] ?? '');
        $capabilities = $_POST['capabilities'] ?? [];
        
        if (!Validator::validate_role($role)) {
            self::send_error('Invalid role');
        }
        
        $role_manager = \RoleUserManager::getInstance()->getComponent('role_manager');
        if ($role_manager) {
            $result = $role_manager->update_role_capabilities($role, $capabilities);
            if ($result) {
                Logger::log_capability_change($role, [], $capabilities);
                self::send_success(null, 'Role capabilities updated successfully');
            } else {
                self::send_error('Failed to update role capabilities');
            }
        } else {
            self::send_error('Role manager not available');
        }
    }
    
    /**
     * Create new role
     */
    public static function create_role(): void {
        if (!current_user_can('manage_options')) {
            self::send_error('Insufficient permissions');
        }
        
        $nonce = $_POST['nonce'] ?? '';
        if (!self::verify_nonce($nonce, 'rum_nonce')) {
            self::send_error('Invalid nonce');
        }
        
        $role_name = Validator::sanitize_role($_POST['role_name'] ?? '');
        $role_display_name = Validator::sanitize_text($_POST['role_display_name'] ?? '');
        $parent_role = Validator::sanitize_role($_POST['parent_role'] ?? '');
        
        if (!Validator::validate_role($role_name)) {
            self::send_error('Invalid role name');
        }
        
        $role_manager = \RoleUserManager::getInstance()->getComponent('role_manager');
        if ($role_manager) {
            $result = $role_manager->create_role($role_name, $role_display_name, $parent_role);
            if ($result) {
                Logger::log("New role created: {$role_name}");
                self::send_success(null, 'Role created successfully');
            } else {
                self::send_error('Failed to create role');
            }
        } else {
            self::send_error('Role manager not available');
        }
    }
    
    /**
     * Delete role
     */
    public static function delete_role(): void {
        if (!current_user_can('manage_options')) {
            self::send_error('Insufficient permissions');
        }
        
        $nonce = $_POST['nonce'] ?? '';
        if (!self::verify_nonce($nonce, 'rum_nonce')) {
            self::send_error('Invalid nonce');
        }
        
        $role = Validator::sanitize_role($_POST['role'] ?? '');
        
        if (!Validator::validate_role($role)) {
            self::send_error('Invalid role');
        }
        
        $role_manager = \RoleUserManager::getInstance()->getComponent('role_manager');
        if ($role_manager) {
            $result = $role_manager->delete_role($role);
            if ($result) {
                Logger::log("Role deleted: {$role}");
                self::send_success(null, 'Role deleted successfully');
            } else {
                self::send_error('Failed to delete role');
            }
        } else {
            self::send_error('Role manager not available');
        }
    }
    
    /**
     * Get role capabilities
     */
    public static function get_role_capabilities(): void {
        if (!current_user_can('manage_options')) {
            self::send_error('Insufficient permissions');
        }
        
        $role = Validator::sanitize_role($_POST['role'] ?? '');
        
        if (!Validator::validate_role($role)) {
            self::send_error('Invalid role');
        }
        
        $role_manager = \RoleUserManager::getInstance()->getComponent('role_manager');
        if ($role_manager) {
            $capabilities = $role_manager->get_role_capabilities($role);
            self::send_success($capabilities);
        } else {
            self::send_error('Role manager not available');
        }
    }
    
    /**
     * Inherit parent capabilities
     */
    public static function inherit_parent_capabilities(): void {
        if (!current_user_can('manage_options')) {
            self::send_error('Insufficient permissions');
        }
        
        $nonce = $_POST['nonce'] ?? '';
        if (!self::verify_nonce($nonce, 'rum_nonce')) {
            self::send_error('Invalid nonce');
        }
        
        $role = Validator::sanitize_role($_POST['role'] ?? '');
        
        if (!Validator::validate_role($role)) {
            self::send_error('Invalid role');
        }
        
        $role_manager = \RoleUserManager::getInstance()->getComponent('role_manager');
        if ($role_manager) {
            $result = $role_manager->inherit_parent_capabilities($role);
            if ($result) {
                Logger::log("Inherited parent capabilities for role: {$role}");
                self::send_success(null, 'Parent capabilities inherited successfully');
            } else {
                self::send_error('Failed to inherit parent capabilities');
            }
        } else {
            self::send_error('Role manager not available');
        }
    }
    
    /**
     * Get parent role options
     */
    public static function get_parent_options(): void {
        if (!current_user_can('manage_options')) {
            self::send_error('Insufficient permissions');
        }
        
        $role_manager = \RoleUserManager::getInstance()->getComponent('role_manager');
        if ($role_manager) {
            $roles = $role_manager->get_available_parent_roles();
            self::send_success($roles);
        } else {
            self::send_error('Role manager not available');
        }
    }
    
    /**
     * Get user details
     */
    public static function get_user_details(): void {
        if (!current_user_can('list_users')) {
            self::send_error('Insufficient permissions');
        }
        
        $nonce = $_POST['nonce'] ?? '';
        if (!self::verify_nonce($nonce, 'dashboard_nonce')) {
            self::send_error('Invalid nonce');
        }
        
        $user_id = intval($_POST['user_id'] ?? 0);
        
        if (!Validator::validate_user_id($user_id)) {
            self::send_error('Invalid user ID');
        }
        
        $user = get_user_by('id', $user_id);
        if (!$user) {
            self::send_error('User not found');
        }
        
        $user_data = [
            'id' => $user->ID,
            'username' => $user->user_login,
            'email' => $user->user_email,
            'display_name' => $user->display_name,
            'role' => $user->roles[0] ?? '',
            'program' => get_user_meta($user->ID, 'program', true),
            'site' => get_user_meta($user->ID, 'site', true),
            'registration_date' => $user->user_registered,
        ];
        
        self::send_success($user_data);
    }
    
    /**
     * Delete user
     */
    public static function delete_user(): void {
        if (!current_user_can('delete_users')) {
            self::send_error('Insufficient permissions');
        }
        
        $nonce = $_POST['nonce'] ?? '';
        if (!self::verify_nonce($nonce, 'dashboard_nonce')) {
            self::send_error('Invalid nonce');
        }
        
        $user_id = intval($_POST['user_id'] ?? 0);
        
        if (!Validator::validate_user_id($user_id)) {
            self::send_error('Invalid user ID');
        }
        
        $user = get_user_by('id', $user_id);
        if (!$user) {
            self::send_error('User not found');
        }
        
        $result = wp_delete_user($user_id);
        if ($result) {
            Logger::log("User deleted: {$user->user_login} (ID: {$user_id})");
            self::send_success(null, 'User deleted successfully');
        } else {
            self::send_error('Failed to delete user');
        }
    }
    
    /**
     * Get sites for program
     */
    public static function get_sites_for_program(): void {
        $nonce = $_POST['nonce'] ?? '';
        if (!self::verify_nonce($nonce, 'dashboard_nonce')) {
            self::send_error('Invalid nonce');
        }
        
        $program = Validator::sanitize_text($_POST['program'] ?? '');
        
        if (empty($program)) {
            self::send_error('Program is required');
        }
        
        global $wpdb;
        $sites = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT site FROM {$wpdb->prefix}rum_program_sites WHERE program = %s ORDER BY site",
            $program
        ));
        
        self::send_success($sites);
    }
    
    /**
     * Get user LearnDash data (for modal)
     */
    public static function get_user_ld_data(): void {
        if (!is_user_logged_in()) {
            self::send_error('User not logged in');
        }
        
        $nonce = $_POST['nonce'] ?? '';
        if (!self::verify_nonce($nonce, 'dashboard_nonce')) {
            self::send_error('Invalid nonce');
        }
        
        $user_id = intval($_POST['user_id'] ?? 0);
        if (!$user_id) {
            self::send_error('No user ID provided');
        }
        
        $user = get_user_by('id', $user_id);
        if (!$user) {
            self::send_error('User not found');
        }
        
        // Get user metadata
        $parent_id = get_user_meta($user_id, 'parent_user_id', true);
        $program = get_user_meta($user_id, 'program', true);
        $site = get_user_meta($user_id, 'site', true);
        $parent_name = $parent_id ? get_user_by('id', $parent_id)->display_name : 'None';
        
        // Start building HTML
        $html = '<div class="user-edit-info">';
        $html .= '<h6>' . __('User Information', 'role-user-manager') . '</h6>';
        $html .= '<div class="row">';
        $html .= '<div class="col-md-6">';
        $html .= '<p><strong>' . __('Name:', 'role-user-manager') . '</strong> ' . esc_html($user->display_name) . '</p>';
        $html .= '<p><strong>' . __('Email:', 'role-user-manager') . '</strong> ' . esc_html($user->user_email) . '</p>';
        $html .= '<p><strong>' . __('Username:', 'role-user-manager') . '</strong> ' . esc_html($user->user_login) . '</p>';
        $html .= '</div>';
        $html .= '<div class="col-md-6">';
        $html .= '<p><strong>' . __('Role:', 'role-user-manager') . '</strong> ' . esc_html(implode(', ', $user->roles)) . '</p>';
        $html .= '<p><strong>' . __('Parent:', 'role-user-manager') . '</strong> ' . esc_html($parent_name) . '</p>';
        $html .= '<p><strong>' . __('Program:', 'role-user-manager') . '</strong> ' . esc_html($program ?: 'None') . '</p>';
        $html .= '<p><strong>' . __('Site:', 'role-user-manager') . '</strong> ' . esc_html($site ?: 'None') . '</p>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div><hr>';
        
        // Get LearnDash stats if available
        $dashboard = \RoleUserManager::getInstance()->getComponent('dashboard');
        if ($dashboard) {
            $stats = $dashboard->get_user_stats($user_id);
            
            $html .= '<h6>' . __('Learning Statistics', 'role-user-manager') . '</h6>';
            $html .= '<div class="row">';
            $html .= '<div class="col-md-3"><strong>' . __('Courses Enrolled:', 'role-user-manager') . '</strong> ' . esc_html($stats['courses_enrolled']) . '</div>';
            $html .= '<div class="col-md-3"><strong>' . __('Courses Completed:', 'role-user-manager') . '</strong> ' . esc_html($stats['courses_completed']) . '</div>';
            $html .= '<div class="col-md-3"><strong>' . __('Assignments:', 'role-user-manager') . '</strong> ' . esc_html($stats['assignments_submitted']) . '</div>';
            $html .= '<div class="col-md-3"><strong>' . __('Certificates:', 'role-user-manager') . '</strong> ' . esc_html($stats['certificates_earned']) . '</div>';
            $html .= '</div>';
        }
        
        self::send_success(['html' => $html]);
    }
    
    /**
     * Remove user from course
     */
    public static function remove_user_from_course(): void {
        if (!current_user_can('edit_users')) {
            self::send_error('Insufficient permissions');
        }
        
        $nonce = $_POST['nonce'] ?? '';
        if (!self::verify_nonce($nonce, 'dashboard_nonce')) {
            self::send_error('Invalid nonce');
        }
        
        $user_id = intval($_POST['user_id'] ?? 0);
        $course_id = intval($_POST['course_id'] ?? 0);
        
        if (!$user_id || !$course_id) {
            self::send_error('Missing user or course ID');
        }
        
        // Check if LearnDash function exists
        if (!function_exists('ld_update_course_access')) {
            self::send_error('LearnDash function not available');
        }
        
        // Unenroll user from course
        $result = ld_update_course_access($user_id, $course_id, $remove = true);
        
        if ($result) {
            self::send_success(null, 'User removed from course');
        } else {
            self::send_error('Failed to remove user from course');
        }
    }
    
    /**
     * Bulk user action
     */
    public static function bulk_user_action(): void {
        if (!current_user_can('edit_users')) {
            self::send_error('Insufficient permissions');
        }
        
        $nonce = $_POST['nonce'] ?? '';
        if (!self::verify_nonce($nonce, 'dashboard_nonce')) {
            self::send_error('Invalid nonce');
        }
        
        $user_ids = isset($_POST['users']) && is_array($_POST['users']) ? array_map('intval', $_POST['users']) : [];
        $action = Validator::sanitize_text($_POST['bulk_action'] ?? '');
        $role = Validator::sanitize_text($_POST['bulk_role'] ?? '');
        
        if (empty($user_ids) || !$action) {
            self::send_error('No users or action specified');
        }
        
        $success = 0;
        $fail = 0;
        
        foreach ($user_ids as $uid) {
            if ($action === 'remove') {
                require_once ABSPATH . 'wp-admin/includes/user.php';
                $result = wp_delete_user($uid);
                if ($result) {
                    $success++;
                } else {
                    $fail++;
                }
            } elseif ($action === 'assign_role' && $role) {
                $u = get_userdata($uid);
                if ($u) {
                    $u->set_role($role);
                    $success++;
                } else {
                    $fail++;
                }
            }
        }
        
        if ($success > 0) {
            self::send_success(null, "Bulk action completed: {$success} success, {$fail} failed");
        } else {
            self::send_error('No users updated');
        }
    }
    
    /**
     * Export users
     */
    public static function export_users(): void {
        if (!current_user_can('edit_users')) {
            self::send_error('Insufficient permissions');
        }
        
        $nonce = $_POST['nonce'] ?? '';
        if (!self::verify_nonce($nonce, 'dashboard_nonce')) {
            self::send_error('Invalid nonce');
        }
        
        // Get filter parameters
        $filter_program = Validator::sanitize_text($_POST['filter_program'] ?? '');
        $filter_site = Validator::sanitize_text($_POST['filter_site'] ?? '');
        $filter_training_status = Validator::sanitize_text($_POST['filter_training_status'] ?? '');
        $filter_date_start = Validator::sanitize_text($_POST['filter_date_start'] ?? '');
        $filter_date_end = Validator::sanitize_text($_POST['filter_date_end'] ?? '');
        
        // Get all users
        $all_users = get_users(['orderby' => 'display_name', 'order' => 'ASC', 'fields' => 'all']);
        
        // Apply filters (simplified version)
        $filtered_users = array_filter($all_users, function ($user) use ($filter_program, $filter_site, $filter_training_status, $filter_date_start, $filter_date_end) {
            // Filter by program
            if (!empty($filter_program)) {
                $user_program = get_user_meta($user->ID, 'program', true);
                if ($user_program !== $filter_program) {
                    return false;
                }
            }
            
            // Filter by site
            if (!empty($filter_site)) {
                $user_site = get_user_meta($user->ID, 'site', true);
                if ($user_site !== $filter_site) {
                    return false;
                }
            }
            
            return true;
        });
        
        // Prepare CSV data
        $csv_data = [];
        $csv_data[] = [
            'Name',
            'Email',
            'Role',
            'Program',
            'Site',
            'Registration Date'
        ];
        
        foreach ($filtered_users as $user) {
            $program = get_user_meta($user->ID, 'program', true);
            $site = get_user_meta($user->ID, 'site', true);
            
            $csv_data[] = [
                $user->display_name,
                $user->user_email,
                implode(', ', $user->roles),
                $program ?: '—',
                $site ?: '—',
                $user->user_registered
            ];
        }
        
        // Generate CSV content
        $csv_content = '';
        foreach ($csv_data as $row) {
            $csv_content .= '"' . implode('","', array_map(function ($field) {
                return str_replace('"', '""', strval($field));
            }, $row)) . '"' . "\n";
        }
        
        $filename = 'users_export_' . date('Y-m-d_H-i-s') . '.csv';
        
        self::send_success([
            'csv_content' => $csv_content,
            'filename' => $filename,
            'count' => count($filtered_users)
        ]);
    }
    
    /**
     * Promote user directly
     */
    public static function promote_user_direct(): void {
        if (!current_user_can('edit_users')) {
            self::send_error('Insufficient permissions');
        }
        
        $nonce = $_POST['nonce'] ?? '';
        if (!self::verify_nonce($nonce, 'arc_dashboard_nonce')) {
            self::send_error('Invalid nonce');
        }
        
        $user_id = intval($_POST['user_id'] ?? 0);
        $requested_role = Validator::sanitize_role($_POST['requested_role'] ?? '');
        
        if (!Validator::validate_user_id($user_id)) {
            self::send_error('Invalid user ID');
        }
        
        if (!Validator::validate_role($requested_role)) {
            self::send_error('Invalid role');
        }
        
        $user = get_user_by('id', $user_id);
        if (!$user) {
            self::send_error('User not found');
        }
        
        $old_role = $user->roles[0] ?? '';
        $user->set_role($requested_role);
        
        // Set the promoter as the parent of the promoted user
        $promoter_id = get_current_user_id();
        update_user_meta($user_id, 'parent_user_id', $promoter_id);
        error_log("Set parent user to promoter: {$promoter_id} for user: {$user_id}");
        
        Logger::log_role_change($user_id, $old_role, $requested_role);
        self::send_success(null, 'User promoted successfully');
    }
    
    /**
     * Submit promotion request
     */
    public static function submit_promotion_request(): void {
        if (!is_user_logged_in()) {
            self::send_error('User not logged in');
        }
        
        $nonce = $_POST['nonce'] ?? '';
        if (!self::verify_nonce($nonce, 'arc_dashboard_nonce')) {
            self::send_error('Invalid nonce');
        }
        
        $requester_id = get_current_user_id();
        $user_id = intval($_POST['user_id'] ?? 0);
        $requested_role = Validator::sanitize_role($_POST['requested_role'] ?? '');
        $reason = Validator::sanitize_text($_POST['reason'] ?? '');
        
        // Get current role from user data
        $user = get_user_by('id', $user_id);
        if (!$user) {
            self::send_error('User not found');
        }
        $current_role = $user->roles[0] ?? '';
        
        $errors = Validator::validate_promotion_request($requester_id, $user_id, $current_role, $requested_role, $reason);
        if (!empty($errors)) {
            self::send_error(implode(', ', $errors));
        }
        
        $workflow = \RoleUserManager::getInstance()->getComponent('workflow');
        if ($workflow) {
            $request_id = $workflow->create_promotion_request($requester_id, $user_id, $current_role, $requested_role, $reason);
            if ($request_id) {
                Logger::log_promotion_request($requester_id, $user_id, $current_role, $requested_role, $reason);
                self::send_success(['request_id' => $request_id], 'Promotion request submitted successfully');
            } else {
                self::send_error('Failed to submit promotion request');
            }
        } else {
            self::send_error('Workflow manager not available');
        }
    }
    
    /**
     * Approve promotion request
     */
    public static function approve_promotion_request(): void {
        if (!current_user_can('edit_users')) {
            self::send_error('Insufficient permissions');
        }
        
        $nonce = $_POST['nonce'] ?? '';
        if (!self::verify_nonce($nonce, 'workflow_nonce')) {
            self::send_error('Invalid nonce');
        }
        
        $request_id = intval($_POST['request_id'] ?? 0);
        $admin_notes = Validator::sanitize_text($_POST['admin_notes'] ?? '');
        
        if ($request_id <= 0) {
            self::send_error('Invalid request ID');
        }
        
        $workflow = \RoleUserManager::getInstance()->getComponent('workflow');
        if ($workflow) {
            $result = $workflow->approve_request($request_id, $admin_notes);
            if ($result) {
                Logger::log("Promotion request approved: {$request_id}");
                self::send_success(null, 'Promotion request approved successfully');
            } else {
                self::send_error('Failed to approve promotion request');
            }
        } else {
            self::send_error('Workflow manager not available');
        }
    }
    
    /**
     * Reject promotion request
     */
    public static function reject_promotion_request(): void {
        if (!current_user_can('edit_users')) {
            self::send_error('Insufficient permissions');
        }
        
        $nonce = $_POST['nonce'] ?? '';
        if (!self::verify_nonce($nonce, 'workflow_nonce')) {
            self::send_error('Invalid nonce');
        }
        
        $request_id = intval($_POST['request_id'] ?? 0);
        $admin_notes = Validator::sanitize_text($_POST['admin_notes'] ?? '');
        
        if ($request_id <= 0) {
            self::send_error('Invalid request ID');
        }
        
        $workflow = \RoleUserManager::getInstance()->getComponent('workflow');
        if ($workflow) {
            $result = $workflow->reject_request($request_id, $admin_notes);
            if ($result) {
                Logger::log("Promotion request rejected: {$request_id}");
                self::send_success(null, 'Promotion request rejected successfully');
            } else {
                self::send_error('Failed to reject promotion request');
            }
        } else {
            self::send_error('Workflow manager not available');
        }
    }
    
    /**
     * Get promotion requests
     */
    public static function get_promotion_requests(): void {
        if (!current_user_can('edit_users')) {
            self::send_error('Insufficient permissions');
        }
        
        $workflow = \RoleUserManager::getInstance()->getComponent('workflow');
        if ($workflow) {
            $requests = $workflow->get_promotion_requests();
            self::send_success($requests);
        } else {
            self::send_error('Workflow manager not available');
        }
    }
} 
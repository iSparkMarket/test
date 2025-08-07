<?php
declare(strict_types=1);

namespace RoleUserManager;

/**
 * Workflow class
 */
class Workflow
{

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks(): void
    {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);

        // AJAX handlers
        add_action('wp_ajax_rum_approve_promotion_request', [Ajax::class, 'approve_promotion_request']);
        add_action('wp_ajax_rum_reject_promotion_request', [Ajax::class, 'reject_promotion_request']);
        add_action('wp_ajax_rum_get_promotion_requests', [Ajax::class, 'get_promotion_requests']);
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu(): void
    {
        add_submenu_page(
            'role-capabilities',
            __('Workflow Admin', 'role-user-manager'),
            __('Workflow Admin', 'role-user-manager'),
            'edit_users',
            'workflow-admin',
            [$this, 'admin_page']
        );
    }

    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts(string $hook): void
    {
        if ($hook !== 'role-capabilities_page_workflow-admin') {
            return;
        }

        Assets::enqueue_admin_assets($hook);
    }

    /**
     * Admin page
     */
    public function admin_page(): void
    {
        if (!current_user_can('edit_users')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        $requests = $this->get_promotion_requests();
        $stats = $this->get_workflow_stats();

        include RUM_PLUGIN_DIR . 'templates/workflow-admin.php';
    }

    /**
     * Create promotion request
     */
    public function create_promotion_request(int $requester_id, int $user_id, string $current_role, string $requested_role, string $reason): int
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'rum_promotion_requests';

        $result = $wpdb->insert(
            $table_name,
            [
                'requester_id' => $requester_id,
                'user_id' => $user_id,
                'current_role' => $current_role,
                'requested_role' => $requested_role,
                'reason' => $reason,
                'status' => 'pending',
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%d', '%s', '%s', '%s', '%s', '%s']
        );

        if ($result === false) {
            return 0;
        }

        return $wpdb->insert_id;
    }

    /**
     * Get promotion requests
     */
    public function get_promotion_requests(): array
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'rum_promotion_requests';

        return $wpdb->get_results(
            "SELECT * FROM {$table_name} ORDER BY created_at DESC",
            ARRAY_A
        );
    }

    /**
     * Get promotion request by ID
     */
    private function get_promotion_request(int $request_id): ?object
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'rum_promotion_requests';

        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE id = %d",
            $request_id
        ));

        return $result;
    }

    /**
     * Check if user has pending request
     */
    public function has_pending_request(int $user_id, string $requested_role): bool
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'rum_promotion_requests';

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} WHERE user_id = %d AND requested_role = %s AND status = 'pending'",
            $user_id,
            $requested_role
        ));

        return $count > 0;
    }

    /**
     * Update request status
     */
    private function update_request_status(int $request_id, string $status, string $admin_notes = ''): bool
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'rum_promotion_requests';

        $result = $wpdb->update(
            $table_name,
            [
                'status' => $status,
                'admin_notes' => $admin_notes,
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $request_id],
            ['%s', '%s', '%s'],
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Approve request
     */
    public function approve_request(int $request_id, string $admin_notes = ''): bool
    {
        $request = $this->get_promotion_request($request_id);
        if (!$request) {
            return false;
        }

        // Update user role
        $user = get_user_by('id', $request->user_id);
        if (!$user) {
            return false;
        }

        $old_role = $user->roles[0] ?? '';
        $user->set_role($request->requested_role);

        // Set the requester as the parent of the promoted user
        update_user_meta($request->user_id, 'parent_user_id', $request->requester_id);
        error_log("Set parent user to requester: {$request->requester_id} for user: {$request->user_id}");

        // Update request status
        $result = $this->update_request_status($request_id, 'approved', $admin_notes);

        if ($result) {
            Logger::log_role_change($request->user_id, $old_role, $request->requested_role);
        }

        return $result;
    }

    /**
     * Reject request
     */
    public function reject_request(int $request_id, string $admin_notes = ''): bool
    {
        return $this->update_request_status($request_id, 'rejected', $admin_notes);
    }

    /**
     * Get workflow statistics
     */
    private function get_workflow_stats(): array
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'rum_promotion_requests';

        $stats = [
            'total' => $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}"),
            'pending' => $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE status = 'pending'"),
            'approved' => $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE status = 'approved'"),
            'rejected' => $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE status = 'rejected'"),
        ];

        return $stats;
    }

    /**
     * Validate promotion request
     */
    /**
     * Validate promotion request
     */
    public function validate_promotion_request($requester, $user, $current_role, $requested_role): array
    {
        $errors = [];

        // Check if requester has permission
        if (!current_user_can('edit_users') && $requester->ID !== $user->ID) {
            $errors[] = 'You do not have permission to request this promotion';
        }

        // Check if user already has the requested role
        if ($current_role === $requested_role) {
            $errors[] = 'User already has the requested role';
        }

        // Check if there's already a pending request
        if ($this->has_pending_request($user->ID, $requested_role)) {
            $errors[] = 'There is already a pending request for this promotion';
        }

        // Enforce role-based workflow restrictions
        $requester_roles = $requester->roles;
        $is_admin = in_array('administrator', $requester_roles, true);
        $is_program_leader = in_array('program-leader', $requester_roles, true);
        $is_site_supervisor = in_array('site-supervisor', $requester_roles, true);

        $is_allowed = false;

        if ($current_role === 'frontline-staff' && $requested_role === 'site-supervisor') {
            // Program Leaders, Site Supervisors, or Admins can request Frontline to Site Supervisor
            $is_allowed = $is_admin || $is_program_leader || $is_site_supervisor;
        } elseif ($current_role === 'site-supervisor' && $requested_role === 'program-leader') {
            // Program Leaders, Site Supervisors, or Admins can request Site Supervisor to Program Leader
            $is_allowed = $is_admin || $is_program_leader || $is_site_supervisor;
        } elseif ($current_role === 'program-leader' && $requested_role === 'data-viewer') {
            // Only Admins can promote Program Leaders to Data Viewer
            $is_allowed = $is_admin;
        }

        if (!$is_allowed) {
            $errors[] = 'You are not allowed to request this promotion path.';
        }

        return $errors;
    }


    /**
     * Get user primary role
     */
    public function get_user_primary_role(int $user_id): string {
        $user = get_user_by('id', $user_id);
        if (!$user) {
            return '';
        }
        return $user->roles[0] ?? '';
    }

    /**
     * Get role display name (public for template access)
     */
    public function get_role_display_name(string $role): string {
        $roles = wp_roles()->get_names();
        return $roles[$role] ?? ucwords(str_replace('-', ' ', $role));
    }
    
    /**
     * Get available promotions for user (simplified version)
     */
    public function get_available_promotions_for_user(int $user_id): array {
        $user = get_user_by('id', $user_id);
        if (!$user) {
            return [];
        }
        
        $current_role = $user->roles[0] ?? '';
        $promotions = [];
        
        // Define promotion paths
        $promotion_paths = [
            'frontline-staff' => ['site-supervisor', 'program-leader'],
            'site-supervisor' => ['program-leader'],
            'program-leader' => ['data-viewer'],
        ];
        
        if (isset($promotion_paths[$current_role])) {
            foreach ($promotion_paths[$current_role] as $promotion_role) {
                $requires_approval = false;
                
                // Determine if approval is required based on the promotion path
                // All promotions require approval unless the requester is an administrator
                if ($promotion_role === 'program-leader') {
                    $requires_approval = true; // Program Leader promotion always requires approval
                } elseif ($current_role === 'site-supervisor' && $promotion_role === 'program-leader') {
                    $requires_approval = true; // Site Supervisor to Program Leader requires approval
                } elseif ($current_role === 'frontline-staff' && $promotion_role === 'site-supervisor') {
                    $requires_approval = true; // Frontline to Site Supervisor requires approval
                }
                
                $promotions[] = [
                    'role' => $promotion_role,
                    'name' => $this->get_role_display_name($promotion_role),
                    'requires_approval' => $requires_approval,
                ];
            }
        }
        
        return $promotions;
    }
    


}
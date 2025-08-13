<?php
declare(strict_types=1);

/**
 * Role Assignment Workflows System
 * 
 * Handles promotion requests and approvals for role hierarchy:
 * - Frontline → Site Supervisor: selectable only by Program Leader or Admin
 * - Site Supervisor → Program Leader: not permitted; must request Admin
 * - Program Leader role: assigned by Admin only
 */

class RoleAssignmentWorkflow
{
    private const TABLE_NAME = 'role_promotion_requests';
    private const STATUS_PENDING = 'pending';
    private const STATUS_APPROVED = 'approved';
    private const STATUS_REJECTED = 'rejected';

    public function __construct()
    {
        add_action('init', [$this, 'init']);
        add_action('wp_ajax_submit_promotion_request', [$this, 'ajax_submit_promotion_request']);
        add_action('wp_ajax_approve_promotion_request', [$this, 'ajax_approve_promotion_request']);
        add_action('wp_ajax_reject_promotion_request', [$this, 'ajax_reject_promotion_request']);
        add_action('wp_ajax_get_promotion_requests', [$this, 'ajax_get_promotion_requests']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
    }

    public function init(): void
    {
        $this->create_tables();
    }

    private function create_tables(): void
    {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        
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
    }

    public function add_admin_menu(): void
    {
        add_submenu_page(
            'users.php',
            __('Role Promotion Requests', 'role-user-manager'),
            __('Promotion Requests', 'role-user-manager'),
            'manage_options',
            'role-promotion-requests',
            [$this, 'admin_page']
        );
    }

    public function enqueue_admin_scripts(string $hook): void
    {
        if ($hook !== 'users_page_role-promotion-requests') {
            return;
        }

        wp_enqueue_script(
            'role-workflow-admin',
            plugin_dir_url(__FILE__) . '../assets/js/workflow-admin.js',
            ['jquery'],
            '1.0.0',
            true
        );

        wp_localize_script('role-workflow-admin', 'workflow_vars', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('role_workflow_nonce'),
        ]);
    }

    public function admin_page(): void
    {
        $requests = $this->get_promotion_requests();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Role Promotion Requests', 'role-user-manager'); ?></h1>
            
            <div class="workflow-stats">
                <div class="stat-box">
                    <h3><?php echo count(array_filter($requests, fn($r) => $r->status === self::STATUS_PENDING)); ?></h3>
                    <p><?php esc_html_e('Pending Requests', 'role-user-manager'); ?></p>
                </div>
                <div class="stat-box">
                    <h3><?php echo count(array_filter($requests, fn($r) => $r->status === self::STATUS_APPROVED)); ?></h3>
                    <p><?php esc_html_e('Approved', 'role-user-manager'); ?></p>
                </div>
                <div class="stat-box">
                    <h3><?php echo count(array_filter($requests, fn($r) => $r->status === self::STATUS_REJECTED)); ?></h3>
                    <p><?php esc_html_e('Rejected', 'role-user-manager'); ?></p>
                </div>
            </div>

            <div class="workflow-requests">
                <?php if (empty($requests)): ?>
                    <p><?php esc_html_e('No promotion requests found.', 'role-user-manager'); ?></p>
                <?php else: ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Requester', 'role-user-manager'); ?></th>
                                <th><?php esc_html_e('User', 'role-user-manager'); ?></th>
                                <th><?php esc_html_e('Current Role', 'role-user-manager'); ?></th>
                                <th><?php esc_html_e('Requested Role', 'role-user-manager'); ?></th>
                                <th><?php esc_html_e('Reason', 'role-user-manager'); ?></th>
                                <th><?php esc_html_e('Status', 'role-user-manager'); ?></th>
                                <th><?php esc_html_e('Date', 'role-user-manager'); ?></th>
                                <th><?php esc_html_e('Actions', 'role-user-manager'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($requests as $request): ?>
                                <tr data-request-id="<?php echo esc_attr($request->id); ?>">
                                    <td><?php echo esc_html(get_user_by('id', $request->requester_id)->display_name); ?></td>
                                    <td><?php echo esc_html(get_user_by('id', $request->user_id)->display_name); ?></td>
                                    <td><?php echo esc_html($this->get_role_display_name($request->current_role)); ?></td>
                                    <td><?php echo esc_html($this->get_role_display_name($request->requested_role)); ?></td>
                                    <td><?php echo esc_html($request->reason); ?></td>
                                    <td>
                                        <span class="status-<?php echo esc_attr($request->status); ?>">
                                            <?php echo esc_html(ucfirst($request->status)); ?>
                                        </span>
                                    </td>
                                    <td><?php echo esc_html(date('M j, Y', strtotime($request->created_at))); ?></td>
                                    <td>
                                        <?php if ($request->status === self::STATUS_PENDING): ?>
                                            <button class="button button-primary approve-request" 
                                                    data-request-id="<?php echo esc_attr($request->id); ?>">
                                                <?php esc_html_e('Approve', 'role-user-manager'); ?>
                                            </button>
                                            <button class="button button-secondary reject-request" 
                                                    data-request-id="<?php echo esc_attr($request->id); ?>">
                                                <?php esc_html_e('Reject', 'role-user-manager'); ?>
                                            </button>
                                        <?php else: ?>
                                            <em><?php esc_html_e('Processed', 'role-user-manager'); ?></em>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <style>
            .workflow-stats {
                display: flex;
                gap: 20px;
                margin-bottom: 30px;
            }
            .stat-box {
                background: white;
                padding: 20px;
                border-radius: 8px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                text-align: center;
                min-width: 120px;
            }
            .stat-box h3 {
                margin: 0 0 10px 0;
                font-size: 2em;
                color: #2271b1;
            }
            .stat-box p {
                margin: 0;
                color: #666;
            }
            .status-pending { color: #f39c12; font-weight: bold; }
            .status-approved { color: #27ae60; font-weight: bold; }
            .status-rejected { color: #e74c3c; font-weight: bold; }
        </style>
        <?php
    }

    public function ajax_submit_promotion_request(): void
    {
        check_ajax_referer('role_workflow_nonce', 'nonce');

        $user_id = intval($_POST['user_id']);
        $requested_role = sanitize_text_field($_POST['requested_role']);
        $reason = sanitize_textarea_field($_POST['reason']);

        $current_user = wp_get_current_user();
        $user = get_user_by('id', $user_id);
        
        if (!$user) {
            wp_send_json_error(['message' => __('User not found.', 'role-user-manager')]);
        }

        $current_role = $this->get_user_primary_role($user_id);
        
        // Validate promotion request
        $validation = $this->validate_promotion_request($current_user, $user, $current_role, $requested_role);
        if (!$validation['valid']) {
            wp_send_json_error(['message' => $validation['message']]);
        }

        // Check if request already exists
        if ($this->has_pending_request($user_id, $requested_role)) {
            wp_send_json_error(['message' => __('A promotion request for this user and role already exists.', 'role-user-manager')]);
        }

        // Create the request
        $request_id = $this->create_promotion_request($current_user->ID, $user_id, $current_role, $requested_role, $reason);
        
        if ($request_id) {
            wp_send_json_success([
                'message' => __('Promotion request submitted successfully.', 'role-user-manager'),
                'request_id' => $request_id
            ]);
        } else {
            wp_send_json_error(['message' => __('Failed to submit promotion request.', 'role-user-manager')]);
        }
    }

    public function ajax_approve_promotion_request(): void
    {
        check_ajax_referer('role_workflow_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'role-user-manager')]);
        }

        $request_id = intval($_POST['request_id']);
        $admin_notes = sanitize_textarea_field($_POST['admin_notes'] ?? '');

        $request = $this->get_promotion_request($request_id);
        if (!$request) {
            wp_send_json_error(['message' => __('Request not found.', 'role-user-manager')]);
        }

        // Update user role
        $user = get_user_by('id', $request->user_id);
        $user->set_role($request->requested_role);

        // Set the requester as the parent of the promoted user
        update_user_meta($request->user_id, 'parent_user_id', $request->requester_id);
        error_log("Set parent user to requester: {$request->requester_id} for user: {$request->user_id}");

        // Update request status
        $this->update_request_status($request_id, self::STATUS_APPROVED, $admin_notes);

        // Log the action
        $this->log_audit("Role promotion approved: User {$request->user_id} promoted from {$request->current_role} to {$request->requested_role} (Parent set to: {$request->requester_id})");

        wp_send_json_success(['message' => __('Promotion request approved successfully.', 'role-user-manager')]);
    }

    public function ajax_reject_promotion_request(): void
    {
        check_ajax_referer('role_workflow_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'role-user-manager')]);
        }

        $request_id = intval($_POST['request_id']);
        $admin_notes = sanitize_textarea_field($_POST['admin_notes'] ?? '');

        $request = $this->get_promotion_request($request_id);
        if (!$request) {
            wp_send_json_error(['message' => __('Request not found.', 'role-user-manager')]);
        }

        // Update request status
        $this->update_request_status($request_id, self::STATUS_REJECTED, $admin_notes);

        // Log the action
        $this->log_audit("Role promotion rejected: User {$request->user_id} promotion from {$request->current_role} to {$request->requested_role}");

        wp_send_json_success(['message' => __('Promotion request rejected successfully.', 'role-user-manager')]);
    }

    public function ajax_get_promotion_requests(): void
    {
        check_ajax_referer('role_workflow_nonce', 'nonce');

        $requests = $this->get_promotion_requests();
        wp_send_json_success(['requests' => $requests]);
    }

    public function validate_promotion_request($requester, $user, $current_role, $requested_role): array
    {
        $requester_roles = array_map('strtolower', $requester->roles);
        
        // Check if promotion is allowed based on role hierarchy
        switch ($current_role) {
            case 'frontline-staff':
                if ($requested_role !== 'site-supervisor') {
                    return ['valid' => false, 'message' => __('Frontline staff can only be promoted to Site Supervisor.', 'role-user-manager')];
                }
                // Program Leaders and Site Supervisors can request Frontline to Site Supervisor promotion
                if (!in_array('program-leader', $requester_roles) && !in_array('site-supervisor', $requester_roles) && !in_array('administrator', $requester_roles)) {
                    return ['valid' => false, 'message' => __('Only Program Leaders, Site Supervisors, or Administrators can request Frontline Staff to Site Supervisor promotion.', 'role-user-manager')];
                }
                // If requester is not admin, this will require approval
                if (!in_array('administrator', $requester_roles)) {
                    return ['valid' => true, 'message' => '', 'requires_approval' => true];
                }
                break;

            case 'site-supervisor':
                if ($requested_role !== 'program-leader') {
                    return ['valid' => false, 'message' => __('Site Supervisors can only be promoted to Program Leader.', 'role-user-manager')];
                }
                // Program Leaders and Site Supervisors can request Site Supervisor to Program Leader promotion
                if (!in_array('program-leader', $requester_roles) && !in_array('site-supervisor', $requester_roles) && !in_array('administrator', $requester_roles)) {
                    return ['valid' => false, 'message' => __('Only Program Leaders, Site Supervisors, or Administrators can request Site Supervisor to Program Leader promotion.', 'role-user-manager')];
                }
                // If requester is not admin, this will require approval
                if (!in_array('administrator', $requester_roles)) {
                    return ['valid' => true, 'message' => '', 'requires_approval' => true];
                }
                break;

            case 'program-leader':
                if ($requested_role !== 'data-viewer') {
                    return ['valid' => false, 'message' => __('Program Leaders can only be promoted to Data Viewer.', 'role-user-manager')];
                }
                // Only Administrators can promote Program Leaders to Data Viewer
                if (!in_array('administrator', $requester_roles)) {
                    return ['valid' => false, 'message' => __('Only Administrators can promote Program Leaders to Data Viewer.', 'role-user-manager')];
                }
                break;

            default:
                return ['valid' => false, 'message' => __('Invalid current role for promotion.', 'role-user-manager')];
        }

        return ['valid' => true, 'message' => ''];
    }

    public function get_user_primary_role(int $user_id): string
    {
        $user = get_user_by('id', $user_id);
        if (!$user) {
            return '';
        }
        
        $roles = array_map('strtolower', $user->roles);
        
        // Return the highest role in hierarchy
        if (in_array('administrator', $roles)) return 'administrator';
        if (in_array('program-leader', $roles)) return 'program-leader';
        if (in_array('site-supervisor', $roles)) return 'site-supervisor';
        if (in_array('frontline-staff', $roles)) return 'frontline-staff';
        
        return $roles[0] ?? '';
    }

    private function get_role_display_name(string $role): string
    {
        $role_names = [
            'frontline-staff' => __('Frontline Staff', 'role-user-manager'),
            'site-supervisor' => __('Site Supervisor', 'role-user-manager'),
            'program-leader' => __('Program Leader', 'role-user-manager'),
            'administrator' => __('Administrator', 'role-user-manager'),
        ];
        
        return $role_names[$role] ?? ucfirst(str_replace('-', ' ', $role));
    }

    public function create_promotion_request(int $requester_id, int $user_id, string $current_role, string $requested_role, string $reason): int
    {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        
        $result = $wpdb->insert(
            $table_name,
            [
                'requester_id' => $requester_id,
                'user_id' => $user_id,
                'current_role' => $current_role,
                'requested_role' => $requested_role,
                'reason' => $reason,
                'status' => self::STATUS_PENDING,
            ],
            ['%d', '%d', '%s', '%s', '%s', '%s']
        );
        
        return $result ? $wpdb->insert_id : 0;
    }

    private function get_promotion_requests(): array
    {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        
        return $wpdb->get_results(
            "SELECT * FROM $table_name ORDER BY created_at DESC"
        );
    }

    private function get_promotion_request(int $request_id): ?object
    {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            $request_id
        ));
    }

    public function has_pending_request(int $user_id, string $requested_role): bool
    {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE user_id = %d AND requested_role = %s AND status = %s",
            $user_id,
            $requested_role,
            self::STATUS_PENDING
        ));
        
        return $count > 0;
    }

    private function update_request_status(int $request_id, string $status, string $admin_notes = ''): bool
    {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        
        return $wpdb->update(
            $table_name,
            [
                'status' => $status,
                'admin_notes' => $admin_notes,
            ],
            ['id' => $request_id],
            ['%s', '%s'],
            ['%d']
        ) !== false;
    }

    public function log_audit(string $message): void
    {
        $log_entry = [
            'timestamp' => current_time('mysql'),
            'message' => $message,
            'user_id' => get_current_user_id(),
        ];
        
        $audit_log = get_option('role_workflow_audit_log', []);
        $audit_log[] = $log_entry;
        
        // Keep only last 100 entries
        if (count($audit_log) > 100) {
            $audit_log = array_slice($audit_log, -100);
        }
        
        update_option('role_workflow_audit_log', $audit_log);
    }

    public function get_available_promotions_for_user(int $user_id): array
    {
        $current_role = $this->get_user_primary_role($user_id);
        $current_user = wp_get_current_user();
        $requester_roles = array_map('strtolower', $current_user->roles);
        
        $available_promotions = [];
        
        switch ($current_role) {
            case 'frontline-staff':
                // Program Leaders, Site Supervisors, and Administrators can request Frontline to Site Supervisor
                if (in_array('program-leader', $requester_roles) || in_array('site-supervisor', $requester_roles) || in_array('administrator', $requester_roles)) {
                    $requires_approval = !in_array('administrator', $requester_roles);
                    $available_promotions[] = [
                        'role' => 'site-supervisor',
                        'name' => __('Site Supervisor', 'role-user-manager'),
                        'requires_approval' => $requires_approval
                    ];
                }
                break;
                
            case 'site-supervisor':
                // Program Leaders, Site Supervisors, and Administrators can request Site Supervisor to Program Leader
                if (in_array('program-leader', $requester_roles) || in_array('site-supervisor', $requester_roles) || in_array('administrator', $requester_roles)) {
                    $requires_approval = !in_array('administrator', $requester_roles);
                    $available_promotions[] = [
                        'role' => 'program-leader',
                        'name' => __('Program Leader', 'role-user-manager'),
                        'requires_approval' => $requires_approval
                    ];
                }
                break;
                
            case 'program-leader':
                // Only Administrators can promote Program Leaders to Data Viewer
                if (in_array('administrator', $requester_roles)) {
                    $available_promotions[] = [
                        'role' => 'data-viewer',
                        'name' => __('Data Viewer', 'role-user-manager'),
                        'requires_approval' => false
                    ];
                }
                break;
        }
        
        return $available_promotions;
    }
}

// Initialize the workflow system
new RoleAssignmentWorkflow(); 
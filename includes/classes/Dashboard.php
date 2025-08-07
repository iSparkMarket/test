<?php
declare(strict_types=1);

namespace RoleUserManager;

/**
 * Dashboard class
 */
class Dashboard {
    
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
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
        add_shortcode('plugin_dashboard', [$this, 'dashboard_shortcode']);
        
        // AJAX handlers
        add_action('wp_ajax_rum_get_user_details', [Ajax::class, 'get_user_details']);
        add_action('wp_ajax_rum_delete_user', [Ajax::class, 'delete_user']);
        add_action('wp_ajax_rum_get_sites_for_program', [Ajax::class, 'get_sites_for_program']);
        add_action('wp_ajax_rum_promote_user_direct', [Ajax::class, 'promote_user_direct']);
        add_action('wp_ajax_rum_submit_promotion_request', [Ajax::class, 'submit_promotion_request']);
    }
    
    /**
     * Enqueue frontend assets
     */
    public function enqueue_frontend_assets(): void {
        // Only load on pages with dashboard shortcode
        if (is_singular() && has_shortcode(get_post()->post_content, 'plugin_dashboard')) {
            Assets::enqueue_frontend_assets();
        }
    }
    
    /**
     * Dashboard shortcode
     */
    public function dashboard_shortcode(): string {
        if (!is_user_logged_in()) {
            return '<p>' . __('You must be logged in to view the dashboard.', 'role-user-manager') . '</p>';
        }
        
        // Get filter parameters
        $filter_site = Validator::sanitize_text($_GET['filter_site'] ?? '');
        $filter_program = Validator::sanitize_text($_GET['filter_program'] ?? '');
        $filter_status = Validator::sanitize_text($_GET['filter_training_status'] ?? '');
        $filter_date_from = Validator::sanitize_text($_GET['filter_date_from'] ?? '');
        $filter_date_to = Validator::sanitize_text($_GET['filter_date_to'] ?? '');
        
        // Get users based on filters
        $users = $this->get_filtered_users($filter_program, $filter_site, $filter_status, $filter_date_from, $filter_date_to);
        
        // Get filter options
        $programs = $this->get_programs();
        $sites = $this->get_sites_for_program($filter_program);
        
        // Debug information
        $debug_info = '';
        if (current_user_can('manage_options')) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'rum_program_sites';
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
            $table_count = $table_exists ? $wpdb->get_var("SELECT COUNT(*) FROM $table_name") : 0;
            
            // Get total users in system
            $total_users = count_users();
            $all_users = get_users(['number' => -1]);
            
            $debug_info = '<div class="debug-info" style="background: #f0f0f0; padding: 10px; margin: 10px 0; border: 1px solid #ccc;">';
            $debug_info .= '<strong>Debug Info:</strong><br>';
            $debug_info .= 'Total users in system: ' . $total_users['total_users'] . '<br>';
            $debug_info .= 'Total users found: ' . count($users) . '<br>';
            $debug_info .= 'Programs available: ' . count($programs) . '<br>';
            $debug_info .= 'Sites for selected program: ' . count($sites) . '<br>';
            $debug_info .= 'Filter program: ' . ($filter_program ?: 'none') . '<br>';
            $debug_info .= 'Filter site: ' . ($filter_site ?: 'none') . '<br>';
            $debug_info .= 'Filter status: ' . ($filter_status ?: 'none') . '<br>';
            $debug_info .= 'Database table exists: ' . ($table_exists ? 'Yes' : 'No') . '<br>';
            $debug_info .= 'Records in table: ' . $table_count . '<br>';
            
            // Show first few users for debugging
            $debug_info .= '<br><strong>First 5 users:</strong><br>';
            $count = 0;
            foreach ($all_users as $user) {
                if ($count >= 5) break;
                $user_program = get_user_meta($user->ID, 'program', true);
                $user_site = get_user_meta($user->ID, 'site', true);
                $debug_info .= "User {$user->ID}: {$user->display_name} - Program: " . ($user_program ?: 'none') . " - Site: " . ($user_site ?: 'none') . '<br>';
                $count++;
            }
            
            $debug_info .= '</div>';
        }
        
        ob_start();
        include plugin_dir_path(__FILE__) . '../../templates/dashboard.php';
        $content = ob_get_clean();

        return $debug_info . $content;
    }
    /**
     * Get filtered users
     */
    private function get_filtered_users(string $program = '', string $site = '', string $status = '', string $date_from = '', string $date_to = ''): array {
        $args = [
            'number' => -1,
            'orderby' => 'display_name',
            'order' => 'ASC',
        ];
        
        $users = get_users($args);
        $filtered_users = [];
        
        foreach ($users as $user) {
            if ($this->user_matches_filters($user, $program, $site, $status, $date_from, $date_to)) {
                $filtered_users[] = $user;
            }
        }
        
        return $filtered_users;
    }
    
    /**
     * Check if user matches filters
     */
    private function user_matches_filters($user, string $program, string $site, string $status, string $date_from, string $date_to): bool {
        // If no filters are applied, show all users
        if (empty($program) && empty($site) && empty($status) && empty($date_from) && empty($date_to)) {
            return true;
        }
        
        $user_program = get_user_meta($user->ID, 'program', true);
        $user_site = get_user_meta($user->ID, 'site', true);
        $user_status = get_user_meta($user->ID, 'training_status', true);
        $user_date = get_user_meta($user->ID, 'training_date', true);
        
        // Program filter
        if (!empty($program) && $user_program !== $program) {
            return false;
        }
        
        // Site filter
        if (!empty($site) && $user_site !== $site) {
            return false;
        }
        
        // Status filter
        if (!empty($status) && $user_status !== $status) {
            return false;
        }
        
        // Date range filter
        if (!empty($date_from) && !empty($date_to)) {
            if (empty($user_date) || $user_date < $date_from || $user_date > $date_to) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Get available programs
     */
    private function get_programs(): array {
        global $wpdb;
        $programs = $wpdb->get_col(
            "SELECT DISTINCT program FROM {$wpdb->prefix}rum_program_sites ORDER BY program"
        );
        
        // If no programs exist, add some sample data
        if (empty($programs) && current_user_can('manage_options')) {
            $sample_data = [
                ['program' => 'Healthcare', 'site' => 'Main Hospital'],
                ['program' => 'Healthcare', 'site' => 'North Clinic'],
                ['program' => 'Healthcare', 'site' => 'South Clinic'],
                ['program' => 'Education', 'site' => 'Central School'],
                ['program' => 'Education', 'site' => 'East Campus'],
                ['program' => 'Technology', 'site' => 'HQ Office'],
                ['program' => 'Technology', 'site' => 'Remote Office'],
            ];
            
            foreach ($sample_data as $data) {
                $wpdb->insert(
                    $wpdb->prefix . 'rum_program_sites',
                    $data,
                    ['%s', '%s']
                );
            }
            
            // Get programs again after inserting sample data
            $programs = $wpdb->get_col(
                "SELECT DISTINCT program FROM {$wpdb->prefix}rum_program_sites ORDER BY program"
            );
        }
        
        return $programs;
    }
    
    /**
     * Get sites for program
     */
    private function get_sites_for_program(string $program): array {
        if (empty($program)) {
            return [];
        }
        
        global $wpdb;
        return $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT site FROM {$wpdb->prefix}rum_program_sites WHERE program = %s ORDER BY site",
            $program
        ));
    }
    
    /**
     * Get user statistics
     */
    public function get_user_stats(int $user_id): array {
        $stats = [
            'courses_enrolled' => 0,
            'courses_completed' => 0,
            'assignments_submitted' => 0,
            'certificates_earned' => 0,
        ];
        
        // Get LearnDash data if available
        if (function_exists('learndash_get_user_course_list')) {
            $courses = learndash_get_user_course_list($user_id);
            $stats['courses_enrolled'] = is_array($courses) ? count($courses) : 0;
            
            foreach ($courses as $course) {
                if (
                    function_exists('learndash_course_completed') &&
                    isset($course['post']->ID) &&
                    learndash_course_completed($user_id, $course['post']->ID)
                ) {
                    $stats['courses_completed']++;
                }
            }
        }
        
        // Get assignment count
        $assignments = get_posts([
            'post_type' => 'sfwd-assignment',
            'author' => $user_id,
            'numberposts' => -1,
        ]);
        $stats['assignments_submitted'] = count($assignments);
        
        // Get certificate count
        $certificates = get_posts([
            'post_type' => 'sfwd-certificates',
            'author' => $user_id,
            'numberposts' => -1,
        ]);
        $stats['certificates_earned'] = count($certificates);
        
        return $stats;
    }
    
    /**
     * Get available promotions for user
     */
    public function get_available_promotions(int $user_id): array {
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
                $promotions[] = [
                    'role' => $promotion_role,
                    'display_name' => $this->get_role_display_name($promotion_role),
                ];
            }
        }
        
        return $promotions;
    }
    
    /**
     * Get role display name
     */
    public function get_role_display_name(string $role): string {
        $roles = wp_roles()->get_names();
        return $roles[$role] ?? ucwords(str_replace('-', ' ', $role));
    }
    
    /**
     * Get status display name
     */
    public function get_status_display_name(string $status): string {
        $status_names = [
            'not_started' => __('Not Started', 'role-user-manager'),
            'in_progress' => __('In Progress', 'role-user-manager'),
            'completed' => __('Completed', 'role-user-manager'),
            'failed' => __('Failed', 'role-user-manager'),
        ];
        
        return $status_names[$status] ?? __('Unknown', 'role-user-manager');
    }
} 
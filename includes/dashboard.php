<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

// Capture filter parameters from the URL (GET method)
$filter_site = sanitize_text_field($_GET['filter_site'] ?? '');
$filter_program = sanitize_text_field($_GET['filter_program'] ?? '');
$filter_status = sanitize_text_field($_GET['filter_training_status'] ?? '');
$filter_date_from = sanitize_text_field($_GET['filter_date_from'] ?? '');
$filter_date_to = sanitize_text_field($_GET['filter_date_to'] ?? '');

// Prepare date filtering if both from/to provided
$filter_date_query = [];
if ($filter_date_from && $filter_date_to) {
    $filter_date_query = [
        'key' => 'training_date',
        'value' => [$filter_date_from, $filter_date_to],
        'compare' => 'BETWEEN',
        'type' => 'DATE',
    ];
}

// Enqueue scripts and styles
function arc_enqueue_bootstrap_for_dashboard()
{
    // Check if we're on a page that might have the dashboard
    $should_load = false;

    if (is_singular()) {
        $post = get_post();
        if ($post && has_shortcode($post->post_content, 'plugin_dashboard')) {
            $should_load = true;
        }
    }

    // Also check if we're on a page with the dashboard shortcode in the URL or if it's explicitly requested
    if (isset($_GET['plugin_dashboard']) || (isset($_GET['filter_program']) || isset($_GET['filter_site']) || isset($_GET['filter_training_status']))) {
        $should_load = true;
    }

    if ($should_load) {
        wp_enqueue_style('bootstrap-css', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css');
        wp_enqueue_script('bootstrap-js', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js', ['jquery'], null, true);

        // Enqueue the dashboard admin.js file for frontend
        wp_enqueue_script('arc-dashboard-js', plugin_dir_url(__FILE__) . '../assets/js/admin.js', ['jquery'], '1.0', true);

        wp_localize_script('jquery', 'arc_dashboard_vars', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('arc_dashboard_nonce')
        ]);
        


        // Localize dashboard_ajax for admin.js
        wp_localize_script('arc-dashboard-js', 'dashboard_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('arc_dashboard_nonce'),
            'admin_url' => admin_url(),
        ]);
    }
}
add_action('wp_enqueue_scripts', 'arc_enqueue_bootstrap_for_dashboard');

// Helper function: Get all descendant user IDs for a given user
function arc_get_descendant_user_ids(int $parent_id, array $all_users, int $depth = 0): array
{
    // Prevent infinite recursion
    if ($depth > 10) {
        return [];
    }

    $descendants = [];
    foreach ($all_users as $user) {
        $user_parent = get_user_meta($user->ID, 'parent_user_id', true);
        if ($user_parent && intval($user_parent) === intval($parent_id)) {
            $descendants[] = $user->ID;
            // Recursively get this child's descendants
            $descendants = array_merge($descendants, arc_get_descendant_user_ids($user->ID, $all_users, $depth + 1));
        }
    }
    return $descendants;
}

// Helper function: Get available programs and sites for filter dropdowns
function arc_get_filter_options($program_filter = '')
{
    global $wpdb;

    $programs = $wpdb->get_col("
        SELECT DISTINCT meta_value 
        FROM {$wpdb->usermeta} 
        WHERE meta_key = 'programme' 
        AND meta_value != '' 
        ORDER BY meta_value ASC
    ");

    // Get sites based on program filter
    if (!empty($program_filter)) {
        // Get sites for specific program
        $sites_query = "
            SELECT DISTINCT um_sites.meta_value 
            FROM {$wpdb->usermeta} um_program
            JOIN {$wpdb->usermeta} um_sites ON um_program.user_id = um_sites.user_id
            WHERE um_program.meta_key = 'programme' 
            AND um_program.meta_value = %s
            AND um_sites.meta_key = 'sites' 
            AND um_sites.meta_value != ''
        ";
        $users_with_sites = $wpdb->get_results($wpdb->prepare($sites_query, $program_filter));
    } else {
        // Get all sites
        $users_with_sites = $wpdb->get_results("
            SELECT user_id, meta_value 
            FROM {$wpdb->usermeta} 
            WHERE meta_key = 'sites' 
            AND meta_value != ''
        ");
    }

    // Flatten sites array (since it's stored as serialized array)
    $flattened_sites = [];
    foreach ($users_with_sites as $user_site) {
        $site_array = maybe_unserialize($user_site->meta_value);
        if (is_array($site_array)) {
            $flattened_sites = array_merge($flattened_sites, $site_array);
        } else {
            $flattened_sites[] = $user_site->meta_value;
        }
    }
    $flattened_sites = array_unique($flattened_sites);
    sort($flattened_sites);

    return [
        'programs' => $programs,
        'sites' => $flattened_sites
    ];
}

// Helper function: Get sites for a specific program (AJAX handler)
function arc_get_sites_for_program($program)
{
    global $wpdb;
    
    if (empty($program)) {
        return [];
    }
    
    $sites_query = "
        SELECT DISTINCT um_sites.meta_value 
        FROM {$wpdb->usermeta} um_program
        JOIN {$wpdb->usermeta} um_sites ON um_program.user_id = um_sites.user_id
        WHERE um_program.meta_key = 'programme' 
        AND um_program.meta_value = %s
        AND um_sites.meta_key = 'sites' 
        AND um_sites.meta_value != ''
    ";
    
    $users_with_sites = $wpdb->get_results($wpdb->prepare($sites_query, $program));
    
    // Flatten sites array
    $flattened_sites = [];
    foreach ($users_with_sites as $user_site) {
        $site_array = maybe_unserialize($user_site->meta_value);
        if (is_array($site_array)) {
            $flattened_sites = array_merge($flattened_sites, $site_array);
        } else {
            $flattened_sites[] = $user_site->meta_value;
        }
    }
    
    $flattened_sites = array_unique($flattened_sites);
    sort($flattened_sites);
    
    return $flattened_sites;
}

// Helper function: Check if user meets training status criteria
function arc_check_training_status($user_id, $training_status)
{
    if (empty($training_status)) {
        return true;
    }

    if (!function_exists('learndash_user_get_enrolled_courses')) {
        return true; // If LearnDash not available, don't filter
    }

    $enrolled_courses = learndash_user_get_enrolled_courses($user_id);
    $completed_courses = 0;
    $in_progress_courses = 0;
    $not_started_courses = 0;

    foreach ($enrolled_courses as $course_id) {
        if (function_exists('learndash_course_completed') && learndash_course_completed($user_id, $course_id)) {
            $completed_courses++;
        } elseif (function_exists('learndash_course_progress')) {
            $progress = learndash_course_progress($user_id, $course_id);
            if (isset($progress['percentage']) && $progress['percentage'] > 0) {
                $in_progress_courses++;
            } else {
                $not_started_courses++;
            }
        } else {
            $not_started_courses++;
        }
    }

    switch ($training_status) {
        case 'completed':
            return $completed_courses > 0;
        case 'in_progress':
            return $in_progress_courses > 0;
        case 'not_started':
            return $not_started_courses > 0;
        case 'has_courses':
            return count($enrolled_courses) > 0;
        case 'no_courses':
            return count($enrolled_courses) === 0;
        default:
            return true;
    }
}

// Helper function: Check if user meets date range criteria
function arc_check_date_range($user_id, $date_start, $date_end)
{
    if (empty($date_start) && empty($date_end)) {
        return true;
    }

    $user_registered = get_userdata($user_id)->user_registered;
    $user_date = strtotime($user_registered);

    if (!empty($date_start)) {
        $start_date = strtotime($date_start);
        if ($user_date < $start_date) {
            return false;
        }
    }

    if (!empty($date_end)) {
        $end_date = strtotime($date_end . ' 23:59:59');
        if ($user_date > $end_date) {
            return false;
        }
    }

    return true;
}

// Register shortcode
add_shortcode('plugin_dashboard', 'plugin_dashboard_shortcode');

/**
 * Shortcode handler for the plugin dashboard UI.
 *
 * @return string HTML output
 */
function plugin_dashboard_shortcode(): string
{
    // Ensure required roles exist
    $required_roles = [
        'data-viewer' => __('Data Viewer', 'role-user-manager'),
        'program-leader' => __('Program Leader', 'role-user-manager'),
        'site-supervisor' => __('Site Supervisor', 'role-user-manager'),
        'frontline-staff' => __('Frontline Staff', 'role-user-manager'),
    ];
    foreach ($required_roles as $role_key => $role_name) {
        if (!get_role($role_key)) {
            add_role($role_key, $role_name, ['read' => true]);
        }
        
        // Add group_leader capability to specific roles for certificate access
        if (in_array($role_key, ['data-viewer', 'program-leader', 'site-supervisor'])) {
            $role = get_role($role_key);
            if ($role && !$role->has_cap('group_leader')) {
                $role->add_cap('group_leader');
            }
        }
    }
    // Set up default parent/child relationships if not already set
    $hierarchy = get_option('arc_role_hierarchy', []);
    $changed = false;
    if (!isset($hierarchy['site-supervisor']) || $hierarchy['site-supervisor'] !== 'program-leader') {
        $hierarchy['site-supervisor'] = 'program-leader';
        $changed = true;
    }
    if (!isset($hierarchy['frontline-staff']) || $hierarchy['frontline-staff'] !== 'site-supervisor') {
        $hierarchy['frontline-staff'] = 'site-supervisor';
        $changed = true;
    }
    if ($changed) {
        update_option('arc_role_hierarchy', $hierarchy);
    }

    if (!is_user_logged_in()) {
        return 'You must be logged in to access this page. <a href="' . wp_login_url() . '">Login here</a>';
    }

    $current_user = wp_get_current_user();
    $user_name = $current_user->display_name ?: 'Demo User';
    $user_roles = array_map('strtolower', $current_user->roles);
    $user_role = ucfirst(implode(', ', $current_user->roles)) ?: 'User';

    // --- Get filter parameters ---
    $per_page = 20;
    $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $filter_program = isset($_GET['filter_program']) ? sanitize_text_field($_GET['filter_program']) : '';
    $filter_site = isset($_GET['filter_site']) ? sanitize_text_field($_GET['filter_site']) : '';
    $filter_training_status = isset($_GET['filter_training_status']) ? sanitize_text_field($_GET['filter_training_status']) : '';
    $filter_date_start = isset($_GET['filter_date_start']) ? sanitize_text_field($_GET['filter_date_start']) : '';
    $filter_date_end = isset($_GET['filter_date_end']) ? sanitize_text_field($_GET['filter_date_end']) : '';

    // Get filter options
    $filter_options = arc_get_filter_options();

    // Only show all users if admin or data-viewer
    if (in_array('administrator', $user_roles) || in_array('data-viewer', $user_roles)) {
        // Get all users first for filtering
        $all_users = get_users(['orderby' => 'display_name', 'order' => 'ASC', 'fields' => 'all']);

        // Apply filters
        $filtered_users = array_filter($all_users, function ($user) use ($filter_program, $filter_site, $filter_training_status, $filter_date_start, $filter_date_end) {
            // Filter by program
            if (!empty($filter_program)) {
                $user_program = get_user_meta($user->ID, 'programme', true);
                if ($user_program !== $filter_program) {
                    return false;
                }
            }

            // Filter by site
            if (!empty($filter_site)) {
                $user_sites = get_user_meta($user->ID, 'sites', true);
                if (!is_array($user_sites)) {
                    $user_sites = [];
                }
                // Check if the filter site is in the user's sites array
                if (!in_array($filter_site, $user_sites)) {
                    return false;
                }
            }

            // Filter by training status
            if (!empty($filter_training_status)) {
                if (!arc_check_training_status($user->ID, $filter_training_status)) {
                    return false;
                }
            }

            // Filter by date range
            if (!arc_check_date_range($user->ID, $filter_date_start, $filter_date_end)) {
                return false;
            }

            return true;
        });

        $total_users = count($filtered_users);
        $visible_users = array_slice(array_values($filtered_users), ($paged - 1) * $per_page, $per_page);


    } else {
        // For non-admins, get all users and filter descendants, then apply filters
        $all_users = get_users(['orderby' => 'display_name', 'order' => 'ASC', 'fields' => 'all']);
        $descendant_ids = arc_get_descendant_user_ids($current_user->ID, $all_users);
        $descendant_users = array_filter($all_users, function ($user) use ($descendant_ids) {
            return in_array($user->ID, $descendant_ids);
        });

        // Apply filters to descendants
        $filtered_users = array_filter($descendant_users, function ($user) use ($filter_program, $filter_site, $filter_training_status, $filter_date_start, $filter_date_end) {
            // Filter by program
            if (!empty($filter_program)) {
                $user_program = get_user_meta($user->ID, 'programme', true);
                if ($user_program !== $filter_program) {
                    return false;
                }
            }

            // Filter by site
            if (!empty($filter_site)) {
                $user_sites = get_user_meta($user->ID, 'sites', true);
                if (!is_array($user_sites)) {
                    $user_sites = [];
                }
                // Check if the filter site is in the user's sites array
                if (!in_array($filter_site, $user_sites)) {
                    return false;
                }
            }

            // Filter by training status
            if (!empty($filter_training_status)) {
                if (!arc_check_training_status($user->ID, $filter_training_status)) {
                    return false;
                }
            }

            // Filter by date range
            if (!arc_check_date_range($user->ID, $filter_date_start, $filter_date_end)) {
                return false;
            }

            return true;
        });

        $total_users = count($filtered_users);
        $visible_users = array_slice(array_values($filtered_users), ($paged - 1) * $per_page, $per_page);


    }

    $children_users = [];
    foreach ($visible_users as $user) {
        $parent_id = get_user_meta($user->ID, 'parent_user_id', true);
        $program = get_user_meta($user->ID, 'programme', true);
        $site = get_user_meta($user->ID, 'sites', true);
        if (!is_array($site))
            $site = [];
        $site_display = !empty($site) ? implode(', ', array_map('trim', $site)) : '—';
        $parent_name = $parent_id ? get_user_by('id', $parent_id)->display_name : '—';

        // Get LearnDash stats with error handling
        $total_courses = 0;
        $total_certificates = 0;
        if (function_exists('learndash_get_user_stats')) {
            $ld_stats = learndash_get_user_stats($user->ID);
            $total_courses = isset($ld_stats['courses']) ? intval($ld_stats['courses']) : 0;
            $total_certificates = isset($ld_stats['certificates']) ? intval($ld_stats['certificates']) : 0;
        }

        $children_users[] = [
            'id' => $user->ID,
            'name' => $user->display_name,
            'role' => implode(', ', $user->roles),
            'parent' => $parent_name,
            'program' => $program ?: '—',
            'site' => $site_display,
            'total_courses' => $total_courses,
            'total_certificates' => $total_certificates,
            'total_hours' => 0,
            'profile_image' => 'https://via.placeholder.com/80x80/007cba/ffffff?text=' . strtoupper(substr($user->display_name, 0, 1)),
            'courses' => []
        ];
    }

    // --- Pagination controls ---
    $total_pages = ceil($total_users / $per_page);

    // Build filter query string for pagination
    $filter_params = [];
    if (!empty($filter_program))
        $filter_params['filter_program'] = $filter_program;
    if (!empty($filter_site))
        $filter_params['filter_site'] = $filter_site;
    if (!empty($filter_training_status))
        $filter_params['filter_training_status'] = $filter_training_status;
    if (!empty($filter_date_start))
        $filter_params['filter_date_start'] = $filter_date_start;
    if (!empty($filter_date_end))
        $filter_params['filter_date_end'] = $filter_date_end;

    $base_url = add_query_arg($filter_params, remove_query_arg(['paged']));
    $search_query = '';

    ob_start();
    ?>
    <div id="plugin-dashboard">
        <form method="get" id="bulk-action-form">
          
            <div class="dashboard-header">
                <h2><?php esc_html_e('Plugin Dashboard', 'role-user-manager'); ?></h2>
                <p><?php printf(__('Welcome, %s (%s)', 'role-user-manager'), esc_html($user_name), esc_html($user_role)); ?>
                </p>
            </div>
  <!-- Filter Controls -->
            <div class="filter-controls"
                style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
                <h4 style="margin-bottom: 15px;"><?php esc_html_e('Filter Users', 'role-user-manager'); ?></h4>
                <div class="row">
                    <div class="col-md-3">
                        <label for="filter_program"
                            class="form-label"><?php esc_html_e('Program', 'role-user-manager'); ?></label>
                        <select name="filter_program" id="filter_program" class="form-select">
                            <option value=""><?php esc_html_e('All Programs', 'role-user-manager'); ?></option>
                            <?php foreach ($filter_options['programs'] as $program): ?>
                                <option value="<?php echo esc_attr($program); ?>" <?php selected($filter_program, $program); ?>>
                                    <?php echo esc_html($program); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="filter_site"
                            class="form-label"><?php esc_html_e('Site', 'role-user-manager'); ?></label>
                        <select name="filter_site" id="filter_site" class="form-select">
                            <option value=""><?php esc_html_e('All Sites', 'role-user-manager'); ?></option>
                            <?php 
                            // If a program is selected, get sites for that program
                            if (!empty($filter_program)) {
                                $program_sites = arc_get_sites_for_program($filter_program);
                                foreach ($program_sites as $site): ?>
                                    <option value="<?php echo esc_attr($site); ?>" <?php selected($filter_site, $site); ?>>
                                        <?php echo esc_html($site); ?>
                                    </option>
                                <?php endforeach;
                            } else {
                                // Show all sites if no program is selected
                                foreach ($filter_options['sites'] as $site): ?>
                                    <option value="<?php echo esc_attr($site); ?>" <?php selected($filter_site, $site); ?>>
                                        <?php echo esc_html($site); ?>
                                    </option>
                                <?php endforeach;
                            }
                            ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="filter_training_status"
                            class="form-label"><?php esc_html_e('Training Status', 'role-user-manager'); ?></label>
                        <select name="filter_training_status" id="filter_training_status" class="form-select">
                            <option value=""><?php esc_html_e('All Statuses', 'role-user-manager'); ?></option>
                            <option value="completed" <?php selected($filter_training_status, 'completed'); ?>>
                                <?php esc_html_e('Completed Courses', 'role-user-manager'); ?></option>
                            <option value="in_progress" <?php selected($filter_training_status, 'in_progress'); ?>>
                                <?php esc_html_e('In Progress', 'role-user-manager'); ?></option>
                            <option value="not_started" <?php selected($filter_training_status, 'not_started'); ?>>
                                <?php esc_html_e('Not Started', 'role-user-manager'); ?></option>
                            <option value="has_courses" <?php selected($filter_training_status, 'has_courses'); ?>>
                                <?php esc_html_e('Has Courses', 'role-user-manager'); ?></option>
                            <option value="no_courses" <?php selected($filter_training_status, 'no_courses'); ?>>
                                <?php esc_html_e('No Courses', 'role-user-manager'); ?></option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="filter_date_start"
                            class="form-label"><?php esc_html_e('Date Range', 'role-user-manager'); ?></label>
                        <div class="row">
                            <div class="col-6">
                                <input type="date" name="filter_date_start" id="filter_date_start" class="form-control"
                                    value="<?php echo esc_attr($filter_date_start); ?>"
                                    placeholder="<?php esc_attr_e('Start Date', 'role-user-manager'); ?>">
                            </div>
                            <div class="col-6">
                                <input type="date" name="filter_date_end" id="filter_date_end" class="form-control"
                                    value="<?php echo esc_attr($filter_date_end); ?>"
                                    placeholder="<?php esc_attr_e('End Date', 'role-user-manager'); ?>">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row mt-3">
                    <div class="col-12">
                        <label for="user_search" class="form-label"><?php esc_html_e('Search Users', 'role-user-manager'); ?></label>
                        <div class="search-container">
                            <input type="text" id="user_search" name="user_search" class="form-control" 
                                placeholder="<?php esc_attr_e('Search by name, role, parent, program, or site...', 'role-user-manager'); ?>"
                                autocomplete="off">
                            <button type="button" class="clear-search" title="<?php esc_attr_e('Clear search', 'role-user-manager'); ?>" tabindex="-1">×</button>
                        </div>
                        <small class="form-text text-muted">
                            <?php esc_html_e('Use keywords to filter the user list in real-time. Press Escape to clear.', 'role-user-manager'); ?>
                        </small>
                    </div>
                </div>
                <div class="row mt-3">
                    <div class="col-12">
                        <button type="submit"
                            class="btn btn-primary"><?php esc_html_e('Apply Filters', 'role-user-manager'); ?></button>
                        <a href="<?php echo esc_url(remove_query_arg(['filter_program', 'filter_site', 'filter_training_status', 'filter_date_start', 'filter_date_end', 'paged'])); ?>"
                            class="btn btn-secondary"><?php esc_html_e('Clear Filters', 'role-user-manager'); ?></a>
                        <?php if (in_array('administrator', $user_roles) || in_array('program-leader', $user_roles)): ?>
                            <button type="button" id="export-users-btn" class="btn btn-success ms-2">
                                📥 <?php esc_html_e('Export Users', 'role-user-manager'); ?>
                            </button>
                        <?php endif; ?>
                        <span
                            class="ms-3 text-muted"><?php printf(__('Showing %d of %d users', 'role-user-manager'), count($children_users), $total_users); ?></span>
                        <?php if (!empty($filter_program) || !empty($filter_site) || !empty($filter_training_status) || !empty($filter_date_start) || !empty($filter_date_end)): ?>
                            <div class="mt-2">
                                <small class="text-info">
                                    <?php esc_html_e('Active filters:', 'role-user-manager'); ?>
                                    <?php if (!empty($filter_program)): ?>Program:
                                        <?php echo esc_html($filter_program); ?>        <?php endif; ?>
                                    <?php if (!empty($filter_site)): ?>Site:
                                        <?php echo esc_html($filter_site); ?>        <?php endif; ?>
                                    <?php if (!empty($filter_training_status)): ?>Status:
                                        <?php echo esc_html($filter_training_status); ?>        <?php endif; ?>
                                    <?php if (!empty($filter_date_start) || !empty($filter_date_end)): ?>Date:
                                        <?php echo esc_html($filter_date_start); ?> -
                                        <?php echo esc_html($filter_date_end); ?>        <?php endif; ?>
                                </small>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div style="display: flex; gap: 10px; align-items: center; margin-bottom: 10px;">
                <!-- Removed search and role assignment UI -->
            </div>
            <style>
                .dashboard-header {
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    color: white;
                    padding: 20px;
                    border-radius: 8px;
                    margin-bottom: 20px;
                    text-align: center;
                }

                .dashboard-main {
                    display: flex;
                    gap: 20px;
                }

                /* .dashboard-left {
                                flex: 0 0 70%;
                            } */

                .dashboard-right {
                    flex: 0 0 30%;
                }

                .users-table {
                    background: white;
                    border-radius: 8px;
                    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
                    margin-bottom: 30px;
                    overflow-x: auto;
                }

                .users-table h3 {
                    background: #f8f9fa;
                    padding: 15px;
                    margin: 0;
                    border-bottom: 1px solid #dee2e6;
                }

                .users-table table {
                    width: 100%;
                    border-collapse: collapse;
                }

                .users-table th,
                .users-table td {
                    padding: 10px;
                    text-align: left;
                    border-bottom: 1px solid #ddd;
                    font-size: 14px;
                }

                .users-table th {
                    background: #f1f1f1;
                }

                .btn {
                    padding: 5px 10px;
                    font-size: 12px;
                    border-radius: 4px;
                    color: white;
                    border: none;
                    cursor: pointer;
                }

                .btn:hover {
                    background-color: #0073aa;
                    color: white !important;
                }

                .btn-edit {
                    background: #0073aa;
                }

                .btn-remove, .btn-outline-primary, .btn-outline-secondary {
                    background: rgb(53, 98, 220);
                }

                .btn-view {
                    background: #6c757d;
                }

                .btn-certificate {
                    background: #28a745;
                }

                .btn-certificate:hover {
                    background-color: #218838;
                    color: white !important;
                }

                .user-card {
                    background: white;
                    padding: 20px;
                    border-radius: 8px;
                    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
                }

                .user-profile {
                    display: flex;
                    align-items: center;
                    gap: 15px;
                    margin-bottom: 10px;
                }

                .user-profile img {
                    width: 60px;
                    height: 60px;
                    border-radius: 50%;
                }

                .user-details p {
                    margin: 4px 0;
                }

                @media (max-width: 768px) {
                    .dashboard-main {
                        flex-direction: column;
                    }

                    .dashboard-left,
                    .dashboard-right {
                        flex: 1 0 100%;
                    }
                }

                .pagination-bar {
                    display: flex;
                    gap: 8px;
                    justify-content: flex-end;
                    margin-bottom: 10px;
                }

                .pagination-bar a,
                .pagination-bar span {
                    padding: 4px 10px;
                    border-radius: 4px;
                    border: 1px solid #ccc;
                    background: #f8f9fa;
                    color: #333;
                    text-decoration: none;
                }

                .pagination-bar .current {
                    background: #2271b1;
                    color: #fff;
                    border-color: #2271b1;
                }

                .bulk-checkbox {
                    width: 16px;
                    height: 16px;
                }

                .modal-backdrop.fade.show {
                    display: none !important;
                }

                .action-btn {
                    width: 170px;
                }

                div#userEditModal {
                    background: rgb(0 0 0 / 60%);
                }

                .modal-dialog {
                    margin: 10% auto;
                }

                .filter-controls {
                    background: #f8f9fa;
                    padding: 20px;
                    border-radius: 8px;
                    margin-bottom: 20px;
                    border: 1px solid #dee2e6;
                }

                .filter-controls h4 {
                    color: #495057;
                    margin-bottom: 15px;
                    font-size: 1.1rem;
                }

                .filter-controls .form-label {
                    font-weight: 500;
                    color: #495057;
                    margin-bottom: 5px;
                }

                .filter-controls .form-select,
                .filter-controls .form-control {
                    border: 1px solid #ced4da;
                    border-radius: 4px;
                    padding: 8px 12px;
                    font-size: 14px;
                }

                .filter-controls .form-select:focus,
                .filter-controls .form-control:focus {
                    border-color: #80bdff;
                    outline: 0;
                    box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
                }

                .filter-controls .btn {
                    padding: 8px 16px;
                    font-size: 14px;
                    border-radius: 4px;
                }

                .filter-controls .btn-primary {
                    background-color: #007bff;
                    border-color: #007bff;
                }

                .filter-controls .btn-primary:hover {
                    background-color: #0056b3;
                    border-color: #0056b3;
                }

                .filter-controls .btn-secondary {
                    background-color: #6c757d;
                    border-color: #6c757d;
                }

                .filter-controls .btn-secondary:hover {
                    background-color: #545b62;
                    border-color: #545b62;
                }

                .filter-controls .btn-success {
                    background-color: #28a745;
                    border-color: #28a745;
                }

                .filter-controls .btn-success:hover {
                    background-color: #218838;
                    border-color: #1e7e34;
                }

                .filter-controls .text-muted {
                    font-size: 14px;
                }

                .filter-controls .row {
                    margin-bottom: 15px;
                }

                .filter-controls .col-md-3 {
                    margin-bottom: 10px;
                }

                @media (max-width: 768px) {
                                    .filter-controls .col-md-3 {
                    margin-bottom: 15px;
                }
            }
            
            /* Loading overlay styles */
            .loading-overlay {
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(255,255,255,0.8);
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 1000;
            }
            
            .users-table {
                position: relative;
            }
            
            #users-table-container {
                position: relative;
            }
            
            /* Search functionality styles */
            #user_search {
                position: relative;
                background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%236c757d' viewBox='0 0 16 16'%3E%3Cpath d='M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001c.03.04.062.078.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1.007 1.007 0 0 0-.115-.1zM12 6.5a5.5 5.5 0 1 1-11 0 5.5 5.5 0 0 1 11 0z'/%3E%3C/svg%3E");
                background-repeat: no-repeat;
                background-position: 12px center;
                background-size: 16px;
                padding-left: 40px;
                border: 2px solid #dee2e6;
                border-radius: 8px;
                transition: all 0.3s ease;
            }
            
            #user_search:focus {
                border-color: #007bff;
                box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
                outline: 0;
            }
            
            #user_search::placeholder {
                color: #6c757d;
                font-style: italic;
            }
            
            #search-results-info {
                border-left: 4px solid #17a2b8;
                background-color: #d1ecf1;
                border-color: #bee5eb;
                animation: slideInDown 0.3s ease-out;
            }
            
            @keyframes slideInDown {
                from {
                    opacity: 0;
                    transform: translateY(-10px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }
            
            /* Hide search results smoothly */
            .users-table tbody tr {
                transition: opacity 0.2s ease;
            }
            
            .users-table tbody tr[style*="display: none"] {
                opacity: 0;
            }
            
            /* Search highlight styles */
            .search-highlight {
                background-color: #fff3cd;
                border-radius: 3px;
                padding: 1px 3px;
                font-weight: 500;
            }
            
            /* Improved search input container */
            .search-container {
                position: relative;
                display: inline-block;
                width: 100%;
            }
            
            .search-container .clear-search {
                position: absolute;
                right: 10px;
                top: 50%;
                transform: translateY(-50%);
                background: none;
                border: none;
                color: #6c757d;
                cursor: pointer;
                font-size: 18px;
                padding: 0;
                width: 20px;
                height: 20px;
                border-radius: 50%;
                display: none;
                align-items: center;
                justify-content: center;
            }
            
            .search-container .clear-search:hover {
                background-color: #f8f9fa;
                color: #495057;
            }
            
            .search-container.has-content .clear-search {
                display: flex;
            }
        </style>

           

            <div class="dashboard-main">
                <div class="dashboard-left">
                    <!-- WordPress Users Table -->
                    <div class="users-table" style="position: relative;">
                        <h3><?php esc_html_e('Registered WordPress Users', 'role-user-manager'); ?></h3>
                        <div id="users-table-container">
                            <table role="table"
                                aria-label="<?php esc_attr_e('Registered WordPress Users', 'role-user-manager'); ?>">
                                <thead>
                                    <tr>
                                        <th><input type="checkbox" id="bulk-select-all" class="bulk-checkbox"></th>
                                        <th><?php esc_html_e('Name', 'role-user-manager'); ?></th>
                                        <th><?php esc_html_e('Role', 'role-user-manager'); ?></th>
                                        <th><?php esc_html_e('Parent', 'role-user-manager'); ?></th>
                                        <th><?php esc_html_e('Program', 'role-user-manager'); ?></th>
                                        <th><?php esc_html_e('Site', 'role-user-manager'); ?></th>
                                        <th><?php esc_html_e('Total Courses', 'role-user-manager'); ?></th>
                                        <th><?php esc_html_e('Total Certificates', 'role-user-manager'); ?></th>
                                        <th><?php esc_html_e('Actions', 'role-user-manager'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($children_users as $user): ?>
                                        <tr>
                                            <td><input type="checkbox" name="bulk_users[]"
                                                    value="<?php echo esc_attr($user['id']); ?>" class="bulk-checkbox"></td>
                                            <td><?php echo esc_html($user['name']); ?></td>
                                            <td><?php echo esc_html($user['role']); ?></td>
                                            <td><?php echo esc_html($user['parent']); ?></td>
                                            <td><?php echo esc_html($user['program']); ?></td>
                                            <td><?php echo esc_html($user['site']); ?></td>
                                            <td><?php echo esc_html($user['total_courses']); ?></td>
                                            <td><?php echo esc_html($user['total_certificates']); ?></td>
                                            <td class="action-btn">
                                                <div class="user-actions">
                                                    <?php
                                                    // Show appropriate buttons based on user role
                                                    if (in_array('data-viewer', $user_roles)) {
                                                        // Data viewers can only view
                                                        ?>
                                                        <button type="button" class="btn btn-view"
                                                            data-user-id="<?php echo intval($user['id']); ?>"><?php esc_html_e('View', 'role-user-manager'); ?></button>
                                                        <?php
                                                    } else {
                                                        // Other roles can edit and remove
                                                    ?>
                                                    <button type="button" class="btn btn-edit"
                                                            data-user-id="<?php echo intval($user['id']); ?>"><?php esc_html_e('Edit', 'role-user-manager'); ?></button>
                                                    <button type="button" class="btn btn-remove"
                                                            data-user-id="<?php echo intval($user['id']); ?>"><?php esc_html_e('Remove', 'role-user-manager'); ?></button>
                                                    <?php
                                                    }
                                                   
                                                    // Add promotion button if user can promote this user
                                                    $workflow = new RoleAssignmentWorkflow();
                                                    $available_promotions = $workflow->get_available_promotions_for_user($user['id']);
                                                    
                                                    // Only show promotion buttons if promotions are available
                                                    if (!empty($available_promotions)) {
                                                        foreach ($available_promotions as $promotion) {
                                                            $button_class = $promotion['requires_approval'] ? 'btn-promote-request' : 'btn-promote-direct';
                                                            $button_text = $promotion['requires_approval'] ? 
                                                                sprintf(__('Request %s', 'role-user-manager'), $promotion['name']) : 
                                                                sprintf(__('Promote to %s', 'role-user-manager'), $promotion['name']);
                                                            ?>
                                                            <button type="button" class="btn <?php echo esc_attr($button_class); ?>"
                                                                    data-user-id="<?php echo intval($user['id']); ?>"
                                                                    data-requested-role="<?php echo esc_attr($promotion['role']); ?>"
                                                                    data-requires-approval="<?php echo $promotion['requires_approval'] ? 'true' : 'false'; ?>"
                                                                    data-promotion-name="<?php echo esc_attr($promotion['name']); ?>">
                                                                <?php echo esc_html($button_text); ?>
                                                            </button>
                                                            <?php
                                                        }
                                                    }
                                                    ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <!-- Pagination moved below the table -->
                        <div class="pagination-bar">
                            <?php if ($paged > 1): ?>
                                <a
                                    href="<?php echo esc_url(add_query_arg(['paged' => $paged - 1], $base_url)); ?>"><?php esc_html_e('&laquo; Prev', 'role-user-manager'); ?></a>
                            <?php endif; ?>
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <?php if ($i == $paged): ?>
                                    <span class="current"><?php echo $i; ?></span>
                                <?php else: ?>
                                    <a href="<?php echo esc_url(add_query_arg(['paged' => $i], $base_url)); ?>"><?php echo $i; ?></a>
                                <?php endif; ?>
                            <?php endfor; ?>
                            <?php if ($paged < $total_pages): ?>
                                <a
                                    href="<?php echo esc_url(add_query_arg(['paged' => $paged + 1], $base_url)); ?>"><?php esc_html_e('Next &raquo;', 'role-user-manager'); ?></a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <!-- Removed dashboard-right section -->
            </div>
        </form>

        <!-- Bootstrap Modal -->
        <div class="modal fade" id="userEditModal" tabindex="-1" aria-labelledby="userEditModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="userEditModalLabel">
                            <?php echo in_array('data-viewer', $user_roles) ? __('View User', 'role-user-manager') : __('Edit User', 'role-user-manager'); ?>
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"
                            aria-label="<?php esc_attr_e('Close', 'role-user-manager'); ?>"></button>
                    </div>
                    <div class="modal-body">
                        <div id="user-modal-content"></div>
                    </div>
                </div>
            </div>
        </div>

        <script>
            // Debug: Check if Bootstrap is available
            console.log('Bootstrap available:', typeof bootstrap !== 'undefined');
            console.log('jQuery available:', typeof jQuery !== 'undefined');
            
            function openUserEditModal(userId) {
                console.log('Opening modal for user ID:', userId);
                console.log('AJAX URL:', arc_dashboard_vars.ajaxurl);
                console.log('Nonce:', arc_dashboard_vars.nonce);

                jQuery('#user-modal-content').html('<div class="text-center"><div class="spinner-border" role="status"><span class="visually-hidden"><?php esc_html_e('Loading...', 'role-user-manager'); ?></span></div><p><?php esc_html_e('Loading user data...', 'role-user-manager'); ?></p></div>');
                
                // Check if Bootstrap is available
                if (typeof bootstrap !== 'undefined') {
                    console.log('Bootstrap available, creating modal');
                var userEditModal = new bootstrap.Modal(document.getElementById('userEditModal'));
                userEditModal.show();
                } else {
                    console.log('Bootstrap not available, showing modal manually');
                    jQuery('#userEditModal').show();
                }

                jQuery.post(arc_dashboard_vars.ajaxurl, {
                    action: 'arc_get_user_ld_data',
                    user_id: userId,
                    _wpnonce: arc_dashboard_vars.nonce
                }, function (response) {
                    console.log('AJAX response:', response);

                    if (response.success) {
                        jQuery('#user-modal-content').html(response.data.html);
                    } else {
                        var msg = (response.data && response.data.message) ? response.data.message : '<?php esc_html_e('Unknown error', 'role-user-manager'); ?>';
                        jQuery('#user-modal-content').html('<div class="alert alert-danger"><?php esc_html_e('Error loading user data:', 'role-user-manager'); ?> ' + msg + '</div>');
                    }
                }).fail(function (jqXHR, textStatus, errorThrown) {
                    console.log('AJAX error:', textStatus, errorThrown);
                    console.log('Response:', jqXHR.responseText);
                    console.log('Status:', jqXHR.status);
                    jQuery('#user-modal-content').html('<div class="alert alert-danger"><?php esc_html_e('Error:', 'role-user-manager'); ?>                     <?php esc_html_e('Could not connect to server. Status:', 'role-user-manager'); ?> ' + textStatus + '</div>');
                });
            }

            function removeUserFromCourse(userId, courseId) {
                if (!confirm('<?php esc_html_e('Are you sure you want to remove this user from the course?', 'role-user-manager'); ?>')) return;

                console.log('Removing user', userId, 'from course', courseId);

                jQuery.post(arc_dashboard_vars.ajaxurl, {
                    action: 'arc_remove_user_from_course',
                    user_id: userId,
                    course_id: courseId,
                    _wpnonce: arc_dashboard_vars.nonce
                }, function (response) {
                    console.log('Remove course response:', response);

                    if (response.success) {
                        // Show success message
                        jQuery('#user-modal-content').prepend('<div class="alert alert-success alert-dismissible fade show" role="alert"><?php esc_html_e('User removed from course successfully!', 'role-user-manager'); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>');
                        // Reload modal content
                        openUserEditModal(userId);
                    } else {
                        var msg = (response.data && response.data.message) ? response.data.message : '<?php esc_html_e('Unknown error', 'role-user-manager'); ?>';
                        jQuery('#user-modal-content').prepend('<div class="alert alert-danger alert-dismissible fade show" role="alert"><?php esc_html_e('Error:', 'role-user-manager'); ?> ' + msg + '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>');
                    }
                }).fail(function (jqXHR, textStatus, errorThrown) {
                    console.log('Remove course AJAX error:', textStatus, errorThrown);
                    jQuery('#user-modal-content').prepend('<div class="alert alert-danger alert-dismissible fade show" role="alert"><?php esc_html_e('Error:', 'role-user-manager'); ?>                     <?php esc_html_e('Could not connect to server. Status:', 'role-user-manager'); ?> ' + textStatus + '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>');
                });
            }

            function openCertificateModal(userId) {
                console.log('Opening certificate modal for user ID:', userId);

                jQuery('#user-modal-content').html('<div class="text-center"><div class="spinner-border" role="status"><span class="visually-hidden"><?php esc_html_e('Loading...', 'role-user-manager'); ?></span></div><p><?php esc_html_e('Loading certificates...', 'role-user-manager'); ?></p></div>');
                
                // Check if Bootstrap is available
                if (typeof bootstrap !== 'undefined') {
                    console.log('Bootstrap available, creating modal');
                    var userEditModal = new bootstrap.Modal(document.getElementById('userEditModal'));
                    userEditModal.show();
                } else {
                    console.log('Bootstrap not available, showing modal manually');
                    jQuery('#userEditModal').show();
                }

                // Update modal title
                jQuery('#userEditModalLabel').text('<?php esc_html_e('User Certificates', 'role-user-manager'); ?>');

                jQuery.post(arc_dashboard_vars.ajaxurl, {
                    action: 'arc_generate_certificate_links',
                    user_id: userId,
                    _wpnonce: arc_dashboard_vars.nonce
                }, function (response) {
                    console.log('Certificate AJAX response:', response);

                    if (response.success) {
                        var html = '<div class="certificate-viewer">';
                        html += '<h6><?php esc_html_e('Certificates for', 'role-user-manager'); ?>: ' + response.data.target_user_name + '</h6>';
                        html += '<p class="text-muted"><?php esc_html_e('Total Certificates:', 'role-user-manager'); ?> ' + response.data.total_certificates + '</p>';
                        
                        // LearnDash Certificates
                        if (response.data.certificates && response.data.certificates.length > 0) {
                            html += '<h6 class="mt-3"><?php esc_html_e('LearnDash Certificates', 'role-user-manager'); ?> (' + response.data.certificates.length + ')</h6>';
                            html += '<div class="list-group">';
                            response.data.certificates.forEach(function(cert) {
                                html += '<div class="list-group-item">';
                                html += '<div class="d-flex w-100 justify-content-between">';
                                html += '<h6 class="mb-1">' + cert.title + '</h6>';
                                html += '<a href="' + cert.link + '" target="_blank" class="btn btn-sm btn-outline-primary"><?php esc_html_e('View Certificate', 'role-user-manager'); ?></a>';
                                html += '</div>';
                                if (cert.completion_date) {
                                    html += '<small class="text-muted"><?php esc_html_e('Completed:', 'role-user-manager'); ?> ' + cert.completion_date + '</small>';
                                }
                                html += '</div>';
                            });
                            html += '</div>';
                        }
                        
                        // External Certificates
                        if (response.data.external_certificates && response.data.external_certificates.length > 0) {
                            html += '<h6 class="mt-3"><?php esc_html_e('External Certificates', 'role-user-manager'); ?> (' + response.data.external_certificates.length + ')</h6>';
                            html += '<div class="list-group">';
                            response.data.external_certificates.forEach(function(cert) {
                                html += '<div class="list-group-item">';
                                html += '<div class="d-flex w-100 justify-content-between">';
                                html += '<h6 class="mb-1">' + cert.title + '</h6>';
                                html += '<a href="' + cert.link + '" target="_blank" class="btn btn-sm btn-outline-secondary"><?php esc_html_e('View Certificate', 'role-user-manager'); ?></a>';
                                html += '</div>';
                                if (cert.provider) {
                                    html += '<small class="text-muted"><?php esc_html_e('Provider:', 'role-user-manager'); ?> ' + cert.provider + '</small>';
                                }
                                html += '</div>';
                            });
                            html += '</div>';
                        }
                        
                        if (response.data.total_certificates === 0) {
                            html += '<div class="alert alert-info"><?php esc_html_e('No certificates found for this user.', 'role-user-manager'); ?></div>';
                        }
                        
                        html += '</div>';
                        jQuery('#user-modal-content').html(html);
                    } else {
                        var msg = (response.data && response.data.message) ? response.data.message : '<?php esc_html_e('Unknown error', 'role-user-manager'); ?>';
                        jQuery('#user-modal-content').html('<div class="alert alert-danger"><?php esc_html_e('Error loading certificates:', 'role-user-manager'); ?> ' + msg + '</div>');
                    }
                }).fail(function (jqXHR, textStatus, errorThrown) {
                    console.log('Certificate AJAX error:', textStatus, errorThrown);
                    jQuery('#user-modal-content').html('<div class="alert alert-danger"><?php esc_html_e('Error:', 'role-user-manager'); ?> <?php esc_html_e('Could not connect to server. Status:', 'role-user-manager'); ?> ' + textStatus + '</div>');
                });
            }

            function promoteUserDirect(userId, requestedRole, promotionName) {
                console.log('Promoting user:', userId, 'to role:', requestedRole);
                
                // Prevent multiple rapid clicks
                var button = event.target;
                if (button.disabled) {
                    return;
                }
                button.disabled = true;
                var originalText = button.innerHTML;
                button.innerHTML = '<?php esc_html_e('Promoting...', 'role-user-manager'); ?>';
                
                jQuery.post(arc_dashboard_vars.ajaxurl, {
                    action: 'rum_promote_user_direct',
                    user_id: userId,
                    requested_role: requestedRole,
                    nonce: arc_dashboard_vars.nonce
                }, function (response) {
                    console.log('Promotion response:', response);
                    
                    if (response.success) {
                        // Show success message
                        alert('<?php esc_html_e('User promoted successfully!', 'role-user-manager'); ?>');
                        // Reload the page to reflect changes
                        location.reload();
                    } else {
                        var msg = (response.data && response.data.message) ? response.data.message : '<?php esc_html_e('Unknown error', 'role-user-manager'); ?>';
                        alert('<?php esc_html_e('Error:', 'role-user-manager'); ?> ' + msg);
                        // Re-enable button on error
                        button.disabled = false;
                        button.innerHTML = originalText;
                    }
                }).fail(function (jqXHR, textStatus, errorThrown) {
                    console.log('Promotion AJAX error:', textStatus, errorThrown);
                    alert('<?php esc_html_e('Error:', 'role-user-manager'); ?> <?php esc_html_e('Could not connect to server.', 'role-user-manager'); ?>');
                    // Re-enable button on error
                    button.disabled = false;
                    button.innerHTML = originalText;
                });
            }

            function requestPromotion(userId, requestedRole, promotionName) {
                console.log('Requesting promotion for user:', userId, 'to role:', requestedRole);
                
                var reason = prompt('<?php esc_html_e('Please provide a reason for this promotion request:', 'role-user-manager'); ?>');
                if (reason === null) {
                    return; // User cancelled
                }
                
                // Prevent multiple rapid clicks
                var button = event.target;
                if (button.disabled) {
                    return;
                }
                button.disabled = true;
                var originalText = button.innerHTML;
                button.innerHTML = '<?php esc_html_e('Submitting...', 'role-user-manager'); ?>';
                
                jQuery.post(arc_dashboard_vars.ajaxurl, {
                    action: 'rum_submit_promotion_request',
                    user_id: userId,
                    requested_role: requestedRole,
                    reason: reason,
                    nonce: arc_dashboard_vars.nonce
                }, function (response) {
                    console.log('Promotion request response:', response);
                    
                    if (response.success) {
                        // Show success message
                        alert('<?php esc_html_e('Promotion request submitted successfully!', 'role-user-manager'); ?>');
                        // Reset button to original state on success
                        button.disabled = false;
                        button.innerHTML = originalText;
                    } else {
                        var msg = (response.data && response.data.message) ? response.data.message : '<?php esc_html_e('Unknown error', 'role-user-manager'); ?>';
                        alert('<?php esc_html_e('Error:', 'role-user-manager'); ?> ' + msg);
                        // Re-enable button on error
                        button.disabled = false;
                        button.innerHTML = originalText;
                    }
                }).fail(function (jqXHR, textStatus, errorThrown) {
                    console.log('Promotion request AJAX error:', textStatus, errorThrown);
                    alert('<?php esc_html_e('Error:', 'role-user-manager'); ?> <?php esc_html_e('Could not connect to server.', 'role-user-manager'); ?>');
                    // Re-enable button on error
                    button.disabled = false;
                    button.innerHTML = originalText;
                });
            }
            // Bulk select all (search-aware)
            var bulkSelectAll = document.getElementById('bulk-select-all');
            if (bulkSelectAll) {
                bulkSelectAll.addEventListener('change', function () {
                    // Only select visible checkboxes (not hidden by search)
                    var visibleCheckboxes = document.querySelectorAll('.users-table tbody tr:not([style*="display: none"]) .bulk-checkbox');
                    for (var i = 0; i < visibleCheckboxes.length; i++) {
                        visibleCheckboxes[i].checked = this.checked;
                    }
                });
            }
            // Show/hide role dropdown based on action
            var bulkAction = document.getElementById('bulk_action');
            if (bulkAction) {
                bulkAction.addEventListener('change', function () {
                    var roleSel = document.getElementById('bulk_role');
                    if (this.value === 'assign_role') {
                        roleSel.style.display = '';
                    } else {
                        roleSel.style.display = 'none';
                    }
                });
            }
            // Bulk action form handler
            var bulkActionForm = document.getElementById('bulk-action-form');
            if (bulkActionForm) {
                bulkActionForm.addEventListener('submit', function (e) {
                    e.preventDefault();
                    var bulkAction = document.getElementById('bulk_action');
                    var bulkRole = document.getElementById('bulk_role');
                    var action = bulkAction ? bulkAction.value : '';
                    var role = bulkRole ? bulkRole.value : '';
                    var users = Array.from(document.querySelectorAll('input[name="bulk_users[]"]:checked')).map(cb => cb.value);
                    var feedback = document.getElementById('bulk-action-feedback');
                    if (!action || users.length === 0 || (action === 'assign_role' && !role)) {
                        if (feedback) {
                            feedback.textContent = '<?php esc_html_e('Please select users and a valid action.', 'role-user-manager'); ?>';
                            feedback.style.color = 'red';
                        }
                        return;
                    }
                    if (feedback) {
                        feedback.textContent = '<?php esc_html_e('Processing...', 'role-user-manager'); ?>';
                        feedback.style.color = '#2271b1';
                    }
                    // AJAX call
                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', '<?php echo admin_url('admin-ajax.php'); ?>');
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    xhr.onload = function () {
                        try {
                            var resp = JSON.parse(xhr.responseText);
                            if (resp.success) {
                                if (feedback) {
                                    feedback.textContent = resp.data && resp.data.message ? resp.data.message : '<?php esc_html_e('Bulk action completed.', 'role-user-manager'); ?>';
                                    feedback.style.color = 'green';
                                }
                                setTimeout(function () { location.reload(); }, 1200);
                            } else {
                                var msg = (resp.data && resp.data.message) ? resp.data.message : '<?php esc_html_e('Bulk action failed.', 'role-user-manager'); ?>';
                                if (feedback) {
                                    feedback.textContent = msg;
                                    feedback.style.color = 'red';
                                }
                            }
                        } catch (e) {
                            if (feedback) {
                                feedback.textContent = '<?php esc_html_e('Bulk action failed.', 'role-user-manager'); ?>';
                                feedback.style.color = 'red';
                            }
                        }
                    };
                    var params = 'action=arc_bulk_user_action&_wpnonce=<?php echo wp_create_nonce('arc_dashboard_nonce'); ?>';
                    users.forEach(function (uid) { params += '&users[]=' + encodeURIComponent(uid); });
                    params += '&bulk_action=' + encodeURIComponent(action);
                    if (action === 'assign_role') params += '&bulk_role=' + encodeURIComponent(role);
                    xhr.send(params);
                });
            }

            // Filter enhancement - Manual submit only
            document.addEventListener('DOMContentLoaded', function () {
                var filterForm = document.getElementById('bulk-action-form');
                var filterSelects = document.querySelectorAll('#filter_program, #filter_site, #filter_training_status');
                var filterDates = document.querySelectorAll('#filter_date_start, #filter_date_end');
                
                // Program-based site filtering
                var programSelect = document.getElementById('filter_program');
                var siteSelect = document.getElementById('filter_site');
                
                if (programSelect && siteSelect) {
                    let isLoading = false;
                    
                    programSelect.addEventListener('change', function() {
                        var selectedProgram = this.value;
                        
                        // Prevent multiple simultaneous requests
                        if (isLoading) {
                            return;
                        }
                        
                        // Clear current site selection
                        siteSelect.innerHTML = '<option value=""><?php esc_html_e('All Sites', 'role-user-manager'); ?></option>';
                        
                        if (selectedProgram) {
                            // Show loading state
                            siteSelect.innerHTML = '<option value=""><?php esc_html_e('Loading sites...', 'role-user-manager'); ?></option>';
                            siteSelect.disabled = true;
                            isLoading = true;
                            
                            // AJAX call to get sites for the selected program
                            jQuery.post(arc_dashboard_vars.ajaxurl, {
                                action: 'arc_get_sites_for_program',
                                program: selectedProgram,
                                _wpnonce: arc_dashboard_vars.nonce
                            }, function(response) {
                                if (response.success) {
                                    siteSelect.innerHTML = '<option value=""><?php esc_html_e('All Sites', 'role-user-manager'); ?></option>';
                                    
                                    if (response.data.sites && response.data.sites.length > 0) {
                                        response.data.sites.forEach(function(site) {
                                            var option = document.createElement('option');
                                            option.value = site;
                                            option.textContent = site;
                                            siteSelect.appendChild(option);
                                        });
                                    } else {
                                        var option = document.createElement('option');
                                        option.value = '';
                                        option.textContent = '<?php esc_html_e('No sites found for this program', 'role-user-manager'); ?>';
                                        siteSelect.appendChild(option);
                                    }
                                } else {
                                    siteSelect.innerHTML = '<option value=""><?php esc_html_e('Error loading sites', 'role-user-manager'); ?></option>';
                                }
                                siteSelect.disabled = false;
                                isLoading = false;
                            }).fail(function() {
                                siteSelect.innerHTML = '<option value=""><?php esc_html_e('Error loading sites', 'role-user-manager'); ?></option>';
                                siteSelect.disabled = false;
                                isLoading = false;
                            });
                        } else {
                            // If no program selected, load all sites
                            isLoading = true;
                            jQuery.post(arc_dashboard_vars.ajaxurl, {
                                action: 'arc_get_sites_for_program',
                                program: '',
                                _wpnonce: arc_dashboard_vars.nonce
                            }, function(response) {
                                if (response.success) {
                                    siteSelect.innerHTML = '<option value=""><?php esc_html_e('All Sites', 'role-user-manager'); ?></option>';
                                    
                                    if (response.data.sites && response.data.sites.length > 0) {
                                        response.data.sites.forEach(function(site) {
                                            var option = document.createElement('option');
                                            option.value = site;
                                            option.textContent = site;
                                            siteSelect.appendChild(option);
                                        });
                                    }
                                }
                                siteSelect.disabled = false;
                                isLoading = false;
                            }).fail(function() {
                                siteSelect.innerHTML = '<option value=""><?php esc_html_e('Error loading sites', 'role-user-manager'); ?></option>';
                                siteSelect.disabled = false;
                                isLoading = false;
                            });
                        }
                    });
                }
                
                // Show loading state when filters are applied (only on manual submit)
                filterForm.addEventListener('submit', function () {
                    var submitBtn = filterForm.querySelector('button[type="submit"]');
                    if (submitBtn) {
                        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> <?php esc_html_e('Applying...', 'role-user-manager'); ?>';
                        submitBtn.disabled = true;
                    }
                });

                // Highlight active filters
                var activeFilters = [];
                filterSelects.forEach(function (select) {
                    if (select.value) {
                        activeFilters.push(select.options[select.selectedIndex].text);
                    }
                });
                filterDates.forEach(function (dateInput) {
                    if (dateInput.value) {
                        activeFilters.push(dateInput.placeholder + ': ' + dateInput.value);
                    }
                });

                if (activeFilters.length > 0) {
                    var filterInfo = document.createElement('div');
                    filterInfo.className = 'alert alert-info alert-dismissible fade show mt-2';
                    filterInfo.innerHTML = '<strong><?php esc_html_e('Active Filters:', 'role-user-manager'); ?></strong> ' + activeFilters.join(', ') +
                        '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
                    filterForm.querySelector('.filter-controls').appendChild(filterInfo);
                }

                // Search functionality
                var searchInput = document.getElementById('user_search');
                var searchContainer = document.querySelector('.search-container');
                var clearSearchBtn = document.querySelector('.clear-search');
                
                if (searchInput) {
                    let searchTimeout;
                    
                    // Add real-time search with debouncing
                    searchInput.addEventListener('input', function() {
                        clearTimeout(searchTimeout);
                        var searchTerm = this.value.toLowerCase().trim();
                        
                        // Toggle clear button visibility
                        if (searchContainer) {
                            if (this.value.length > 0) {
                                searchContainer.classList.add('has-content');
                            } else {
                                searchContainer.classList.remove('has-content');
                            }
                        }
                        
                        // Debounce search to avoid excessive filtering
                        searchTimeout = setTimeout(function() {
                            filterUsersRealTime(searchTerm);
                        }, 300);
                    });
                    
                    // Clear search when escape key is pressed
                    searchInput.addEventListener('keyup', function(e) {
                        if (e.key === 'Escape') {
                            clearSearchFunction();
                        }
                    });
                    
                    // Handle Enter key to prevent form submission
                    searchInput.addEventListener('keydown', function(e) {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                        }
                    });
                }
                
                // Clear search button functionality
                if (clearSearchBtn) {
                    clearSearchBtn.addEventListener('click', function() {
                        clearSearchFunction();
                    });
                }
                
                function clearSearchFunction() {
                    if (searchInput) {
                        searchInput.value = '';
                        searchInput.focus();
                        if (searchContainer) {
                            searchContainer.classList.remove('has-content');
                        }
                        filterUsersRealTime('');
                    }
                }
                
                function filterUsersRealTime(searchTerm) {
                    var tableRows = document.querySelectorAll('.users-table tbody tr');
                    var visibleCount = 0;
                    var totalCount = tableRows.length;
                    
                    tableRows.forEach(function(row) {
                        var shouldShow = true;
                        var cells = row.querySelectorAll('td');
                        
                        // Clear any existing highlights first
                        cells.forEach(function(cell) {
                            if (cell.querySelector('.search-highlight')) {
                                cell.innerHTML = cell.textContent;
                            }
                        });
                        
                        if (searchTerm && searchTerm.length >= 2) {
                            var rowText = '';
                            var searchableColumns = [1, 2, 3, 4, 5]; // Name, Role, Parent, Program, Site
                            
                            // Build searchable text from relevant columns
                            if (cells.length >= 6) {
                                searchableColumns.forEach(function(index) {
                                    rowText += (cells[index].textContent || '').toLowerCase() + ' ';
                                });
                                
                                // Also check user ID if available
                                var checkbox = row.querySelector('input[name="bulk_users[]"]');
                                if (checkbox) {
                                    rowText += checkbox.value + ' ';
                                }
                            }
                            
                            shouldShow = rowText.includes(searchTerm);
                            
                            // Add highlighting to matching cells
                            if (shouldShow) {
                                searchableColumns.forEach(function(index) {
                                    if (cells[index]) {
                                        var cellText = cells[index].textContent;
                                        var cellTextLower = cellText.toLowerCase();
                                        
                                        if (cellTextLower.includes(searchTerm)) {
                                            var regex = new RegExp('(' + escapeRegExp(searchTerm) + ')', 'gi');
                                            var highlightedText = cellText.replace(regex, '<span class="search-highlight">$1</span>');
                                            cells[index].innerHTML = highlightedText;
                                        }
                                    }
                                });
                            }
                        } else if (searchTerm && searchTerm.length === 1) {
                            // For single character searches, be more restrictive (match start of words)
                            var rowText = '';
                            if (cells.length >= 6) {
                                for (var i = 1; i <= 5; i++) {
                                    rowText += (cells[i].textContent || '').toLowerCase() + ' ';
                                }
                            }
                            
                            // Match start of words
                            var words = rowText.split(' ');
                            shouldShow = words.some(function(word) {
                                return word.startsWith(searchTerm);
                            });
                        }
                        
                        if (shouldShow) {
                            row.style.display = '';
                            visibleCount++;
                        } else {
                            row.style.display = 'none';
                        }
                    });
                    
                    // Update search results info
                    updateSearchResultsInfo(visibleCount, totalCount, searchTerm);
                    
                    // Update bulk select all functionality for filtered results
                    updateBulkSelectForFilteredResults();
                }
                
                // Helper function to escape regex special characters
                function escapeRegExp(string) {
                    return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                }
                
                function updateSearchResultsInfo(visibleCount, totalCount, searchTerm) {
                    var infoElement = document.querySelector('.filter-controls .ms-3.text-muted');
                    if (infoElement) {
                        var baseText = infoElement.textContent;
                        if (searchTerm) {
                            // Show search results
                            var searchInfo = document.getElementById('search-results-info');
                            if (!searchInfo) {
                                searchInfo = document.createElement('div');
                                searchInfo.id = 'search-results-info';
                                searchInfo.className = 'mt-2 alert alert-info';
                                infoElement.parentNode.appendChild(searchInfo);
                            }
                            searchInfo.innerHTML = '<strong><?php esc_html_e('Search Results:', 'role-user-manager'); ?></strong> ' + 
                                visibleCount + ' <?php esc_html_e('of', 'role-user-manager'); ?> ' + totalCount + 
                                ' <?php esc_html_e('users match', 'role-user-manager'); ?> "<em>' + searchTerm + '</em>"' +
                                ' <button type="button" class="btn btn-sm btn-outline-secondary ms-2" onclick="clearSearch()"><?php esc_html_e('Clear Search', 'role-user-manager'); ?></button>';
                        } else {
                            // Remove search info if no search term
                            var searchInfo = document.getElementById('search-results-info');
                            if (searchInfo) {
                                searchInfo.remove();
                            }
                        }
                    }
                }
                
                function updateBulkSelectForFilteredResults() {
                    var bulkSelectAll = document.getElementById('bulk-select-all');
                    if (bulkSelectAll) {
                        // Remove existing listeners to avoid duplicates
                        var newBulkSelectAll = bulkSelectAll.cloneNode(true);
                        bulkSelectAll.parentNode.replaceChild(newBulkSelectAll, bulkSelectAll);
                        
                        newBulkSelectAll.addEventListener('change', function () {
                            var visibleCheckboxes = document.querySelectorAll('.users-table tbody tr:not([style*="display: none"]) .bulk-checkbox');
                            for (var i = 0; i < visibleCheckboxes.length; i++) {
                                visibleCheckboxes[i].checked = this.checked;
                            }
                        });
                    }
                }
                
                // Global function to clear search (called from clear button in search results)
                window.clearSearch = function() {
                    clearSearchFunction();
                };

                // Export functionality
                var exportBtn = document.getElementById('export-users-btn');
                if (exportBtn) {
                    exportBtn.addEventListener('click', function () {
                        // Show loading state
                        var originalText = exportBtn.innerHTML;
                        exportBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> <?php esc_html_e('Exporting...', 'role-user-manager'); ?>';
                        exportBtn.disabled = true;

                        // Get current filter values
                        var filterData = {};
                        var filterProgram = document.getElementById('filter_program');
                        var filterSite = document.getElementById('filter_site');
                        var filterTrainingStatus = document.getElementById('filter_training_status');
                        var filterDateStart = document.getElementById('filter_date_start');
                        var filterDateEnd = document.getElementById('filter_date_end');

                        filterData.filter_program = filterProgram ? filterProgram.value : '';
                        filterData.filter_site = filterSite ? filterSite.value : '';
                        filterData.filter_training_status = filterTrainingStatus ? filterTrainingStatus.value : '';
                        filterData.filter_date_start = filterDateStart ? filterDateStart.value : '';
                        filterData.filter_date_end = filterDateEnd ? filterDateEnd.value : '';

                        // AJAX call to export
                        var xhr = new XMLHttpRequest();
                        xhr.open('POST', '<?php echo admin_url('admin-ajax.php'); ?>');
                        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                        xhr.onload = function () {
                            try {
                                var resp = JSON.parse(xhr.responseText);
                                if (resp.success) {
                                    // Create and download CSV file
                                    var blob = new Blob([resp.data.csv_content], { type: 'text/csv;charset=utf-8;' });
                                    var link = document.createElement('a');
                                    if (link.download !== undefined) {
                                        var url = URL.createObjectURL(blob);
                                        link.setAttribute('href', url);
                                        link.setAttribute('download', resp.data.filename);
                                        link.style.visibility = 'hidden';
                                        document.body.appendChild(link);
                                        link.click();
                                        document.body.removeChild(link);
                                    }

                                    // Show success message
                                    var successMsg = document.createElement('div');
                                    successMsg.className = 'alert alert-success alert-dismissible fade show mt-2';
                                    successMsg.innerHTML = '<?php esc_html_e('Export completed successfully!', 'role-user-manager'); ?> ' + resp.data.count + ' <?php esc_html_e('users exported.', 'role-user-manager'); ?>' +
                                        '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
                                    filterForm.querySelector('.filter-controls').appendChild(successMsg);

                                    // Auto-remove success message after 5 seconds
                                    setTimeout(function () {
                                        if (successMsg.parentNode) {
                                            successMsg.parentNode.removeChild(successMsg);
                                        }
                                    }, 5000);
                                } else {
                                    // Show error message
                                    var errorMsg = document.createElement('div');
                                    errorMsg.className = 'alert alert-danger alert-dismissible fade show mt-2';
                                    errorMsg.innerHTML = (resp.data && resp.data.message) ? resp.data.message : '<?php esc_html_e('Export failed.', 'role-user-manager'); ?>' +
                                        '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
                                    filterForm.querySelector('.filter-controls').appendChild(errorMsg);
                                }
                            } catch (e) {
                                // Show error message
                                var errorMsg = document.createElement('div');
                                errorMsg.className = 'alert alert-danger alert-dismissible fade show mt-2';
                                errorMsg.innerHTML = '<?php esc_html_e('Export failed.', 'role-user-manager'); ?>' +
                                    '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
                                filterForm.querySelector('.filter-controls').appendChild(errorMsg);
                            }

                            // Reset button state
                            exportBtn.innerHTML = originalText;
                            exportBtn.disabled = false;
                        };

                        var params = 'action=arc_export_users&_wpnonce=<?php echo wp_create_nonce('arc_dashboard_nonce'); ?>';
                        for (var key in filterData) {
                            if (filterData[key]) {
                                params += '&' + key + '=' + encodeURIComponent(filterData[key]);
                            }
                        }
                        xhr.send(params);
                    });
                }
            });
            
            // Add event listeners for user action buttons
            document.addEventListener('click', function(e) {
                if (e.target.classList.contains('btn-edit') || e.target.classList.contains('btn-view')) {
                    e.preventDefault();
                    var userId = e.target.getAttribute('data-user-id');
                    if (userId) {
                        openUserEditModal(userId);
                    }
                }
                
                if (e.target.classList.contains('btn-certificate')) {
                    e.preventDefault();
                    var userId = e.target.getAttribute('data-user-id');
                    if (userId) {
                        openCertificateModal(userId);
                    }
                }
                
                if (e.target.classList.contains('btn-remove')) {
                    e.preventDefault();
                    var userId = e.target.getAttribute('data-user-id');
                    if (userId && confirm('<?php esc_html_e('Are you sure you want to remove this user?', 'role-user-manager'); ?>')) {
                        // Handle user removal
                        console.log('Remove user:', userId);
                    }
                }
                
                // Handle promotion buttons
                if (e.target.classList.contains('btn-promote-direct')) {
                    e.preventDefault();
                    var userId = e.target.getAttribute('data-user-id');
                    var requestedRole = e.target.getAttribute('data-requested-role');
                    var promotionName = e.target.getAttribute('data-promotion-name');
                    
                    if (userId && requestedRole) {
                        if (confirm('<?php esc_html_e('Are you sure you want to promote this user?', 'role-user-manager'); ?>')) {
                            promoteUserDirect(userId, requestedRole, promotionName);
                        }
                    }
                }
                
                if (e.target.classList.contains('btn-promote-request')) {
                    e.preventDefault();
                    var userId = e.target.getAttribute('data-user-id');
                    var requestedRole = e.target.getAttribute('data-requested-role');
                    var promotionName = e.target.getAttribute('data-promotion-name');
                    
                    if (userId && requestedRole) {
                        requestPromotion(userId, requestedRole, promotionName);
                    }
                }
            });

        </script>
        <?php
        return ob_get_clean();
}

// AJAX handler to fetch user LearnDash data
function arc_get_user_ld_data_handler()
{
    // Security checks
    check_ajax_referer('arc_dashboard_nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'User not logged in.']);
    }

    $user_id = intval($_POST['user_id'] ?? 0);
    if (!$user_id) {
        wp_send_json_error(['message' => 'No user ID provided.']);
    }

    // Get user info
    $user = get_user_by('id', $user_id);
    if (!$user) {
        wp_send_json_error(['message' => 'User not found.']);
    }

    // Check if current user can view this user's data
    $current_user = wp_get_current_user();
    $current_user_roles = array_map('strtolower', $current_user->roles);

    // If data-viewer, only allow viewing, not editing/removing
    $is_data_viewer = in_array('data-viewer', $current_user_roles);

    // For other users, you can add custom permission logic here if needed

    // Get user metadata
    $parent_id = get_user_meta($user_id, 'parent_user_id', true);
    $program = get_user_meta($user_id, 'programme', true);
    $site = get_user_meta($user_id, 'sites', true);
    if (!is_array($site))
        $site = [];
    $site_display = !empty($site) ? implode(', ', array_map('trim', $site)) : 'None';
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
    $html .= '<p><strong>' . __('Site:', 'role-user-manager') . '</strong> ' . esc_html($site_display) . '</p>';
    $html .= '</div>';
    $html .= '</div>';
    $html .= '</div><hr>';

    // Initialize totals
    $total_hours = 0;
    $total_points = 0;
    $course_rows = '';
    $courses = [];
    $certificates = [];
    $external_certificates = [];

    // Check if LearnDash is active and get data
    if (function_exists('learndash_user_get_enrolled_courses')) {
        $courses = learndash_user_get_enrolled_courses($user_id);

        // Get LearnDash certificates using multiple methods
        $ld_certificates = [];
        
        // Method 1: Try to get certificates using course completion
        if (!empty($courses)) {
            foreach ($courses as $course_id) {
                // Check if course has certificate enabled
                $certificate_id = get_post_meta($course_id, '_ld_certificate', true);
                if (!empty($certificate_id) && $certificate_id > 0) {
                    // Check if user has completed the course
                    $course_progress = function_exists('learndash_user_get_course_progress') ? learndash_user_get_course_progress($user_id, $course_id) : null;
                    if ($course_progress && isset($course_progress['status']) && $course_progress['status'] === 'completed') {
                        $certificate_link = function_exists('learndash_get_course_certificate_link') ? learndash_get_course_certificate_link($course_id, $user_id) : '';
                        if (!empty($certificate_link)) {
                            $course = get_post($course_id);
                            if ($course) {
                                $ld_certificates[] = (object) [
                                    'post_title' => $course->post_title,
                                    'permalink' => $certificate_link,
                                ];
                            }
                        }
                    }
                }
            }
        }

        // Method 2: Check for quiz certificates
        $quizzes = get_user_meta($user_id, '_sfwd-quizzes', true);
        if (is_array($quizzes) && !empty($quizzes)) {
            foreach ($quizzes as $quiz_attempt) {
                if (isset($quiz_attempt['certificate']['certificateLink']) && !empty($quiz_attempt['certificate']['certificateLink'])) {
                    $quiz_id = $quiz_attempt['quiz'];
                    $quiz = get_post($quiz_id);
                    if ($quiz) {
                        $ld_certificates[] = (object) [
                            'post_title' => $quiz->post_title . ' (Quiz)',
                            'permalink' => $quiz_attempt['certificate']['certificateLink'],
                        ];
                    }
                }
            }
        }

        // Method 3: Try using LearnDash certificate functions if available
        if (empty($ld_certificates) && function_exists('learndash_get_user_quiz_attempts')) {
            $quiz_attempts = learndash_get_user_quiz_attempts($user_id);
            if ($quiz_attempts) {
                foreach ($quiz_attempts as $attempt) {
                    if (isset($attempt['certificate_url']) && !empty($attempt['certificate_url'])) {
                        $quiz_id = $attempt['quiz_id'];
                        $quiz = get_post($quiz_id);
                        if ($quiz) {
                            $ld_certificates[] = (object) [
                                'post_title' => $quiz->post_title . ' (Quiz)',
                                'permalink' => $attempt['certificate_url'],
                            ];
                        }
                    }
                }
            }
        }

        $certificates = $ld_certificates;
    }

    // Get external certificates
    global $wpdb;
    $table_name = $wpdb->prefix . 'external_courses';
    
    // Check if external courses table exists
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
        $external_courses = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE user_id = %d AND status = 'approved' ORDER BY date_submitted DESC",
            $user_id
        ));

        // Count external certificates
        foreach ($external_courses as $course) {
            if (!empty($course->certificate_file)) {
                $external_certificates[] = (object) [
                    'post_title' => $course->course_name,
                    'permalink' => $course->certificate_file,
                ];
            }
        }
    }

    // Process courses for display
        if (!empty($courses)) {
            foreach ($courses as $course_id) {
                $course = get_post($course_id);
                if (!$course)
                    continue;

                $points = get_post_meta($course_id, 'course_points', true);
                $hours = get_post_meta($course_id, 'course_hours', true);
                $total_points += floatval($points);
                $total_hours += floatval($hours);

                // Get completion status
                $completed = false;
                $completion_percentage = 0;

                if (function_exists('learndash_course_completed')) {
                    $completed = learndash_course_completed($user_id, $course_id);
                }

                if (function_exists('learndash_course_progress')) {
                    $progress = learndash_course_progress($user_id, $course_id);
                    if (isset($progress['percentage'])) {
                        $completion_percentage = intval($progress['percentage']);
                    }
                }

                $course_rows .= '<tr>';
                $course_rows .= '<td>' . esc_html($course->post_title) . '</td>';
                $course_rows .= '<td>' . esc_html($hours ?: '0') . '</td>';
                $course_rows .= '<td>' . esc_html($points ?: '0') . '</td>';
                $course_rows .= '<td>' . ($completed ? '<span class="badge bg-success">Completed</span>' : '<span class="badge bg-warning">' . $completion_percentage . '%</span>') . '</td>';
                // If data-viewer, do not show Remove button
                if ($is_data_viewer) {
                    $course_rows .= '<td><span class="text-muted">N/A</span></td>';
                } else {
                    $course_rows .= '<td><button class="btn btn-sm btn-danger" onclick="removeUserFromCourse(' . intval($user_id) . ',' . intval($course_id) . ')">' . __('Remove', 'role-user-manager') . '</button></td>';
                }
                $course_rows .= '</tr>';
            }
    }

    // Build courses section
    $html .= '<h6>' . __('Enrolled Courses', 'role-user-manager') . ' (' . count($courses) . ')</h6>';
    if (!empty($courses)) {
        $html .= '<table class="table table-sm">';
        $html .= '<thead><tr><th>' . __('Course', 'role-user-manager') . '</th><th>' . __('Hours', 'role-user-manager') . '</th><th>' . __('Points', 'role-user-manager') . '</th><th>' . __('Status', 'role-user-manager') . '</th><th>' . __('Action', 'role-user-manager') . '</th></tr></thead>';
        $html .= '<tbody>' . $course_rows . '</tbody>';
        $html .= '</table>';
        $html .= '<div class="row"><div class="col-md-6"><strong>' . __('Total Hours:', 'role-user-manager') . '</strong> ' . esc_html($total_hours) . '</div><div class="col-md-6"><strong>' . __('Total Points:', 'role-user-manager') . '</strong> ' . esc_html($total_points) . '</div></div>';
    } else {
        $html .= '<p class="text-muted">' . __('No courses enrolled.', 'role-user-manager') . '</p>';
    }

    // Build certificates section with both LearnDash and external certificates
    $total_certificates = count($certificates) + count($external_certificates);
    $html .= '<hr><h6>' . __('Certificates', 'role-user-manager') . ' (' . $total_certificates . ')</h6>';
    
    if (!empty($certificates) || !empty($external_certificates)) {
        $html .= '<div class="list-group">';
        
        // LearnDash Certificates
        if (!empty($certificates)) {
            $html .= '<h6 class="mt-3">' . __('LearnDash Certificates', 'role-user-manager') . ' (' . count($certificates) . ')</h6>';
        foreach ($certificates as $cert) {
            $html .= '<div class="list-group-item">';
            $html .= '<div class="d-flex w-100 justify-content-between">';
            $html .= '<h6 class="mb-1">' . esc_html($cert->post_title) . '</h6>';
                $html .= '<a href="' . esc_url($cert->permalink) . '" target="_blank" class="btn btn-sm btn-outline-primary">' . __('View Certificate', 'role-user-manager') . '</a>';
            $html .= '</div>';
            $html .= '</div>';
        }
        }
        
        // External Certificates
        if (!empty($external_certificates)) {
            $html .= '<h6 class="mt-3">' . __('External Certificates', 'role-user-manager') . ' (' . count($external_certificates) . ')</h6>';
            foreach ($external_certificates as $cert) {
                $html .= '<div class="list-group-item">';
                $html .= '<div class="d-flex w-100 justify-content-between">';
                $html .= '<h6 class="mb-1">' . esc_html($cert->post_title) . '</h6>';
                $html .= '<a href="' . esc_url($cert->permalink) . '" target="_blank" class="btn  btn-sm btn-outline-secondary">' . __('View Certificate', 'role-user-manager') . '</a>';
                $html .= '</div>';
                $html .= '</div>';
            }
        }
        
        $html .= '</div>';
    } else {
        $html .= '<p class="text-muted">' . __('No certificates earned.', 'role-user-manager') . '</p>';
    }

    wp_send_json_success(['html' => $html]);
}
add_action('wp_ajax_arc_get_user_ld_data', 'arc_get_user_ld_data_handler');

// AJAX handler to remove user from course
function arc_remove_user_from_course_handler()
{
    // Security checks
    check_ajax_referer('arc_dashboard_nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'User not logged in.']);
    }

    $user_id = intval($_POST['user_id'] ?? 0);
    $course_id = intval($_POST['course_id'] ?? 0);

    if (!$user_id || !$course_id) {
        wp_send_json_error(['message' => 'Missing user or course ID.']);
    }

    // Check permissions
    if (!current_user_can('manage_options') && !current_user_can('edit_users')) {
        wp_send_json_error(['message' => 'Insufficient permissions.']);
    }

    // Check if LearnDash function exists
    if (!function_exists('ld_update_course_access')) {
        wp_send_json_error(['message' => 'LearnDash function not available.']);
    }

    // Unenroll user from course
    $result = ld_update_course_access($user_id, $course_id, $remove = true);

    if ($result) {
        wp_send_json_success(['message' => 'User removed from course.']);
    } else {
        wp_send_json_error(['message' => 'Failed to remove user from course.']);
    }
}
add_action('wp_ajax_arc_remove_user_from_course', 'arc_remove_user_from_course_handler');

add_action('wp_ajax_arc_bulk_user_action', function () {
    check_ajax_referer('arc_dashboard_nonce');
    if (!current_user_can('edit_users')) {
        wp_send_json_error(['message' => 'Insufficient permissions.']);
    }
    $user_ids = isset($_POST['users']) && is_array($_POST['users']) ? array_map('intval', $_POST['users']) : [];
    $action = sanitize_text_field($_POST['bulk_action'] ?? '');
    $role = sanitize_text_field($_POST['bulk_role'] ?? '');
    if (empty($user_ids) || !$action) {
        wp_send_json_error(['message' => 'No users or action specified.']);
    }
    $success = 0;
    $fail = 0;
    foreach ($user_ids as $uid) {
        if ($action === 'remove') {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            $result = wp_delete_user($uid);
            if ($result)
                $success++;
            else
                $fail++;
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
        wp_send_json_success(['message' => __('Bulk action completed:', 'role-user-manager') . ' ' . $success . ' ' . __('success,', 'role-user-manager') . ' ' . $fail . ' ' . __('failed.', 'role-user-manager')]);
    } else {
        wp_send_json_error(['message' => 'No users updated.']);
    }
});

// AJAX handler to export users
function arc_export_users_handler()
{
    // Security checks
    check_ajax_referer('arc_dashboard_nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'User not logged in.']);
    }

    $current_user = wp_get_current_user();
    $user_roles = array_map('strtolower', $current_user->roles);

    // Only allow program-leader or administrator to export
    if (!in_array('administrator', $user_roles) && !in_array('program-leader', $user_roles)) {
        wp_send_json_error(['message' => 'Insufficient permissions.']);
    }

    // Get filter parameters
    $filter_program = isset($_POST['filter_program']) ? sanitize_text_field($_POST['filter_program']) : '';
    $filter_site = isset($_POST['filter_site']) ? sanitize_text_field($_POST['filter_site']) : '';
    $filter_training_status = isset($_POST['filter_training_status']) ? sanitize_text_field($_POST['filter_training_status']) : '';
    $filter_date_start = isset($_POST['filter_date_start']) ? sanitize_text_field($_POST['filter_date_start']) : '';
    $filter_date_end = isset($_POST['filter_date_end']) ? sanitize_text_field($_POST['filter_date_end']) : '';

    // Get all users and apply filters (same logic as dashboard)
    $all_users = get_users(['orderby' => 'display_name', 'order' => 'ASC', 'fields' => 'all']);

    // For administrators, use all users; for others, use descendants only
    if (in_array('administrator', $user_roles)) {
        $users_to_filter = $all_users;
    } else {
        $descendant_ids = arc_get_descendant_user_ids($current_user->ID, $all_users);
        $users_to_filter = array_filter($all_users, function ($user) use ($descendant_ids) {
            return in_array($user->ID, $descendant_ids);
        });
    }

    // Apply filters to users
    $filtered_users = array_filter($users_to_filter, function ($user) use ($filter_program, $filter_site, $filter_training_status, $filter_date_start, $filter_date_end) {
        // Filter by program
        if (!empty($filter_program)) {
            $user_program = get_user_meta($user->ID, 'programme', true);
            if ($user_program !== $filter_program) {
                return false;
            }
        }

        // Filter by site
        if (!empty($filter_site)) {
            $user_sites = get_user_meta($user->ID, 'sites', true);
            if (!is_array($user_sites)) {
                $user_sites = [];
            }
            if (!in_array($filter_site, $user_sites)) {
                return false;
            }
        }

        // Filter by training status
        if (!empty($filter_training_status)) {
            if (!arc_check_training_status($user->ID, $filter_training_status)) {
                return false;
            }
        }

        // Filter by date range
        if (!arc_check_date_range($user->ID, $filter_date_start, $filter_date_end)) {
            return false;
        }

        return true;
    });

    // Prepare CSV data
    $csv_data = [];
    $csv_data[] = [
        'Name',
        'Email',
        'Role',
        'Parent',
        'Program',
        'Site',
        'Total Courses',
        'Total Certificates',
        'Registration Date'
    ];

    foreach ($filtered_users as $user) {
        $parent_id = get_user_meta($user->ID, 'parent_user_id', true);
        $program = get_user_meta($user->ID, 'programme', true);
        $site = get_user_meta($user->ID, 'sites', true);
        if (!is_array($site)) {
            $site = [];
        }
        $site_display = !empty($site) ? implode(', ', array_map('trim', $site)) : '—';
        $parent_name = $parent_id ? get_user_by('id', $parent_id)->display_name : '—';

        // Get LearnDash stats
        $total_courses = 0;
        $total_certificates = 0;
        if (function_exists('learndash_get_user_stats')) {
            $ld_stats = learndash_get_user_stats($user->ID);
            $total_courses = isset($ld_stats['courses']) ? intval($ld_stats['courses']) : 0;
            $total_certificates = isset($ld_stats['certificates']) ? intval($ld_stats['certificates']) : 0;
        }

        $csv_data[] = [
            $user->display_name,
            $user->user_email,
            implode(', ', $user->roles),
            $parent_name,
            $program ?: '—',
            $site_display,
            $total_courses,
            $total_certificates,
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

    // Set headers for CSV download
    $filename = 'users_export_' . date('Y-m-d_H-i-s') . '.csv';

    wp_send_json_success([
        'csv_content' => $csv_content,
        'filename' => $filename,
        'count' => count($filtered_users)
    ]);
}
add_action('wp_ajax_arc_export_users', 'arc_export_users_handler');

// AJAX handler to get sites for a specific program
function arc_get_sites_for_program_ajax_handler()
{
    // Security checks
    check_ajax_referer('arc_dashboard_nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'User not logged in.']);
    }

    $program = sanitize_text_field($_POST['program'] ?? '');
    
    if (empty($program)) {
        wp_send_json_success(['sites' => []]);
    }

    $sites = arc_get_sites_for_program($program);
    
    wp_send_json_success(['sites' => $sites]);
}
add_action('wp_ajax_arc_get_sites_for_program', 'arc_get_sites_for_program_ajax_handler');

// AJAX handler for direct user promotion
function arc_promote_user_direct_handler()
{
    // Check if user is logged in
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'User not logged in']);
        return;
    }

    // Verify nonce
    if (!check_ajax_referer('arc_dashboard_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Security check failed']);
        return;
    }

    $user_id = intval($_POST['user_id']);
    $requested_role = sanitize_text_field($_POST['requested_role']);

    // Debug logging
    error_log("Promotion request - User ID: $user_id, Requested Role: $requested_role");

    $current_user = wp_get_current_user();
    $user = get_user_by('id', $user_id);
    
    if (!$user) {
        error_log("User not found: $user_id");
        wp_send_json_error(['message' => __('User not found.', 'role-user-manager')]);
    }

    $workflow = new RoleAssignmentWorkflow();
    $current_role = $workflow->get_user_primary_role($user_id);
    
    // Debug logging for direct promotion validation
    error_log("=== DIRECT PROMOTION VALIDATION DEBUG ===");
    error_log("Current User ID: " . $current_user->ID);
    error_log("Current User Roles: " . implode(', ', $current_user->roles));
    error_log("Target User ID: " . $user_id);
    error_log("Target User Roles: " . implode(', ', $user->roles));
    error_log("Current Role: " . $current_role);
    error_log("Requested Role: " . $requested_role);
    
    // Validate promotion request
    $validation = $workflow->validate_promotion_request($current_user, $user, $current_role, $requested_role);
    error_log("Validation Result: " . json_encode($validation));
    
    if (!$validation['valid']) {
        error_log("Validation failed: " . $validation['message']);
        wp_send_json_error(['message' => $validation['message']]);
    }
    
    error_log("Validation PASSED");

    // Check if this requires approval
    $requires_approval = $validation['requires_approval'] ?? false;

    if ($requires_approval) {
        error_log("Promotion requires approval");
        wp_send_json_error(['message' => __('This promotion requires admin approval. Please use the request button instead.', 'role-user-manager')]);
    }

    // Perform the promotion
    $user->set_role($requested_role);
    
    // Set the promoter as the parent of the promoted user
    update_user_meta($user_id, 'parent_user_id', $current_user->ID);
    error_log("Set parent user to promoter: {$current_user->ID} for user: {$user_id}");
    
    // Log the action
    $workflow->log_audit("Direct promotion: User {$user_id} promoted from {$current_role} to {$requested_role} by " . $current_user->display_name . " (Parent set to: {$current_user->ID})");

    error_log("Promotion successful");
    wp_send_json_success(['message' => __('User promoted successfully.', 'role-user-manager')]);
}
add_action('wp_ajax_rum_promote_user_direct', 'arc_promote_user_direct_handler');

// AJAX handler for submitting promotion requests
function arc_submit_promotion_request_handler()
{
    // Check if user is logged in
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'User not logged in']);
        return;
    }

    // Verify nonce
    if (!check_ajax_referer('arc_dashboard_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Security check failed']);
        return;
    }

    $user_id = intval($_POST['user_id']);
    $requested_role = sanitize_text_field($_POST['requested_role']);
    $reason = sanitize_textarea_field($_POST['reason']);

    $current_user = wp_get_current_user();
    $user = get_user_by('id', $user_id);
    
    if (!$user) {
        wp_send_json_error(['message' => __('User not found.', 'role-user-manager')]);
    }

    $workflow = new RoleAssignmentWorkflow();
    $current_role = $workflow->get_user_primary_role($user_id);
    
    // Debug logging for validation
    error_log("=== PROMOTION VALIDATION DEBUG ===");
    error_log("Current User ID: " . $current_user->ID);
    error_log("Current User Roles: " . implode(', ', $current_user->roles));
    error_log("Target User ID: " . $user_id);
    error_log("Target User Roles: " . implode(', ', $user->roles));
    error_log("Current Role: " . $current_role);
    error_log("Requested Role: " . $requested_role);
    
    // Validate promotion request
    $validation = $workflow->validate_promotion_request($current_user, $user, $current_role, $requested_role);
    error_log("Validation Result: " . json_encode($validation));
    
    if (!$validation['valid']) {
        error_log("Validation FAILED: " . $validation['message']);
        wp_send_json_error(['message' => $validation['message']]);
    }
    
    error_log("Validation PASSED");

    // Check if request already exists
    if ($workflow->has_pending_request($user_id, $requested_role)) {
        wp_send_json_error(['message' => __('A promotion request for this user and role already exists.', 'role-user-manager')]);
    }

    // Create the request
    $request_id = $workflow->create_promotion_request($current_user->ID, $user_id, $current_role, $requested_role, $reason);
    
    if ($request_id) {
        wp_send_json_success([
            'message' => __('Promotion request submitted successfully.', 'role-user-manager'),
            'request_id' => $request_id
        ]);
    } else {
        wp_send_json_error(['message' => __('Failed to submit promotion request.', 'role-user-manager')]);
    }
}
add_action('wp_ajax_rum_submit_promotion_request', 'arc_submit_promotion_request_handler');

// Helper function: Generate valid certificate links for any user
function arc_generate_user_certificate_links($user_id, $current_user_id = null) {
    // Security and permission checks
    if (!$user_id || !is_numeric($user_id)) {
        return [
            'success' => false,
            'message' => 'Invalid user ID provided.',
            'certificates' => []
        ];
    }

    // If no current user provided, use logged-in user
    if (!$current_user_id) {
        $current_user_id = get_current_user_id();
    }

    // Check if current user is logged in
    if (!$current_user_id) {
        return [
            'success' => false,
            'message' => 'User must be logged in to view certificates.',
            'certificates' => []
        ];
    }

    // Get user objects
    $target_user = get_user_by('id', $user_id);
    $current_user = get_user_by('id', $current_user_id);

    if (!$target_user || !$current_user) {
        return [
            'success' => false,
            'message' => 'User not found.',
            'certificates' => []
        ];
    }

    // Permission checks
    $current_user_roles = array_map('strtolower', $current_user->roles);
    $can_view_certificates = false;

    // Administrators and data viewers can view all certificates
    if (in_array('administrator', $current_user_roles) || in_array('data-viewer', $current_user_roles)) {
        $can_view_certificates = true;
    }
    // Users can view their own certificates
    elseif ($current_user_id == $user_id) {
        $can_view_certificates = true;
    }
    // Check for group_leader capability (includes program-leader, site-supervisor, data-viewer)
    elseif (current_user_can('group_leader')) {
        $can_view_certificates = true;
    }
    // Parent users can view their children's certificates
    else {
        $parent_id = get_user_meta($user_id, 'parent_user_id', true);
        if ($parent_id && intval($parent_id) === intval($current_user_id)) {
            $can_view_certificates = true;
        }
        // Check for hierarchical relationships (grandparents, etc.)
        else {
            $all_users = get_users(['fields' => 'all']);
            $descendant_ids = arc_get_descendant_user_ids($current_user_id, $all_users);
            if (in_array($user_id, $descendant_ids)) {
                $can_view_certificates = true;
            }
        }
    }

    if (!$can_view_certificates) {
        return [
            'success' => false,
            'message' => 'You do not have permission to view certificates for this user.',
            'certificates' => []
        ];
    }

    $certificates = [];
    $external_certificates = [];

    // Check if LearnDash is active and get certificates
    if (function_exists('learndash_user_get_enrolled_courses')) {
        $courses = learndash_user_get_enrolled_courses($user_id);
        $ld_certificates = [];

        // Method 1: Course completion certificates
        if (!empty($courses)) {
            foreach ($courses as $course_id) {
                // Check if course has certificate enabled
                $certificate_id = get_post_meta($course_id, '_ld_certificate', true);
                if (!empty($certificate_id) && $certificate_id > 0) {
                    // Check if user has completed the course
                    $course_progress = function_exists('learndash_user_get_course_progress') ? 
                        learndash_user_get_course_progress($user_id, $course_id) : null;
                    
                    if ($course_progress && isset($course_progress['status']) && $course_progress['status'] === 'completed') {
                        // Generate certificate link using the proper function
                        $certificate_link = '';
                        if (function_exists('learndash_get_course_certificate_link')) {
                            $certificate_link = learndash_get_course_certificate_link($user_id, $course_id);
                        }
                        
                        if (!empty($certificate_link)) {
                            $course = get_post($course_id);
                            if ($course) {
                                $ld_certificates[] = [
                                    'type' => 'course',
                                    'title' => $course->post_title,
                                    'link' => $certificate_link,
                                    'course_id' => $course_id,
                                    'user_id' => $user_id,
                                    'completion_date' => get_user_meta($user_id, 'course_completed_' . $course_id, true)
                                ];
                            }
                        }
                    }
                }
            }
        }

        // Method 2: Quiz certificates
        $quizzes = get_user_meta($user_id, '_sfwd-quizzes', true);
        if (is_array($quizzes) && !empty($quizzes)) {
            foreach ($quizzes as $quiz_attempt) {
                if (isset($quiz_attempt['certificate']['certificateLink']) && !empty($quiz_attempt['certificate']['certificateLink'])) {
                    $quiz_id = $quiz_attempt['quiz'];
                    $quiz = get_post($quiz_id);
                    if ($quiz) {
                        $ld_certificates[] = [
                            'type' => 'quiz',
                            'title' => $quiz->post_title . ' (Quiz)',
                            'link' => $quiz_attempt['certificate']['certificateLink'],
                            'quiz_id' => $quiz_id,
                            'user_id' => $user_id,
                            'completion_date' => isset($quiz_attempt['completed']) ? $quiz_attempt['completed'] : ''
                        ];
                    }
                }
            }
        }

        // Method 3: LearnDash quiz attempts
        if (function_exists('learndash_get_user_quiz_attempts')) {
            $quiz_attempts = learndash_get_user_quiz_attempts($user_id);
            if ($quiz_attempts) {
                foreach ($quiz_attempts as $attempt) {
                    if (isset($attempt['certificate_url']) && !empty($attempt['certificate_url'])) {
                        $quiz_id = $attempt['quiz_id'];
                        $quiz = get_post($quiz_id);
                        if ($quiz) {
                            $ld_certificates[] = [
                                'type' => 'quiz',
                                'title' => $quiz->post_title . ' (Quiz)',
                                'link' => $attempt['certificate_url'],
                                'quiz_id' => $quiz_id,
                                'user_id' => $user_id,
                                'completion_date' => isset($attempt['completed']) ? $attempt['completed'] : ''
                            ];
                        }
                    }
                }
            }
        }

        $certificates = $ld_certificates;
    }

    // Get external certificates
    global $wpdb;
    $table_name = $wpdb->prefix . 'external_courses';
    
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
        $external_courses = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE user_id = %d AND status = 'approved' ORDER BY date_submitted DESC",
            $user_id
        ));

        foreach ($external_courses as $course) {
            if (!empty($course->certificate_file)) {
                $external_certificates[] = [
                    'type' => 'external',
                    'title' => $course->course_name,
                    'link' => $course->certificate_file,
                    'provider' => $course->certificate_provider,
                    'user_id' => $user_id,
                    'submission_date' => $course->date_submitted
                ];
            }
        }
    }

    return [
        'success' => true,
        'message' => 'Certificate links generated successfully.',
        'certificates' => $certificates,
        'external_certificates' => $external_certificates,
        'total_certificates' => count($certificates) + count($external_certificates),
        'user_id' => $user_id,
        'target_user_name' => $target_user->display_name
    ];
}

// AJAX handler for generating certificate links
function arc_generate_certificate_links_handler() {
    // Security checks
    check_ajax_referer('arc_dashboard_nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'User not logged in.']);
    }

    $user_id = intval($_POST['user_id'] ?? 0);
    if (!$user_id) {
        wp_send_json_error(['message' => 'No user ID provided.']);
    }

    $result = arc_generate_user_certificate_links($user_id);
    
    if ($result['success']) {
        wp_send_json_success($result);
    } else {
        wp_send_json_error(['message' => $result['message']]);
    }
}
add_action('wp_ajax_arc_generate_certificate_links', 'arc_generate_certificate_links_handler');

// AJAX handler for dynamic user filtering
function arc_filter_users_ajax_handler() {
    // Security checks
    check_ajax_referer('arc_dashboard_nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'User not logged in.']);
    }

    $current_user = wp_get_current_user();
    $user_roles = array_map('strtolower', $current_user->roles);

    // Get filter parameters
    $per_page = 20;
    $paged = isset($_POST['paged']) ? max(1, intval($_POST['paged'])) : 1;
    $filter_program = isset($_POST['filter_program']) && $_POST['filter_program'] !== null ? sanitize_text_field($_POST['filter_program']) : '';
    $filter_site = isset($_POST['filter_site']) && $_POST['filter_site'] !== null ? sanitize_text_field($_POST['filter_site']) : '';
    $filter_training_status = isset($_POST['filter_training_status']) && $_POST['filter_training_status'] !== null ? sanitize_text_field($_POST['filter_training_status']) : '';
    $filter_date_start = isset($_POST['filter_date_start']) && $_POST['filter_date_start'] !== null ? sanitize_text_field($_POST['filter_date_start']) : '';
    $filter_date_end = isset($_POST['filter_date_end']) && $_POST['filter_date_end'] !== null ? sanitize_text_field($_POST['filter_date_end']) : '';

    // Get all users and apply filters (same logic as dashboard)
    $all_users = get_users(['orderby' => 'display_name', 'order' => 'ASC', 'fields' => 'all']);

    // For administrators, use all users; for others, use descendants only
    if (in_array('administrator', $user_roles) || in_array('data-viewer', $user_roles)) {
        $users_to_filter = $all_users;
    } else {
        $descendant_ids = arc_get_descendant_user_ids($current_user->ID, $all_users);
        $users_to_filter = array_filter($all_users, function ($user) use ($descendant_ids) {
            return in_array($user->ID, $descendant_ids);
        });
    }

    // Apply filters to users
    $filtered_users = array_filter($users_to_filter, function ($user) use ($filter_program, $filter_site, $filter_training_status, $filter_date_start, $filter_date_end) {
        // Filter by program
        if (!empty($filter_program)) {
            $user_program = get_user_meta($user->ID, 'programme', true);
            if ($user_program !== $filter_program) {
                return false;
            }
        }

        // Filter by site
        if (!empty($filter_site)) {
            $user_sites = get_user_meta($user->ID, 'sites', true);
            if (!is_array($user_sites)) {
                $user_sites = [];
            }
            if (!in_array($filter_site, $user_sites)) {
                return false;
            }
        }

        // Filter by training status
        if (!empty($filter_training_status)) {
            if (!arc_check_training_status($user->ID, $filter_training_status)) {
                return false;
            }
        }

        // Filter by date range
        if (!arc_check_date_range($user->ID, $filter_date_start, $filter_date_end)) {
            return false;
        }

        return true;
    });

    $total_users = count($filtered_users);
    $visible_users = array_slice(array_values($filtered_users), ($paged - 1) * $per_page, $per_page);

    // Build user data for response
    $children_users = [];
    foreach ($visible_users as $user) {
        $parent_id = get_user_meta($user->ID, 'parent_user_id', true);
        $program = get_user_meta($user->ID, 'programme', true);
        $site = get_user_meta($user->ID, 'sites', true);
        if (!is_array($site))
            $site = [];
        $site_display = !empty($site) ? implode(', ', array_map('trim', $site)) : '—';
        $parent_name = $parent_id ? get_user_by('id', $parent_id)->display_name : '—';

        // Get LearnDash stats with error handling
        $total_courses = 0;
        $total_certificates = 0;
        if (function_exists('learndash_get_user_stats')) {
            $ld_stats = learndash_get_user_stats($user->ID);
            $total_courses = isset($ld_stats['courses']) ? intval($ld_stats['courses']) : 0;
            $total_certificates = isset($ld_stats['certificates']) ? intval($ld_stats['certificates']) : 0;
        }

        $children_users[] = [
            'id' => $user->ID,
            'name' => $user->display_name,
            'role' => implode(', ', $user->roles),
            'parent' => $parent_name,
            'program' => $program ?: '—',
            'site' => $site_display,
            'total_courses' => $total_courses,
            'total_certificates' => $total_certificates,
            'total_hours' => 0,
            'profile_image' => 'https://via.placeholder.com/80x80/007cba/ffffff?text=' . strtoupper(substr($user->display_name, 0, 1)),
            'courses' => []
        ];
    }

    // Build pagination
    $total_pages = ceil($total_users / $per_page);
    $pagination_html = '';
    
    if ($total_pages > 1) {
        $pagination_html .= '<div class="pagination-bar">';
        if ($paged > 1) {
            $pagination_html .= '<a href="#" class="page-link" data-page="' . ($paged - 1) . '">' . __('&laquo; Prev', 'role-user-manager') . '</a>';
        }
        for ($i = 1; $i <= $total_pages; $i++) {
            if ($i == $paged) {
                $pagination_html .= '<span class="current">' . $i . '</span>';
            } else {
                $pagination_html .= '<a href="#" class="page-link" data-page="' . $i . '">' . $i . '</a>';
            }
        }
        if ($paged < $total_pages) {
            $pagination_html .= '<a href="#" class="page-link" data-page="' . ($paged + 1) . '">' . __('Next &raquo;', 'role-user-manager') . '</a>';
        }
        $pagination_html .= '</div>';
    }

    // Build table HTML
    $table_html = '<table role="table" aria-label="' . esc_attr__('Registered WordPress Users', 'role-user-manager') . '">';
    $table_html .= '<thead><tr>';
    $table_html .= '<th><input type="checkbox" id="bulk-select-all" class="bulk-checkbox"></th>';
    $table_html .= '<th>' . __('Name', 'role-user-manager') . '</th>';
    $table_html .= '<th>' . __('Role', 'role-user-manager') . '</th>';
    $table_html .= '<th>' . __('Parent', 'role-user-manager') . '</th>';
    $table_html .= '<th>' . __('Program', 'role-user-manager') . '</th>';
    $table_html .= '<th>' . __('Site', 'role-user-manager') . '</th>';
    $table_html .= '<th>' . __('Total Courses', 'role-user-manager') . '</th>';
    $table_html .= '<th>' . __('Total Certificates', 'role-user-manager') . '</th>';
    $table_html .= '<th>' . __('Actions', 'role-user-manager') . '</th>';
    $table_html .= '</tr></thead><tbody>';

    foreach ($children_users as $user) {
        $table_html .= '<tr>';
        $table_html .= '<td><input type="checkbox" name="bulk_users[]" value="' . esc_attr($user['id']) . '" class="bulk-checkbox"></td>';
        $table_html .= '<td>' . esc_html($user['name']) . '</td>';
        $table_html .= '<td>' . esc_html($user['role']) . '</td>';
        $table_html .= '<td>' . esc_html($user['parent']) . '</td>';
        $table_html .= '<td>' . esc_html($user['program']) . '</td>';
        $table_html .= '<td>' . esc_html($user['site']) . '</td>';
        $table_html .= '<td>' . esc_html($user['total_courses']) . '</td>';
        $table_html .= '<td>' . esc_html($user['total_certificates']) . '</td>';
        $table_html .= '<td class="action-btn"><div class="user-actions">';
        
        // Show appropriate buttons based on user role
        if (in_array('data-viewer', $user_roles)) {
            $table_html .= '<button type="button" class="btn btn-view" data-user-id="' . intval($user['id']) . '">' . __('View', 'role-user-manager') . '</button>';
        } else {
            $table_html .= '<button type="button" class="btn btn-edit" data-user-id="' . intval($user['id']) . '">' . __('Edit', 'role-user-manager') . '</button>';
            $table_html .= '<button type="button" class="btn btn-remove" data-user-id="' . intval($user['id']) . '">' . __('Remove', 'role-user-manager') . '</button>';
        }
        
        // Add promotion buttons if available
        try {
            $workflow = new RoleAssignmentWorkflow();
            $available_promotions = $workflow->get_available_promotions_for_user($user['id']);
            
            if (!empty($available_promotions)) {
                foreach ($available_promotions as $promotion) {
                    $button_class = $promotion['requires_approval'] ? 'btn-promote-request' : 'btn-promote-direct';
                    $button_text = $promotion['requires_approval'] ? 
                        sprintf(__('Request %s', 'role-user-manager'), $promotion['name']) : 
                        sprintf(__('Promote to %s', 'role-user-manager'), $promotion['name']);
                    $table_html .= '<button type="button" class="btn ' . esc_attr($button_class) . '" data-user-id="' . intval($user['id']) . '" data-requested-role="' . esc_attr($promotion['role']) . '" data-requires-approval="' . ($promotion['requires_approval'] ? 'true' : 'false') . '" data-promotion-name="' . esc_attr($promotion['name']) . '">' . esc_html($button_text) . '</button>';
                }
            }
        } catch (Exception $e) {
            // Log error but don't break the table
            error_log('Role User Manager: Error getting promotions for user ' . $user['id'] . ': ' . $e->getMessage());
        }
        
        $table_html .= '</div></td></tr>';
    }
    
    $table_html .= '</tbody></table>';

    wp_send_json_success([
        'table_html' => $table_html,
        'pagination_html' => $pagination_html,
        'total_users' => $total_users,
        'showing_users' => count($children_users),
        'current_page' => $paged,
        'total_pages' => $total_pages
    ]);
}
add_action('wp_ajax_arc_filter_users', 'arc_filter_users_ajax_handler');

// --- Data Viewer Aggregated Dashboard Section ---
function arc_render_data_viewer_aggregated_dashboard() {
    if (!current_user_can('data-viewer')) return;
    ?>
    <div class="data-viewer-aggregated-dashboard" style="margin-top:40px;">
        <h2>Aggregated Course Data</h2>
        <form id="data-viewer-date-filter" style="margin-bottom:20px;">
            <label for="dv_date_start">Start Date:</label>
            <input type="date" id="dv_date_start" name="dv_date_start">
            <label for="dv_date_end">End Date:</label>
            <input type="date" id="dv_date_end" name="dv_date_end">
            <button type="button" id="dv_filter_btn">Apply Filter</button>
        </form>
        <div id="dv_agg_table_container">
            <!-- Aggregated data table will be rendered here -->
        </div>
        <div id="dv_agg_chart_container" style="margin-top:30px;">
            <!-- Aggregated data chart will be rendered here -->
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
    // Placeholder: AJAX call to fetch aggregated data and render table/chart
    jQuery(document).ready(function($) {
        function fetchAggregatedData() {
            var start = $('#dv_date_start').val();
            var end = $('#dv_date_end').val();
            $.post(arc_dashboard_vars.ajaxurl, {
                action: 'arc_get_aggregated_data',
                _wpnonce: arc_dashboard_vars.nonce,
                date_start: start,
                date_end: end
            }, function(response) {
                if (response.success) {
                    $('#dv_agg_table_container').html(response.data.table_html);
                    // Render chart
                    if (response.data.chart_data) {
                        renderAggChart(response.data.chart_data);
                    }
                } else {
                    $('#dv_agg_table_container').html('<div class="alert alert-danger">Failed to load data.</div>');
                }
            });
        }
        function renderAggChart(chartData) {
            $('#dv_agg_chart_container').html('<canvas id="dvAggChart"></canvas>');
            var ctx = document.getElementById('dvAggChart').getContext('2d');
            new Chart(ctx, chartData);
        }
        $('#dv_filter_btn').on('click', fetchAggregatedData);
        // Initial load
        fetchAggregatedData();
    });
    </script>
    <?php
}
add_action('plugin_dashboard_after', 'arc_render_data_viewer_aggregated_dashboard');

// AJAX handler for aggregated data
add_action('wp_ajax_arc_get_aggregated_data', function() {
    if (!current_user_can('data-viewer')) wp_send_json_error(['message' => 'Unauthorized']);
    $date_start = isset($_POST['date_start']) ? sanitize_text_field($_POST['date_start']) : '';
    $date_end = isset($_POST['date_end']) ? sanitize_text_field($_POST['date_end']) : '';
    // Example aggregation logic (replace with real queries as needed)
    global $wpdb;
    $enrollments = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = 'enrolled_courses'");
    $completions = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = 'completed_courses'");
    $sites = $wpdb->get_var("SELECT COUNT(DISTINCT meta_value) FROM {$wpdb->usermeta} WHERE meta_key = 'sites'");
    // Table HTML
    $table_html = '<table class="table table-bordered"><thead><tr><th>Metric</th><th>Total</th></tr></thead><tbody>';
    $table_html .= '<tr><td>Enrollments</td><td>' . intval($enrollments) . '</td></tr>';
    $table_html .= '<tr><td>Completions</td><td>' . intval($completions) . '</td></tr>';
    $table_html .= '<tr><td>Sites</td><td>' . intval($sites) . '</td></tr>';
    $table_html .= '</tbody></table>';
    // Chart.js config example
    $chart_data = [
        'type' => 'bar',
        'data' => [
            'labels' => ['Enrollments', 'Completions', 'Sites'],
            'datasets' => [[
                'label' => 'Totals',
                'data' => [intval($enrollments), intval($completions), intval($sites)],
                'backgroundColor' => ['#007bff', '#28a745', '#ffc107']
            ]]
        ],
        'options' => [
            'responsive' => true,
            'plugins' => [ 'legend' => ['display' => false] ]
        ]
    ];
    wp_send_json_success([
        'table_html' => $table_html,
        'chart_data' => $chart_data
    ]);
});
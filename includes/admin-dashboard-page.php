<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * Register main admin menu for Role User Manager Dashboard
 */
function rum_register_admin_dashboard_menu(): void {
    if (!current_user_can('list_users')) return;

    add_users_page(
        __('Role User Manager', 'role-user-manager'),
        __('RUM Dashboard', 'role-user-manager'),
        'list_users',
        'rum-dashboard',
        'rum_render_admin_dashboard_page'
    );
}
add_action('admin_menu', 'rum_register_admin_dashboard_menu');

// Assets are enqueued via arc_enqueue_admin_assets() in main plugin file

/**
 * Render the admin dashboard page with tabs
 */
function rum_render_admin_dashboard_page(): void {
    if (!current_user_can('list_users')) {
        wp_die(__('You do not have permission to access this page.', 'role-user-manager'));
    }

    $active_tab = sanitize_text_field($_GET['tab'] ?? 'users');

    echo '<div class="wrap">';
    echo '<h1>' . esc_html__('Role User Manager', 'role-user-manager') . '</h1>';
    echo '<h2 class="nav-tab-wrapper">';
    echo '<a href="' . esc_url(admin_url('users.php?page=rum-dashboard&tab=users')) . '" class="nav-tab ' . ($active_tab === 'users' ? 'nav-tab-active' : '') . '">' . esc_html__('User List', 'role-user-manager') . '</a>';
    echo '<a href="' . esc_url(admin_url('users.php?page=rum-dashboard&tab=viewer')) . '" class="nav-tab ' . ($active_tab === 'viewer' ? 'nav-tab-active' : '') . '">' . esc_html__('Data Viewer', 'role-user-manager') . '</a>';
    echo '</h2>';

    if ($active_tab === 'viewer') {
        rum_render_data_viewer_tab();
    } else {
        rum_render_user_list_tab();
    }

    echo '</div>';
}

/**
 * Tab 1: User List with filters and export
 */
function rum_render_user_list_tab(): void {
    require_once plugin_dir_path(__FILE__) . 'admin-user-list-table.php';

    $roles   = wp_roles()->get_names();
    $parents = get_users(['orderby' => 'display_name', 'order' => 'ASC']);

    // Get Programs/Sites options from existing helpers if available
    $programs = function_exists('arc_get_filter_options') ? (array) (arc_get_filter_options()['programs'] ?? []) : [];
    $sites    = function_exists('arc_get_filter_options') ? (array) (arc_get_filter_options()['sites'] ?? []) : [];

    $selected = [
        'role'    => sanitize_text_field($_GET['filter_role'] ?? ''),
        'parent'  => intval($_GET['filter_parent'] ?? 0),
        'program' => sanitize_text_field($_GET['filter_program'] ?? ''),
        'site'    => sanitize_text_field($_GET['filter_site'] ?? ''),
        'search'  => sanitize_text_field($_GET['s'] ?? ''),
    ];

    $table = new RUM_User_List_Table($selected);
    $table->prepare_items();

    echo '<form method="get">';
    echo '<input type="hidden" name="page" value="rum-dashboard" />';
    echo '<input type="hidden" name="tab" value="users" />';

    echo '<div class="tablenav top">';
    echo '<div class="alignleft actions">';

    // Role filter
    echo '<label class="screen-reader-text" for="filter_role">' . esc_html__('Filter by role', 'role-user-manager') . '</label>';
    echo '<select name="filter_role" id="filter_role">';
    echo '<option value="">' . esc_html__('All Roles', 'role-user-manager') . '</option>';
    foreach ($roles as $key => $label) {
        echo '<option value="' . esc_attr($key) . '" ' . selected($selected['role'], $key, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select>';

    // Parent filter
    echo '<label class="screen-reader-text" for="filter_parent">' . esc_html__('Filter by parent', 'role-user-manager') . '</label>';
    echo '<select name="filter_parent" id="filter_parent">';
    echo '<option value="0">' . esc_html__('All Parents', 'role-user-manager') . '</option>';
    foreach ($parents as $p) {
        echo '<option value="' . intval($p->ID) . '" ' . selected($selected['parent'], (int)$p->ID, false) . '>' . esc_html($p->display_name) . '</option>';
    }
    echo '</select>';

    // Program filter
    echo '<label class="screen-reader-text" for="filter_program">' . esc_html__('Filter by program', 'role-user-manager') . '</label>';
    echo '<select name="filter_program" id="filter_program">';
    echo '<option value="">' . esc_html__('All Programs', 'role-user-manager') . '</option>';
    foreach ($programs as $prog) {
        echo '<option value="' . esc_attr((string)$prog) . '" ' . selected($selected['program'], (string)$prog, false) . '>' . esc_html((string)$prog) . '</option>';
    }
    echo '</select>';

    // Site filter
    echo '<label class="screen-reader-text" for="filter_site">' . esc_html__('Filter by site', 'role-user-manager') . '</label>';
    echo '<select name="filter_site" id="filter_site">';
    echo '<option value="">' . esc_html__('All Sites', 'role-user-manager') . '</option>';
    foreach ($sites as $s) {
        echo '<option value="' . esc_attr((string)$s) . '" ' . selected($selected['site'], (string)$s, false) . '>' . esc_html((string)$s) . '</option>';
    }
    echo '</select>';

    submit_button(__('Filter'), 'secondary', '', false);
    echo '</div>';

    echo '<div class="alignleft actions">';
    echo '<a href="#" id="rum-export-csv" class="button button-primary">' . esc_html__('Export CSV', 'role-user-manager') . '</a>';
    echo '</div>';

    echo '<p class="search-box">';
    echo '<label class="screen-reader-text" for="user-search-input">' . esc_html__('Search Users:', 'role-user-manager') . '</label>';
    echo '<input type="search" id="user-search-input" name="s" value="' . esc_attr($selected['search']) . '" />';
    submit_button(__('Search Users'), 'button', '', false, ['id' => 'search-submit']);
    echo '</p>';

    echo '</div>';

    $table->display();
    echo '</form>';

    // Export nonce field for JS
    echo '<input type="hidden" id="rum_export_nonce" value="' . esc_attr(wp_create_nonce('arc_dashboard_nonce')) . '" />';

    // Quick edit modal container (populated by existing assets/js/admin.js if needed)
    echo '<div id="rum-quick-edit-modal" style="display:none; position:relative; z-index:100000;"></div>';
}

/**
 * Tab 2: Data Viewer (Hierarchy)
 */
function rum_render_data_viewer_tab(): void {
    // Role dropdown: Program Leader or Site Supervisor
    $role_choices = [
        'program-leader' => __('Program Leader', 'role-user-manager'),
        'site-supervisor' => __('Site Supervisor', 'role-user-manager'),
    ];

    echo '<div class="card">';
    echo '<div class="card-body">';
    echo '<div class="rum-data-viewer-filters">';
    echo '<label>' . esc_html__('Role:', 'role-user-manager') . '</label> ';
    echo '<select id="rum-viewer-role">';
    foreach ($role_choices as $key => $label) {
        echo '<option value="' . esc_attr($key) . '">' . esc_html($label) . '</option>';
    }
    echo '</select> ';

    echo '<label>' . esc_html__('User:', 'role-user-manager') . '</label> ';
    echo '<select id="rum-viewer-user"><option value="">' . esc_html__('Select a user', 'role-user-manager') . '</option></select> ';

    echo '<button class="button" id="rum-load-hierarchy">' . esc_html__('Load Hierarchy', 'role-user-manager') . '</button>';
    echo '</div>';
    echo '<div id="rum-hierarchy-container" style="margin-top:16px;"></div>';
    echo '</div>';
    echo '</div>';
}

/**
 * AJAX: get users by selected role
 */
function rum_ajax_viewer_get_users_by_role(): void {
    if (!current_user_can('list_users')) wp_send_json_error(['message' => 'forbidden']);
    if (!check_ajax_referer('arc_dashboard_nonce', 'nonce', false)) wp_send_json_error(['message' => 'nonce']);

    $role = sanitize_text_field($_POST['role'] ?? '');
    if (empty($role)) wp_send_json_success(['users' => []]);

    $users = get_users([
        'role'    => $role,
        'orderby' => 'display_name',
        'order'   => 'ASC',
        'fields'  => ['ID', 'display_name']
    ]);

    $out = array_map(function($u) {
        return ['id' => (int)$u->ID, 'name' => $u->display_name];
    }, $users);

    wp_send_json_success(['users' => $out]);
}
add_action('wp_ajax_rum_viewer_get_users_by_role', 'rum_ajax_viewer_get_users_by_role');

/**
 * AJAX: load descendants hierarchy
 */
function rum_ajax_viewer_get_hierarchy(): void {
    if (!current_user_can('list_users')) wp_send_json_error(['message' => 'forbidden']);
    if (!check_ajax_referer('arc_dashboard_nonce', 'nonce', false)) wp_send_json_error(['message' => 'nonce']);

    $user_id = intval($_POST['user_id'] ?? 0);
    if ($user_id <= 0) wp_send_json_error(['message' => 'invalid']);

    $all = get_users(['number' => -1]);

    // Build a parent->children map with hierarchy validation
    $children = [];
    foreach ($all as $u) {
        $pid = intval(get_user_meta($u->ID, 'parent_user_id', true));
        $role = !empty($u->roles) ? $u->roles[0] : '';
        // Apply the same global hierarchy rules
        if ($role === 'data-viewer' || $role === 'program-leader') {
            $pid = 0;
        } elseif ($role === 'site-supervisor') {
            $p = $pid ? get_user_by('id', $pid) : null;
            if (!$p || empty($p->roles) || $p->roles[0] !== 'program-leader') { continue; }
        } elseif ($role === 'frontline-staff') {
            $p = $pid ? get_user_by('id', $pid) : null;
            if (!$p || empty($p->roles) || $p->roles[0] !== 'site-supervisor') { continue; }
        }
        if ($pid > 0) {
            if (!isset($children[$pid])) $children[$pid] = [];
            $children[$pid][] = $u->ID;
        }
    }

    $tree = rum_build_tree($user_id, $children);
    wp_send_json_success(['tree' => $tree]);
}
add_action('wp_ajax_rum_viewer_get_hierarchy', 'rum_ajax_viewer_get_hierarchy');

function rum_build_tree(int $root_id, array $children): array {
    $user = get_user_by('id', $root_id);
    if (!$user) return [];
    $role = !empty($user->roles) ? $user->roles[0] : '';
    $label = sprintf('%s (%s)', $user->display_name, $role);

    $node = [
        'id' => $root_id,
        'label' => $label,
        'children' => [],
    ];

    if (!empty($children[$root_id])) {
        foreach ($children[$root_id] as $child_id) {
            $node['children'][] = rum_build_tree((int)$child_id, $children);
        }
    }
    return $node;
}

/**
 * AJAX: Get quick edit options (roles, parents, programs, sites)
 */
function rum_ajax_admin_get_options(): void {
    if (!current_user_can('edit_users')) wp_send_json_error(['message' => 'forbidden']);
    if (!check_ajax_referer('arc_dashboard_nonce', 'nonce', false)) wp_send_json_error(['message' => 'nonce']);

    $roles = wp_roles()->get_names();
    $parents = get_users(['orderby' => 'display_name', 'order' => 'ASC']);
    $parents_out = array_map(function($u){
        $r = !empty($u->roles) ? $u->roles[0] : '';
        return ['id' => (int)$u->ID, 'name' => $u->display_name, 'role' => $r];
    }, $parents);

    $programs = function_exists('arc_get_filter_options') ? (array) (arc_get_filter_options()['programs'] ?? []) : [];

    wp_send_json_success([
        'roles'    => $roles,
        'parents'  => $parents_out,
        'programs' => array_values($programs),
    ]);
}
add_action('wp_ajax_rum_admin_get_options', 'rum_ajax_admin_get_options');

/**
 * AJAX: Get a user's current data for quick edit
 */
function rum_ajax_admin_get_user(): void {
    if (!current_user_can('edit_users')) wp_send_json_error(['message' => 'forbidden']);
    if (!check_ajax_referer('arc_dashboard_nonce', 'nonce', false)) wp_send_json_error(['message' => 'nonce']);
    $user_id = intval($_POST['user_id'] ?? 0);
    $u = $user_id ? get_user_by('id', $user_id) : null;
    if (!$u) wp_send_json_error(['message' => 'invalid']);
    $role = !empty($u->roles) ? $u->roles[0] : '';
    $parent_id = intval(get_user_meta($u->ID, 'parent_user_id', true));
    $program = get_user_meta($u->ID, 'programme', true);
    if ($program === '') { $program = get_user_meta($u->ID, 'program', true); }
    $sites = get_user_meta($u->ID, 'sites', true);
    if (!is_array($sites) || empty($sites)) {
        $s = get_user_meta($u->ID, 'site', true);
        $sites = $s ? [$s] : [];
    }
    wp_send_json_success([
        'id' => (int)$u->ID,
        'role' => $role,
        'parent' => $parent_id,
        'program' => (string)$program,
        'sites' => array_values($sites),
    ]);
}
add_action('wp_ajax_rum_admin_get_user', 'rum_ajax_admin_get_user');

/**
 * AJAX: Get parent-derived data (role, program, sites)
 */
function rum_ajax_admin_get_parent_data(): void {
    if (!current_user_can('edit_users')) wp_send_json_error(['message' => 'forbidden']);
    if (!check_ajax_referer('arc_dashboard_nonce', 'nonce', false)) wp_send_json_error(['message' => 'nonce']);
    $parent_id = intval($_POST['parent_id'] ?? 0);
    $u = $parent_id ? get_user_by('id', $parent_id) : null;
    if (!$u) wp_send_json_success(['role' => '', 'program' => '', 'sites' => []]);
    $role = !empty($u->roles) ? $u->roles[0] : '';
    $program = get_user_meta($u->ID, 'programme', true);
    if ($program === '') { $program = get_user_meta($u->ID, 'program', true); }
    // Candidate sites: parent's sites or all sites for program via helper
    $sites = get_user_meta($u->ID, 'sites', true);
    if (!is_array($sites) || empty($sites)) {
        if (function_exists('arc_get_sites_for_program') && $program) {
            $sites = arc_get_sites_for_program($program);
        } else {
            $s = get_user_meta($u->ID, 'site', true);
            $sites = $s ? [$s] : [];
        }
    }
    wp_send_json_success(['role' => $role, 'program' => (string)$program, 'sites' => array_values($sites)]);
}
add_action('wp_ajax_rum_admin_get_parent_data', 'rum_ajax_admin_get_parent_data');

/**
 * AJAX: Quick update user with role-based constraints
 */
function rum_ajax_quick_update_user(): void {
    if (!current_user_can('edit_users')) wp_send_json_error(['message' => 'forbidden']);
    if (!check_ajax_referer('arc_dashboard_nonce', 'nonce', false)) wp_send_json_error(['message' => 'nonce']);

    $user_id = intval($_POST['user_id'] ?? 0);
    $role    = sanitize_text_field($_POST['role'] ?? '');
    $parent  = intval($_POST['parent_user_id'] ?? 0);
    $program = sanitize_text_field($_POST['program'] ?? '');
    $sites   = $_POST['sites'] ?? [];
    if (!is_array($sites)) { $sites = array_filter(array_map('trim', explode(',', (string)$sites))); }

    $user = $user_id ? get_user_by('id', $user_id) : null;
    if (!$user) wp_send_json_error(['message' => 'invalid_user']);

    // Enforce role rules
    if ($role === 'program-leader') {
        $parent = 0;
        // program required; sites can be multiple
        if ($program === '') wp_send_json_error(['message' => 'Program required for Program Leader']);
    } elseif ($role === 'data-viewer') {
        // Data Viewer must not have a parent
        $parent = 0;
    } elseif ($role === 'site-supervisor') {
        // parent must be program-leader
        $pu = $parent ? get_user_by('id', $parent) : null;
        if (!$pu || empty($pu->roles) || $pu->roles[0] !== 'program-leader') {
            wp_send_json_error(['message' => 'Parent must be a Program Leader']);
        }
        // inherit program from parent
        $program = get_user_meta($pu->ID, 'programme', true);
        if ($program === '') { $program = get_user_meta($pu->ID, 'program', true); }
        // exactly one site; must belong to program
        if (function_exists('arc_get_sites_for_program')) {
            $valid_sites = arc_get_sites_for_program($program);
            if (empty($sites) || count($sites) !== 1 || !in_array($sites[0], $valid_sites, true)) {
                wp_send_json_error(['message' => 'Select one site from the parent program']);
            }
        } else {
            if (empty($sites) || count($sites) !== 1) wp_send_json_error(['message' => 'Select one site']);
        }
    } elseif ($role === 'frontline-staff') {
        // parent must be site-supervisor
        $pu = $parent ? get_user_by('id', $parent) : null;
        if (!$pu || empty($pu->roles) || $pu->roles[0] !== 'site-supervisor') {
            wp_send_json_error(['message' => 'Parent must be a Site Supervisor']);
        }
        // inherit program and site(s) from parent
        $program = get_user_meta($pu->ID, 'programme', true);
        if ($program === '') { $program = get_user_meta($pu->ID, 'program', true); }
        $sites = get_user_meta($pu->ID, 'sites', true);
        if (!is_array($sites) || empty($sites)) {
            $s = get_user_meta($pu->ID, 'site', true);
            $sites = $s ? [$s] : [];
        }
    }

    // Persist: role, parent, program, sites (both key variants for compatibility)
    $user->set_role($role);
    update_user_meta($user->ID, 'parent_user_id', $parent);
    update_user_meta($user->ID, 'programme', $program);
    update_user_meta($user->ID, 'program', $program);
    update_user_meta($user->ID, 'sites', array_values($sites));
    update_user_meta($user->ID, 'site', !empty($sites) ? (string)$sites[0] : '');

    wp_send_json_success(['message' => 'updated']);
}
add_action('wp_ajax_rum_quick_update_user', 'rum_ajax_quick_update_user');




<?php
/**
 * Compatibility layer for old dashboard code
 */

// Create a simple RoleAssignmentWorkflow class for compatibility
class RoleAssignmentWorkflow {
    
    public function get_available_promotions_for_user($user_id) {
        $workflow = \RoleUserManager::getInstance()->getComponent('workflow');
        if ($workflow) {
            return $workflow->get_available_promotions_for_user($user_id);
        }
        return [];
    }
    
    public function get_user_primary_role($user_id) {
        $workflow = \RoleUserManager::getInstance()->getComponent('workflow');
        if ($workflow) {
            return $workflow->get_user_primary_role($user_id);
        }
        return '';
    }
    
    public function validate_promotion_request($requester, $user, $current_role, $requested_role) {
        $workflow = \RoleUserManager::getInstance()->getComponent('workflow');
        if ($workflow) {
            $errors = $workflow->validate_promotion_request($requester, $user, $current_role, $requested_role);
            return ['valid' => empty($errors), 'message' => implode(', ', $errors)];
        }
        return ['valid' => false, 'message' => 'Workflow not available'];
    }
    
    public function has_pending_request($user_id, $requested_role) {
        $workflow = \RoleUserManager::getInstance()->getComponent('workflow');
        if ($workflow) {
            return $workflow->has_pending_request($user_id, $requested_role);
        }
        return false;
    }
    
    public function create_promotion_request($requester_id, $user_id, $current_role, $requested_role, $reason) {
        $workflow = \RoleUserManager::getInstance()->getComponent('workflow');
        if ($workflow) {
            return $workflow->create_promotion_request($requester_id, $user_id, $current_role, $requested_role, $reason);
        }
        return false;
    }
    
    public function log_audit($message) {
        $workflow = \RoleUserManager::getInstance()->getComponent('workflow');
        if ($workflow) {
            $workflow->log_audit($message);
        }
    }
}

// Helper function to get sites for program (compatibility with old code)
function arc_get_sites_for_program($program) {
    global $wpdb;
    
    if (empty($program)) {
        return [];
    }
    
    $sites = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT site FROM {$wpdb->prefix}rum_program_sites WHERE program = %s ORDER BY site",
        $program
    ));
    
    return $sites;
}

// Helper function to get filter options (compatibility with old code)
function arc_get_filter_options($program_filter = '') {
    global $wpdb;
    
    $programs = $wpdb->get_col(
        "SELECT DISTINCT program FROM {$wpdb->prefix}rum_program_sites ORDER BY program"
    );
    
    // Get sites based on program filter
    if (!empty($program_filter)) {
        $sites = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT site FROM {$wpdb->prefix}rum_program_sites WHERE program = %s ORDER BY site",
            $program_filter
        ));
    } else {
        $sites = $wpdb->get_col(
            "SELECT DISTINCT site FROM {$wpdb->prefix}rum_program_sites ORDER BY site"
        );
    }
    
    return [
        'programs' => $programs,
        'sites' => $sites
    ];
}

// Helper function to check training status (compatibility with old code)
function arc_check_training_status($user_id, $training_status) {
    if (empty($training_status)) {
        return true;
    }
    
    // Simplified version - always return true for now
    return true;
}

// Helper function to check date range (compatibility with old code)
function arc_check_date_range($user_id, $date_start, $date_end) {
    if (empty($date_start) && empty($date_end)) {
        return true;
    }
    
    // Simplified version - always return true for now
    return true;
}

// Helper function to get descendant user IDs (compatibility with old code)
function arc_get_descendant_user_ids($parent_id, $all_users, $depth = 0) {
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
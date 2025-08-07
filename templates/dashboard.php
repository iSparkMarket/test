<?php
/**
 * Dashboard Template
 * 
 * @package RoleUserManager
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="rum-dashboard">
    <div class="dashboard-header">
        <h1><?php _e('User Management Dashboard', 'role-user-manager'); ?></h1>
        
        <!-- Filters -->
        <div class="dashboard-filters">
            <form id="bulk-action-form" method="get">
                <div class="filter-row">
                    <div class="filter-group">
                        <label for="filter_program"><?php _e('Program:', 'role-user-manager'); ?></label>
                        <select name="filter_program" id="filter_program">
                            <option value=""><?php _e('All Programs', 'role-user-manager'); ?></option>
                            <?php foreach ($programs as $program): ?>
                                <option value="<?php echo esc_attr($program); ?>" <?php selected($filter_program, $program); ?>>
                                    <?php echo esc_html($program); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="filter_site"><?php _e('Site:', 'role-user-manager'); ?></label>
                        <select name="filter_site" id="filter_site">
                            <option value=""><?php _e('All Sites', 'role-user-manager'); ?></option>
                            <?php foreach ($sites as $site): ?>
                                <option value="<?php echo esc_attr($site); ?>" <?php selected($filter_site, $site); ?>>
                                    <?php echo esc_html($site); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="filter_training_status"><?php _e('Training Status:', 'role-user-manager'); ?></label>
                        <select name="filter_training_status" id="filter_training_status">
                            <option value=""><?php _e('All Statuses', 'role-user-manager'); ?></option>
                            <option value="not_started" <?php selected($filter_status, 'not_started'); ?>>
                                <?php _e('Not Started', 'role-user-manager'); ?>
                            </option>
                            <option value="in_progress" <?php selected($filter_status, 'in_progress'); ?>>
                                <?php _e('In Progress', 'role-user-manager'); ?>
                            </option>
                            <option value="completed" <?php selected($filter_status, 'completed'); ?>>
                                <?php _e('Completed', 'role-user-manager'); ?>
                            </option>
                            <option value="failed" <?php selected($filter_status, 'failed'); ?>>
                                <?php _e('Failed', 'role-user-manager'); ?>
                            </option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="filter_date_from"><?php _e('Date From:', 'role-user-manager'); ?></label>
                        <input type="date" name="filter_date_from" id="filter_date_from" value="<?php echo esc_attr($filter_date_from); ?>" />
                    </div>
                    
                    <div class="filter-group">
                        <label for="filter_date_to"><?php _e('Date To:', 'role-user-manager'); ?></label>
                        <input type="date" name="filter_date_to" id="filter_date_to" value="<?php echo esc_attr($filter_date_to); ?>" />
                    </div>
                    
                    <div class="filter-actions">
                        <button type="submit" class="button button-primary">
                            <?php _e('Apply Filters', 'role-user-manager'); ?>
                        </button>
                        <a href="?" class="button"><?php _e('Clear Filters', 'role-user-manager'); ?></a>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <!-- User Grid -->
    <div class="dashboard-content">
        <div class="user-grid">
            <?php if (empty($users)): ?>
                <div class="no-users">
                    <p><?php _e('No users found matching the current filters.', 'role-user-manager'); ?></p>
                </div>
            <?php else: ?>
                <?php foreach ($users as $user): ?>
                    <?php
                    $user_program = get_user_meta($user->ID, 'program', true);
                    $user_site = get_user_meta($user->ID, 'site', true);
                    $user_status = get_user_meta($user->ID, 'training_status', true);
                    $user_date = get_user_meta($user->ID, 'training_date', true);
                    $user_role = $user->roles[0] ?? '';
                    
                    // Get user stats
                    $stats = $this->get_user_stats($user->ID);
                    
                    // Get available promotions
                    $promotions = $this->get_available_promotions($user->ID);
                    ?>
                    
                    <div class="user-card" data-user-id="<?php echo esc_attr($user->ID); ?>">
                        <div class="user-header">
                            <h3><?php echo esc_html($user->display_name); ?></h3>
                            <p class="user-email"><?php echo esc_html($user->user_email); ?></p>
                            <p class="user-role"><?php echo esc_html($this->get_role_display_name($user_role)); ?></p>
                        </div>
                        
                        <div class="user-details">
                            <div class="detail-row">
                                <span class="label"><?php _e('Program:', 'role-user-manager'); ?></span>
                                <span class="value"><?php echo esc_html($user_program ?: 'N/A'); ?></span>
                            </div>
                            <div class="detail-row">
                                <span class="label"><?php _e('Site:', 'role-user-manager'); ?></span>
                                <span class="value"><?php echo esc_html($user_site ?: 'N/A'); ?></span>
                            </div>
                            <div class="detail-row">
                                <span class="label"><?php _e('Training Status:', 'role-user-manager'); ?></span>
                                <span class="value status-<?php echo esc_attr($user_status ?: 'none'); ?>">
                                    <?php echo esc_html($this->get_status_display_name($user_status)); ?>
                                </span>
                            </div>
                            <?php if ($user_date): ?>
                                <div class="detail-row">
                                    <span class="label"><?php _e('Training Date:', 'role-user-manager'); ?></span>
                                    <span class="value"><?php echo esc_html($user_date); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="user-stats">
                            <div class="stat-item">
                                <span class="stat-number"><?php echo esc_html($stats['courses_enrolled']); ?></span>
                                <span class="stat-label"><?php _e('Courses', 'role-user-manager'); ?></span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-number"><?php echo esc_html($stats['courses_completed']); ?></span>
                                <span class="stat-label"><?php _e('Completed', 'role-user-manager'); ?></span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-number"><?php echo esc_html($stats['assignments_submitted']); ?></span>
                                <span class="stat-label"><?php _e('Assignments', 'role-user-manager'); ?></span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-number"><?php echo esc_html($stats['certificates_earned']); ?></span>
                                <span class="stat-label"><?php _e('Certificates', 'role-user-manager'); ?></span>
                            </div>
                        </div>
                        
                        <div class="user-actions">
                            <button class="btn btn-view" data-user-id="<?php echo esc_attr($user->ID); ?>">
                                <?php _e('View', 'role-user-manager'); ?>
                            </button>
                            <button class="btn btn-edit" data-user-id="<?php echo esc_attr($user->ID); ?>">
                                <?php _e('Edit', 'role-user-manager'); ?>
                            </button>
                            <button class="btn btn-delete" data-user-id="<?php echo esc_attr($user->ID); ?>">
                                <?php _e('Delete', 'role-user-manager'); ?>
                            </button>
                            
                            <?php if (!empty($promotions)): ?>
                                <div class="promotion-actions">
                                    <span class="promotion-label"><?php _e('Promote to:', 'role-user-manager'); ?></span>
                                    <?php foreach ($promotions as $promotion): ?>
                                        <button class="btn btn-promote-direct" 
                                                data-user-id="<?php echo esc_attr($user->ID); ?>"
                                                data-requested-role="<?php echo esc_attr($promotion['role']); ?>"
                                                data-promotion-name="<?php echo esc_attr($promotion['display_name']); ?>">
                                            <?php echo esc_html($promotion['display_name']); ?>
                                        </button>
                                        <button class="btn btn-promote-request" 
                                                data-user-id="<?php echo esc_attr($user->ID); ?>"
                                                data-current-role="<?php echo esc_attr($user_role); ?>"
                                                data-requested-role="<?php echo esc_attr($promotion['role']); ?>"
                                                data-promotion-name="<?php echo esc_attr($promotion['display_name']); ?>">
                                            <?php echo esc_html($promotion['display_name']); ?> (Request)
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal for user details -->
<div class="dashboard-modal" style="display: none;">
    <div class="modal-backdrop"></div>
    <div class="modal-dialog">
        <div class="modal-header">
            <h3><?php _e('User Details', 'role-user-manager'); ?></h3>
            <button class="modal-close">&times;</button>
        </div>
        <div class="modal-content">
            <!-- Content will be loaded via AJAX -->
        </div>
    </div>
</div>

<!-- Notifications -->
<div class="dashboard-notifications"></div>

<script>
jQuery(document).ready(function($) {
    // Site filter based on program selection
    $('#filter_program').on('change', function() {
        const program = $(this).val();
        const siteSelect = $('#filter_site');
        
        if (program) {
            $.ajax({
                url: rum_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'rum_get_sites_for_program',
                    program: program,
                    nonce: rum_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        siteSelect.html('<option value=""><?php _e('All Sites', 'role-user-manager'); ?></option>');
                        response.data.forEach(function(site) {
                            siteSelect.append('<option value="' + site + '">' + site + '</option>');
                        });
                    }
                }
            });
        } else {
            siteSelect.html('<option value=""><?php _e('All Sites', 'role-user-manager'); ?></option>');
        }
    });
});
</script> 
<?php
declare(strict_types=1);

namespace RoleUserManager;

/**
 * User Meta class
 */
class UserMeta {
    
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
        add_action('show_user_profile', [$this, 'show_custom_user_fields']);
        add_action('edit_user_profile', [$this, 'show_custom_user_fields']);
        add_action('personal_options_update', [$this, 'save_custom_user_fields']);
        add_action('edit_user_profile_update', [$this, 'save_custom_user_fields']);
    }
    
    /**
     * Show custom user fields
     */
    public function show_custom_user_fields($user): void {
        if (!current_user_can('edit_users')) {
            return;
        }
        
        $program = get_user_meta($user->ID, 'program', true);
        $site = get_user_meta($user->ID, 'site', true);
        $parent_user_id = get_user_meta($user->ID, 'parent_user_id', true);
        $training_status = get_user_meta($user->ID, 'training_status', true);
        $training_date = get_user_meta($user->ID, 'training_date', true);
        
        // Get available programs and sites
        $programs = $this->get_programs();
        $sites = $this->get_sites_for_program($program);
        $users = get_users(['orderby' => 'display_name']);
        
        ?>
        <h3><?php _e('Role User Manager Settings', 'role-user-manager'); ?></h3>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="program"><?php _e('Program', 'role-user-manager'); ?></label>
                </th>
                <td>
                    <select name="program" id="program">
                        <option value=""><?php _e('Select Program', 'role-user-manager'); ?></option>
                        <?php foreach ($programs as $prog): ?>
                            <option value="<?php echo esc_attr($prog); ?>" <?php selected($program, $prog); ?>>
                                <?php echo esc_html($prog); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="site"><?php _e('Site', 'role-user-manager'); ?></label>
                </th>
                <td>
                    <select name="site" id="site">
                        <option value=""><?php _e('Select Site', 'role-user-manager'); ?></option>
                        <?php foreach ($sites as $s): ?>
                            <option value="<?php echo esc_attr($s); ?>" <?php selected($site, $s); ?>>
                                <?php echo esc_html($s); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="parent_user_id"><?php _e('Parent User', 'role-user-manager'); ?></label>
                </th>
                <td>
                    <select name="parent_user_id" id="parent_user_id">
                        <option value=""><?php _e('Select Parent User', 'role-user-manager'); ?></option>
                        <?php foreach ($users as $u): ?>
                            <?php if ($u->ID !== $user->ID): ?>
                                <option value="<?php echo esc_attr($u->ID); ?>" <?php selected($parent_user_id, $u->ID); ?>>
                                    <?php echo esc_html($u->display_name); ?> (<?php echo esc_html($u->user_login); ?>)
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="training_status"><?php _e('Training Status', 'role-user-manager'); ?></label>
                </th>
                <td>
                    <select name="training_status" id="training_status">
                        <option value=""><?php _e('Select Status', 'role-user-manager'); ?></option>
                        <option value="not_started" <?php selected($training_status, 'not_started'); ?>>
                            <?php _e('Not Started', 'role-user-manager'); ?>
                        </option>
                        <option value="in_progress" <?php selected($training_status, 'in_progress'); ?>>
                            <?php _e('In Progress', 'role-user-manager'); ?>
                        </option>
                        <option value="completed" <?php selected($training_status, 'completed'); ?>>
                            <?php _e('Completed', 'role-user-manager'); ?>
                        </option>
                        <option value="failed" <?php selected($training_status, 'failed'); ?>>
                            <?php _e('Failed', 'role-user-manager'); ?>
                        </option>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="training_date"><?php _e('Training Date', 'role-user-manager'); ?></label>
                </th>
                <td>
                    <input type="date" name="training_date" id="training_date" 
                           value="<?php echo esc_attr($training_date); ?>" class="regular-text" />
                </td>
            </tr>
        </table>
        
        <script>
        jQuery(document).ready(function($) {
            $('#program').on('change', function() {
                var program = $(this).val();
                var siteSelect = $('#site');
                
                siteSelect.html('<option value=""><?php _e('Loading...', 'role-user-manager'); ?></option>');
                
                if (program) {
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'rum_get_sites_for_program',
                            program: program,
                            nonce: '<?php echo wp_create_nonce('rum_nonce'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                siteSelect.html('<option value=""><?php _e('Select Site', 'role-user-manager'); ?></option>');
                                $.each(response.data, function(index, site) {
                                    siteSelect.append('<option value="' + site + '">' + site + '</option>');
                                });
                            }
                        }
                    });
                } else {
                    siteSelect.html('<option value=""><?php _e('Select Site', 'role-user-manager'); ?></option>');
                }
            });
        });
        </script>
        <?php
    }
    
    /**
     * Save custom user fields
     */
    public function save_custom_user_fields(int $user_id): void {
        if (!current_user_can('edit_users')) {
            return;
        }
        
        if (!wp_verify_nonce($_POST['_wpnonce'], 'update-user_' . $user_id)) {
            return;
        }
        
        $old_program = get_user_meta($user_id, 'program', true);
        $old_site = get_user_meta($user_id, 'site', true);
        $old_parent_user_id = get_user_meta($user_id, 'parent_user_id', true);
        
        // Save program
        $program = Validator::sanitize_text($_POST['program'] ?? '');
        update_user_meta($user_id, 'program', $program);
        
        // Save site
        $site = Validator::sanitize_text($_POST['site'] ?? '');
        update_user_meta($user_id, 'site', $site);
        
        // Save parent user
        $parent_user_id = intval($_POST['parent_user_id'] ?? 0);
        update_user_meta($user_id, 'parent_user_id', $parent_user_id);
        
        // Save training status
        $training_status = Validator::sanitize_text($_POST['training_status'] ?? '');
        update_user_meta($user_id, 'training_status', $training_status);
        
        // Save training date
        $training_date = Validator::sanitize_text($_POST['training_date'] ?? '');
        update_user_meta($user_id, 'training_date', $training_date);
        
        // Update descendants if program/site changed
        if ($old_program !== $program || $old_site !== $site) {
            $this->update_descendants_program_and_sites($user_id, $program, [$site]);
        }
        
        // Log changes
        $changes = [];
        if ($old_program !== $program) {
            $changes[] = "Program: {$old_program} → {$program}";
        }
        if ($old_site !== $site) {
            $changes[] = "Site: {$old_site} → {$site}";
        }
        if ($old_parent_user_id !== $parent_user_id) {
            $changes[] = "Parent User: {$old_parent_user_id} → {$parent_user_id}";
        }
        
        if (!empty($changes)) {
            Logger::log("User {$user_id} profile updated: " . implode(', ', $changes));
        }
    }
    
    /**
     * Update descendants program and sites
     */
    public function update_descendants_program_and_sites(int $parent_id, string $program, array $sites): void {
        $all_users = get_users(['number' => -1]);
        $descendants = $this->get_descendant_user_ids($parent_id, $all_users);
        
        foreach ($descendants as $descendant_id) {
            update_user_meta($descendant_id, 'program', $program);
            update_user_meta($descendant_id, 'site', implode(',', $sites));
        }
        
        if (!empty($descendants)) {
            Logger::log("Updated program/site for " . count($descendants) . " descendants of user {$parent_id}");
        }
    }
    
    /**
     * Get descendant user IDs
     */
    private function get_descendant_user_ids(int $parent_id, array $all_users, int $depth = 0): array {
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
                $descendants = array_merge($descendants, $this->get_descendant_user_ids($user->ID, $all_users, $depth + 1));
            }
        }
        return $descendants;
    }
    
    /**
     * Get available programs
     */
    private function get_programs(): array {
        global $wpdb;
        return $wpdb->get_col(
            "SELECT DISTINCT program FROM {$wpdb->prefix}rum_program_sites ORDER BY program"
        );
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
} 
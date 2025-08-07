<?php
/**
 * Role Manager Template
 * 
 * @package RoleUserManager
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

$roles = wp_roles()->get_names();
$capability_groups = $this->get_all_capabilities();
$role_hierarchy = $this->get_roles_with_hierarchy();
?>

<div class="wrap">
    <h1><?php _e('Role Capabilities', 'role-user-manager'); ?></h1>
    
    <div class="rum-role-manager">
        <div class="rum-sidebar">
            <h2><?php _e('Roles', 'role-user-manager'); ?></h2>
            <div class="rum-role-list">
                <?php foreach ($roles as $role_key => $role_name): ?>
                    <div class="rum-role-item" data-role="<?php echo esc_attr($role_key); ?>">
                        <h3><?php echo esc_html($role_name); ?></h3>
                        <p class="role-key"><?php echo esc_html($role_key); ?></p>
                        <?php if (isset($role_hierarchy[$role_key]['parent'])): ?>
                            <p class="role-parent">
                                <?php 
                                $parent = $role_hierarchy[$role_key]['parent'];
                                $parent_name = $roles[$parent] ?? $parent;
                                printf(__('Parent: %s', 'role-user-manager'), esc_html($parent_name));
                                ?>
                            </p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <div class="rum-create-role">
                <h3><?php _e('Create New Role', 'role-user-manager'); ?></h3>
                <form id="create-role-form">
                    <p>
                        <label for="role_name"><?php _e('Role Name:', 'role-user-manager'); ?></label>
                        <input type="text" id="role_name" name="role_name" required />
                    </p>
                    <p>
                        <label for="role_display_name"><?php _e('Display Name:', 'role-user-manager'); ?></label>
                        <input type="text" id="role_display_name" name="role_display_name" required />
                    </p>
                    <p>
                        <label for="parent_role"><?php _e('Parent Role:', 'role-user-manager'); ?></label>
                        <select id="parent_role" name="parent_role">
                            <option value=""><?php _e('No Parent', 'role-user-manager'); ?></option>
                            <?php foreach ($roles as $role_key => $role_name): ?>
                                <option value="<?php echo esc_attr($role_key); ?>">
                                    <?php echo esc_html($role_name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                    <button type="submit" class="button button-primary">
                        <?php _e('Create Role', 'role-user-manager'); ?>
                    </button>
                </form>
            </div>
        </div>
        
        <div class="rum-main">
            <div class="rum-capabilities">
                <h2><?php _e('Capabilities', 'role-user-manager'); ?></h2>
                <div id="capabilities-container">
                    <p class="rum-select-role">
                        <?php _e('Select a role to manage its capabilities.', 'role-user-manager'); ?>
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Role selection
    $('.rum-role-item').on('click', function() {
        $('.rum-role-item').removeClass('active');
        $(this).addClass('active');
        
        const role = $(this).data('role');
        loadRoleCapabilities(role);
    });
    
    // Load role capabilities
    function loadRoleCapabilities(role) {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'rum_get_role_capabilities',
                role: role,
                nonce: rum_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    displayCapabilities(role, response.data);
                }
            }
        });
    }
    
    // Display capabilities
    function displayCapabilities(role, capabilities) {
        const container = $('#capabilities-container');
        let html = `<h3>${role}</h3>`;
        
        // Add inherit button
        html += `<button class="button inherit-parent-caps" data-role="${role}">Inherit Parent Capabilities</button>`;
        
        // Group capabilities
        const groups = <?php echo json_encode($capability_groups); ?>;
        
        for (const [groupName, groupCaps] of Object.entries(groups)) {
            if (groupCaps.length > 0) {
                html += `<div class="capability-group">`;
                html += `<h4>${groupName.charAt(0).toUpperCase() + groupName.slice(1)}</h4>`;
                html += `<div class="capabilities-list">`;
                
                groupCaps.forEach(cap => {
                    const checked = capabilities.includes(cap) ? 'checked' : '';
                    html += `
                        <label class="capability-item">
                            <input type="checkbox" name="capabilities[]" value="${cap}" ${checked}>
                            <span>${cap}</span>
                        </label>
                    `;
                });
                
                html += `</div></div>`;
            }
        }
        
        html += `<button class="button button-primary save-capabilities" data-role="${role}">Save Capabilities</button>`;
        
        container.html(html);
    }
    
    // Save capabilities
    $(document).on('click', '.save-capabilities', function() {
        const role = $(this).data('role');
        const capabilities = [];
        
        $('input[name="capabilities[]"]:checked').each(function() {
            capabilities.push($(this).val());
        });
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'rum_update_role_capabilities',
                role: role,
                capabilities: capabilities,
                nonce: rum_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    alert('Capabilities updated successfully!');
                } else {
                    alert('Error: ' + response.message);
                }
            }
        });
    });
    
    // Inherit parent capabilities
    $(document).on('click', '.inherit-parent-caps', function() {
        const role = $(this).data('role');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'rum_inherit_parent_capabilities',
                role: role,
                nonce: rum_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    loadRoleCapabilities(role);
                    alert('Parent capabilities inherited successfully!');
                } else {
                    alert('Error: ' + response.message);
                }
            }
        });
    });
    
    // Create role form
    $('#create-role-form').on('submit', function(e) {
        e.preventDefault();
        
        const formData = {
            role_name: $('#role_name').val(),
            role_display_name: $('#role_display_name').val(),
            parent_role: $('#parent_role').val(),
            nonce: rum_ajax.nonce
        };
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'rum_create_role',
                ...formData
            },
            success: function(response) {
                if (response.success) {
                    alert('Role created successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + response.message);
                }
            }
        });
    });
});
</script> 
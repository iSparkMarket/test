<?php
declare(strict_types=1);


add_action('show_user_profile', 'arc_show_custom_user_fields');
add_action('edit_user_profile', 'arc_show_custom_user_fields');
add_action('user_new_form', 'arc_show_custom_user_fields');
add_action('personal_options_update', 'arc_save_custom_user_fields');
add_action('edit_user_profile_update', 'arc_save_custom_user_fields');
add_action('user_register', 'arc_save_custom_user_fields');

function arc_show_custom_user_fields($user)
{
    if (!current_user_can('edit_users')) return;

    // If $user is not an object (e.g., on user-new.php), set defaults
    $user_id = (is_object($user) && isset($user->ID)) ? (int)$user->ID : 0;

    // Fetch data for dynamic dropdowns
    $program_site_map = get_option('dash_program_site_map', []);
    $all_programs = array_keys($program_site_map);

    $programme = $user_id ? get_user_meta($user_id, 'programme', true) : '';
    $saved_sites = $user_id ? get_user_meta($user_id, 'sites', true) : [];
    if (!is_array($saved_sites)) $saved_sites = [];
    $parent_user_id = $user_id ? get_user_meta($user_id, 'parent_user_id', true) : '';
?>
    <h3>Programme, Sites, and Parent User</h3>
    <table class="form-table" id="arc-custom-fields">
        <tr id="arc_parent_user_row">
            <th><label for="arc_parent_user_id">Parent User</label></th>
            <td>
                <select name="arc_parent_user_id" id="arc_parent_user_id">
                    <option value="">No Parent</option>
                    <!-- Options will be populated by JS -->
                </select>
                <script>
                    var currentParentId = <?php echo json_encode($parent_user_id); ?>;
                    var programSiteMap = <?php echo json_encode($program_site_map); ?>;
                    var savedSites = <?php echo json_encode($saved_sites); ?>;
                    var user_id = <?php echo (int)$user_id; ?>;
                    var ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';
                </script>
                <p class="description">Select the parent user based on role hierarchy.</p>
            </td>
        </tr>
        <tr id="arc_programme_row">
            <th><label for="arc_programme">Programme</label></th>
            <td id="arc_programme_cell">
                <!-- Will be replaced by JS for readonly if needed -->
                <select name="arc_programme" id="arc_programme">
                    <option value=""><?php _e('Select a Programme', 'arc'); ?></option>
                    <?php foreach ($all_programs as $prog_name) : ?>
                        <option value="<?php echo esc_attr($prog_name); ?>" <?php selected($programme, $prog_name); ?>><?php echo esc_html($prog_name); ?></option>
                    <?php endforeach; ?>
                </select>
                <span id="arc_programme_readonly" style="display:none;"></span>
                <p class="description">Programme assigned to this user.</p>
            </td>
        </tr>
        <tr id="arc_sites_row">
            <th><label for="arc_sites">Sites</label></th>
            <td id="arc_sites_cell" style="width:100%; max-width:300px;">
                <!-- Will be replaced by JS for readonly or single-select if needed -->
                <select style="width:100%; max-width:300px" name="arc_sites[]" id="arc_sites" multiple="multiple"></select>
                <span id="arc_sites_readonly" style="display:block;"></span>
                <p class="description" id="arc_sites_desc">Select one or more sites. Use Ctrl/Cmd to select multiple.</p>
            </td>
        </tr>
    </table>
<style>
	.select2-container {
    max-width: min-content !important;
		min-width:300px;
}
</style>
    <script>
        (function($) {
            function populateSitesDropdown(availableSites, selectedSites) {
                var sitesSelect = $('#arc_sites');
                sitesSelect.empty();
                if (availableSites && availableSites.length > 0) {
                    $.each(availableSites, function(index, site) {
                        var isSelected = $.inArray(site, selectedSites) !== -1;
                        sitesSelect.append($('<option>', {
                            value: site,
                            text: site,
                            selected: isSelected
                        }));
                    });
                }
            }

            function updateSitesForProgram(programName) {
                var sites = programSiteMap[programName] || [];
                populateSitesDropdown(sites, savedSites);
            }

            function updateSitesForParent(parentId) {
                if (!parentId) {
                    populateSitesDropdown([], savedSites);
                    return;
                }
                $.post(ajaxurl, {
                    action: 'arc_get_sites_for_parent',
                    parent_id: parentId,
                    _wpnonce: '<?php echo wp_create_nonce('arc_get_sites_for_parent'); ?>'
                }, function(response) {
                    if (response.success) {
                        populateSitesDropdown(response.data.sites, savedSites);
                    }
                });
            }

            function updateParentUserOptions(role) {
                var parentSelect = $('#arc_parent_user_id');
                var currentParent = parentSelect.val() || currentParentId; // Keep existing parent if possible
                // Use global ajaxurl and user_id
                $.post(ajaxurl, {
                    action: 'arc_get_parent_users',
                    role: role,
                    user_id: user_id,
                    _wpnonce: '<?php echo wp_create_nonce('arc_get_parent_users'); ?>'
                }, function(response) {
                    if (response.success && response.data && response.data.options_html) {
                        parentSelect.html(response.data.options_html);
                        parentSelect.val(currentParent); // Re-select the parent after populating
                    }
                });
            }

            function updateFieldVisibility(role) {
                // Reset all fields to editable by default
                $('#arc_programme_row').show();
                $('#arc_sites_row').show();
                $('#arc_parent_user_row').show();
                $('#arc_programme').show();
                $('#arc_programme_readonly').hide();
                $('#arc_sites').show();
                $('#arc_sites_readonly').hide();
                $('#arc_sites').attr('multiple', 'multiple');
                $('#arc_sites').prop('disabled', false);
                $('#arc_sites_desc').text('Select one or more sites. Use Ctrl/Cmd to select multiple.');

                if (role === 'frontline-staff') {
                    // Programme and Sites are readonly, inherited from parent
                    $('#arc_programme').hide();
                    $('#arc_programme_readonly').text($('#arc_programme option:selected').text() || '').show();
                    $('#arc_sites').hide();
                    // Show inherited sites as text
                    var sitesText = '';
                    if (savedSites.length > 0) {
                        sitesText = savedSites.join(', ');
                    } else {
                        sitesText = 'No sites assigned.';
                    }
                    $('#arc_sites_readonly').text(sitesText).show();
                    $('#arc_sites_desc').text('Inherited from parent.');
                } else if (role === 'site-supervisor') {
                    // Programme is readonly, Sites is single-select
                    $('#arc_programme').hide();
                    $('#arc_programme_readonly').text($('#arc_programme option:selected').text() || '').show();
                    $('#arc_sites').removeAttr('multiple');
                    $('#arc_sites').prop('disabled', false).show();
                    $('#arc_sites_readonly').hide();
                    $('#arc_sites_desc').text('Select one site.');
                } else if (role === 'program-leader') {
                    // Both editable, multi-select
                    $('#arc_programme').show();
                    $('#arc_programme_readonly').hide();
                    $('#arc_sites').attr('multiple', 'multiple').prop('disabled', false).show();
                    $('#arc_sites_readonly').hide();
                    $('#arc_sites_desc').text('Select one or more sites. Use Ctrl/Cmd to select multiple.');
                } else {
                    $('#arc_programme_row').hide();
                    $('#arc_sites_row').hide();
                    $('#arc_parent_user_row').hide();
                }
            }

            function updateProgrammeAndSitesForParent(parentId, role) {
                if (!parentId) return;
                $.post(ajaxurl, {
                    action: 'arc_get_program_and_sites_for_parent',
                    parent_id: parentId,
                    _wpnonce: '<?php echo wp_create_nonce('arc_get_program_and_sites_for_parent'); ?>'
                }, function(response) {
                    if (response.success) {
                        $('#arc_programme').val(response.data.programme).trigger('change');
                        $('#arc_sites').empty().select2({
                            data: response.data.sites.map(function(site) {
                                return { id: site, text: site };
                            })
                        });
                        $('#arc_sites').trigger('change');
                        $('#arc_programme_readonly').text(response.data.programme).show();
                        $('#arc_sites_readonly').text(response.data.sites.join(', ')).show();
                        $('#arc_sites_desc').text('Inherited from parent.');
                    }
                });
            }
            $(document).ready(function() {
                // Use the main role dropdown provided by WordPress
                var roleDropdown = $('select#role');
                var programmeDropdown = $('#arc_programme');
                var parentDropdown = $('#arc_parent_user_id');

                function initializeFields(role) {
                    updateParentUserOptions(role);
                    updateFieldVisibility(role);

                    if (role === 'program-leader') {
                        updateSitesForProgram(programmeDropdown.val());
                    } else if (role === 'site-supervisor') {
                        updateSitesForParent(parentDropdown.val() || currentParentId);
                    } else {
                        populateSitesDropdown([], savedSites);
                    }
                }

                // Initial load - add small delay to ensure role manager has modified the interface
                setTimeout(function() {
                    initializeFields(roleDropdown.val());
                }, 100);

                roleDropdown.on('change', function() {
                    initializeFields($(this).val());
                });

                programmeDropdown.on('change', function() {
                    updateSitesForProgram($(this).val());
                });

                parentDropdown.on('change', function() {
                    updateSitesForParent($(this).val());
                    if (roleDropdown.val() === 'frontline-staff') {
                        updateProgrammeAndSitesForParent($(this).val(), roleDropdown.val());
                    } else if (roleDropdown.val() === 'site-supervisor') {
                        updateProgrammeAndSitesForParent($(this).val(), roleDropdown.val());
                    }
                });
            });
        })(jQuery);
    </script>
<?php
}

add_action('wp_ajax_arc_get_parent_users', function () {
    check_ajax_referer('arc_get_parent_users');
    $role = sanitize_text_field($_POST['role'] ?? '');
    $user_id = intval($_POST['user_id'] ?? 0);

    $parent_roles_map = [
        'frontline-staff' => 'site-supervisor',
        'site-supervisor'  => 'program-leader',
        'program-leader'   => 'data-viewer',
    ];

    $parent_role_to_get = $parent_roles_map[$role] ?? null;

    $options = '<option value="">No Parent</option>';

    if ($parent_role_to_get) {
        $users = get_users(['role' => $parent_role_to_get]);
    } else {
        $users = [];
    }

    foreach ($users as $user) {
        if ($user->ID == $user_id) continue;
        $options .= '<option value="' . esc_attr($user->ID) . '">' . esc_html($user->display_name) . ' (' . esc_html(implode(', ', $user->roles)) . ')</option>';
    }
    wp_send_json_success(['options_html' => $options]);
});

add_action('wp_ajax_arc_get_sites_for_parent', function () {
    check_ajax_referer('arc_get_sites_for_parent');

    $parent_id = intval($_POST['parent_id'] ?? 0);
    if (!$parent_id) {
        wp_send_json_error(['message' => 'No parent ID provided.']);
    }

    // Get the sites assigned to the parent (from user meta)
    $parent_sites = get_user_meta($parent_id, 'sites', true);
    if (!is_array($parent_sites)) $parent_sites = [];

    // Return only the parent's assigned sites
    wp_send_json_success(['sites' => $parent_sites]);
});

add_action('wp_ajax_arc_get_program_and_sites_for_parent', function () {
    check_ajax_referer('arc_get_program_and_sites_for_parent');
    $parent_id = intval($_POST['parent_id'] ?? 0);
    if (!$parent_id) {
        wp_send_json_error(['message' => 'No parent ID provided.']);
    }
    $parent_programme = get_user_meta($parent_id, 'programme', true);
    $parent_sites = get_user_meta($parent_id, 'sites', true);
    if (!is_array($parent_sites)) $parent_sites = [];
    wp_send_json_success([
        'programme' => $parent_programme,
        'sites' => $parent_sites,
    ]);
});

/**
 * Updates the programme and sites for all descendants of a given user.
 *
 * This function is called recursively to ensure all descendants are updated.
 *
 * @param int $parent_id The ID of the user whose descendants are to be updated.
 * @param string $programme The new programme to set for descendants.
 * @param array $sites The new sites to set for descendants.
 */
function arc_update_descendants_programme_and_sites(int $parent_id, string $programme, array $sites): void {
    $children = get_users([
        'meta_key' => 'parent_user_id',
        'meta_value' => $parent_id,
        'fields' => 'all',
    ]);
    foreach ($children as $child) {
        update_user_meta($child->ID, 'programme', $programme);
        update_user_meta($child->ID, 'sites', $sites);
        // Recursive update for further descendants
        arc_update_descendants_programme_and_sites($child->ID, $programme, $sites);
    }
}

/**
 * Saves custom user fields for Programme, Sites, and Parent User.
 *
 * This function handles the saving of the custom fields on user profile edit and new user registration.
 * It also manages inheritance logic based on the user's role and parent.
 *
 * @param int $user_id The ID of the user being saved.
 */
function arc_save_custom_user_fields(int $user_id): void
{
    if (!current_user_can('edit_users', $user_id)) return;

    // Get the role from the standard WordPress role field
    $role = sanitize_text_field($_POST['role'] ?? '');
    if (!$role) {
        $user_info = get_userdata($user_id);
        $role = $user_info->roles ? $user_info->roles[0] : '';
    }

    // Validate that only one role is assigned (enforce single role policy)
    $user_info = get_userdata($user_id);
    if ($user_info && count($user_info->roles) > 1) {
        // If user has multiple roles, keep only the first one
        $primary_role = reset($user_info->roles);
        $user_info->set_role($primary_role);
        $role = $primary_role;
        
        // Log the enforcement
        $log = get_option('arc_audit_log', []);
        $log[] = [
            'time' => current_time('mysql'),
            'user' => is_user_logged_in() ? wp_get_current_user()->user_login : 'system',
            'message' => 'Enforced single role for user_id ' . $user_id . ': kept role "' . $primary_role . '", removed other roles'
        ];
        if (count($log) > 100) {
            $log = array_slice($log, -100);
        }
        update_option('arc_audit_log', $log);
    }

    // --- Strict validation and sanitization ---
    $parent_user_id = isset($_POST['arc_parent_user_id']) ? intval($_POST['arc_parent_user_id']) : 0;
    $programme = isset($_POST['arc_programme']) ? sanitize_text_field($_POST['arc_programme']) : '';
    $sites_array = isset($_POST['arc_sites']) && is_array($_POST['arc_sites']) ? array_map('sanitize_text_field', $_POST['arc_sites']) : [];

    // Validate program exists
    $program_site_map = get_option('dash_program_site_map', []);
    $all_programs = array_keys($program_site_map);
    if ($programme && !in_array($programme, $all_programs)) {
        $programme = '';
    }

    // Validate sites exist for the selected program
    $valid_sites = ($programme && isset($program_site_map[$programme])) ? $program_site_map[$programme] : [];
    $sites_array = array_intersect($sites_array, $valid_sites);

    // For site-supervisor, only allow one site
    if ($role === 'site-supervisor' && count($sites_array) > 1) {
        $sites_array = array_slice($sites_array, 0, 1);
    }
    // When saving user meta, store sites as array
    update_user_meta($user_id, 'sites', $sites_array);

    // --- Audit logging: capture old values ---
    $old_parent = get_user_meta($user_id, 'parent_user_id', true);
    $old_programme = get_user_meta($user_id, 'programme', true);
    $old_sites = get_user_meta($user_id, 'sites', true);

    // --- Save user meta ---
    update_user_meta($user_id, 'parent_user_id', $parent_user_id);

    // Inheritance logic
    if ($role === 'frontline-staff' && $parent_user_id) {
        $parent_programme = get_user_meta($parent_user_id, 'programme', true);
        $parent_sites = get_user_meta($parent_user_id, 'sites', true);
        update_user_meta($user_id, 'programme', $parent_programme);
        update_user_meta($user_id, 'sites', $parent_sites);
    } elseif ($role === 'site-supervisor') {
        if ($parent_user_id) {
            $parent_programme = get_user_meta($parent_user_id, 'programme', true);
            update_user_meta($user_id, 'sites', $sites_array); // Save its own site
            update_user_meta($user_id, 'programme', $parent_programme);
        } else {
            // No parent: save selected programme and site
            update_user_meta($user_id, 'programme', $programme);
            update_user_meta($user_id, 'sites', $sites_array);
        }
    } else {
        update_user_meta($user_id, 'programme', $programme);
        update_user_meta($user_id, 'sites', $sites_array);
    }

    // --- Audit logging: log changes if any ---
    $new_parent = get_user_meta($user_id, 'parent_user_id', true);
    $new_programme = get_user_meta($user_id, 'programme', true);
    $new_sites = get_user_meta($user_id, 'sites', true);
    $changes = [];
    if ($old_parent != $new_parent) {
        $changes[] = "parent_user_id: '$old_parent' => '$new_parent'";
    }
    if ($old_programme != $new_programme) {
        $changes[] = "programme: '$old_programme' => '$new_programme'";
    }
    if ($old_sites != $new_sites) {
        $changes[] = "sites: '$old_sites' => '$new_sites'";
    }
    if (!empty($changes)) {
        $log = get_option('arc_audit_log', []);
        $log[] = [
            'time' => current_time('mysql'),
            'user' => is_user_logged_in() ? wp_get_current_user()->user_login : 'system',
            'message' => 'User meta updated for user_id ' . $user_id . ': ' . implode('; ', $changes)
        ];
        if (count($log) > 100) {
            $log = array_slice($log, -100);
        }
        update_option('arc_audit_log', $log);
    }

    // --- Cascade update for descendants ---
    if (
        current_user_can('edit_users') &&
        get_current_user_id() !== $user_id
    ) {
        $new_programme = get_user_meta($user_id, 'programme', true);
        $new_sites = get_user_meta($user_id, 'sites', true);
        arc_update_descendants_programme_and_sites($user_id, $new_programme, $new_sites);
    }
}

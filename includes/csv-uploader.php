<?php
declare(strict_types=1);

/**
 * Add CSV upload menu to admin.
 */
add_action('admin_menu', 'cram_add_csv_upload_menu');
function cram_add_csv_upload_menu()
{
    add_menu_page(
        __('Upload Program & Site CSV', 'role-user-manager'),
        __('Upload CSV', 'role-user-manager'),
        'manage_options',
        'upload-csv',
        'cram_upload_csv_ui',
        'dashicons-upload',
        30
    );
}

/**
 * CSV Upload UI and Processing
 */
function cram_upload_csv_ui()
{
    // --- Handle Export and Rollback (must be before any output) ---
    if (isset($_POST['export_csv']) && check_admin_referer('export_csv_nonce', 'export_csv_nonce_field')) {
        cram_export_program_site_csv();
        exit;
    }
    if (isset($_POST['restore_backup']) && check_admin_referer('restore_backup_nonce', 'restore_backup_nonce_field')) {
        cram_restore_program_site_backup();
    }

    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    ?>


    <div class="wrap">
        <div class="page-header">
            <h1>📊 CSV Data Management</h1>
            <p>Upload CSV files or manually manage your Program and Site data with our intuitive interface.</p>
        </div>

        <div class="upload-section">
            <div class="format-requirements">
                <h3>📋 CSV Format Requirements</h3>
                <table>
                    <tbody>
                        <tr>
                            <td><strong>Program Name</strong> - Name of the program</td>
                            <td>|</td>
                            <td><strong>Site Name</strong> - Name of the site</td>
                        </tr>
                    </tbody>
                </table>
                <p><em>Note: Column headers should match exactly (case-sensitive).</em></p>
            </div>

            <form method="post" enctype="multipart/form-data" class="upload-form">
                <?php wp_nonce_field('upload_csv_nonce', 'upload_csv_nonce_field'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="program_site_csv">📁 CSV File</label></th>
                        <td><input type="file" name="program_site_csv" id="program_site_csv" accept=".csv" required></td>
                    </tr>
                </table>
                <?php submit_button(__('📤 Upload & Sync Data', 'role-user-manager'), 'primary', 'upload_csv'); ?>
            </form>
        </div>

        <!-- Export and Rollback UI (moved inside function) -->
        <div style="margin: 20px 0; display: flex; gap: 10px;">
            <!-- Removed Export and Restore buttons -->
            <form method="post" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete ALL programs and sites? This cannot be undone!');">
                <input type="hidden" name="delete_all_program_site" value="1">
                <?php wp_nonce_field('delete_all_program_site_nonce', 'delete_all_program_site_nonce_field'); ?>
                <button type="submit" class="button button-danger"><?php _e('🗑️ Delete ALL Programs & Sites', 'role-user-manager'); ?></button>
            </form>
        </div>

        <?php
        // Process form submissions
        if (isset($_POST['upload_csv']) && check_admin_referer('upload_csv_nonce', 'upload_csv_nonce_field')) {
            cram_process_csv_upload();
        }
        if (isset($_POST['add_program_site']) && check_admin_referer('manage_data_nonce', 'manage_data_nonce_field')) {
            cram_add_program_site_data();
        }
        if (isset($_POST['add_site_to_program']) && check_admin_referer('manage_data_nonce', 'manage_data_nonce_field')) {
            cram_add_site_to_program();
        }
        if (isset($_POST['edit_data']) && check_admin_referer('manage_data_nonce', 'manage_data_nonce_field')) {
            cram_edit_program_site_data();
        }
        if (isset($_POST['remove_program']) && check_admin_referer('manage_data_nonce', 'manage_data_nonce_field')) {
            cram_remove_program_data();
        }
        if (isset($_POST['remove_site']) && check_admin_referer('manage_data_nonce', 'manage_data_nonce_field')) {
            cram_remove_site_data();
        }
        if (isset($_POST['delete_all_program_site']) && check_admin_referer('delete_all_program_site_nonce', 'delete_all_program_site_nonce_field')) {
            cram_delete_all_program_site();
        }
        ?>

        <!-- Management Forms -->
        <div class="management-forms">
            <!-- Add New Program & Site -->
            <div class="form-container">
                <h3>➕ Add New Program & Site</h3>
                <form method="post">
                    <?php wp_nonce_field('manage_data_nonce', 'manage_data_nonce_field'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="new_program">Program Name</label></th>
                            <td><input type="text" name="new_program" id="new_program" placeholder="Enter program name..."
                                    required></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="new_sites">Site Names (comma or new line separated)</label></th>
                            <td>
                                <textarea style="width: 100%; max-width:300px;" name="new_sites" id="new_sites" placeholder="Enter one or more site names, separated by commas or new lines..." rows="4" required></textarea>
                               
                            </td>
                        </tr>
                    </table>
                    <?php submit_button(__('💾 Add Program & Site', 'role-user-manager'), 'primary', 'add_program_site'); ?>
                </form>
            </div>

            <!-- Add Site to Existing Program -->
            <div class="form-container">
                <h3>🏢 Add Site to Existing Program</h3>
                <form method="post">
                    <?php wp_nonce_field('manage_data_nonce', 'manage_data_nonce_field'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="existing_program">Select Program</label></th>
                            <td>
                                <select name="existing_program" id="existing_program" required>
                                    <option value="">Choose a program...</option>
                                    <?php
                                    $program_site_map = get_option('dash_program_site_map', []);
                                    foreach (array_keys($program_site_map) as $program) {
                                        echo '<option value="' . esc_attr($program) . '">' . esc_html($program) . '</option>';
                                    }
                                    ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="additional_site">New Site Name</label></th>
                            <td><input type="text" name="additional_site" id="additional_site"
                                    placeholder="Enter site name..." required></td>
                        </tr>
                    </table>
                    <?php submit_button(__('📍 Add Site to Program', 'role-user-manager'), 'primary', 'add_site_to_program'); ?>
                </form>
            </div>
        </div>

        <?php
        // Display stored data with edit/remove functionality
        $program_site_map = get_option('dash_program_site_map', []);
        if (!empty($program_site_map)) {
            ?>


            <div class="data-management-container">
                <div class="section-header">
                    <h3>Current Program → Site Mappings</h3>
                    <?php
                    $total_programs = count($program_site_map);
                    $total_sites = 0;
                    foreach ($program_site_map as $sites) {
                        $total_sites += count($sites);
                    }
                    ?>
                    <div class="stats-bar">
                        <div class="stats-item">
                            <span class="stats-number"><?php echo $total_programs; ?></span>
                            <span class="stats-label">Programs</span>
                        </div>
                        <div class="stats-item">
                            <span class="stats-number"><?php echo $total_sites; ?></span>
                            <span class="stats-label">Sites</span>
                        </div>
                        <div class="stats-item">
                            <span
                                class="stats-number"><?php echo $total_programs > 0 ? number_format($total_sites / $total_programs, 1) : 0; ?></span>
                            <span class="stats-label">Avg Sites/Program</span>
                        </div>
                    </div>
                </div>

                <!-- Edit form (hidden by default) -->
                <div id="edit-form" class="edit-form-container">
                    <h4>✏️ Edit Program & Site</h4>
                    <form method="post">
                        <?php wp_nonce_field('manage_data_nonce', 'manage_data_nonce_field'); ?>
                        <input type="hidden" name="edit_program_original" id="edit_program_original">
                        <input type="hidden" name="edit_site_original" id="edit_site_original">
                        <table class="form-table">
                            <tr>
                                <th><label for="edit_program">Program Name</label></th>
                                <td><input type="text" name="edit_program" id="edit_program" required></td>
                            </tr>
                            <tr>
                                <th><label for="edit_site">Site Name</label></th>
                                <td><input type="text" name="edit_site" id="edit_site" required></td>
                            </tr>
                        </table>
                        <div class="edit-form-buttons">
                            <input type="submit" name="edit_data" class="button button-primary" value="💾 Update">
                            <button type="button" class="button" onclick="hideEditForm()">❌ Cancel</button>
                        </div>
                    </form>
                </div>

                <table class="modern-data-table widefat">
                    <thead>
                        <tr>
                            <th >Program Name</th>
                            <th >Site Name</th>
                            <th >Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        foreach ($program_site_map as $program => $sites) {
                            $program_row_count = count($sites);
                            $first_site = true;

                            foreach ($sites as $site) {
                                echo '<tr>';

                                // Program name (only show on first site)
                                if ($first_site) {
                                    echo '<td rowspan="' . $program_row_count . '" class="program-cell">';
                                    echo esc_html($program);
                                    echo '<div class="program-actions">';
                                    echo '<a href="#" onclick="editProgram(\'' . esc_js($program) . '\'); return false;" class="program-action-btn">✏️ Edit Name</a>';
                                    echo '<a href="#" onclick="removeProgram(\'' . esc_js($program) . '\'); return false;" class="program-action-btn danger">🗑️ Remove Program</a>';
                                    echo '</div>';
                                    echo '</td>';
                                    $first_site = false;
                                }

                                // Site name
                                echo '<td class="site-cell">' . esc_html($site) . '</td>';

                                // Actions
                                echo '<td>';
                                echo '<div class="action-buttons">';
                                echo '<a href="#" onclick="editData(\'' . esc_js($program) . '\', \'' . esc_js($site) . '\'); return false;" class="action-btn edit-btn">✏️ Edit</a>';
                                echo '<a href="#" onclick="removeSite(\'' . esc_js($program) . '\', \'' . esc_js($site) . '\'); return false;" class="action-btn remove-btn">🗑️ Remove</a>';
                                echo '</div>';
                                echo '</td>';

                                echo '</tr>';
                            }
                        }
                        ?>
                    </tbody>
                </table>
            </div>
            <?php

            // JavaScript for edit/remove functionality
            ?>
            <script>
                function editData(program, site) {
                    document.getElementById('edit_program_original').value = program;
                    document.getElementById('edit_site_original').value = site;
                    document.getElementById('edit_program').value = program;
                    document.getElementById('edit_site').value = site;
                    document.getElementById('edit-form').style.display = 'block';
                    document.getElementById('edit-form').scrollIntoView({
                        behavior: 'smooth'
                    });
                }

                function editProgram(program) {
                    var newName = prompt('Enter new program name:', program);
                    if (newName && newName !== program) {
                        if (confirm('This will rename the program "' + program + '" to "' + newName + '". Continue?')) {
                            var form = document.createElement('form');
                            form.method = 'post';
                            form.innerHTML = '<input type="hidden" name="edit_program_name" value="1">' +
                                '<input type="hidden" name="old_program_name" value="' + program + '">' +
                                '<input type="hidden" name="new_program_name" value="' + newName + '">' +
                                '<?php echo wp_nonce_field('manage_data_nonce', 'manage_data_nonce_field', true, false); ?>';
                            document.body.appendChild(form);
                            form.submit();
                        }
                    }
                }

                function hideEditForm() {
                    document.getElementById('edit-form').style.display = 'none';
                }

                function removeProgram(program) {
                    if (confirm('Are you sure you want to remove the entire program "' + program + '" and all its sites?')) {
                        var form = document.createElement('form');
                        form.method = 'post';
                        form.innerHTML = '<input type="hidden" name="remove_program" value="1">' +
                            '<input type="hidden" name="program_to_remove" value="' + program + '">' +
                            '<?php echo wp_nonce_field('manage_data_nonce', 'manage_data_nonce_field', true, false); ?>';
                        document.body.appendChild(form);
                        form.submit();
                    }
                }

                function removeSite(program, site) {
                    if (confirm('Are you sure you want to remove the site "' + site + '" from program "' + program + '"?')) {
                        var form = document.createElement('form');
                        form.method = 'post';
                        form.innerHTML = '<input type="hidden" name="remove_site" value="1">' +
                            '<input type="hidden" name="program_for_site_removal" value="' + program + '">' +
                            '<input type="hidden" name="site_to_remove" value="' + site + '">' +
                            '<?php echo wp_nonce_field('manage_data_nonce', 'manage_data_nonce_field', true, false); ?>';
                        document.body.appendChild(form);
                        form.submit();
                    }
                }
            </script>
            <?php
        }
        ?>
    </div>
    <?php
}

/**
 * Process CSV upload
 */
function cram_process_csv_upload()
{
    // --- Backup current mapping before import ---
    $current_map = get_option('dash_program_site_map', []);
    update_option('dash_program_site_map_backup', $current_map);

    if (empty($_FILES['program_site_csv']['tmp_name'])) {
        echo '<div class="notice notice-error"><p>' . __('No file was uploaded.', 'role-user-manager') . '</p></div>';
        return;
    }

    if ($_FILES['program_site_csv']['error'] !== UPLOAD_ERR_OK) {
        echo '<div class="notice notice-error"><p>' . __('File upload error: ', 'role-user-manager') . $_FILES['program_site_csv']['error'] . '</p></div>';
        return;
    }

    $file_info = pathinfo($_FILES['program_site_csv']['name']);
    $mime_type = mime_content_type($_FILES['program_site_csv']['tmp_name']);
    if (strtolower($file_info['extension']) !== 'csv' || !in_array($mime_type, ['text/plain', 'text/csv', 'application/vnd.ms-excel'])) {
        echo '<div class="notice notice-error"><p>' . __('Please upload a valid CSV file.', 'role-user-manager') . '</p></div>';
        return;
    }

    if ($_FILES['program_site_csv']['size'] > 5 * 1024 * 1024) {
        echo '<div class="notice notice-error"><p>' . __('File size too large. Maximum allowed size is 5MB.', 'role-user-manager') . '</p></div>';
        return;
    }

    $csv_file = $_FILES['program_site_csv']['tmp_name'];
    $handle = fopen($csv_file, 'r');

    if (!$handle) {
        echo '<div class="notice notice-error"><p>' . __('Unable to read the CSV file.', 'role-user-manager') . '</p></div>';
        return;
    }

    $headers = fgetcsv($handle);
    if (!$headers) {
        echo '<div class="notice notice-error"><p>' . __('CSV file appears to be empty or invalid.', 'role-user-manager') . '</p></div>';
        fclose($handle);
        return;
    }

    // Clean headers (remove BOM and trim whitespace)
    $headers = array_map(function ($header) {
        return trim(str_replace("\xEF\xBB\xBF", '', $header));
    }, $headers);

    // Validate required headers
    $required_headers = ['Program Name', 'Site Name'];
    $missing_headers = array_diff($required_headers, $headers);

    if (!empty($missing_headers)) {
        echo '<div class="notice notice-error"><p>' . __('Missing required columns: ', 'role-user-manager') . implode(', ', $missing_headers) . '</p></div>';
        fclose($handle);
        return;
    }

    // Find column indices
    $program_index = array_search('Program Name', $headers);
    $site_index = array_search('Site Name', $headers);

    $program_site_map = get_option('dash_program_site_map', []);
    $processed_count = 0;
    $skipped_count = 0;
    $error_count = 0;
    $row_number = 1;
    $errors = [];
    $seen = [];

    // Process each row
    while (($data = fgetcsv($handle)) !== false) {
        $row_number++;

        // Skip empty rows
        if (empty(array_filter($data))) {
            continue;
        }

        // Validate row has enough columns
        if (count($data) < max($program_index, $site_index) + 1) {
            $errors[] = [
                'row' => $row_number,
                'program' => $data[$program_index] ?? '',
                'site' => $data[$site_index] ?? '',
                'error' => __('Insufficient columns', 'role-user-manager')
            ];
            $error_count++;
            continue;
        }

        $program_name = isset($data[$program_index]) ? trim($data[$program_index]) : '';
        $site_name = isset($data[$site_index]) ? trim($data[$site_index]) : '';

        // --- Extra validation ---
        if (preg_match('/[^\w\s\-\.,&\'"()\/]/u', $program_name)) {
            $errors[] = [
                'row' => $row_number,
                'program' => $program_name,
                'site' => $site_name,
                'error' => __('Invalid characters in Program Name', 'role-user-manager')
            ];
            $error_count++;
            continue;
        }
        if (preg_match('/[^\w\s\-\.,&\'"()\/]/u', $site_name)) {
            $errors[] = [
                'row' => $row_number,
                'program' => $program_name,
                'site' => $site_name,
                'error' => __('Invalid characters in Site Name', 'role-user-manager')
            ];
            $error_count++;
            continue;
        }
        if (isset($seen[$program_name . '|' . $site_name])) {
            $errors[] = [
                'row' => $row_number,
                'program' => $program_name,
                'site' => $site_name,
                'error' => __('Duplicate row in CSV', 'role-user-manager')
            ];
            $error_count++;
            continue;
        }
        $seen[$program_name . '|' . $site_name] = true;

        // Skip rows with empty required fields
        if (empty($program_name) || empty($site_name)) {
            $errors[] = [
                'row' => $row_number,
                'program' => $program_name,
                'site' => $site_name,
                'error' => __('Empty Program Name or Site Name', 'role-user-manager')
            ];
            $skipped_count++;
            continue;
        }

        // Sanitize data
        $program_name = sanitize_text_field($program_name);
        $site_name = sanitize_text_field($site_name);

        // Check if this combination already exists
        if (isset($program_site_map[$program_name]) && in_array($site_name, $program_site_map[$program_name])) {
            $skipped_count++;
            continue;
        }

        // Add to program site map
        if (!isset($program_site_map[$program_name])) {
            $program_site_map[$program_name] = [];
        }

        $program_site_map[$program_name][] = $site_name;
        $processed_count++;
    }

    fclose($handle);

    // Update the option with new data
    update_option('dash_program_site_map', $program_site_map);

    // --- Audit log ---
    cram_log_audit(__('Imported CSV: ', 'role-user-manager') . $processed_count . __(' added, ', 'role-user-manager') . $skipped_count . __(' skipped, ', 'role-user-manager') . $error_count . __(' errors', 'role-user-manager'));

    // Display results
    $message = __('CSV processing complete!', 'role-user-manager') . '<br>';
    $message .= __('✅ <strong>', 'role-user-manager') . $processed_count . __('</strong> new program-site combinations added<br>', 'role-user-manager');

    if ($skipped_count > 0) {
        $message .= __('⚠️ <strong>', 'role-user-manager') . $skipped_count . __('</strong> rows skipped (duplicates or empty data)<br>', 'role-user-manager');
    }

    if ($error_count > 0) {
        $message .= __('❌ <strong>', 'role-user-manager') . $error_count . __('</strong> rows had errors<br>', 'role-user-manager');
    }

    $notice_class = $error_count > 0 ? 'notice-warning' : 'notice-success';
    echo '<div class="notice ' . $notice_class . '"><p>' . $message . '</p></div>';

    // Show detailed errors if any
    if (!empty($errors)) {
        $max_errors = 50;
        echo '<div class="notice notice-error"><p><strong>' . __('Error Details:', 'role-user-manager') . '</strong></p>';
        echo '<div style="max-height:400px;overflow:auto;"><table class="widefat striped"><thead><tr><th>' . __('Row', 'role-user-manager') . '</th><th>' . __('Program Name', 'role-user-manager') . '</th><th>' . __('Site Name', 'role-user-manager') . '</th><th>' . __('Error', 'role-user-manager') . '</th></tr></thead><tbody>';
        foreach (array_slice($errors, 0, $max_errors) as $err) {
            echo '<tr>';
            echo '<td>' . esc_html($err['row']) . '</td>';
            echo '<td>' . esc_html($err['program']) . '</td>';
            echo '<td>' . esc_html($err['site']) . '</td>';
            echo '<td>' . esc_html($err['error']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        if (count($errors) > $max_errors) {
            echo '<p><em>' . __('... and ', 'role-user-manager') . (count($errors) - $max_errors) . __(' more errors', 'role-user-manager') . '</em></p>';
        }
        echo '</div></div>';
    }
}

/**
 * Add new program and site manually
 */
function cram_add_program_site_data()
{
    if (empty($_POST['new_program']) || empty($_POST['new_sites'])) {
        echo '<div class="notice notice-error"><p>' . __('Both Program Name and at least one Site Name are required.', 'role-user-manager') . '</p></div>';
        return;
    }

    $program_name = sanitize_text_field($_POST['new_program']);
    // Split by comma or new line, trim, and remove empty
    $sites_raw = preg_split('/[\r\n,]+/', $_POST['new_sites']);
    $site_names = array_filter(array_map('sanitize_text_field', array_map('trim', $sites_raw)));

    $program_site_map = get_option('dash_program_site_map', []);
    if (!isset($program_site_map[$program_name])) {
        $program_site_map[$program_name] = [];
    }

    $added = [];
    $skipped = [];
    foreach ($site_names as $site_name) {
        if (in_array($site_name, $program_site_map[$program_name])) {
            $skipped[] = $site_name;
            continue;
        }
        $program_site_map[$program_name][] = $site_name;
        $added[] = $site_name;
    }
    update_option('dash_program_site_map', $program_site_map);

    if ($added) {
        echo '<div class="notice notice-success"><p>' . __('✅ Added site(s): ', 'role-user-manager') . esc_html(implode(', ', $added)) . __(' to program "', 'role-user-manager') . esc_html($program_name) . __('"', 'role-user-manager') . '</p></div>';
    }
    if ($skipped) {
        echo '<div class="notice notice-warning"><p>' . __('⚠️ Skipped existing site(s): ', 'role-user-manager') . esc_html(implode(', ', $skipped)) . '</p></div>';
    }
    cram_log_audit(__('Added program and site: ', 'role-user-manager') . esc_html($program_name) . __(' with sites: ', 'role-user-manager') . esc_html(implode(', ', $added)));
}

/**
 * Add site to existing program
 */
function cram_add_site_to_program()
{
    if (empty($_POST['existing_program']) || empty($_POST['additional_site'])) {
        echo '<div class="notice notice-error"><p>' . __('Both Program selection and Site Name are required.', 'role-user-manager') . '</p></div>';
        return;
    }

    $program_name = sanitize_text_field($_POST['existing_program']);
    $site_name = sanitize_text_field($_POST['additional_site']);

    $program_site_map = get_option('dash_program_site_map', []);

    // Check if program exists
    if (!isset($program_site_map[$program_name])) {
        echo '<div class="notice notice-error"><p>' . __('Selected program does not exist.', 'role-user-manager') . '</p></div>';
        return;
    }

    // Check if site already exists in this program
    if (in_array($site_name, $program_site_map[$program_name])) {
        echo '<div class="notice notice-warning"><p>' . __('This site already exists in the selected program.', 'role-user-manager') . '</p></div>';
        return;
    }

    // Add site to program
    $program_site_map[$program_name][] = $site_name;
    update_option('dash_program_site_map', $program_site_map);

    echo '<div class="notice notice-success"><p>' . __('✅ Successfully added "', 'role-user-manager') . esc_html($site_name) . __('" to program "', 'role-user-manager') . esc_html($program_name) . __('"', 'role-user-manager') . '</p></div>';
    cram_log_audit(__('Added site to program: ', 'role-user-manager') . esc_html($program_name) . __(' with site: ', 'role-user-manager') . esc_html($site_name));
}

/**
 * Edit program and site data
 */
function cram_edit_program_site_data()
{
    if (
        empty($_POST['edit_program_original']) || empty($_POST['edit_site_original']) ||
        empty($_POST['edit_program']) || empty($_POST['edit_site'])
    ) {
        echo '<div class="notice notice-error"><p>' . __('All fields are required for editing.', 'role-user-manager') . '</p></div>';
        return;
    }

    $original_program = sanitize_text_field($_POST['edit_program_original']);
    $original_site = sanitize_text_field($_POST['edit_site_original']);
    $new_program = sanitize_text_field($_POST['edit_program']);
    $new_site = sanitize_text_field($_POST['edit_site']);

    $program_site_map = get_option('dash_program_site_map', []);

    // Check if original combination exists
    if (!isset($program_site_map[$original_program]) || !in_array($original_site, $program_site_map[$original_program])) {
        echo '<div class="notice notice-error"><p>' . __('Original program-site combination not found.', 'role-user-manager') . '</p></div>';
        return;
    }

    // Remove original site from original program
    $program_site_map[$original_program] = array_diff($program_site_map[$original_program], [$original_site]);

    // Remove program if no sites left
    if (empty($program_site_map[$original_program])) {
        unset($program_site_map[$original_program]);
    }

    // Add to new program (create if doesn't exist)
    if (!isset($program_site_map[$new_program])) {
        $program_site_map[$new_program] = [];
    }

    // Check if new combination already exists
    if (!in_array($new_site, $program_site_map[$new_program])) {
        $program_site_map[$new_program][] = $new_site;
    }

    update_option('dash_program_site_map', $program_site_map);

    echo '<div class="notice notice-success"><p>' . __('✅ Successfully updated program-site mapping', 'role-user-manager') . '</p></div>';
    cram_log_audit(__('Edited program and site: ', 'role-user-manager') . esc_html($original_program) . __(' to ', 'role-user-manager') . esc_html($new_program) . __(' and site: ', 'role-user-manager') . esc_html($new_site));
}

/**
 * Remove entire program and all its sites
 */
function cram_remove_program_data()
{
    if (empty($_POST['program_to_remove'])) {
        echo '<div class="notice notice-error"><p>' . __('Program name is required for removal.', 'role-user-manager') . '</p></div>';
        return;
    }

    $program_name = sanitize_text_field($_POST['program_to_remove']);
    $program_site_map = get_option('dash_program_site_map', []);

    if (!isset($program_site_map[$program_name])) {
        echo '<div class="notice notice-error"><p>' . __('Program not found.', 'role-user-manager') . '</p></div>';
        return;
    }

    $site_count = count($program_site_map[$program_name]);
    unset($program_site_map[$program_name]);
    update_option('dash_program_site_map', $program_site_map);

    echo '<div class="notice notice-success"><p>' . __('✅ Successfully removed program "', 'role-user-manager') . esc_html($program_name) . __('" and ', 'role-user-manager') . $site_count . __(' associated sites', 'role-user-manager') . '</p></div>';
    cram_log_audit(__('Removed program: ', 'role-user-manager') . esc_html($program_name) . __(' with ', 'role-user-manager') . $site_count . __(' sites', 'role-user-manager'));
}

/**
 * Remove specific site from program
 */
function cram_remove_site_data()
{
    if (empty($_POST['program_for_site_removal']) || empty($_POST['site_to_remove'])) {
        echo '<div class="notice notice-error"><p>' . __('Both program and site are required for removal.', 'role-user-manager') . '</p></div>';
        return;
    }

    $program_name = sanitize_text_field($_POST['program_for_site_removal']);
    $site_name = sanitize_text_field($_POST['site_to_remove']);

    $program_site_map = get_option('dash_program_site_map', []);

    if (!isset($program_site_map[$program_name]) || !in_array($site_name, $program_site_map[$program_name])) {
        echo '<div class="notice notice-error"><p>' . __('Program-site combination not found.', 'role-user-manager') . '</p></div>';
        return;
    }

    // Remove site from program
    $program_site_map[$program_name] = array_diff($program_site_map[$program_name], [$site_name]);

    // Remove program if no sites left
    if (empty($program_site_map[$program_name])) {
        unset($program_site_map[$program_name]);
        echo '<div class="notice notice-success"><p>' . __('✅ Successfully removed site "', 'role-user-manager') . esc_html($site_name) . __('" and empty program "', 'role-user-manager') . esc_html($program_name) . __('"', 'role-user-manager') . '</p></div>';
    } else {
        echo '<div class="notice notice-success"><p>' . __('✅ Successfully removed site "', 'role-user-manager') . esc_html($site_name) . __('" from program "', 'role-user-manager') . esc_html($program_name) . __('"', 'role-user-manager') . '</p></div>';
    }

    update_option('dash_program_site_map', $program_site_map);
    cram_log_audit(__('Removed site: ', 'role-user-manager') . esc_html($site_name) . __(' from program: ', 'role-user-manager') . esc_html($program_name));
}

/**
 * Handle program name editing
 */
add_action('admin_init', 'cram_handle_program_name_edit');
function cram_handle_program_name_edit()
{
    if (isset($_POST['edit_program_name']) && check_admin_referer('manage_data_nonce', 'manage_data_nonce_field')) {
        if (empty($_POST['old_program_name']) || empty($_POST['new_program_name'])) {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-error"><p>' . __('Both old and new program names are required.', 'role-user-manager') . '</p></div>';
            });
            return;
        }

        $old_program = sanitize_text_field($_POST['old_program_name']);
        $new_program = sanitize_text_field($_POST['new_program_name']);

        $program_site_map = get_option('dash_program_site_map', []);

        if (!isset($program_site_map[$old_program])) {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-error"><p>' . __('Original program not found.', 'role-user-manager') . '</p></div>';
            });
            return;
        }

        if (isset($program_site_map[$new_program])) {
            add_action('admin_notices', function () use ($new_program) {
                echo '<div class="notice notice-error"><p>' . __('Program "', 'role-user-manager') . esc_html($new_program) . __('" already exists.', 'role-user-manager') . '</p></div>';
            });
            return;
        }

        // Rename program
        $program_site_map[$new_program] = $program_site_map[$old_program];
        unset($program_site_map[$old_program]);
        update_option('dash_program_site_map', $program_site_map);

        add_action('admin_notices', function () use ($old_program, $new_program) {
            echo '<div class="notice notice-success"><p>' . __('✅ Successfully renamed program "', 'role-user-manager') . esc_html($old_program) . __('" to "', 'role-user-manager') . esc_html($new_program) . __('"', 'role-user-manager') . '</p></div>';
        });
        cram_log_audit(__('Renamed program: ', 'role-user-manager') . esc_html($old_program) . __(' to ', 'role-user-manager') . esc_html($new_program));
    }
}

function cram_export_program_site_csv() {
    if (!current_user_can('manage_options')) return;
    if (!isset($_POST['export_csv_nonce_field']) || !wp_verify_nonce($_POST['export_csv_nonce_field'], 'export_csv_nonce')) {
        wp_die('Security check failed');
    }
    $program_site_map = get_option('dash_program_site_map', []);
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="program-site-export-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Program Name', 'Site Name']);
    foreach ($program_site_map as $program => $sites) {
        foreach ($sites as $site) {
            fputcsv($out, [$program, $site]);
        }
    }
    fclose($out);
    // --- Audit log ---
    cram_log_audit(__('Exported Program→Site CSV', 'role-user-manager'));
    exit;
}
function cram_restore_program_site_backup() {
    if (!current_user_can('manage_options')) return;
    $backup = get_option('dash_program_site_map_backup', []);
    if (!empty($backup)) {
        update_option('dash_program_site_map', $backup);
        echo '<div class="notice notice-success"><p>' . __('✅ Restored last backup of Program→Site mapping.', 'role-user-manager') . '</p></div>';
        cram_log_audit(__('Restored Program→Site mapping from backup', 'role-user-manager'));
    } else {
        echo '<div class="notice notice-error"><p>' . __('No backup found to restore.', 'role-user-manager') . '</p></div>';
    }
}
function cram_log_audit($message) {
    $log = get_option('arc_audit_log', []);
    $log[] = [
        'time' => current_time('mysql'),
        'user' => is_user_logged_in() ? wp_get_current_user()->user_login : 'system',
        'message' => $message
    ];
    if (count($log) > 100) $log = array_slice($log, -100);
    update_option('arc_audit_log', $log);
}

function cram_delete_all_program_site() {
    if (!current_user_can('manage_options')) {
        echo '<div class="notice notice-error"><p>' . __('Insufficient permissions.', 'role-user-manager') . '</p></div>';
        return;
    }
    delete_option('dash_program_site_map');
    echo '<div class="notice notice-success"><p>' . __('✅ All programs and sites have been deleted.', 'role-user-manager') . '</p></div>';
    // Audit log
    cram_log_audit(__('Deleted ALL program and site mappings.', 'role-user-manager'));
}

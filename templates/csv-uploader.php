<?php
/**
 * CSV Uploader Template
 * 
 * @package RoleUserManager
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap">
    <h1><?php _e('Program/Site Management', 'role-user-manager'); ?></h1>
    
    <div class="rum-csv-uploader">
        <!-- CSV Upload Section -->
        <div class="upload-section">
            <h2><?php _e('Upload CSV File', 'role-user-manager'); ?></h2>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field('csv_upload_action', 'csv_upload_nonce'); ?>
                <input type="hidden" name="action" value="upload_csv">
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="csv_file"><?php _e('CSV File:', 'role-user-manager'); ?></label>
                        </th>
                        <td>
                            <input type="file" name="csv_file" id="csv_file" accept=".csv" required />
                            <p class="description">
                                <?php _e('Upload a CSV file with columns: Program, Site', 'role-user-manager'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" name="submit" id="submit" class="button button-primary" value="<?php _e('Upload CSV', 'role-user-manager'); ?>">
                </p>
            </form>
        </div>
        
        <!-- Manual Add Section -->
        <div class="add-section">
            <h2><?php _e('Add Program/Site Manually', 'role-user-manager'); ?></h2>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <?php wp_nonce_field('add_program_site_action', 'add_program_site_nonce'); ?>
                <input type="hidden" name="action" value="add_program_site">
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="program"><?php _e('Program:', 'role-user-manager'); ?></label>
                        </th>
                        <td>
                            <input type="text" name="program" id="program" class="regular-text" required />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="site"><?php _e('Site:', 'role-user-manager'); ?></label>
                        </th>
                        <td>
                            <input type="text" name="site" id="site" class="regular-text" required />
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" name="submit" id="submit" class="button button-primary" value="<?php _e('Add Program/Site', 'role-user-manager'); ?>">
                </p>
            </form>
        </div>
        
        <!-- Current Data Section -->
        <div class="data-section">
            <h2><?php _e('Current Program/Site Mappings', 'role-user-manager'); ?></h2>
            
            <div class="tablenav top">
                <div class="alignleft actions">
                    <a href="<?php echo admin_url('admin-post.php?action=export_program_sites_csv'); ?>" class="button">
                        <?php _e('Export CSV', 'role-user-manager'); ?>
                    </a>
                </div>
            </div>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th scope="col"><?php _e('Program', 'role-user-manager'); ?></th>
                        <th scope="col"><?php _e('Site', 'role-user-manager'); ?></th>
                        <th scope="col"><?php _e('Created', 'role-user-manager'); ?></th>
                        <th scope="col"><?php _e('Actions', 'role-user-manager'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($program_sites)): ?>
                        <tr>
                            <td colspan="4"><?php _e('No program/site mappings found.', 'role-user-manager'); ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($program_sites as $mapping): ?>
                            <tr>
                                <td><?php echo esc_html($mapping['program']); ?></td>
                                <td><?php echo esc_html($mapping['site']); ?></td>
                                <td><?php echo esc_html($mapping['created_at']); ?></td>
                                <td>
                                    <button class="button edit-mapping" 
                                            data-id="<?php echo esc_attr($mapping['id']); ?>"
                                            data-program="<?php echo esc_attr($mapping['program']); ?>"
                                            data-site="<?php echo esc_attr($mapping['site']); ?>">
                                        <?php _e('Edit', 'role-user-manager'); ?>
                                    </button>
                                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: inline;">
                                        <?php wp_nonce_field('remove_program_site_action', 'remove_program_site_nonce'); ?>
                                        <input type="hidden" name="action" value="remove_program_site">
                                        <input type="hidden" name="id" value="<?php echo esc_attr($mapping['id']); ?>">
                                        <button type="submit" class="button delete-mapping" 
                                                onclick="return confirm('<?php _e('Are you sure you want to delete this mapping?', 'role-user-manager'); ?>')">
                                            <?php _e('Delete', 'role-user-manager'); ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div id="edit-modal" class="modal" style="display: none;">
    <div class="modal-backdrop"></div>
    <div class="modal-dialog">
        <div class="modal-header">
            <h3><?php _e('Edit Program/Site Mapping', 'role-user-manager'); ?></h3>
            <button class="modal-close">&times;</button>
        </div>
        <div class="modal-content">
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <?php wp_nonce_field('edit_program_site_action', 'edit_program_site_nonce'); ?>
                <input type="hidden" name="action" value="edit_program_site">
                <input type="hidden" name="id" id="edit_id">
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="edit_program"><?php _e('Program:', 'role-user-manager'); ?></label>
                        </th>
                        <td>
                            <input type="text" name="program" id="edit_program" class="regular-text" required />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="edit_site"><?php _e('Site:', 'role-user-manager'); ?></label>
                        </th>
                        <td>
                            <input type="text" name="site" id="edit_site" class="regular-text" required />
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" name="submit" id="submit" class="button button-primary" value="<?php _e('Update Mapping', 'role-user-manager'); ?>">
                    <button type="button" class="button cancel-edit"><?php _e('Cancel', 'role-user-manager'); ?></button>
                </p>
            </form>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Edit mapping
    $('.edit-mapping').on('click', function() {
        const id = $(this).data('id');
        const program = $(this).data('program');
        const site = $(this).data('site');
        
        $('#edit_id').val(id);
        $('#edit_program').val(program);
        $('#edit_site').val(site);
        
        $('#edit-modal').show();
    });
    
    // Close modal
    $('.modal-close, .modal-backdrop, .cancel-edit').on('click', function() {
        $('#edit-modal').hide();
    });
    
    // Close modal on escape key
    $(document).on('keydown', function(e) {
        if (e.keyCode === 27) {
            $('#edit-modal').hide();
        }
    });
});
</script>

<style>
.modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    z-index: 100000;
}

.modal-backdrop {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
}

.modal-dialog {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    background: white;
    padding: 20px;
    border-radius: 5px;
    min-width: 400px;
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.modal-close {
    background: none;
    border: none;
    font-size: 20px;
    cursor: pointer;
}

.rum-csv-uploader {
    max-width: 1200px;
}

.upload-section,
.add-section,
.data-section {
    margin-bottom: 30px;
    padding: 20px;
    background: white;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
}
</style> 
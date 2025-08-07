<?php
/**
 * Workflow Admin Template
 * 
 * @package RoleUserManager
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap">
    <h1><?php _e('Workflow Admin - Promotion Requests', 'role-user-manager'); ?></h1>
    
    <!-- Statistics -->
    <div class="workflow-stats">
        <div class="stat-box">
            <h3><?php _e('Total Requests', 'role-user-manager'); ?></h3>
            <span class="stat-number"><?php echo esc_html($stats['total']); ?></span>
        </div>
        <div class="stat-box pending">
            <h3><?php _e('Pending', 'role-user-manager'); ?></h3>
            <span class="stat-number"><?php echo esc_html($stats['pending']); ?></span>
        </div>
        <div class="stat-box approved">
            <h3><?php _e('Approved', 'role-user-manager'); ?></h3>
            <span class="stat-number"><?php echo esc_html($stats['approved']); ?></span>
        </div>
        <div class="stat-box rejected">
            <h3><?php _e('Rejected', 'role-user-manager'); ?></h3>
            <span class="stat-number"><?php echo esc_html($stats['rejected']); ?></span>
        </div>
    </div>
    
    <!-- Requests Table -->
    <div class="workflow-requests">
        <h2><?php _e('Promotion Requests', 'role-user-manager'); ?></h2>
        
        <?php if (empty($requests)): ?>
            <p><?php _e('No promotion requests found.', 'role-user-manager'); ?></p>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th scope="col"><?php _e('ID', 'role-user-manager'); ?></th>
                        <th scope="col"><?php _e('Requester', 'role-user-manager'); ?></th>
                        <th scope="col"><?php _e('User', 'role-user-manager'); ?></th>
                        <th scope="col"><?php _e('Current Role', 'role-user-manager'); ?></th>
                        <th scope="col"><?php _e('Requested Role', 'role-user-manager'); ?></th>
                        <th scope="col"><?php _e('Reason', 'role-user-manager'); ?></th>
                        <th scope="col"><?php _e('Status', 'role-user-manager'); ?></th>
                        <th scope="col"><?php _e('Created', 'role-user-manager'); ?></th>
                        <th scope="col"><?php _e('Actions', 'role-user-manager'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requests as $request): ?>
                        <?php
                        $requester = get_user_by('id', $request['requester_id']);
                        $user = get_user_by('id', $request['user_id']);
                        $status_class = 'status-' . $request['status'];
                        ?>
                        <tr class="<?php echo esc_attr($status_class); ?>">
                            <td><?php echo esc_html($request['id']); ?></td>
                            <td>
                                <?php if ($requester): ?>
                                    <?php echo esc_html($requester->display_name); ?>
                                    <br><small><?php echo esc_html($requester->user_email); ?></small>
                                <?php else: ?>
                                    <?php _e('Unknown', 'role-user-manager'); ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($user): ?>
                                    <?php echo esc_html($user->display_name); ?>
                                    <br><small><?php echo esc_html($user->user_email); ?></small>
                                <?php else: ?>
                                    <?php _e('Unknown', 'role-user-manager'); ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($this->get_role_display_name($request['current_role'])); ?></td>
                            <td><?php echo esc_html($this->get_role_display_name($request['requested_role'])); ?></td>
                            <td>
                                <div class="reason-text">
                                    <?php echo esc_html(wp_trim_words($request['reason'], 10)); ?>
                                    <?php if (strlen($request['reason']) > 50): ?>
                                        <button class="button view-full-reason" data-reason="<?php echo esc_attr($request['reason']); ?>">
                                            <?php _e('View Full', 'role-user-manager'); ?>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <span class="status-badge status-<?php echo esc_attr($request['status']); ?>">
                                    <?php echo esc_html(ucfirst($request['status'])); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html($request['created_at']); ?></td>
                            <td>
                                <?php if ($request['status'] === 'pending'): ?>
                                    <button class="button approve-request" data-request-id="<?php echo esc_attr($request['id']); ?>">
                                        <?php _e('Approve', 'role-user-manager'); ?>
                                    </button>
                                    <button class="button reject-request" data-request-id="<?php echo esc_attr($request['id']); ?>">
                                        <?php _e('Reject', 'role-user-manager'); ?>
                                    </button>
                                <?php else: ?>
                                    <span class="action-completed">
                                        <?php echo esc_html(ucfirst($request['status'])); ?>
                                    </span>
                                    <?php if (!empty($request['admin_notes'])): ?>
                                        <br><small><?php echo esc_html($request['admin_notes']); ?></small>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- Approve Modal -->
<div id="approve-modal" class="modal" style="display: none;">
    <div class="modal-backdrop"></div>
    <div class="modal-dialog">
        <div class="modal-header">
            <h3><?php _e('Approve Promotion Request', 'role-user-manager'); ?></h3>
            <button class="modal-close">&times;</button>
        </div>
        <div class="modal-content">
            <form id="approve-form">
                <input type="hidden" name="request_id" id="approve_request_id">
                
                <p><?php _e('Are you sure you want to approve this promotion request?', 'role-user-manager'); ?></p>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="approve_notes"><?php _e('Notes (optional):', 'role-user-manager'); ?></label>
                        </th>
                        <td>
                            <textarea name="admin_notes" id="approve_notes" rows="3" class="large-text"></textarea>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <button type="submit" class="button button-primary">
                        <?php _e('Approve Request', 'role-user-manager'); ?>
                    </button>
                    <button type="button" class="button cancel-approve">
                        <?php _e('Cancel', 'role-user-manager'); ?>
                    </button>
                </p>
            </form>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div id="reject-modal" class="modal" style="display: none;">
    <div class="modal-backdrop"></div>
    <div class="modal-dialog">
        <div class="modal-header">
            <h3><?php _e('Reject Promotion Request', 'role-user-manager'); ?></h3>
            <button class="modal-close">&times;</button>
        </div>
        <div class="modal-content">
            <form id="reject-form">
                <input type="hidden" name="request_id" id="reject_request_id">
                
                <p><?php _e('Are you sure you want to reject this promotion request?', 'role-user-manager'); ?></p>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="reject_notes"><?php _e('Reason for rejection (optional):', 'role-user-manager'); ?></label>
                        </th>
                        <td>
                            <textarea name="admin_notes" id="reject_notes" rows="3" class="large-text"></textarea>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <button type="submit" class="button button-primary">
                        <?php _e('Reject Request', 'role-user-manager'); ?>
                    </button>
                    <button type="button" class="button cancel-reject">
                        <?php _e('Cancel', 'role-user-manager'); ?>
                    </button>
                </p>
            </form>
        </div>
    </div>
</div>

<!-- Reason Modal -->
<div id="reason-modal" class="modal" style="display: none;">
    <div class="modal-backdrop"></div>
    <div class="modal-dialog">
        <div class="modal-header">
            <h3><?php _e('Full Reason', 'role-user-manager'); ?></h3>
            <button class="modal-close">&times;</button>
        </div>
        <div class="modal-content">
            <p id="full-reason-text"></p>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Approve request
    $('.approve-request').on('click', function() {
        const requestId = $(this).data('request-id');
        $('#approve_request_id').val(requestId);
        $('#approve-modal').show();
    });
    
    // Reject request
    $('.reject-request').on('click', function() {
        const requestId = $(this).data('request-id');
        $('#reject_request_id').val(requestId);
        $('#reject-modal').show();
    });
    
    // View full reason
    $('.view-full-reason').on('click', function() {
        const reason = $(this).data('reason');
        $('#full-reason-text').text(reason);
        $('#reason-modal').show();
    });
    
    // Approve form submission
    $('#approve-form').on('submit', function(e) {
        e.preventDefault();
        
        const requestId = $('#approve_request_id').val();
        const notes = $('#approve_notes').val();
        
        $.ajax({
            url: workflow_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'rum_approve_promotion_request',
                request_id: requestId,
                admin_notes: notes,
                nonce: workflow_vars.nonce
            },
            success: function(response) {
                if (response.success) {
                    alert('Request approved successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + response.message);
                }
            }
        });
    });
    
    // Reject form submission
    $('#reject-form').on('submit', function(e) {
        e.preventDefault();
        
        const requestId = $('#reject_request_id').val();
        const notes = $('#reject_notes').val();
        
        $.ajax({
            url: workflow_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'rum_reject_promotion_request',
                request_id: requestId,
                admin_notes: notes,
                nonce: workflow_vars.nonce
            },
            success: function(response) {
                if (response.success) {
                    alert('Request rejected successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + response.message);
                }
            }
        });
    });
    
    // Close modals
    $('.modal-close, .modal-backdrop, .cancel-approve, .cancel-reject').on('click', function() {
        $('.modal').hide();
    });
    
    // Close modal on escape key
    $(document).on('keydown', function(e) {
        if (e.keyCode === 27) {
            $('.modal').hide();
        }
    });
});
</script>

<style>
.workflow-stats {
    display: flex;
    gap: 20px;
    margin-bottom: 30px;
}

.stat-box {
    background: white;
    padding: 20px;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    text-align: center;
    flex: 1;
}

.stat-box h3 {
    margin: 0 0 10px 0;
    font-size: 14px;
    color: #666;
}

.stat-number {
    font-size: 24px;
    font-weight: bold;
    color: #333;
}

.stat-box.pending .stat-number {
    color: #f0ad4e;
}

.stat-box.approved .stat-number {
    color: #5cb85c;
}

.stat-box.rejected .stat-number {
    color: #d9534f;
}

.status-badge {
    padding: 4px 8px;
    border-radius: 3px;
    font-size: 12px;
    font-weight: bold;
    text-transform: uppercase;
}

.status-pending {
    background: #f0ad4e;
    color: white;
}

.status-approved {
    background: #5cb85c;
    color: white;
}

.status-rejected {
    background: #d9534f;
    color: white;
}

.reason-text {
    max-width: 200px;
}

.view-full-reason {
    margin-top: 5px;
    font-size: 11px;
}

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
    max-width: 600px;
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

.action-completed {
    color: #666;
    font-style: italic;
}
</style> 
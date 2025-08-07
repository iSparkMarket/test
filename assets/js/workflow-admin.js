jQuery(document).ready(function($) {
    console.log('Workflow Admin JavaScript loaded successfully');

    // Handle approve request
    $(document).on('click', '.approve-request', function() {
        const requestId = $(this).data('request-id');
        const adminNotes = prompt('Enter approval notes (optional):');
        
        if (adminNotes === null) return; // User cancelled
        
        approveRequest(requestId, adminNotes);
    });

    // Handle reject request
    $(document).on('click', '.reject-request', function() {
        const requestId = $(this).data('request-id');
        const adminNotes = prompt('Enter rejection reason (optional):');
        
        if (adminNotes === null) return; // User cancelled
        
        rejectRequest(requestId, adminNotes);
    });

    function approveRequest(requestId, adminNotes) {
        $.ajax({
            url: workflow_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'approve_promotion_request',
                request_id: requestId,
                admin_notes: adminNotes,
                nonce: workflow_vars.nonce
            },
            success: function(response) {
                if (response.success) {
                    showNotification(response.data.message, 'success');
                    // Reload the page to show updated status
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    showNotification(response.data.message, 'error');
                }
            },
            error: function() {
                showNotification('Error processing request. Please try again.', 'error');
            }
        });
    }

    function rejectRequest(requestId, adminNotes) {
        $.ajax({
            url: workflow_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'reject_promotion_request',
                request_id: requestId,
                admin_notes: adminNotes,
                nonce: workflow_vars.nonce
            },
            success: function(response) {
                if (response.success) {
                    showNotification(response.data.message, 'success');
                    // Reload the page to show updated status
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    showNotification(response.data.message, 'error');
                }
            },
            error: function() {
                showNotification('Error processing request. Please try again.', 'error');
            }
        });
    }

    function showNotification(message, type = 'info') {
        const notification = $(`
            <div class="notice notice-${type} is-dismissible">
                <p>${message}</p>
            </div>
        `);
        
        $('.wrap h1').after(notification);
        
        // Auto-dismiss after 5 seconds
        setTimeout(function() {
            notification.fadeOut(300, function() {
                $(this).remove();
            });
        }, 5000);
    }
}); 
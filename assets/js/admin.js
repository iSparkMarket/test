jQuery(document).ready(function($) {
    console.log('Dashboard JavaScript loaded successfully');
    
    // Debug: Track all AJAX calls
    $(document).ajaxSend(function(event, xhr, settings) {
        console.log('AJAX call being made:', settings.url, settings.type, settings.data);
    });
    
    $(document).ajaxError(function(event, xhr, settings, thrownError) {
        console.log('AJAX error:', settings.url, xhr.status, thrownError);
    });
    
    // Reset any stuck loading states on page load
    $('button[type="submit"]').each(function() {
        var btn = $(this);
        if (btn.text().includes('Applying...')) {
            btn.html('Apply Filters').prop('disabled', false);
        }
    });
    
    // Test if the form exists
    if ($('#bulk-action-form').length) {
        console.log('Dashboard form found and ready for AJAX filtering');
    } else {
        console.log('Dashboard form not found');
    }
    
    // Dynamic filtering functionality
    var currentPage = 1;
    var isLoading = false;
    var isSubmitting = false; // Prevent duplicate form submissions
    
    function updateUserList(page = 1) {
        if (isLoading) {
            return;
        }
        
        isLoading = true;
        currentPage = page;
        
        // Show loading state
        var submitBtn = $('#bulk-action-form').find('button[type="submit"]');
        if (submitBtn.length) {
            submitBtn.html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Loading...');
            submitBtn.prop('disabled', true);
        }
        
        // Show loading overlay on table
        var tableContainer = $('.users-table');
        tableContainer.append('<div class="loading-overlay" style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: rgba(255,255,255,0.8); display: flex; align-items: center; justify-content: center; z-index: 1000;"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div></div>');
        
        // Collect filter data
        var filterData = {
            action: 'arc_filter_users',
            _wpnonce: arc_dashboard_vars.nonce,
            paged: page,
            filter_program: $('#filter_program').val() || null,
            filter_site: $('#filter_site').val() || null,
            filter_training_status: $('#filter_training_status').val() || null,
            filter_date_start: $('#filter_date_start').val() || null,
            filter_date_end: $('#filter_date_end').val() || null
        };
        
        $.ajax({
            url: arc_dashboard_vars.ajaxurl,
            type: 'POST',
            data: filterData,
            success: function(response) {
                
                if (response.success) {
                    // Update table content
                    var tableContainer = $('#users-table-container');
                    var existingTable = tableContainer.find('table');
                    var existingPagination = $('.users-table').find('.pagination-bar');
                    
                    // Remove existing table and pagination
                    existingTable.remove();
                    existingPagination.remove();
                    
                    // Add new table
                    tableContainer.html(response.data.table_html);
                    
                    // Add new pagination
                    if (response.data.pagination_html) {
                        $('.users-table').append(response.data.pagination_html);
                    }
                    
                    // Update user count display
                    var countDisplay = $('.text-muted');
                    if (countDisplay.length) {
                        countDisplay.text('Showing ' + response.data.showing_users + ' of ' + response.data.total_users + ' users');
                    }
                    
                    // Reinitialize bulk select all
                    initializeBulkSelectAll();
                    
                    // Update URL without page reload
                    updateURL();
                    
                    console.log('User list updated successfully');
                } else {
                    console.error('Filter failed:', response.data);
                    alert('Error updating user list. Please try again.');
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX error:', status, error);
                console.error('Response text:', xhr.responseText);
                console.error('Status code:', xhr.status);
                alert('Error updating user list. Please try again.');
            },
            complete: function() {
                // Reset loading states
                isLoading = false;
                isSubmitting = false; // Reset submission flag
                var submitBtn = $('#bulk-action-form').find('button[type="submit"]');
                if (submitBtn.length) {
                    submitBtn.html('Apply Filters');
                    submitBtn.prop('disabled', false);
                }
                
                // Remove loading overlay
                $('.loading-overlay').remove();
            }
        });
    }
    
    function updateURL() {
        var params = new URLSearchParams(window.location.search);
        
        // Update filter parameters
        var filterProgram = $('#filter_program').val();
        var filterSite = $('#filter_site').val();
        var filterTrainingStatus = $('#filter_training_status').val();
        var filterDateStart = $('#filter_date_start').val();
        var filterDateEnd = $('#filter_date_end').val();
        
        if (filterProgram) params.set('filter_program', filterProgram);
        else params.delete('filter_program');
        
        if (filterSite) params.set('filter_site', filterSite);
        else params.delete('filter_site');
        
        if (filterTrainingStatus) params.set('filter_training_status', filterTrainingStatus);
        else params.delete('filter_training_status');
        
        if (filterDateStart) params.set('filter_date_start', filterDateStart);
        else params.delete('filter_date_start');
        
        if (filterDateEnd) params.set('filter_date_end', filterDateEnd);
        else params.delete('filter_date_end');
        
        // Update page parameter
        if (currentPage > 1) params.set('paged', currentPage);
        else params.delete('paged');
        
        // Update URL without page reload
        var newURL = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
        window.history.pushState({}, '', newURL);
    }
    
    function initializeBulkSelectAll() {
        var bulkSelectAll = $('#bulk-select-all');
        if (bulkSelectAll.length) {
            bulkSelectAll.off('change').on('change', function() {
                var checkboxes = $('.bulk-checkbox');
                checkboxes.prop('checked', this.checked);
            });
        }
    }
    
            // Filter form submission handling - now uses AJAX
        $(document).on('submit', '#bulk-action-form', function(e) {
            e.preventDefault();
            
            // Prevent duplicate submissions
            if (isSubmitting) {
                console.log('Form submission already in progress, ignoring duplicate');
                return false;
            }
            
            isSubmitting = true;
            updateUserList(1); // Reset to first page when applying filters
            return false; // Prevent form submission
        });
    
    // Auto-submit on filter change (optional - uncomment if desired)
    /*
    $('#filter_program, #filter_site, #filter_training_status, #filter_date_start, #filter_date_end').on('change', function() {
        // Only auto-submit if it's not a date field (to allow range selection)
        if (!$(this).attr('id').includes('date')) {
            updateUserList(1);
        }
    });
    
    // Date field change handling - submit when both dates are filled or cleared
    $('#filter_date_start, #filter_date_end').on('change', function() {
        var startDate = $('#filter_date_start').val();
        var endDate = $('#filter_date_end').val();
        
        // Only submit if both dates are filled or both are empty
        if ((startDate && endDate) || (!startDate && !endDate)) {
            updateUserList(1);
        }
    });
    */
    
    // Pagination click handling
    $(document).on('click', '.page-link', function(e) {
        e.preventDefault();
        var page = $(this).data('page');
        if (page) {
            updateUserList(page);
        }
    });
    
    // Clear filters button
    $(document).on('click', 'a[href*="remove_query_arg"]', function(e) {
        e.preventDefault();
        
        // Clear all filter fields
        $('#filter_program, #filter_site, #filter_training_status').val('');
        $('#filter_date_start, #filter_date_end').val('');
        
        // Update user list
        updateUserList(1);
    });
    
    // Initialize bulk select all on page load
    initializeBulkSelectAll();
    
    // User actions
    $(document).on('click', '.btn-view', function() {
        const userId = $(this).data('user-id');
        console.log('View button clicked, userId:', userId);
        console.log('Button element:', this);
        if (typeof openUserEditModal === 'function') {
            console.log('openUserEditModal function found, calling it');
            openUserEditModal(userId);
        } else {
            console.log('openUserEditModal function not found, using fallback');
            viewUser(userId);
        }
    });
    
    $(document).on('click', '.btn-edit', function() {
        const userId = $(this).data('user-id');
        console.log('Edit button clicked, userId:', userId);
        console.log('Button element:', this);
        if (typeof openUserEditModal === 'function') {
            console.log('openUserEditModal function found, calling it');
            openUserEditModal(userId);
        } else {
            console.log('openUserEditModal function not found, using fallback');
            editUser(userId);
        }
    });
    
    $(document).on('click', '.btn-delete', function() {
        const userId = $(this).data('user-id');
        deleteUser(userId);
    });
    
    $(document).on('click', '.btn-remove', function() {
        const userId = $(this).data('user-id');
        console.log('Remove button clicked, userId:', userId);
        alert('Remove user ID: ' + userId);
    });
    
    // Debug: Check if buttons exist
    console.log('Checking for buttons...');
    console.log('View buttons found:', $('.btn-view').length);
    console.log('Edit buttons found:', $('.btn-edit').length);
    console.log('Remove buttons found:', $('.btn-remove').length);
    
    function viewUser(userId) {
        // Open user profile in modal or navigate to profile page
        const userCard = $('.user-card[data-user-id="' + userId + '"]');
        const userName = userCard.find('h3').text();
        
        // Create modal for user details
        const modal = createModal('User Details: ' + userName);
        
        // Load user details via AJAX
        $.ajax({
            url: dashboard_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'rum_get_user_details',
                nonce: dashboard_ajax.nonce,
                user_id: userId
            },
            success: function(response) {
                if (response.success) {
                    modal.find('.modal-content').html(response.data.html);
                } else {
                    modal.find('.modal-content').html('<p>Error loading user details.</p>');
                }
            }
        });
    }
    
    function editUser(userId) {
        // Use the existing modal functionality if available, otherwise redirect
        if (typeof openUserEditModal === 'function') {
            openUserEditModal(userId);
        } else {
            // Fallback to admin page redirect
            window.location.href = dashboard_ajax.admin_url + 'user-edit.php?user_id=' + userId;
        }
    }
    
    function deleteUser(userId) {
        const userCard = $('.user-card[data-user-id="' + userId + '"]');
        const userName = userCard.find('h3').text();
        
        if (!confirm('Are you sure you want to delete user "' + userName + '"? This action cannot be undone.')) {
            return;
        }
        
        $.ajax({
            url: dashboard_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'rum_delete_user',
                nonce: dashboard_ajax.nonce,
                user_id: userId
            },
            success: function(response) {
                if (response.success) {
                    userCard.fadeOut(300, function() {
                        $(this).remove();
                    });
                    showNotification('User deleted successfully.', 'success');
                } else {
                    showNotification('Error: ' + response.data, 'error');
                }
            },
            error: function() {
                showNotification('Error deleting user. Please try again.', 'error');
            }
        });
    }
    
    function createModal(title) {
        const modal = $(`
            <div class="dashboard-modal" style="display: none;">
                <div class="modal-backdrop"></div>
                <div class="modal-dialog">
                    <div class="modal-header">
                        <h3>${title}</h3>
                        <button class="modal-close">&times;</button>
                    </div>
                    <div class="modal-content">
                        <div class="loading-spinner">Loading...</div>
                    </div>
                </div>
            </div>
        `);
        
        $('body').append(modal);
        modal.fadeIn(300);
        
        modal.find('.modal-close, .modal-backdrop').on('click', function() {
            modal.fadeOut(300, function() {
                modal.remove();
            });
        });
        
        return modal;
    }
    
    function showNotification(message, type = 'info') {
        const notification = $(`
            <div class="dashboard-notification ${type}">
                <span>${message}</span>
                <button class="notification-close">&times;</button>
            </div>
        `);
        
        $('body').append(notification);
        notification.slideDown(300);
        
        notification.find('.notification-close').on('click', function() {
            notification.slideUp(300, function() {
                notification.remove();
            });
        });
        
        setTimeout(function() {
            notification.slideUp(300, function() {
                notification.remove();
            });
        }, 5000);
    }
    
    // Keyboard shortcuts
    $(document).on('keydown', function(e) {
        // Escape to close modals
        if (e.keyCode === 27) {
            $('.dashboard-modal').fadeOut(300, function() {
                $(this).remove();
            });
        }
    });
    
    // Add loading states to buttons
    $(document).on('click', '.btn-delete', function() {
        const btn = $(this);
        btn.prop('disabled', true).text('Deleting...');
        
        setTimeout(function() {
            btn.prop('disabled', false).text('Delete');
        }, 3000);
    });

    // Handle promotion buttons
    $(document).on('click', '.btn-promote-direct', function() {
        const userId = $(this).data('user-id');
        const requestedRole = $(this).data('requested-role');
        const promotionName = $(this).data('promotion-name');
        
        console.log('Promote direct clicked:', {userId, requestedRole, promotionName});
        
        if (confirm('Are you sure you want to promote this user to ' + promotionName + '?')) {
            promoteUserDirect(userId, requestedRole);
        }
    });

    $(document).on('click', '.btn-promote-request', function() {
        const userId = $(this).data('user-id');
        const requestedRole = $(this).data('requested-role');
        const promotionName = $(this).data('promotion-name');
        
        console.log('Promote request clicked:', {userId, requestedRole, promotionName});
        
        const reason = prompt('Please provide a reason for this promotion request:');
        if (reason !== null) {
            submitPromotionRequest(userId, requestedRole, reason);
        }
    });

    function promoteUserDirect(userId, requestedRole) {
        console.log('Sending promote direct request:', {userId, requestedRole});
        
        $.ajax({
            url: dashboard_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'rum_promote_user_direct',
                user_id: userId,
                requested_role: requestedRole,
                nonce: dashboard_ajax.nonce
            },
            success: function(response) {
                console.log('Promote direct response:', response);
                if (response.success) {
                    showNotification('User promoted successfully!', 'success');
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    showNotification('Error: ' + response.data.message, 'error');
                }
            },
            error: function(xhr, status, error) {
                console.log('Promote direct error:', {xhr, status, error});
                showNotification('Error promoting user. Please try again.', 'error');
            }
        });
    }

    function submitPromotionRequest(userId, requestedRole, reason) {
        console.log('Sending promotion request:', {userId, requestedRole, reason});
        
        $.ajax({
            url: dashboard_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'rum_submit_promotion_request',
                user_id: userId,
                requested_role: requestedRole,
                reason: reason,
                nonce: dashboard_ajax.nonce
            },
            success: function(response) {
                console.log('Promotion request response:', response);
                if (response.success) {
                    showNotification('Promotion request submitted successfully!', 'success');
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    showNotification('Error: ' + response.data.message, 'error');
                }
            },
            error: function(xhr, status, error) {
                console.log('Promotion request error:', {xhr, status, error});
                showNotification('Error submitting promotion request. Please try again.', 'error');
            }
        });
    }
});
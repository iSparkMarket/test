# Role Assignment Workflows

This plugin now includes a comprehensive Role Assignment Workflows system that manages user promotions with approval processes.

## Features

### Role Hierarchy
- **Frontline Staff** → **Site Supervisor**: Can be promoted by Program Leaders or Administrators
- **Site Supervisor** → **Program Leader**: Requires Administrator approval
- **Program Leader**: Can only be assigned by Administrators

### Promotion Workflow

#### Direct Promotions
- Program Leaders and Administrators can directly promote Frontline Staff to Site Supervisor
- These promotions happen immediately without approval

#### Approval Required Promotions
- Site Supervisor to Program Leader promotions require Administrator approval
- Requests are submitted with a reason and stored in the database
- Administrators can approve or reject requests with notes

### Admin Interface

#### Dashboard Integration
- Promotion buttons appear in the user dashboard for eligible users
- Buttons show different text based on whether approval is required:
  - "Promote to Site Supervisor" (direct)
  - "Request Program Leader" (requires approval)

#### Admin Panel
- New menu item: **Users → Promotion Requests**
- Shows statistics for pending, approved, and rejected requests
- Table view of all promotion requests with action buttons
- Ability to approve/reject requests with optional notes

### Database Structure

The system creates a new table: `wp_role_promotion_requests`

```sql
CREATE TABLE wp_role_promotion_requests (
    id mediumint(9) NOT NULL AUTO_INCREMENT,
    requester_id bigint(20) NOT NULL,
    user_id bigint(20) NOT NULL,
    current_role varchar(50) NOT NULL,
    requested_role varchar(50) NOT NULL,
    reason text,
    status varchar(20) DEFAULT 'pending',
    admin_notes text,
    created_at datetime DEFAULT CURRENT_TIMESTAMP,
    updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY requester_id (requester_id),
    KEY user_id (user_id),
    KEY status (status)
);
```

### AJAX Endpoints

#### For Users (Dashboard)
- `promote_user_direct`: Direct promotion without approval
- `submit_promotion_request`: Submit promotion request for approval

#### For Administrators
- `approve_promotion_request`: Approve a promotion request
- `reject_promotion_request`: Reject a promotion request
- `get_promotion_requests`: Get all promotion requests

### Security Features

- Nonce verification for all AJAX requests
- Role-based access control
- Input sanitization and validation
- Audit logging for all promotion actions
- Prevention of duplicate requests

### Usage Examples

#### For Program Leaders
1. Navigate to the dashboard
2. Find a Frontline Staff user
3. Click "Promote to Site Supervisor" button
4. Confirm the promotion

#### For Site Supervisors
1. Navigate to the dashboard
2. Find a Site Supervisor user
3. Click "Request Program Leader" button
4. Provide a reason for the request
5. Submit the request

#### For Administrators
1. Go to **Users → Promotion Requests**
2. View pending requests
3. Click "Approve" or "Reject" buttons
4. Add optional notes
5. Process the request

### Audit Logging

All promotion actions are logged with:
- Timestamp
- User performing the action
- User being promoted
- Previous and new roles
- Action type (direct promotion, approval, rejection)

### CSS Classes

New CSS classes added for styling:
- `.btn-promote-direct`: Green button for direct promotions
- `.btn-promote-request`: Yellow button for approval requests
- `.workflow-stats`: Statistics container
- `.stat-box`: Individual statistic boxes
- `.status-pending`, `.status-approved`, `.status-rejected`: Status indicators

### JavaScript Functions

#### Dashboard Functions
- `promoteUserDirect()`: Handle direct promotions
- `submitPromotionRequest()`: Submit approval requests

#### Admin Functions
- `approveRequest()`: Approve promotion requests
- `rejectRequest()`: Reject promotion requests
- `showNotification()`: Display status messages

### Installation

The workflow system is automatically initialized when the plugin is activated. The database table is created on first use.

### Compatibility

- Works with existing role hierarchy system
- Compatible with WordPress multisite
- Supports all existing user management features
- No conflicts with other plugins

### Troubleshooting

#### Common Issues

1. **Promotion buttons not showing**
   - Check user permissions
   - Verify role hierarchy is set up correctly
   - Ensure user has eligible roles for promotion

2. **AJAX errors**
   - Check browser console for JavaScript errors
   - Verify nonce is being passed correctly
   - Check server error logs

3. **Database issues**
   - Ensure table was created properly
   - Check WordPress database permissions
   - Verify plugin activation completed

#### Debug Mode

Enable WordPress debug mode to see detailed error messages:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

### Future Enhancements

- Email notifications for request status changes
- Bulk approval/rejection functionality
- Advanced filtering and search
- Export functionality for audit logs
- Integration with external approval systems 
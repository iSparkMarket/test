# Role User Manager

## Multisite Support

**Role User Manager** is compatible with WordPress Multisite networks. By default:

- All roles, capabilities, and program-site mappings are managed **per site** (subsite) in the network.
- User management, role assignment, and data restrictions apply to each site individually.
- The plugin does **not** make changes network-wide unless you specifically use network admin tools or custom code.

### What to Expect in Multisite
- Each site can have its own custom roles, capabilities, and program/site mappings.
- Super Admins can activate the plugin network-wide, but all configuration is still per site.
- A notice will appear in the admin dashboard if the plugin is network-activated, reminding you of this behavior.

### Network-Wide Management
If you require network-wide role/capability or program-site mapping management, custom development is required. Please contact the plugin author or a WordPress developer for advanced multisite features.

---

For more details, see the plugin documentation or contact support. 

## Hooks & Filters Reference

The plugin provides the following custom hooks for extensibility:

### Actions
- `arc_capability_added` — Fires when a capability is added to a role. Args: ($role_key, $capability)
- `arc_capability_removed` — Fires when a capability is removed from a role. Args: ($role_key, $capability)

### Filters
- *(Currently, no custom filters are exposed. Future versions may add filters for role/cap management.)*

## Quickstart / Usage
1. **Install and activate the plugin** from the WordPress admin.
2. **Go to Users > Role Capabilities** to manage roles and capabilities.
3. **Use the CSV uploader** to manage program/site mappings.
4. **Use the dashboard** to view and manage users, roles, and assignments.
5. **Check the audit log** for a record of all changes.

## Changelog
- See plugin header or GitHub for version history.

## Support
For help, feature requests, or bug reports, contact the plugin author or open an issue on the project repository. 

## Option Names Reference

The plugin uses the following WordPress options for persistent data:

- `arc_role_hierarchy`: Stores the parent/child relationships between custom roles.
- `arc_audit_log`: Stores the audit log of all critical actions (role/cap changes, user meta changes, CSV imports/exports).
- `dash_program_site_map`: Stores the mapping of programs to sites for user assignment.
- `dash_program_site_map_backup`: Stores the backup of the program-site mapping before CSV import.
- `arc_plugin_caps_{md5(plugin)}`: Stores the capabilities added by a specific plugin for safe removal on deactivation.

These options are managed automatically by the plugin and do not require manual editing. 
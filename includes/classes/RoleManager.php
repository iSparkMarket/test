<?php
declare(strict_types=1);

namespace RoleUserManager;

/**
 * Role Manager class
 */
class RoleManager {
    
    private array $capability_groups = [];
    private array $auto_roles = ['program-leader', 'site-supervisor', 'frontline-staff'];
    private const MAX_ROLE_HIERARCHY_LEVEL = 3;
    
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
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        
        // Hook into post type and taxonomy registration
        add_action('registered_post_type', [$this, 'refresh_capabilities_cache'], 10, 2);
        add_action('registered_taxonomy', [$this, 'refresh_capabilities_cache'], 10, 2);
        
        // Automatic capability assignment/removal on plugin activation/deactivation
        add_action('activated_plugin', [$this, 'auto_assign_plugin_caps'], 20, 2);
        add_action('deactivated_plugin', [$this, 'auto_remove_plugin_caps'], 20, 2);
        
        // Clear cache when plugins are activated/deactivated
        add_action('activated_plugin', [$this, 'clear_capabilities_cache']);
        add_action('deactivated_plugin', [$this, 'clear_capabilities_cache']);
        
        // Modify user edit interface to enforce single role selection
        add_action('admin_head', [$this, 'modify_user_edit_interface']);
        add_action('admin_footer', [$this, 'enforce_single_role_script']);
        add_action('profile_update', [$this, 'ensure_single_role'], 10, 2);
        add_action('user_register', [$this, 'ensure_single_role'], 10, 1);
        add_action('edit_user_profile_update', [$this, 'handle_role_assignment']);
        add_action('personal_options_update', [$this, 'handle_role_assignment']);
    }
    
    /**
     * Get role hierarchy data
     */
    private function get_role_hierarchy(): array {
        return get_option('rum_role_hierarchy', []);
    }
    
    /**
     * Save role hierarchy data
     */
    private function save_role_hierarchy(array $hierarchy): void {
        update_option('rum_role_hierarchy', $hierarchy);
    }
    
    /**
     * Get parent role key for a given role
     */
    public function get_parent_role(string $role_key): ?string {
        $hierarchy = $this->get_role_hierarchy();
        return isset($hierarchy[$role_key]) ? $hierarchy[$role_key] : null;
    }
    
    /**
     * Get the current level (depth) of a role in the hierarchy
     */
    public function get_role_level(string $role_key): int {
        $level = 0;
        $current = $role_key;
        $visited = [];
        
        while ($current !== null && !in_array($current, $visited, true)) {
            $visited[] = $current;
            $current = $this->get_parent_role($current);
            $level++;
        }
        
        return $level;
    }
    
    /**
     * Set parent role for a given role
     */
    public function set_parent_role(string $role_key, string $parent_role_key): void {
        if ($this->would_create_circular_dependency($role_key, $parent_role_key)) {
            throw new \Exception('Circular dependency detected');
        }
        
        $hierarchy = $this->get_role_hierarchy();
        $hierarchy[$role_key] = $parent_role_key;
        $this->save_role_hierarchy($hierarchy);
    }
    
    /**
     * Get roles with hierarchy information
     */
    public function get_roles_with_hierarchy(): array {
        $roles = wp_roles()->get_names();
        $hierarchy = $this->get_role_hierarchy();
        $result = [];
        
        foreach ($roles as $role_key => $role_name) {
            $result[$role_key] = [
                'name' => $role_name,
                'parent' => $hierarchy[$role_key] ?? null,
                'level' => $this->get_role_level($role_key),
            ];
        }
        
        return $result;
    }
    
    /**
     * Inherit parent capabilities for a role
     */
    public function inherit_parent_capabilities(string $role_key): bool {
        $parent_role = $this->get_parent_role($role_key);
        if (!$parent_role) {
            return false;
        }
        
        $parent_caps = get_role($parent_role)->capabilities ?? [];
        $current_caps = get_role($role_key)->capabilities ?? [];
        
        $new_caps = array_merge($current_caps, $parent_caps);
        $role = get_role($role_key);
        
        if ($role) {
            $role->capabilities = $new_caps;
            return true;
        }
        
        return false;
    }
    
    /**
     * Initialize the role manager
     */
    public function init(): void {
        $this->discover_all_capabilities();
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu(): void {
        add_menu_page(
            __('Role Capabilities', 'role-user-manager'),
            __('Role Capabilities', 'role-user-manager'),
            'manage_options',
            'role-capabilities',
            [$this, 'admin_page'],
            'dashicons-groups',
            30
        );
    }
    
    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts(string $hook): void {
        if ($hook !== 'toplevel_page_role-capabilities') {
            return;
        }
        
        Assets::enqueue_admin_assets($hook);
    }
    
    /**
     * Discover all capabilities
     */
    public function discover_all_capabilities(): void {
        $cached_capabilities = get_transient('rum_all_capabilities');
        if ($cached_capabilities !== false) {
            $this->capability_groups = $cached_capabilities;
            return;
        }
        
        $capabilities = [];
        
        // Get capabilities from all roles
        $roles = wp_roles()->roles;
        foreach ($roles as $role) {
            if (isset($role['capabilities']) && is_array($role['capabilities'])) {
                $capabilities = array_merge($capabilities, array_keys($role['capabilities']));
            }
        }
        
        // Get capabilities from post types
        $post_types = get_post_types(['public' => true], 'objects');
        foreach ($post_types as $post_type) {
            if (isset($post_type->cap)) {
                $capabilities = array_merge($capabilities, array_keys((array) $post_type->cap));
            }
        }
        
        // Get capabilities from taxonomies
        $taxonomies = get_taxonomies(['public' => true], 'objects');
        foreach ($taxonomies as $taxonomy) {
            if (isset($taxonomy->cap)) {
                $capabilities = array_merge($capabilities, array_keys((array) $taxonomy->cap));
            }
        }
        
        // Remove duplicates and sort
        $capabilities = array_unique($capabilities);
        sort($capabilities);
        
        // Group capabilities
        $this->capability_groups = $this->group_capabilities($capabilities);
        
        // Cache for 1 hour
        set_transient('rum_all_capabilities', $this->capability_groups, HOUR_IN_SECONDS);
    }
    
    /**
     * Group capabilities by category
     */
    private function group_capabilities(array $capabilities): array {
        $groups = [
            'general' => [],
            'posts' => [],
            'pages' => [],
            'users' => [],
            'comments' => [],
            'plugins' => [],
            'themes' => [],
            'custom' => [],
        ];
        
        foreach ($capabilities as $capability) {
            if (strpos($capability, 'edit_posts') !== false || strpos($capability, 'publish_posts') !== false) {
                $groups['posts'][] = $capability;
            } elseif (strpos($capability, 'edit_pages') !== false || strpos($capability, 'publish_pages') !== false) {
                $groups['pages'][] = $capability;
            } elseif (strpos($capability, 'edit_users') !== false || strpos($capability, 'delete_users') !== false) {
                $groups['users'][] = $capability;
            } elseif (strpos($capability, 'moderate_comments') !== false || strpos($capability, 'edit_comment') !== false) {
                $groups['comments'][] = $capability;
            } elseif (strpos($capability, 'activate_plugins') !== false || strpos($capability, 'install_plugins') !== false) {
                $groups['plugins'][] = $capability;
            } elseif (strpos($capability, 'switch_themes') !== false || strpos($capability, 'edit_themes') !== false) {
                $groups['themes'][] = $capability;
            } elseif (strpos($capability, 'manage_options') !== false || strpos($capability, 'read') !== false) {
                $groups['general'][] = $capability;
            } else {
                $groups['custom'][] = $capability;
            }
        }
        
        return $groups;
    }
    
    /**
     * Refresh capabilities cache
     */
    public function refresh_capabilities_cache($object_name, $object): void {
        $this->clear_capabilities_cache();
        $this->discover_all_capabilities();
    }
    
    /**
     * Clear capabilities cache
     */
    public function clear_capabilities_cache(): void {
        delete_transient('rum_all_capabilities');
    }
    
    /**
     * Admin page
     */
    public function admin_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }
        
        $this->discover_all_capabilities();
        
        include RUM_PLUGIN_DIR . 'templates/role-manager.php';
    }
    
    /**
     * Update role capabilities
     */
    public function update_role_capabilities(string $role, array $capabilities): bool {
        $role_obj = get_role($role);
        if (!$role_obj) {
            return false;
        }
        
        $old_capabilities = $role_obj->capabilities;
        $role_obj->capabilities = $capabilities;
        
        Logger::log_capability_change($role, $old_capabilities, $capabilities);
        return true;
    }
    
    /**
     * Create new role
     */
    public function create_role(string $role_name, string $display_name, string $parent_role = ''): bool {
        if (get_role($role_name)) {
            return false;
        }
        
        $result = add_role($role_name, $display_name);
        if ($result === null) {
            return false;
        }
        
        if (!empty($parent_role)) {
            $this->set_parent_role($role_name, $parent_role);
        }
        
        return true;
    }
    
    /**
     * Delete role
     */
    public function delete_role(string $role): bool {
        $role_obj = get_role($role);
        if (!$role_obj) {
            return false;
        }
        
        // Remove from hierarchy
        $hierarchy = $this->get_role_hierarchy();
        unset($hierarchy[$role]);
        $this->save_role_hierarchy($hierarchy);
        
        return remove_role($role);
    }
    
    /**
     * Get role capabilities
     */
    public function get_role_capabilities(string $role): array {
        $role_obj = get_role($role);
        return $role_obj ? $role_obj->capabilities : [];
    }
    
    /**
     * Get available parent roles
     */
    public function get_available_parent_roles(): array {
        $roles = wp_roles()->get_names();
        $current_role = $_POST['role'] ?? '';
        
        // Filter out the current role and its descendants
        $filtered_roles = [];
        foreach ($roles as $role_key => $role_name) {
            if ($role_key !== $current_role && !$this->is_descendant($role_key, $current_role)) {
                $filtered_roles[$role_key] = $role_name;
            }
        }
        
        return $filtered_roles;
    }
    
    /**
     * Check if a role is a descendant of another role
     */
    private function is_descendant(string $child_role, string $parent_role): bool {
        $current = $child_role;
        $visited = [];
        
        while ($current && !in_array($current, $visited, true)) {
            $visited[] = $current;
            if ($current === $parent_role) {
                return true;
            }
            $current = $this->get_parent_role($current);
        }
        
        return false;
    }
    
    /**
     * Check if setting a parent would create a circular dependency
     */
    private function would_create_circular_dependency(string $child_role, string $parent_role): bool {
        if ($child_role === $parent_role) {
            return true;
        }
        
        return $this->is_descendant($parent_role, $child_role);
    }
    
    /**
     * Auto assign plugin capabilities
     */
    public function auto_assign_plugin_caps(string $plugin, bool $network_wide): void {
        // Implementation for auto-assigning plugin capabilities
        Logger::log("Plugin activated: {$plugin}");
    }
    
    /**
     * Auto remove plugin capabilities
     */
    public function auto_remove_plugin_caps(string $plugin, bool $network_wide): void {
        // Implementation for auto-removing plugin capabilities
        Logger::log("Plugin deactivated: {$plugin}");
    }
    
    /**
     * Get all capabilities
     */
    public function get_all_capabilities(): array {
        return $this->capability_groups;
    }
    
    /**
     * Modify user edit interface
     */
    public function modify_user_edit_interface(): void {
        // Implementation for modifying user edit interface
    }
    
    /**
     * Enforce single role script
     */
    public function enforce_single_role_script(): void {
        // Implementation for enforcing single role selection
    }
    
    /**
     * Ensure single role
     */
    public function ensure_single_role(int $user_id, $old_user_data = null): void {
        $user = get_user_by('id', $user_id);
        if (!$user) {
            return;
        }
        
        $roles = $user->roles;
        if (count($roles) > 1) {
            // Keep only the first role
            $primary_role = $roles[0];
            $user->set_role($primary_role);
            
            Logger::log("User {$user_id} had multiple roles, kept only: {$primary_role}");
        }
    }
    
    /**
     * Handle role assignment
     */
    public function handle_role_assignment(int $user_id): void {
        $this->ensure_single_role($user_id);
    }
} 
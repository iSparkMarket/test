<?php
declare(strict_types=1);

/**
 * Advanced Role Capabilities Manager
 * 
 * PHP Compatibility: This file provides dual compatibility for PHP 7.4+ and PHP 8.x
 * - PHP 7.4+: Uses basic type hints and no union types
 * - PHP 8+: Uses advanced type hints including union types and nullable return types
 * Conditional code automatically detects PHP version and uses appropriate syntax.
 */

// Define plugin constants
define('ARC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ARC_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('ARC_VERSION', '1.0.0');

class AdvancedRoleCapabilitiesManager
{
	private array $capability_groups = [];
	private array $auto_roles = ['program-leader', 'site-supervisor', 'frontline-staff'];
	private string $audit_log_option = 'arc_audit_log';
	private const MAX_ROLE_HIERARCHY_LEVEL = 3; // Maximum allowed depth for role hierarchy

	
	public function __construct()
	{
		add_action('init', [$this, 'init']);
		add_action('admin_menu', [$this, 'add_admin_menu']);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
		add_action('wp_ajax_arc_update_role_capabilities', [$this, 'ajax_update_role_capabilities']);
		add_action('wp_ajax_arc_create_role', [$this, 'ajax_create_role']);
		add_action('wp_ajax_arc_delete_role', [$this, 'ajax_delete_role']);
		add_action('wp_ajax_arc_get_role_capabilities', [$this, 'ajax_get_role_capabilities']);
		add_action('wp_ajax_arc_inherit_parent_capabilities', [$this, 'ajax_inherit_parent_capabilities']); // FIXED: Added missing action
		add_action('wp_ajax_arc_get_parent_options', [$this, 'ajax_get_parent_options']); // Added missing action

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
	private function get_role_hierarchy(): array
	{
		return get_option('arc_role_hierarchy', []);
	}

	/**
	 * Save role hierarchy data
	 */
	private function save_role_hierarchy(array $hierarchy): void
	{
		update_option('arc_role_hierarchy', $hierarchy);
	}

	/**
	 * Get parent role key for a given role
	 */
	public function get_parent_role(string $role_key)
	{
		$hierarchy = $this->get_role_hierarchy();
		return isset($hierarchy[$role_key]) ? $hierarchy[$role_key] : null;
	}

	/**
	 * Get parent role key for a given role (PHP 8+ version with nullable return type)
	 */
	private function get_parent_role_php8(string $role_key): ?string
	{
		$hierarchy = $this->get_role_hierarchy();
		return isset($hierarchy[$role_key]) ? $hierarchy[$role_key] : null;
	}

	/**
	 * Get the current level (depth) of a role in the hierarchy
	 */
	public function get_role_level(string $role_key): int
	{
		$level = 0;
		$current = $role_key;
		$visited = [];
		while ($parent = $this->get_parent_role($current)) {
			if (in_array($parent, $visited, true)) break; // Prevent infinite loop
			$visited[] = $parent;
			$level++;
			$current = $parent;
		}
		return $level;
	}

	/**
	 * Set parent role for a given role, enforcing max hierarchy level
	 */
	public function set_parent_role(string $role_key, string $parent_role_key): void
	{
		if (!empty($parent_role_key)) {
			// Check if setting this parent would exceed max level
			$parent_level = $this->get_role_level($parent_role_key);
			if ($parent_level + 1 >= self::MAX_ROLE_HIERARCHY_LEVEL) {
				// Do not allow setting parent if it would exceed max level
				// Optionally log or throw error
				return;
			}
		}
		$hierarchy = $this->get_role_hierarchy();
		if (empty($parent_role_key)) {
			unset($hierarchy[$role_key]);
		} else {
			$hierarchy[$role_key] = $parent_role_key;
		}
		$this->save_role_hierarchy($hierarchy);
	}



	/**
	 * Get all roles with their parent information
	 */
	public function get_roles_with_hierarchy(): array
	{
		global $wp_roles;
		if (!isset($wp_roles)) {
			$wp_roles = new WP_Roles();
		}

		$roles = [];
		$hierarchy = $this->get_role_hierarchy();

		foreach ($wp_roles->roles as $role_key => $role_data) {
			$roles[$role_key] = [
				'name' => $role_data['name'],
				'capabilities' => $role_data['capabilities']
			];

			if (isset($hierarchy[$role_key])) {
				$roles[$role_key]['parent_key'] = $hierarchy[$role_key];
			}
		}

		return $roles;
	}

	/**
	 * Inherit capabilities from parent role
	 */
	public function inherit_parent_capabilities(string $role_key): bool
	{
		$parent_key = $this->get_parent_role($role_key);
		if (!$parent_key) {
			return false;
		}

		$parent_role = get_role($parent_key);
		$child_role = get_role($role_key);

		if ($parent_role && $child_role) {
			foreach ($parent_role->capabilities as $cap => $grant) {
				if ($grant) {
					$child_role->add_cap($cap);
				}
			}
			return true;
		}
		return false;
	}

	/**
	 * Initializes the plugin: Loads translations and discovers capabilities if in admin.
	 */
	public function init(): void
	{
		// Load text domain for internationalization
		load_plugin_textdomain('role-user-manager', false, dirname(plugin_basename(__FILE__)) . '/languages');

		// Initialize capabilities on admin pages only
		if (is_admin()) {
			$this->discover_all_capabilities();
		}
	}

	/**
	 * Adds the "Role Capabilities" page under the Users menu in the admin dashboard.
	 */
	public function add_admin_menu(): void
	{
		add_users_page(
			__('Role Capabilities', 'role-user-manager'),
			__('Role Capabilities', 'role-user-manager'),
			'manage_options',
			'role-capabilities',
			[$this, 'admin_page']
		);
	}

	/**
	 * Enqueues admin scripts and styles for the plugin's settings page.
	 */
	public function enqueue_admin_scripts(string $hook): void
	{
		if ($hook !== 'users_page_role-capabilities') {
			return;
		}

		wp_enqueue_script('jquery');
		wp_enqueue_script(
			'arc-admin-js',
			plugin_dir_url(dirname(__FILE__)) . 'assets/js/admin.js',
			['jquery'],
			ARC_VERSION,
			true
		);

		wp_localize_script('arc-admin-js', 'arcAjax', [
			'ajaxurl' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('arc_nonce'),
			'strings' => [
				'confirm_delete' => __('Are you sure you want to delete this role? This action cannot be undone.', 'role-user-manager'),
				'role_updated' => __('Role capabilities updated successfully!', 'role-user-manager'),
				'role_created' => __('Role created successfully!', 'role-user-manager'),
				'role_deleted' => __('Role deleted successfully!', 'role-user-manager'),
				'error' => __('An error occurred. Please try again.', 'role-user-manager'),
				'parent_caps_inherited' => __('Parent capabilities inherited successfully!', 'role-user-manager'),
				'circular_dependency' => __('A role cannot be its own parent or create a circular hierarchy.', 'role-user-manager'),
			]
		]);

		wp_enqueue_style(
			'arc-admin-css',
			plugin_dir_url(dirname(__FILE__)) . 'assets/css/admin.css',
			[],
			ARC_VERSION
		);
	}

	/**
	 * Discovers all capabilities from roles, core, custom post types, and taxonomies.
	 * Groups and caches them for performance.
	 */
	public function discover_all_capabilities(): void
	{
		// Check cache first
		$cached_caps = get_transient('arc_all_capabilities');
		if ($cached_caps !== false) {
			$this->capability_groups = $cached_caps;
			return;
		}

		global $wp_roles;

		if (!isset($wp_roles)) {
			$wp_roles = new WP_Roles();
		}

		$all_capabilities = [];

		// Get capabilities from all existing roles
		foreach ($wp_roles->roles as $role_name => $role_data) {
			if (isset($role_data['capabilities'])) {
				$all_capabilities = array_merge($all_capabilities, array_keys($role_data['capabilities']));
			}
		}

		// Add core WordPress capabilities that might not be assigned to any role
		$core_capabilities = [
			// Posts
			'edit_posts',
			'edit_others_posts',
			'edit_published_posts',
			'edit_private_posts',
			'publish_posts',
			'read_private_posts',
			'delete_posts',
			'delete_others_posts',
			'delete_published_posts',
			'delete_private_posts',

			// Pages
			'edit_pages',
			'edit_others_pages',
			'edit_published_pages',
			'edit_private_pages',
			'publish_pages',
			'read_private_pages',
			'delete_pages',
			'delete_others_pages',
			'delete_published_pages',
			'delete_private_pages',

			// Media
			'upload_files',
			'edit_files',

			// Comments
			'moderate_comments',
			'edit_comment',
			'edit_others_comments',
			'delete_comment',
			'delete_others_comments',

			// Themes
			'switch_themes',
			'edit_themes',
			'edit_theme_options',
			'delete_themes',
			'install_themes',
			'update_themes',

			// Plugins
			'activate_plugins',
			'edit_plugins',
			'install_plugins',
			'update_plugins',
			'delete_plugins',

			// Users
			'list_users',
			'create_users',
			'edit_users',
			'edit_others_users',
			'promote_users',
			'delete_users',

			// Admin
			'manage_options',
			'manage_categories',
			'manage_links',
			'read',
			'unfiltered_html',
			'edit_dashboard',
			'update_core',
			'export',
			'import',
			'manage_sites',
			'manage_network',

			// Multisite
			'create_sites',
			'delete_sites',
			'manage_network_users',
			'manage_network_themes',
			'manage_network_plugins',
			'manage_network_options'
		];

		$all_capabilities = array_merge($all_capabilities, $core_capabilities);

		// Get capabilities from custom post types
		$post_types = get_post_types(['_builtin' => false], 'objects');
		foreach ($post_types as $post_type) {
			if (isset($post_type->cap)) {
				$caps = (array) $post_type->cap;
				$all_capabilities = array_merge($all_capabilities, array_values($caps));
			}
		}

		// Get capabilities from taxonomies
		$taxonomies = get_taxonomies(['_builtin' => false], 'objects');
		foreach ($taxonomies as $taxonomy) {
			if (isset($taxonomy->cap)) {
				$caps = (array) $taxonomy->cap;
				$all_capabilities = array_merge($all_capabilities, array_values($caps));
			}
		}

		// Remove duplicates and sort
		$all_capabilities = array_unique($all_capabilities);
		sort($all_capabilities);

		// Group capabilities by type
		$this->capability_groups = $this->group_capabilities($all_capabilities);

		// Cache for 1 hour
		set_transient('arc_all_capabilities', $this->capability_groups, HOUR_IN_SECONDS);
	}

	/**
	 * Groups capabilities into logical categories for display.
	 */
	private function group_capabilities(array $capabilities): array
	{
		$groups = [
			'Core Posts' => [],
			'Core Pages' => [],
			'Core Media' => [],
			'Core Comments' => [],
			'Core Users' => [],
			'Core Admin' => [],
			'Core Themes' => [],
			'Core Plugins' => [],
			'Custom Post Types' => [],
			'Taxonomies' => [],
			'WooCommerce' => [],
			'Advanced Custom Fields' => [],
			'Other Plugins' => [],
			'Miscellaneous' => []
		];

		foreach ($capabilities as $cap) {
			$placed = false;

			// Core capabilities
			if (preg_match('/^(edit|publish|delete|read)_(posts?|others_posts?|published_posts?|private_posts?)$/', $cap)) {
				$groups['Core Posts'][] = $cap;
				$placed = true;
			} elseif (preg_match('/^(edit|publish|delete|read)_(pages?|others_pages?|published_pages?|private_pages?)$/', $cap)) {
				$groups['Core Pages'][] = $cap;
				$placed = true;
			} elseif (in_array($cap, ['upload_files', 'edit_files'])) {
				$groups['Core Media'][] = $cap;
				$placed = true;
			} elseif (preg_match('/comment/', $cap)) {
				$groups['Core Comments'][] = $cap;
				$placed = true;
			} elseif (preg_match('/user/', $cap) || in_array($cap, ['list_users', 'create_users', 'edit_users', 'promote_users', 'delete_users'])) {
				$groups['Core Users'][] = $cap;
				$placed = true;
			} elseif (preg_match('/theme/', $cap)) {
				$groups['Core Themes'][] = $cap;
				$placed = true;
			} elseif (preg_match('/plugin/', $cap)) {
				$groups['Core Plugins'][] = $cap;
				$placed = true;
			} elseif (in_array($cap, ['manage_options', 'manage_categories', 'manage_links', 'read', 'unfiltered_html', 'edit_dashboard', 'update_core', 'export', 'import', 'manage_sites', 'manage_network'])) {
				$groups['Core Admin'][] = $cap;
				$placed = true;
			}

			// Plugin-specific capabilities
			if (!$placed) {
				if (strpos($cap, 'woocommerce') !== false || strpos($cap, 'shop_') !== false || strpos($cap, 'product') !== false || strpos($cap, 'order') !== false) {
					$groups['WooCommerce'][] = $cap;
					$placed = true;
				} elseif (strpos($cap, 'acf') !== false || strpos($cap, 'field') !== false) {
					$groups['Advanced Custom Fields'][] = $cap;
					$placed = true;
				}
			}

			// Custom post types (look for patterns)
			if (!$placed) {
				$post_types = get_post_types(['_builtin' => false], 'names');
				foreach ($post_types as $post_type) {
					if (strpos($cap, $post_type) !== false) {
						$groups['Custom Post Types'][] = $cap;
						$placed = true;
						break;
					}
				}
			}

			// Taxonomy capabilities
			if (!$placed) {
				if (preg_match('/(manage|edit|delete|assign)_.*?_(terms?|categories|tags)/', $cap)) {
					$groups['Taxonomies'][] = $cap;
					$placed = true;
				}
			}

			// If still not placed, put in miscellaneous
			if (!$placed) {
				$groups['Miscellaneous'][] = $cap;
			}
		}

		return $groups;
	}

	public function refresh_capabilities_cache($object_name, $object)
	{
		$this->clear_capabilities_cache();
	}

	public function clear_capabilities_cache(): void
	{
		delete_transient('arc_all_capabilities');
	}

	public function admin_page(): void
	{
		if (!current_user_can('manage_options')) {
			wp_die(__('You do not have sufficient permissions to access this page.', 'role-user-manager'));
		}

		global $wp_roles;

		if (!isset($wp_roles)) {
			$wp_roles = new WP_Roles();
		}

		$roles = $wp_roles->get_names();
		$roles_with_hierarchy = $this->get_roles_with_hierarchy();
		$total_caps = 0;
		foreach ($this->capability_groups as $group_caps) {
			$total_caps += count($group_caps);
		}

?>
		<div class="wrap">
			<h1><?php _e('Advanced Role Capabilities Manager', 'role-user-manager'); ?></h1>

			<div class="arc-container">
				<div class="arc-header">
					<div class="arc-stats">
						<div class="arc-stat-box">
							<div class="arc-stat-number"><?php echo count($roles); ?></div>
							<div><?php _e('Total Roles', 'role-user-manager'); ?></div>
						</div>
						<div class="arc-stat-box">
							<div class="arc-stat-number"><?php echo $total_caps; ?></div>
							<div><?php _e('Total Capabilities', 'role-user-manager'); ?></div>
						</div>
						<div class="arc-stat-box">
							<div class="arc-stat-number"><?php echo count($this->capability_groups); ?></div>
							<div><?php _e('Capability Groups', 'role-user-manager'); ?></div>
						</div>
					</div>
				</div>

				<div id="arc-notices"></div>

				<div class="arc-main">
					<div class="arc-sidebar">
						<h3><?php _e('Select Role', 'role-user-manager'); ?></h3>
						<div class="arc-new-role">
							<h4><?php _e('Create New Role', 'role-user-manager'); ?></h4>
							<input type="text" id="arc-new-role-name"
								placeholder="<?php _e('Role Name', 'role-user-manager'); ?>">
							<input type="text" id="arc-new-role-key"
								placeholder="<?php _e('Role Key (lowercase)', 'role-user-manager'); ?>">
							<select id="arc-parent-role">
								<option value=""><?php _e('No Parent Role', 'role-user-manager'); ?></option>
								<?php foreach ($roles as $role_key => $role_name): ?>
									<option value="<?php echo esc_attr($role_key); ?>">
										<?php echo esc_html($role_name); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<button type="button" id="arc-create-role" class="button button-secondary">
								<?php _e('Create Role', 'role-user-manager'); ?>
							</button>
						</div>

						<div id="arc-roles-list">
							<?php foreach ($roles_with_hierarchy as $role_key => $role_data): ?>
								<div class="arc-role-item" data-role="<?php echo esc_attr($role_key); ?>">
									<strong><?php echo esc_html($role_data['name']); ?></strong>
									<br><small><?php echo esc_html($role_key); ?></small>
									<?php if (isset($role_data['parent_key']) && $role_data['parent_key']): ?>
										<br><small style="color: #666;">
											<?php _e('Parent:', 'role-user-manager'); ?>
											<?php echo esc_html($roles[$role_data['parent_key']] ?? $role_data['parent_key']); ?>
										</small>
									<?php endif; ?>
									<?php if (!in_array($role_key, ['administrator', 'editor', 'author', 'contributor', 'subscriber'])): ?>
										<button type="button" class="button-link arc-delete-role"
											data-role="<?php echo esc_attr($role_key); ?>" style="color: #d63638; float: right;">
											<?php _e('Delete', 'role-user-manager'); ?>
										</button>
									<?php endif; ?>
								</div>
							<?php endforeach; ?>
						</div>
					</div>

					<div class="arc-content">
						<div id="arc-role-editor" style="display: none;">
							<div class="row">
								<h3 id="arc-current-role-title"></h3>
								<div id="arc-current-role-parent-title"></div>
							</div>

							<form id="arc-capabilities-form">
								<?php wp_nonce_field('arc_update_capabilities', 'arc_nonce'); ?>
								<input type="hidden" id="arc-current-role" name="role" value="">

								<div class="arc-parent-role-selector" style="margin-bottom: 20px;">
									<label for="arc-role-parent-select">
										<strong><?php _e('Parent Role:', 'role-user-manager'); ?></strong>
									</label>
									<select id="arc-role-parent-select" name="parent_role">
										<option value=""><?php _e('No Parent Role', 'role-user-manager'); ?></option>
										<?php foreach ($roles as $role_key => $role_name): ?>
											<option value="<?php echo esc_attr($role_key); ?>">
												<?php echo esc_html($role_name); ?>
											</option>
										<?php endforeach; ?>
									</select>
									<button type="button" id="arc-inherit-parent-caps" class="button button-secondary">
										<?php _e('Inherit Parent Capabilities', 'role-user-manager'); ?>
									</button>
								</div>

								<div class="arc-actions" style="margin-bottom: 20px;">
									<button type="button" id="arc-select-all"
										class="button"><?php _e('Select All', 'role-user-manager'); ?></button>
									<button type="button" id="arc-select-none"
										class="button"><?php _e('Select None', 'role-user-manager'); ?></button>
									<button type="submit"
										class="button button-primary"><?php _e('Update Role Capabilities', 'role-user-manager'); ?></button>
								</div>

								<?php foreach ($this->capability_groups as $group_name => $capabilities): ?>
									<div class="arc-capability-group">
										<h3>
											<?php echo esc_html($group_name); ?>
											<span style="font-size: 12px; font-weight: normal; color: #666;">
												(<?php echo count($capabilities); ?>
												<?php _e('capabilities', 'role-user-manager'); ?>)
											</span>
										</h3>
										<div class="arc-capabilities-grid">
											<?php foreach ($capabilities as $capability): ?>
												<div class="arc-capability-item">
													<input type="checkbox" id="cap_<?php echo esc_attr($capability); ?>"
														name="capabilities[]" value="<?php echo esc_attr($capability); ?>">
													<label for="cap_<?php echo esc_attr($capability); ?>">
														<?php echo esc_html($capability); ?>
													</label>
												</div>
											<?php endforeach; ?>
										</div>
									</div>
								<?php endforeach; ?>

								<div class="arc-actions">
									<button type="submit" class="button button-primary button-large">
										<?php _e('Update Role Capabilities', 'role-user-manager'); ?>
									</button>
								</div>
							</form>
						</div>

						<div id="arc-no-role-selected">
							<p><?php _e('Select a role from the sidebar to manage its capabilities.', 'role-user-manager'); ?>
							</p>
						</div>
					</div>
				</div>
			</div>
		</div>

		<script type="text/javascript">
			jQuery(document).ready(function($) {
				var currentRole = '';

				// Role selection
				$('.arc-role-item').click(function(e) {
					if ($(e.target).hasClass('arc-delete-role')) {
						return;
					}

					$('.arc-role-item').removeClass('active');
					$(this).addClass('active');
					currentRole = $(this).data('role');
					loadRoleCapabilities(currentRole);
				});

				// Load role capabilities
				function loadRoleCapabilities(role) {
					$('#arc-current-role').val(role);
					$('#arc-current-role-title').text('Editing: ' + $('.arc-role-item[data-role="' + role + '"] strong').text());
					$('#arc-no-role-selected').hide();
					$('#arc-role-editor').show();

					// Get role capabilities via AJAX
					$.post(arcAjax.ajaxurl, {
						action: 'arc_get_role_capabilities',
						role: role,
						nonce: arcAjax.nonce
					}, function(response) {
						if (response.success) {
							// Uncheck all capabilities first
							$('input[name="capabilities[]"]').prop('checked', false);

							// Check capabilities that the role has
							if (response.data.capabilities) {
								$.each(response.data.capabilities, function(cap, value) {
									if (value) {
										$('#cap_' + cap).prop('checked', true);
									}
								});
							}

							// Set parent role select
							$('#arc-role-parent-select').val(response.data.parent_key || '');

							// Update parent role display
							if (response.data.parent_key) {
								$('#arc-current-role-parent-title').text('Parent: ' + response.data.parent_name);
							} else {
								$('#arc-current-role-parent-title').text('');
							}
						}
					});
				}

				// Select all/none functionality
				$('#arc-select-all').click(function() {
					$('input[name="capabilities[]"]').prop('checked', true);
				});

				$('#arc-select-none').click(function() {
					$('input[name="capabilities[]"]').prop('checked', false);
				});

				// Inherit parent capabilities
				$('#arc-inherit-parent-caps').click(function() {
					var parentRole = $('#arc-role-parent-select').val();
					if (!parentRole) {
						alert('Please select a parent role first.');
						return;
					}

					$.post(arcAjax.ajaxurl, {
						action: 'arc_inherit_parent_capabilities',
						role: currentRole,
						parent_role: parentRole,
						nonce: arcAjax.nonce
					}, function(response) {
						if (response.success) {
							loadRoleCapabilities(currentRole); // Reload to show inherited capabilities
							showNotice(arcAjax.strings.parent_caps_inherited, 'success');
						} else {
							showNotice(response.data || arcAjax.strings.error, 'error');
						}
					});
				});

				// Form submission
				$('#arc-capabilities-form').submit(function(e) {
					e.preventDefault();

					var formData = $(this).serialize();
					formData += '&action=arc_update_role_capabilities';

					$('.arc-content').addClass('arc-loading');

					$.post(arcAjax.ajaxurl, formData, function(response) {
						$('.arc-content').removeClass('arc-loading');

						if (response.success) {
							showNotice(arcAjax.strings.role_updated, 'success');
							// Refresh the role list to show updated parent info
							location.reload();
						} else {
							showNotice(response.data || arcAjax.strings.error, 'error');
						}
					});
				});

				// Create new role
				$('#arc-create-role').click(function() {
					var roleName = $('#arc-new-role-name').val().trim();
					var roleKey = $('#arc-new-role-key').val().trim().toLowerCase();
					var parentRole = $('#arc-parent-role').val();

					if (!roleName || !roleKey) {
						alert('Please enter both role name and key.');
						return;
					}

					$.post(arcAjax.ajaxurl, {
						action: 'arc_create_role',
						role_name: roleName,
						role_key: roleKey,
						parent_role: parentRole,
						nonce: arcAjax.nonce
					}, function(response) {
						if (response.success) {
							showNotice(arcAjax.strings.role_created, 'success');
							location.reload(); // Refresh to show new role
						} else {
							showNotice(response.data || arcAjax.strings.error, 'error');
						}
					});
				});

				// Delete role
				$(document).on('click', '.arc-delete-role', function(e) {
					e.stopPropagation();

					if (!confirm(arcAjax.strings.confirm_delete)) {
						return;
					}

					var role = $(this).data('role');

					$.ajax({
						url: arcAjax.ajaxurl,
						method: 'POST',
						data: {
							action: 'arc_delete_role',
							role: role,
							nonce: arcAjax.nonce
						},
						success: function(response) {
							if (response.success) {
								showNotice(arcAjax.strings.role_deleted, 'success');
								location.reload(); // Refresh to remove deleted role
							} else {
								showNotice(response.data || arcAjax.strings.error, 'error');
							}
						},
						error: function() {
							showNotice(arcAjax.strings.error, 'error');
						}
					});
				});

				// Show notices
				function showNotice(message, type) {
					var notice = $('<div class="arc-notice ' + type + '">' + message + '</div>');
					$('#arc-notices').html(notice);
					setTimeout(function() {
						notice.fadeOut();
					}, 5000);
				}
			});

			// Auto-generate role key from name
			jQuery('#arc-new-role-name').on('keyup', function() {
				var name = jQuery(this).val();
				var slug = name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
				jQuery('#arc-new-role-key').val(slug);
			});

			// Advanced Role Capabilities Manager - Admin JavaScript
			// This file is automatically generated and can be customized

			jQuery(document).ready(function($) {
				// Additional JavaScript functionality can be added here    
				// Example: Capability search functionality
				var searchInput = $('<input type="text" placeholder="Search capabilities..." style="margin-bottom: 15px; width: 300px; padding: 5px;">');
				$('.arc-content h3').first().after(searchInput);

				searchInput.on('input', function() {
					var searchTerm = $(this).val().toLowerCase();
					$('.arc-capability-item').each(function() {
						var capabilityText = $(this).find('label').text().toLowerCase();
						if (capabilityText.indexOf(searchTerm) !== -1) {
							$(this).show();
						} else {
							$(this).hide();
						}
					});

					// Hide empty groups
					$('.arc-capability-group').each(function() {
						var visibleItems = $(this).find('.arc-capability-item:visible').length;
						if (visibleItems === 0) {
							$(this).hide();
						} else {
							$(this).show();
						}
					});
				});

				// Group toggle functionality
				$('.arc-capability-group h3').css('cursor', 'pointer').click(function() {
					$(this).next('.arc-capabilities-grid').toggle();
				});

				// Bulk select per group
				$('.arc-capability-group h3').each(function() {
					var groupName = $(this).text();
					var selectAllBtn = $('<button type="button" class="button button-small" style="margin-left: 10px;">Select All</button>');
					var selectNoneBtn = $('<button type="button" class="button button-small" style="margin-left: 5px;">None</button>');

					$(this).append(selectAllBtn).append(selectNoneBtn);

					selectAllBtn.click(function(e) {
						e.stopPropagation();
						$(this).closest('.arc-capability-group').find('input[type="checkbox"]').prop('checked', true);
					});

					selectNoneBtn.click(function(e) {
						e.stopPropagation();
						$(this).closest('.arc-capability-group').find('input[type="checkbox"]').prop('checked', false);
					});
				});
			});

			jQuery(document).ready(function($) {
				// Role selection
				$('.arc-role-item').on('click', function() {
					var roleKey = $(this).data('role');
					selectRole(roleKey);
				});

				// Create new role
				$('#arc-create-role').on('click', function() {
					var roleName = $('#arc-new-role-name').val().trim();
					var roleKey = $('#arc-new-role-key').val().trim();
					var parentRole = $('#arc-parent-role').val();

					if (!roleName || !roleKey) {
						showNotice(arcAjax.strings.error, 'error');
						return;
					}

					$.ajax({
						url: arcAjax.ajaxurl,
						method: 'POST',
						data: {
							action: 'arc_create_role',
							role_name: roleName,
							role_key: roleKey,
							parent_role: parentRole,
							nonce: arcAjax.nonce
						},
						success: function(response) {
							if (response.success) {
								showNotice(response.data.message, 'success');
								location.reload();
							} else {
								showNotice(response.data.message, 'error');
							}
						},
						error: function() {
							showNotice(arcAjax.strings.error, 'error');
						}
					});
				});

			
				// Update role capabilities
				$('#arc-capabilities-form').on('submit', function(e) {
					e.preventDefault();

					var formData = $(this).serialize();

					$.ajax({
						url: arcAjax.ajaxurl,
						method: 'POST',
						data: formData + '&action=arc_update_role_capabilities&nonce=' + arcAjax.nonce,
						success: function(response) {
							if (response.success) {
								showNotice(response.data.message, 'success');
							} else {
								showNotice(response.data.message, 'error');
							}
						},
						error: function() {
							showNotice(arcAjax.strings.error, 'error');
						}
					});
				});

				// Inherit parent capabilities
				$('#arc-inherit-parent-caps').on('click', function() {
					var roleKey = $('#arc-current-role').val();

					if (!roleKey) {
						return;
					}

					$.ajax({
						url: arcAjax.ajaxurl,
						method: 'POST',
						data: {
							action: 'arc_inherit_parent_capabilities',
							role: roleKey,
							nonce: arcAjax.nonce
						},
						success: function(response) {
							if (response.success) {
								showNotice(response.data.message, 'success');
								// Update checkboxes
								updateCapabilityCheckboxes(response.data.capabilities);
							} else {
								showNotice(response.data.message, 'error');
							}
						},
						error: function() {
							showNotice(arcAjax.strings.error, 'error');
						}
					});
				});

				// Parent role selection change
				$('#arc-role-parent-select').on('change', function() {
					var selectedParent = $(this).val();
					var currentRole = $('#arc-current-role').val();

					if (selectedParent && currentRole) {
						// Check for circular dependency
						checkCircularDependency(currentRole, selectedParent);
					}
				});

				// Select all capabilities
				$('#arc-select-all').on('click', function() {
					$('.arc-capability-checkbox').prop('checked', true);
				});

				// Select no capabilities
				$('#arc-select-none').on('click', function() {
					$('.arc-capability-checkbox').prop('checked', false);
				});

				function selectRole(roleKey) {
					$('.arc-role-item').removeClass('active');
					$('.arc-role-item[data-role="' + roleKey + '"]').addClass('active');

					$('#arc-current-role').val(roleKey);
					$('#arc-welcome-message').hide();
					$('#arc-role-editor').show();

					// Update role title
					var roleName = $('.arc-role-item[data-role="' + roleKey + '"] .arc-role-title').text();
					$('#arc-current-role-title').text('Edit Role: ' + roleName);

					// Load role capabilities
					loadRoleCapabilities(roleKey);

					// Load parent options
					loadParentOptions(roleKey);
				}

				function loadRoleCapabilities(roleKey) {
					$.ajax({
						url: arcAjax.ajaxurl,
						method: 'POST',
						data: {
							action: 'arc_get_role_capabilities',
							role: roleKey,
							nonce: arcAjax.nonce
						},
						success: function(response) {
							if (response.success) {
								updateCapabilityCheckboxes(response.data.capabilities);

								// Update parent role selection
								$('#arc-role-parent-select').val(response.data.parent_role || '');

								// Update parent role title
								if (response.data.parent_role_name) {
									$('#arc-current-role-parent-title').html(
										'<small>Child of: <strong>' + response.data.parent_role_name + '</strong></small>'
									).show();
								} else {
									$('#arc-current-role-parent-title').hide();
								}
							} else {
								showNotice(response.data.message, 'error');
							}
						},
						error: function() {
							showNotice(arcAjax.strings.error, 'error');
						}
					});
				}

				function loadParentOptions(roleKey) {
					$.ajax({
						url: arcAjax.ajaxurl,
						method: 'POST',
						data: {
							action: 'arc_get_parent_options',
							role: roleKey,
							nonce: arcAjax.nonce
						},
						success: function(response) {
							if (response.success) {
								var select = $('#arc-role-parent-select');
								var currentValue = select.val();

								// Clear existing options except "No Parent"
								select.find('option:not([value=""])').remove();

								// Add new options
								$.each(response.data.options, function(key, name) {
									select.append('<option value="' + key + '">' + name + '</option>');
								});

								// Restore selected value if still valid
								if (currentValue && response.data.options[currentValue]) {
									select.val(currentValue);
								}
							}
						}
					});
				}

				function updateCapabilityCheckboxes(capabilities) {
					// Uncheck all first
					$('.arc-capability-checkbox').prop('checked', false);

					// Check capabilities that are granted
					$.each(capabilities, function(cap, granted) {
						if (granted) {
							$('#cap-' + cap).prop('checked', true);
						}
					});
				}

				function checkCircularDependency(childRole, parentRole) {
					// Simple client-side check - server will do the real validation
					if (childRole === parentRole) {
						showNotice(arcAjax.strings.circular_dependency, 'error');
						$('#arc-role-parent-select').val('');
					}
				}

				function showNotice(message, type) {
					var noticeClass = type === 'error' ? 'notice-error' : 'notice-success';
					var notice = $('<div class="notice ' + noticeClass + ' is-dismissible"><p>' + message + '</p></div>');

					$('#arc-notices').html(notice);

					// Auto-hide success notices
					if (type === 'success') {
						setTimeout(function() {
							notice.fadeOut();
						}, 3000);
					}
				}
			});
		</script>
<?php
	}
	public function ajax_update_role_capabilities(): void
	{
		// Verify nonce
		if (!wp_verify_nonce($_POST['arc_nonce'], 'arc_update_capabilities')) {
			wp_die(__('Security check failed', 'role-user-manager'));
		}

		// Check permissions
		if (!current_user_can('manage_options')) {
			wp_send_json_error(__('Insufficient permissions', 'role-user-manager'));
		}

		$role_key = sanitize_text_field($_POST['role']);
		$capabilities = isset($_POST['capabilities']) ? array_map('sanitize_text_field', $_POST['capabilities']) : [];
		$parent_role = sanitize_text_field($_POST['parent_role']);

		// Get the role
		$role = get_role($role_key);
		if (!$role) {
			wp_send_json_error(__('Role not found', 'role-user-manager'));
		}

		// Get all possible capabilities to properly remove unchecked ones
		$all_capabilities = [];
		foreach ($this->capability_groups as $group_caps) {
			$all_capabilities = array_merge($all_capabilities, $group_caps);
		}

		// Remove all capabilities first
		foreach ($all_capabilities as $cap) {
			$role->remove_cap($cap);
		}

		// Add selected capabilities
		foreach ($capabilities as $cap) {
			$role->add_cap($cap);
		}

		// Update parent role hierarchy
		if (!empty($parent_role)) {
			$parent_level = $this->get_role_level($parent_role);
			if ($parent_level + 1 >= self::MAX_ROLE_HIERARCHY_LEVEL) {
				wp_send_json_error(__('Cannot assign parent: would exceed maximum hierarchy level.', 'role-user-manager'));
			}
		}
		$this->set_parent_role($role_key, $parent_role);

		wp_send_json_success([
			'message' => __('Role capabilities updated successfully', 'role-user-manager'),
			'capabilities' => $capabilities,
			'parent_role' => $parent_role
		]);
	}

	/**
	 * AJAX handler for creating new roles
	 */
	public function ajax_create_role(): void
	{
		// Verify nonce
		if (!wp_verify_nonce($_POST['nonce'], 'arc_nonce')) {
			wp_send_json_error(__('Security check failed', 'role-user-manager'));
		}

		// Check permissions
		if (!current_user_can('manage_options')) {
			wp_send_json_error(__('Insufficient permissions', 'role-user-manager'));
		}

		$role_name = sanitize_text_field($_POST['role_name']);
		$role_key = sanitize_text_field($_POST['role_key']);
		$parent_role = sanitize_text_field($_POST['parent_role']);

		// Validate inputs
		if (empty($role_name) || empty($role_key)) {
			wp_send_json_error(__('Role name and key are required', 'role-user-manager'));
		}

		// Check if role key already exists
		if (get_role($role_key)) {
			wp_send_json_error(__('Role key already exists', 'role-user-manager'));
		}

		// Create the role with basic read capability
		$result = add_role($role_key, $role_name, ['read' => true]);

		if (!$result) {
			wp_send_json_error(__('Failed to create role', 'role-user-manager'));
		}

		// Set parent role if specified
		if (!empty($parent_role)) {
			$parent_level = $this->get_role_level($parent_role);
			if ($parent_level + 1 >= self::MAX_ROLE_HIERARCHY_LEVEL) {
				wp_send_json_error(__('Cannot assign parent: would exceed maximum hierarchy level.', 'role-user-manager'));
			}
			$this->set_parent_role($role_key, $parent_role);
		}

		wp_send_json_success([
			'message' => __('Role created successfully', 'role-user-manager'),
			'role_key' => $role_key,
			'role_name' => $role_name,
			'parent_role' => $parent_role
		]);
	}

	/**
	 * AJAX handler for deleting roles
	 */
	public function ajax_delete_role(): void
	{
		// Verify nonce
		if (!wp_verify_nonce($_POST['nonce'], 'arc_nonce')) {
			wp_send_json_error(__('Security check failed', 'role-user-manager'));
		}

		// Check permissions
		if (!current_user_can('manage_options')) {
			wp_send_json_error(__('Insufficient permissions', 'role-user-manager'));
		}

		$role_key = sanitize_text_field($_POST['role']);

		// Prevent deletion of core WordPress roles
		$core_roles = ['administrator', 'editor', 'author', 'contributor', 'subscriber'];
		if (in_array($role_key, $core_roles)) {
			wp_send_json_error(__('Cannot delete core WordPress roles', 'role-user-manager'));
		}

		// Check if role exists
		if (!get_role($role_key)) {
			wp_send_json_error(__('Role not found', 'role-user-manager'));
		}

		// Remove role from hierarchy
		$this->set_parent_role($role_key, '');

		// Remove any child relationships
		$hierarchy = $this->get_role_hierarchy();
		foreach ($hierarchy as $child_role => $parent_role) {
			if ($parent_role === $role_key) {
				$this->set_parent_role($child_role, '');
			}
		}

		// Delete the role
		remove_role($role_key);

		wp_send_json_success([
			'message' => __('Role deleted successfully', 'role-user-manager'),
			'role_key' => $role_key
		]);
	}

	/**
	 * AJAX handler for getting role capabilities
	 */
	public function ajax_get_role_capabilities(): void
	{
		// Verify nonce
		if (!wp_verify_nonce($_POST['nonce'], 'arc_nonce')) {
			wp_send_json_error(__('Security check failed', 'role-user-manager'));
		}

		// Check permissions
		if (!current_user_can('manage_options')) {
			wp_send_json_error(__('Insufficient permissions', 'role-user-manager'));
		}

		$role_key = sanitize_text_field($_POST['role']);
		$role = get_role($role_key);

		if (!$role) {
			wp_send_json_error(__('Role not found', 'role-user-manager'));
		}

		// Get parent role information
		$parent_key = $this->get_parent_role($role_key);
		$parent_name = '';
		if ($parent_key) {
			$parent_role = get_role($parent_key);
			if ($parent_role) {
				global $wp_roles;
				if (!isset($wp_roles)) {
					$wp_roles = new WP_Roles();
				}
				$parent_name = $wp_roles->roles[$parent_key]['name'] ?? $parent_key;
			}
		}

		wp_send_json_success([
			'capabilities' => $role->capabilities,
			'parent_key' => $parent_key,
			'parent_name' => $parent_name
		]);
	}

	/**
	 * AJAX handler for inheriting parent capabilities
	 */
	public function ajax_inherit_parent_capabilities(): void
	{
		// Verify nonce
		if (!wp_verify_nonce($_POST['nonce'], 'arc_nonce')) {
			wp_send_json_error(__('Security check failed', 'role-user-manager'));
		}

		// Check permissions
		if (!current_user_can('manage_options')) {
			wp_send_json_error(__('Insufficient permissions', 'role-user-manager'));
		}

		$role_key = sanitize_text_field($_POST['role']);
		$parent_role_key = sanitize_text_field($_POST['parent_role']);

		// Validate inputs
		if (empty($role_key) || empty($parent_role_key)) {
			wp_send_json_error(__('Role and parent role are required', 'role-user-manager'));
		}

		// Check if roles exist
		$role = get_role($role_key);
		$parent_role = get_role($parent_role_key);

		if (!$role || !$parent_role) {
			wp_send_json_error(__('One or both roles not found', 'role-user-manager'));
		}

		// Prevent circular inheritance
		if ($role_key === $parent_role_key) {
			wp_send_json_error(__('A role cannot inherit from itself', 'role-user-manager'));
		}

		// Set parent role first
		$this->set_parent_role($role_key, $parent_role_key);

		// Inherit capabilities
		$result = $this->inherit_parent_capabilities($role_key);

		if ($result) {
			wp_send_json_success([
				'message' => __('Parent capabilities inherited successfully', 'role-user-manager'),
				'role_key' => $role_key,
				'parent_role_key' => $parent_role_key
			]);
		} else {
			wp_send_json_error(__('Failed to inherit parent capabilities', 'role-user-manager'));
		}
	}

	/**
	 * AJAX handler for getting parent options
	 */
	public function ajax_get_parent_options(): void
	{
		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => __('Insufficient permissions', 'role-user-manager')]);
		}
		if (!isset($_POST['role']) || !isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'arc_nonce')) {
			wp_send_json_error(['message' => __('Security check failed', 'role-user-manager')]);
		}
		$current_role = sanitize_text_field($_POST['role']);
		// Get all roles
		global $wp_roles;
		if (!isset($wp_roles)) {
			$wp_roles = new WP_Roles();
		}
		$roles = $wp_roles->get_names();
		// Get descendants to prevent circular dependencies
		$manager = new AdvancedRoleCapabilitiesManager();
		$descendants = [];
		$stack = [$current_role];
		while ($stack) {
			$parent = array_pop($stack);
			$children = $manager->get_child_roles($parent);
			foreach ($children as $child) {
				if (!in_array($child, $descendants)) {
					$descendants[] = $child;
					$stack[] = $child;
				}
			}
		}
		$options = [];
		foreach ($roles as $role_key => $role_name) {
			if ($role_key === $current_role) continue;
			if (in_array($role_key, $descendants)) continue;
			$options[$role_key] = $role_name;
		}
		wp_send_json_success(['options' => $options]);
	}

	/**
	 * Get all child roles for a given parent role
	 */
	public function get_child_roles(string $parent_role_key): array
	{
		$hierarchy = $this->get_role_hierarchy();
		$children = [];

		foreach ($hierarchy as $child_role => $parent_role) {
			if ($parent_role === $parent_role_key) {
				$children[] = $child_role;
			}
		}

		return $children;
	}

	/**
	 * Check if a role hierarchy would create a circular dependency
	 */
	private function would_create_circular_dependency(string $child_role, string $parent_role): bool
	{
		// A role cannot be its own parent
		if ($child_role === $parent_role) {
			return true;
		}

		// Check if the parent role is already a descendant of the child role
		$current_parent = $this->get_parent_role($parent_role);
		$visited = [];

		while ($current_parent && !in_array($current_parent, $visited)) {
			if ($current_parent === $child_role) {
				return true;
			}
			$visited[] = $current_parent;
			$current_parent = $this->get_parent_role($current_parent);
		}

		return false;
	}

	/**
	 * Get role hierarchy tree for display
	 */
	public function get_role_hierarchy_tree(): array
	{
		$roles = $this->get_roles_with_hierarchy();
		$tree = [];

		// First, add all roles without parents (top-level roles)
		foreach ($roles as $role_key => $role_data) {
			if (!isset($role_data['parent_key']) || empty($role_data['parent_key'])) {
				$tree[$role_key] = $role_data;
				$tree[$role_key]['children'] = [];
			}
		}

		// Then, add child roles
		foreach ($roles as $role_key => $role_data) {
			if (isset($role_data['parent_key']) && !empty($role_data['parent_key'])) {
				$parent_key = $role_data['parent_key'];
				if (isset($tree[$parent_key])) {
					$tree[$parent_key]['children'][$role_key] = $role_data;
				} else {
					// Parent not found in tree, add as top-level (orphaned)
					$tree[$role_key] = $role_data;
					$tree[$role_key]['children'] = [];
				}
			}
		}

		return $tree;
	}

	/**
	 * Export roles and capabilities configuration
	 */
	public function export_roles_config(): array
	{
		$roles = $this->get_roles_with_hierarchy();
		$hierarchy = $this->get_role_hierarchy();

		$config = [
			'version' => ARC_VERSION,
			'timestamp' => current_time('mysql'),
			'roles' => $roles,
			'hierarchy' => $hierarchy
		];

		return $config;
	}

	/**
	 * Check if current PHP version supports union types
	 */
	private function supports_union_types(): bool
	{
		return version_compare(PHP_VERSION, '8.0.0', '>=');
	}

	/**
	 * Get the appropriate import method based on PHP version
	 */
	private function get_import_method()
	{
		if ($this->supports_union_types()) {
			return [$this, 'import_roles_config_php8'];
		}
		return [$this, 'import_roles_config'];
	}

	/**
	 * Import roles and capabilities configuration
	 */
	public function import_roles_config(array $config)
	{
		if (!isset($config['roles']) || !isset($config['hierarchy'])) {
			return new WP_Error('invalid_config', __('Invalid configuration format', 'role-user-manager'));
		}

		$imported_roles = 0;
		$skipped_roles = 0;

		// Import roles
		foreach ($config['roles'] as $role_key => $role_data) {
			// Skip core WordPress roles
			if (in_array($role_key, ['administrator', 'editor', 'author', 'contributor', 'subscriber'])) {
				$skipped_roles++;
				continue;
			}

			// Create or update role
			$role = get_role($role_key);
			if (!$role) {
				add_role($role_key, $role_data['name'], $role_data['capabilities']);
				$imported_roles++;
			} else {
				// Update existing role capabilities
				foreach ($role_data['capabilities'] as $cap => $grant) {
					if ($grant) {
						$role->add_cap($cap);
					} else {
						$role->remove_cap($cap);
					}
				}
			}
		}

		// Import hierarchy
		$this->save_role_hierarchy($config['hierarchy']);

		return [
			'imported_roles' => $imported_roles,
			'skipped_roles' => $skipped_roles
		];
	}

	/**
	 * Import roles and capabilities configuration (PHP 8+ version with union types)
	 */
	private function import_roles_config_php8(array $config): array|WP_Error
	{
		if (!isset($config['roles']) || !isset($config['hierarchy'])) {
			return new WP_Error('invalid_config', __('Invalid configuration format', 'role-user-manager'));
		}

		$imported_roles = 0;
		$skipped_roles = 0;

		// Import roles
		foreach ($config['roles'] as $role_key => $role_data) {
			// Skip core WordPress roles
			if (in_array($role_key, ['administrator', 'editor', 'author', 'contributor', 'subscriber'])) {
				$skipped_roles++;
				continue;
			}

			// Create or update role
			$role = get_role($role_key);
			if (!$role) {
				add_role($role_key, $role_data['name'], $role_data['capabilities']);
				$imported_roles++;
			} else {
				// Update existing role capabilities
				foreach ($role_data['capabilities'] as $cap => $grant) {
					if ($grant) {
						$role->add_cap($cap);
					} else {
						$role->remove_cap($cap);
					}
				}
			}
		}

		// Import hierarchy
		$this->save_role_hierarchy($config['hierarchy']);

		return [
			'imported_roles' => $imported_roles,
			'skipped_roles' => $skipped_roles
		];
	}

	// --- Modular capability assignment ---
	public function assign_capabilities_to_roles(array $capabilities, array $roles = null): void
	{
		if (!$roles) $roles = $this->auto_roles;
		foreach ($roles as $role_key) {
			$role = get_role($role_key);
			if ($role) {
				foreach ($capabilities as $cap) {
					if (!$role->has_cap($cap)) {
						$role->add_cap($cap);
						do_action('arc_capability_added', $role_key, $cap);
						$this->log_audit("Added capability '$cap' to role '$role_key'");
					}
				}
			}
		}
	}
	public function remove_capabilities_from_roles(array $capabilities, array $roles = null): void
	{
		if (!$roles) $roles = $this->auto_roles;
		foreach ($roles as $role_key) {
			$role = get_role($role_key);
			if ($role) {
				foreach ($capabilities as $cap) {
					if ($role->has_cap($cap)) {
						$role->remove_cap($cap);
						do_action('arc_capability_removed', $role_key, $cap);
						$this->log_audit("Removed capability '$cap' from role '$role_key'");
					}
				}
			}
		}
	}

	// --- Automatic assignment/removal on plugin activation/deactivation ---
	public function auto_assign_plugin_caps(string $plugin, bool $network_wide): void
	{
		$before = $this->get_all_capabilities();
		// Try to include the plugin file to register its caps (best effort)
		$plugin_file = WP_PLUGIN_DIR . '/' . $plugin;
		if (file_exists($plugin_file)) {
			include_once($plugin_file);
		}
		$after = $this->get_all_capabilities();
		$new_caps = array_diff($after, $before);
		if (!empty($new_caps)) {
			$this->assign_capabilities_to_roles($new_caps);
			update_option('arc_plugin_caps_' . md5($plugin), $new_caps);
			$this->log_audit("Auto-assigned new plugin capabilities to custom roles: " . implode(', ', $new_caps));
		}
	}
	public function auto_remove_plugin_caps(string $plugin, bool $network_wide): void
	{
		$caps = get_option('arc_plugin_caps_' . md5($plugin), []);
		if (!empty($caps)) {
			$this->remove_capabilities_from_roles($caps);
			delete_option('arc_plugin_caps_' . md5($plugin));
			$this->log_audit("Auto-removed plugin capabilities from custom roles: " . implode(', ', $caps));
		}
	}
	// --- Get all capabilities from all roles ---
	public function get_all_capabilities(): array
	{
		global $wp_roles;
		if (!isset($wp_roles)) {
			$wp_roles = new WP_Roles();
		}
		$caps = [];
		foreach ($wp_roles->roles as $role) {
			if (isset($role['capabilities'])) {
				$caps = array_merge($caps, array_keys($role['capabilities']));
			}
		}
		return array_unique($caps);
	}
	// --- Simple audit logging ---
	public function log_audit(string $message): void
	{
		$log = get_option($this->audit_log_option, []);
		$log[] = [
			'time' => current_time('mysql'),
			'user' => is_user_logged_in() ? wp_get_current_user()->user_login : 'system',
			'message' => $message
		];
		// Keep only last 100 entries
		if (count($log) > 100) {
			$log = array_slice($log, -100);
		}
		update_option($this->audit_log_option, $log);
	}

	/**
	 * Modify the user edit interface to show single role selection instead of multiple checkboxes
	 */
	public function modify_user_edit_interface(): void
	{
		global $pagenow;
		
		// Only apply on user edit pages
		if (!in_array($pagenow, ['user-edit.php', 'user-new.php', 'profile.php'])) {
			return;
		}

		// Hide the default WordPress role checkboxes
		echo '<style>
			/* Hide default WordPress role checkboxes */
			.user-role-wrap .description,
			.user-role-wrap .role-checkboxes,
			.user-role-wrap input[type="checkbox"][name*="role"] {
				display: none !important;
			}
			
			/* Style our custom single role dropdown */
			.arc-single-role-select {
				width: 100%;
				max-width: 400px;
				padding: 8px;
				border: 1px solid #ddd;
				border-radius: 4px;
				background: #fff;
			}
			
			.arc-single-role-description {
				color: #666;
				font-style: italic;
				margin-top: 5px;
			}
		</style>';
	}

	/**
	 * Add JavaScript to enforce single role selection
	 */
	public function enforce_single_role_script(): void
	{
		global $pagenow;
		
		// Only apply on user edit pages
		if (!in_array($pagenow, ['user-edit.php', 'user-new.php', 'profile.php'])) {
			return;
		}

		// Get all available roles
		global $wp_roles;
		if (!isset($wp_roles)) {
			$wp_roles = new WP_Roles();
		}
		$roles = $wp_roles->get_names();

		// Get current user's role (if editing existing user)
		$current_role = '';
		if (isset($_GET['user_id'])) {
			$user = get_userdata($_GET['user_id']);
			if ($user && !empty($user->roles)) {
				$current_role = reset($user->roles); // Get first role
			}
		}

		echo '<script type="text/javascript">
		jQuery(document).ready(function($) {
			// Replace the default role checkboxes with a single dropdown
			var roleWrap = $(".user-role-wrap");
			if (roleWrap.length > 0) {
				// Create single role dropdown
				var roleSelect = $("<select name=\"role\" class=\"arc-single-role-select\">");
				roleSelect.append("<option value=\"\">' . esc_js(__('Select a role', 'role-user-manager')) . '</option>");
				
				// Add role options
				var roles = ' . json_encode($roles) . ';
				Object.keys(roles).forEach(function(roleKey) {
					var roleName = roles[roleKey];
					var selected = (roleKey === "' . esc_js($current_role) . '") ? " selected" : "";
					roleSelect.append("<option value=\"" + roleKey + "\"" + selected + ">" + roleName + "</option>");
				});
				
				// Replace the existing role interface
				roleWrap.find(".role-checkboxes").hide();
				roleWrap.find(".description").after(roleSelect);
				roleWrap.find(".description").after("<p class=\"arc-single-role-description\">' . esc_js(__('Users can only have one role at a time.', 'role-user-manager')) . '</p>");
			}
			
			// Ensure only one role can be selected
			$("input[name*=\"role\"]").on("change", function() {
				if (this.checked) {
					$("input[name*=\"role\"]").not(this).prop("checked", false);
				}
			});
			
			// Handle form submission to ensure single role
			$("form").on("submit", function() {
				var selectedRoles = $("input[name*=\"role\"]:checked");
				if (selectedRoles.length > 1) {
					alert("' . esc_js(__('Users can only have one role. Please select only one role.', 'role-user-manager')) . '");
					return false;
				}
			});
		});
		</script>';
	}

	/**
	 * Ensure users only have one role when profile is updated
	 */
	public function ensure_single_role(int $user_id, $old_user_data = null): void
	{
		$user = get_userdata($user_id);
		if (!$user) {
			return;
		}

		// If user has multiple roles, keep only the first one
		if (count($user->roles) > 1) {
			$primary_role = reset($user->roles);
			$user->set_role($primary_role);
			
			// Log the action
			$this->log_audit("Enforced single role for user ID {$user_id}: kept role '{$primary_role}', removed other roles");
		}
	}

	/**
	 * Handle role assignment when the user edit form is submitted.
	 * This ensures that if a user is assigned multiple roles, only the first one is kept.
	 */
	public function handle_role_assignment(int $user_id): void
	{
		$this->ensure_single_role($user_id);
	}
}

// Initialize the plugin
new AdvancedRoleCapabilitiesManager();

// Activation hook
register_activation_hook(__FILE__, 'arc_activate');
function arc_activate(): void
{   $group_leader = get_role('group_leader');
	$caps = [];
    if ($group_leader) {
        $caps = $group_leader->capabilities;
    }

    // Ensure 'read' is included
    $caps['read'] = true;

	// Create any necessary database tables or options
	add_option('arc_role_hierarchy', []);
	add_option('arc_audit_log', []); // Initialize audit log

	// Create custom roles
	add_role('data-viewer', __('Data Viewer', 'role-user-manager'), $caps);
	add_role('program-leader', __('Program Leader', 'role-user-manager'), ['read' => true]);
	add_role('site-supervisor', __('Site Supervisor', 'role-user-manager'), ['read' => true]);
	add_role('frontline-staff', __('Frontline Staff', 'role-user-manager'), ['read' => true]);

	// Set up parent relationships
	$hierarchy = get_option('arc_role_hierarchy', []);
	$hierarchy['site-supervisor'] = 'program-leader';
	$hierarchy['frontline-staff'] = 'site-supervisor';
	update_option('arc_role_hierarchy', $hierarchy);

	// Flush rewrite rules if needed
	flush_rewrite_rules();
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'arc_deactivate');
function arc_deactivate(): void
{
	// Clean up transients
	delete_transient('arc_all_capabilities');

	// Flush rewrite rules
	flush_rewrite_rules();
}

// Uninstall hook
register_uninstall_hook(__FILE__, 'arc_uninstall');
function arc_uninstall(): void
{
	// Remove options
	delete_option('arc_role_hierarchy');
	delete_option('arc_audit_log'); // Remove audit log

	// Remove transients
	delete_transient('arc_all_capabilities');

	// Remove custom roles
	remove_role('data-viewer');
	remove_role('program-leader');
	remove_role('site-supervisor');
	remove_role('frontline-staff');

	// Optionally remove other custom roles (uncomment if desired)
	// $core_roles = ['administrator', 'editor', 'author', 'contributor', 'subscriber'];
	// global $wp_roles;
	// if (isset($wp_roles)) {
	//     foreach ($wp_roles->roles as $role_key => $role_data) {
	//         if (!in_array($role_key, $core_roles)) {
	//             remove_role($role_key);
	//         }
	//     }
	// }
}

?>
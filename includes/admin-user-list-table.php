<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * RUM_User_List_Table
 *
 * Admin users table with filters and basic inline edit support (handled via JS modal)
 */
class RUM_User_List_Table extends WP_List_Table {
    private array $items_data = [];
    private array $filters = [];

    public function __construct(array $args = []) {
        parent::__construct([
            'singular' => 'rum_user',
            'plural'   => 'rum_users',
            'ajax'     => true,
        ]);

        $this->filters = [
            'role'        => sanitize_text_field($args['role'] ?? ''),
            'parent'      => intval($args['parent'] ?? 0),
            'program'     => sanitize_text_field($args['program'] ?? ''),
            'site'        => sanitize_text_field($args['site'] ?? ''),
            'search'      => sanitize_text_field($args['search'] ?? ''),
        ];
    }

    public function get_columns(): array {
        return [
            'cb'       => '<input type="checkbox" />',
            'name'     => __('User Name', 'role-user-manager'),
            'role'     => __('Role', 'role-user-manager'),
            'parent'   => __('Parent User', 'role-user-manager'),
            'program'  => __('Program', 'role-user-manager'),
            'site'     => __('Site', 'role-user-manager'),
            'actions'  => __('Actions', 'role-user-manager'),
        ];
    }

    protected function get_sortable_columns(): array {
        return [
            'name' => ['name', true],
            'role' => ['role', false],
        ];
    }

    protected function column_cb($item): string {
        return '<input type="checkbox" name="user[]" value="' . esc_attr((string)$item['ID']) . '" />';
    }

    public function column_default($item, $column_name) {
        switch ($column_name) {
            case 'name':
            case 'role':
            case 'parent':
            case 'program':
            case 'site':
                return esc_html((string)($item[$column_name] ?? ''));
            case 'actions':
                $edit_btn = '<a href="#" class="button button-small rum-quick-edit" data-user-id="' . intval($item['ID']) . '">' . esc_html__('Quick Edit', 'role-user-manager') . '</a>';
                return $edit_btn;
            default:
                return '';
        }
    }

    public function prepare_items(): void {
        $per_page = $this->get_items_per_page('rum_users_per_page', 20);

        $columns  = $this->get_columns();
        $hidden   = [];
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = [$columns, $hidden, $sortable];

        $paged   = max(1, intval($_REQUEST['paged'] ?? 1));
        $search  = $this->filters['search'];

        // Build args for get_users
        $args = [
            'number'  => $per_page,
            'offset'  => ($paged - 1) * $per_page,
            'orderby' => 'display_name',
            'order'   => 'ASC',
        ];

        if (!empty($this->filters['role'])) {
            $args['role'] = $this->filters['role'];
        }

        if (!empty($search)) {
            $args['search'] = '*' . esc_attr($search) . '*';
            $args['search_columns'] = ['user_login', 'user_nicename', 'user_email', 'user_url'];
        }

        // We'll filter program/site/parent via PHP for flexibility
        $total_users = get_users(array_merge($args, ['fields' => 'ids', 'number' => -1]));

        $rows = [];
        foreach ($total_users as $user_id) {
            $user = get_user_by('id', (int)$user_id);
            if (!$user) { continue; }

            $primary_role = !empty($user->roles) ? $user->roles[0] : '';
            $parent_id    = intval(get_user_meta($user->ID, 'parent_user_id', true));
            $parent_name  = $parent_id ? (get_user_by('id', $parent_id)->display_name ?? '') : '';

            // Program: support both keys
            $program = get_user_meta($user->ID, 'programme', true);
            if ($program === '') {
                $program = get_user_meta($user->ID, 'program', true);
            }

            // Sites: prefer array 'sites'; fallback to single 'site'
            $sites = get_user_meta($user->ID, 'sites', true);
            if (!is_array($sites) || empty($sites)) {
                $site_single = get_user_meta($user->ID, 'site', true);
                $sites = $site_single ? [$site_single] : [];
            }

            // Filters
            if (!empty($this->filters['parent']) && $parent_id !== intval($this->filters['parent'])) {
                continue;
            }
            if (!empty($this->filters['program']) && $program !== $this->filters['program']) {
                continue;
            }
            if (!empty($this->filters['site']) && !in_array($this->filters['site'], $sites, true)) {
                continue;
            }

            $rows[] = [
                'ID'      => $user->ID,
                'name'    => $user->display_name,
                'role'    => $primary_role,
                'parent'  => $parent_name ?: '-',
                'program' => $program ?: '-',
                'site'    => !empty($sites) ? implode(', ', $sites) : '-',
            ];
        }

        // Sort if requested
        $orderby = sanitize_text_field($_REQUEST['orderby'] ?? '');
        $order   = strtoupper(sanitize_text_field($_REQUEST['order'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
        if ($orderby === 'name' || $orderby === 'role') {
            usort($rows, function($a, $b) use ($orderby, $order) {
                $cmp = strcmp((string)$a[$orderby], (string)$b[$orderby]);
                return $order === 'DESC' ? -$cmp : $cmp;
            });
        }

        $this->items_data   = $rows;
        $total_items        = count($rows);
        $this->items        = array_slice($rows, ($paged - 1) * $per_page, $per_page);

        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => (int)ceil($total_items / $per_page),
        ]);
    }
}



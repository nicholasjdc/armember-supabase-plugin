<?php
/**
 * Unified Admin Page
 * Combines Settings and Table Manager into one tabbed interface
 */

if (!defined('ABSPATH')) {
    exit;
}

class Supabase_Admin_Page {

    private $supabase;

    public function __construct() {
        $this->supabase = new Supabase_Client();

        // Add admin menu
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);

        // Handle AJAX requests
        add_action('wp_ajax_supabase_sync_users', [$this, 'ajax_sync_users']);
        add_action('wp_ajax_supabase_sync_tables', [$this, 'ajax_sync_tables']);
        add_action('wp_ajax_supabase_create_table_page', [$this, 'ajax_create_table_page']);
        add_action('wp_ajax_supabase_delete_table_page', [$this, 'ajax_delete_table_page']);
        add_action('wp_ajax_supabase_toggle_table_lock', [$this, 'ajax_toggle_table_lock']);
        add_action('wp_ajax_supabase_set_library_table', [$this, 'ajax_set_library_table']);
        add_action('wp_ajax_supabase_set_pdf_column', [$this, 'ajax_set_pdf_column']);
        add_action('wp_ajax_supabase_toggle_table_searchable', [$this, 'ajax_toggle_table_searchable']);
        add_action('wp_ajax_supabase_set_table_search_fields', [$this, 'ajax_set_table_search_fields']);

        // Enqueue admin scripts
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
    }

    /**
     * Add admin menu item
     */
    public function add_admin_menu() {
        add_menu_page(
            'Supabase Sync',
            'Supabase Sync',
            'manage_options',
            'supabase-armember',
            [$this, 'render_admin_page'],
            'dashicons-database',
            30
        );
    }

    /**
     * Register plugin settings
     */
    public function register_settings() {
        register_setting('supabase_armember_settings', 'supabase_project_url');
        register_setting('supabase_armember_settings', 'supabase_service_key');
        register_setting('supabase_armember_settings', 'supabase_access_plans');
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'toplevel_page_supabase-armember') {
            return;
        }

        wp_enqueue_script('jquery');

        wp_enqueue_style(
            'supabase-admin',
            SUPABASE_ARMEMBER_PLUGIN_URL . 'admin/css/table-manager.css',
            [],
            SUPABASE_ARMEMBER_VERSION
        );

        wp_enqueue_script(
            'supabase-admin',
            SUPABASE_ARMEMBER_PLUGIN_URL . 'admin/js/admin-page.js',
            ['jquery'],
            SUPABASE_ARMEMBER_VERSION,
            true
        );

        wp_localize_script('supabase-admin', 'supabaseAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('supabase_admin'),
            'syncUsersNonce' => wp_create_nonce('supabase_sync_users')
        ]);
    }

    /**
     * Render admin page with tabs
     */
    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'settings';

        ?>
        <div class="wrap">
            <h1>Supabase ARMember Sync</h1>

            <h2 class="nav-tab-wrapper">
                <a href="?page=supabase-armember&tab=settings"
                   class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                    Settings
                </a>
                <a href="?page=supabase-armember&tab=tables"
                   class="nav-tab <?php echo $active_tab === 'tables' ? 'nav-tab-active' : ''; ?>">
                    Tables
                </a>
                <a href="?page=supabase-armember&tab=library"
                   class="nav-tab <?php echo $active_tab === 'library' ? 'nav-tab-active' : ''; ?>">
                    Library
                </a>
                <a href="?page=supabase-armember&tab=librarians"
                   class="nav-tab <?php echo $active_tab === 'librarians' ? 'nav-tab-active' : ''; ?>">
                    Librarians
                </a>
                <a href="?page=supabase-armember&tab=logs"
                   class="nav-tab <?php echo $active_tab === 'logs' ? 'nav-tab-active' : ''; ?>">
                    Sync Logs
                </a>
            </h2>

            <div class="tab-content">
                <?php
                if ($active_tab === 'settings') {
                    $this->render_settings_tab();
                } elseif ($active_tab === 'library') {
                    $this->render_library_tab();
                } elseif ($active_tab === 'librarians') {
                    $this->render_librarians_tab();
                } elseif ($active_tab === 'logs') {
                    $this->render_logs_tab();
                } else {
                    $this->render_tables_tab();
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render Settings Tab
     */
    private function render_settings_tab() {
        // Save settings
        if (isset($_POST['submit'])) {
            check_admin_referer('supabase_armember_settings');

            update_option('supabase_project_url', sanitize_text_field($_POST['supabase_project_url']));

            // Only update service key if a new value was provided
            // Empty field means "keep existing key" (don't overwrite with empty)
            $submitted_key = sanitize_text_field($_POST['supabase_service_key']);
            if (!empty($submitted_key) && $submitted_key !== str_repeat('•', 40)) {
                update_option('supabase_service_key', $submitted_key);
            }

            update_option('supabase_access_plans', sanitize_text_field($_POST['supabase_access_plans']));

            echo '<div class="notice notice-success"><p>Settings saved successfully.</p></div>';
        }

        // Get values from database
        $project_url = get_option('supabase_project_url', '');
        $service_key = get_option('supabase_service_key', '');
        $access_plans = get_option('supabase_access_plans', '');

        // Anonymize service key for display (show only if empty for initial setup)
        $service_key_display = empty($service_key) ? '' : str_repeat('•', 40);

        ?>

        <form method="post" action="">
            <?php wp_nonce_field('supabase_armember_settings'); ?>

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="supabase_project_url">Supabase Project URL</label>
                    </th>
                    <td>
                        <input type="url" id="supabase_project_url" name="supabase_project_url"
                               value="<?php echo esc_attr($project_url); ?>"
                               class="regular-text" required>
                        <p class="description">Your Supabase project URL (e.g., https://xxxxx.supabase.co)</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="supabase_service_key">Supabase Service Key</label>
                    </th>
                    <td>
                        <input type="password"
                               id="supabase_service_key"
                               name="supabase_service_key"
                               value="<?php echo esc_attr($service_key_display); ?>"
                               placeholder="<?php echo empty($service_key) ? 'Enter your service role key' : '••••••••••••••••••••••••••••••••••••••••'; ?>"
                               class="regular-text">
                        <?php if (!empty($service_key)): ?>
                            <p class="description">🔒 <strong>Service key is set and hidden for security.</strong> Leave blank to keep existing key, or enter a new key to update.</p>
                        <?php else: ?>
                            <p class="description">Your Supabase service role key (keep this secret!)</p>
                        <?php endif; ?>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="supabase_access_plans">Paid Plans (Comma Separated)</label>
                    </th>
                    <td>
                        <input type="text" id="supabase_access_plans" name="supabase_access_plans"
                               value="<?php echo esc_attr($access_plans); ?>" class="regular-text">
                        <p class="description">Plan names that grant access to Supabase data (e.g., "Plan (Paid),Premium")</p>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>

        <hr>

        <h2>User Sync</h2>
        <p>Manually sync all WordPress users to Supabase. Users are also automatically synced when they register or update their profile.</p>

        <div id="sync-users-status" style="display:none;"></div>

        <p>
            <button type="button" id="sync-users-btn" class="button button-secondary">
                Sync All Users to Supabase
            </button>
        </p>

        <hr>

        <h2>Sync Status</h2>
        <?php
        $last_sync = get_option('supabase_last_sync');
        $last_user_sync = get_option('supabase_last_user_sync');
        $recent_errors = get_option('supabase_recent_errors', []);
        ?>

        <table class="form-table">
            <tr>
                <th>Last Data Sync:</th>
                <td><?php echo $last_sync ? esc_html($last_sync) : 'Never'; ?></td>
            </tr>
            <tr>
                <th>Last User Sync:</th>
                <td><?php echo $last_user_sync ? esc_html($last_user_sync) : 'Never'; ?></td>
            </tr>
        </table>

        <?php if (!empty($recent_errors)): ?>
            <h3>Recent Errors</h3>
            <ul>
                <?php foreach (array_reverse($recent_errors) as $error): ?>
                    <li>
                        <strong><?php echo esc_html($error['time']); ?>:</strong>
                        <?php echo esc_html($error['message']); ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php
    }

    /**
     * Render Tables Tab
     */
    private function render_tables_tab() {
        $tables = get_option('supabase_schema_tables', []);
        $last_sync = get_option('supabase_schema_last_sync', false);
        $library_table = get_option('supabase_library_table', '');

        // Filter out system tables like wp_users
        $tables = array_filter($tables, function($table) {
            return $table['table_name'] !== 'wp_users';
        });

        ?>
        <?php if (!$this->supabase->is_configured()): ?>
            <div class="notice notice-error">
                <p>Supabase is not configured. Please configure your Supabase credentials in the <a href="?page=supabase-armember&tab=settings">Settings tab</a>.</p>
            </div>
        <?php else: ?>

            <div class="supabase-sync-header">
                <div class="sync-info">
                    <?php if ($last_sync): ?>
                        <p>Last synced: <strong><?php echo esc_html($last_sync); ?></strong></p>
                    <?php else: ?>
                        <p>Never synced. Click the button to sync tables from Supabase.</p>
                    <?php endif; ?>
                </div>
                <button type="button" class="button button-primary" id="sync-tables-btn">
                    <span class="dashicons dashicons-update"></span> Sync Tables from Supabase
                </button>
            </div>

            <div id="sync-status" class="notice" style="display:none;"></div>

            <?php if (empty($tables)): ?>
                <div class="notice notice-info">
                    <p>No tables found. Click "Sync Tables" to fetch available tables from Supabase.</p>
                </div>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Table Name</th>
                            <th>Rows</th>
                            <th>Columns</th>
                            <th>Locked</th>
                            <th>Library</th>
                            <th>PDF Column</th>
                            <th>Page Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tables as $table): ?>
                            <?php
                            $page = $this->get_table_page($table['table_name']);
                            $page_exists = $page !== null;
                            $is_library = ($library_table === $table['table_name']);
                            $pdf_column = $table['pdf_column'] ?? '';
                            ?>
                            <tr data-table="<?php echo esc_attr($table['table_name']); ?>">
                                <td><strong><?php echo esc_html($table['table_name']); ?></strong></td>
                                <td><?php echo number_format($table['row_count']); ?></td>
                                <td><?php echo count($table['columns']); ?></td>
                                <td class="locked-checkbox-cell">
                                    <label>
                                        <input type="checkbox"
                                               class="table-lock-checkbox"
                                               data-table="<?php echo esc_attr($table['table_name']); ?>"
                                               <?php checked($table['is_locked'] ?? true, true); ?> />
                                    </label>
                                </td>
                                <td class="library-designation-cell">
                                    <?php if ($is_library): ?>
                                        <span class="library-badge" title="This is the library table">
                                            <span class="dashicons dashicons-book" style="color: #2271b1;"></span>
                                            <strong>Library</strong>
                                        </span>
                                        <button type="button"
                                                class="button button-small set-library-btn"
                                                data-table="<?php echo esc_attr($table['table_name']); ?>"
                                                data-action="remove">
                                            Remove
                                        </button>
                                    <?php else: ?>
                                        <button type="button"
                                                class="button button-small set-library-btn"
                                                data-table="<?php echo esc_attr($table['table_name']); ?>"
                                                data-action="set">
                                            Set as Library
                                        </button>
                                    <?php endif; ?>
                                </td>
                                <td class="pdf-column-cell">
                                    <select class="pdf-column-select" 
                                            data-table="<?php echo esc_attr($table['table_name']); ?>"
                                            style="min-width: 150px;">
                                        <option value="">-- None --</option>
                                        <?php foreach ($table['columns'] as $column): ?>
                                            <option value="<?php echo esc_attr($column['column_name']); ?>"
                                                    <?php selected($pdf_column, $column['column_name']); ?>>
                                                <?php echo esc_html($column['column_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <?php if ($page_exists): ?>
                                        <span class="dashicons dashicons-yes-alt" style="color: green;"></span>
                                        <a href="<?php echo get_permalink($page->ID); ?>" target="_blank">View Page</a>
                                    <?php else: ?>
                                        <span class="dashicons dashicons-minus" style="color: gray;"></span>
                                        No page
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($page_exists): ?>
                                        <a href="<?php echo get_edit_post_link($page->ID); ?>" class="button button-small">Edit Page</a>
                                        <button type="button" class="button button-small button-link-delete delete-page-btn" data-table="<?php echo esc_attr($table['table_name']); ?>" data-page-id="<?php echo esc_attr($page->ID); ?>">
                                            Delete Page
                                        </button>
                                    <?php else: ?>
                                        <button type="button" class="button button-small create-page-btn" data-table="<?php echo esc_attr($table['table_name']); ?>">
                                            Create Page
                                        </button>
                                    <?php endif; ?>
                                    <button type="button" class="button button-small view-columns-btn" data-table="<?php echo esc_attr($table['table_name']); ?>">
                                        View Columns
                                    </button>
                                </td>
                            </tr>
                            <tr class="column-details" id="columns-<?php echo esc_attr($table['table_name']); ?>" style="display:none;">
                                <td colspan="8">
                                    <div class="columns-list">
                                        <h4>Columns for <?php echo esc_html($table['table_name']); ?>:</h4>
                                        <ul>
                                            <?php foreach ($table['columns'] as $column): ?>
                                                <li>
                                                    <strong><?php echo esc_html($column['column_name']); ?></strong>
                                                    - <?php echo esc_html($column['data_type']); ?>
                                                    <?php if ($column['is_nullable'] === 'YES'): ?>
                                                        <span style="color: gray;">(nullable)</span>
                                                    <?php endif; ?>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- General Search Settings Card -->
                <div class="general-search-settings-card" style="margin-top: 30px; padding: 20px; background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                    <h3 style="margin-top: 0;">
                        <span class="dashicons dashicons-search" style="margin-right: 5px;"></span>
                        General Search Settings
                    </h3>
                    <p class="description">
                        Select which databases appear as options in the General Search (<code>[supabase_multi_search]</code> shortcode).
                        Unchecked databases will not be available for searching.
                    </p>

                    <div class="general-search-checkboxes" style="margin-top: 15px; display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 10px;">
                        <?php foreach ($tables as $table): ?>
                            <?php
                            $is_searchable = $table['is_searchable'] ?? true; // Default to searchable
                            ?>
                            <label style="display: flex; align-items: center; padding: 8px 12px; background: #f9f9f9; border: 1px solid #e5e5e5; border-radius: 4px; cursor: pointer;">
                                <input type="checkbox"
                                       class="table-searchable-checkbox"
                                       data-table="<?php echo esc_attr($table['table_name']); ?>"
                                       <?php checked($is_searchable, true); ?>
                                       style="margin-right: 10px;" />
                                <span>
                                    <strong><?php echo esc_html(ucwords(str_replace('_', ' ', $table['table_name']))); ?></strong>
                                    <span style="color: #666; font-size: 12px; display: block;">
                                        <?php echo number_format($table['row_count']); ?> records
                                    </span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <p class="description" style="margin-top: 15px;">
                        <strong>Note:</strong> Changes are saved automatically when you check or uncheck a database.
                    </p>
                </div>

                <!-- General Search Field Visibility Card -->
                <?php
                $first_table = reset($tables);
                $default_table_name = (is_array($first_table) && isset($first_table['table_name'])) ? $first_table['table_name'] : '';
                ?>
                <div class="search-fields-settings-card" style="margin-top: 20px; padding: 20px; background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                    <h3 style="margin-top: 0;">
                        <span class="dashicons dashicons-visibility" style="margin-right: 5px;"></span>
                        General Search Field Visibility
                    </h3>
                    <p class="description">
                        Choose which fields are shown (and searched) for each database in the General Search
                        (<code>[supabase_multi_search]</code> shortcode).
                    </p>

                    <div class="search-fields-table-selector" style="margin-top: 15px;">
                        <label for="search-fields-table-select"><strong>Select database:</strong></label>
                        <select id="search-fields-table-select" style="min-width: 260px; margin-left: 10px;">
                            <?php foreach ($tables as $table): ?>
                                <option value="<?php echo esc_attr($table['table_name']); ?>"
                                        <?php selected($table['table_name'], $default_table_name); ?>>
                                    <?php echo esc_html(ucwords(str_replace('_', ' ', $table['table_name']))); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="search-fields-lists-wrapper" style="margin-top: 15px;">
                        <?php foreach ($tables as $table): ?>
                            <?php
                            $table_name = $table['table_name'];
                            $selected_search_fields = $this->get_table_search_fields($table);
                            $is_active_table = ($table_name === $default_table_name);
                            ?>
                            <div class="table-search-fields-list"
                                 data-table="<?php echo esc_attr($table_name); ?>"
                                 style="<?php echo $is_active_table ? '' : 'display:none;'; ?>">
                                <div class="search-fields-selection-count" style="margin-bottom: 10px; color: #666;">
                                    <?php echo count($selected_search_fields); ?> of <?php echo count($table['columns']); ?> fields selected
                                </div>
                                <div class="search-fields-checkboxes" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 8px;">
                                    <?php foreach ($table['columns'] as $column): ?>
                                        <?php
                                        $column_name = $column['column_name'] ?? '';
                                        if ($column_name === '') {
                                            continue;
                                        }
                                        ?>
                                        <label style="display: flex; align-items: flex-start; gap: 8px; padding: 8px 10px; background: #f9f9f9; border: 1px solid #e5e5e5; border-radius: 4px; cursor: pointer;">
                                            <input type="checkbox"
                                                   class="table-search-field-checkbox"
                                                   data-table="<?php echo esc_attr($table_name); ?>"
                                                   value="<?php echo esc_attr($column_name); ?>"
                                                   <?php checked(in_array($column_name, $selected_search_fields, true)); ?> />
                                            <span>
                                                <strong><?php echo esc_html($column_name); ?></strong>
                                                <span style="color: #666; font-size: 12px; display: block;">
                                                    <?php echo esc_html($column['data_type'] ?? 'unknown type'); ?>
                                                </span>
                                            </span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <p class="description" style="margin-top: 15px;">
                        <strong>Note:</strong> Changes are saved automatically when you check or uncheck fields. At least one field must remain selected.
                    </p>
                </div>

            <?php endif; ?>

        <?php endif; ?>
        <?php
    }

    /**
     * Render Library Tab
     */
    private function render_library_tab() {
        // Handle form submission
        if (isset($_POST['submit_library_settings'])) {
            check_admin_referer('supabase_library_settings');

            // Save geographic areas
            $geographic_areas = isset($_POST['geographic_areas']) ? sanitize_textarea_field($_POST['geographic_areas']) : '';
            $areas_array = array_filter(array_map('trim', explode("\n", $geographic_areas)));
            update_option('supabase_library_geographic_areas', $areas_array);

            echo '<div class="notice notice-success"><p>Library settings saved successfully.</p></div>';
        }

        $library_manager = new Supabase_Library_Manager();
        $library_table = $library_manager->get_library_table();
        $library_table_info = $library_manager->get_library_table_info();
        $geographic_areas = $library_manager->get_geographic_areas();

        ?>
        <div class="library-settings-page">
            <h2>Library Catalog Settings</h2>

            <?php if (!$library_table): ?>
                <div class="notice notice-warning">
                    <p>No library table has been designated. Please go to the <a href="?page=supabase-armember&tab=tables">Tables tab</a> to designate a table as the library.</p>
                </div>
            <?php else: ?>
                <div class="notice notice-info">
                    <p>
                        <strong>Current Library Table:</strong> <?php echo esc_html($library_table); ?>
                        <?php if ($library_table_info): ?>
                            (<?php echo number_format($library_table_info['row_count']); ?> items)
                        <?php endif; ?>
                    </p>
                    <p><strong>Shortcode:</strong> <code>[supabase_library_catalog]</code></p>
                    <p>Use this shortcode on any page to display the library catalog with search functionality.</p>
                </div>

                <?php
                $validation = $library_manager->validate_library_table();
                if (!$validation['valid']):
                ?>
                    <div class="notice notice-error">
                        <p><strong>Library Table Validation Error:</strong> <?php echo esc_html($validation['error']); ?></p>
                        <p>The library table is missing required fields. Please ensure your Supabase table has columns for: title, author.</p>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <hr>

            <form method="post" action="">
                <?php wp_nonce_field('supabase_library_settings'); ?>

                <h3>Physical Locations</h3>
                <p>Configure the physical locations that appear in the library search dropdown. These values should match the "Physical Location" column in your database. Enter one location per line.</p>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="geographic_areas">Physical Locations</label>
                        </th>
                        <td>
                            <textarea id="geographic_areas"
                                      name="geographic_areas"
                                      rows="10"
                                      class="large-text code"><?php echo esc_textarea(implode("\n", $geographic_areas)); ?></textarea>
                            <p class="description">Enter one physical location per line (e.g., Main Library, Branch A, Storage Room B). These values will be used to filter the "Physical Location" column in the database.</p>
                        </td>
                    </tr>
                </table>

                <?php submit_button('Save Library Settings', 'primary', 'submit_library_settings'); ?>
            </form>

            <hr>

            <h3>Library Field Mappings</h3>
            <p>The library catalog expects the following fields in your Supabase table:</p>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Field Name</th>
                        <th>Description</th>
                        <th>Required</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>title</code></td>
                        <td>Book/item title</td>
                        <td><span class="dashicons dashicons-yes" style="color: green;"></span> Required</td>
                    </tr>
                    <tr>
                        <td><code>author</code></td>
                        <td>Author name(s)</td>
                        <td><span class="dashicons dashicons-yes" style="color: green;"></span> Required</td>
                    </tr>
                    <tr>
                        <td><code>description</code></td>
                        <td>Item description</td>
                        <td>Optional</td>
                    </tr>
                    <tr>
                        <td><code>publisher</code></td>
                        <td>Publisher name</td>
                        <td>Optional</td>
                    </tr>
                    <tr>
                        <td><code>publisher_location</code></td>
                        <td>Publisher location</td>
                        <td>Optional</td>
                    </tr>
                    <tr>
                        <td><code>publication_date</code></td>
                        <td>Date of publication</td>
                        <td>Optional</td>
                    </tr>
                    <tr>
                        <td><code>reprint_date</code></td>
                        <td>Reprint date</td>
                        <td>Optional</td>
                    </tr>
                    <tr>
                        <td><code>isbn</code></td>
                        <td>ISBN number</td>
                        <td>Optional</td>
                    </tr>
                    <tr>
                        <td><code>call_number</code></td>
                        <td>Library call number</td>
                        <td>Optional</td>
                    </tr>
                    <tr>
                        <td><code>spl_collection</code></td>
                        <td>SPL Collection identifier</td>
                        <td>Optional</td>
                    </tr>
                    <tr>
                        <td><code>link_url</code></td>
                        <td>External link URL</td>
                        <td>Optional</td>
                    </tr>
                    <tr>
                        <td><code>acquisition_date</code></td>
                        <td>Date item was acquired</td>
                        <td>Optional</td>
                    </tr>
                    <tr>
                        <td><code>donor_or_purchase</code></td>
                        <td>Donor name or purchase info</td>
                        <td>Optional</td>
                    </tr>
                    <tr>
                        <td><code>librarian_notes</code></td>
                        <td>Internal librarian notes</td>
                        <td>Optional</td>
                    </tr>
                    <tr>
                        <td><code>keyword</code></td>
                        <td>Keywords for searching</td>
                        <td>Optional</td>
                    </tr>
                    <tr>
                        <td><code>Physical Location</code></td>
                        <td>Physical location of the item</td>
                        <td>Optional</td>
                    </tr>
                    <tr>
                        <td><code>new</code></td>
                        <td>Boolean flag for new items</td>
                        <td>Optional</td>
                    </tr>
                    <tr>
                        <td><code>updated</code></td>
                        <td>Last update timestamp</td>
                        <td>Optional</td>
                    </tr>
                    <tr>
                        <td><code>updated_by</code></td>
                        <td>User who last updated</td>
                        <td>Optional</td>
                    </tr>
                </tbody>
            </table>

            <p class="description">
                <strong>Note:</strong> If your Supabase table uses different column names, the data will not display correctly.
                Future versions may support custom field mapping.
            </p>
        </div>
        <?php
    }

    /**
     * Render Librarians Tab
     * Manage users who have access to the Librarian CRUD interface
     */
    private function render_librarians_tab() {
        // Handle form submission
        if (isset($_POST['submit_librarians'])) {
            check_admin_referer('supabase_librarians_settings');

            // Save librarian emails
            $librarian_emails = isset($_POST['librarian_emails']) ? sanitize_textarea_field($_POST['librarian_emails']) : '';
            $emails_array = $this->parse_librarian_emails($librarian_emails);
            update_option('supabase_librarian_emails', $emails_array);

            echo '<div class="notice notice-success"><p>Librarian settings saved successfully.</p></div>';
        }

        // Get current librarians
        $librarian_emails = get_option('supabase_librarian_emails', []);
        $emails_text = implode("\n", $librarian_emails);

        // Get info about current librarians
        $librarian_users = $this->get_librarian_user_info($librarian_emails);

        ?>
        <div class="librarians-settings-page">
            <h2>Librarian Access Management</h2>

            <p>Manage which users have access to the Librarian interface (<code>[supabase_librarian]</code> shortcode).</p>
            <p>Librarians can add, edit, and delete library records. <strong>Administrators always have access.</strong></p>

            <hr>

            <form method="post" action="">
                <?php wp_nonce_field('supabase_librarians_settings'); ?>

                <h3>Authorized Librarians</h3>
                <p>Enter the email addresses of users who should have librarian access. One email per line.</p>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="librarian_emails">Librarian Email Addresses</label>
                        </th>
                        <td>
                            <textarea id="librarian_emails"
                                      name="librarian_emails"
                                      rows="10"
                                      class="large-text code"
                                      placeholder="librarian1@example.com&#10;librarian2@example.com"><?php echo esc_textarea($emails_text); ?></textarea>
                            <p class="description">
                                Enter one email address per line. Users must have a WordPress account with this email to access the librarian interface.
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button('Save Librarian Settings', 'primary', 'submit_librarians'); ?>
            </form>

            <hr>

            <h3>Current Librarians</h3>
            <?php if (empty($librarian_emails)): ?>
                <p><em>No librarians configured. Only administrators can access the librarian interface.</em></p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Email</th>
                            <th>User Status</th>
                            <th>Display Name</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($librarian_users as $info): ?>
                            <tr>
                                <td><?php echo esc_html($info['email']); ?></td>
                                <td>
                                    <?php if ($info['exists']): ?>
                                        <span style="color: green;">✓ Valid WordPress User</span>
                                    <?php else: ?>
                                        <span style="color: #b32d2e;">✗ No WordPress Account</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($info['display_name'] ?: '—'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="description">
                    <strong>Note:</strong> Users marked "No WordPress Account" will not be able to access the librarian interface until they create an account with that email.
                </p>
            <?php endif; ?>

            <hr>

            <h3>Access Information</h3>
            <table class="form-table">
                <tr>
                    <th>Librarian Shortcode:</th>
                    <td><code>[supabase_librarian]</code></td>
                </tr>
                <tr>
                    <th>Who Can Access:</th>
                    <td>
                        <ul style="margin: 0; padding-left: 20px;">
                            <li>All WordPress Administrators (automatically)</li>
                            <li>Users whose email is listed above</li>
                        </ul>
                    </td>
                </tr>
                <tr>
                    <th>Current Admins:</th>
                    <td>
                        <?php
                        $admins = get_users(['role' => 'administrator', 'fields' => ['display_name', 'user_email']]);
                        foreach ($admins as $admin) {
                            echo esc_html($admin->display_name) . ' (' . esc_html($admin->user_email) . ')<br>';
                        }
                        ?>
                    </td>
                </tr>
            </table>
        </div>
        <?php
    }

    /**
     * Render Sync Logs Tab
     * Display ARMember sync logs from database
     */
    private function render_logs_tab() {
        // Handle clear logs action
        if (isset($_POST['clear_logs']) && check_admin_referer('supabase_clear_logs')) {
            delete_option('supabase_sync_logs');
            echo '<div class="notice notice-success"><p>Sync logs cleared successfully.</p></div>';
        }

        // Get logs from database
        $all_logs = get_option('supabase_sync_logs', []);
        
        // Get the most recent entries for display (limit to 100 for performance)
        $max_display_logs = 100;
        $logs = array_slice($all_logs, 0, $max_display_logs);

        ?>
        <div class="sync-logs-page">
            <h2>ARMember Sync Logs</h2>
            
            <p>View recent ARMember synchronization activity. Logs help diagnose issues with plan name detection and user syncing.</p>

            <?php if (empty($logs)): ?>
                <div class="notice notice-info">
                    <p><strong>No sync logs found.</strong> This could mean:</p>
                    <ul>
                        <li>No users have been synced yet</li>
                        <li>Logs have been cleared</li>
                    </ul>
                    <p>Try syncing a user (go to Settings tab and click "Sync All Users to Supabase") to generate log entries.</p>
                </div>
            <?php else: ?>
                <div class="sync-logs-container">
                    <div class="sync-logs-header">
                        <p><strong><?php echo count($logs); ?></strong> recent sync log entries (showing most recent 100)</p>
                        <p class="description">Logs are stored in the WordPress database and automatically managed.</p>
                        <form method="post" action="" style="margin-top: 10px;">
                            <?php wp_nonce_field('supabase_clear_logs'); ?>
                            <button type="submit" name="clear_logs" class="button button-secondary" onclick="return confirm('Are you sure you want to clear all sync logs?');">
                                Clear All Logs
                            </button>
                        </form>
                    </div>

                    <div class="sync-logs-content">
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th style="width: 180px;">Timestamp</th>
                                    <th>Log Entry</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($logs as $log_entry): ?>
                                    <?php
                                    // Extract timestamp and message from log entry array
                                    $timestamp = isset($log_entry['timestamp']) ? $log_entry['timestamp'] : '';
                                    $message = isset($log_entry['message']) ? $log_entry['message'] : '';
                                    
                                    // Remove [ARMember Sync] prefix if present for cleaner display
                                    $message = str_replace('[ARMember Sync] ', '', $message);
                                    
                                    // Highlight different types of messages
                                    $row_class = '';
                                    if (strpos($message, 'Could not find plan name') !== false || strpos($message, 'Unknown') !== false) {
                                        $row_class = 'warning';
                                    } elseif (strpos($message, 'Found plan name') !== false) {
                                        $row_class = 'success';
                                    } elseif (strpos($message, 'Error') !== false || strpos($message, 'Failed') !== false) {
                                        $row_class = 'error';
                                    }
                                    ?>
                                    <tr class="<?php echo esc_attr($row_class); ?>">
                                        <td>
                                            <code style="font-size: 11px;"><?php echo esc_html($timestamp); ?></code>
                                        </td>
                                        <td>
                                            <code style="background: transparent; padding: 0; font-size: 12px;"><?php echo esc_html($message); ?></code>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <style>
                    .sync-logs-content .warning {
                        background-color: #fff8e5;
                    }
                    .sync-logs-content .success {
                        background-color: #f0f9ff;
                    }
                    .sync-logs-content .error {
                        background-color: #fef2f2;
                    }
                    .sync-logs-container {
                        margin-top: 20px;
                    }
                    .sync-logs-header {
                        margin-bottom: 15px;
                    }
                </style>
            <?php endif; ?>

            <hr>

            <h3>Understanding the Logs</h3>
            <table class="form-table">
                <tr>
                    <th>Log Entry Type</th>
                    <th>What It Means</th>
                </tr>
                <tr>
                    <td><code>User ID: X, Plan IDs: [...]</code></td>
                    <td>Shows which user is being synced and their plan IDs from ARMember</td>
                </tr>
                <tr>
                    <td><code>Found plan name "X" for plan ID: Y</code></td>
                    <td>Successfully retrieved the plan name from ARMember</td>
                </tr>
                <tr>
                    <td><code>Could not find plan name for plan IDs: [...]</code></td>
                    <td>Unable to retrieve plan name - this is why plans show as "Unknown"</td>
                </tr>
                <tr>
                    <td><code>Post found - Type: X, Title: Y</code></td>
                    <td>Debug info showing what post was found when looking up plan ID</td>
                </tr>
                <tr>
                    <td><code>No post found for plan ID: X</code></td>
                    <td>The plan ID doesn't correspond to a WordPress post</td>
                </tr>
                <tr>
                    <td><code>Membership Plan: X</code></td>
                    <td>The final plan name that was synced to Supabase</td>
                </tr>
            </table>

            <p class="description">
                <strong>Tip:</strong> If you see "Could not find plan name" entries, check the debug information that follows to understand why. 
                The logs will show what methods were tried and what was found (or not found) when looking up the plan.
            </p>
        </div>
        <?php
    }

    /**
     * Parse librarian emails from textarea input
     */
    private function parse_librarian_emails($input) {
        $lines = explode("\n", $input);
        $emails = [];

        foreach ($lines as $line) {
            $email = trim($line);
            if (!empty($email) && is_email($email)) {
                $emails[] = strtolower($email);
            }
        }

        return array_unique($emails);
    }

    /**
     * Get user info for librarian emails
     */
    private function get_librarian_user_info($emails) {
        $info = [];

        foreach ($emails as $email) {
            $user = get_user_by('email', $email);
            $info[] = [
                'email' => $email,
                'exists' => $user !== false,
                'display_name' => $user ? $user->display_name : '',
                'user_id' => $user ? $user->ID : null
            ];
        }

        return $info;
    }

    /**
     * AJAX handler for syncing all users
     */
    public function ajax_sync_users() {
        check_ajax_referer('supabase_sync_users', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $users = get_users(['fields' => 'ID']);

        if (empty($users)) {
            wp_send_json_error(['message' => 'No users found']);
        }

        $sync_handler = new Supabase_Sync_Handler();
        $synced = 0;
        $failed = 0;

        foreach ($users as $user_id) {
            $result = $sync_handler->sync_user_to_supabase($user_id);
            if ($result) {
                $synced++;
            } else {
                $failed++;
            }
        }

        update_option('supabase_last_user_sync', current_time('mysql'));

        $message = "Synced {$synced} users successfully.";
        if ($failed > 0) {
            $message .= " {$failed} users failed to sync.";
        }

        wp_send_json_success(['message' => $message]);
    }

    /**
     * AJAX handler for syncing tables
     */
    public function ajax_sync_tables() {
        check_ajax_referer('supabase_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $tables = $this->supabase->fetch_schema_tables();

        if ($tables === false) {
            wp_send_json_error(['message' => 'Failed to fetch tables from Supabase. Check your configuration and error logs.']);
        }

        // Preserve lock states, PDF column designations, searchability, and search field selections from existing tables
        $old_tables = get_option('supabase_schema_tables', []);
        $lock_states = [];
        $pdf_columns = [];
        $searchable_states = [];
        $search_fields = [];
        foreach ($old_tables as $old_table) {
            $lock_states[$old_table['table_name']] = $old_table['is_locked'] ?? true;
            $searchable_states[$old_table['table_name']] = $old_table['is_searchable'] ?? true;
            if (isset($old_table['pdf_column'])) {
                $pdf_columns[$old_table['table_name']] = $old_table['pdf_column'];
            }
            if (isset($old_table['search_fields']) && is_array($old_table['search_fields'])) {
                $search_fields[$old_table['table_name']] = $old_table['search_fields'];
            }
        }

        // Apply old lock states, searchability, PDF column designations, and search fields to synced tables
        // New tables default to locked and searchable
        foreach ($tables as &$table) {
            $table['is_locked'] = $lock_states[$table['table_name']] ?? true;
            $table['is_searchable'] = $searchable_states[$table['table_name']] ?? true;
            if (isset($pdf_columns[$table['table_name']])) {
                $table['pdf_column'] = $pdf_columns[$table['table_name']];
            }
            if (isset($search_fields[$table['table_name']])) {
                $available_columns = $this->get_table_column_names($table);
                $valid_search_fields = $this->sanitize_table_search_fields($search_fields[$table['table_name']], $available_columns);
                if (!empty($valid_search_fields)) {
                    $table['search_fields'] = $valid_search_fields;
                }
            }
        }

        update_option('supabase_schema_tables', $tables);
        update_option('supabase_schema_last_sync', current_time('mysql'));

        wp_send_json_success([
            'message' => 'Successfully synced ' . count($tables) . ' tables from Supabase.',
            'tables' => $tables
        ]);
    }

    /**
     * AJAX handler for creating table page
     */
    public function ajax_create_table_page() {
        check_ajax_referer('supabase_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $table_name = isset($_POST['table']) ? sanitize_text_field($_POST['table']) : '';

        if (empty($table_name)) {
            wp_send_json_error(['message' => 'Table name is required']);
        }

        $existing_page = $this->get_table_page($table_name);
        if ($existing_page) {
            wp_send_json_error(['message' => 'Page already exists for this table']);
        }

        $parent_id = $this->get_or_create_databases_page();

        if (!$parent_id) {
            wp_send_json_error(['message' => 'Failed to create or find parent page']);
        }

        $page_id = wp_insert_post([
            'post_title' => ucwords(str_replace('_', ' ', $table_name)),
            'post_content' => '[supabase_table table="' . $table_name . '"]',
            'post_status' => 'draft',
            'post_type' => 'page',
            'post_parent' => $parent_id,
            'post_name' => $table_name
        ]);

        if (is_wp_error($page_id)) {
            wp_send_json_error(['message' => 'Failed to create page: ' . $page_id->get_error_message()]);
        }

        $this->add_table_to_master_list($table_name, $page_id);

        wp_send_json_success([
            'message' => 'Page created successfully',
            'page_id' => $page_id,
            'edit_url' => get_edit_post_link($page_id, 'raw'),
            'view_url' => get_permalink($page_id)
        ]);
    }

    /**
     * AJAX handler for deleting table page
     */
    public function ajax_delete_table_page() {
        check_ajax_referer('supabase_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $table_name = isset($_POST['table']) ? sanitize_text_field($_POST['table']) : '';
        $page_id = isset($_POST['page_id']) ? intval($_POST['page_id']) : 0;

        if (empty($table_name) || empty($page_id)) {
            wp_send_json_error(['message' => 'Table name and page ID are required']);
        }

        // Verify the page exists
        $page = get_post($page_id);
        if (!$page) {
            wp_send_json_error(['message' => 'Page not found']);
        }

        // Verify it's actually a page (not another post type)
        if ($page->post_type !== 'page') {
            wp_send_json_error(['message' => 'Invalid post type']);
        }

        // Additional safety: verify the page contains the table shortcode
        if (strpos($page->post_content, '[supabase_table table="' . $table_name . '"]') === false) {
            wp_send_json_error(['message' => 'Page does not appear to be associated with this table']);
        }

        // Delete the page (moves to trash by default)
        $result = wp_trash_post($page_id);

        if (!$result) {
            wp_send_json_error(['message' => 'Failed to delete page']);
        }

        // Remove from master databases list
        $this->remove_table_from_master_list($table_name, $page_id);

        wp_send_json_success([
            'message' => 'Page deleted successfully',
            'table' => $table_name
        ]);
    }

    /**
     * AJAX handler for toggling table lock status
     */
    public function ajax_toggle_table_lock() {
        check_ajax_referer('supabase_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $table_name = isset($_POST['table']) ? sanitize_text_field($_POST['table']) : '';
        $is_locked = isset($_POST['is_locked']) ? (bool)$_POST['is_locked'] : true;

        if (empty($table_name)) {
            wp_send_json_error(['message' => 'Table name required']);
        }

        $tables = get_option('supabase_schema_tables', []);
        $found = false;

        foreach ($tables as &$table) {
            if ($table['table_name'] === $table_name) {
                $table['is_locked'] = $is_locked;
                $found = true;
                break;
            }
        }

        if (!$found) {
            wp_send_json_error(['message' => 'Table not found']);
        }

        update_option('supabase_schema_tables', $tables);

        wp_send_json_success([
            'message' => 'Lock status updated',
            'table' => $table_name,
            'is_locked' => $is_locked
        ]);
    }

    /**
     * AJAX handler for setting PDF column designation
     */
    public function ajax_set_pdf_column() {
        check_ajax_referer('supabase_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $table_name = isset($_POST['table']) ? sanitize_text_field($_POST['table']) : '';
        $pdf_column = isset($_POST['pdf_column']) ? sanitize_text_field($_POST['pdf_column']) : '';

        if (empty($table_name)) {
            wp_send_json_error(['message' => 'Table name required']);
        }

        $tables = get_option('supabase_schema_tables', []);
        $found = false;

        foreach ($tables as &$table) {
            if ($table['table_name'] === $table_name) {
                // Validate that the column exists in this table
                if (!empty($pdf_column)) {
                    $column_exists = false;
                    foreach ($table['columns'] as $column) {
                        if ($column['column_name'] === $pdf_column) {
                            $column_exists = true;
                            break;
                        }
                    }
                    if (!$column_exists) {
                        wp_send_json_error(['message' => 'Column not found in table']);
                    }
                }
                // Set or unset the pdf_column
                if (!empty($pdf_column)) {
                    $table['pdf_column'] = $pdf_column;
                } else {
                    // Remove the pdf_column designation if empty
                    unset($table['pdf_column']);
                }
                $found = true;
                break;
            }
        }

        if (!$found) {
            wp_send_json_error(['message' => 'Table not found']);
        }

        $result = update_option('supabase_schema_tables', $tables);
        
        // Log for debugging (remove in production if needed)
        if ($result === false) {
            error_log('Failed to update supabase_schema_tables option');
        }

        wp_send_json_success([
            'message' => 'PDF column designation updated',
            'table' => $table_name,
            'pdf_column' => $pdf_column
        ]);
    }

    /**
     * AJAX handler for setting searchable/displayed fields per table (General Search)
     */
    public function ajax_set_table_search_fields() {
        check_ajax_referer('supabase_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $table_name = isset($_POST['table']) ? sanitize_text_field($_POST['table']) : '';
        $submitted_fields = isset($_POST['search_fields']) ? (array) $_POST['search_fields'] : [];

        if (empty($table_name)) {
            wp_send_json_error(['message' => 'Table name required']);
        }

        $tables = get_option('supabase_schema_tables', []);
        $found = false;

        foreach ($tables as &$table) {
            if ($table['table_name'] === $table_name) {
                $available_columns = $this->get_table_column_names($table);
                $valid_fields = $this->sanitize_table_search_fields($submitted_fields, $available_columns);

                if (empty($valid_fields)) {
                    wp_send_json_error(['message' => 'At least one field must be selected']);
                }

                $table['search_fields'] = $valid_fields;
                $found = true;
                break;
            }
        }

        if (!$found) {
            wp_send_json_error(['message' => 'Table not found']);
        }

        update_option('supabase_schema_tables', $tables);

        wp_send_json_success([
            'message' => 'Search fields updated',
            'table' => $table_name,
            'search_fields' => $valid_fields
        ]);
    }

    /**
     * Get column names for a table definition.
     */
    private function get_table_column_names($table) {
        if (!isset($table['columns']) || !is_array($table['columns'])) {
            return [];
        }

        $columns = [];
        foreach ($table['columns'] as $column) {
            if (isset($column['column_name']) && $column['column_name'] !== '') {
                $columns[] = $column['column_name'];
            }
        }

        return $columns;
    }

    /**
     * Sanitize and validate selected search fields against available columns.
     */
    private function sanitize_table_search_fields($search_fields, $available_columns) {
        if (!is_array($search_fields) || empty($available_columns)) {
            return [];
        }

        $available_lookup = array_fill_keys($available_columns, true);
        $sanitized_fields = [];

        foreach ($search_fields as $field) {
            if (!is_scalar($field)) {
                continue;
            }

            $field_name = sanitize_text_field((string) $field);
            if ($field_name === '') {
                continue;
            }

            if (isset($available_lookup[$field_name]) && !in_array($field_name, $sanitized_fields, true)) {
                $sanitized_fields[] = $field_name;
            }
        }

        return $sanitized_fields;
    }

    /**
     * Get selected search fields for a table, defaulting to all columns.
     */
    private function get_table_search_fields($table) {
        $available_columns = $this->get_table_column_names($table);
        if (empty($available_columns)) {
            return [];
        }

        $selected_fields = isset($table['search_fields']) ? $table['search_fields'] : [];
        $valid_selected_fields = $this->sanitize_table_search_fields($selected_fields, $available_columns);

        if (!empty($valid_selected_fields)) {
            return $valid_selected_fields;
        }

        return $available_columns;
    }

    /**
     * Get existing page for a table
     */
    private function get_table_page($table_name) {
        $pages = get_posts([
            'post_type' => 'page',
            'post_status' => 'any',
            'posts_per_page' => 1,
            'name' => $table_name
        ]);

        return !empty($pages) ? $pages[0] : null;
    }

    /**
     * Get or create the /databases/ parent page
     */
    private function get_or_create_databases_page() {
        $page = get_page_by_path('databases');

        // Content with multi-search shortcode at the top
        $content = '[supabase_multi_search]' . "\n\n" .
                   '<hr />' . "\n\n" .
                   '<h2>Browse Individual Databases</h2>' . "\n" .
                   '<p>Click on a database below to view its complete records:</p>';

        if ($page) {
            // Update existing page to include shortcode if not already present, and update title
            $update_data = [];
            if (strpos($page->post_content, '[supabase_multi_search]') === false) {
                $update_data['post_content'] = $content;
            }
            if ($page->post_title !== 'Databases Lookup') {
                $update_data['post_title'] = 'Databases Lookup';
            }
            if (!empty($update_data)) {
                $update_data['ID'] = $page->ID;
                wp_update_post($update_data);
            }
            return $page->ID;
        }

        // Create new page with shortcode
        $page_id = wp_insert_post([
            'post_title' => 'Databases Lookup',
            'post_content' => $content,
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_name' => 'databases'
        ]);

        if (is_wp_error($page_id)) {
            return false;
        }

        return $page_id;
    }

    /**
     * Add table to master list page
     */
    private function add_table_to_master_list($table_name, $page_id) {
        $parent_page = get_page_by_path('databases');

        if (!$parent_page) {
            return;
        }

        $content = $parent_page->post_content;
        $page_url = get_permalink($page_id);
        $table_display_name = ucwords(str_replace('_', ' ', $table_name));

        $new_link = '<li><a href="' . $page_url . '">' . $table_display_name . '</a></li>';

        if (strpos($content, '<ul>') !== false) {
            $content = str_replace('</ul>', $new_link . '</ul>', $content);
        } else {
            $content .= "\n<ul>\n" . $new_link . "\n</ul>";
        }

        wp_update_post([
            'ID' => $parent_page->ID,
            'post_content' => $content
        ]);
    }

    /**
     * Remove table from master list page
     */
    private function remove_table_from_master_list($table_name, $page_id) {
        $parent_page = get_page_by_path('databases');

        if (!$parent_page) {
            return;
        }

        $content = $parent_page->post_content;
        $table_display_name = ucwords(str_replace('_', ' ', $table_name));

        // Use regex to match the link regardless of URL (more robust)
        // This will match: <li><a href="ANY_URL">Table Display Name</a></li>
        $pattern = '/<li>\s*<a[^>]+>' . preg_quote($table_display_name, '/') . '<\/a>\s*<\/li>\s*/i';
        $content = preg_replace($pattern, '', $content);

        // Also try to match by table name slug in URL (backup method)
        $pattern_by_slug = '/<li>\s*<a[^>]+\/' . preg_quote($table_name, '/') . '[\/"\?][^>]*>[^<]*<\/a>\s*<\/li>\s*/i';
        $content = preg_replace($pattern_by_slug, '', $content);

        // Clean up empty lists
        $content = preg_replace('/<ul>\s*<\/ul>/', '', $content);

        // Clean up multiple consecutive newlines
        $content = preg_replace("/\n{3,}/", "\n\n", $content);

        wp_update_post([
            'ID' => $parent_page->ID,
            'post_content' => $content
        ]);
    }

    /**
     * AJAX handler for setting/removing library table designation
     */
    public function ajax_set_library_table() {
        check_ajax_referer('supabase_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $table_name = isset($_POST['table']) ? sanitize_text_field($_POST['table']) : '';
        $action = isset($_POST['action_type']) ? sanitize_text_field($_POST['action_type']) : '';

        if (empty($table_name)) {
            wp_send_json_error(['message' => 'Table name required']);
        }

        if ($action === 'set') {
            // Set this table as the library table
            update_option('supabase_library_table', $table_name);
            wp_send_json_success([
                'message' => 'Library table set successfully',
                'table' => $table_name,
                'is_library' => true
            ]);
        } elseif ($action === 'remove') {
            // Remove library designation
            $current_library = get_option('supabase_library_table', '');
            if ($current_library === $table_name) {
                delete_option('supabase_library_table');
                wp_send_json_success([
                    'message' => 'Library designation removed',
                    'table' => $table_name,
                    'is_library' => false
                ]);
            } else {
                wp_send_json_error(['message' => 'This table is not the library table']);
            }
        } else {
            wp_send_json_error(['message' => 'Invalid action']);
        }
    }

    /**
     * AJAX handler for toggling table searchability (General Search visibility)
     */
    public function ajax_toggle_table_searchable() {
        check_ajax_referer('supabase_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $table_name = isset($_POST['table']) ? sanitize_text_field($_POST['table']) : '';
        $is_searchable = isset($_POST['is_searchable']) ? (bool)$_POST['is_searchable'] : false;

        if (empty($table_name)) {
            wp_send_json_error(['message' => 'Table name required']);
        }

        $tables = get_option('supabase_schema_tables', []);
        $found = false;

        foreach ($tables as &$table) {
            if ($table['table_name'] === $table_name) {
                $table['is_searchable'] = $is_searchable;
                $found = true;
                break;
            }
        }

        if (!$found) {
            wp_send_json_error(['message' => 'Table not found']);
        }

        update_option('supabase_schema_tables', $tables);

        wp_send_json_success([
            'message' => 'Searchability updated',
            'table' => $table_name,
            'is_searchable' => $is_searchable
        ]);
    }
}

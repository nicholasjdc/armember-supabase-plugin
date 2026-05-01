<?php
/**
 * Library Display Class
 * Handles the library catalog shortcode and display
 */

if (!defined('ABSPATH')) {
    exit;
}

class Supabase_Library_Display {

    private $supabase;
    private $library_manager;

    public function __construct() {
        $this->supabase = new Supabase_Client();
        $this->library_manager = new Supabase_Library_Manager();

        // Register shortcode
        add_shortcode('supabase_library_catalog', [$this, 'render_library_catalog']);

        // Register REST API endpoint
        add_action('rest_api_init', [$this, 'register_rest_routes']);

        // Enqueue scripts and styles
        add_action('wp_enqueue_scripts', [$this, 'enqueue_library_assets']);
    }

    /**
     * Register REST API routes for library catalog
     */
    public function register_rest_routes() {
        register_rest_route('supabase/v1', '/library-search', [
            'methods' => 'GET',
            'callback' => [$this, 'handle_library_search'],
            'permission_callback' => [$this, 'check_library_search_permission']
        ]);
    }

    /**
     * Returns a WP_Error if the current user has exceeded 30 requests/minute, null otherwise.
     */
    private function check_rate_limit() {
        if (!is_user_logged_in()) {
            return null;
        }
        $key = 'supabase_rl_' . get_current_user_id();
        $count = (int) get_transient($key);
        if ($count >= 30) {
            return new WP_Error('rate_limited', 'Too many requests. Please wait before searching again.', ['status' => 429]);
        }
        set_transient($key, $count + 1, 60);
        return null;
    }

    /**
     * Permission callback for the library-search REST endpoint.
     * Unlocked tables are publicly accessible; locked tables require a paid membership.
     */
    public function check_library_search_permission() {
        $table_info = $this->library_manager->get_library_table_info();
        $is_locked = $table_info['is_locked'] ?? true;

        if (!$is_locked) {
            return true;
        }

        if (!is_user_logged_in()) {
            return new WP_Error('rest_forbidden', 'Authentication required', ['status' => 401]);
        }

        if (current_user_can('manage_options')) {
            return true;
        }

        if (!(bool) get_user_meta(get_current_user_id(), 'supabase_access', true)) {
            return new WP_Error('rest_forbidden', 'Paid membership required', ['status' => 403]);
        }

        return true;
    }

    /**
     * Enqueue library catalog assets
     */
    public function enqueue_library_assets() {
        if (!is_singular() || !has_shortcode(get_post()->post_content, 'supabase_library_catalog')) {
            return;
        }

        // DataTables
        wp_enqueue_style('datatables', 'https://cdn.datatables.net/1.13.4/css/jquery.dataTables.min.css');
        wp_enqueue_script('datatables', 'https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js', ['jquery'], null, true);

        // DataTables Buttons
        wp_enqueue_style('datatables-buttons', 'https://cdn.datatables.net/buttons/2.3.6/css/buttons.dataTables.min.css');
        wp_enqueue_script('datatables-buttons', 'https://cdn.datatables.net/buttons/2.3.6/js/dataTables.buttons.min.js', ['datatables'], null, true);
        wp_enqueue_script('datatables-buttons-html5', 'https://cdn.datatables.net/buttons/2.3.6/js/buttons.html5.min.js', ['datatables-buttons'], null, true);
        wp_enqueue_script('datatables-buttons-print', 'https://cdn.datatables.net/buttons/2.3.6/js/buttons.print.min.js', ['datatables-buttons'], null, true);

        // JSZip for Excel export
        wp_enqueue_script('jszip', 'https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js', [], null, true);

        // Library catalog CSS - use file modification time for better cache busting
        $css_file_path = SUPABASE_ARMEMBER_PLUGIN_DIR . 'public/css/library-catalog.css';
        $css_version = file_exists($css_file_path) ? filemtime($css_file_path) : SUPABASE_ARMEMBER_VERSION;
        wp_enqueue_style(
            'supabase-library-catalog',
            SUPABASE_ARMEMBER_PLUGIN_URL . 'public/css/library-catalog.css',
            [],
            $css_version
        );

        // Library catalog JS - use file modification time for better cache busting
        $js_file_path = SUPABASE_ARMEMBER_PLUGIN_DIR . 'public/js/library-catalog.js';
        $js_version = file_exists($js_file_path) ? filemtime($js_file_path) : SUPABASE_ARMEMBER_VERSION;
        wp_enqueue_script(
            'supabase-library-catalog',
            SUPABASE_ARMEMBER_PLUGIN_URL . 'public/js/library-catalog.js',
            ['jquery', 'datatables'],
            $js_version,
            true
        );

        wp_localize_script('supabase-library-catalog', 'supabaseLibrary', [
            'apiUrl' => rest_url('supabase/v1/library-search'),
            'nonce' => wp_create_nonce('wp_rest')
        ]);
    }

    /**
     * Render library catalog shortcode
     *
     * @param array $atts Shortcode attributes
     * @return string HTML output
     */
    public function render_library_catalog($atts = []) {
        // Check if library table is configured
        if (!$this->library_manager->has_library_table()) {
            return '<div class="supabase-notice supabase-error">Library table not configured. Please contact the site administrator.</div>';
        }

        // Validate library table
        $validation = $this->library_manager->validate_library_table();
        if (!$validation['valid']) {
            return '<div class="supabase-notice supabase-error">Library table configuration error: ' . esc_html($validation['error']) . '</div>';
        }

        $table_info = $this->library_manager->get_library_table_info();
        $is_locked = $table_info['is_locked'] ?? true;

        // Check access for locked tables
        if ($is_locked) {
            if (!is_user_logged_in()) {
                return '<div class="supabase-notice supabase-info">Please log in to access the library catalog.</div>';
            }

            if (!$this->has_database_access()) {
                return '<div class="supabase-notice supabase-info">You need a paid membership to access the library catalog.</div>';
            }
        }

        $geographic_areas = $this->library_manager->get_geographic_areas();

        ob_start();
        ?>
        <div class="supabase-library-catalog">
            <div class="library-search-form">
                <h2>Search Library Catalog</h2>

                <form id="library-search-form" class="search-form">
                    <div class="search-row">
                        <div class="search-field">
                            <label for="search-title">Title</label>
                            <input type="text" id="search-title" name="title" class="search-input" placeholder="Enter title...">
                        </div>

                        <div class="search-field">
                            <label for="search-author">Author</label>
                            <input type="text" id="search-author" name="author" class="search-input" placeholder="Enter author...">
                        </div>
                    </div>

                    <div class="search-row">
                        <div class="search-field">
                            <label for="search-keyword">Keyword</label>
                            <input type="text" id="search-keyword" name="keyword" class="search-input" placeholder="Enter keyword...">
                        </div>

                        <div class="search-field">
                            <label for="search-physical-location">Physical Location</label>
                            <select id="search-physical-location" name="physical_location" class="search-select">
                                <option value="">All Locations</option>
                                <?php foreach ($geographic_areas as $area): ?>
                                    <option value="<?php echo esc_attr($area); ?>"><?php echo esc_html($area); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="search-row">
                        <div class="search-field checkbox-field">
                            <label>
                                <input type="checkbox" id="search-new" name="new" value="1">
                                New Items Only
                            </label>
                        </div>

                        <div class="search-actions">
                            <button type="submit" class="button button-primary">Search</button>
                            <button type="button" id="clear-search" class="button">Clear</button>
                        </div>
                    </div>
                </form>
            </div>

            <div class="library-results">
                <table id="library-results-table" class="display" style="width:100%">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Author</th>
                            <th>Publisher</th>
                            <th>Publication Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    </tbody>
                </table>
            </div>

            <!-- Item Detail Modal -->
            <div id="item-detail-modal" class="library-modal" style="display:none;">
                <div class="library-modal-content">
                    <div class="library-modal-header">
                        <button type="button" id="print-item-btn" class="button button-secondary print-btn">
                            <span class="dashicons dashicons-printer"></span> Print
                        </button>
                        <span class="library-modal-close">&times;</span>
                    </div>
                    <div id="item-detail-content">
                        <!-- Detail content will be populated by JavaScript -->
                    </div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Handle library search REST API request
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_library_search($request) {
        $rate_limit_error = $this->check_rate_limit();
        if ($rate_limit_error) {
            return $rate_limit_error;
        }

        try {
            if (!$this->library_manager->has_library_table()) {
                return new WP_REST_Response([
                    'error' => 'Library table not configured'
                ], 400);
            }

            $table_info = $this->library_manager->get_library_table_info();

            if (!$table_info) {
                return new WP_REST_Response([
                    'error' => 'Library table information not available'
                ], 400);
            }

            $table_name = $table_info['table_name'];

        // Get search parameters
        $title = $request->get_param('title');
        $author = $request->get_param('author');
        $keyword = $request->get_param('keyword');
        $physical_location = $request->get_param('physical_location');
        $new_only = $request->get_param('new');


        // DataTables parameters
        $draw = intval($request->get_param('draw') ?? 1);
        $start = intval($request->get_param('start') ?? 0);
        $length = intval($request->get_param('length') ?? 10);

        $search_param = $request->get_param('search');
        $search_value = '';
        if (is_array($search_param) && isset($search_param['value'])) {
            $search_value = $search_param['value'];
        }

        // Build query
        $query_params = [];
        $filters = [];

        // Get actual column names from table (case-insensitive)
        $actual_columns = $this->get_actual_column_names($table_info);

        // Add search filters
        if (!empty($title)) {
            $title_col = $this->find_column($actual_columns, 'title');
            if ($title_col) {
                $filters[] = $title_col . '.ilike.*' . $title . '*';
            }
        }

        if (!empty($author)) {
            $author_col = $this->find_column($actual_columns, 'author');
            if ($author_col) {
                $filters[] = $author_col . '.ilike.*' . $author . '*';
            }
        }

        if (!empty($keyword)) {
            // Keyword searches across multiple fields (title, author, description, publisher)
            $keyword_search_columns = ['title', 'author', 'description', 'publisher'];
            $keyword_filters = [];
            
            foreach ($keyword_search_columns as $col_name) {
                $col = $this->find_column($actual_columns, $col_name);
                if ($col) {
                    $keyword_filters[] = $col . '.ilike.*' . $keyword . '*';
                }
            }
            
            if (!empty($keyword_filters)) {
                $filters[] = 'or=(' . implode(',', $keyword_filters) . ')';
            }
        }

        if (!empty($physical_location)) {
            $physical_location_col = $this->find_column($actual_columns, 'Physical Location');
            if ($physical_location_col) {
                // For exact matching with spaces and special characters like "/"
                // Use .eq. operator - the value will be properly encoded during query building
                $filters[] = $physical_location_col . '.eq.' . $physical_location;
            }
        }

        if (!empty($new_only)) {
            $new_col = $this->find_column($actual_columns, 'new');
            if ($new_col) {
                $filters[] = $new_col . '.eq.true';
            }
        }

        // DataTables global search
        if (!empty($search_value)) {
            $title_col = $this->find_column($actual_columns, 'title');
            $author_col = $this->find_column($actual_columns, 'author');
            $search_filters = [];

            if ($title_col) {
                $search_filters[] = $title_col . '.ilike.*' . $search_value . '*';
            }
            if ($author_col) {
                $search_filters[] = $author_col . '.ilike.*' . $search_value . '*';
            }

            if (!empty($search_filters)) {
                $filters[] = 'or=(' . implode(',', $search_filters) . ')';
            }
        }

        // Get total count (without filters)
        $total_count = $this->get_table_count($table_name);

        // Get filtered count (with filters applied)
        $filtered_count = $this->get_filtered_count($table_name, $filters, $actual_columns);
        
        // Debug logging

        // Build query parameters for Supabase fetch method
        $query_params = [
            'limit' => $length,
            'offset' => $start
        ];

        // Add order by title
        $title_col = $this->find_column($actual_columns, 'title');
        if ($title_col) {
            $query_params['order'] = $title_col . '.asc';
        }

        // Add filters to query params
        foreach ($filters as $filter) {
            // Filters can be in two formats:
            // 1. "key=value" (e.g., "or=(Title.ilike.*george*)")
            // 2. "column.operator.value" (e.g., "Title.ilike.*george*")

            if (strpos($filter, '=') !== false) {
                // Format: key=value
                list($key, $value) = explode('=', $filter, 2);
                $query_params[$key] = $value;
            } else {
                // Format: column.operator.value
                // Extract column name (everything before first dot)
                $parts = explode('.', $filter, 2);
                if (count($parts) === 2) {
                    $column = $parts[0];
                    $operatorAndValue = $parts[1]; // e.g., "eq.Branch A/B" or "ilike.*george*"
                    $query_params[$column] = $operatorAndValue;
                }
            }
        }


        // Fetch data
        $data = $this->supabase->fetch($table_name, $query_params);

        if ($data === false) {
            return new WP_REST_Response([
                'error' => 'Failed to fetch library data'
            ], 500);
        }

        // Format data for DataTables
        $formatted_data = [];
        foreach ($data as $row) {
            $formatted_data[] = [
                'title' => $this->get_value_case_insensitive($row, 'title'),
                'author' => $this->get_value_case_insensitive($row, 'author'),
                'publisher' => $this->get_value_case_insensitive($row, 'publisher'),
                'publication_date' => $this->get_value_case_insensitive($row, 'publication_date'),
                'full_data' => $row
            ];
        }

            return new WP_REST_Response([
                'draw' => $draw,
                'recordsTotal' => $total_count,
                'recordsFiltered' => $filtered_count,
                'data' => $formatted_data
            ]);
        } catch (Exception $e) {
            error_log('Library search error: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());

            return new WP_REST_Response([
                'error' => 'An error occurred while searching the library',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get actual column names from table info
     *
     * @param array $table_info
     * @return array Column names
     */
    private function get_actual_column_names($table_info) {
        if (!isset($table_info['columns'])) {
            return [];
        }

        return array_column($table_info['columns'], 'column_name');
    }

    /**
     * Find a column name case-insensitively
     *
     * @param array $columns Available column names
     * @param string $search Column name to search for
     * @return string|null Actual column name or null
     */
    private function find_column($columns, $search) {
        $search_lower = strtolower($search);

        foreach ($columns as $column) {
            if (strtolower($column) === $search_lower) {
                return $column;
            }
        }

        return null;
    }

    /**
     * Get value from array case-insensitively
     *
     * @param array $array
     * @param string $key
     * @return mixed
     */
    private function get_value_case_insensitive($array, $key) {
        $key_lower = strtolower($key);

        foreach ($array as $k => $v) {
            if (strtolower($k) === $key_lower) {
                return $v;
            }
        }

        return '';
    }

    /**
     * Get table row count
     *
     * @param string $table_name
     * @return int
     */
    private function get_table_count($table_name) {
        // Get count from cached table info
        $table_info = $this->library_manager->get_library_table_info();

        if ($table_info && isset($table_info['row_count'])) {
            return intval($table_info['row_count']);
        }

        // Fallback: return 0 if no cached count
        return 0;
    }

    /**
     * Get filtered row count
     *
     * @param string $table_name
     * @param array $filters Filter strings in the same format as data query
     * @param array $actual_columns Actual column names from table
     * @return int
     */
    private function get_filtered_count($table_name, $filters, $actual_columns = []) {
        if (empty($filters)) {
            return $this->get_table_count($table_name);
        }

        // Build query parameters for count query (same format as data query)
        $count_params = [];
        
        // Add filters to count query params (same logic as data query)
        foreach ($filters as $filter) {
            // Filters can be in two formats:
            // 1. "key=value" (e.g., "or=(Title.ilike.*george*)")
            // 2. "column.operator.value" (e.g., "Title.ilike.*george*")

            if (strpos($filter, '=') !== false) {
                // Format: key=value
                list($key, $value) = explode('=', $filter, 2);
                $count_params[$key] = $value;
            } else {
                // Format: column.operator.value
                // Extract column name (everything before first dot)
                $parts = explode('.', $filter, 2);
                if (count($parts) === 2) {
                    $column = $parts[0];
                    $operatorAndValue = $parts[1]; // e.g., "eq.Branch A/B" or "ilike.*george*"
                    $count_params[$column] = $operatorAndValue;
                }
            }
        }

        // Use Supabase count endpoint with filters (same as fetch method)
        // Build query string - special handling for 'or' parameter
        $query_params = array_merge(['select' => '*'], $count_params);

        // Extract 'or' parameter if present for special handling (same as fetch method)
        $or_param = null;
        if (isset($query_params['or'])) {
            $or_param = $query_params['or'];
            unset($query_params['or']);
        }

        // Build regular query string
        $query_string = http_build_query($query_params);

        // Add 'or' parameter manually with minimal encoding (same as fetch method)
        if ($or_param !== null) {
            $or_param_encoded = str_replace(' ', '%20', $or_param);
            $query_string .= ($query_string ? '&' : '') . 'or=' . $or_param_encoded;
        }

        $endpoint = $this->supabase->url . '/rest/v1/' . $table_name;
        if (!empty($query_string)) {
            $endpoint .= '?' . $query_string;
        }

        // Debug logging
        error_log('Filtered count query: ' . $endpoint);

        $response = wp_remote_get($endpoint, [
            'headers' => [
                'apikey' => $this->supabase->key,
                'Authorization' => 'Bearer ' . $this->supabase->key,
                'Prefer' => 'count=exact',
                'Range' => '0-0' // Only get headers, not data
            ],
            'timeout' => 15
        ]);

        if (is_wp_error($response)) {
            error_log('Error getting filtered count: ' . $response->get_error_message());
            // Fallback to total count if query fails
            return $this->get_table_count($table_name);
        }

        $headers = wp_remote_retrieve_headers($response);
        $count = 0;

        if (isset($headers['content-range'])) {
            if (preg_match('/\/(\d+)$/', $headers['content-range'], $matches)) {
                $count = (int) $matches[1];
            }
        }

        return $count;
    }

    /**
     * Check if current user has database access
     *
     * @return bool
     */
    private function has_database_access() {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return false;
        }

        return (bool)get_user_meta($user_id, 'supabase_access', true);
    }
}

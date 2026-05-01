<?php
/**
 * Data Display Handler
 * Handles shortcodes and data fetching for display
 */

if (!defined('ABSPATH')) {
    exit;
}

class Supabase_Data_Display {

    private $supabase;

    public function __construct() {
        $this->supabase = new Supabase_Client();

        // Register shortcodes
        add_shortcode('supabase_table', [$this, 'render_table_shortcode']);
        add_shortcode('supabase_multi_search', [$this, 'render_multi_search_shortcode']);

        // Register REST API endpoint for DataTables server-side processing
        add_action('rest_api_init', [$this, 'register_rest_routes']);
    }

    /**
     * Register REST API routes
     */
    public function register_rest_routes() {
        register_rest_route('supabase/v1', '/table-data/(?P<table>[^/]+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_table_data_for_datatables'],
            'permission_callback' => [$this, 'check_table_access'],
            'args' => [
                'table' => [
                    'required' => true,
                    'sanitize_callback' => function($value) {
                        return urldecode($value);
                    }
                ]
            ]
        ]);

        // Multi-database search endpoint
        register_rest_route('supabase/v1', '/multi-search', [
            'methods' => 'GET',
            'callback' => [$this, 'handle_multi_search_request'],
            'permission_callback' => [$this, 'check_table_access'],
        ]);
    }

    /**
     * Permission callback for REST API
     */
    public function check_table_access($request) {
        // Get table name from request
        $table_name = $request->get_param('table');

        // Check if table is unlocked
        if ($table_name) {
            $tables = get_option('supabase_schema_tables', []);
            foreach ($tables as $table) {
                if ($table['table_name'] === $table_name) {
                    // If unlocked, allow everyone
                    if (!($table['is_locked'] ?? true)) {
                        return true;
                    }
                    break;
                }
            }
        }

        // Locked table logic (current behavior)
        // Must be logged in
        if (!is_user_logged_in()) {
            return new WP_Error('rest_forbidden', 'You must be logged in to access this data.', ['status' => 401]);
        }

        // Administrators always have access
        if (current_user_can('manage_options')) {
            return true;
        }

        // Must have supabase access
        $user_id = get_current_user_id();
        $has_access = get_user_meta($user_id, 'supabase_access', true);

        if (!$has_access) {
            return new WP_Error('rest_forbidden', 'You do not have permission to access this data.', ['status' => 403]);
        }

        return true;
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
     * REST API endpoint for multi-database search
     */
    public function handle_multi_search_request($request) {
        $rate_limit_error = $this->check_rate_limit();
        if ($rate_limit_error) {
            return $rate_limit_error;
        }

        // Get search parameters
        $databases = $request->get_param('databases');
        $search_value = $request->get_param('search_value');
        $advanced_filters = $request->get_param('advanced_filters');

        // DataTables parameters
        $draw = $request->get_param('draw') ?: 1;
        $start = intval($request->get_param('start') ?: 0);
        $length = intval($request->get_param('length') ?: 25);

        // Validate databases parameter
        if (empty($databases) || !is_array($databases)) {
            return new WP_REST_Response([
                'draw' => intval($draw),
                'recordsTotal' => 0,
                'recordsFiltered' => 0,
                'data' => [],
                'error' => 'No databases selected'
            ], 200);
        }

        // Validate databases against synced tables
        $available_tables = get_option('supabase_schema_tables', []);
        $available_table_names = array_map(function($table) {
            return $table['table_name'];
        }, $available_tables);

        $databases = array_intersect($databases, $available_table_names);

        if (empty($databases)) {
            return new WP_REST_Response([
                'draw' => intval($draw),
                'recordsTotal' => 0,
                'recordsFiltered' => 0,
                'data' => [],
                'error' => 'Invalid database selection'
            ], 200);
        }

        // Limit number of databases for performance
        if (count($databases) > 10) {
            return new WP_REST_Response([
                'draw' => intval($draw),
                'recordsTotal' => 0,
                'recordsFiltered' => 0,
                'data' => [],
                'error' => 'Please select no more than 10 databases at a time'
            ], 200);
        }

        // Execute search across selected databases
        $all_results = $this->execute_multi_database_search($databases, $search_value, $advanced_filters);

        // Apply sorting if specified
        $order = $request->get_param('order');
        if (is_array($order) && !empty($order[0])) {
            $order_column = $order[0]['column'] ?? 0;
            $order_dir = $order[0]['dir'] ?? 'asc';
            $all_results = $this->sort_results($all_results, $order_column, $order_dir);
        }

        // Get total count
        $total_count = count($all_results);

        // Apply pagination
        $paginated_results = array_slice($all_results, $start, $length);

        // Return DataTables format
        return new WP_REST_Response([
            'draw' => intval($draw),
            'recordsTotal' => $total_count,
            'recordsFiltered' => $total_count,
            'data' => $paginated_results
        ], 200);
    }

    /**
     * Execute search across multiple databases
     */
    private function execute_multi_database_search($databases, $search_value, $advanced_filters) {
        $all_results = [];
        $available_tables = get_option('supabase_schema_tables', []);

        foreach ($databases as $database) {
            // Get table schema
            $table_schema = null;
            foreach ($available_tables as $table) {
                if ($table['table_name'] === $database) {
                    $table_schema = $table;
                    break;
                }
            }

            if (!$table_schema) {
                continue;
            }

            $search_fields = $this->get_table_search_fields($table_schema);

            // Build search parameters
            $params = ['limit' => 1000]; // Limit per database for performance

            // Build search query
            if (!empty($search_value) && empty($advanced_filters)) {
                // Simple keyword search
                $search_conditions = $this->build_simple_search($table_schema['columns'], $search_value, $search_fields);
                if (empty($search_conditions)) {
                    // No text-compatible selected fields to search in this table.
                    continue;
                }
                $params['or'] = '(' . implode(',', $search_conditions) . ')';
            } elseif (!empty($advanced_filters) && is_array($advanced_filters)) {
                // Advanced search
                $filter_conditions = $this->build_advanced_search($table_schema['columns'], $advanced_filters);
                foreach ($filter_conditions as $key => $value) {
                    $params[$key] = $value;
                }
            }

            // Fetch data from Supabase
            $results = $this->supabase->fetch($database, $params);

            if ($results && is_array($results)) {
                // Add source database metadata and filter to selected fields
                foreach ($results as $result) {
                    $filtered_result = $this->filter_search_result_fields($result, $table_schema, $search_fields);
                    $filtered_result['_source_database'] = $database;
                    $filtered_result['_source_database_display'] = ucwords(str_replace('_', ' ', $database));
                    $all_results[] = $filtered_result;
                }
            }
        }

        return $all_results;
    }

    /**
     * Build simple search conditions for Supabase
     */
    private function build_simple_search($columns, $search_value, $allowed_fields = []) {
        $search_conditions = [];
        $search_value = sanitize_text_field($search_value);
        // URL encode the search value for PostgREST
        $encoded_search_value = urlencode($search_value);
        $allowed_field_lookup = [];

        if (!empty($allowed_fields) && is_array($allowed_fields)) {
            foreach ($allowed_fields as $field_name) {
                if (is_string($field_name) && $field_name !== '') {
                    $allowed_field_lookup[$field_name] = true;
                }
            }
        }

        // Text-compatible data types
        $text_types = ['text', 'character varying', 'varchar', 'char', 'character', 'bpchar', 'citext', 'uuid', 'name'];

        foreach ($columns as $column) {
            $col_name = $column['column_name'];
            $data_type = strtolower($column['data_type']);

            if (!empty($allowed_field_lookup) && !isset($allowed_field_lookup[$col_name])) {
                continue;
            }

            // Only search text-like columns
            $is_text = false;
            foreach ($text_types as $text_type) {
                if (strpos($data_type, $text_type) !== false) {
                    $is_text = true;
                    break;
                }
            }

            if ($is_text) {
                $search_conditions[] = $col_name . '.ilike.*' . $encoded_search_value . '*';
            }
        }

        return $search_conditions;
    }

    /**
     * Get selected search fields for a table schema, defaulting to all table columns.
     */
    private function get_table_search_fields($table_schema) {
        $available_fields = [];
        if (isset($table_schema['columns']) && is_array($table_schema['columns'])) {
            foreach ($table_schema['columns'] as $column) {
                if (isset($column['column_name']) && $column['column_name'] !== '') {
                    $available_fields[] = $column['column_name'];
                }
            }
        }

        if (empty($available_fields)) {
            return [];
        }

        if (!isset($table_schema['search_fields']) || !is_array($table_schema['search_fields'])) {
            return $available_fields;
        }

        $available_lookup = array_fill_keys($available_fields, true);
        $selected_fields = [];
        foreach ($table_schema['search_fields'] as $field_name) {
            if (!is_string($field_name) || $field_name === '') {
                continue;
            }
            if (isset($available_lookup[$field_name]) && !in_array($field_name, $selected_fields, true)) {
                $selected_fields[] = $field_name;
            }
        }

        if (!empty($selected_fields)) {
            return $selected_fields;
        }

        return $available_fields;
    }

    /**
     * Filter result data to selected search/display fields for the table.
     */
    private function filter_search_result_fields($result, $table_schema, $search_fields) {
        if (!is_array($result)) {
            return [];
        }

        $selected_fields = $search_fields;
        if (empty($selected_fields)) {
            $selected_fields = $this->get_table_search_fields($table_schema);
        }

        $filtered_result = [];
        foreach ($selected_fields as $field_name) {
            if (array_key_exists($field_name, $result)) {
                $filtered_result[$field_name] = $result[$field_name];
            }
        }

        $pdf_column = isset($table_schema['pdf_column']) ? $table_schema['pdf_column'] : '';
        if (!empty($pdf_column) && array_key_exists($pdf_column, $result)) {
            $filtered_result['_pdf_url'] = $result[$pdf_column];
        }

        return $filtered_result;
    }

    /**
     * Build advanced search conditions for Supabase
     */
    private function build_advanced_search($columns, $filters) {
        $conditions = [];

        // Map of filter keys to possible column names
        $field_mappings = [
            'first_name' => ['first_name', 'firstname', 'given_name', 'forename'],
            'last_name' => ['last_name', 'lastname', 'surname', 'family_name', 'maiden_name'],
            'birth_place' => ['birth_place', 'birthplace', 'place_of_birth', 'birth_location'],
            'residence' => ['residence', 'location', 'address', 'place'],
            'death_place' => ['death_place', 'deathplace', 'place_of_death', 'death_location'],
            'birth_year' => ['birth_year', 'birth_date', 'birthyear', 'dob', 'year_of_birth'],
            'death_year' => ['death_year', 'death_date', 'deathyear', 'dod', 'year_of_death']
        ];

        // Get available column names
        $available_columns = array_map(function($col) {
            return strtolower($col['column_name']);
        }, $columns);

        foreach ($filters as $filter_key => $filter_value) {
            if (empty($filter_value)) {
                continue;
            }

            $filter_value = sanitize_text_field($filter_value);

            // Handle year ranges
            if ($filter_key === 'birth_year_from' || $filter_key === 'birth_year_to' ||
                $filter_key === 'death_year_from' || $filter_key === 'death_year_to') {

                $year_type = (strpos($filter_key, 'birth') !== false) ? 'birth_year' : 'death_year';
                $range_type = (strpos($filter_key, 'from') !== false) ? 'from' : 'to';

                // Find matching column
                $matching_col = $this->find_matching_column($available_columns, $field_mappings[$year_type]);

                if ($matching_col) {
                    if ($range_type === 'from') {
                        $conditions[$matching_col . '.gte'] = $filter_value;
                    } else {
                        $conditions[$matching_col . '.lte'] = $filter_value;
                    }
                }
                continue;
            }

            // Handle text fields
            if (isset($field_mappings[$filter_key])) {
                $matching_col = $this->find_matching_column($available_columns, $field_mappings[$filter_key]);

                if ($matching_col) {
                    // URL encode the filter value for PostgREST
                    $conditions[$matching_col . '.ilike'] = '*' . urlencode($filter_value) . '*';
                }
            }
        }

        return $conditions;
    }

    /**
     * Find matching column from available columns
     */
    private function find_matching_column($available_columns, $possible_names) {
        foreach ($possible_names as $name) {
            if (in_array(strtolower($name), $available_columns)) {
                // Find the actual column name with original casing
                foreach ($available_columns as $col) {
                    if (strtolower($col) === strtolower($name)) {
                        return $col;
                    }
                }
            }
        }
        return null;
    }

    /**
     * Sort aggregated results
     */
    private function sort_results($results, $column_index, $direction) {
        if (empty($results)) {
            return $results;
        }

        // Get the column name from index
        $columns = array_keys($results[0]);
        if (!isset($columns[$column_index])) {
            return $results;
        }

        $sort_column = $columns[$column_index];

        usort($results, function($a, $b) use ($sort_column, $direction) {
            $val_a = $a[$sort_column] ?? '';
            $val_b = $b[$sort_column] ?? '';

            if ($direction === 'asc') {
                return strcmp($val_a, $val_b);
            } else {
                return strcmp($val_b, $val_a);
            }
        });

        return $results;
    }

    /**
     * REST API endpoint for DataTables server-side processing
     */
    public function get_table_data_for_datatables($request) {
        $rate_limit_error = $this->check_rate_limit();
        if ($rate_limit_error) {
            return $rate_limit_error;
        }

        $table_name = $request->get_param('table');

        // DataTables parameters - safely extract nested values
        $draw = $request->get_param('draw') ?: 1;
        $start = intval($request->get_param('start') ?: 0);
        $length = intval($request->get_param('length') ?: 10);

        // Get search parameter - DataTables sends it as search[value]
        // WordPress REST API needs special handling for nested params
        $search_value = '';
        $search = $request->get_param('search');
        if (is_array($search) && isset($search['value'])) {
            $search_value = sanitize_text_field($search['value']);
        } else {
            // Fallback: try to get it from raw $_GET parameters
            if (isset($_GET['search']) && is_array($_GET['search']) && isset($_GET['search']['value'])) {
                $search_value = sanitize_text_field($_GET['search']['value']);
            }
        }

        // Get order parameter
        $order = $request->get_param('order');
        $order_column_index = 0;
        $order_dir = 'asc';
        if (is_array($order) && !empty($order[0])) {
            $order_column_index = intval($order[0]['column'] ?? 0);
            $order_dir = $order[0]['dir'] ?? 'asc';
        } elseif (isset($_GET['order']) && is_array($_GET['order']) && !empty($_GET['order'][0])) {
            // Fallback for nested array params
            $order_column_index = intval($_GET['order'][0]['column'] ?? 0);
            $order_dir = sanitize_text_field($_GET['order'][0]['dir'] ?? 'asc');
        }

        // Get columns - WordPress REST API doesn't parse nested arrays well from GET params
        // So we need to check both the request object and $_GET directly
        $columns = $request->get_param('columns');
        if (empty($columns) || !is_array($columns) || !isset($columns[0]['data'])) {
            // Fallback: parse from $_GET
            if (isset($_GET['columns']) && is_array($_GET['columns'])) {
                $columns = $_GET['columns'];
            } else {
                $columns = [];
            }
        }


        // Get total count
        $total_count = $this->get_table_count($table_name);

        // Build Supabase query parameters
        $params = [
            'limit' => $length,
            'offset' => $start
        ];

        // Add ordering
        if (!empty($columns[$order_column_index]['data'])) {
            $order_column = $columns[$order_column_index]['data'];
            $params['order'] = $order_column . '.' . $order_dir;
        }

        // Add search filter if provided
        $filtered_count = $total_count;
        if (!empty($search_value)) {
            // Get searchable columns from the request
            $searchable_columns = [];

            if (!empty($columns) && is_array($columns)) {
                foreach ($columns as $column) {
                    // Handle both array format and ensure column data exists
                    if (is_array($column) && isset($column['data'])) {
                        // Check if searchable - handle string 'true'/'false' or boolean true/false
                        $is_searchable = false;
                        if (isset($column['searchable'])) {
                            $is_searchable = ($column['searchable'] === 'true' || $column['searchable'] === true);
                        } else {
                            // Default to searchable if not specified
                            $is_searchable = true;
                        }

                        if ($is_searchable) {
                            $searchable_columns[] = sanitize_text_field($column['data']);
                        }
                    }
                }
            }

            // Fallback: if no columns specified or none are searchable, search all columns
            if (empty($searchable_columns)) {
                // Get columns from table schema
                $table_columns = $this->get_table_columns($table_name);
                if (!empty($table_columns)) {
                    $searchable_columns = $table_columns;
                    error_log('[Search] Using fallback: searching all ' . count($searchable_columns) . ' table columns');
                }
            } else {
                error_log('[Search] Found ' . count($searchable_columns) . ' searchable columns from DataTables');
            }

            // Build OR search query for Supabase
            if (!empty($searchable_columns)) {
                // Filter to only text-compatible columns (ilike only works on text types)
                $text_searchable_columns = $this->filter_text_columns($table_name, $searchable_columns);

                if (!empty($text_searchable_columns)) {
                    $search_conditions = [];
                    // URL encode the search value for PostgREST, but keep wildcards unencoded
                    $encoded_search_value = urlencode($search_value);

                    error_log('[Search] Encoded search value: "' . $search_value . '" -> "' . $encoded_search_value . '"');
                    error_log('[Search] Filtered to ' . count($text_searchable_columns) . ' text columns out of ' . count($searchable_columns) . ' total');

                    foreach ($text_searchable_columns as $col) {
                        // Build PostgREST ilike query with wildcards
                        // Format: column.ilike.*value*
                        $search_conditions[] = $col . '.ilike.*' . $encoded_search_value . '*';
                    }
                    $params['or'] = '(' . implode(',', $search_conditions) . ')';

                    // Log search query for debugging
                    error_log('[Search] Query built with ' . count($search_conditions) . ' conditions');

                    // Recalculate filtered count with search applied
                    $filtered_count = $this->get_table_count($table_name, $params);
                    error_log('[Search] Filtered count: ' . $filtered_count);
                } else {
                    error_log('[Search] WARNING: No text-compatible columns found for search!');
                }
            } else {
                error_log('[Search] WARNING: No searchable columns found!');
            }
        }

        // Fetch data from Supabase
        $data = $this->supabase->fetch($table_name, $params);

        if ($data === false) {
            error_log('Failed to fetch data from Supabase for table: ' . $table_name);
            // Return empty data instead of error to keep DataTables happy
            return new WP_REST_Response([
                'draw' => intval($draw),
                'recordsTotal' => 0,
                'recordsFiltered' => 0,
                'data' => [],
                'error' => 'Failed to fetch data from Supabase'
            ], 200);
        }

        // Ensure data is an array
        if (!is_array($data)) {
            $data = [];
        }

        // Return DataTables format
        return new WP_REST_Response([
            'draw' => intval($draw),
            'recordsTotal' => $total_count,
            'recordsFiltered' => $filtered_count,
            'data' => $data
        ], 200);
    }

    /**
     * Get total count for a table
     */
    private function get_table_count($table, $additional_params = []) {
        $cache_key = 'supabase_count_' . $table . '_' . md5(serialize($additional_params));
        $cached_count = get_transient($cache_key);

        if ($cached_count !== false) {
            return $cached_count;
        }

        $params = array_merge(['select' => 'count'], $additional_params);
        unset($params['limit'], $params['offset'], $params['order']);

        $endpoint = $this->supabase->url . '/rest/v1/' . $table . '?' . http_build_query($params);

        $response = wp_remote_get($endpoint, [
            'headers' => [
                'apikey' => $this->supabase->key,
                'Authorization' => 'Bearer ' . $this->supabase->key,
                'Prefer' => 'count=exact'
            ],
            'timeout' => 15
        ]);

        if (is_wp_error($response)) {
            return 0;
        }

        $headers = wp_remote_retrieve_headers($response);
        $count = 0;

        if (isset($headers['content-range'])) {
            if (preg_match('/\/(\d+)$/', $headers['content-range'], $matches)) {
                $count = (int) $matches[1];
            }
        }

        // Cache for 5 minutes
        set_transient($cache_key, $count, 300);

        return $count;
    }

    /**
     * Shortcode: [supabase_table table="your_data_table"]
     */
    public function render_table_shortcode($atts) {
        // Parse attributes
        $atts = shortcode_atts([
            'table' => 'your_data_table',
            'columns' => '', // Comma-separated column names (optional)
            'image_columns' => '' // Comma-separated column names that contain image URLs
        ], $atts, 'supabase_table');

        // Check if table is unlocked first
        $table_name = $atts['table'];
        $tables = get_option('supabase_schema_tables', []);
        $is_locked = true;

        foreach ($tables as $table) {
            if ($table['table_name'] === $table_name) {
                $is_locked = $table['is_locked'] ?? true;
                break;
            }
        }

        // If table is locked, enforce access control
        if ($is_locked) {
            // Check if user is logged in
            if (!is_user_logged_in()) {
                return $this->render_message('Please log in to view this content.', 'warning');
            }

            // Check if user has access (administrators always have access)
            if (!current_user_can('manage_options')) {
                $user_id = get_current_user_id();
                $has_access = get_user_meta($user_id, 'supabase_access', true);

                if (!$has_access) {
                    return $this->render_message('This content is available to premium members only. Please upgrade your membership to access.', 'info');
                }
            }
        }

        // Check if Supabase is configured
        if (!$this->supabase->is_configured()) {
            if (current_user_can('manage_options')) {
                return $this->render_message('Supabase is not configured. Please configure in Settings > Supabase Sync.', 'error');
            }
            return $this->render_message('Data is temporarily unavailable. Please try again later.', 'error');
        }

        // Get column information from synced schema
        $columns = $this->get_table_columns($atts['table']);

        if (empty($columns)) {
            // Fallback: fetch a sample row to determine columns
            $sample_data = $this->supabase->fetch($atts['table'], ['limit' => 1]);
            if ($sample_data && !empty($sample_data[0])) {
                $columns = array_keys($sample_data[0]);
            } else {
                return $this->render_message('Unable to determine table structure. Please sync tables in admin.', 'error');
            }
        }

        // Filter columns if specified
        if (!empty($atts['columns'])) {
            $specified_columns = array_map('trim', explode(',', $atts['columns']));
            $columns = array_intersect($columns, $specified_columns);
        }

        // Parse image columns
        $image_columns = [];
        if (!empty($atts['image_columns'])) {
            $image_columns = array_map('trim', explode(',', $atts['image_columns']));
        }

        // Render DataTables table
        return $this->render_datatables_table($atts['table'], $columns, $image_columns);
    }

    /**
     * Shortcode: [supabase_multi_search]
     * Renders multi-database search interface
     */
    public function render_multi_search_shortcode($atts) {
        // Get available databases first to check for unlocked tables
        $all_tables = get_option('supabase_schema_tables', []);

        // Filter out system tables like wp_users
        $all_tables = array_filter($all_tables, function($table) {
            return $table['table_name'] !== 'wp_users';
        });

        // Check if there are any unlocked tables
        $has_unlocked_tables = false;
        foreach ($all_tables as $table) {
            if (!($table['is_locked'] ?? true)) {
                $has_unlocked_tables = true;
                break;
            }
        }

        // If all tables are locked, enforce standard access control
        if (!$has_unlocked_tables) {
            // Check if user is logged in
            if (!is_user_logged_in()) {
                return $this->render_message('Please log in to search databases.', 'warning');
            }

            // Check if user has access (administrators always have access)
            if (!current_user_can('manage_options')) {
                $user_id = get_current_user_id();
                $has_access = get_user_meta($user_id, 'supabase_access', true);

                if (!$has_access) {
                    return $this->render_message('Database search is available to premium members only. Please upgrade your membership to access.', 'info');
                }
            }
        }

        // Check if Supabase is configured
        if (!$this->supabase->is_configured()) {
            if (current_user_can('manage_options')) {
                return $this->render_message('Supabase is not configured. Please configure in Settings > Supabase Sync.', 'error');
            }
            return $this->render_message('Search is temporarily unavailable. Please try again later.', 'error');
        }

        // Filter tables by access permissions AND searchability setting
        $tables = array_filter($all_tables, function($table) {
            // First check if table is marked as searchable (defaults to true for backwards compatibility)
            $is_searchable = $table['is_searchable'] ?? true;
            if (!$is_searchable) {
                return false; // Table is not enabled for general search
            }

            $is_locked = $table['is_locked'] ?? true;

            // Unlocked tables visible to all
            if (!$is_locked) {
                return true;
            }

            // Locked tables only for admins or users with access
            if (current_user_can('manage_options')) {
                return true;
            }

            if (is_user_logged_in()) {
                $has_access = get_user_meta(get_current_user_id(), 'supabase_access', true);
                return (bool)$has_access;
            }

            return false;
        });

        if (empty($tables)) {
            return $this->render_message('No databases available. Please sync databases in the admin panel.', 'error');
        }

        // Enqueue assets
        $this->enqueue_multi_search_assets();

        // Render the search interface
        return $this->render_multi_search_interface($tables);
    }

    /**
     * Render the multi-search interface HTML
     */
    private function render_multi_search_interface($tables) {
        ob_start();
        ?>
        <div class="supabase-multi-search-wrapper">
            <div class="multi-search-form" id="multi-search-form">
                <h3>Search Across Databases</h3>

                <!-- Database Selection -->
                <div class="database-selection">
                    <h4>Select Databases to Search:</h4>
                    <div class="database-checkboxes">
                        <label class="select-all-wrapper">
                            <input type="checkbox" id="select-all-databases" />
                            <strong>Select All</strong>
                        </label>
                        <?php foreach ($tables as $table): ?>
                            <label class="database-checkbox">
                                <input type="checkbox"
                                       name="databases[]"
                                       value="<?php echo esc_attr($table['table_name']); ?>"
                                       class="database-select" />
                                <?php echo esc_html(ucwords(str_replace('_', ' ', $table['table_name']))); ?>
                                <span class="record-count">(<?php echo number_format($table['row_count']); ?> records)</span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Simple Search -->
                <div class="simple-search">
                    <h4 id="search-terms-heading">Search Terms:</h4>
                    <label for="multi-search-keyword" class="screen-reader-text">Search keywords</label>
                    <input type="text"
                           id="multi-search-keyword"
                           placeholder="Enter keywords to search across all fields..."
                           class="search-input"
                           aria-describedby="search-hint" />
                    <p class="search-hint" id="search-hint">Tip: Enter names, places, dates, or any keywords to search</p>
                </div>

                <!-- Search Button -->
                <div class="search-actions">
                    <button type="button" id="execute-multi-search" class="button button-primary button-large" aria-describedby="search-hint">
                        <span class="dashicons dashicons-search" aria-hidden="true"></span>
                        Search Databases
                    </button>
                </div>

                <div id="search-status-message" role="status" aria-live="polite" aria-atomic="true"></div>
            </div>

            <!-- Results Container -->
            <div class="multi-search-results" style="display: none;" role="region" aria-labelledby="results-heading">
                <h3 id="results-heading">Search Results</h3>
                <div id="results-summary" role="status" aria-live="polite" aria-atomic="true"></div>
                <table id="multi-search-results-table" class="display" style="width:100%" role="table" aria-label="Search results across multiple databases">
                    <caption class="screen-reader-text">Search results from selected genealogy databases</caption>
                    <thead>
                        <tr>
                            <th scope="col">Database Source</th>
                            <th scope="col">Record Data</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                </table>
            </div>
        </div>

        <script type="text/javascript">
        // Pass PHP data to JavaScript
        var supabaseMultiSearch = {
            restUrl: '<?php echo esc_url(rest_url('supabase/v1/multi-search')); ?>',
            nonce: '<?php echo wp_create_nonce('wp_rest'); ?>',
            availableTables: <?php echo json_encode($tables); ?>,
            tablePageUrls: <?php echo json_encode($this->get_table_page_urls($tables)); ?>,
            tablePdfColumns: <?php echo json_encode($this->get_table_pdf_columns($tables)); ?>
        };
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Get URLs for table pages
     */
    private function get_table_page_urls($tables) {
        $urls = [];

        foreach ($tables as $table) {
            $table_name = $table['table_name'];

            // Find the page for this table
            $pages = get_posts([
                'post_type' => 'page',
                'post_status' => 'any',
                'posts_per_page' => 1,
                'name' => $table_name
            ]);

            if (!empty($pages)) {
                $urls[$table_name] = get_permalink($pages[0]->ID);
            }
        }

        return $urls;
    }

    /**
     * Get PDF column mappings for tables
     * Returns an array mapping table names to their PDF column names
     */
    private function get_table_pdf_columns($tables) {
        $pdf_columns = [];

        foreach ($tables as $table) {
            $table_name = $table['table_name'];
            if (isset($table['pdf_column']) && !empty($table['pdf_column'])) {
                $pdf_columns[$table_name] = $table['pdf_column'];
            }
        }

        return $pdf_columns;
    }

    /**
     * Enqueue multi-search assets
     */
    private function enqueue_multi_search_assets() {
        static $enqueued = false;

        if ($enqueued) {
            return;
        }

        wp_enqueue_script('jquery');

        // DataTables CSS
        wp_enqueue_style(
            'datatables',
            'https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css',
            [],
            '1.13.7'
        );

        // DataTables Buttons CSS
        wp_enqueue_style(
            'datatables-buttons',
            'https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css',
            ['datatables'],
            '2.4.2'
        );

        // Multi-search custom CSS
        wp_enqueue_style(
            'supabase-multi-search',
            SUPABASE_ARMEMBER_PLUGIN_URL . 'public/css/multi-search.css',
            [],
            SUPABASE_ARMEMBER_VERSION
        );

        // DataTables JS
        wp_enqueue_script(
            'datatables',
            'https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js',
            ['jquery'],
            '1.13.7',
            true
        );

        // JSZip for Excel export
        wp_enqueue_script(
            'jszip',
            'https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js',
            [],
            '3.10.1',
            true
        );

        // DataTables Buttons
        wp_enqueue_script(
            'datatables-buttons',
            'https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js',
            ['datatables'],
            '2.4.2',
            true
        );

        // DataTables Buttons HTML5 (CSV, Excel export)
        wp_enqueue_script(
            'datatables-buttons-html5',
            'https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js',
            ['datatables-buttons', 'jszip'],
            '2.4.2',
            true
        );

        // PDF.js for rendering PDFs as printable canvas elements
        wp_enqueue_script(
            'pdfjs',
            'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js',
            [],
            '3.11.174',
            true
        );

        // Multi-search custom JS
        wp_enqueue_script(
            'supabase-multi-search',
            SUPABASE_ARMEMBER_PLUGIN_URL . 'public/js/multi-search.js',
            ['jquery', 'datatables', 'datatables-buttons', 'datatables-buttons-html5', 'pdfjs'],
            SUPABASE_ARMEMBER_VERSION,
            true
        );

        $enqueued = true;
    }

    /**
     * Get columns for a table from synced schema
     */
    private function get_table_columns($table_name) {
        $tables = get_option('supabase_schema_tables', []);

        foreach ($tables as $table) {
            if ($table['table_name'] === $table_name) {
                return array_map(function($col) {
                    return $col['column_name'];
                }, $table['columns']);
            }
        }

        return [];
    }

    /**
     * Filter columns to only text-compatible types
     * PostgreSQL ilike operator only works on text types, not numeric/boolean/date
     */
    private function filter_text_columns($table_name, $column_names) {
        $tables = get_option('supabase_schema_tables', []);

        // Find the table schema
        $table_schema = null;
        foreach ($tables as $table) {
            if ($table['table_name'] === $table_name) {
                $table_schema = $table;
                break;
            }
        }

        if (!$table_schema || empty($table_schema['columns'])) {
            error_log('[Search] No schema found for table "' . $table_name . '" — run "Sync Tables" in admin. Skipping search to avoid ilike errors on non-text columns.');
            return [];
        }

        // Build map of column name to data type
        $column_types = [];
        foreach ($table_schema['columns'] as $col) {
            $column_types[$col['column_name']] = strtolower($col['data_type']);
        }

        // Text-compatible data types in PostgreSQL
        $text_types = [
            'text',
            'character varying',
            'varchar',
            'char',
            'character',
            'bpchar',  // PostgreSQL internal name for blank-padded char
            'citext',  // case-insensitive text
            'uuid',    // UUIDs are stored as text-like
            'name',    // PostgreSQL system type, text-like
        ];

        // Filter to only text-compatible columns
        $text_columns = [];
        foreach ($column_names as $col_name) {
            if (isset($column_types[$col_name])) {
                $data_type = $column_types[$col_name];

                // Check if it's a text-compatible type
                $is_text = false;
                foreach ($text_types as $text_type) {
                    if (strpos($data_type, $text_type) !== false) {
                        $is_text = true;
                        break;
                    }
                }

                if ($is_text) {
                    $text_columns[] = $col_name;
                } else {
                    error_log('[Search] Skipping non-text column: ' . $col_name . ' (type: ' . $data_type . ')');
                }
            } else {
                // Column not in schema - skip for safety
                error_log('[Search] Skipping unknown column: ' . $col_name);
            }
        }

        return $text_columns;
    }

    /**
     * Render DataTables-enabled table
     */
    private function render_datatables_table($table_name, $columns, $image_columns = []) {
        // Generate unique table ID
        $table_id = 'supabase-table-' . sanitize_html_class($table_name);

        // Enqueue DataTables assets
        $this->enqueue_datatables_assets();

        // Build column definitions for DataTables
        $column_defs = [];
        foreach ($columns as $index => $column) {
            $column_defs[] = [
                'data' => $column,
                'name' => $column,
                'title' => ucwords(str_replace('_', ' ', $column)),
                'searchable' => true,  // Explicitly mark columns as searchable
                'orderable' => true    // Explicitly mark columns as orderable
            ];
        }

        ob_start();
        $table_display_name = ucwords(str_replace('_', ' ', $table_name));
        ?>
        <a href="#<?php echo esc_attr($table_id); ?>" class="skip-link">Skip to data table</a>
        <div class="supabase-datatables-wrapper" role="region" aria-labelledby="table-heading-<?php echo esc_attr($table_id); ?>">
            <h2 id="table-heading-<?php echo esc_attr($table_id); ?>" class="screen-reader-text"><?php echo esc_html($table_display_name); ?> Data Table</h2>
            <table id="<?php echo esc_attr($table_id); ?>" class="display" style="width:100%" role="table" aria-label="<?php echo esc_attr($table_display_name . ' records'); ?>">
                <caption class="screen-reader-text">
                    Table showing records from <?php echo esc_html($table_display_name); ?>.
                    Use the search box to filter results, and column headers to sort.
                </caption>
                <thead>
                    <tr>
                        <?php foreach ($columns as $column): ?>
                            <th scope="col"><?php echo esc_html(ucwords(str_replace('_', ' ', $column))); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
            </table>
        </div>

        <script type="text/javascript">
        jQuery(document).ready(function($) {
            var tableName = '<?php echo esc_js($table_name); ?>';
            var restUrl = '<?php echo esc_url(rest_url('supabase/v1/table-data/')); ?>';

            $('#<?php echo esc_js($table_id); ?>').DataTable({
                processing: true,
                serverSide: true,
                ajax: {
                    url: restUrl + encodeURIComponent(tableName),
                    type: 'GET',
                    beforeSend: function(xhr) {
                        xhr.setRequestHeader('X-WP-Nonce', '<?php echo wp_create_nonce('wp_rest'); ?>');
                    },
                    error: function(xhr, error, thrown) {
                        console.error('DataTables AJAX Error:', {
                            status: xhr.status,
                            statusText: xhr.statusText,
                            responseText: xhr.responseText,
                            error: error,
                            thrown: thrown
                        });
                    }
                },
                columns: <?php echo json_encode($column_defs); ?>,
                columnDefs: [
                    <?php if (!empty($image_columns)): ?>
                    {
                        targets: [<?php echo implode(',', array_map(function($col) use ($columns) {
                            return array_search($col, $columns);
                        }, $image_columns)); ?>],
                        render: function(data, type, row) {
                            if (type === 'display' && data) {
                                return '<a href="' + data + '" target="_blank" rel="noopener">View Image</a>';
                            }
                            return data;
                        }
                    }
                    <?php endif; ?>
                ],
                pageLength: 25,
                order: [[0, 'asc']],
                language: {
                    processing: 'Loading data...',
                    search: 'Search:',
                    lengthMenu: 'Show _MENU_ entries',
                    info: 'Showing _START_ to _END_ of _TOTAL_ entries',
                    infoEmpty: 'No entries available',
                    infoFiltered: '(filtered from _MAX_ total entries)',
                    paginate: {
                        first: 'First',
                        last: 'Last',
                        next: 'Next',
                        previous: 'Previous'
                    }
                }
            });
        });
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Enqueue DataTables assets
     */
    private function enqueue_datatables_assets() {
        static $enqueued = false;

        if ($enqueued) {
            return;
        }

        wp_enqueue_script('jquery');

        // DataTables CSS
        wp_enqueue_style(
            'datatables',
            'https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css',
            [],
            '1.13.7'
        );

        // DataTables JS
        wp_enqueue_script(
            'datatables',
            'https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js',
            ['jquery'],
            '1.13.7',
            true
        );

        $enqueued = true;
    }

    /**
     * Get data with caching
     */
    private function get_cached_data($table, $limit, $cache_duration) {
        $cache_key = "supabase_data_{$table}_{$limit}";
        $data = get_transient($cache_key);

        if ($data === false) {
            // Fetch from Supabase
            $params = [
                'limit' => $limit,
                'order' => 'created_at.desc'
            ];

            $data = $this->supabase->fetch($table, $params);

            if ($data !== false) {
                // Cache the data
                set_transient($cache_key, $data, $cache_duration);
            }
        }

        return $data;
    }

    /**
     * Render data as HTML table
     */
    private function render_html_table($data, $columns_string = '') {
        if (empty($data)) {
            return '';
        }

        // Parse columns to display
        $columns = [];
        if (!empty($columns_string)) {
            $columns = array_map('trim', explode(',', $columns_string));
        } else {
            // Use all columns from first row
            $columns = array_keys($data[0]);
        }

        // Start building table
        $html = '<div class="supabase-table-wrapper">';
        $html .= '<table class="supabase-table wp-list-table widefat fixed striped">';

        // Table header
        $html .= '<thead><tr>';
        foreach ($columns as $column) {
            $html .= '<th>' . esc_html(ucwords(str_replace('_', ' ', $column))) . '</th>';
        }
        $html .= '</tr></thead>';

        // Table body
        $html .= '<tbody>';
        foreach ($data as $row) {
            $html .= '<tr>';
            foreach ($columns as $column) {
                $value = isset($row[$column]) ? $row[$column] : '-';

                // Format value based on type
                if (is_bool($value)) {
                    $value = $value ? 'Yes' : 'No';
                } elseif (is_array($value) || is_object($value)) {
                    $value = json_encode($value);
                }

                $html .= '<td>' . esc_html($value) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody>';

        $html .= '</table>';
        $html .= '</div>';

        // Add basic styling
        $html .= $this->get_table_styles();

        return $html;
    }

    /**
     * Render a message box
     */
    private function render_message($message, $type = 'info') {
        $class = 'supabase-message supabase-message-' . esc_attr($type);
        return '<div class="' . $class . '">' . esc_html($message) . '</div>' . $this->get_message_styles();
    }

    /**
     * Get table CSS styles
     */
    private function get_table_styles() {
        return '
        <style>
            .supabase-table-wrapper {
                overflow-x: auto;
                margin: 20px 0;
            }
            .supabase-table {
                width: 100%;
                border-collapse: collapse;
            }
            .supabase-table th,
            .supabase-table td {
                padding: 12px;
                text-align: left;
                border-bottom: 1px solid #ddd;
            }
            .supabase-table th {
                background-color: #f5f5f5;
                font-weight: 600;
            }
            .supabase-table tr:hover {
                background-color: #f9f9f9;
            }
        </style>';
    }

    /**
     * Get message CSS styles
     */
    private function get_message_styles() {
        return '
        <style>
            .supabase-message {
                padding: 15px;
                margin: 20px 0;
                border-radius: 4px;
                border-left: 4px solid;
            }
            .supabase-message-info {
                background-color: #e7f3ff;
                border-color: #2196F3;
                color: #0c5393;
            }
            .supabase-message-warning {
                background-color: #fff3cd;
                border-color: #ffc107;
                color: #856404;
            }
            .supabase-message-error {
                background-color: #f8d7da;
                border-color: #dc3545;
                color: #721c24;
            }
        </style>';
    }
}

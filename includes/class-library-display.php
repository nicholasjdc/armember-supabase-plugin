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

        // The search form is built from the admin-managed configuration, so
        // adding or relabelling a field is a settings change, not a code change.
        $search_config = $this->library_manager->get_search_config($table_info);
        $search_fields = array_values(array_filter($search_config, function ($field) {
            return !empty($field['enabled']);
        }));

        if (empty($search_fields)) {
            return '<div class="supabase-notice supabase-error">No search fields are configured for the library catalog. Please contact the site administrator.</div>';
        }

        // Two fields per row, matching the existing stylesheet.
        $rows = array_chunk($search_fields, 2);

        ob_start();
        ?>
        <div class="supabase-library-catalog">
            <div class="library-search-form">
                <h2>Search Library Catalog</h2>

                <form id="library-search-form" class="search-form">
                    <?php foreach ($rows as $row): ?>
                        <div class="search-row">
                            <?php foreach ($row as $field): ?>
                                <?php $this->render_search_field($field); ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>

                    <div class="search-row search-row-actions">
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
     * Naive plural for dropdown placeholders ("All Locations").
     *
     * @param string $label
     * @return string
     */
    private function pluralize($label) {
        return substr(strtolower($label), -1) === 's' ? $label : $label . 's';
    }

    /**
     * Render one search field from its configuration.
     *
     * Every control carries a data-key attribute; the JavaScript collects
     * criteria from those rather than from hardcoded element ids.
     *
     * @param array $field
     * @return void
     */
    private function render_search_field($field) {
        $key = $field['key'];
        $id = 'search-' . str_replace('_', '-', $key);
        $label = $field['label'];

        if ($field['control'] === 'checkbox') {
            ?>
            <div class="search-field checkbox-field">
                <label>
                    <input type="checkbox"
                           id="<?php echo esc_attr($id); ?>"
                           name="<?php echo esc_attr($key); ?>"
                           class="library-search-field"
                           data-key="<?php echo esc_attr($key); ?>"
                           value="1">
                    <?php echo esc_html($label); ?>
                </label>
            </div>
            <?php
            return;
        }

        if ($field['control'] === 'dropdown') {
            ?>
            <div class="search-field">
                <label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?></label>
                <select id="<?php echo esc_attr($id); ?>"
                        name="<?php echo esc_attr($key); ?>"
                        class="search-select library-search-field"
                        data-key="<?php echo esc_attr($key); ?>">
                    <option value="">All <?php echo esc_html($this->pluralize($label)); ?></option>
                    <?php foreach ($field['options'] as $option): ?>
                        <option value="<?php echo esc_attr($option); ?>"><?php echo esc_html($option); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php
            return;
        }

        // text, exact and keyword all present as a free text input.
        ?>
        <div class="search-field">
            <label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?></label>
            <input type="text"
                   id="<?php echo esc_attr($id); ?>"
                   name="<?php echo esc_attr($key); ?>"
                   class="search-input library-search-field"
                   data-key="<?php echo esc_attr($key); ?>"
                   placeholder="<?php echo esc_attr('Enter ' . strtolower($label) . '...'); ?>">
        </div>
        <?php
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
        $filters = [];

        // Get actual column names from table (case-insensitive)
        $actual_columns = $this->get_actual_column_names($table_info);

        // Search fields come from the admin-managed configuration. Columns have
        // already been resolved against the live schema, so anything that was
        // renamed in the table has been dropped rather than queried.
        $search_config = $this->library_manager->get_search_config($table_info);
        $text_columns = [];

        foreach ($search_config as $field) {
            if (empty($field['enabled'])) {
                continue;
            }

            if ($field['control'] === 'text' || $field['control'] === 'exact') {
                $text_columns[] = $field['column'];
            } elseif ($field['control'] === 'keyword') {
                $text_columns = array_merge($text_columns, $field['targets']);
            }

            $value = $request->get_param($field['key']);

            if ($value === null || $value === '') {
                continue;
            }

            $value = sanitize_text_field((string) $value);

            if ($value === '') {
                continue;
            }

            $filter = $this->build_field_filter($field, $value);

            if ($filter !== null) {
                $filters[] = $filter;
            }
        }

        // DataTables global search, across every column the form can search.
        if (!empty($search_value)) {
            $search_value = sanitize_text_field((string) $search_value);
            $search_filters = [];

            foreach (array_unique($text_columns) as $column) {
                $search_filters[] = $this->filter_cmp($column, 'ilike', '*' . $search_value . '*');
            }

            if (!empty($search_filters)) {
                $filters[] = $this->filter_or($search_filters);
            }
        }

        // Build the query string once, so the count and the data query can never
        // drift apart.
        $count_query = $this->build_query_string($filters);
        $data_query = $this->build_query_string($filters, [
            'order'  => $this->resolve_sort_column($actual_columns, $search_config),
            'limit'  => $length,
            'offset' => $start
        ]);

        // Get total count (without filters)
        $total_count = $this->get_table_count($table_name);

        // Get filtered count (with filters applied)
        $filtered_count = empty($filters)
            ? $total_count
            : $this->get_filtered_count($table_name, $count_query);

        // Fetch data
        $data = $this->supabase->fetch_query($table_name, $data_query);

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
     * Decide which column to sort results by.
     *
     * Prefers title, then the first configured column-backed field, so a table
     * without a title column still returns results in a stable order.
     *
     * @param array $actual_columns
     * @param array $search_config
     * @return string|null
     */
    private function resolve_sort_column($actual_columns, $search_config) {
        $title_col = $this->find_column($actual_columns, 'title');

        if ($title_col) {
            return $title_col;
        }

        foreach ($search_config as $field) {
            if (!empty($field['enabled']) && $field['column'] !== '') {
                return $field['column'];
            }
        }

        return null;
    }

    /**
     * Turn one configured field and its submitted value into a filter node.
     *
     * @param array $field Configured search field
     * @param string $value Sanitised user input
     * @return array|null Filter node, or null if the control produces no filter
     */
    private function build_field_filter($field, $value) {
        switch ($field['control']) {
            case 'text':
                return $this->filter_cmp($field['column'], 'ilike', '*' . $value . '*');

            case 'exact':
            case 'dropdown':
                return $this->filter_cmp($field['column'], 'eq', $value);

            case 'checkbox':
                // true is a literal, not user input, so it must not be quoted.
                return $this->filter_cmp($field['column'], 'eq', 'true', true);

            case 'keyword':
                $keyword_filters = [];

                foreach ($field['targets'] as $target) {
                    $keyword_filters[] = $this->filter_cmp($target, 'ilike', '*' . $value . '*');
                }

                return empty($keyword_filters) ? null : $this->filter_or($keyword_filters);
        }

        return null;
    }

    /**
     * Build a comparison filter node.
     *
     * @param string $column Actual database column name
     * @param string $op PostgREST operator, e.g. "ilike" or "eq"
     * @param string $value Operand value
     * @param bool $raw True for literals such as "true" that must not be quoted
     * @return array
     */
    private function filter_cmp($column, $op, $value, $raw = false) {
        return [
            'type'   => 'cmp',
            'column' => $column,
            'op'     => $op,
            'value'  => $value,
            'raw'    => $raw
        ];
    }

    /**
     * Build an OR group from comparison nodes.
     *
     * @param array $children Comparison nodes
     * @return array
     */
    private function filter_or(array $children) {
        return [
            'type'     => 'or',
            'children' => $children
        ];
    }

    /**
     * Build a fully encoded PostgREST query string from structured filters.
     *
     * Filters are emitted as an ordered list rather than an associative array,
     * so two filters on the same column no longer overwrite each other.
     * Repeated "or" parameters are combined with AND by PostgREST.
     *
     * @param array $filters Filter nodes from filter_cmp()/filter_or()
     * @param array $options Optional 'order' column, 'limit', 'offset'
     * @return string Query string without the leading "?"
     */
    private function build_query_string(array $filters, array $options = []) {
        $parts = ['select=*'];

        foreach ($filters as $filter) {
            if (!isset($filter['type'])) {
                continue;
            }

            if ($filter['type'] === 'cmp') {
                $parts[] = $this->encode_key_identifier($filter['column']) . '='
                    . $this->encode_operand($filter['op'], $filter['value'], $filter['raw'], false);
                continue;
            }

            if ($filter['type'] === 'or' && !empty($filter['children'])) {
                $group = [];
                foreach ($filter['children'] as $child) {
                    $group[] = $this->encode_grouped_identifier($child['column']) . '.'
                        . $this->encode_operand($child['op'], $child['value'], $child['raw'], true);
                }
                $parts[] = 'or=(' . implode(',', $group) . ')';
            }
        }

        if (!empty($options['order'])) {
            $parts[] = 'order=' . rawurlencode($options['order']) . '.asc';
        }
        if (isset($options['limit'])) {
            $parts[] = 'limit=' . intval($options['limit']);
        }
        if (isset($options['offset'])) {
            $parts[] = 'offset=' . intval($options['offset']);
        }

        return implode('&', $parts);
    }

    /**
     * Encode an operator and its value for use in a query string.
     *
     * The quoting here is deliberately asymmetric, because PostgREST parses the
     * two positions with different parsers:
     *
     * - Inside an or(...) group it uses pLogicSingleVal, which strips double
     *   quotes and honours \" and \\ escaping. Quoting is required here: a raw
     *   comma or parenthesis in user input would otherwise close the group and
     *   change what the query means.
     * - At the top level it uses pSingleVal, which takes the remainder of the
     *   value verbatim and does NOT strip quotes. Quoting here would be a bug:
     *   the quote characters would become part of the search string.
     *
     * Wildcards survive either way, since PostgREST maps * to % after parsing.
     *
     * @param string $op PostgREST operator
     * @param string $value Operand value
     * @param bool $raw True for literals that must not be quoted
     * @param bool $grouped True when the operand sits inside an or(...) group
     * @return string
     */
    private function encode_operand($op, $value, $raw, $grouped) {
        if ($raw) {
            return $op . '.' . $value;
        }

        if (!$grouped) {
            return $op . '.' . rawurlencode($value);
        }

        return $op . '.%22' . rawurlencode($this->escape_quoted($value)) . '%22';
    }

    /**
     * Encode a column name for use as a query string key.
     *
     * A dot in a column name is read by PostgREST as a path separator, which
     * would misparse catalog columns such as "Pub. Year" and "Acq. Year", so
     * those are double-quoted. Names without a dot are left as-is to preserve
     * the existing behaviour for values such as "Physical Location".
     *
     * @param string $column
     * @return string
     */
    private function encode_key_identifier($column) {
        if (strpos($column, '.') !== false) {
            return $this->encode_grouped_identifier($column);
        }

        return rawurlencode($column);
    }

    /**
     * Encode a column name for use inside an or(...) group.
     *
     * Identifiers containing spaces or mixed case must be double-quoted, which
     * matters once columns like "Call Number" become searchable.
     *
     * @param string $column
     * @return string
     */
    private function encode_grouped_identifier($column) {
        return '%22' . rawurlencode($this->escape_quoted($column)) . '%22';
    }

    /**
     * Escape a value for placement inside a PostgREST double-quoted string.
     *
     * @param string $value
     * @return string
     */
    private function escape_quoted($value) {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $value);
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
     * Get filtered row count.
     *
     * @param string $table_name
     * @param string $query_string The same query string used for the data query
     * @return int
     */
    private function get_filtered_count($table_name, $query_string) {
        $count = $this->supabase->count_query($table_name, $query_string);

        if ($count === null) {
            // Fall back to the unfiltered total so paging still renders.
            return $this->get_table_count($table_name);
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

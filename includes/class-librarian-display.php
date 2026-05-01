<?php
/**
 * Librarian Display Class
 * Handles the librarian CRUD interface shortcode and REST endpoints
 */

if (!defined('ABSPATH')) {
    exit;
}

class Supabase_Librarian_Display {

    private $supabase;
    private $library_manager;

    public function __construct() {
        $this->supabase = new Supabase_Client();
        $this->library_manager = new Supabase_Library_Manager();
        
        // Register shortcode
        add_shortcode('supabase_librarian', [$this, 'render_librarian_interface']);

        // Register REST API endpoints
        add_action('rest_api_init', [$this, 'register_rest_routes']);
    }

    /**
     * Register REST API routes for librarian CRUD operations
     */
    public function register_rest_routes() {
        // Get records for DataTable
        register_rest_route('supabase/v1', '/librarian-data', [
            'methods' => 'GET',
            'callback' => [$this, 'handle_get_records'],
            'permission_callback' => [$this, 'check_librarian_permission']
        ]);

        // Create new record
        register_rest_route('supabase/v1', '/librarian-record', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_create_record'],
            'permission_callback' => [$this, 'check_librarian_permission']
        ]);

        // Update existing record
        register_rest_route('supabase/v1', '/librarian-record', [
            'methods' => 'PATCH',
            'callback' => [$this, 'handle_update_record'],
            'permission_callback' => [$this, 'check_librarian_permission']
        ]);

        // Delete record
        register_rest_route('supabase/v1', '/librarian-record', [
            'methods' => 'DELETE',
            'callback' => [$this, 'handle_delete_record'],
            'permission_callback' => [$this, 'check_librarian_permission']
        ]);
    }

    /**
     * Returns a WP_Error if the current user has exceeded 60 requests/minute, null otherwise.
     */
    private function check_rate_limit() {
        $key = 'supabase_rl_' . get_current_user_id();
        $count = (int) get_transient($key);
        if ($count >= 60) {
            return new WP_Error('rate_limited', 'Too many requests. Please wait a moment.', ['status' => 429]);
        }
        set_transient($key, $count + 1, 60);
        return null;
    }

    /**
     * Check if the current user has librarian permission
     * Used for REST API permission callbacks
     *
     * @return bool|WP_Error
     */
    public function check_librarian_permission() {
        if (!is_user_logged_in()) {
            return new WP_Error(
                'rest_forbidden',
                'You must be logged in to access the librarian interface.',
                ['status' => 401]
            );
        }

        if ($this->current_user_can_manage_library()) {
            return true;
        }

        return new WP_Error(
            'rest_forbidden',
            'You do not have permission to manage library records.',
            ['status' => 403]
        );
    }

    /**
     * Check if the current user can manage library records
     * Returns true if user is admin OR their email is in the librarian list
     *
     * @return bool
     */
    public function current_user_can_manage_library() {
        // Not logged in = no access
        if (!is_user_logged_in()) {
            return false;
        }

        // Administrators always have access
        if (current_user_can('manage_options')) {
            return true;
        }

        // Check if user's email is in the librarian list
        $current_user = wp_get_current_user();
        $user_email = strtolower($current_user->user_email);

        $librarian_emails = get_option('supabase_librarian_emails', []);

        if (is_array($librarian_emails) && in_array($user_email, $librarian_emails, true)) {
            return true;
        }

        return false;
    }

    /**
     * Render librarian interface shortcode
     *
     * @param array $atts Shortcode attributes
     * @return string HTML output
     */
    public function render_librarian_interface($atts = []) {
        // Check if user is logged in
        if (!is_user_logged_in()) {
            return '<div class="supabase-notice supabase-warning">Please <a href="' . esc_url(wp_login_url(get_permalink())) . '">log in</a> to access the librarian interface.</div>';
        }

        // Check if user has librarian permission
        if (!$this->current_user_can_manage_library()) {
            return '<div class="supabase-notice supabase-error">You do not have permission to access the librarian interface. Please contact an administrator if you believe this is an error.</div>';
        }

        // Check if library table is configured
        if (!$this->library_manager->has_library_table()) {
            return '<div class="supabase-notice supabase-error">Library table not configured. Please contact the site administrator.</div>';
        }

        // Validate library table
        $validation = $this->library_manager->validate_library_table();
        if (!$validation['valid']) {
            return '<div class="supabase-notice supabase-error">Library table configuration error: ' . esc_html($validation['error']) . '</div>';
        }

        // Enqueue assets
        $this->enqueue_librarian_assets();

        $table_info = $this->library_manager->get_library_table_info();
        $geographic_areas = $this->library_manager->get_geographic_areas();
        $columns = $table_info['columns'] ?? [];

        // Get primary key column (first column or 'id')
        $primary_key = 'id';
        if (!empty($columns)) {
            // Check if 'id' exists, otherwise use first column
            $column_names = array_column($columns, 'column_name');
            if (!in_array('id', $column_names) && !empty($column_names)) {
                $primary_key = $column_names[0];
            }
        }

        ob_start();
        ?>
        <div class="supabase-librarian-wrapper">
            <div class="librarian-header">
                <h2>Library Record Management</h2>
                <button type="button" id="add-record-btn" class="button button-primary">
                    <span class="dashicons dashicons-plus-alt2"></span> Add New Record
                </button>
            </div>

            <div class="librarian-table-container">
                <table id="librarian-records-table" class="display" style="width:100%">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Author</th>
                            <th>Publisher</th>
                            <th>Call Number</th>
                            <th>New</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    </tbody>
                </table>
            </div>
            <p class="librarian-note"><em>Note: Records are identified by Title. Ensure titles are unique to avoid updating multiple records.</em></p>

            <!-- Add/Edit Modal -->
            <div id="librarian-modal" class="librarian-modal" style="display:none;">
                <div class="librarian-modal-content">
                    <div class="librarian-modal-header">
                        <h3 id="modal-title">Add New Record</h3>
                        <span class="librarian-modal-close">&times;</span>
                    </div>
                    <form id="librarian-record-form">
                        <input type="hidden" id="record-id" name="record_id" value="">
                        <input type="hidden" id="form-mode" name="form_mode" value="create">

                        <div class="form-grid">
                            <!-- Title (Required) -->
                            <div class="form-field">
                                <label for="field-Title">Title <span class="required">*</span></label>
                                <input type="text" id="field-Title" name="Title" required>
                            </div>

                            <!-- Author -->
                            <div class="form-field">
                                <label for="field-Author">Author</label>
                                <input type="text" id="field-Author" name="Author">
                            </div>

                            <!-- Publisher -->
                            <div class="form-field">
                                <label for="field-Publisher">Publisher</label>
                                <input type="text" id="field-Publisher" name="Publisher">
                            </div>

                            <!-- Publisher Location -->
                            <div class="form-field">
                                <label for="field-Publisher-Location">Publisher Location</label>
                                <input type="text" id="field-Publisher-Location" name="Publisher Location">
                            </div>

                            <!-- Publication Year -->
                            <div class="form-field">
                                <label for="field-Pub-Year">Pub. Year</label>
                                <input type="number" id="field-Pub-Year" name="Pub. Year" placeholder="e.g., 1995">
                            </div>

                            <!-- Reprint Year -->
                            <div class="form-field">
                                <label for="field-Reprint-Year">Reprint Year</label>
                                <input type="text" id="field-Reprint-Year" name="Reprint Year">
                            </div>

                            <!-- Call Number -->
                            <div class="form-field">
                                <label for="field-Call-Number">Call Number</label>
                                <input type="text" id="field-Call-Number" name="Call Number">
                            </div>

                            <!-- ISBN -->
                            <div class="form-field">
                                <label for="field-ISBN">ISBN</label>
                                <input type="text" id="field-ISBN" name="ISBN">
                            </div>

                            <!-- Location -->
                            <div class="form-field">
                                <label for="field-Location">Location</label>
                                <input type="text" id="field-Location" name="Location">
                            </div>

                            <!-- Physical Location -->
                            <div class="form-field">
                                <label for="field-Physical-Location">Physical Location</label>
                                <input type="text" id="field-Physical-Location" name="Physical Location">
                            </div>

                            <!-- Media Type -->
                            <div class="form-field">
                                <label for="field-Media-Type">Media Type</label>
                                <input type="text" id="field-Media-Type" name="Media Type">
                            </div>

                            <!-- SPL Collection -->
                            <div class="form-field">
                                <label for="field-SPL-Collection">SPL Collection</label>
                                <input type="text" id="field-SPL-Collection" name="SPL Collection">
                            </div>

                            <!-- Acquisition Year -->
                            <div class="form-field">
                                <label for="field-Acq-Year">Acq. Year</label>
                                <input type="text" id="field-Acq-Year" name="Acq. Year">
                            </div>

                            <!-- Donor -->
                            <div class="form-field">
                                <label for="field-Donor">Donor</label>
                                <input type="text" id="field-Donor" name="Donor">
                            </div>

                            <!-- Link -->
                            <div class="form-field">
                                <label for="field-Link">Link URL</label>
                                <input type="url" id="field-Link" name="Link" placeholder="https://...">
                            </div>

                            <!-- New Item -->
                            <div class="form-field">
                                <label for="field-New">New Item</label>
                                <select id="field-New" name="New">
                                    <option value="">-- Select --</option>
                                    <option value="Yes">Yes</option>
                                    <option value="No">No</option>
                                </select>
                            </div>

                            <!-- Description -->
                            <div class="form-field full-width">
                                <label for="field-Description">Description</label>
                                <textarea id="field-Description" name="Description" rows="2"></textarea>
                            </div>

                            <!-- Librarian Notes -->
                            <div class="form-field full-width">
                                <label for="field-Librarian-Notes">Librarian Notes</label>
                                <textarea id="field-Librarian-Notes" name="Librarian Notes" rows="2"></textarea>
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="button" class="button button-secondary" id="cancel-form-btn">Cancel</button>
                            <button type="submit" class="button button-primary" id="submit-form-btn">Save Record</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Delete Confirmation Modal -->
            <div id="delete-confirm-modal" class="librarian-modal" style="display:none;">
                <div class="librarian-modal-content delete-confirm-content">
                    <div class="librarian-modal-header">
                        <h3>Confirm Delete</h3>
                        <span class="librarian-modal-close">&times;</span>
                    </div>
                    <div class="delete-confirm-body">
                        <p><strong>Are you sure you want to delete this record?</strong></p>
                        <p id="delete-record-title" class="delete-record-info"></p>
                        <p class="delete-warning">This action cannot be undone.</p>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="button button-secondary" id="cancel-delete-btn">Cancel</button>
                        <button type="button" class="button button-danger" id="confirm-delete-btn">Delete Record</button>
                    </div>
                </div>
            </div>

            <!-- Status Messages -->
            <div id="librarian-status-message" role="status" aria-live="polite"></div>
        </div>

        <script type="text/javascript">
        var supabaseLibrarian = {
            restUrl: '<?php echo esc_url(rest_url('supabase/v1/')); ?>',
            nonce: '<?php echo wp_create_nonce('wp_rest'); ?>',
            primaryKey: '<?php echo esc_js($primary_key); ?>'
        };
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Enqueue librarian assets
     */
    private function enqueue_librarian_assets() {
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

        // Librarian CSS
        wp_enqueue_style(
            'supabase-librarian',
            SUPABASE_ARMEMBER_PLUGIN_URL . 'public/css/librarian.css',
            [],
            SUPABASE_ARMEMBER_VERSION
        );

        // Librarian JS
        wp_enqueue_script(
            'supabase-librarian',
            SUPABASE_ARMEMBER_PLUGIN_URL . 'public/js/librarian.js',
            ['jquery', 'datatables'],
            SUPABASE_ARMEMBER_VERSION,
            true
        );
    }

    /**
     * Handle GET request for records (DataTable server-side processing)
     */
    public function handle_get_records($request) {
        $rate_limit_error = $this->check_rate_limit();
        if ($rate_limit_error) {
            return $rate_limit_error;
        }

        if (!$this->library_manager->has_library_table()) {
            return new WP_REST_Response([
                'error' => 'Library table not configured'
            ], 400);
        }

        $table_info = $this->library_manager->get_library_table_info();
        $table_name = $table_info['table_name'];

        // DataTables parameters
        $draw = intval($request->get_param('draw') ?? 1);
        $start = intval($request->get_param('start') ?? 0);
        $length = intval($request->get_param('length') ?? 25);

        // Search
        $search_param = $request->get_param('search');
        $search_value = '';
        if (is_array($search_param) && isset($search_param['value'])) {
            $search_value = sanitize_text_field($search_param['value']);
        }

        // Get total count
        $total_count = $table_info['row_count'] ?? 0;

        // Build query parameters
        $query_params = [
            'limit' => $length,
            'offset' => $start,
            'order' => 'Title.asc'
        ];

        // Build filters for search
        $filters = [];
        if (!empty($search_value)) {
            // Search in Title, Author, Publisher, Call Number
            $encoded_search = urlencode($search_value);
            $filters[] = 'or=(Title.ilike.*' . $encoded_search . '*,Author.ilike.*' . $encoded_search . '*,Publisher.ilike.*' . $encoded_search . '*)';
        }

        // Get filtered count
        $filtered_count = $this->get_filtered_count($table_name, $filters);

        // Apply search if provided
        if (!empty($search_value)) {
            // Search in Title, Author, Publisher, Call Number
            $encoded_search = urlencode($search_value);
            $query_params['or'] = '(Title.ilike.*' . $encoded_search . '*,Author.ilike.*' . $encoded_search . '*,Publisher.ilike.*' . $encoded_search . '*)';
        }

        // Fetch data
        $data = $this->supabase->fetch($table_name, $query_params);

        if ($data === false) {
            return new WP_REST_Response([
                'draw' => $draw,
                'recordsTotal' => 0,
                'recordsFiltered' => 0,
                'data' => [],
                'error' => 'Failed to fetch data'
            ], 200);
        }

        // Format data for DataTable - use exact column names from SGS Library Records
        $formatted_data = [];
        foreach ($data as $row) {
            $formatted_data[] = [
                'Title' => $row['Title'] ?? '',
                'Author' => $row['Author'] ?? '',
                'Publisher' => $row['Publisher'] ?? '',
                'Call Number' => $row['Call Number'] ?? '',
                'New' => $row['New'] ?? '',
                'full_data' => $row
            ];
        }

        return new WP_REST_Response([
            'draw' => $draw,
            'recordsTotal' => $total_count,
            'recordsFiltered' => $filtered_count,
            'data' => $formatted_data
        ], 200);
    }

    /**
     * Handle POST request to create a new record
     */
    public function handle_create_record($request) {
        $rate_limit_error = $this->check_rate_limit();
        if ($rate_limit_error) {
            return $rate_limit_error;
        }

        if (!$this->library_manager->has_library_table()) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Library table not configured'
            ], 400);
        }

        $table_info = $this->library_manager->get_library_table_info();
        $table_name = $table_info['table_name'];

        // Get and sanitize record data
        $record_data = $this->sanitize_record_data($request->get_json_params());

        // Check for Title (required)
        if (empty($record_data['Title'])) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Title is required'
            ], 400);
        }

        // Add timestamp
        $record_data['Last Updated Date'] = current_time('Y-m-d');


        // Insert into Supabase - URL encode table name for spaces
        $encoded_table = rawurlencode($table_name);
        $endpoint = "{$this->supabase->url}/rest/v1/{$encoded_table}";

        $response = wp_remote_post($endpoint, [
            'headers' => [
                'apikey' => $this->supabase->key,
                'Authorization' => "Bearer {$this->supabase->key}",
                'Content-Type' => 'application/json',
                'Prefer' => 'return=representation'
            ],
            'body' => json_encode($record_data),
            'timeout' => 15
        ]);

        if (is_wp_error($response)) {
            error_log('[Librarian Create] WP Error: ' . $response->get_error_message());
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Failed to create record: ' . $response->get_error_message()
            ], 500);
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);


        if ($status_code >= 200 && $status_code < 300) {
            $created_record = json_decode($body, true);
            return new WP_REST_Response([
                'success' => true,
                'message' => 'Record created successfully',
                'record' => $created_record[0] ?? $created_record
            ], 201);
        } else {
            $error_data = json_decode($body, true);
            $error_message = isset($error_data['message']) ? $error_data['message'] : $body;
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Failed to create record: ' . $error_message
            ], 200);
        }
    }

    /**
     * Handle PATCH request to update an existing record
     * Since SGS Library Records has no primary key, we use Title to identify records
     */
    public function handle_update_record($request) {
        $rate_limit_error = $this->check_rate_limit();
        if ($rate_limit_error) {
            return $rate_limit_error;
        }

        if (!$this->library_manager->has_library_table()) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Library table not configured'
            ], 400);
        }

        $table_info = $this->library_manager->get_library_table_info();
        $table_name = $table_info['table_name'];

        $params = $request->get_json_params();

        // Use original_title to find the record (since there's no ID)
        $original_title = sanitize_text_field($params['original_title'] ?? '');

        if (empty($original_title)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Original title is required to identify the record'
            ], 400);
        }

        // Get and sanitize record data
        $record_data = $this->sanitize_record_data($params);

        // Check for Title (required)
        if (empty($record_data['Title'])) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Title is required'
            ], 400);
        }

        // Add timestamp
        $record_data['Last Updated Date'] = current_time('Y-m-d');


        // Update in Supabase - use Title to identify the record
        $encoded_table = rawurlencode($table_name);
        $encoded_title = rawurlencode($original_title);
        $endpoint = "{$this->supabase->url}/rest/v1/{$encoded_table}?Title=eq.{$encoded_title}";

        $response = wp_remote_request($endpoint, [
            'method' => 'PATCH',
            'headers' => [
                'apikey' => $this->supabase->key,
                'Authorization' => "Bearer {$this->supabase->key}",
                'Content-Type' => 'application/json',
                'Prefer' => 'return=representation'
            ],
            'body' => json_encode($record_data),
            'timeout' => 15
        ]);

        if (is_wp_error($response)) {
            error_log('[Librarian Update] WP Error: ' . $response->get_error_message());
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Failed to update record: ' . $response->get_error_message()
            ], 500);
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);


        if ($status_code >= 200 && $status_code < 300) {
            $updated_record = json_decode($body, true);
            return new WP_REST_Response([
                'success' => true,
                'message' => 'Record updated successfully',
                'record' => $updated_record[0] ?? $updated_record
            ], 200);
        } else {
            $error_data = json_decode($body, true);
            $error_message = isset($error_data['message']) ? $error_data['message'] : $body;
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Failed to update record: ' . $error_message
            ], 200);
        }
    }

    /**
     * Handle DELETE request to delete a record
     * Since SGS Library Records has no primary key, we use Title to identify records
     */
    public function handle_delete_record($request) {
        $rate_limit_error = $this->check_rate_limit();
        if ($rate_limit_error) {
            return $rate_limit_error;
        }

        if (!$this->library_manager->has_library_table()) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Library table not configured'
            ], 400);
        }

        $table_info = $this->library_manager->get_library_table_info();
        $table_name = $table_info['table_name'];

        $params = $request->get_json_params();
        $title = sanitize_text_field($params['title'] ?? '');

        if (empty($title)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Title is required to identify the record'
            ], 400);
        }


        // Delete from Supabase - use Title to identify the record
        $encoded_table = rawurlencode($table_name);
        $encoded_title = rawurlencode($title);
        $endpoint = "{$this->supabase->url}/rest/v1/{$encoded_table}?Title=eq.{$encoded_title}";

        $response = wp_remote_request($endpoint, [
            'method' => 'DELETE',
            'headers' => [
                'apikey' => $this->supabase->key,
                'Authorization' => "Bearer {$this->supabase->key}"
            ],
            'timeout' => 15
        ]);

        if (is_wp_error($response)) {
            error_log('[Librarian Delete] WP Error: ' . $response->get_error_message());
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Failed to delete record: ' . $response->get_error_message()
            ], 500);
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);


        if ($status_code >= 200 && $status_code < 300) {
            return new WP_REST_Response([
                'success' => true,
                'message' => 'Record deleted successfully'
            ], 200);
        } else {
            $error_data = json_decode($body, true);
            $error_message = isset($error_data['message']) ? $error_data['message'] : $body;
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Failed to delete record: ' . $error_message
            ], 200);
        }
    }

    /**
     * Sanitize record data from request
     * Uses exact column names from the library table
     *
     * @param array $params Request parameters
     * @return array Sanitized data with correct column names
     */
    private function sanitize_record_data($params) {
        // Map form field names to exact Supabase column names
        // Only include fields that need special handling or have different names
        $field_map = [
            'Publisher Location' => 'Publisher Location',
            'Pub. Year' => 'Pub. Year',
            'Reprint Year' => 'Reprint Year',
            'Media Type' => 'Media Type',
            'Call Number' => 'Call Number',
            'Physical Location' => 'Physical Location',
            'SPL Collection' => 'SPL Collection',
            'Acq. Year' => 'Acq. Year',
            'Librarian Notes' => 'Librarian Notes',
            'Last Updated Date' => 'Last Updated Date'
        ];
        
        // Fields that match exactly can be added directly
        $direct_fields = ['Title', 'Author', 'Description', 'Publisher', 'Location', 'ISBN', 'Link', 'Donor', 'New', 'updatedByName'];

        $data = [];

        // Process mapped fields (those with special handling or different names)
        foreach ($field_map as $form_field => $db_column) {
            if (isset($params[$form_field]) && $params[$form_field] !== '') {
                $value = $params[$form_field];

                // Special handling for numeric fields
                if ($form_field === 'Pub. Year') {
                    $data[$db_column] = intval($value);
                } else {
                    $data[$db_column] = sanitize_text_field($value);
                }
            }
        }

        // Process direct fields (form field name matches database column name)
        foreach ($direct_fields as $field) {
            if (isset($params[$field]) && $params[$field] !== '') {
                $value = $params[$field];
                
                // Special handling for URL fields
                if ($field === 'Link') {
                    $data[$field] = esc_url_raw($value);
                } else {
                    $data[$field] = sanitize_text_field($value);
                }
            }
        }

        return $data;
    }

    /**
     * Find a column name case-insensitively
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
     * Get total row count for the table
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
     * @return int
     */
    private function get_filtered_count($table_name, $filters) {
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
                    $operatorAndValue = $parts[1]; // e.g., "ilike.*george*"
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

        $encoded_table = rawurlencode($table_name);
        $endpoint = $this->supabase->url . '/rest/v1/' . $encoded_table;
        if (!empty($query_string)) {
            $endpoint .= '?' . $query_string;
        }


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
            error_log('Error getting librarian filtered count: ' . $response->get_error_message());
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
}


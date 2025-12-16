<?php
/**
 * Supabase API Client
 * Handles all communication with Supabase REST API
 */

if (!defined('ABSPATH')) {
    exit;
}

class Supabase_Client {
    public $url;
    public $key;

    public function __construct() {
        $this->url = get_option('supabase_project_url');
        $this->key = get_option('supabase_service_key');
    }

    /**
     * Check if Supabase is configured
     */
    public function is_configured() {
        return !empty($this->url) && !empty($this->key);
    }

    /**
     * Insert or update data in a table
     *
     * @param string $table Table name
     * @param array $data Data to insert/update
     * @param string $conflict_column Column to use for conflict resolution (default: wordpress_user_id)
     * @return array|false Response data or false on error
     */
    public function upsert($table, $data, $conflict_column = 'wordpress_user_id') {
        if (!$this->is_configured()) {
            $this->log_error('Supabase not configured');
            return false;
        }

        // Add on_conflict parameter to tell Supabase which column to check for duplicates
        $endpoint = "{$this->url}/rest/v1/{$table}?on_conflict={$conflict_column}";

        $response = wp_remote_post($endpoint, [
            'headers' => [
                'apikey' => $this->key,
                'Authorization' => "Bearer {$this->key}",
                'Content-Type' => 'application/json',
                'Prefer' => 'resolution=merge-duplicates'
            ],
            'body' => json_encode($data),
            'timeout' => 15
        ]);

        return $this->handle_response($response, 'upsert', $table);
    }

    /**
     * Fetch data from a table
     *
     * @param string $table Table name
     * @param array $params Query parameters (select, filters, etc.)
     * @return array|false Response data or false on error
     */
    public function fetch($table, $params = []) {
        if (!$this->is_configured()) {
            $this->log_error('Supabase not configured');
            return false;
        }

        // Build query string - special handling for 'or' parameter
        // PostgREST requires specific encoding for complex queries
        $query_params = array_merge(['select' => '*'], $params);

        // Extract 'or' parameter if present for special handling
        $or_param = null;
        if (isset($query_params['or'])) {
            $or_param = $query_params['or'];
            unset($query_params['or']);
        }

        // Build regular query string
        $query_string = http_build_query($query_params);

        // Add 'or' parameter manually with minimal encoding
        if ($or_param !== null) {
            // Only encode the search values, not the PostgREST syntax characters
            $or_param_encoded = str_replace(' ', '%20', $or_param);
            $query_string .= ($query_string ? '&' : '') . 'or=' . $or_param_encoded;
        }

        $endpoint = "{$this->url}/rest/v1/{$table}?{$query_string}";

        // Debug logging - always log when there's a search to help diagnose issues
        if ($or_param !== null) {
            error_log('[Supabase Fetch] Endpoint with search: ' . $endpoint);
        }

        $response = wp_remote_get($endpoint, [
            'headers' => [
                'apikey' => $this->key,
                'Authorization' => "Bearer {$this->key}"
            ],
            'timeout' => 15
        ]);

        return $this->handle_response($response, 'fetch', $table);
    }

    /**
     * Update data in a table
     *
     * @param string $table Table name
     * @param array $data Data to update
     * @param array $filters WHERE conditions (e.g., ['wordpress_user_id' => 'eq.123'])
     * @return array|false Response data or false on error
     */
    public function update($table, $data, $filters = []) {
        if (!$this->is_configured()) {
            $this->log_error('Supabase not configured');
            return false;
        }

        // Build query string with filters
        $query_string = http_build_query($filters);
        $endpoint = "{$this->url}/rest/v1/{$table}?{$query_string}";

        $response = wp_remote_request($endpoint, [
            'method' => 'PATCH',
            'headers' => [
                'apikey' => $this->key,
                'Authorization' => "Bearer {$this->key}",
                'Content-Type' => 'application/json',
                'Prefer' => 'return=representation'
            ],
            'body' => json_encode($data),
            'timeout' => 15
        ]);

        return $this->handle_response($response, 'update', $table);
    }

    /**
     * Delete data from a table
     *
     * @param string $table Table name
     * @param array $filters WHERE conditions
     * @return array|false Response data or false on error
     */
    public function delete($table, $filters = []) {
        if (!$this->is_configured()) {
            $this->log_error('Supabase not configured');
            return false;
        }

        $query_string = http_build_query($filters);
        $endpoint = "{$this->url}/rest/v1/{$table}?{$query_string}";

        $response = wp_remote_request($endpoint, [
            'method' => 'DELETE',
            'headers' => [
                'apikey' => $this->key,
                'Authorization' => "Bearer {$this->key}"
            ],
            'timeout' => 15
        ]);

        return $this->handle_response($response, 'delete', $table);
    }

    /**
     * Handle API response
     */
    private function handle_response($response, $operation, $table) {
        if (is_wp_error($response)) {
            $error_msg = "Supabase {$operation} error on {$table}: " . $response->get_error_message();
            $this->log_error($error_msg);
            // Also log to PHP error log for immediate visibility
            error_log('[Supabase Error] ' . $error_msg);
            return false;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        // Supabase returns 200-299 for success
        if ($status_code >= 200 && $status_code < 300) {
            $data = json_decode($body, true);

            // Log successful operation
            $this->log_success("Supabase {$operation} successful on {$table}");

            return $data;
        } else {
            $error_msg = "Supabase {$operation} failed on {$table}. Status: {$status_code}, Body: {$body}";
            $this->log_error($error_msg);
            // Also log to PHP error log for immediate visibility
            error_log('[Supabase Error] ' . $error_msg);
            return false;
        }
    }

    /**
     * Log error message
     */
    private function log_error($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Supabase ARMember Sync] ERROR: ' . $message);
        }

        // Store recent errors for admin dashboard
        $errors = get_option('supabase_recent_errors', []);
        $errors[] = [
            'message' => $message,
            'time' => current_time('mysql')
        ];

        // Keep only last 10 errors
        $errors = array_slice($errors, -10);
        update_option('supabase_recent_errors', $errors);
    }

    /**
     * Log success message
     */
    private function log_success($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Supabase ARMember Sync] SUCCESS: ' . $message);
        }

        // Update last sync time
        update_option('supabase_last_sync', current_time('mysql'));
    }

    /**
     * Fetch table schema information from Supabase
     * Gets list of tables with their columns and data types
     *
     * @param string $schema Schema name (default: 'public')
     * @return array|false Array of tables with metadata or false on error
     */
    public function fetch_schema_tables($schema = 'public') {
        if (!$this->is_configured()) {
            $this->log_error('Supabase not configured');
            return false;
        }

        // Use PostgREST root endpoint to get available tables
        // This queries the OpenAPI specification which lists all exposed tables
        $root_endpoint = "{$this->url}/rest/v1/";

        $root_response = wp_remote_get($root_endpoint, [
            'headers' => [
                'apikey' => $this->key,
                'Authorization' => "Bearer {$this->key}",
                'Accept' => 'application/openapi+json'
            ],
            'timeout' => 30
        ]);

        if (is_wp_error($root_response)) {
            $this->log_error('Failed to fetch schema from PostgREST: ' . $root_response->get_error_message());
            return false;
        }

        $status_code = wp_remote_retrieve_response_code($root_response);
        $response_body = wp_remote_retrieve_body($root_response);

        if ($status_code < 200 || $status_code >= 300) {
            $this->log_error("Failed to fetch schema. Status: {$status_code}, Body: {$response_body}");
            return false;
        }

        $openapi_spec = json_decode($response_body, true);

        if (!$openapi_spec || !isset($openapi_spec['paths'])) {
            $this->log_error("Invalid OpenAPI response. Body: {$response_body}");
            return false;
        }

        // Extract table names from paths
        $table_names = [];
        foreach ($openapi_spec['paths'] as $path => $details) {
            // Paths are like "/{table_name}"
            $table_name = trim($path, '/');

            // Skip non-table paths (those with slashes or special characters)
            if (!empty($table_name) && strpos($table_name, '/') === false && strpos($table_name, '{') === false) {
                $table_names[] = $table_name;
            }
        }

        if (empty($table_names)) {
            $this->log_error('No tables found in OpenAPI spec');
            return [];
        }

        // For each table, fetch column information and row count
        $schema_info = [];
        foreach ($table_names as $table_name) {
            // Get sample row to determine columns
            $sample_data = $this->fetch($table_name, ['limit' => 1]);

            $columns = [];
            if ($sample_data && !empty($sample_data[0])) {
                foreach ($sample_data[0] as $col_name => $value) {
                    $columns[] = [
                        'column_name' => $col_name,
                        'data_type' => $this->guess_data_type($value),
                        'is_nullable' => 'UNKNOWN'
                    ];
                }
            }

            $schema_info[] = [
                'table_name' => $table_name,
                'columns' => $columns,
                'row_count' => $this->fetch_row_count($table_name)
            ];
        }

        $this->log_success('Schema sync completed. Found ' . count($schema_info) . ' tables.');
        return $schema_info;
    }

    /**
     * Guess data type from a value
     */
    private function guess_data_type($value) {
        if (is_null($value)) {
            return 'text';
        }
        if (is_bool($value)) {
            return 'boolean';
        }
        if (is_int($value)) {
            return 'integer';
        }
        if (is_float($value)) {
            return 'numeric';
        }
        if (is_array($value)) {
            return 'jsonb';
        }
        return 'text';
    }

    /**
     * Fetch column information for a specific table
     *
     * @param string $table_name Table name
     * @param string $schema Schema name
     * @return array|false Array of columns with metadata or false on error
     */
    private function fetch_table_columns($table_name, $schema = 'public') {
        $columns_query = "table_schema=eq.{$schema}&table_name=eq.{$table_name}&select=column_name,data_type,is_nullable";
        $columns_endpoint = "{$this->url}/rest/v1/information_schema.columns?{$columns_query}";

        $columns_response = wp_remote_get($columns_endpoint, [
            'headers' => [
                'apikey' => $this->key,
                'Authorization' => "Bearer {$this->key}"
            ],
            'timeout' => 15
        ]);

        if (is_wp_error($columns_response)) {
            return false;
        }

        $status_code = wp_remote_retrieve_response_code($columns_response);
        if ($status_code < 200 || $status_code >= 300) {
            return false;
        }

        return json_decode(wp_remote_retrieve_body($columns_response), true);
    }

    /**
     * Fetch row count for a table
     *
     * @param string $table Table name
     * @return int Row count or 0 on error
     */
    private function fetch_row_count($table) {
        $count_endpoint = "{$this->url}/rest/v1/{$table}?select=count";

        $response = wp_remote_get($count_endpoint, [
            'headers' => [
                'apikey' => $this->key,
                'Authorization' => "Bearer {$this->key}",
                'Prefer' => 'count=exact'
            ],
            'timeout' => 15
        ]);

        if (is_wp_error($response)) {
            return 0;
        }

        // The count is in the Content-Range header
        $headers = wp_remote_retrieve_headers($response);
        if (isset($headers['content-range'])) {
            // Format: "0-24/25" or "*/25"
            $range = $headers['content-range'];
            if (preg_match('/\/(\d+)$/', $range, $matches)) {
                return (int) $matches[1];
            }
        }

        return 0;
    }
}

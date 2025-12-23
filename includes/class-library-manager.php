<?php
/**
 * Library Manager Class
 * Handles library-specific logic and settings
 */

if (!defined('ABSPATH')) {
    exit;
}

class Supabase_Library_Manager {

    private $supabase;

    public function __construct() {
        $this->supabase = new Supabase_Client();
    }

    /**
     * Get the designated library table name
     *
     * @return string|null Library table name or null if not set
     */
    public function get_library_table() {
        return get_option('supabase_library_table', null);
    }

    /**
     * Check if a library table is designated
     *
     * @return bool
     */
    public function has_library_table() {
        $library_table = $this->get_library_table();
        return !empty($library_table);
    }

    /**
     * Get library table information from schema
     *
     * @return array|null Table information or null if not found
     */
    public function get_library_table_info() {
        $library_table_name = $this->get_library_table();

        if (!$library_table_name) {
            return null;
        }

        $tables = get_option('supabase_schema_tables', []);

        foreach ($tables as $table) {
            if ($table['table_name'] === $library_table_name) {
                return $table;
            }
        }

        return null;
    }

    /**
     * Get library-specific settings
     *
     * @return array
     */
    public function get_library_settings() {
        return [
            'geographic_areas' => $this->get_geographic_areas(),
            'field_mappings' => $this->get_field_mappings(),
            'display_fields' => $this->get_display_fields(),
            'search_fields' => $this->get_search_fields()
        ];
    }

    /**
     * Get geographic areas for dropdown
     *
     * @return array
     */
    public function get_geographic_areas() {
        $default_areas = [
            'North America',
            'South America',
            'Europe',
            'Asia',
            'Africa',
            'Australia',
            'Antarctica'
        ];

        $custom_areas = get_option('supabase_library_geographic_areas', []);

        return !empty($custom_areas) ? $custom_areas : $default_areas;
    }

    /**
     * Get field mappings for library columns
     * Maps standard library fields to actual database columns
     * Updated for SGS Library Records schema
     *
     * @return array
     */
    public function get_field_mappings() {
        // SGS Library Records actual column names
        $default_mappings = [
            'title' => 'Title',
            'author' => 'Author',
            'description' => 'Description',
            'publisher' => 'Publisher',
            'publisher_location' => 'Publisher Location',
            'publication_date' => 'Pub. Year',
            'reprint_date' => 'Reprint Year',
            'isbn' => 'ISBN',
            'call_number' => 'Call Number',
            'spl_collection' => 'SPL Collection',
            'link_url' => 'Link',
            'acquisition_date' => 'Acq. Year',
            'donor_or_purchase' => 'Donor',
            'librarian_notes' => 'Librarian Notes',
            'updated' => 'Last Updated Date',
            'updated_by' => 'updatedByName',
            'new' => 'New',
            'location' => 'Location',
            'media_type' => 'Media Type',
            'physical_location' => 'Physical Location'
        ];

        $custom_mappings = get_option('supabase_library_field_mappings', []);

        return !empty($custom_mappings) ? array_merge($default_mappings, $custom_mappings) : $default_mappings;
    }

    /**
     * Get display fields (shown in detail view)
     *
     * @return array
     */
    public function get_display_fields() {
        $default_fields = [
            'title',
            'author',
            'description',
            'publisher',
            'publisher_location',
            'publication_date',
            'reprint_date',
            'isbn',
            'call_number',
            'spl_collection',
            'link_url',
            'acquisition_date',
            'donor_or_purchase',
            'librarian_notes',
            'updated',
            'updated_by',
            'new'
        ];

        $custom_fields = get_option('supabase_library_display_fields', []);

        return !empty($custom_fields) ? $custom_fields : $default_fields;
    }

    /**
     * Get search fields (shown in search form)
     *
     * @return array
     */
    public function get_search_fields() {
        $default_fields = [
            'title',
            'author',
            'keyword',
            'geographic_area',
            'new'
        ];

        $custom_fields = get_option('supabase_library_search_fields', []);

        return !empty($custom_fields) ? $custom_fields : $default_fields;
    }

    /**
     * Update library settings
     *
     * @param array $settings
     * @return bool
     */
    public function update_library_settings($settings) {
        $updated = true;

        if (isset($settings['geographic_areas'])) {
            $updated = $updated && update_option('supabase_library_geographic_areas', $settings['geographic_areas']);
        }

        if (isset($settings['field_mappings'])) {
            $updated = $updated && update_option('supabase_library_field_mappings', $settings['field_mappings']);
        }

        if (isset($settings['display_fields'])) {
            $updated = $updated && update_option('supabase_library_display_fields', $settings['display_fields']);
        }

        if (isset($settings['search_fields'])) {
            $updated = $updated && update_option('supabase_library_search_fields', $settings['search_fields']);
        }

        return $updated;
    }

    /**
     * Get actual database column name for a library field
     *
     * @param string $field_name Standard library field name
     * @return string|null Actual database column name or null
     */
    public function get_db_column($field_name) {
        $mappings = $this->get_field_mappings();
        return isset($mappings[$field_name]) ? $mappings[$field_name] : null;
    }

    /**
     * Validate library table has required columns
     *
     * @return array Array with 'valid' boolean and 'missing_fields' array
     */
    public function validate_library_table() {
        $table_info = $this->get_library_table_info();

        if (!$table_info) {
            return [
                'valid' => false,
                'error' => 'Library table not found or not set',
                'missing_fields' => []
            ];
        }

        $required_fields = ['title', 'author'];
        $table_columns = array_column($table_info['columns'], 'column_name');
        $missing_fields = [];

        foreach ($required_fields as $field) {
            if (!$this->find_column_case_insensitive($table_columns, $field)) {
                $missing_fields[] = $field;
            }
        }

        return [
            'valid' => empty($missing_fields),
            'missing_fields' => $missing_fields,
            'error' => empty($missing_fields) ? null : 'Missing required fields: ' . implode(', ', $missing_fields)
        ];
    }

    /**
     * Find a column name case-insensitively
     *
     * @param array $columns Available column names
     * @param string $search Column name to search for
     * @return string|null Actual column name or null
     */
    private function find_column_case_insensitive($columns, $search) {
        $search_lower = strtolower($search);

        foreach ($columns as $column) {
            if (strtolower($column) === $search_lower) {
                return $column;
            }
        }

        return null;
    }
}

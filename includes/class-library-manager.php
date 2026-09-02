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
     * Option name holding the configurable search form definition.
     */
    const SEARCH_CONFIG_OPTION = 'supabase_library_search_config';

    /**
     * Control types a search field may use.
     */
    const SEARCH_CONTROLS = ['text', 'exact', 'dropdown', 'checkbox', 'keyword'];

    /**
     * Get the search form configuration, validated against the live schema.
     *
     * Falls back to a default that reproduces the previously hardcoded form, so
     * the catalog behaves identically until someone edits it in the admin.
     *
     * @param array|null $table_info Defaults to the designated library table
     * @return array List of field definitions
     */
    public function get_search_config($table_info = null) {
        if ($table_info === null) {
            $table_info = $this->get_library_table_info();
        }

        $columns = $this->get_column_names($table_info);

        if (empty($columns)) {
            return [];
        }

        $stored = get_option(self::SEARCH_CONFIG_OPTION, []);

        if (empty($stored) || !is_array($stored)) {
            return $this->get_default_search_config($columns);
        }

        return $this->sanitize_search_config($stored, $columns);
    }

    /**
     * Save the search form configuration.
     *
     * @param array $config
     * @return bool
     */
    public function update_search_config($config) {
        $columns = $this->get_column_names($this->get_library_table_info());
        return update_option(self::SEARCH_CONFIG_OPTION, $this->sanitize_search_config($config, $columns));
    }

    /**
     * List configured columns that no longer exist in the table.
     *
     * A catalog re-upload that renames a column would otherwise fail silently,
     * with the affected search field simply disappearing from the form.
     *
     * @return array Missing column names
     */
    public function get_search_config_issues() {
        $stored = get_option(self::SEARCH_CONFIG_OPTION, []);

        if (empty($stored) || !is_array($stored)) {
            return [];
        }

        $columns = $this->get_column_names($this->get_library_table_info());

        if (empty($columns)) {
            return [];
        }

        $missing = [];

        foreach ($stored as $field) {
            $column = isset($field['column']) ? $field['column'] : '';

            if ($column !== '' && !$this->find_column_case_insensitive($columns, $column)) {
                $missing[] = $column;
            }

            if (isset($field['targets']) && is_array($field['targets'])) {
                foreach ($field['targets'] as $target) {
                    if (!$this->find_column_case_insensitive($columns, $target)) {
                        $missing[] = $target;
                    }
                }
            }
        }

        return array_values(array_unique($missing));
    }

    /**
     * Default configuration, matching the form the catalog shipped with.
     *
     * @param array $columns Live column names
     * @return array
     */
    private function get_default_search_config($columns) {
        $defaults = [
            ['column' => 'Title', 'label' => 'Title', 'control' => 'text'],
            ['column' => 'Author', 'label' => 'Author', 'control' => 'text'],
            [
                'column'  => '',
                'key'     => 'keyword',
                'label'   => 'Key Word',
                'control' => 'keyword',
                'targets' => ['Title', 'Author', 'Description', 'Publisher']
            ],
            ['column' => 'Physical Location', 'label' => 'Physical Location', 'control' => 'dropdown'],
            ['column' => 'New', 'label' => 'New Items Only', 'control' => 'checkbox'],
        ];

        $config = [];

        foreach ($defaults as $field) {
            $field['enabled'] = true;
            $config[] = $field;
        }

        $config = $this->sanitize_search_config($config, $columns);

        // Carry over the hand-typed location list from the previous settings
        // screen so existing dropdown values are not lost.
        $legacy_options = get_option('supabase_library_geographic_areas', []);

        if (!empty($legacy_options) && is_array($legacy_options)) {
            foreach ($config as &$field) {
                if ($field['control'] === 'dropdown' && empty($field['options'])) {
                    $field['options'] = array_values($legacy_options);
                    break;
                }
            }
            unset($field);
        }

        return $config;
    }

    /**
     * Validate a configuration against the live schema.
     *
     * Fields whose column no longer exists are dropped rather than queried,
     * which keeps a renamed column from producing a broken request.
     *
     * @param array $config
     * @param array $columns Live column names
     * @return array
     */
    private function sanitize_search_config($config, $columns) {
        if (!is_array($config) || empty($columns)) {
            return [];
        }

        $sanitized = [];
        $used_keys = [];

        foreach ($config as $field) {
            if (!is_array($field)) {
                continue;
            }

            $control = isset($field['control']) ? (string) $field['control'] : 'text';
            if (!in_array($control, self::SEARCH_CONTROLS, true)) {
                $control = 'text';
            }

            $column = '';
            if ($control !== 'keyword') {
                $requested = isset($field['column']) ? (string) $field['column'] : '';
                $column = $this->find_column_case_insensitive($columns, $requested);

                if (!$column) {
                    continue;
                }
            }

            // Keyword is a virtual field spanning several columns rather than
            // one of its own.
            $targets = [];
            if ($control === 'keyword') {
                $requested_targets = isset($field['targets']) && is_array($field['targets'])
                    ? $field['targets']
                    : [];

                foreach ($requested_targets as $target) {
                    $resolved = $this->find_column_case_insensitive($columns, (string) $target);
                    if ($resolved && !in_array($resolved, $targets, true)) {
                        $targets[] = $resolved;
                    }
                }

                if (empty($targets)) {
                    continue;
                }
            }

            $key = isset($field['key']) && $field['key'] !== ''
                ? $this->make_field_key((string) $field['key'])
                : $this->make_field_key($column);

            if ($key === '') {
                continue;
            }

            // Two columns can slugify to the same key; keep both addressable.
            $base_key = $key;
            $suffix = 2;
            while (isset($used_keys[$key])) {
                $key = $base_key . '_' . $suffix;
                $suffix++;
            }
            $used_keys[$key] = true;

            $options = [];
            if ($control === 'dropdown' && isset($field['options']) && is_array($field['options'])) {
                foreach ($field['options'] as $option) {
                    $option = trim((string) $option);
                    if ($option !== '' && !in_array($option, $options, true)) {
                        $options[] = $option;
                    }
                }
            }

            $label = isset($field['label']) && trim((string) $field['label']) !== ''
                ? trim((string) $field['label'])
                : ($column !== '' ? $column : ucfirst($key));

            $sanitized[] = [
                'key'     => $key,
                'column'  => $column,
                'label'   => $label,
                'control' => $control,
                'enabled' => !empty($field['enabled']),
                'targets' => $targets,
                'options' => $options,
                'order'   => isset($field['order']) ? intval($field['order']) : count($sanitized),
            ];
        }

        usort($sanitized, function ($a, $b) {
            return $a['order'] <=> $b['order'];
        });

        return $sanitized;
    }

    /**
     * Build a stable REST parameter name from a column name.
     *
     * The slugs match the parameter names the catalog already used, so
     * "Physical Location" stays physical_location.
     *
     * @param string $column
     * @return string
     */
    private function make_field_key($column) {
        $key = strtolower(preg_replace('/[^A-Za-z0-9]+/', '_', (string) $column));
        return trim($key, '_');
    }

    /**
     * Extract column names from a table definition.
     *
     * @param array|null $table_info
     * @return array
     */
    public function get_column_names($table_info) {
        if (!$table_info || !isset($table_info['columns']) || !is_array($table_info['columns'])) {
            return [];
        }

        $columns = [];
        foreach ($table_info['columns'] as $column) {
            if (isset($column['column_name']) && $column['column_name'] !== '') {
                $columns[] = $column['column_name'];
            }
        }

        return $columns;
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

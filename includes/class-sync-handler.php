<?php
/**
 * Sync Handler
 * Handles syncing ARMember user data to Supabase
 */

if (!defined('ABSPATH')) {
    exit;
}

class Supabase_Sync_Handler {

    private $supabase;
    
    const MAX_SYNC_LOGS = 500; // Maximum number of sync log entries to keep

    public function __construct() {
        $this->supabase = new Supabase_Client();

        // Hook into ARMember events
        add_action('arm_after_user_plan_change', [$this, 'sync_user_on_plan_change'], 10, 2);
        add_action('arm_after_user_register', [$this, 'sync_user_on_register'], 10, 1);
        add_action('profile_update', [$this, 'sync_user_on_profile_update'], 10, 1);
    }

    /**
     * Log sync activity to database
     */
    private function log_sync_activity($message) {
        $logs = get_option('supabase_sync_logs', []);
        
        $log_entry = [
            'timestamp' => current_time('mysql'),
            'message' => '[ARMember Sync] ' . $message
        ];
        
        // Add to beginning of array
        array_unshift($logs, $log_entry);
        
        // Keep only the most recent entries
        $logs = array_slice($logs, 0, self::MAX_SYNC_LOGS);
        
        update_option('supabase_sync_logs', $logs);
        
        // Also log to error_log if WP_DEBUG_LOG is enabled (for compatibility)
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log($log_entry['message']);
        }
    }

    /**
     * Sync user when they change plan
     */
    public function sync_user_on_plan_change($user_id, $plan_id) {
        $this->sync_user_to_supabase($user_id);
    }

    /**
     * Sync user when they register
     */
    public function sync_user_on_register($user_id) {
        $this->sync_user_to_supabase($user_id);
    }

    /**
     * Sync user when profile is updated
     */
    public function sync_user_on_profile_update($user_id) {
        $this->sync_user_to_supabase($user_id);
    }

    /**
     * Sync user data to Supabase
     */
    public function sync_user_to_supabase($user_id) {
        $user = get_userdata($user_id);

        if (!$user) {
            return false;
        }

        // Get ARMember plan information
        $user_plans = get_user_meta($user_id, 'arm_user_plan_ids', true);
        
        // Log sync activity
        $this->log_sync_activity('User ID: ' . $user_id . ', Plan IDs: ' . json_encode($user_plans));
        
        $has_database_access = $this->user_has_paid_plan($user_plans);

        // Get membership plan name
        $membership_plan = $this->get_user_plan_name($user_plans);
        
        // Log final plan name
        $this->log_sync_activity('User ID: ' . $user_id . ', Membership Plan: ' . $membership_plan);

        // Prepare user data for Supabase
        $user_data = [
            'wordpress_user_id' => $user_id,
            'email' => $user->user_email,
            'membership_plan' => $membership_plan,
            'has_database_access' => $has_database_access,
            'synced_at' => current_time('mysql'),
            'display_name' => $user->display_name
        ];

        // Sync to Supabase
        $result = $this->supabase->upsert('wp_users', $user_data);

        // Update user meta for access control
        if ($has_database_access) {
            update_user_meta($user_id, 'supabase_access', true);
        } else {
            delete_user_meta($user_id, 'supabase_access');
        }

        return $result !== false;
    }

    /**
     * Get user's membership plan name
     */
    private function get_user_plan_name($user_plans) {
        if (empty($user_plans)) {
            return 'Free';
        }

        if (!is_array($user_plans)) {
            $user_plans = [$user_plans];
        }

        // Try multiple methods to get plan name
        foreach ($user_plans as $plan_id) {
            $plan_name = null;
            
            // Ensure plan_id is numeric (handle string IDs)
            $plan_id = is_numeric($plan_id) ? intval($plan_id) : $plan_id;

            // Method 1: Query ARMember database tables directly (most reliable)
            $plan_name = $this->get_plan_name_from_database($plan_id);
            
            // Method 2: Try ARMember global settings
            if (empty($plan_name)) {
                global $arm_global_settings, $arm_subscription_plans;
                
                if (isset($arm_global_settings) && is_object($arm_global_settings)) {
                    if (method_exists($arm_global_settings, 'arm_get_all_membership_plans')) {
                        $all_plans = $arm_global_settings->arm_get_all_membership_plans();
                        if (is_array($all_plans) && isset($all_plans[$plan_id])) {
                            $plan_name = isset($all_plans[$plan_id]['arm_subscription_plan_name']) 
                                ? $all_plans[$plan_id]['arm_subscription_plan_name'] 
                                : null;
                        }
                    }
                }
                
                // Try alternative global variable
                if (empty($plan_name) && isset($arm_subscription_plans) && is_array($arm_subscription_plans)) {
                    if (isset($arm_subscription_plans[$plan_id])) {
                        $plan_name = isset($arm_subscription_plans[$plan_id]['arm_subscription_plan_name']) 
                            ? $arm_subscription_plans[$plan_id]['arm_subscription_plan_name'] 
                            : null;
                    }
                }
            }

            // Method 3: Try WordPress post (if plans are stored as posts)
            if (empty($plan_name)) {
                $plan = get_post($plan_id);
                if ($plan) {
                    // Check if it's an ARMember plan post type
                    if ($plan->post_type === 'arm_membership' || $plan->post_type === 'arm_subscription_plan') {
                        $plan_name = $plan->post_title;
                    }
                }
            }

            // Method 4: Try ARMember plan meta fields
            if (empty($plan_name)) {
                $plan_name = get_post_meta($plan_id, 'arm_subscription_plan_name', true);
                if (empty($plan_name)) {
                    $plan_name = get_post_meta($plan_id, 'arm_plan_name', true);
                }
            }

            // Method 5: Try direct post title as fallback (any post type)
            if (empty($plan_name)) {
                $plan = get_post($plan_id);
                if ($plan) {
                    $plan_name = $plan->post_title;
                }
            }

            // If we found a plan name, return it
            if (!empty($plan_name)) {
                $this->log_sync_activity('Found plan name "' . $plan_name . '" for plan ID: ' . $plan_id);
                return $plan_name;
            }
        }

        // Log debug information if we couldn't find the plan
        $this->log_sync_activity('Could not find plan name for plan IDs: ' . json_encode($user_plans));
        $this->log_sync_activity('Attempted to get post for plan ID: ' . (isset($user_plans[0]) ? $user_plans[0] : 'N/A'));
        if (isset($user_plans[0])) {
            $test_plan = get_post($user_plans[0]);
            if ($test_plan) {
                $this->log_sync_activity('Post found - Type: ' . $test_plan->post_type . ', Title: ' . $test_plan->post_title);
            } else {
                $this->log_sync_activity('No post found for plan ID: ' . $user_plans[0]);
            }
        }
        
        return 'Unknown';
    }

    /**
     * Get plan name from ARMember database tables
     * ARMember stores plans in custom database tables
     */
    private function get_plan_name_from_database($plan_id) {
        global $wpdb;
        
        // Ensure plan_id is numeric
        if (!is_numeric($plan_id)) {
            return null;
        }
        
        $plan_id = intval($plan_id);
        
        // Common ARMember table names and column structures
        // Try different table/column combinations
        $table_queries = [
            // Table: wp_arm_membership_plan
            [
                'table' => $wpdb->prefix . 'arm_membership_plan',
                'id_col' => 'arm_plan_id',
                'name_col' => 'arm_subscription_plan_name'
            ],
            [
                'table' => $wpdb->prefix . 'arm_membership_plan',
                'id_col' => 'arm_subscription_plan_id',
                'name_col' => 'arm_subscription_plan_name'
            ],
            [
                'table' => $wpdb->prefix . 'arm_membership_plan',
                'id_col' => 'arm_plan_id',
                'name_col' => 'arm_plan_name'
            ],
            // Table: wp_arm_subscription_plans
            [
                'table' => $wpdb->prefix . 'arm_subscription_plans',
                'id_col' => 'arm_plan_id',
                'name_col' => 'arm_subscription_plan_name'
            ],
            [
                'table' => $wpdb->prefix . 'arm_subscription_plans',
                'id_col' => 'arm_subscription_plan_id',
                'name_col' => 'arm_subscription_plan_name'
            ],
            // Table: wp_arm_plan
            [
                'table' => $wpdb->prefix . 'arm_plan',
                'id_col' => 'arm_plan_id',
                'name_col' => 'arm_subscription_plan_name'
            ],
        ];
        
        foreach ($table_queries as $query_info) {
            // Check if table exists
            $table_name = $query_info['table'];
            $table_exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
                DB_NAME,
                $table_name
            ));
            
            if ($table_exists) {
                // Try to get plan name
                $result = $wpdb->get_var($wpdb->prepare(
                    "SELECT `{$query_info['name_col']}` FROM `{$table_name}` WHERE `{$query_info['id_col']}` = %d LIMIT 1",
                    $plan_id
                ));
                
                if (!empty($result)) {
                    $this->log_sync_activity('Found plan name "' . $result . '" from database table ' . $table_name . ' for plan ID: ' . $plan_id);
                    return $result;
                }
            }
        }
        
        // If no table worked, try to find any ARMember table with plan data
        $arm_tables = $wpdb->get_results($wpdb->prepare(
            "SELECT table_name FROM information_schema.tables 
             WHERE table_schema = %s 
             AND table_name LIKE %s",
            DB_NAME,
            $wpdb->prefix . 'arm%'
        ));
        
        foreach ($arm_tables as $table) {
            $table_name = $table->table_name;
            
            // Get column names for this table
            $columns = $wpdb->get_results($wpdb->prepare(
                "SELECT column_name FROM information_schema.columns 
                 WHERE table_schema = %s AND table_name = %s",
                DB_NAME,
                $table_name
            ));
            
            $column_names = array_map(function($col) { return $col->column_name; }, $columns);
            
            // Look for ID and name columns
            $id_col = null;
            $name_col = null;
            
            foreach ($column_names as $col) {
                if (stripos($col, 'plan_id') !== false || stripos($col, 'id') !== false) {
                    $id_col = $col;
                }
                if (stripos($col, 'plan_name') !== false || stripos($col, 'name') !== false) {
                    $name_col = $col;
                }
            }
            
            if ($id_col && $name_col) {
                $result = $wpdb->get_var($wpdb->prepare(
                    "SELECT `{$name_col}` FROM `{$table_name}` WHERE `{$id_col}` = %d LIMIT 1",
                    $plan_id
                ));
                
                if (!empty($result)) {
                    $this->log_sync_activity('Found plan name "' . $result . '" from database table ' . $table_name . ' (auto-detected columns) for plan ID: ' . $plan_id);
                    return $result;
                }
            }
        }
        
        return null;
    }

    /**
     * Check if user has a paid plan
     */
    private function user_has_paid_plan($user_plans) {
        if (empty($user_plans)) {
            return false;
        }

        $paid_plans = get_option('supabase_access_plans', '');
        $paid_plans_array = array_map('trim', explode(',', $paid_plans));
        
        // Normalize plan names for comparison (case-insensitive)
        $paid_plans_array = array_map('strtolower', $paid_plans_array);

        if (!is_array($user_plans)) {
            $user_plans = [$user_plans];
        }

        foreach ($user_plans as $plan_id) {
            // Get plan name using the same method as get_user_plan_name
            $plan_name = $this->get_plan_name_from_database($plan_id);
            
            // Fallback to other methods if database query didn't work
            if (empty($plan_name)) {
                global $arm_global_settings;
                if (isset($arm_global_settings) && is_object($arm_global_settings)) {
                    if (method_exists($arm_global_settings, 'arm_get_all_membership_plans')) {
                        $all_plans = $arm_global_settings->arm_get_all_membership_plans();
                        if (is_array($all_plans) && isset($all_plans[$plan_id])) {
                            $plan_name = isset($all_plans[$plan_id]['arm_subscription_plan_name']) 
                                ? $all_plans[$plan_id]['arm_subscription_plan_name'] 
                                : null;
                        }
                    }
                }
            }
            
            // Try WordPress post as last resort
            if (empty($plan_name)) {
                $plan = get_post($plan_id);
                if ($plan) {
                    $plan_name = $plan->post_title;
                }
            }
            
            // Check if plan name matches any paid plan (case-insensitive)
            if (!empty($plan_name) && in_array(strtolower(trim($plan_name)), $paid_plans_array)) {
                return true;
            }
        }

        return false;
    }
}


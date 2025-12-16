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

    public function __construct() {
        $this->supabase = new Supabase_Client();

        // Hook into ARMember events
        add_action('arm_after_user_plan_change', [$this, 'sync_user_on_plan_change'], 10, 2);
        add_action('arm_after_user_register', [$this, 'sync_user_on_register'], 10, 1);
        add_action('profile_update', [$this, 'sync_user_on_profile_update'], 10, 1);
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
        $has_database_access = $this->user_has_paid_plan($user_plans);

        // Get membership plan name
        $membership_plan = $this->get_user_plan_name($user_plans);

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

        // Get the first plan's name
        foreach ($user_plans as $plan_id) {
            $plan = get_post($plan_id);
            if ($plan) {
                return $plan->post_title;
            }
        }

        return 'Unknown';
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

        if (!is_array($user_plans)) {
            $user_plans = [$user_plans];
        }

        foreach ($user_plans as $plan_id) {
            $plan = get_post($plan_id);
            if ($plan && in_array($plan->post_title, $paid_plans_array)) {
                return true;
            }
        }

        return false;
    }
}


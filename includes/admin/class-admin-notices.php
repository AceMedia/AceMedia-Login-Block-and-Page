<?php
/**
 * Admin Notices Handler
 *
 * @package AceLoginBlock\Admin
 */

namespace AceLoginBlock\Admin;

if (!defined('ABSPATH')) {
    exit;
}

class Admin_Notices {
    /**
     * Initialize the class
     */
    public function __construct() {
        add_action('admin_notices', [$this, 'display_2fa_setup_notice']);
        add_action('admin_init', [$this, 'handle_clear_2fa_logs']);
    }

    /**
     * Display 2FA setup notice for users who need to set it up
     */
    public function display_2fa_setup_notice() {
        $user_id = get_current_user_id();
        
        // Don't show notice if user doesn't need 2FA setup
        if (!$this->user_needs_2fa_setup($user_id)) {
            return;
        }

        // Show notice template
        include ACEMEDIA_LOGIN_BLOCK_PATH . 'includes/templates/admin/notices/2fa-setup.php';
    }

    /**
     * Check if user needs 2FA setup
     */
    private function user_needs_2fa_setup($user_id) {
        $user = get_userdata($user_id);
        if (!$user) {
            return false;
        }

        foreach ($user->roles as $role) {
            $role_2fa_required = (bool) get_option("acemedia_2fa_enabled_{$role}", false);
            if ($role_2fa_required) {
                $user_2fa_enabled = (bool) get_user_meta($user_id, '_acemedia_2fa_enabled', true);
                $user_2fa_setup_complete = (bool) get_user_meta($user_id, '_acemedia_2fa_setup_complete', true);

                if (!$user_2fa_setup_complete || !$user_2fa_enabled) {
                    return true;
                }
            }
        }

        return false;
    }



    /**
     * Handle clearing 2FA logs
     */
    public function handle_clear_2fa_logs() {
        if (
            isset($_POST['clear_2fa_logs']) &&
            isset($_POST['clear_2fa_logs_nonce']) &&
            wp_verify_nonce($_POST['clear_2fa_logs_nonce'], 'clear_2fa_logs') &&
            current_user_can('manage_options')
        ) {
            $users = get_users();
            foreach ($users as $user) {
                delete_user_meta($user->ID, '_acemedia_2fa_logs');
            }

            add_settings_error(
                'acemedia_login_block_messages',
                'logs-cleared',
                __('All 2FA logs have been cleared.', 'acemedia-login-block'),
                'updated'
            );
        }
    }

    /**
     * Add an admin notice
     */
    public function add_notice($message, $type = 'success', $is_dismissible = true) {
        $class = sprintf('notice notice-%s%s', 
            esc_attr($type),
            $is_dismissible ? ' is-dismissible' : ''
        );
        
        printf(
            '<div class="%1$s"><p>%2$s</p></div>',
            esc_attr($class),
            wp_kses_post($message)
        );
    }
}

// Initialize the admin notices handler
new Admin_Notices();
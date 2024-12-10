<?php
namespace AceLoginBlock\Auth;

if (!defined('ABSPATH')) {
    exit;
}

class Authentication {
    /**
     * Initialize the class
     */
    public function __construct() {
        add_action('wp_login', [$this, 'login_redirect'], 10, 2);
        add_action('wp_logout', [$this, 'logout_redirect']);
        add_action('init', [$this, 'handle_logout']);
        add_filter('login_title', [$this, 'login_block_login_title']);
    }

    /**
     * Handle the login redirect after a user logs in
     */
    public function login_redirect($user_login, $user) {
        // Default redirect URL if none is specified for the user’s role
        $redirect_url = isset($_POST['redirect_to']) ? esc_url(sanitize_text_field(wp_unslash($_POST['redirect_to']))) : admin_url();

        // Check for role-specific redirects
        foreach ($user->roles as $role) {
            $role_redirect_key = "acemedia_login_block_redirect_{$role}"; // Option key for the redirect URL
            $role_redirect_url = get_option($role_redirect_key);

            // If a role-specific redirect URL is found, set it as the redirect URL
            if (!empty($role_redirect_url)) {
                $redirect_url = esc_url($role_redirect_url);
                break; // Stop after the first matching role redirect
            }
        }

        // Perform the redirect
        wp_safe_redirect($redirect_url);
        exit;
    }

    /**
     * Adjust the logout redirect function
     */
    public function logout_redirect() {
        $custom_page_id = get_option('acemedia_login_block_custom_page', 0);
        if (!$custom_page_id) {
            // Custom login page not set, do nothing
            return;
        }

        $redirect_url = home_url(); // Change this to your desired logout redirect URL
        wp_safe_redirect($redirect_url);
        exit();
    }

    /**
     * Handle the logout process
     */
    public function handle_logout() {
        $custom_page_id = get_option('acemedia_login_block_custom_page', 0);
        if (!$custom_page_id) {
            // Custom login page not set, do nothing
            return;
        }

        if (isset($_GET['action']) && $_GET['action'] === 'logout') {
            // Verify the nonce
            if (isset($_GET['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'log-out')) {
                // Perform the logout
                wp_logout();

                // Redirect to the desired URL after logout
                $redirect_url = home_url(); // Change this to your desired logout redirect URL
                wp_safe_redirect($redirect_url);
                exit();
            }
        }
    }

    /**
     * Customize the login page title.
     */
    public function login_block_login_title($title) {
        return __('Login', 'acemedia-login-block');
    }
}

// Initialize the class
new Authentication();
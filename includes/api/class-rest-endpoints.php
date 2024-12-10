<?php
/**
 * REST Endpoints Handler
 *
 * @package AceLoginBlock
 */

namespace AceLoginBlock\API;

if (!defined('ABSPATH')) {
    exit;
}

class Rest_Endpoints {
    /**
     * Initialize the class
     */
    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Register REST API routes
     */
    public function register_routes() {
        register_rest_route('acemedia/v1', '/verify-2fa', [
            'methods' => 'POST',
            'callback' => [$this, 'verify_2fa'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('acemedia/v1', '/check-2fa', [
            'methods' => 'POST',
            'callback' => [$this, 'check_2fa'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Verify 2FA code
     */
    public function verify_2fa($request) {
        $username = sanitize_text_field($request->get_param('username'));
        $code = sanitize_text_field($request->get_param('code'));

        $user = get_user_by('login', $username);
        if (!$user) {
            return new \WP_Error('invalid_username', __('Invalid username.', 'acemedia-login-block'), ['status' => 404]);
        }

        $two_factor = new \AceLoginBlock\Auth\Two_Factor();
        $result = $two_factor->verify_auth_app_code($user->ID, $code);

        if ($result['success']) {
            return rest_ensure_response(['success' => true]);
        } else {
            return rest_ensure_response(['success' => false, 'message' => $result['message']]);
        }
    }

    /**
     * Check 2FA status
     */
    public function check_2fa($request) {
        $username = sanitize_text_field($request->get_param('username'));

        $user = get_user_by('login', $username);
        if (!$user) {
            return new \WP_Error('invalid_username', __('Invalid username.', 'acemedia-login-block'), ['status' => 404]);
        }

        $two_factor = new \AceLoginBlock\Auth\Two_Factor();
        $status = $two_factor->check_2fa_status($user->ID);

        return rest_ensure_response($status);
    }
}

// Initialize the REST endpoints
new Rest_Endpoints();
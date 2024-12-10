<?php
namespace AceLoginBlock\Auth;

if (!defined('ABSPATH')) {
    exit;
}

class Login_Handler {
    /**
     * Initialize the class
     */
    public function __construct() {
        add_action('login_init', [$this, 'load_custom_page_template']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_login_script']);
    }

    /**
     * Replace wp-login.php with the selected custom page content and template.
     */
    public function load_custom_page_template() {
        $custom_page_id = get_option('acemedia_login_block_custom_page');

        // Check if we're on wp-login.php and a custom page is set
        if (isset($_SERVER['REQUEST_URI'])) {
            $request_uri = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI']));
            if (strpos($request_uri, 'wp-login.php') !== false && $custom_page_id) {
                // If the request method is POST, let WordPress handle the login process
                if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
                    return; // Let WordPress handle the login submission
                }

                // Fetch the template for the chosen page
                $page_template = get_page_template_slug($custom_page_id);

                // If no custom template is found, use the default page template
                if (!empty($page_template) && locate_template($page_template)) {
                    $template_path = locate_template($page_template);
                } else {
                    $template_path = get_page_template();
                }

                if (!empty($template_path)) {
                    // Set up the global post data for the custom page
                    global $wp_query, $post;
                    $post = get_post($custom_page_id);
                    setup_postdata($post);

                    // Load the custom page template
                    include $template_path;

                    // Prevent further execution after the template is loaded
                    exit;
                } else {
                    wp_die(esc_html__('Template not found for the login page.', 'acemedia-login-block'));
                }
            }
        }
    }

    /**
     * Enqueue styles and scripts for the custom login page.
     */
    public function enqueue_login_script() {
        if (has_block('acemedia/login-block')) {
            wp_enqueue_script(
                'acemedia-login-frontend',
                plugin_dir_url(__FILE__) . '../../build/acemedia-login.js',
                [],
                filemtime(plugin_dir_path(__FILE__) . '../../build/acemedia-login.js'),
                true
            );

            // Fetch the current value of the 2FA setting
            $is_2fa_enabled = (bool) get_option('acemedia_2fa_enabled', false);

            wp_localize_script('acemedia-login-frontend', 'aceLoginBlock', [
                'loginUrl' => site_url('wp-login.php'),
                'userRoles' => wp_get_current_user()->roles,
                'redirectUrl' => site_url('/wp-admin'),
                'is2FAEnabled' => $is_2fa_enabled,
                'twoFALabel' => __('Enter 2FA Code:', 'acemedia-login-block'),
                'twoFAPlaceholder' => __('2FA Code', 'acemedia-login-block'),
                'submit2FA' => __('Verify', 'acemedia-login-block'),
                'verify2FAEndpoint' => rest_url('acemedia/v1/verify-2fa'),
                'check2FAEndpoint' => rest_url('acemedia/v1/check-2fa'),
                'nonce' => wp_create_nonce('wp_rest'),
            ]);
        }
    }
}

// Initialize the class
new Login_Handler();
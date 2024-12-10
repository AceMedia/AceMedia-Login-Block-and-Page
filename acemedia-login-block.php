<?php
/**
 * Plugin Name:       Ace Login Block
 * Description:       A block to replace the WordPress login page using a custom page and its template from the site editor.
 * Requires at least: 6.6
 * Tested up to:      6.7
 * Requires PHP:      7.2
 * Version:           0.426.0
 * Author:            Shane Rounce
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       acemedia-login-block
 * GitHub URI:        https://github.com/AceMedia/AceMedia-Login-Block-and-Page
 * @package           AceLoginBlock
 *
 * The uncompressed source code, including JavaScript and CSS files, can be found at the GitHub repository: https://github.com/AceMedia/AceMedia-Login-Block-and-Page.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}




require 'vendor/autoload.php';



// Define plugin constants
define('ACEMEDIA_LOGIN_BLOCK_VERSION', '0.426.0');
define('ACEMEDIA_LOGIN_BLOCK_PATH', plugin_dir_path(__FILE__));
define('ACEMEDIA_LOGIN_BLOCK_URL', plugin_dir_url(__FILE__));

// Load the class autoloader
require_once ACEMEDIA_LOGIN_BLOCK_PATH . 'includes/class-loader.php';

// Admin includes
require_once ACEMEDIA_LOGIN_BLOCK_PATH . 'includes/admin/class-settings-page.php';
require_once ACEMEDIA_LOGIN_BLOCK_PATH . 'includes/admin/class-admin-notices.php';
require_once ACEMEDIA_LOGIN_BLOCK_PATH . 'includes/admin/class-user-profile.php';

// Auth includes
require_once ACEMEDIA_LOGIN_BLOCK_PATH . 'includes/auth/class-login-handler.php';
require_once ACEMEDIA_LOGIN_BLOCK_PATH . 'includes/auth/class-two-factor.php';
require_once ACEMEDIA_LOGIN_BLOCK_PATH . 'includes/auth/class-backup-codes.php';
require_once ACEMEDIA_LOGIN_BLOCK_PATH . 'includes/auth/class-authentication.php';

// Block includes
require_once ACEMEDIA_LOGIN_BLOCK_PATH . 'includes/blocks/class-login-block.php';
require_once ACEMEDIA_LOGIN_BLOCK_PATH . 'includes/blocks/class-username-block.php';
require_once ACEMEDIA_LOGIN_BLOCK_PATH . 'includes/blocks/class-password-block.php';
require_once ACEMEDIA_LOGIN_BLOCK_PATH . 'includes/blocks/class-remember-me-block.php';

// API includes
require_once ACEMEDIA_LOGIN_BLOCK_PATH . 'includes/api/class-rest-endpoints.php';

// Utility includes
require_once ACEMEDIA_LOGIN_BLOCK_PATH . 'includes/utils/class-encryption.php';
require_once ACEMEDIA_LOGIN_BLOCK_PATH . 'includes/utils/class-logging.php';
require_once ACEMEDIA_LOGIN_BLOCK_PATH . 'includes/utils/class-security.php';

// Frontend includes
require_once ACEMEDIA_LOGIN_BLOCK_PATH . 'includes/frontend/class-assets.php';
require_once ACEMEDIA_LOGIN_BLOCK_PATH . 'includes/frontend/class-login-page.php';



/**
 * Registers the block and its settings.
 */
function acemedia_create_block_login_block_init() {
    register_block_type( __DIR__ . '/build/login-block' );
    register_block_type( __DIR__ . '/build/username-block' );
    register_block_type( __DIR__ . '/build/password-block' );
}
add_action( 'init', 'acemedia_create_block_login_block_init' );



// Global array to store admin pages
global $acemedia_admin_pages;
$acemedia_admin_pages = [];

// Function to clean title by removing <span> tags and their content
function acemedia_remove_spans_and_content($title) {
    // Remove <span> tags and their content
    $cleaned_title = preg_replace('/<span[^>]*>.*?<\/span>/i', '', $title);
    $cleaned_title = wp_strip_all_tags($cleaned_title);

    // Trim the title to remove any leading/trailing whitespace
    return trim($cleaned_title);
}

// In the capture function
function acemedia_capture_admin_pages() {
    global $acemedia_admin_pages;

    // Use the current menu items
    global $menu, $submenu;

    // Iterate over the top-level menu
    foreach ($menu as $menu_item) {
        // Get the menu slug
        $slug = isset($menu_item[2]) ? $menu_item[2] : '';

        // Add to the admin pages array if it has a capability and a title
        if (!empty($slug) && isset($menu_item[0]) && isset($menu_item[1])) {
            $title = acemedia_remove_spans_and_content($menu_item[0]); // Remove <span> tags and their content

            // Only add if title doesn't contain "separator" followed by any digits
            if (!preg_match('/separator/i', trim($slug))) { // Use preg_match for regex check
                $acemedia_admin_pages[$slug] = [
                    'title' => $title,
                    'capability' => $menu_item[1],
                ];
            }
        }
    }

    // Iterate over submenus to capture additional pages
    foreach ($submenu as $parent_slug => $sub_menu) {
        foreach ($sub_menu as $sub_menu_item) {
            // Add sub-menu items
            $slug = isset($sub_menu_item[2]) ? $sub_menu_item[2] : '';
            if (!empty($slug) && isset($sub_menu_item[0]) && isset($sub_menu_item[1])) {
                $title = acemedia_remove_spans_and_content($sub_menu_item[0]); // Remove <span> tags and their content

            // Only add if title doesn't contain "separator" followed by any digits
            if (!preg_match('/separator/i', trim($slug))) {  // Use preg_match for regex check
                    $acemedia_admin_pages[$slug] = [
                        'title' => $title,
                        'capability' => $sub_menu_item[1],
                    ];
                }
            }
        }
    }
}

add_action('admin_menu', 'acemedia_capture_admin_pages');

// Render Login settings page









/**
 * Enqueue assets for the blocks.
 */
function acemedia_login_block_enqueue_assets() {
    wp_enqueue_script(
        'acemedia-login-toggle',
        plugin_dir_url( __FILE__ ) . 'build/login-toggle.js',
        array(),
        '1.0.0',
        true
    );

    wp_enqueue_script(
        'acemedia-login-block-editor',
        plugin_dir_url( __FILE__ ) . 'build/login-block.js',
        array( 'wp-blocks', 'wp-element', 'wp-editor' ),
        filemtime( plugin_dir_path( __FILE__ ) . 'build/login-block.js' ),
        true
    );


    wp_localize_script('acemedia-login-block-editor', 'aceLoginBlock', array(
        'loginUrl' => site_url('wp-login.php'),
        'redirectUrl' => site_url('/wp-admin'),
        'loginNonce' => wp_create_nonce('login_nonce'),
        'userRoles' => wp_get_current_user()->roles, // Pass the current user roles
    ));

    wp_enqueue_script(
        'acemedia-username-block-editor',
        plugin_dir_url( __FILE__ ) . 'build/username-block.js',
        array( 'wp-blocks', 'wp-element', 'wp-editor' ),
        filemtime( plugin_dir_path( __FILE__ ) . 'build/username-block.js' ),
        true
    );

    wp_enqueue_script(
        'acemedia-password-block-editor',
        plugin_dir_url( __FILE__ ) . 'build/password-block.js',
        array( 'wp-blocks', 'wp-element', 'wp-editor' ),
        filemtime( plugin_dir_path( __FILE__ ) . 'build/password-block.js' ),
        true
    );

    wp_enqueue_script(
        'acemedia-remember-me-block-editor',
        plugin_dir_url( __FILE__ ) . 'build/remember-me-block.js',
        array( 'wp-blocks', 'wp-element', 'wp-editor' ),
        filemtime( plugin_dir_path( __FILE__ ) . 'build/remember-me-block.js' ),
        true
    );
/*
    wp_enqueue_style(
        'acemedia-login-block-editor-style',
        plugin_dir_url( __FILE__ ) . 'build/login-block.css',
        array(),
        filemtime( plugin_dir_path( __FILE__ ) . 'build/login-block.css' )
    );
    */


}
add_action( 'enqueue_block_editor_assets', 'acemedia_login_block_enqueue_assets' );

function acemedia_register_username_block() {
    register_block_type('acemedia/username-block', array(
        'render_callback' => 'acemedia_render_username_block',
        'attributes' => array(
            'label' => array(
                'type' => 'string',
                'default' => __('Username', 'acemedia-login-block'),
            ),
            'placeholder' => array(
                'type' => 'string',
                'default' => __('Username', 'acemedia-login-block'),
            ),
        ),
    ));
}
add_action('init', 'acemedia_register_username_block');

/**
 * Renders the Ace Username Block.
 *
 * @param array $attributes Block attributes.
 * @return string The HTML output for the username block.
 */
function acemedia_render_username_block($attributes) {
    $placeholder = isset($attributes['placeholder']) ? sanitize_text_field($attributes['placeholder']) : __('Username', 'acemedia-login-block');
    $placeholder = esc_attr($placeholder);
    return '<input type="text" id="log" name="log" placeholder="' . $placeholder . '" required />';
}

/**
 * Registers the Ace Password Block.
 */
function acemedia_register_password_block() {
    register_block_type('acemedia/password-block', array(
        'render_callback' => 'acemedia_render_password_block',
        'attributes' => array(
            'label' => array(
                'type' => 'string',
                'default' => __('Password', 'acemedia-login-block'),
            ),
            'showPassword' => array(
                'type' => 'boolean',
                'default' => false,
            ),
        ),
    ));
}
add_action('init', 'acemedia_register_password_block');

/**
 * Renders the Ace Password Block.
 *
 * @param array $attributes Block attributes.
 * @return string The HTML output for the password block.
 */
function acemedia_render_password_block($attributes) {
    $placeholder = isset($attributes['placeholder']) ? sanitize_text_field($attributes['placeholder']) : __('Password', 'acemedia-login-block');
    $placeholder = esc_attr($placeholder);
    $show_password = !empty($attributes['showPassword']) ? 'true' : 'false';
    $login_nonce = wp_create_nonce('login_action');
    $html = '<input type="password" id="pwd" name="pwd" placeholder="' . $placeholder . '" required />';
    $html .= '<input type="hidden" name="login_nonce" value="' . esc_attr($login_nonce) . '" />';
    if ($attributes['showPassword']) {
        $html .= '<span style="cursor:pointer" data-show-password="' . esc_attr($show_password) . '">' . esc_html__('Show Password', 'acemedia-login-block') . '</span>';
    }
    return $html;
}



/**
 * Registers the Ace Remember Me Block.
 */
function acemedia_register_remember_me_block() {
    register_block_type('acemedia/remember-me-block', array(
        'render_callback' => 'acemedia_render_remember_me_block',
        'attributes' => array(
            'label' => array(
                'type' => 'string',
                'default' => __('Remember Me', 'acemedia-login-block'),
            ),
            'checked' => array(
                'type' => 'boolean',
                'default' => false,
            ),
        ),
    ));
}
add_action('init', 'acemedia_register_remember_me_block');

/**
 * Renders the Ace Remember Me Block.
 */
function acemedia_render_remember_me_block($attributes) {
    $label = esc_html($attributes['label']);
    $checked = $attributes['checked'] ? 'checked' : '';
    return '<p><label><input type="checkbox" name="rememberme" ' . $checked . ' /> ' . $label . '</label></p>';
}



function acemedia_register_2fa_block() {
    register_block_type('acemedia/2fa-block', array(
        'render_callback' => 'acemedia_render_2fa_block',
        'attributes' => array(
            'label' => array(
                'type' => 'string',
                'default' => __('Authentication Code', 'acemedia-login-block'),
            ),
            'placeholder' => array(
                'type' => 'string',
                'default' => __('Enter Code', 'acemedia-login-block'),
            ),
        ),
    ));
}
add_action('init', 'acemedia_register_2fa_block');

function acemedia_render_2fa_block($attributes) {
    $placeholder = esc_attr($attributes['placeholder'] ?? 'Enter Code');
    return '<input type="text" name="2fa_code" placeholder="' . $placeholder . '" required />';
}



// Register setting for selecting the 2FA method
register_setting('acemedia_login_block_options_group', 'acemedia_2fa_method', [
    'type' => 'string',
    'description' => __('2FA Method', 'acemedia-login-block'),
    'sanitize_callback' => 'sanitize_text_field',
    'default' => 'email', // Default to email-based 2FA
]);




function wp_encrypt($data) {
    $key = wp_salt('auth');
    return sodium_crypto_secretbox(
        json_encode($data),
        random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
        sodium_crypto_generichash($key, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES)
    );
}

function wp_decrypt($encrypted) {
    $key = wp_salt('auth');
    return json_decode(sodium_crypto_secretbox_open(
        $encrypted,
        random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
        sodium_crypto_generichash($key, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES)
    ), true);
}




function is_login_page() {
    return in_array($GLOBALS['pagenow'], ['wp-login.php', 'wp-register.php']);
}

function acemedia_enqueue_admin_login_script() {
    if (has_block('acemedia/login-block') || is_login_page()) {
        wp_enqueue_script(
            'acemedia-login-frontend',
            plugin_dir_url(__FILE__) . 'build/acemedia-login.js',
            [],
            filemtime(plugin_dir_path(__FILE__) . 'build/acemedia-login.js'),
            true
        );

        // Fetch the current value of the 2FA setting
        $is_2fa_enabled = (bool) get_option('acemedia_2fa_enabled', false);

        wp_localize_script('acemedia-login-frontend', 'aceLoginBlock', [
            'loginUrl' => site_url('wp-login.php'),
            'userRoles' => wp_get_current_user()->roles,
            'redirectUrl' => site_url('/wp-admin'),
            'is2FAEnabled' => $is_2fa_enabled,
            'twoFALabel' => __('Enter 2FA Code', 'acemedia-login-block'),
            'twoFAPlaceholder' => __('2FA Code', 'acemedia-login-block'),
            'submit2FA' => __('Verify 2FA', 'acemedia-login-block'),
            'verify2FAEndpoint' => rest_url('acemedia/v1/verify-2fa'),
            'check2FAEndpoint' => rest_url('acemedia/v1/check-2fa'),
            'nonce' => wp_create_nonce('wp_rest'),
        ]);
    }
}
add_action('login_enqueue_scripts', 'acemedia_enqueue_admin_login_script');


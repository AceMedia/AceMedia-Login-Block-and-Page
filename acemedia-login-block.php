<?php
/**
 * Plugin Name:       Ace Login Block
 * Description:       A block to replace the WordPress login page using a custom page and its template from the site editor.
 * Requires at least: 6.6
 * Tested up to:      7.1
 * Requires PHP:      8.2
 * Version:           0.430.0
 * Author:            Shane Rounce
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       acemedia-login-block
 * GitHub URI:        https://github.com/AceMedia/WordPress-Login-Block-and-Page
 * @package           AceLoginBlock
 *
 * The uncompressed source code, including JavaScript and CSS files, can be found at the GitHub repository: https://github.com/AceMedia/WordPress-Login-Block-and-Page.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}




/**
 * Keep public requests available after a runtime downgrade or incomplete upload.
 * Never silently remove the second-factor policy when dependencies cannot load.
 * Restore the supported runtime/package using hosting access to recover login.
 */
function acemedia_login_block_runtime_error() {
    return new WP_Error('acemedia_auth_unavailable', __('Login is temporarily unavailable because Ace Login Block requires PHP 8.2 or later, sodium and its installed dependencies. Please contact the site administrator.', 'acemedia-login-block'));
}

function acemedia_login_block_runtime_notice() {
    if (current_user_can('activate_plugins')) {
        echo '<div class="notice notice-error"><p>' . esc_html(acemedia_login_block_runtime_error()->get_error_message()) . '</p></div>';
    }
}

/**
 * Optional features.
 *
 * Passkeys need PHP's OpenSSL extension, which WordPress cannot stand in for. When it is
 * missing, only passkeys are switched off and everything else keeps working. An account
 * that depends on a passkey is refused with a clear message instead of having its second
 * factor quietly dropped.
 *
 * @return bool
 */
function acemedia_login_block_openssl_available() {
    $available = extension_loaded('openssl') && function_exists('openssl_verify');
    return (bool) apply_filters('acemedia_login_block_openssl_available', $available);
}

function acemedia_login_block_passkeys_available() {
    return acemedia_login_block_openssl_available() && class_exists('\\lbuchs\\WebAuthn\\WebAuthn');
}

// Sodium: WordPress ships a pure PHP fallback and loads it itself. Load it here too in the
// unlikely case it has not been, so the authenticator-app secrets can still be encrypted.
if (
    (!function_exists('sodium_crypto_secretbox') || !function_exists('sodium_crypto_secretbox_open'))
    && defined('WPINC')
    && is_readable(ABSPATH . WPINC . '/sodium_compat/autoload.php')
) {
    require_once ABSPATH . WPINC . '/sodium_compat/autoload.php';
}

$acemedia_runtime_ready = PHP_VERSION_ID >= 80200
    && function_exists('sodium_crypto_secretbox')
    && function_exists('sodium_crypto_secretbox_open')
    && is_readable(__DIR__ . '/vendor/autoload.php');
if ($acemedia_runtime_ready) {
    try {
        require_once __DIR__ . '/vendor/autoload.php';
        $acemedia_runtime_ready = class_exists('OTPHP\\TOTP')
            && class_exists('Endroid\\QrCode\\QrCode');
    } catch (Throwable $error) {
        $acemedia_runtime_ready = false;
    }
}
if (!$acemedia_runtime_ready) {
    add_filter('authenticate', 'acemedia_login_block_runtime_error', PHP_INT_MAX);
    add_action('admin_notices', 'acemedia_login_block_runtime_notice');
    add_action('network_admin_notices', 'acemedia_login_block_runtime_notice');
    return;
}



// Define plugin constants
define('ACEMEDIA_LOGIN_BLOCK_VERSION', '0.430.0');
define('ACEMEDIA_LOGIN_BLOCK_PATH', plugin_dir_path(__FILE__));
define('ACEMEDIA_LOGIN_BLOCK_URL', plugin_dir_url(__FILE__));

// Load the class autoloader
require_once ACEMEDIA_LOGIN_BLOCK_PATH . 'includes/class-loader.php';

// Admin includes
require_once ACEMEDIA_LOGIN_BLOCK_PATH . 'includes/admin/class-settings-page.php';
require_once ACEMEDIA_LOGIN_BLOCK_PATH . 'includes/admin/class-security-page.php';
require_once ACEMEDIA_LOGIN_BLOCK_PATH . 'includes/admin/class-admin-notices.php';
require_once ACEMEDIA_LOGIN_BLOCK_PATH . 'includes/admin/class-user-profile.php';

// Auth includes
require_once ACEMEDIA_LOGIN_BLOCK_PATH . 'includes/auth/class-login-handler.php';
require_once ACEMEDIA_LOGIN_BLOCK_PATH . 'includes/auth/class-login-lockout.php';
require_once ACEMEDIA_LOGIN_BLOCK_PATH . 'includes/auth/class-passkeys.php';
require_once ACEMEDIA_LOGIN_BLOCK_PATH . 'includes/auth/class-two-factor.php';
require_once ACEMEDIA_LOGIN_BLOCK_PATH . 'includes/auth/class-backup-codes.php';
require_once ACEMEDIA_LOGIN_BLOCK_PATH . 'includes/auth/class-authentication.php';

// Block includes
require_once ACEMEDIA_LOGIN_BLOCK_PATH . 'includes/blocks/class-login-block.php';
require_once ACEMEDIA_LOGIN_BLOCK_PATH . 'includes/blocks/class-username-block.php';
require_once ACEMEDIA_LOGIN_BLOCK_PATH . 'includes/blocks/class-password-block.php';
require_once ACEMEDIA_LOGIN_BLOCK_PATH . 'includes/blocks/class-remember-me-block.php';


// Utility includes
require_once ACEMEDIA_LOGIN_BLOCK_PATH . 'includes/utils/class-encryption.php';
require_once ACEMEDIA_LOGIN_BLOCK_PATH . 'includes/utils/class-logging.php';
require_once ACEMEDIA_LOGIN_BLOCK_PATH . 'includes/utils/class-security.php';

// Frontend includes
require_once ACEMEDIA_LOGIN_BLOCK_PATH . 'includes/frontend/class-assets.php';
require_once ACEMEDIA_LOGIN_BLOCK_PATH . 'includes/frontend/class-login-page.php';



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
    // login-toggle.js is not part of the build; enqueue removed to avoid a 404 in the editor.

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
function acemedia_register_2fa_method_setting() {
    register_setting('acemedia_login_block_options_group', 'acemedia_2fa_method', [
        'type' => 'string',
        'description' => __('2FA Method', 'acemedia-login-block'),
        'sanitize_callback' => 'sanitize_text_field',
        'default' => 'email', // Default to email-based 2FA
    ]);
}
add_action('admin_init', 'acemedia_register_2fa_method_setting');




/**
 * Encrypt a scalar value at rest (e.g. a TOTP secret) with libsodium.
 * Returns an "aceenc:" prefixed base64 of nonce+ciphertext so decryption can
 * distinguish encrypted values from legacy plaintext. Renamed off the reserved
 * wp_ prefix for WordPress.org compliance.
 */
function acemedia_login_block_encrypt($data) {
    if (!function_exists('sodium_crypto_secretbox')) {
        return false;
    }
    $key    = sodium_crypto_generichash(wp_salt('auth'), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $nonce  = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = sodium_crypto_secretbox((string) $data, $nonce, $key);
    return 'aceenc:' . base64_encode($nonce . $cipher);
}

/**
 * Decrypt a value produced by acemedia_login_block_encrypt(). Legacy plaintext
 * (no "aceenc:" prefix) is returned unchanged so existing secrets keep working;
 * an undecryptable value returns false.
 */
function acemedia_login_block_decrypt($encrypted) {
    if (!is_string($encrypted) || strpos($encrypted, 'aceenc:') !== 0) {
        return $encrypted;
    }
    if (!function_exists('sodium_crypto_secretbox_open')) {
        return false;
    }
    $raw = base64_decode(substr($encrypted, 7), true);
    if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        return false;
    }
    $key    = sodium_crypto_generichash(wp_salt('auth'), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $nonce  = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plain  = sodium_crypto_secretbox_open($cipher, $nonce, $key);
    return $plain === false ? false : $plain;
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
        $passkeys_enabled = (bool) get_option('acemedia_passkeys_enabled', true);
        $passkeys_supported = $passkeys_enabled && acemedia_login_block_passkeys_available();

        wp_localize_script('acemedia-login-frontend', 'aceLoginBlock', [
            'loginUrl' => site_url('wp-login.php'),
            'userRoles' => wp_get_current_user()->roles,
            'redirectUrl' => site_url('/wp-admin'),
            'is2FAEnabled' => $is_2fa_enabled,
            'passkeysEnabled' => $passkeys_supported,
            'twoFALabel' => __('Enter 2FA Code', 'acemedia-login-block'),
            'twoFAPlaceholder' => __('2FA Code', 'acemedia-login-block'),
            'submit2FA' => __('Verify 2FA', 'acemedia-login-block'),
            'verify2FAEndpoint' => rest_url('acemedia/v1/verify-2fa'),
            'check2FAEndpoint' => rest_url('acemedia/v1/check-2fa'),
            'passkeyLoginOptionsEndpoint' => rest_url('acemedia/v1/passkeys/login-options'),
            'passkeyLoginEndpoint' => rest_url('acemedia/v1/passkeys/login'),
            'passkeyPasswordLabel' => __('Use Passkey for Password', 'acemedia-login-block'),
            'passkeyTwoFALabel' => __('Use Passkey for 2FA', 'acemedia-login-block'),
            'rememberDeviceLabel' => __('Remember this device for 2FA for 30 days', 'acemedia-login-block'),
            'csrfToken' => wp_create_nonce('wp_rest'),
            'nonce' => wp_create_nonce('wp_rest'),
        ]);
    }
}
add_action('login_enqueue_scripts', 'acemedia_enqueue_admin_login_script');

// Stop the login log cleanup cron when the plugin is deactivated.
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook(\AceLoginBlock\Auth\Login_Lockout::CRON_HOOK);
});

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

/**
 * Registers the block and its settings.
 */
function acemedia_create_block_login_block_init() {
    register_block_type( __DIR__ . '/build/login-block' );
    register_block_type( __DIR__ . '/build/username-block' );
    register_block_type( __DIR__ . '/build/password-block' );
}
add_action( 'init', 'acemedia_create_block_login_block_init' );

/**
 * Register the custom login page setting and create the settings page.
 */
function acemedia_login_block_register_settings() {
    // Register the custom login page setting
    register_setting('acemedia_login_block_options_group', 'acemedia_login_block_custom_page', [
        'type' => 'integer',
        'description' => __('Custom page for login', 'acemedia-login-block'),
        'default' => 0,
    ]);

    // Register the 2FA enabled setting
    register_setting('acemedia_login_block_options_group', 'acemedia_2fa_enabled', [
        'type' => 'boolean',
        'description' => __('Enable Two-Factor Authentication (2FA)', 'acemedia-login-block'),
        'default' => 0,
    ]);

    // Register settings for redirect URLs
    $roles = wp_roles()->roles; // Get all WordPress roles
    foreach ($roles as $role => $details) {
        // Register role-specific redirect URL settings
        $role_redirect_key = "acemedia_login_block_redirect_{$role}";
        register_setting('acemedia_login_block_options_group', $role_redirect_key, [
            'type' => 'string',
            'description' => sprintf(__('Redirect URL for %s role', 'acemedia-login-block'), $details['name']),
            'default' => '',
        ]);
    }

    // Add the settings page
    add_options_page(
        __('Ace Login Block Settings', 'acemedia-login-block'),
        __('Ace Login Block', 'acemedia-login-block'),
        'manage_options',
        'acemedia-login-block',
        'acemedia_login_block_render_settings_page'
    );
}
add_action('admin_menu', 'acemedia_login_block_register_settings');

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

function acemedia_login_block_render_settings_page() {
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Ace Login Block Settings', 'acemedia-login-block'); ?></h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('acemedia_login_block_options_group');
            do_settings_sections('acemedia_login_block');
            ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row"><?php esc_html_e('Custom Login Page', 'acemedia-login-block'); ?></th>
                    <td><?php acemedia_login_block_custom_page_field_html(); ?></td>
                </tr>
                <?php
                // Get all WordPress roles
                $roles = wp_roles()->roles;

                // Get all public pages for front-end options
                $front_end_pages = get_pages();

                // Include the global admin pages array
                global $acemedia_admin_pages;

                foreach ($roles as $role => $details) {
                    $redirect_url = get_option("acemedia_login_block_redirect_{$role}", '');
                    ?>
                    <tr valign="top">
                        <th scope="row"><?php echo esc_html(ucfirst($role)); ?> <?php esc_html_e('Redirect URL', 'acemedia-login-block'); ?></th>
                        <td>
                            <label for="acemedia_login_block_redirect_<?php echo esc_attr($role); ?>">
                                <select id="acemedia_login_block_redirect_<?php echo esc_attr($role); ?>" name="acemedia_login_block_redirect_<?php echo esc_attr($role); ?>">
                                    <option value=""><?php esc_html_e('Default behaviour', 'acemedia-login-block'); ?></option>
                                    
                                    <!-- Frontend Pages Header -->
                                    <option disabled><?php esc_html_e('--- Frontend Pages ---', 'acemedia-login-block'); ?></option>
                                    <?php
                                    // Front-end pages options
                                    foreach ($front_end_pages as $page) : ?>
                                        <option value="<?php echo esc_attr(get_permalink($page->ID)); ?>" <?php selected(esc_url($redirect_url), get_permalink($page->ID)); ?>>
                                            <?php echo esc_html($page->post_title); ?>
                                        </option>
                                    <?php endforeach; ?>
                                    
                                    <!-- Admin Pages Header -->
                                    <option disabled><?php esc_html_e('--- Admin Pages ---', 'acemedia-login-block'); ?></option>
                                    <?php 
                                    // Admin pages options
                                    foreach ($acemedia_admin_pages as $page => $info) {
                                        // Check if the role has the capability to access the page
                                        if (user_can(get_role($role), $info['capability'])) {
                                            // Construct the full admin URL
                                            $admin_url = admin_url($page);
                                            ?>
                                            <option value="<?php echo esc_attr($admin_url); ?>" <?php selected(esc_url($redirect_url), $admin_url); ?>>
                                                <?php echo esc_html($info['title']); ?>
                                            </option>
                                            <?php
                                        }
                                    }
                                    ?>
                                </select>
                            </label>
                        </td>
                    </tr>
                    <?php
                }
                ?>

      
                <!-- 2FA Enable/Disable -->
                <tr valign="top">
                    <th scope="row"><?php esc_html_e('Enable Two-Factor Authentication (2FA)', 'acemedia-login-block'); ?></th>
                    <td>
                        <label for="acemedia_2fa_enabled">
                            <input type="checkbox" id="acemedia_2fa_enabled" name="acemedia_2fa_enabled" value="1" <?php checked(1, get_option('acemedia_2fa_enabled', 0)); ?>>
                            <?php esc_html_e('Enable 2FA for additional login security.', 'acemedia-login-block'); ?>
                        </label>
                    </td>
                </tr>

    

            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}






/**
 * Display the dropdown to select the login page in the Ace Login Block settings page.
 */
function acemedia_login_block_custom_page_field_html() {
    $custom_page_id = get_option( 'acemedia_login_block_custom_page', 0 );
    $pages = get_pages();

    echo '<select name="acemedia_login_block_custom_page">';
    echo '<option value="">' . esc_html__( 'Select a page', 'acemedia-login-block' ) . '</option>';

    foreach ( $pages as $page ) {
        $selected = selected( $custom_page_id, $page->ID, false );
        echo '<option value="' . esc_attr( $page->ID ) . '" ' . esc_attr( $selected ) . '>' . esc_html( $page->post_title ) . '</option>';
    }

    echo '</select>';
}

/**
 * Replace wp-login.php with the selected custom page content and template.
 */
function acemedia_login_block_load_custom_page_template() {
    $custom_page_id = get_option( 'acemedia_login_block_custom_page' );



    // Check if we're on wp-login.php and a custom page is set
    if ( isset( $_SERVER['REQUEST_URI'] ) ) {
        $request_uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
        if ( strpos( $request_uri, 'wp-login.php' ) !== false && $custom_page_id ) {
    
            
            // If the request method is POST, let WordPress handle the login process
            if ( isset( $_SERVER['REQUEST_METHOD'] ) && $_SERVER['REQUEST_METHOD'] === 'POST' ) {
                return; // Let WordPress handle the login submission
            }

            // Fetch the template for the chosen page
            $page_template = get_page_template_slug( $custom_page_id );

            // If no custom template is found, use the default page template
            if ( ! empty( $page_template ) && locate_template( $page_template ) ) {
                $template_path = locate_template( $page_template );
            } else {
                $template_path = get_page_template();
            }

            if ( ! empty( $template_path ) ) {
                // Set up the global post data for the custom page
                global $wp_query, $post;
                $post = get_post( $custom_page_id );
                setup_postdata( $post );

                // Load the custom page template
                include $template_path;

                // Prevent further execution after the template is loaded
                exit;
            } else {
                wp_die( esc_html__( 'Template not found for the login page.', 'acemedia-login-block' ) );
                    }
        }
    }
}
add_action( 'login_init', 'acemedia_login_block_load_custom_page_template' );

/**
 * Enqueue styles and scripts for the custom login page.
 */
add_action('wp_enqueue_scripts', 'acemedia_enqueue_login_script');

function acemedia_enqueue_login_script() {
    if (has_block('acemedia/login-block')) {
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
            'twoFALabel' => __('Enter Authentication Code', 'acemedia-login-block'),
            'twoFAPlaceholder' => __('Authentication Code', 'acemedia-login-block'),
            'submit2FA' => __('Verify', 'acemedia-login-block'),
            'verify2FAEndpoint' => rest_url('acemedia/v1/verify-2fa'),
            'check2FAEndpoint' => rest_url('acemedia/v1/check-2fa'),
            'nonce' => wp_create_nonce('wp_rest'),
        ]);
    }
}


// Handle the login redirect after a user logs in
function acemedia_login_redirect($user_login, $user) {
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
add_action('wp_login', 'acemedia_login_redirect', 10, 2);




// Adjust the logout redirect function
function acemedia_logout_redirect() {
    $custom_page_id = get_option('acemedia_login_block_custom_page', 0);
    if (!$custom_page_id) {
        // Custom login page not set, do nothing
        return;
    }

    $redirect_url = home_url(); // Change this to your desired logout redirect URL
    wp_safe_redirect($redirect_url);
    exit();
}
add_action('wp_logout', 'acemedia_logout_redirect');

function acemedia_handle_logout() {
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
add_action('init', 'acemedia_handle_logout');

/**
 * Customize the login page title.
 */
function acemedia_login_block_login_title( $title ) {
    return __( 'Login', 'acemedia-login-block' );
}
add_filter( 'login_title', 'acemedia_login_block_login_title' );

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
    // Ensure the placeholder is properly sanitized
    $placeholder = isset($attributes['placeholder']) ? sanitize_text_field($attributes['placeholder']) : __('Username', 'acemedia-login-block');

    // Escape the placeholder for safe HTML output
    $placeholder = esc_attr($placeholder);
    
    // Return the sanitized and escaped input field
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
    // Ensure the placeholder is properly sanitized
    $placeholder = isset($attributes['placeholder']) ? sanitize_text_field($attributes['placeholder']) : __('Password', 'acemedia-login-block');

    // Escape the placeholder for safe HTML output
    $placeholder = esc_attr($placeholder);

    // Ensure the showPassword attribute is boolean and safe for use in HTML attributes
    $show_password = !empty($attributes['showPassword']) ? 'true' : 'false';

    // Generate a nonce field for the login form
    $login_nonce = wp_create_nonce('login_action');

    // Start building the HTML for the password input
    $html = '<input type="password" id="pwd" name="pwd" placeholder="' . $placeholder . '" required />';

    // Add the hidden login_nonce field
    $html .= '<input type="hidden" name="login_nonce" value="' . esc_attr($login_nonce) . '" />';

    // Conditionally add the "Show Password" toggle if enabled
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



function acemedia_verify_qr_code($secret, $code) {
    // Use a library like `spomky-labs/otphp` for TOTP validation
    $totp = new \OTPHP\TOTP($secret);
    return $totp->verify($code);
}


function acemedia_send_2fa_email($user_id) {
    $user = get_userdata($user_id);
    
    if ($user && is_email($user->user_email)) {
        $code = wp_generate_password(6, false, false); // Generate a 6-digit code
        update_user_meta($user_id, '_acemedia_2fa_code', $code);

        $subject = __('Your 2FA Code', 'acemedia-login-block');
        $message = sprintf(__('Your 2FA code is: %s', 'acemedia-login-block'), $code);
        $headers = ['Content-Type: text/html; charset=UTF-8'];

        // Send email and check for success
        if (!wp_mail($user->user_email, $subject, $message, $headers)) {
            error_log(sprintf('Failed to send 2FA email to %s', $user->user_email));
        }
    } else {
        error_log(sprintf('Invalid email for user ID %d', $user_id));
    }
}

require 'vendor/autoload.php';

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\RoundBlockSizeMode;

/**
 * Generate a QR code for 2FA.
 *
 * @param int $user_id User ID.
 * @return string URL to the QR code image.
 */
function acemedia_generate_qr_code($user_id) {
    // Generate or retrieve a Base32-encoded secret
    $secret = get_user_meta($user_id, '_acemedia_2fa_secret', true);
    if (!$secret) {
        // Generate a random secret that's compatible with Google Authenticator
        $random_bytes = random_bytes(16);
        $secret = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', base32_encode($random_bytes)), 0, 32));
        update_user_meta($user_id, '_acemedia_2fa_secret', $secret);
    }

    // Ensure proper encoding of the issuer and username
    $issuer = rawurlencode(get_bloginfo('name')); // Use site name instead of hardcoded value
    $username = rawurlencode(get_userdata($user_id)->user_login);

    // Construct the otpauth:// URI
    $uri = sprintf(
        'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
        $issuer,
        $username,
        $secret,
        $issuer
    );

    // Create the QR code
    $qrCode = new QrCode(
        data: $uri,
        encoding: new Encoding('UTF-8'),
        errorCorrectionLevel: ErrorCorrectionLevel::High,
        size: 300,
        margin: 10,
        roundBlockSizeMode: RoundBlockSizeMode::Margin,
        foregroundColor: new Color(0, 0, 0),
        backgroundColor: new Color(255, 255, 255)
    );

    // Generate the QR code image
    $writer = new PngWriter();
    $result = $writer->write($qrCode);

    // Ensure the directory exists for QR codes
    $qr_code_dir = plugin_dir_path(__FILE__) . 'qrcodes/';
    if (!file_exists($qr_code_dir)) {
        mkdir($qr_code_dir, 0755, true);
    }

    // Save the QR code image
    $qr_code_path = $qr_code_dir . $user_id . '.png';
    $result->saveToFile($qr_code_path);

    // Return the URL to the QR code image
    return plugin_dir_url(__FILE__) . 'qrcodes/' . $user_id . '.png';
}

// Helper function for base32 encoding
function base32_encode($data) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $binary = '';
    foreach (str_split($data) as $char) {
        $binary .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
    }
    $binary = str_pad($binary, ceil(strlen($binary) / 40) * 40, '0');
    $result = '';
    foreach (str_split($binary, 5) as $chunk) {
        $result .= $alphabet[bindec($chunk)];
    }
    return $result;
}







add_action('rest_api_init', function () {
    register_rest_route('acemedia/v1', '/check-2fa', [
        'methods' => 'POST',
        'callback' => 'acemedia_check_2fa_status',
        'permission_callback' => '__return_true',
        'args' => [
            'username' => [
                'required' => true,
                'validate_callback' => function ($param, $request, $key) {
                    return is_string($param);
                },
            ],
        ],
    ]);
});

function acemedia_check_2fa_status(WP_REST_Request $request) {
    $username = $request->get_param('username');
    $user = get_user_by('login', sanitize_text_field($username));

    if (!$user) {
        return new WP_Error('invalid_username', __('Invalid username.', 'acemedia-login-block'), ['status' => 404]);
    }

    $is_2fa_enabled = (bool) get_user_meta($user->ID, '_acemedia_2fa_enabled', true);
    $selected_method = get_user_meta($user->ID, '_acemedia_2fa_method', true);

    return [
        'is2FAEnabled' => $is_2fa_enabled,
        'method' => $selected_method,
    ];
}


add_action('rest_api_init', function () {
    register_rest_route('acemedia/v1', '/verify-2fa', [
        'methods' => 'POST',
        'callback' => 'acemedia_verify_2fa_code',
        'permission_callback' => '__return_true',
        'args' => [
            'code' => [
                'required' => true,
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'username' => [
                'required' => true,
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ],
    ]);
});


function acemedia_verify_2fa_code(WP_REST_Request $request) {
    $code = $request->get_param('code');
    $username = $request->get_param('username'); // Get the username from the request

    if (!$username) {
        return new WP_Error('missing_username', __('Username is required.', 'acemedia-login-block'), ['status' => 400]);
    }

    $user = get_user_by('login', sanitize_text_field($username)); // Find the user by username
    if (!$user) {
        return new WP_Error('invalid_username', __('Invalid username.', 'acemedia-login-block'), ['status' => 404]);
    }

    $user_id = $user->ID;
    $expected_code = get_user_meta($user_id, '_acemedia_2fa_code', true);

    if ($code === $expected_code) {
        delete_user_meta($user_id, '_acemedia_2fa_code'); // Invalidate the code after verification
        return ['success' => true];
    }

    return new WP_Error('invalid_code', __('Invalid authentication code.', 'acemedia-login-block'), ['status' => 400]);
}



// Add 2FA fields to user profile
function acemedia_add_2fa_fields($user) {
    $is_2fa_enabled_global = (bool) get_option('acemedia_2fa_enabled', false);
    if (!$is_2fa_enabled_global) {
        return;
    }

    $is_2fa_enabled = get_user_meta($user->ID, '_acemedia_2fa_enabled', true);
    $secret = get_user_meta($user->ID, '_acemedia_2fa_secret', true);
    $selected_method = get_user_meta($user->ID, '_acemedia_2fa_method', true);
    ?>
    <h3><?php esc_html_e('Two-Factor Authentication', 'acemedia-login-block'); ?></h3>
    <table class="form-table">
        <tr>
            <th><label for="acemedia_2fa_enabled"><?php esc_html_e('Enable 2FA', 'acemedia-login-block'); ?></label></th>
            <td>
                <input type="checkbox" name="acemedia_2fa_enabled" id="acemedia_2fa_enabled" value="1" <?php checked($is_2fa_enabled, 1); ?> />
            </td>
        </tr>
        <tr>
            <th><label for="acemedia_2fa_method"><?php esc_html_e('2FA Method', 'acemedia-login-block'); ?></label></th>
            <td>
                <select name="acemedia_2fa_method" id="acemedia_2fa_method">
                    <option value="email" <?php selected($selected_method, 'email'); ?>><?php esc_html_e('Email', 'acemedia-login-block'); ?></option>
                    <option value="auth_app" <?php selected($selected_method, 'auth_app'); ?>><?php esc_html_e('Authentication App', 'acemedia-login-block'); ?></option>
                </select>
            </td>
        </tr>
        <?php if ($is_2fa_enabled && $secret && $selected_method === 'auth_app'): ?>
        <tr>
            <th><label for="acemedia_2fa_qr"><?php esc_html_e('2FA QR Code', 'acemedia-login-block'); ?></label></th>
            <td>
                <?php $qr_code_url = acemedia_generate_qr_code($user->ID); ?>
                <img src="<?php echo esc_url($qr_code_url); ?>" alt="<?php esc_attr_e('2FA QR Code', 'acemedia-login-block'); ?>" />
                <p class="description"><?php esc_html_e('Scan this QR code with your authentication app.', 'acemedia-login-block'); ?></p>
            </td>
        </tr>
        <?php endif; ?>
    </table>
    <?php
}
add_action('show_user_profile', 'acemedia_add_2fa_fields', 1);
add_action('edit_user_profile', 'acemedia_add_2fa_fields', 1);

// Save 2FA settings
function acemedia_save_2fa_fields($user_id) {
    if (!current_user_can('edit_user', $user_id)) {
        return false;
    }

    $is_2fa_enabled_global = (bool) get_option('acemedia_2fa_enabled', false);
    if (!$is_2fa_enabled_global) {
        delete_user_meta($user_id, '_acemedia_2fa_enabled');
        delete_user_meta($user_id, '_acemedia_2fa_method');
        delete_user_meta($user_id, '_acemedia_2fa_secret');
        return;
    }

    $is_2fa_enabled = isset($_POST['acemedia_2fa_enabled']) ? 1 : 0;
    update_user_meta($user_id, '_acemedia_2fa_enabled', $is_2fa_enabled);

    $selected_method = isset($_POST['acemedia_2fa_method']) ? sanitize_text_field($_POST['acemedia_2fa_method']) : 'email';
    update_user_meta($user_id, '_acemedia_2fa_method', $selected_method);

    if ($is_2fa_enabled && $selected_method === 'auth_app') {
        $secret = get_user_meta($user_id, '_acemedia_2fa_secret', true);
        if (!$secret) {
            acemedia_generate_qr_code($user_id);
        }
    } else {
        delete_user_meta($user_id, '_acemedia_2fa_secret');
    }
}
add_action('personal_options_update', 'acemedia_save_2fa_fields');
add_action('edit_user_profile_update', 'acemedia_save_2fa_fields');
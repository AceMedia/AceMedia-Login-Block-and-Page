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

    // Register settings for redirect URLs and 2FA per role
    $roles = wp_roles()->roles;
    foreach ($roles as $role => $details) {
        // Register role-specific redirect URL settings
        $role_redirect_key = "acemedia_login_block_redirect_{$role}";
        register_setting('acemedia_login_block_options_group', $role_redirect_key, [
            'type' => 'string',
            'description' => sprintf(__('Redirect URL for %s role', 'acemedia-login-block'), $details['name']),
            'default' => '',
        ]);

        // Register role-specific 2FA settings
        $role_2fa_key = "acemedia_2fa_enabled_{$role}";
        register_setting('acemedia_login_block_options_group', $role_2fa_key, [
            'type' => 'boolean',
            'description' => sprintf(__('Enable 2FA for %s role', 'acemedia-login-block'), $details['name']),
            'default' => false,
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
                $roles = wp_roles()->roles;

         // Get all public pages for front-end options
         $front_end_pages = get_pages();

         // Include the global admin pages array
         global $acemedia_admin_pages;


                foreach ($roles as $role => $details) {
                    $redirect_url = get_option("acemedia_login_block_redirect_{$role}", '');
                    $is_2fa_enabled = get_option("acemedia_2fa_enabled_{$role}", false);
                    ?>
                    <tr valign="top">
                        <th scope="row"><?php echo esc_html(ucfirst($role)); ?></th>
                        <td>
                        <label for="acemedia_login_block_redirect_<?php echo esc_attr($role); ?>"><?php esc_html_e('Redirect: ', 'acemedia-login-block'); ?>
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
                            <label>
                                <input type="checkbox"
                                       name="<?php echo esc_attr("acemedia_2fa_enabled_{$role}"); ?>"
                                       value="1"
                                       <?php checked($is_2fa_enabled, true); ?>>
                                <?php esc_html_e('Requires 2FA', 'acemedia-login-block'); ?>
                            </label>
                        </td>
                    </tr>
                    <?php
                }
                ?>
            </table>
            <?php submit_button(); ?>
        </form>



        <h2><?php esc_html_e('Two-Factor Authentication Logs', 'acemedia-login-block'); ?></h2>
<table class="wp-list-table widefat fixed striped">
    <thead>
        <tr>
            <th><?php esc_html_e('Time', 'acemedia-login-block'); ?></th>
            <th><?php esc_html_e('User', 'acemedia-login-block'); ?></th>
            <th><?php esc_html_e('IP Address', 'acemedia-login-block'); ?></th>
            <th><?php esc_html_e('Action', 'acemedia-login-block'); ?></th>
            <th><?php esc_html_e('Status', 'acemedia-login-block'); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php
        global $wpdb;
        $logs = [];
        $users = get_users();
        $total_log_size = 0;

        // Collect logs from all users
        foreach ($users as $user) {
            $user_logs = get_user_meta($user->ID, '_acemedia_2fa_logs', true) ?: [];
            foreach ($user_logs as $log) {
                $log['username'] = $user->user_login;
                if (isset($log['time']) && strtotime($log['time']) > strtotime('-24 hours')) {
                    $logs[] = $log;
                }
            }
            $total_log_size += strlen(serialize($user_logs));
        }

        // Sort logs by time, newest first
        usort($logs, function($a, $b) {
            return strtotime($b['time']) - strtotime($a['time']);
        });

        if (empty($logs)): ?>
            <tr>
                <td colspan="5"><?php esc_html_e('No failed login attempts in the last 24 hours.', 'acemedia-login-block'); ?></td>
            </tr>
        <?php else:
            foreach ($logs as $log):
                if (isset($log['success']) && !$log['success']): ?>
                <tr>
                    <td><?php echo esc_html(get_date_from_gmt($log['time'])); ?></td>
                    <td><?php echo esc_html($log['username']); ?></td>
                    <td><?php echo esc_html($log['ip']); ?></td>
                    <td><?php echo esc_html($log['action'] ?? 'verify_2fa'); ?></td>
                    <td>
                        <span class="dashicons dashicons-no" style="color: #dc3232;"></span>
                        <?php esc_html_e('Failed', 'acemedia-login-block'); ?>
                    </td>
                </tr>
                <?php endif;
            endforeach;
        endif; ?>
    </tbody>
</table>

<div style="margin-top: 20px;">
    <p>
        <?php
        printf(
            esc_html__('Total log size: %s', 'acemedia-login-block'),
            size_format($total_log_size)
        );
        ?>
    </p>
    <form method="post" action="">
        <?php wp_nonce_field('clear_2fa_logs', 'clear_2fa_logs_nonce'); ?>
        <input type="submit" name="clear_2fa_logs" class="button button-secondary" value="<?php esc_attr_e('Clear All 2FA Logs', 'acemedia-login-block'); ?>" onclick="return confirm('<?php esc_attr_e('Are you sure you want to clear all 2FA logs? This cannot be undone.', 'acemedia-login-block'); ?>');" />
    </form>
</div>

    </div>
    <?php
}


function acemedia_handle_clear_2fa_logs() {
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
add_action('admin_init', 'acemedia_handle_clear_2fa_logs');

/**
 * Display the dropdown to select the login page in the Ace Login Block settings page.
 */
function acemedia_login_block_custom_page_field_html() {
    $custom_page_id = get_option( 'acemedia_login_block_custom_page', 0 );
    $pages = get_pages();

    echo '<select name="acemedia_login_block_custom_page">';
    echo '<option value="">' . esc_html__( 'Default WordPress Login', 'acemedia-login-block' ) . '</option>';

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
    require_once 'vendor/autoload.php';

    try {
        $totp = \OTPHP\TOTP::create($secret);
        return $totp->verify($code);
    } catch (Exception $e) {
        error_log('TOTP verification error: ' . $e->getMessage());
        return false;
    }
}


function acemedia_send_2fa_email($user_id) {
    $user = get_userdata($user_id);

    if ($user && is_email($user->user_email)) {
        $code = wp_generate_password(6, false, false);
        update_user_meta($user_id, '_acemedia_2fa_code', $code);
        update_user_meta($user_id, '_acemedia_2fa_code_time', time());

        $subject = __('Your 2FA Code', 'acemedia-login-block');
        $message = sprintf(__('Your 2FA code is: %s (valid for 5 minutes)', 'acemedia-login-block'), $code);
        $headers = ['Content-Type: text/html; charset=UTF-8'];

        if (!wp_mail($user->user_email, $subject, $message, $headers)) {
            error_log(sprintf('Failed to send 2FA email to %s', $user->user_email));
        }
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
        // Define valid Base32 alphabet (A-Z and 2-7 only)
        $base32_alphabet = str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567');
        $secret = '';

        // Generate exactly 32 characters from valid Base32 alphabet
        for ($i = 0; $i < 32; $i++) {
            $secret .= $base32_alphabet[array_rand($base32_alphabet)];
        }

        update_user_meta($user_id, '_acemedia_2fa_secret', $secret);
    }

    // Get site and user info
    $site_name = html_entity_decode(get_bloginfo('name'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $username = get_userdata($user_id)->user_login;

    // Create otpauth URI with proper encoding
    $uri = sprintf(
        'otpauth://totp/%s:%s?secret=%s&issuer=%s',
        rawurlencode($site_name),
        rawurlencode($username),
        $secret,
        rawurlencode($site_name)
    );


    error_log('Raw secret: ' . $secret);
    error_log('Generated TOTP URI: ' . $uri);

    // Create QR code
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

    // Generate and save QR code
    $writer = new PngWriter();
    $result = $writer->write($qrCode);

    // Save the QR code image
    $qrcodes_dir = plugin_dir_path(__FILE__) . 'qrcodes/';
    if (!file_exists($qrcodes_dir)) {
        mkdir($qrcodes_dir, 0755, true);
    }

    $result->saveToFile($qrcodes_dir . $user_id . '.png');

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

    $needs_2fa = false;
    foreach ($user->roles as $role) {
        if (get_option("acemedia_2fa_enabled_{$role}", false)) {
            $needs_2fa = true;
            break;
        }
    }

    $is_2fa_enabled = (bool) get_user_meta($user->ID, '_acemedia_2fa_enabled', true);
    $selected_method = get_user_meta($user->ID, '_acemedia_2fa_method', true);
    $needs_setup = $needs_2fa && (!$is_2fa_enabled || !get_user_meta($user->ID, '_acemedia_2fa_setup_complete', true));

    return [
        'is2FAEnabled' => $is_2fa_enabled && $needs_2fa,
        'method' => $selected_method,
        'needs2FASetup' => $needs_setup,
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


function acemedia_validate_2fa($user, $username, $password) {
    if (!$user || is_wp_error($user)) {
        return $user;
    }

    // Check if any of user's roles require 2FA
    $needs_2fa = false;
    foreach ($user->roles as $role) {
        if (get_option("acemedia_2fa_enabled_{$role}", false)) {
            $needs_2fa = true;
            break;
        }
    }

    if (!$needs_2fa) {
        return $user;
    }

    $two_factor_code = isset($_POST['2fa_code']) ? $_POST['2fa_code'] : '';
    if (empty($two_factor_code)) {
        // Display an input field for the 2FA code if not provided
        add_action('login_form', function() {
            echo '<p>';
            echo '<label for="2fa_code">' . __('Two-Factor Authentication Code', 'acemedia-login-block') . '<br />';
            echo '<input type="text" name="2fa_code" id="2fa_code" class="input" value="" size="20" /></label>';
            echo '</p>';
        });
        return new WP_Error('2fa_required', __('Two-factor authentication code required.', 'acemedia-login-block'));
    }

    // Create a proper REST Request object
    $request = new WP_REST_Request('POST', '/acemedia/v1/verify-2fa');
    $request->set_param('code', $two_factor_code);
    $request->set_param('username', $username);

    // Verify the 2FA code
    $verification_result = acemedia_verify_2fa_code($request);

    if (is_wp_error($verification_result) || !$verification_result['success']) {
        return new WP_Error('2fa_invalid', __('Invalid two-factor authentication code.', 'acemedia-login-block'));
    }

    return $user;
}
add_filter('authenticate', 'acemedia_validate_2fa', 99, 3);

function acemedia_verify_2fa_code(WP_REST_Request $request) {
    $code = $request->get_param('code');
    $username = $request->get_param('username');

    if (!$username) {
        return new WP_Error('missing_username', __('Username is required.', 'acemedia-login-block'), ['status' => 400]);
    }

    $user = get_user_by('login', sanitize_text_field($username));
    if (!$user) {
        return new WP_Error('invalid_username', __('Invalid username.', 'acemedia-login-block'), ['status' => 404]);
    }

    // Add rate limiting
    $attempts = get_transient('2fa_attempts_' . $user->ID);
    if ($attempts === false) {
        set_transient('2fa_attempts_' . $user->ID, 1, HOUR_IN_SECONDS);
    } else if ($attempts >= 10) {
        return new WP_Error('too_many_attempts', __('Too many attempts. Please try again later.', 'acemedia-login-block'));
    } else {
        set_transient('2fa_attempts_' . $user->ID, $attempts + 1, HOUR_IN_SECONDS);
    }

    $user_id = $user->ID;
    $selected_method = get_user_meta($user_id, '_acemedia_2fa_method', true);

    // First check if it's a valid backup code
    $backup_codes = get_user_meta($user_id, '_acemedia_2fa_backup_codes', true);
    if (is_array($backup_codes)) {
        foreach ($backup_codes as $index => $code_data) {
            if (!$code_data['used'] && wp_check_password($code, $code_data['hash'])) {
                // Mark code as used
                $backup_codes[$index]['used'] = true;
                update_user_meta($user_id, '_acemedia_2fa_backup_codes', $backup_codes);

                // Log backup code usage
                acemedia_log_2fa_attempt($user_id, [
                    'action' => 'use_backup_code',
                    'time' => current_time('mysql')
                ]);

                return ['success' => true];
            }
        }
    }

    // Check authenticator app code
    if ($selected_method === 'auth_app') {
        $secret = get_user_meta($user_id, '_acemedia_2fa_secret', true);
        if ($secret && acemedia_verify_qr_code($secret, $code)) {
            acemedia_log_2fa_attempt($user_id, [
                'action' => 'verify_2fa',
                'success' => true
            ]);
            return ['success' => true];
        }
    }
    // Check email code
    else if ($selected_method === 'email') {
        $expected_code = get_user_meta($user_id, '_acemedia_2fa_code', true);
        if ($code === $expected_code) {
            delete_user_meta($user_id, '_acemedia_2fa_code');
            acemedia_log_2fa_attempt($user_id, [
                'action' => 'verify_2fa',
                'success' => true
            ]);
            return ['success' => true];
        }
    }

    acemedia_log_2fa_attempt($user_id, [
        'action' => 'verify_2fa',
        'success' => false
    ]);
    return new WP_Error('invalid_code', __('Invalid authentication code.', 'acemedia-login-block'));
}

add_action('wp_ajax_get_or_generate_backup_codes', function() {
    check_ajax_referer('get_or_generate_backup_codes');

    $user_id = get_current_user_id();
    if (!$user_id || !current_user_can('edit_user', $user_id)) {
        wp_send_json_error(['message' => __('Permission denied.', 'acemedia-login-block')]);
        return;
    }

    $force_new = isset($_POST['force_new']) && $_POST['force_new'] === 'true';
    $backup_codes = get_user_meta($user_id, '_acemedia_2fa_backup_codes', true);

    if ($force_new || !is_array($backup_codes) || empty($backup_codes)) {
        // Generate new codes with high entropy
        $codes = [];
        for ($i = 0; $i < 10; $i++) {
            $codes[] = strtoupper(bin2hex(random_bytes(4))); // 8 characters of high entropy
        }

        // Store codes with metadata
        $hashed_codes = array_map(function($code) {
            return [
                'hash' => wp_hash_password($code),
                'created' => time(),
                'used' => false
            ];
        }, $codes);

        update_user_meta($user_id, '_acemedia_2fa_backup_codes', $hashed_codes);

        // Log backup code generation
        acemedia_log_2fa_attempt($user_id, [
            'action' => 'generate_backup_codes',
            'time' => current_time('mysql')
        ]);

        wp_send_json_success(['codes' => $codes]);
    } else {
        // Return placeholder codes for existing backup codes
        $codes = array_map(function($code_data) {
            return sprintf('BACKUP-%s', substr(md5($code_data['hash']), 0, 4));
        }, $backup_codes);

        wp_send_json_success(['codes' => $codes]);
    }
});


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



function acemedia_log_2fa_attempt($user_id, $data = []) {
    $log = array_merge([
        'time' => current_time('mysql'),
        'ip' => $_SERVER['REMOTE_ADDR'],
        'user_agent' => $_SERVER['HTTP_USER_AGENT']
    ], $data);

    $logs = get_user_meta($user_id, '_acemedia_2fa_logs', true) ?: [];
    array_unshift($logs, $log);
    update_user_meta($user_id, '_acemedia_2fa_logs', array_slice($logs, 0, 10));
}


// Add 2FA fields to user profile
function acemedia_add_2fa_fields($user) {
    $is_2fa_enabled_global = (bool) get_option('acemedia_2fa_enabled', false);
    if (!$is_2fa_enabled_global) {
        return;
    }

    $is_2fa_enabled = get_user_meta($user->ID, '_acemedia_2fa_enabled', true);
    $secret = get_user_meta($user->ID, '_acemedia_2fa_secret', true);
    $selected_method = get_user_meta($user->ID, '_acemedia_2fa_method', true) ?: 'email';
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
        <tr id="acemedia_2fa_qr_row" style="display: <?php echo ($is_2fa_enabled && $selected_method === 'auth_app') ? 'table-row' : 'none'; ?>">
            <th><label for="acemedia_2fa_qr"><?php esc_html_e('2FA QR Code', 'acemedia-login-block'); ?></label></th>
            <td>
                <?php $qr_code_url = acemedia_generate_qr_code($user->ID); ?>
                <img src="<?php echo esc_url($qr_code_url); ?>" alt="<?php esc_attr_e('2FA QR Code', 'acemedia-login-block'); ?>" />
                <p class="description"><?php esc_html_e('Scan this QR code with your authentication app.', 'acemedia-login-block'); ?></p>
            </td>
        </tr>
    <tr>
        <th><?php esc_html_e('Backup Codes', 'acemedia-login-block'); ?></th>
        <td>
            <button type="button" class="button" id="generate-backup-codes">
                <?php esc_html_e('Generate New Backup Codes', 'acemedia-login-block'); ?>
            </button>
            <div id="backup-codes-container" style="display: none; margin-top: 10px;">
                <p class="description">
                    <?php esc_html_e('Save these backup codes in a secure location. Each code can only be used once.', 'acemedia-login-block'); ?>
                </p>
                <pre id="backup-codes" style="background: #f1f1f1; padding: 10px; margin: 10px 0;"></pre>
                <button type="button" class="button" id="download-backup-codes">
                    <?php esc_html_e('Download Backup Codes', 'acemedia-login-block'); ?>
                </button>
            </div>
            <script>
            jQuery(document).ready(function($) {
$('#generate-backup-codes').on('click', function() {
    if (!confirm('<?php esc_html_e('Generating new backup codes will invalidate any existing codes. Continue?', 'acemedia-login-block'); ?>')) {
        return;
    }
    $.post(ajaxurl, {
        action: 'get_or_generate_backup_codes',
        _ajax_nonce: '<?php echo wp_create_nonce("get_or_generate_backup_codes"); ?>',
        force_new: true // Force new codes when explicitly requested
    }, function(response) {
        if (response.success) {
            $('#backup-codes').text(response.data.codes.join('\n'));
            $('#backup-codes-container').show();
        }
    });
});

                $('#download-backup-codes').on('click', function() {
        const codes = $('#backup-codes').text();
        const siteDomain = '<?php echo sanitize_file_name(parse_url(get_site_url(), PHP_URL_HOST)); ?>';
        const filename = siteDomain + '-2fa-backup-codes.txt';

        const blob = new Blob([codes], { type: 'text/plain' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(a.href);
    });
            });
            </script>
        </td>
    </tr>
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

    // If 2FA is disabled, reset the setup status
    if (!$is_2fa_enabled) {
        delete_user_meta($user_id, '_acemedia_2fa_setup_complete');
        delete_user_meta($user_id, '_acemedia_2fa_secret');
    }

    $selected_method = isset($_POST['acemedia_2fa_method']) ? sanitize_text_field($_POST['acemedia_2fa_method']) : 'email';
    update_user_meta($user_id, '_acemedia_2fa_method', $selected_method);
}
add_action('personal_options_update', 'acemedia_save_2fa_fields');
add_action('edit_user_profile_update', 'acemedia_save_2fa_fields');



function acemedia_user_needs_2fa_setup($user_id) {
    $user = get_userdata($user_id);
    if (!$user) {
        return false;
    }

    // Check if any of the user's roles require 2FA
    foreach ($user->roles as $role) {
        $role_2fa_required = (bool) get_option("acemedia_2fa_enabled_{$role}", false);
        if ($role_2fa_required) {
            $user_2fa_enabled = (bool) get_user_meta($user_id, '_acemedia_2fa_enabled', true);
            $user_2fa_setup_complete = (bool) get_user_meta($user_id, '_acemedia_2fa_setup_complete', true);

            // If 2FA is required for this role but not set up, return true
            if (!$user_2fa_setup_complete || !$user_2fa_enabled) {
                return true;
            }
        }
    }

    return false;
}

// Add admin notice for users who need to set up 2FA
function acemedia_2fa_setup_notice() {
    $user_id = get_current_user_id();
    if (acemedia_user_needs_2fa_setup($user_id)) {
        ?>
        <!-- Overlay and Warning -->
        <style>
            #wpwrap::before {
                content: '';
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(255, 255, 255, 0.9);
                z-index: 999998;
            }
            .acemedia-modal {
                display: none;
                position: fixed;
                z-index: 1000000;
                left: 0;
                top: 0;
                width: 100%;
                height: 100%;
                background-color: rgba(0,0,0,0.4);
            }
            .acemedia-modal-content {
                background-color: #fefefe;
                margin: 0px auto;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                padding: 20px;
                border: 1px solid #888;
                width: 80%;
                max-width: 600px;
                position: absolute;
                z-index: 1000001;
            }
        </style>

        <!-- Notice -->
        <div class="notice notice-warning" style="display: none;"></div>

        <!-- Modal -->
        <div id="acemedia-2fa-setup-modal" class="acemedia-modal">
            <div class="acemedia-modal-content">
                <h2><?php _e('Two-Factor Authentication Required', 'acemedia-login-block'); ?></h2>
                <p><?php _e('You must set up Two-Factor Authentication to continue using the admin area. Please choose your preferred authentication method:', 'acemedia-login-block'); ?></p>

                <table class="form-table">
                    <tr>
                        <th><label for="acemedia_2fa_method"><?php esc_html_e('Authentication Method', 'acemedia-login-block'); ?></label></th>
                        <td>
                            <select name="acemedia_2fa_method" id="acemedia_2fa_method">
                                <option value="email"><?php esc_html_e('Email Code', 'acemedia-login-block'); ?></option>
                                <option value="auth_app"><?php esc_html_e('Authentication App', 'acemedia-login-block'); ?></option>
                            </select>
                            <p class="description">
                                <?php esc_html_e('Email: Receive codes via email each time you log in.', 'acemedia-login-block'); ?><br>
                                <?php esc_html_e('Authentication App: Use an app like Google Authenticator for offline code generation.', 'acemedia-login-block'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr id="acemedia_2fa_qr_row" style="display: none;">
                        <th><label for="acemedia_2fa_qr"><?php esc_html_e('QR Code', 'acemedia-login-block'); ?></label></th>
                        <td>
                            <?php $qr_code_url = acemedia_generate_qr_code(get_current_user_id()); ?>
                            <img src="<?php echo esc_url($qr_code_url); ?>" alt="<?php esc_attr_e('2FA QR Code', 'acemedia-login-block'); ?>" />
                            <p class="description"><?php esc_html_e('Scan this QR code with your authentication app to get started.', 'acemedia-login-block'); ?></p>
                        </td>
                    </tr>
                </table>

                <div class="submit-wrapper" style="margin-top: 20px; text-align: right;">
                    <button type="button" class="button" id="download-2fa-backup-codes" style="margin-right: 10px;">
                        <?php _e('Download Backup Codes', 'acemedia-login-block'); ?>
                    </button>
                    <button type="button" class="button button-primary" onclick="acemediaSave2FASetup()">
                        <?php _e('Save and Enable 2FA', 'acemedia-login-block'); ?>
                    </button>
                </div>
            </div>
        </div>

        <script>
              jQuery(document).ready(function($) {
                $('#download-2fa-backup-codes').on('click', function() {
    $.post(ajaxurl, {
        action: 'get_or_generate_backup_codes',
        _ajax_nonce: '<?php echo wp_create_nonce("get_or_generate_backup_codes"); ?>',
        force_new: false // Don't force new codes in modal
    }, function(response) {
        if (response.success) {
            const siteDomain = '<?php echo sanitize_file_name(parse_url(get_site_url(), PHP_URL_HOST)); ?>';
            const filename = siteDomain + '-2fa-backup-codes.txt';
            const blob = new Blob([response.data.codes.join('\n')], { type: 'text/plain' });
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(a.href);
        }
    });
});

    });
            // Show modal immediately
            document.getElementById('acemedia-2fa-setup-modal').style.display = 'block';

            // Method change handler
            document.addEventListener('change', function(event) {
                if (event.target.id === 'acemedia_2fa_method') {
                    const qrRow = document.getElementById('acemedia_2fa_qr_row');
                    qrRow.style.display = event.target.value === 'auth_app' ? 'table-row' : 'none';

                    if (event.target.value === 'auth_app') {
                        const qrImage = qrRow.querySelector('img');
                        if (qrImage) {
                            const timestamp = new Date().getTime();
                            qrImage.src = qrImage.src.split('?')[0] + '?' + timestamp;
                        }
                    }
                }
            });

            function acemediaSave2FASetup() {
                const formData = new FormData();
                formData.append('action', 'acemedia_save_2fa_setup');
                formData.append('_ajax_nonce', '<?php echo wp_create_nonce("acemedia_2fa_setup"); ?>');
                formData.append('acemedia_2fa_enabled', '1');
                formData.append('acemedia_2fa_method', document.getElementById('acemedia_2fa_method').value);

                fetch(ajaxurl, {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        window.location.href = data.data.redirect;
                    } else {
                        alert(data.data.message || 'Error saving 2FA settings');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error saving 2FA settings');
                });
            }
        </script>
        <?php
    }
}

add_action('admin_notices', 'acemedia_2fa_setup_notice');

// Handle AJAX save
function acemedia_handle_2fa_setup() {
    check_ajax_referer('acemedia_2fa_setup');

    $user_id = get_current_user_id();
    if (!$user_id) {
        wp_send_json_error(['message' => 'Not logged in']);
        return;
    }

    $is_2fa_enabled = isset($_POST['acemedia_2fa_enabled']) ? 1 : 0;
    $selected_method = isset($_POST['acemedia_2fa_method']) ? sanitize_text_field($_POST['acemedia_2fa_method']) : 'email';

    update_user_meta($user_id, '_acemedia_2fa_enabled', $is_2fa_enabled);
    update_user_meta($user_id, '_acemedia_2fa_method', $selected_method);
    update_user_meta($user_id, '_acemedia_2fa_setup_complete', true);

    // Get user's roles and determine redirect URL
    $user = get_userdata($user_id);
    $redirect_url = admin_url(); // Default fallback

    // Check for role-specific redirects
    foreach ($user->roles as $role) {
        $role_redirect_key = "acemedia_login_block_redirect_{$role}";
        $role_redirect_url = get_option($role_redirect_key);

        if (!empty($role_redirect_url)) {
            $redirect_url = esc_url($role_redirect_url);
            break; // Stop after first matching role redirect
        }
    }

    wp_send_json_success([
        'message' => 'Settings saved',
        'redirect' => $redirect_url
    ]);
}
add_action('wp_ajax_acemedia_save_2fa_setup', 'acemedia_handle_2fa_setup');



function acemedia_enforce_2fa_setup() {
    // Don't run on AJAX requests or REST API endpoints
    if (wp_doing_ajax() || defined('REST_REQUEST')) {
        return;
    }

    // Get current user
    $user_id = get_current_user_id();
    if (!$user_id) {
        return;
    }

    // Check if user needs 2FA setup
    if (acemedia_user_needs_2fa_setup($user_id)) {
        // Always add the notice action
        add_action('admin_notices', 'acemedia_2fa_setup_notice');

        global $pagenow;

        // Allow access to profile.php and admin-ajax.php
        $allowed_pages = array(
            'profile.php',
            'admin-ajax.php'
        );

        // Only redirect if not already on an allowed page
        if (!in_array($pagenow, $allowed_pages)) {
            wp_safe_redirect(admin_url('profile.php'));
            exit;
        }
    }
}
add_action('admin_init', 'acemedia_enforce_2fa_setup', 1);


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
            'twoFALabel' => __('Enter Authentication Code', 'acemedia-login-block'),
            'twoFAPlaceholder' => __('Authentication Code', 'acemedia-login-block'),
            'submit2FA' => __('Verify', 'acemedia-login-block'),
            'verify2FAEndpoint' => rest_url('acemedia/v1/verify-2fa'),
            'check2FAEndpoint' => rest_url('acemedia/v1/check-2fa'),
            'nonce' => wp_create_nonce('wp_rest'),
        ]);
    }
}
add_action('login_enqueue_scripts', 'acemedia_enqueue_admin_login_script');






function acemedia_add_2fa_to_login_form() {
    ?>
    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function () {
            if (!aceLoginBlock.is2FAEnabled) {
                return;
            }

            const loginButton = document.querySelector('#wp-submit');
            if (loginButton) {
                loginButton.addEventListener('click', handleLoginAttempt);
            }

            function handleLoginAttempt(event) {
                event.preventDefault();
                const form = event.target.closest('form');

                if (form) {
                    form.removeEventListener('submit', handleFormSubmit);
                    form.addEventListener('submit', handleFormSubmit);

                    const formInputs = {
                        twoFactorState: createHiddenInput('two_factor_state', 'pending'),
                        csrfToken: createHiddenInput('csrf_token', aceLoginBlock.csrfToken),
                        twoFactorNonce: createHiddenInput('two_factor_nonce', ''),
                        twoFactorVerified: createHiddenInput('two_factor_verified', 'false')
                    };

                    Object.values(formInputs).forEach(input => {
                        if (!form.querySelector(`input[name="${input.name}"]`)) {
                            form.appendChild(input);
                        }
                    });

                    const usernameInput = form.querySelector('input[name="log"]');
                    const username = usernameInput ? usernameInput.value : '';

                    if (!username) {
                        alert('Please enter your username.');
                        return;
                    }

                    const sessionStart = Date.now();
                    createHiddenInput('session_start', sessionStart);

                    fetch(aceLoginBlock.check2FAEndpoint, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': aceLoginBlock.nonce,
                        },
                        body: JSON.stringify({
                            username,
                            timestamp: sessionStart,
                            csrf_token: aceLoginBlock.csrfToken
                        }),
                    })
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Network response was not ok');
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.needs2FASetup) {
                            formInputs.twoFactorState.value = 'setup';
                            formInputs.twoFactorVerified.value = 'true';
                            form.submit();
                        } else if (data.is2FAEnabled) {
                            formInputs.twoFactorState.value = 'verification';
                            formInputs.twoFactorNonce.value = data.nonce;
                            show2FAPrompt(form, username, formInputs);
                        } else {
                            formInputs.twoFactorState.value = 'disabled';
                            formInputs.twoFactorVerified.value = 'true';
                            form.submit();
                        }
                    })
                    .catch(error => {
                        console.error('Error checking 2FA status:', error);
                        alert('An error occurred while checking the 2FA status.');
                        formInputs.twoFactorState.value = 'error';
                    });
                }
            }

            function createHiddenInput(name, value) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = value;
                return input;
            }

            function handleFormSubmit(e) {
                const form = e.target;
                const twoFactorVerified = form.querySelector('input[name="two_factor_verified"]');
                const twoFactorState = form.querySelector('input[name="two_factor_state"]');

                if (!twoFactorVerified || twoFactorVerified.value !== 'true') {
                    e.preventDefault();
                    alert('Please complete two-factor authentication.');
                    return;
                }

                const sessionStart = form.querySelector('input[name="session_start"]');
                if (sessionStart && (Date.now() - parseInt(sessionStart.value, 10)) > 1800000) {
                    e.preventDefault();
                    alert('Session expired. Please refresh and try again.');
                    return;
                }
            }

            function show2FAPrompt(form, username) {
                let twoFAContainer = form.querySelector('.wp-block-acemedia-2fa-block');
                if (!twoFAContainer) {
                    const pwdInput = form.querySelector('input[name="pwd"]');
                    const pwdLabel = form.querySelector('label[for="user_pass"]');
                    const pwdShowToggle = form.querySelector('span[data-show-password="true"]');

                    const twoFALabel = document.createElement('label');
                    twoFALabel.setAttribute('for', '2fa_code');
                    twoFALabel.textContent = aceLoginBlock.twoFALabel || 'Enter Authentication Code';

                    const twoFAInput = document.createElement('input');
                    twoFAInput.type = 'text';
                    twoFAInput.name = '2fa_code';
                    twoFAInput.className = 'tfa-code-input';
                    twoFAInput.placeholder = aceLoginBlock.twoFAPlaceholder || 'Authentication Code';
                    twoFAInput.required = true;

                    pwdInput.style.display = 'none';
                    if (pwdLabel) pwdLabel.style.display = 'none';
                    if (pwdShowToggle) pwdShowToggle.style.display = 'none';

                    pwdInput.insertAdjacentElement('afterend', twoFAInput);
                    if (pwdLabel) {
                        pwdLabel.insertAdjacentElement('afterend', twoFALabel);
                    } else {
                        pwdInput.parentElement.insertBefore(twoFALabel, pwdInput);
                    }

                    const verify2FA = () => {
                        const twoFACode = twoFAInput.value;
                        if (!twoFACode) {
                            alert('Please enter your authentication code.');
                            return;
                        }

                        fetch(aceLoginBlock.verify2FAEndpoint, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-WP-Nonce': aceLoginBlock.nonce,
                            },
                            body: JSON.stringify({ code: twoFACode, username }),
                        })
                        .then((response) => response.json())
                        .then((data) => {
                            if (data.success) {
                                form.dataset.twoFactorVerified = 'true';
                                form.submit();
                            } else {
                                alert(data.message || 'Invalid authentication code. Please try again.');
                            }
                        })
                        .catch((error) => {
                            console.error('2FA verification failed:', error);
                            alert('An error occurred while verifying the authentication code.');
                        });
                    };

                    const loginButton = form.querySelector('#wp-submit');
                    loginButton.textContent = aceLoginBlock.submit2FA || 'Verify';
                    loginButton.removeEventListener('click', handleLoginAttempt);
                    loginButton.addEventListener('click', (e) => {
                        e.preventDefault();
                        verify2FA();
                    });

                    twoFAInput.addEventListener('keypress', function(e) {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            verify2FA();
                        }
                    });
                }
            }
        });
    </script>
    <?php
}
add_action('login_form', 'acemedia_add_2fa_to_login_form');

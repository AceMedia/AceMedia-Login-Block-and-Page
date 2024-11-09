<?php
/**
 * Plugin Name:       Ace Login Block
 * Description:       A block to replace the WordPress login page using a custom page and its template from the site editor.
 * Requires at least: 6.6
 * Tested up to:      6.7
 * Requires PHP:      7.2
 * Version:           0.423.0
 * Author:            Shane Rounce
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       acemedia-login-block
 * @package AceLoginBlock
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
    register_setting( 'acemedia_login_block_options_group', 'acemedia_login_block_custom_page', [
        'type' => 'integer',
    'description' => __( 'Custom page for login', 'acemedia-login-block' ),
        'sanitize_callback' => 'absint',
        'default' => 0,
    ] );

    // Register settings for redirect URLs
    $roles = wp_roles()->roles; // Get all WordPress roles
    foreach ( $roles as $role => $details ) {
        register_setting( 'acemedia_login_block_options_group', "acemedia_login_block_redirect_{$role}", [
            'type' => 'string',
            // Translators: %s is the role name
            'description' => sprintf( __( 'Redirect URL for %s', 'acemedia-login-block' ), $role ),
            'sanitize_callback' => 'esc_url_raw',
            'default' => '',
        ] );
    }

    // Add the settings page
    add_options_page(
        __( 'Login Settings', 'acemedia-login-block' ),
        __( 'Login Block', 'acemedia-login-block' ),
        'manage_options',
        'acemedia-login-block',
        'acemedia_login_block_render_settings_page'
    );
}
add_action( 'admin_menu', 'acemedia_login_block_register_settings' );

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
function acemedia_enqueue_login_script() {
    // Check if we're on a singular page or post and if the block is in the content.
    if ( has_block( 'acemedia/login-block' ) ) {
        wp_enqueue_script(
            'acemedia-login-frontend',
            plugin_dir_url( __FILE__ ) . 'build/ace-login.js',
            array(),
            filemtime( plugin_dir_path( __FILE__ ) . 'build/ace-login.js' ),
            true
        );

        // Localize the script to pass any necessary data
        wp_localize_script( 'acemedia-login-frontend', 'aceLoginBlock', array(
            'loginUrl' => site_url( 'wp-login.php' ),
            'redirectUrl' => site_url( '/wp-admin' ),
        ));
    }
}
add_action( 'wp_enqueue_scripts', 'acemedia_enqueue_login_script' );


// Handle the login redirect after a user logs in
add_action('wp_login', 'acemedia_login_redirect', 10, 2);
function acemedia_login_redirect($user_login, $user) {
    // Initialize the redirect URL with the default redirect if set
    if ( isset( $_POST['login_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['login_nonce'] ) ), 'login_action' ) ) {
        // The nonce is valid, process the form data
        $redirect_url = isset( $_POST['redirect_to'] ) ? esc_url( sanitize_text_field( wp_unslash( $_POST['redirect_to'] ) ) ) : admin_url();
    } else {
        // The nonce check failed, handle the error
        wp_die( esc_html__( 'Security check failed.', 'acemedia-login-block' ) );
    }


    // Check for role-specific redirects
    foreach ($user->roles as $role) {
        $role_redirect_key = "acemedia_login_block_redirect_{$role}"; // Adjust the option key
        $role_redirect_url = get_option($role_redirect_key);

        // If a role-specific redirect URL is found, use it
        if (!empty($role_redirect_url)) {
            $redirect_url = esc_url($role_redirect_url); // Use the full URL directly
            break; // Break after finding the first applicable redirect
        }
    }

    // Perform the redirect
    wp_safe_redirect($redirect_url);
    exit(); // Exit to ensure no further processing occurs
}



add_action('wp_logout', 'acemedia_logout_redirect');
function acemedia_logout_redirect() {
    $redirect_url = home_url(); // Change this to your desired logout redirect URL
    wp_safe_redirect($redirect_url);
    exit();
}

add_action('init', 'acemedia_handle_logout');
function acemedia_handle_logout() {
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
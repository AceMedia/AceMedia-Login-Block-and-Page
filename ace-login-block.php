<?php
/**
 * Plugin Name:       Ace Login Block
 * Description:       A block to replace the WordPress login page using a custom page and its template from the site editor.
 * Requires at least: 6.6
 * Requires PHP:      7.2
 * Version:           0.423.0
 * Author:            Shane Rounce
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ace-login-block
 * Tested up to:      6.7
 * @package AceLoginBlock
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * Registers the block and its settings.
 */
function create_block_ace_login_block_init() {
    register_block_type( __DIR__ . '/build/login-block' );
    register_block_type( __DIR__ . '/build/username-block' );
    register_block_type( __DIR__ . '/build/password-block' );
}
add_action( 'init', 'create_block_ace_login_block_init' );

/**
 * Register the custom login page setting and create the settings page.
 */
function ace_login_block_register_settings() {
    // Register the custom login page setting
    register_setting( 'ace_login_block_options_group', 'ace_login_block_custom_page', [
        'type' => 'integer',
        'description' => __( 'Custom page for login', 'ace-login-block' ),
        'sanitize_callback' => 'absint',
        'default' => 0,
    ] );

    // Register settings for redirect URLs
    $roles = wp_roles()->roles; // Get all WordPress roles
    foreach ( $roles as $role => $details ) {
        register_setting( 'ace_login_block_options_group', "ace_login_block_redirect_{$role}", [
            'type' => 'string',
            'description' => __( "Redirect URL for {$role}", 'ace-login-block' ),
            'sanitize_callback' => 'esc_url_raw',
            'default' => '',
        ] );
    }

    // Add the settings page
    add_options_page(
        __( 'Login Settings', 'ace-login-block' ),
        __( 'Login Block', 'ace-login-block' ),
        'manage_options',
        'ace-login-block',
        'ace_login_block_render_settings_page'
    );
}
add_action( 'admin_menu', 'ace_login_block_register_settings' );

// Global array to store admin pages
global $ace_admin_pages;
$ace_admin_pages = [];

// Function to clean title by removing <span> tags and their content
function remove_spans_and_content($title) {
    // Remove <span> tags and their content
    $cleaned_title = preg_replace('/<span[^>]*>.*?<\/span>/i', '', $title);
    $cleaned_title = strip_tags($cleaned_title);
    
    // Trim the title to remove any leading/trailing whitespace
    return trim($cleaned_title);
}

// In the capture function
function ace_capture_admin_pages() {
    global $ace_admin_pages;

    // Use the current menu items
    global $menu, $submenu;

    // Iterate over the top-level menu
    foreach ($menu as $menu_item) {
        // Get the menu slug
        $slug = isset($menu_item[2]) ? $menu_item[2] : '';

        // Add to the admin pages array if it has a capability and a title
        if (!empty($slug) && isset($menu_item[0]) && isset($menu_item[1])) {
            $title = remove_spans_and_content($menu_item[0]); // Remove <span> tags and their content

            // Only add if title doesn't contain "separator" followed by any digits
            if (!preg_match('/separator/i', trim($slug))) { // Use preg_match for regex check
                $ace_admin_pages[$slug] = [
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
                $title = remove_spans_and_content($sub_menu_item[0]); // Remove <span> tags and their content

            // Only add if title doesn't contain "separator" followed by any digits
            if (!preg_match('/separator/i', trim($slug))) {  // Use preg_match for regex check
                    $ace_admin_pages[$slug] = [
                        'title' => $title,
                        'capability' => $sub_menu_item[1],
                    ];
                }
            }
        }
    }
}

add_action('admin_menu', 'ace_capture_admin_pages');

// Render Login settings page

function ace_login_block_render_settings_page() {
    ?>
    <div class="wrap">
        <h1><?php _e('Ace Login Block Settings', 'ace-login-block'); ?></h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('ace_login_block_options_group');
            do_settings_sections('ace_login_block');
            ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row"><?php _e('Custom Login Page', 'ace-login-block'); ?></th>
                    <td><?php ace_login_block_custom_page_field_html(); ?></td>
                </tr>
                <?php
                // Get all WordPress roles
                $roles = wp_roles()->roles;

                // Get all public pages for front-end options
                $front_end_pages = get_pages();

                // Include the global admin pages array
                global $ace_admin_pages;

                foreach ($roles as $role => $details) {
                    $redirect_url = get_option("ace_login_block_redirect_{$role}", '');
                    ?>
                    <tr valign="top">
                        <th scope="row"><?php echo esc_html(ucfirst($role)); ?> <?php _e('Redirect URL', 'ace-login-block'); ?></th>
                        <td>
                            <label for="ace_login_block_redirect_<?php echo esc_attr($role); ?>">
                                <select id="ace_login_block_redirect_<?php echo esc_attr($role); ?>" name="ace_login_block_redirect_<?php echo esc_attr($role); ?>">
                                    <option value=""><?php _e('Default behaviour', 'ace-login-block'); ?></option>
                                    
                                    <!-- Frontend Pages Header -->
                                    <option disabled><?php _e('--- Frontend Pages ---', 'ace-login-block'); ?></option>
                                    <?php
                                    // Front-end pages options
                                    foreach ($front_end_pages as $page) : ?>
                                        <option value="<?php echo esc_attr(get_permalink($page->ID)); ?>" <?php selected(esc_url($redirect_url), get_permalink($page->ID)); ?>>
                                            <?php echo esc_html($page->post_title); ?>
                                        </option>
                                    <?php endforeach; ?>
                                    
                                    <!-- Admin Pages Header -->
                                    <option disabled><?php _e('--- Admin Pages ---', 'ace-login-block'); ?></option>
                                    <?php 
                                    // Admin pages options
                                    foreach ($ace_admin_pages as $page => $info) {
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
function ace_login_block_custom_page_field_html() {
    $custom_page_id = get_option( 'ace_login_block_custom_page', 0 );
    $pages = get_pages();

    echo '<select name="ace_login_block_custom_page">';
    echo '<option value="">' . esc_html__( 'Select a page', 'ace-login-block' ) . '</option>';

    foreach ( $pages as $page ) {
        $selected = selected( $custom_page_id, $page->ID, false );
        echo '<option value="' . esc_attr( $page->ID ) . '" ' . $selected . '>' . esc_html( $page->post_title ) . '</option>';
    }

    echo '</select>';
}

/**
 * Replace wp-login.php with the selected custom page content and template.
 */
function ace_login_block_load_custom_page_template() {
    $custom_page_id = get_option( 'ace_login_block_custom_page' );



    // Check if we're on wp-login.php and a custom page is set
    if ( strpos( $_SERVER['REQUEST_URI'], 'wp-login.php' ) !== false && $custom_page_id ) {
        
        // If the request method is POST, let WordPress handle the login process
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
            wp_die( __( 'Template not found for the login page.', 'ace-login-block' ) );
        }
    }
}
add_action( 'login_init', 'ace_login_block_load_custom_page_template' );

/**
 * Enqueue styles and scripts for the custom login page.
 */
function ace_login_block_login_enqueue_assets() {
    // Ensure scripts are using the WordPress Address (URL)
    $wp_address = get_bloginfo('wpurl');
    /*
        wp_enqueue_style(
            'ace-login-block-style',
            $wp_address . '/wp-content/plugins/ace-login-block/build/login-block.css',
            array(),
            filemtime( plugin_dir_path( __FILE__ ) . 'build/login-block.css' )
        );
    */

    wp_enqueue_script(
        'ace-login-block-js',
        plugin_dir_url( __FILE__ ) . 'build/login-toggle.js',
        array( 'wp-blocks', 'wp-element', 'wp-editor' ),
        filemtime( plugin_dir_url( __FILE__ ) . 'build/login-toggle.js' ),
        true
    );

    // Localize script to pass the site URL and nonce to JavaScript
    wp_localize_script('ace-login-block-js', 'aceLoginBlock', array(
        'loginUrl' => site_url('wp-login.php'),
        'redirectUrl' => site_url('/wp-admin'),
        'loginNonce' => wp_create_nonce('login_nonce')
    ));
}
add_action( 'wp_enqueue_scripts', 'ace_login_block_login_enqueue_assets' );


// Handle the login redirect after a user logs in
// Handle the login redirect after a user logs in
add_action('wp_login', 'custom_login_redirect', 10, 2);
function custom_login_redirect($user_login, $user) {
    // Initialize the redirect URL with the default redirect if set
    $redirect_url = isset($_POST['redirect_to']) ? esc_url($_POST['redirect_to']) : admin_url(); // Use admin_url() as default

    // Check for role-specific redirects
    foreach ($user->roles as $role) {
        $role_redirect_key = "ace_login_block_redirect_{$role}"; // Adjust the option key
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



add_action('wp_logout', 'custom_logout_redirect');
function custom_logout_redirect() {
    $redirect_url = home_url(); // Change this to your desired logout redirect URL
    wp_safe_redirect($redirect_url);
    exit();
}

add_action('init', 'custom_handle_logout');
function custom_handle_logout() {
    if (isset($_GET['action']) && $_GET['action'] === 'logout') {
        // Verify the nonce
        if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'log-out')) {
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
function ace_login_block_login_title( $title ) {
    return __( 'Login', 'ace-login-block' );
}
add_filter( 'login_title', 'ace_login_block_login_title' );

/**
 * Enqueue assets for the blocks.
 */
function ace_login_block_enqueue_assets() {
    wp_enqueue_script(
        'ace-login-toggle',
        plugin_dir_url( __FILE__ ) . 'build/login-toggle.js',
        array(),
        '1.0.0',
        true
    );

    wp_enqueue_script(
        'ace-login-block-editor',
        plugin_dir_url( __FILE__ ) . 'build/login-block.js',
        array( 'wp-blocks', 'wp-element', 'wp-editor' ),
        filemtime( plugin_dir_path( __FILE__ ) . 'build/login-block.js' ),
        true
    );

    wp_localize_script('ace-login-block-editor', 'aceLoginBlock', array(
        'loginUrl' => site_url('wp-login.php'),
        'redirectUrl' => site_url('/wp-admin'),
        'loginNonce' => wp_create_nonce('login_nonce'),
        'userRoles' => wp_get_current_user()->roles, // Pass the current user roles
    ));

    wp_enqueue_script(
        'ace-username-block-editor',
        plugin_dir_url( __FILE__ ) . 'build/username-block.js',
        array( 'wp-blocks', 'wp-element', 'wp-editor' ),
        filemtime( plugin_dir_path( __FILE__ ) . 'build/username-block.js' ),
        true
    );

    wp_enqueue_script(
        'ace-password-block-editor',
        plugin_dir_url( __FILE__ ) . 'build/password-block.js',
        array( 'wp-blocks', 'wp-element', 'wp-editor' ),
        filemtime( plugin_dir_path( __FILE__ ) . 'build/password-block.js' ),
        true
    );

    wp_enqueue_script(
        'ace-remember-me-block-editor',
        plugin_dir_url( __FILE__ ) . 'build/remember-me-block.js',
        array( 'wp-blocks', 'wp-element', 'wp-editor' ),
        filemtime( plugin_dir_path( __FILE__ ) . 'build/remember-me-block.js' ),
        true
    );
/*
    wp_enqueue_style(
        'ace-login-block-editor-style',
        plugin_dir_url( __FILE__ ) . 'build/login-block.css',
        array(),
        filemtime( plugin_dir_path( __FILE__ ) . 'build/login-block.css' )
    );
    */


}
add_action( 'enqueue_block_editor_assets', 'ace_login_block_enqueue_assets' );

function register_username_block() {
    register_block_type('ace/username-block', array(
        'render_callback' => 'render_username_block',
        'attributes' => array(
            'label' => array(
                'type' => 'string',
                'default' => __('Username', 'login-block'),
            ),
            'placeholder' => array(
                'type' => 'string',
                'default' => __('Username', 'login-block'),
            ),
        ),
    ));
}
add_action('init', 'register_username_block');

/**
 * Renders the Ace Username Block.
 *
 * @param array $attributes Block attributes.
 * @return string The HTML output for the username block.
 */
function render_username_block($attributes) {
    // Ensure the placeholder is properly sanitized
    $placeholder = isset($attributes['placeholder']) ? sanitize_text_field($attributes['placeholder']) : __('Username', 'login-block');

    // Escape the placeholder for safe HTML output
    $placeholder = esc_attr($placeholder);
    
    // Return the sanitized and escaped input field
    return '<input type="text" id="log" name="log" placeholder="' . $placeholder . '" required />';
}

/**
 * Registers the Ace Password Block.
 */
function register_password_block() {
    register_block_type('ace/password-block', array(
        'render_callback' => 'render_password_block',
        'attributes' => array(
            'label' => array(
                'type' => 'string',
                'default' => __('Password', 'login-block'),
            ),
            'showPassword' => array(
                'type' => 'boolean',
                'default' => false,
            ),
        ),
    ));
}
add_action('init', 'register_password_block');

/**
 * Renders the Ace Password Block.
 *
 * @param array $attributes Block attributes.
 * @return string The HTML output for the password block.
 */
function render_password_block($attributes) {
    // Ensure the placeholder is properly sanitized
    $placeholder = isset($attributes['placeholder']) ? sanitize_text_field($attributes['placeholder']) : __('Password', 'login-block');

    // Escape the placeholder for safe HTML output
    $placeholder = esc_attr($placeholder);

    // Ensure the showPassword attribute is boolean and safe for use in HTML attributes
    $show_password = !empty($attributes['showPassword']) ? 'true' : 'false';

    // Start building the HTML for the password input
    $html = '<input type="password" id="pwd" name="pwd" placeholder="' . $placeholder . '" required />';

    // Conditionally add the "Show Password" toggle if enabled
    if ($attributes['showPassword']) {
        $html .= '<span style="cursor:pointer" data-show-password="' . esc_attr($show_password) . '">' . esc_html__('Show Password', 'login-block') . '</span>';
    }

    return $html;
}




/**
 * Registers the Ace Remember Me Block.
 */
function register_remember_me_block() {
    register_block_type('ace/remember-me-block', array(
        'render_callback' => 'render_remember_me_block',
        'attributes' => array(
            'label' => array(
                'type' => 'string',
                'default' => __('Remember Me', 'login-block'),
            ),
            'checked' => array(
                'type' => 'boolean',
                'default' => false,
            ),
        ),
    ));
}
add_action('init', 'register_remember_me_block');

/**
 * Renders the Ace Remember Me Block.
 */
function render_remember_me_block($attributes) {
    $label = esc_html($attributes['label']);
    $checked = $attributes['checked'] ? 'checked' : '';
    return '<p><label><input type="checkbox" name="rememberme" ' . $checked . ' /> ' . $label . '</label></p>';
}
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
                if (\AceLoginBlock\Auth\Two_Factor::has_trusted_cookie() && empty($_POST['acemedia_logout_choice'])) {
                    $this->render_logout_trust_prompt();
                    exit;
                }

                $choice = isset($_POST['acemedia_logout_choice'])
                    ? sanitize_text_field(wp_unslash($_POST['acemedia_logout_choice']))
                    : 'keep';

                if ($choice === 'forget') {
                    \AceLoginBlock\Auth\Two_Factor::forget_trusted_device(get_current_user_id());
                }

                // Perform the logout
                wp_logout();

                // Redirect to the desired URL after logout
                $redirect_url = home_url(); // Change this to your desired logout redirect URL
                wp_safe_redirect($redirect_url);
                exit();
            }
        }
    }

    private function render_logout_trust_prompt() {
        $action_url = wp_login_url() . '?action=logout&_wpnonce=' . urlencode(sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? '')));

        if (function_exists('login_header')) {
            login_header(__('Log out', 'acemedia-login-block'));
        } else {
            echo '<!doctype html><html><head><meta charset="utf-8"></head><body>';
        }
        ?>
        <div id="login">
            <h1><?php esc_html_e('Log out', 'acemedia-login-block'); ?></h1>
            <p><?php esc_html_e('Do you want to keep this device remembered for 2FA next time you log in?', 'acemedia-login-block'); ?></p>
            <form method="post" action="<?php echo esc_url($action_url); ?>">
                <p>
                    <button type="submit" class="button" name="acemedia_logout_choice" value="keep">
                        <?php esc_html_e('Keep remembered', 'acemedia-login-block'); ?>
                    </button>
                    <button type="submit" class="button button-secondary" name="acemedia_logout_choice" value="forget">
                        <?php esc_html_e('Forget this device', 'acemedia-login-block'); ?>
                    </button>
                </p>
            </form>
        </div>
        <?php
        if (function_exists('login_footer')) {
            login_footer();
        } else {
            echo '</body></html>';
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
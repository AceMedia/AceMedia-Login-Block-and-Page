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
        add_filter('login_redirect', [$this, 'login_redirect'], 10, 3);
        add_filter('logout_redirect', [$this, 'logout_redirect'], 10, 3);
        add_action('init', [$this, 'handle_logout']);
        add_filter('login_title', [$this, 'login_block_login_title']);
    }

    /**
     * Handle the login redirect after a user logs in
     */
    public function login_redirect($redirect_url, $requested_redirect_url, $user) {
        if (!$user instanceof \WP_User) {
            return $redirect_url;
        }
        foreach ($user->roles as $role) {
            $role_redirect_url = get_option("acemedia_login_block_redirect_{$role}");
            if (!empty($role_redirect_url)) {
                return wp_validate_redirect($role_redirect_url, $redirect_url);
            }
        }
        return $redirect_url;
    }

    public function logout_redirect($redirect_url, $requested_redirect_url, $user) {
        if (!$requested_redirect_url && get_option('acemedia_login_block_custom_page', 0)) {
            return home_url();
        }
        return $redirect_url;
    }

    /**
     * Handle the logout process
     */
    public function handle_logout() {
        if (($GLOBALS['pagenow'] ?? '') === 'wp-login.php' && isset($_GET['action']) && $_GET['action'] === 'logout') {
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

                // Core performs logout and applies its requested redirect/filter.
                return;
            }
        }
    }

    private function render_logout_trust_prompt() {
        $args = ['action' => 'logout', '_wpnonce' => sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? ''))];
        if (!empty($_GET['redirect_to']) && is_string($_GET['redirect_to'])) {
            $args['redirect_to'] = wp_validate_redirect(esc_url_raw(wp_unslash($_GET['redirect_to'])), home_url());
        }
        $action_url = add_query_arg($args, wp_login_url());

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
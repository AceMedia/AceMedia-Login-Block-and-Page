<?php
/**
 * Security tab: login attempt log, unlocking and counters.
 *
 * @package AceLoginBlock
 */

namespace AceLoginBlock\Admin;

use AceLoginBlock\Auth\Login_Lockout;

if (!defined('ABSPATH')) {
    exit;
}

class Security_Page {
    public const PER_PAGE = 25;

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_post_acemedia_login_unlock', [$this, 'handle_unlock']);
        add_action('admin_post_acemedia_login_purge_log', [$this, 'handle_purge']);
    }

    public static function url(array $args = []) {
        return add_query_arg(
            array_merge(['page' => 'acemedia-login-block', 'tab' => 'security'], $args),
            admin_url('options-general.php')
        );
    }

    /**
     * Unlock an account, an IP address or an unknown username.
     */
    public function handle_unlock() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to do that.', 'acemedia-login-block'), 403);
        }
        check_admin_referer('acemedia_login_unlock');

        $type = isset($_POST['unlock_type']) ? sanitize_key(wp_unslash($_POST['unlock_type'])) : '';
        $key = isset($_POST['unlock_key']) ? sanitize_text_field(wp_unslash($_POST['unlock_key'])) : '';
        $lockout = Login_Lockout::instance();

        if ($type === 'user') {
            $lockout->unlock_user(absint($key));
        } elseif (in_array($type, ['ip', 'name'], true) && $key !== '') {
            $lockout->unlock_store_entry($type, $key);
        }

        wp_safe_redirect(self::url(['acemedia_notice' => 'unlocked']));
        exit;
    }

    /**
     * Run the retention cleanup now.
     */
    public function handle_purge() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to do that.', 'acemedia-login-block'), 403);
        }
        check_admin_referer('acemedia_login_purge_log');

        Login_Lockout::instance()->cleanup();

        wp_safe_redirect(self::url(['acemedia_notice' => 'purged']));
        exit;
    }

    public function render() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $lockout = Login_Lockout::instance();

        // Read-only list filters from the query string.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $filters = [
            'event' => isset($_GET['event']) ? sanitize_key(wp_unslash($_GET['event'])) : '',
            'search' => isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '',
            'ip' => isset($_GET['ip']) ? sanitize_text_field(wp_unslash($_GET['ip'])) : '',
            'lockout_only' => !empty($_GET['lockout_only']),
            'page' => isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1,
            'per_page' => self::PER_PAGE,
        ];
        $notice = isset($_GET['acemedia_notice']) ? sanitize_key(wp_unslash($_GET['acemedia_notice'])) : '';
        // phpcs:enable

        $result = $lockout->query_attempts($filters);
        $users = $lockout->get_flagged_users();
        $counters = $lockout->get_store_entries();
        $retention_days = $lockout->get_retention_days();
        $proxy_warning = null;
        if (get_option('acemedia_login_trusted_proxy_header', 'off') !== 'auto') {
            $dominant = $lockout->dominant_ip_share();
            if ($dominant && $dominant['share'] > 0.8) {
                $proxy_warning = $dominant;
            }
        }
        $total_pages = (int) ceil($result['total'] / self::PER_PAGE);

        include ACEMEDIA_LOGIN_BLOCK_PATH . 'includes/templates/admin/security-page.php';
    }
}

Security_Page::instance();

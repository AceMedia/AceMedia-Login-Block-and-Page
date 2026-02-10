<?php
namespace AceLoginBlock\Auth;

if (!defined('ABSPATH')) {
    exit;
}

class Login_Lockout {
    private const META_ATTEMPTS = '_acemedia_login_failed_attempts';
    private const META_LOCKED_UNTIL = '_acemedia_login_locked_until';

    public function __construct() {
        add_filter('authenticate', [$this, 'check_lockout'], 20, 3);
        add_action('wp_login_failed', [$this, 'record_failed_login'], 10, 1);
        add_action('wp_login', [$this, 'clear_on_success'], 10, 2);
    }

    public function check_lockout($user, $username, $password) {
        if (!$this->is_enabled()) {
            return $user;
        }

        if (!$username) {
            return $user;
        }

        $user_obj = $this->get_user_from_login($username);
        if (!$user_obj) {
            return $user;
        }

        $locked_until = (int) get_user_meta($user_obj->ID, self::META_LOCKED_UNTIL, true);
        if ($locked_until && time() < $locked_until) {
            $remaining_minutes = (int) ceil(($locked_until - time()) / MINUTE_IN_SECONDS);
            $unlock_time = wp_date(get_option('time_format'), $locked_until);
            return new \WP_Error(
                'acemedia_login_locked',
                sprintf(
                    __('Too many failed login attempts. Try again in %1$d minute(s) (at %2$s).', 'acemedia-login-block'),
                    max(1, $remaining_minutes),
                    $unlock_time
                )
            );
        }

        if ($locked_until && time() >= $locked_until) {
            delete_user_meta($user_obj->ID, self::META_LOCKED_UNTIL);
            delete_user_meta($user_obj->ID, self::META_ATTEMPTS);
        }

        return $user;
    }

    public function record_failed_login($username) {
        if (!$this->is_enabled()) {
            return;
        }

        $user_obj = $this->get_user_from_login($username);
        if (!$user_obj) {
            return;
        }

        $now = time();
        $window_seconds = $this->get_window_minutes() * MINUTE_IN_SECONDS;
        $attempts = get_user_meta($user_obj->ID, self::META_ATTEMPTS, true);

        if (!is_array($attempts)) {
            $attempts = [];
        }

        $first_attempt = isset($attempts['first']) ? (int) $attempts['first'] : 0;
        if (!$first_attempt || ($now - $first_attempt) > $window_seconds) {
            $attempts = [
                'count' => 1,
                'first' => $now,
                'last' => $now,
            ];
        } else {
            $attempts['count'] = isset($attempts['count']) ? ((int) $attempts['count'] + 1) : 1;
            $attempts['last'] = $now;
        }

        update_user_meta($user_obj->ID, self::META_ATTEMPTS, $attempts);

        if ((int) $attempts['count'] >= $this->get_max_attempts()) {
            $lock_seconds = $this->get_lock_minutes() * MINUTE_IN_SECONDS;
            update_user_meta($user_obj->ID, self::META_LOCKED_UNTIL, $now + $lock_seconds);
        }
    }

    public function clear_on_success($user_login, $user) {
        if (!$this->is_enabled() || !$user instanceof \WP_User) {
            return;
        }

        delete_user_meta($user->ID, self::META_ATTEMPTS);
        delete_user_meta($user->ID, self::META_LOCKED_UNTIL);
    }

    private function is_enabled() {
        return (bool) get_option('acemedia_login_lockout_enabled', true);
    }

    private function get_max_attempts() {
        $max = absint(get_option('acemedia_login_lockout_max_attempts', 5));
        return $max > 0 ? $max : 5;
    }

    private function get_window_minutes() {
        $window = absint(get_option('acemedia_login_lockout_window_minutes', 15));
        return $window > 0 ? $window : 15;
    }

    private function get_lock_minutes() {
        $duration = absint(get_option('acemedia_login_lockout_duration_minutes', 30));
        return $duration > 0 ? $duration : 30;
    }

    private function get_user_from_login($login) {
        $login = sanitize_text_field($login);
        $user = get_user_by('login', $login);

        if (!$user && is_email($login)) {
            $user = get_user_by('email', $login);
        }

        return $user ?: null;
    }
}

new Login_Lockout();

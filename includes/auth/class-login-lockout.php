<?php
namespace AceLoginBlock\Auth;

use AceLoginBlock\Utils\Logging;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Failed login tracking and lockout.
 *
 * Counts failures per account (user meta, kept for back-compat), per unknown
 * username and per IP address (capped option store), writes every failed or
 * blocked attempt to a small custom table and prunes it on a daily cron.
 */
class Login_Lockout {
    public const META_ATTEMPTS = '_acemedia_login_failed_attempts';
    public const META_LOCKED_UNTIL = '_acemedia_login_locked_until';
    public const META_TRUSTED_IPS = '_acemedia_login_trusted_ips';
    public const STORE_OPTION = 'acemedia_login_lockout_store';
    public const DB_VERSION_OPTION = 'acemedia_login_attempts_db_version';
    public const DB_VERSION = '1';
    public const CRON_HOOK = 'acemedia_login_attempts_cleanup';
    public const ERROR_CODE = 'acemedia_login_locked';
    private const STORE_CAP = 1000;
    private const TRUSTED_IPS_CAP = 10;

    /**
     * Cloudflare edge ranges (https://www.cloudflare.com/ips/), checked 2026-10.
     * Extend with the acemedia_login_lockout_trusted_proxies filter.
     */
    private const CLOUDFLARE_RANGES = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    private static $instance = null;

    /**
     * Lock type that caused the last blocked attempt in this request.
     *
     * @var string
     */
    private $blocked_type = '';

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', [$this, 'maybe_install']);
        add_action(self::CRON_HOOK, [$this, 'cleanup']);
        add_filter('authenticate', [$this, 'check_lockout'], 20, 3);
        add_action('wp_login_failed', [$this, 'record_failed_login'], 10, 2);
        add_action('wp_login', [$this, 'clear_on_success'], 10, 2);
    }

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'acemedia_login_attempts';
    }

    /**
     * Create or upgrade the attempts table and schedule the cleanup cron.
     */
    public function maybe_install() {
        if (get_option(self::DB_VERSION_OPTION) !== self::DB_VERSION) {
            global $wpdb;
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';

            $table = self::table_name();
            $charset = $wpdb->get_charset_collate();
            dbDelta("CREATE TABLE {$table} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                attempt_time datetime NOT NULL,
                username varchar(191) NOT NULL DEFAULT '',
                user_id bigint(20) unsigned NOT NULL DEFAULT 0,
                ip varchar(45) NOT NULL DEFAULT '',
                user_agent varchar(255) NOT NULL DEFAULT '',
                event varchar(20) NOT NULL DEFAULT 'failed',
                attempt_count int(10) unsigned NOT NULL DEFAULT 0,
                ip_count int(10) unsigned NOT NULL DEFAULT 0,
                lockout varchar(40) NOT NULL DEFAULT '',
                PRIMARY KEY  (id),
                KEY attempt_time (attempt_time),
                KEY ip (ip),
                KEY username (username)
            ) {$charset};");

            update_option(self::DB_VERSION_OPTION, self::DB_VERSION, false);
        }

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
        }
    }

    public function check_lockout($user, $username, $password) {
        if (!$this->is_enabled()) {
            return $user;
        }

        if (is_wp_error($user) && array_intersect(['empty_username', 'empty_password'], $user->get_error_codes())) {
            return $user;
        }

        $now = time();
        $ip = self::get_client_ip();

        if ($ip) {
            $ip_until = $this->get_store_lock('ip', self::ip_key($ip));
            if ($ip_until > $now) {
                $this->blocked_type = 'ip';
                return $this->locked_error($ip_until);
            }
        }

        if (!$username) {
            return $user;
        }

        $user_obj = $this->get_user_from_login($username);
        if (!$user_obj) {
            $name_until = $this->get_store_lock('name', $this->name_key($username));
            if ($name_until > $now) {
                $this->blocked_type = 'name';
                return $this->locked_error($name_until);
            }
            return $user;
        }

        $locked_until = (int) get_user_meta($user_obj->ID, self::META_LOCKED_UNTIL, true);
        if ($locked_until && $now < $locked_until) {
            // A correct password from an address this user has signed in from before
            // is not held back by somebody else's failures on the same account.
            if ($user instanceof \WP_User && (int) $user->ID === (int) $user_obj->ID && $this->is_trusted_ip($user_obj->ID, $ip)) {
                return $user;
            }
            $this->blocked_type = 'account';
            return $this->locked_error($locked_until);
        }

        if ($locked_until && $now >= $locked_until) {
            delete_user_meta($user_obj->ID, self::META_LOCKED_UNTIL);
            delete_user_meta($user_obj->ID, self::META_ATTEMPTS);
        }

        return $user;
    }

    public function record_failed_login($username, $error = null) {
        if (!$this->is_enabled()) {
            return;
        }

        $username = sanitize_user((string) $username);
        $ip = self::get_client_ip();
        $user_obj = $username !== '' ? $this->get_user_from_login($username) : null;

        if (is_wp_error($error) && in_array(self::ERROR_CODE, $error->get_error_codes(), true)) {
            $this->insert_row([
                'username' => $username,
                'user_id' => $user_obj ? $user_obj->ID : 0,
                'ip' => $ip,
                'event' => 'blocked',
                'lockout' => $this->blocked_type,
            ]);
            return;
        }

        $now = time();
        $window_seconds = $this->get_window_minutes() * MINUTE_IN_SECONDS;
        $lock_seconds = $this->get_lock_minutes() * MINUTE_IN_SECONDS;
        $locks = [];
        $count = 0;
        $ip_count = 0;

        if ($ip) {
            $ip_key = self::ip_key($ip);
            $entry = $this->bump_store('ip', $ip_key, $ip_key);
            $ip_count = (int) $entry['count'];
            $ip_max = $this->get_ip_max_attempts();
            if ($ip_max > 0 && $ip_count >= $ip_max && self::is_lockable_ip($ip)) {
                $this->set_store_lock('ip', $ip_key, $now + $lock_seconds);
                $locks[] = 'ip';
            }
        }

        if ($user_obj) {
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
            $count = (int) $attempts['count'];

            if ($count >= $this->get_max_attempts()) {
                update_user_meta($user_obj->ID, self::META_LOCKED_UNTIL, $now + $lock_seconds);
                $locks[] = 'account';
                Logging::log_event($user_obj->ID, 'login_lockout', [
                    'attempts' => $count,
                    'window_minutes' => $this->get_window_minutes(),
                    'lock_minutes' => $this->get_lock_minutes(),
                ], false);
            }
        } elseif ($username !== '') {
            $key = $this->name_key($username);
            $entry = $this->bump_store('name', $key, $username);
            $count = (int) $entry['count'];
            if ($count >= $this->get_max_attempts()) {
                $this->set_store_lock('name', $key, $now + $lock_seconds);
                $locks[] = 'name';
            }
        }

        $this->insert_row([
            'username' => $username,
            'user_id' => $user_obj ? $user_obj->ID : 0,
            'ip' => $ip,
            'event' => 'failed',
            'attempt_count' => $count,
            'ip_count' => $ip_count,
            'lockout' => implode(',', $locks),
        ]);

        if ($locks) {
            $this->maybe_notify($locks, $username, $ip, max($count, $ip_count), $now + $lock_seconds);
        }
    }

    public function clear_on_success($user_login, $user) {
        if (!$this->is_enabled() || !$user instanceof \WP_User) {
            return;
        }

        delete_user_meta($user->ID, self::META_ATTEMPTS);
        delete_user_meta($user->ID, self::META_LOCKED_UNTIL);
        $this->remember_trusted_ip($user->ID, self::get_client_ip());
    }

    /**
     * Daily cron: drop log rows past the retention period and expired counters.
     */
    public function cleanup() {
        global $wpdb;

        $cutoff = gmdate('Y-m-d H:i:s', time() - ($this->get_retention_days() * DAY_IN_SECONDS));
        $table = self::table_name();
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE attempt_time < %s", $cutoff)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        update_option(self::STORE_OPTION, $this->prune_store($this->get_store()), false);
    }

    /**
     * Unlock an account and clear its failed attempt count.
     */
    public function unlock_user($user_id) {
        delete_user_meta((int) $user_id, self::META_ATTEMPTS);
        delete_user_meta((int) $user_id, self::META_LOCKED_UNTIL);
    }

    /**
     * Remove an IP or unknown username entry from the counter store.
     */
    public function unlock_store_entry($bucket, $key) {
        $store = $this->get_store();
        if (isset($store[$bucket][$key])) {
            unset($store[$bucket][$key]);
            update_option(self::STORE_OPTION, $store, false);
        }
    }

    /**
     * Accounts with an active lock or a failed attempt count.
     *
     * @return array[]
     */
    public function get_flagged_users() {
        $users = get_users([
            'meta_query' => [
                'relation' => 'OR',
                ['key' => self::META_LOCKED_UNTIL, 'compare' => 'EXISTS'],
                ['key' => self::META_ATTEMPTS, 'compare' => 'EXISTS'],
            ],
            'fields' => ['ID', 'user_login'],
            'number' => 200,
        ]);

        $rows = [];
        foreach ($users as $user) {
            $attempts = get_user_meta($user->ID, self::META_ATTEMPTS, true);
            $locked_until = (int) get_user_meta($user->ID, self::META_LOCKED_UNTIL, true);
            $first = is_array($attempts) && isset($attempts['first']) ? (int) $attempts['first'] : 0;

            // Skip counts whose window has already passed; they reset on the next failure.
            if ($locked_until <= time() && (time() - $first) > $this->get_window_minutes() * MINUTE_IN_SECONDS) {
                continue;
            }

            $rows[] = [
                'id' => (int) $user->ID,
                'login' => $user->user_login,
                'count' => is_array($attempts) && isset($attempts['count']) ? (int) $attempts['count'] : 0,
                'locked_until' => $locked_until,
            ];
        }
        return $rows;
    }

    /**
     * Counter store entries (IPs and unknown usernames), newest first.
     *
     * @return array[]
     */
    public function get_store_entries() {
        $rows = [];
        foreach ($this->prune_store($this->get_store()) as $bucket => $entries) {
            foreach ($entries as $key => $entry) {
                $rows[] = array_merge($entry, ['bucket' => $bucket, 'key' => (string) $key]);
            }
        }
        usort($rows, function ($a, $b) {
            return (int) $b['last'] - (int) $a['last'];
        });
        return $rows;
    }

    /**
     * Query the attempts log.
     *
     * @param array $args event, search, ip, lockout_only, page, per_page.
     * @return array
     */
    public function query_attempts(array $args) {
        global $wpdb;

        $table = self::table_name();
        $where = ['1=1'];
        $params = [];

        if (!empty($args['event']) && in_array($args['event'], ['failed', 'blocked'], true)) {
            $where[] = 'event = %s';
            $params[] = $args['event'];
        }
        if (!empty($args['search'])) {
            $where[] = 'username LIKE %s';
            $params[] = '%' . $wpdb->esc_like($args['search']) . '%';
        }
        if (!empty($args['ip'])) {
            $where[] = 'ip = %s';
            $params[] = $args['ip'];
        }
        if (!empty($args['lockout_only'])) {
            $where[] = "lockout <> ''";
        }

        $per_page = max(1, (int) ($args['per_page'] ?? 25));
        $page = max(1, (int) ($args['page'] ?? 1));
        $where_sql = implode(' AND ', $where);

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
        $total = (int) $wpdb->get_var($params ? $wpdb->prepare($count_sql, $params) : $count_sql);

        $rows_sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY attempt_time DESC, id DESC LIMIT %d OFFSET %d";
        $rows = $wpdb->get_results($wpdb->prepare($rows_sql, array_merge($params, [$per_page, ($page - 1) * $per_page])), ARRAY_A);
        // phpcs:enable

        return [
            'rows' => $rows ?: [],
            'total' => $total,
        ];
    }

    /**
     * Client IP.
     *
     * Defaults to REMOTE_ADDR. A proxy header (CF-Connecting-IP, else the first
     * X-Forwarded-For hop) is only used when the "Trusted proxy header" setting
     * is on auto AND the request really arrived from a trusted proxy range, so a
     * spoofed header from anywhere else can never choose the IP.
     */
    public static function get_client_ip() {
        $remote = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        $remote = filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '';
        $ip = $remote;

        if ($remote && get_option('acemedia_login_trusted_proxy_header', 'off') === 'auto' && self::is_trusted_proxy($remote)) {
            $header = '';
            if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
                $header = sanitize_text_field(wp_unslash($_SERVER['HTTP_CF_CONNECTING_IP']));
            } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $hops = explode(',', sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR'])));
                $header = trim($hops[0]);
            }
            if ($header && filter_var($header, FILTER_VALIDATE_IP)) {
                $ip = $header;
            }
        }

        $filtered = (string) apply_filters('acemedia_login_lockout_client_ip', $ip, $remote);
        return filter_var($filtered, FILTER_VALIDATE_IP) ? $filtered : $ip;
    }

    /**
     * Counter key: the address itself for IPv4, the /64 network for IPv6.
     */
    public static function ip_key($ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $bin = inet_pton($ip);
            return inet_ntop(substr($bin, 0, 8) . str_repeat(chr(0), 8)) . '/64';
        }
        return $ip;
    }

    /**
     * Private, loopback and reserved addresses are never IP locked.
     */
    public static function is_lockable_ip($ip) {
        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    public static function is_trusted_proxy($ip) {
        $ranges = (array) apply_filters('acemedia_login_lockout_trusted_proxies', self::CLOUDFLARE_RANGES);
        foreach ($ranges as $cidr) {
            if (self::ip_in_cidr($ip, (string) $cidr)) {
                return true;
            }
        }
        return false;
    }

    private static function ip_in_cidr($ip, $cidr) {
        $parts = explode('/', $cidr, 2);
        $ip_bin = @inet_pton($ip); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        $net_bin = @inet_pton($parts[0]); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        if ($ip_bin === false || $net_bin === false || strlen($ip_bin) !== strlen($net_bin)) {
            return false;
        }
        $bits = isset($parts[1]) ? (int) $parts[1] : strlen($ip_bin) * 8;
        $bytes = intdiv($bits, 8);
        if (substr($ip_bin, 0, $bytes) !== substr($net_bin, 0, $bytes)) {
            return false;
        }
        $rem = $bits % 8;
        if ($rem === 0) {
            return true;
        }
        $mask = (0xff << (8 - $rem)) & 0xff;
        return (ord($ip_bin[$bytes]) & $mask) === (ord($net_bin[$bytes]) & $mask);
    }

    /**
     * Share of recent failed attempts that came from the single busiest IP.
     *
     * @return array|null ['ip' => string, 'share' => float, 'total' => int] or null.
     */
    public function dominant_ip_share($hours = 24, $min_rows = 20) {
        global $wpdb;
        $table = self::table_name();
        $since = gmdate('Y-m-d H:i:s', time() - $hours * HOUR_IN_SECONDS);
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE event = 'failed' AND attempt_time >= %s", $since));
        if ($total < $min_rows) {
            return null;
        }
        $top = $wpdb->get_row($wpdb->prepare("SELECT ip, COUNT(*) AS c FROM {$table} WHERE event = 'failed' AND attempt_time >= %s GROUP BY ip ORDER BY c DESC LIMIT 1", $since), ARRAY_A);
        // phpcs:enable
        if (!$top) {
            return null;
        }
        return ['ip' => $top['ip'], 'share' => (int) $top['c'] / $total, 'total' => $total];
    }

    private function locked_error($locked_until) {
        $remaining_minutes = (int) ceil(($locked_until - time()) / MINUTE_IN_SECONDS);

        // Same wording for every lock type so it does not reveal whether a username exists.
        return new \WP_Error(
            self::ERROR_CODE,
            sprintf(
                /* translators: 1: minutes remaining, 2: time the lock ends. */
                __('Too many failed login attempts. Try again in %1$d minute(s) (at %2$s).', 'acemedia-login-block'),
                max(1, $remaining_minutes),
                wp_date(get_option('time_format'), $locked_until)
            )
        );
    }

    private function insert_row(array $data) {
        global $wpdb;

        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';

        $wpdb->insert(
            self::table_name(),
            [
                'attempt_time' => current_time('mysql', true),
                'username' => mb_substr((string) ($data['username'] ?? ''), 0, 191),
                'user_id' => (int) ($data['user_id'] ?? 0),
                'ip' => (string) ($data['ip'] ?? ''),
                'user_agent' => mb_substr($user_agent, 0, 255),
                'event' => (string) ($data['event'] ?? 'failed'),
                'attempt_count' => (int) ($data['attempt_count'] ?? 0),
                'ip_count' => (int) ($data['ip_count'] ?? 0),
                'lockout' => (string) ($data['lockout'] ?? ''),
            ],
            ['%s', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s']
        );
    }

    private function maybe_notify(array $locks, $username, $ip, $count, $locked_until) {
        if (!get_option('acemedia_login_lockout_notify', true)) {
            return;
        }

        // Account lockouts already mail through Logging when security alerts are on.
        if ($locks === ['account'] && get_option('acemedia_security_alerts_enabled', false)) {
            return;
        }

        // At most one email every five minutes so a spray cannot flood the inbox.
        if (get_transient('acemedia_login_lockout_mailed')) {
            return;
        }
        set_transient('acemedia_login_lockout_mailed', 1, 5 * MINUTE_IN_SECONDS);

        $recipient = sanitize_email(get_option('acemedia_security_alerts_email', ''));
        if (empty($recipient)) {
            $recipient = get_option('admin_email');
        }
        if (empty($recipient)) {
            return;
        }

        $labels = [
            'account' => __('account', 'acemedia-login-block'),
            'name' => __('unknown username', 'acemedia-login-block'),
            'ip' => __('IP address', 'acemedia-login-block'),
        ];
        $types = array_map(function ($lock) use ($labels) {
            return $labels[$lock] ?? $lock;
        }, $locks);

        $subject = sprintf(
            /* translators: %s: site name. */
            __('[%s] Login lockout', 'acemedia-login-block'),
            wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES)
        );

        $lines = [
            sprintf(__('A login lockout was triggered on %s.', 'acemedia-login-block'), home_url()),
            '',
            sprintf(__('Locked: %s', 'acemedia-login-block'), implode(', ', $types)),
            sprintf(__('Username tried: %s', 'acemedia-login-block'), $username !== '' ? $username : '-'),
            sprintf(__('IP address: %s', 'acemedia-login-block'), $ip ?: '-'),
            sprintf(__('Failed attempts: %d', 'acemedia-login-block'), $count),
            sprintf(__('Locked until: %s', 'acemedia-login-block'), wp_date(get_option('date_format') . ' ' . get_option('time_format'), $locked_until)),
            '',
            sprintf(__('Review or unlock: %s', 'acemedia-login-block'), admin_url('options-general.php?page=acemedia-login-block&tab=security')),
            '',
            __('You can turn these emails off in the Ace Login Block settings.', 'acemedia-login-block'),
        ];

        wp_mail($recipient, $subject, implode("\n", $lines));
    }

    private function get_store() {
        $store = get_option(self::STORE_OPTION, []);
        if (!is_array($store)) {
            $store = [];
        }
        $store += ['ip' => [], 'name' => []];
        return $store;
    }

    private function prune_store(array $store) {
        $now = time();
        $window_seconds = $this->get_window_minutes() * MINUTE_IN_SECONDS;

        foreach (['ip', 'name'] as $bucket) {
            $entries = isset($store[$bucket]) && is_array($store[$bucket]) ? $store[$bucket] : [];
            foreach ($entries as $key => $entry) {
                $expired_window = ($now - (int) ($entry['first'] ?? 0)) > $window_seconds;
                $expired_lock = (int) ($entry['locked_until'] ?? 0) <= $now;
                if ($expired_window && $expired_lock) {
                    unset($entries[$key]);
                }
            }
            if (count($entries) > self::STORE_CAP) {
                uasort($entries, function ($a, $b) {
                    return (int) $b['last'] - (int) $a['last'];
                });
                $entries = array_slice($entries, 0, self::STORE_CAP, true);
            }
            $store[$bucket] = $entries;
        }

        return $store;
    }

    private function bump_store($bucket, $key, $label) {
        $now = time();
        $window_seconds = $this->get_window_minutes() * MINUTE_IN_SECONDS;
        $store = $this->prune_store($this->get_store());
        $entry = $store[$bucket][$key] ?? null;

        if (!$entry || ($now - (int) $entry['first']) > $window_seconds) {
            $entry = [
                'count' => 1,
                'first' => $now,
                'last' => $now,
                'locked_until' => (int) ($entry['locked_until'] ?? 0),
                'label' => mb_substr((string) $label, 0, 191),
            ];
        } else {
            $entry['count'] = (int) $entry['count'] + 1;
            $entry['last'] = $now;
        }

        $store[$bucket][$key] = $entry;
        update_option(self::STORE_OPTION, $store, false);

        return $entry;
    }

    private function set_store_lock($bucket, $key, $until) {
        $store = $this->get_store();
        if (isset($store[$bucket][$key])) {
            $store[$bucket][$key]['locked_until'] = (int) $until;
            update_option(self::STORE_OPTION, $store, false);
        }
    }

    private function get_store_lock($bucket, $key) {
        $store = $this->get_store();
        return isset($store[$bucket][$key]['locked_until']) ? (int) $store[$bucket][$key]['locked_until'] : 0;
    }

    private function name_key($username) {
        return md5(strtolower(trim((string) $username)));
    }

    private function is_trusted_ip($user_id, $ip) {
        if (!$ip) {
            return false;
        }
        $trusted = get_user_meta($user_id, self::META_TRUSTED_IPS, true);
        return is_array($trusted) && in_array(wp_hash($ip), $trusted, true);
    }

    private function remember_trusted_ip($user_id, $ip) {
        if (!$ip) {
            return;
        }
        $hash = wp_hash($ip);
        $trusted = get_user_meta($user_id, self::META_TRUSTED_IPS, true);
        $trusted = is_array($trusted) ? array_values(array_diff($trusted, [$hash])) : [];
        array_unshift($trusted, $hash);
        update_user_meta($user_id, self::META_TRUSTED_IPS, array_slice($trusted, 0, self::TRUSTED_IPS_CAP));
    }

    private function is_enabled() {
        return (bool) get_option('acemedia_login_lockout_enabled', true);
    }

    private function get_max_attempts() {
        $max = absint(get_option('acemedia_login_lockout_max_attempts', 5));
        return $max > 0 ? $max : 5;
    }

    private function get_ip_max_attempts() {
        // 0 turns IP locking off.
        return absint(get_option('acemedia_login_lockout_ip_max_attempts', 20));
    }

    private function get_window_minutes() {
        $window = absint(get_option('acemedia_login_lockout_window_minutes', 15));
        return $window > 0 ? $window : 15;
    }

    private function get_lock_minutes() {
        $duration = absint(get_option('acemedia_login_lockout_duration_minutes', 30));
        return $duration > 0 ? $duration : 30;
    }

    public function get_retention_days() {
        $days = absint(get_option('acemedia_login_log_retention_days', 30));
        return $days > 0 ? $days : 30;
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

Login_Lockout::instance();

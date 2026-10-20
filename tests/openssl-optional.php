<?php
/**
 * Passkeys must switch off on their own when OpenSSL is missing, without taking the rest of the
 * plugin down and without quietly dropping anyone's second factor.
 *
 * Run twice against a disposable WordPress on localhost, with the plugin active:
 *
 *   ACEMEDIA_TEST_DISPOSABLE=1 wp eval-file tests/openssl-optional.php
 *   ACEMEDIA_TEST_DISPOSABLE=1 ACE_TEST_NO_OPENSSL=1 wp eval-file tests/openssl-optional.php
 *
 * The second run needs this mu-plugin so the plugin sees OpenSSL as missing when it loads:
 *
 *   <?php if ( getenv( 'ACE_TEST_NO_OPENSSL' ) === '1' ) { add_filter( 'acemedia_login_block_openssl_available', '__return_false' ); }
 */
function ace_expect( $ok, $label ) {
    if ( ! $ok ) {
        throw new RuntimeException( 'FAIL: ' . $label );
    }
    echo "PASS: $label\n";
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || getenv( 'ACEMEDIA_TEST_DISPOSABLE' ) !== '1'
    || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) {
    throw new RuntimeException( 'Run only with WP-CLI on a disposable localhost site and ACEMEDIA_TEST_DISPOSABLE=1.' );
}

$no_openssl = getenv( 'ACE_TEST_NO_OPENSSL' ) === '1';
echo $no_openssl ? "== Mode: OpenSSL treated as MISSING ==\n" : "== Mode: OpenSSL available ==\n";

ace_expect( function_exists( 'acemedia_login_block_passkeys_available' ), 'The plugin has loaded (it is not in its fail-closed state)' );
ace_expect( class_exists( '\\AceLoginBlock\\Auth\\Two_Factor' ), 'Two-factor code is loaded' );
ace_expect( acemedia_login_block_passkeys_available() === ! $no_openssl, 'Passkey availability follows OpenSSL' );

// REST routes.
$routes = rest_get_server()->get_routes();
$has_passkey_routes = isset( $routes['/acemedia/v1/passkeys/login-options'] );
ace_expect( $has_passkey_routes === ! $no_openssl, 'Passkey REST routes are ' . ( $no_openssl ? 'absent' : 'present' ) );
ace_expect( isset( $routes['/acemedia/v1/check-2fa'] ), 'The 2FA check route is still registered' );

$made = array();
$make = function ( $role = 'subscriber' ) use ( &$made ) {
    $name = 'ace-opt-' . strtolower( wp_generate_password( 10, false, false ) );
    $pass = wp_generate_password( 32 );
    $id   = wp_create_user( $name, $pass, $name . '@example.invalid' );
    if ( is_wp_error( $id ) ) {
        throw new RuntimeException( 'Could not create fixture account.' );
    }
    $u = get_userdata( $id );
    $u->set_role( $role );
    $made[] = $id;
    return array( $u, $pass );
};
$auth = function ( $u, $pass, $post = array() ) {
    $_POST = $post;
    $r = wp_authenticate( $u->user_login, $pass );
    $_POST = array();
    return $r;
};
$code_of = function ( $r ) {
    return is_wp_error( $r ) ? $r->get_error_code() : 'user';
};

try {
    // 1. A plain account with no 2FA signs in with just a password.
    list( $plain, $plain_pass ) = $make();
    ace_expect( $auth( $plain, $plain_pass ) instanceof WP_User, 'Password login works for an account with no 2FA' );
    ace_expect( is_wp_error( $auth( $plain, 'wrong-password' ) ), 'A wrong password is still refused' );

    // 2. Email 2FA is unaffected.
    list( $mail, $mail_pass ) = $make();
    update_user_meta( $mail->ID, '_acemedia_2fa_enabled', 1 );
    update_user_meta( $mail->ID, '_acemedia_2fa_method', 'email' );
    update_user_meta( $mail->ID, '_acemedia_2fa_code', 'FIXTURE' );
    update_user_meta( $mail->ID, '_acemedia_2fa_code_time', time() );
    delete_user_meta( $mail->ID, '_acemedia_2fa_attempts' );
    ace_expect( $auth( $mail, $mail_pass, array( '2fa_code' => 'FIXTURE' ) ) instanceof WP_User, 'Email 2FA code still completes login' );
    ace_expect( is_wp_error( $auth( $mail, $mail_pass, array( '2fa_code' => 'FIXTURE' ) ) ), 'Email 2FA code is still single-use' );

    // 3. A role that REQUIRES a passkey is refused, never waved through.
    list( $needs, $needs_pass ) = $make( 'subscriber' );
    update_option( 'acemedia_passkey_2fa_required_subscriber', 1 );
    $r = $auth( $needs, $needs_pass );
    ace_expect( is_wp_error( $r ), 'An account whose role requires a passkey is NOT let in on a password alone' );
    $expected = $no_openssl ? 'passkeys_unavailable' : 'passkey_required_not_configured';
    ace_expect( $code_of( $r ) === $expected, 'It is refused with the right reason (' . $expected . ')' );
    ace_expect( is_wp_error( $auth( $needs, $needs_pass, array( '2fa_code' => '123456' ) ) ), 'A made-up code does not get it past the passkey requirement' );
    delete_option( 'acemedia_passkey_2fa_required_subscriber' );

    // 4. A user who chose passkey as their method, with backup codes as the recovery route.
    list( $chosen, $chosen_pass ) = $make();
    update_user_meta( $chosen->ID, '_acemedia_2fa_enabled', 1 );
    update_user_meta( $chosen->ID, '_acemedia_2fa_method', 'passkey' );
    update_user_meta( $chosen->ID, '_acemedia_2fa_backup_codes', array( array( 'hash' => wp_hash_password( 'BACKUP-FIXTURE' ) ) ) );
    delete_user_meta( $chosen->ID, '_acemedia_2fa_attempts' );
    ace_expect( is_wp_error( $auth( $chosen, $chosen_pass ) ), 'A passkey-method account is not let in on a password alone' );
    ace_expect( is_wp_error( $auth( $chosen, $chosen_pass, array( '2fa_code' => '000000' ) ) ), 'A wrong code is refused for a passkey-method account' );
    ace_expect( $auth( $chosen, $chosen_pass, array( '2fa_code' => 'BACKUP-FIXTURE' ) ) instanceof WP_User, 'A valid backup code still gets a passkey-method account in' );
    ace_expect( is_wp_error( $auth( $chosen, $chosen_pass, array( '2fa_code' => 'BACKUP-FIXTURE' ) ) ), 'That backup code cannot be used twice' );

    // 5. The administrator notice appears only when passkeys are off.
    $admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
    wp_set_current_user( $admin->ID );
    set_current_screen( 'plugins' );
    ob_start();
    ( new \AceLoginBlock\Admin\Admin_Notices() )->display_passkeys_unavailable_notice();
    $notice = ob_get_clean();
    ace_expect( ( false !== strpos( $notice, 'passkeys are switched off' ) ) === $no_openssl, 'The admin notice is shown only when OpenSSL is missing' );
    wp_set_current_user( 0 );
} finally {
    foreach ( $made as $id ) {
        if ( is_multisite() ) {
            require_once ABSPATH . 'wp-admin/includes/ms.php';
            wpmu_delete_user( $id );
        } else {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user( $id );
        }
    }
    delete_option( 'acemedia_passkey_2fa_required_subscriber' );
}
echo $no_openssl ? "OpenSSL-missing checks complete.\n" : "OpenSSL-available checks complete.\n";

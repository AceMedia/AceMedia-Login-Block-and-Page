=== Ace Login Block ===
Contributors: shanerounce  
Tags: login, block, custom login, WordPress, Gutenberg  
Requires at least: 6.6 
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 0.428.0  
License: GPLv2 or later  
License URI: https://www.gnu.org/licenses/gpl-2.0.html  

Customize your WordPress login page with a fully-integrated Gutenberg block, giving you complete control over the design and functionality.

== Description ==

Ace Login Block allows you to replace the default WordPress login page with a custom page of your choosing, all designed and managed within the block editor. This lets you create a branded login experience for your users while leveraging the flexibility and ease of Gutenberg.

*Features include:*

* Replace the default WordPress login page with a custom block-based design.
* Full integration with the block editor, giving you complete control over the login page layout.
* Allow the default WordPress login functionality for POST requests to ensure smooth login submissions.
* Customize redirects and add additional fields or branding elements to the login page.
* Prevent further execution of the default `wp-login.php` after your custom login template loads.
* Role-based redirects and per-role 2FA requirements.
* Passkeys (security keys) for passwordless login and as a 2FA bypass.
* Email and authenticator app 2FA, plus backup codes.
* Remembered devices that skip 2FA only (password still required).
* Login lockout after repeated failed attempts.
* Passkey policy settings: RP ID override, attestation preference, and per-role passwordless control.

Ace Login Block provides a seamless way to craft unique, branded login experiences while ensuring compatibility with WordPress’s login handling.

== Installation ==

This section describes how to install the plugin and get it working.

1. Upload the plugin files to the `/wp-content/plugins/ace-login-block` directory, or install the plugin through the WordPress plugin screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Navigate to the "Ace Login Block" settings and select the page to use for your custom login page.

== Frequently Asked Questions ==

= How do I customize my login page? =

Once the plugin is activated, go to the "Ace Login Block" settings in your WordPress dashboard and select the page you want to use as your custom login page. You can then use the block editor to design the page.

= Will WordPress still handle logins? =

Yes, WordPress will continue to handle all login submissions via POST requests, ensuring that authentication continues to work normally. Ace Login Block simply replaces the display of the login page with your custom page design for GET requests.

= Can I add custom fields to my login page? =

Yes, you can add custom blocks, text, images, or any other elements within the block editor to fully customize the layout of your login page.

= What happens if I disable the plugin? =

If you disable Ace Login Block, WordPress will revert to the default `wp-login.php` page for login access.

= Do remembered devices bypass my password? =

No. Remembered devices only skip the 2FA step. Password entry is still required.

= Do passkeys require HTTPS? =

Yes. Passkeys require a secure context (HTTPS or `http://localhost`).

== Changelog ==

= 0.428.0 =
Release Date: 2026-07-01
Security release — hardening of the two-factor and REST layer:
* Email 2FA codes now expire after 5 minutes and are single-use (previously never expired).
* Two-factor code verification now uses constant-time comparison (hash_equals).
* Reduced the 2FA attempt rate limit from 1000 to 5 per hour (filterable) to prevent brute-forcing.
* TOTP secrets are now encrypted at rest with libsodium (legacy plaintext secrets remain readable and are upgraded on next save).
* REST 2FA endpoints no longer disclose whether a username exists (prevents user enumeration).
* Sanitised all 2FA code inputs; set an explicit TOTP verification window (±30s).
* Backup-code endpoint no longer returns hash-derived placeholders after generation.
* Renamed internal encryption helpers off the reserved wp_ prefix for WordPress.org compliance.

= 0.427.0 =
Release Date: 2026-02-24
* Fixed inconsistent 2FA form state handling that could show false authentication popups during successful login.
* Replaced disruptive login alerts with inline status messages across custom login and wp-login flows.
* Improved browser compatibility and secure-context messaging for passkeys.
* Added admin guidance for security key setup, including Flipper Zero/FIDO2 usage notes.
* Improved passkey registration feedback and request handling reliability in user profile screens.

= 0.426.0 =
Release Date: 2024-12-05
* Fixed the selector for the form submission button.

= 0.425.0 =
Release Date: 2024-11-11
* Allows users to be redirected based on role-specific settings, even if no custom login page is set.

= 0.424.0 =
Release Date: 2024-11-11
* Ensures login block functionality is only active when a custom login page override is set.
* Prevents interference with the default WordPress login process when no override page is configured.

= 0.423 =
Release Date: 2024-10-12
* New Feature: Implemented dynamic login redirects based on user roles, allowing specific redirect URLs for each role within the settings.
* Enhancement: Updated ace-login-block.php to handle dynamic redirects, ensuring users are sent to the appropriate page after logging in based on their role.
* Enhancement: Modified package-lock.json and package.json to include new dependencies required for the updated functionality.
* Enhancement: Enhanced src/block.json to support role-based page listings, making it easier to manage page options for each role.
* Enhancement: Updated src/login-block.js to dynamically list available pages per role, improving the settings interface for selecting redirect URLs.
* Important Note: Ensure that roles are properly configured in the WordPress admin to utilize the dynamic redirect feature effectively.

= 0.422 =
Release Date: 2024-10-12
* New Feature: Introduced a custom login block structure to fully control the layout of the form using native block interactions.
* New Feature: Added new blocks for the username and password input fields.
* Enhancement: Dynamic form action URL is now set using localized data from PHP.
* Enhancement: Added nonce handling to the login form for improved security.
* Enhancement: Improved reliability of redirect handling after login.
* Bug Fix: Resolved an issue with form submission not being detected as a POST request.
* Bug Fix: Fixed redirection issues caused by interception from the custom page template.
* Bug Fix: Improved logout handling to ensure users are correctly redirected after logging out.
* Important Note: Ensure the logout link includes the correct action=logout parameter and nonce.

= 0.421 =
Release Date: 2024-10-11
* Bug Fix: Resolved issues with form redirection that affected login functionality in v0.420.
* Enhancement: Added a new option to allow users to show the "Show Password" toggle in the login block.

= 0.420 =
Release Date: 2024-10-11 (Pre-Release)
* Initial Release: First release of the Ace Login Block plugin.
* Key Features:
    - Custom Login Page: Replace the default wp-login.php form with a page created in the block editor.
    - Post Request Handling: Securely handles login form submissions.
    - Seamless Template Loading: Automatically loads the selected custom page template.
    - Block Editor Integration: Customize your login page using blocks.
    - Debugging: Debug messages included to assist with development.
* Important Note: Known issues with form redirection in this version, recommended to use v0.421 for better performance.

== Source ==

The source code for this plugin, including unminified JavaScript and CSS, is available on GitHub:  
[GitHub Repository](https://github.com/AceMedia/AceMedia-Login-Block-and-Page)

== License ==

This plugin is licensed under the GPLv2 (or later) as it is required to follow WordPress.org repository rules.

# Login Block 0.429.0 candidate validation

Validation date: 20 October 2026. This is a local candidate; these checks do not certify every plugin, PHP extension, browser or authenticator combination.

## Executed checks

| Environment | Evidence |
| --- | --- |
| PHP 8.2, 8.3, 8.4 and 8.5 | All 22 first-party PHP files parse; 28 bootstrap/integration fixture assertions pass per runtime. |
| Actual PHP 8.0.30 and 8.1.34 CLI binaries | Bootstrap parses and returns safely with a final authentication rejection. Dependency classes are not loaded. These are bootstrap checks, not complete WordPress runtime tests on unsupported PHP. |
| WordPress 6.6, PHP 8.2.32, SQLite, Chromium | Fresh installation and activation; 18 real WordPress authentication assertions pass, including email, backup and TOTP replay/rate-budget behaviour. Native browser login and profile/passkey enrolment UI render with no JavaScript errors. |
| WordPress 6.9, PHP 8.4, SQLite, Chromium | Native password-factor passkey login reaches the profile screen using a CDP virtual authenticator. |
| WordPress 7.1.3, PHP 8.4, MariaDB, Chromium | The 18 real WordPress authentication assertions pass on a single site, with the plugin network-activated on multisite, and inside a subsite. A block-built login page renders, a wrong password is refused, a correct one logs in and redirects, and the four blocks insert, save and reload in the block editor. WooCommerce 11.2.1 customer login through My Account still works alongside the plugin. |
| Real WebAuthn signatures | Registration succeeds; passwordless options require user verification; a verified assertion succeeds; a deliberately unverified signed passwordless assertion is rejected; an unverified security key can produce a second-factor token, without directly signing in. |
| Independent authentication filter | A separately registered account restriction vetoes an otherwise valid passkey. |
| Concurrent WordPress workers | A transient state is consumed once across two workers without persistent cache, and once across four workers with Redis Object Cache 3.0.0 and an isolated Redis 7.0.15 instance. Owner-safe lock release and stale-lock cleanup also pass. |
| Public WordPress.org 0.426.0 upgrade | Existing custom-page option, role redirect and saved block content hash survive replacement with the candidate; saved username/password/remember-me fields still render. |
| Saved custom page, Twenty Twenty-Five | Login button and Enter key work; wrong-password retry works; role and requested redirects work; no JavaScript page errors were observed. |
| Installable ZIP | Canonical `acemedia-login-block/` root, explicit input list, per-file SHA-256 verification and dependency autoload checks on PHP 8.2–8.5. |

The WordPress.org public archive was downloaded for the upgrade check. The baseline GitHub branch was fetched read-only and matched the local base commit before candidate edits. No production accounts or client material were used.

## Repeating the isolated fixtures

```sh
php tests/integration-guards.php
php tests/integration-guards.php guard
php tests/integration-guards.php missing-vendor
php tests/integration-guards.php missing-sodium
php tests/two-factor-regression.php
```

The first three guard failure modes substitute environmental conditions in a test harness. To exercise the unmodified version decision with a genuinely unsupported runtime, run `php8.0 -n tests/integration-guards.php actual-guard` or the PHP 8.1 equivalent. Do not run `actual-guard` on a supported runtime: that mode is deliberately for older PHP.

Use disposable WordPress installations for browser, upgrade and concurrency checks. Virtual authenticator credentials, session cookies and fictional account passwords are temporary test data and are not included in this repository or release package.

## Screenshots

The Nightjar Reading Room screenshots show invented poetry-library content. They use Twenty Twenty-Five with demo-only form CSS. No client site, real user account or second-factor secret is displayed. The desktop and mobile images live under `docs/screenshots/`; WordPress.org asset publication is a separate approved step.

## Known limits

I have checked the flows named above. They do not cover every theme, browser or authenticator combination, so please tell me about anything that misbehaves. I have reviewed the full Git history for secrets and found nothing.

The blocks are still registered with block API version 1, so WordPress 6.9 and later show a deprecation notice in the editor. Moving them to version 3 would change the saved markup, so it needs deprecation entries and I have left it for a later release.

## Plugin Check report

Plugin Check 2.1.0 reports **0 errors and 93 warnings** for archive SHA-256 `9b112c38d30ae81af6062fa26a3ab10fe91aef9f08f8e8c51cdbed9c8ae7a56e`. The full findings are in [plugin-check-report.json](plugin-check-report.json). This is not a security certification.

- 35 variable-prefix warnings concern the settings template and existing variables; one additional warning concerns the legacy global `is_login_page` helper. These remain compatibility/namespace cleanup items.
- 29 nonce warnings cover profile hooks protected by WordPress core's update-user nonce, public authentication inputs checked against passwords or expiring factors, and read-only navigation parameters. These contexts were reviewed; guest WordPress nonces are not substitutes for authentication.
- Seven hook-prefix warnings concern intentional calls to core WordPress authentication/login hooks. Renaming them would break integrations.
- Twelve direct-query/cache warnings concern explicit concurrency control and stale-lock cleanup. Bound values use prepared queries; locks use database uniqueness and owner-conditional release, with cache invalidation.
- Eight empty-file warnings concern existing placeholder include files retained for package compatibility.
- One slow metadata-query warning identifies the existing discoverable-passkey fallback scan. An indexed migration remains a performance improvement for larger user populations; no speed improvement is claimed for that path.

## Exact archive secret scan

Gitleaks scanned the extracted final archive with full redaction and reported zero findings. Unused vendor WebAuthn demo endpoints and their test certificates are excluded from the production archive. An earlier scan matched a public Microsoft TPM certificate in that excluded test directory, not a private credential. This scan covers the final ZIP only; Git history and any separately published source still require review.

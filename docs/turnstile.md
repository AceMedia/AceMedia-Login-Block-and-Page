# Optional login verification

Settings → Login verification selects the browser steps that require Cloudflare Turnstile: password sign-in, second-factor verification, registration, password recovery and passkey sign-in. Each step is off initially. Network administrators can set shared defaults or enforce a policy across all sites.

Enter both widget keys, or reuse the configured Registration Guard or Ace Content provider. Saved secrets are hidden and blank input preserves them. Network enforcement checks that every site has keys before saving.

Add a Verification block to control its position within a form layout. Select the matching step in its inspector. Enabled login steps are also inserted automatically when an existing login layout has no Verification block. Configure the policy in settings so direct form submissions receive the same protection.

Registration Guard owns registration when it is configured. Set its policy there to avoid duplicate challenges. WooCommerce protection applies to the My Account registration form, preserving checkout and API customer creation. Application-password and other programmatic authentication flows remain available.

Password preflight returns a short-lived, single-use receipt tied to the entered credentials. Final authentication uses that receipt rather than redeeming the same Cloudflare token twice. Second-factor verification happens before consuming an authentication or backup code. Passkeys continue to require cryptographic proof.

Enable only the steps appropriate for the site. A passkey already provides strong resistance to automated password attacks; adding a challenge to passkeys is optional. When selected, passkey verification requires an explicit sign-in attempt rather than silent autofill.

Trusted aliases can be added with `acemedia_login_turnstile_hostnames`. Keep the list limited to origins served by the same installation.

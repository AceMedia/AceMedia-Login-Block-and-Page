# TODO

## Next steps

- Add trusted device support for 2FA (device token cookie + hashed user meta + expiry). (done)
- Add passkey policy settings (RP ID override, attestation policy, passwordless allowance per role). (done)
- Implement conditional UI for passkeys (autofill / mediation) on the login page. (done)
- Add security event logging (passkey add/remove, failed 2FA, lockout) with optional email alerts. (done)
- Consolidate duplicate 2FA REST endpoints into a single source of truth and cover email 2FA. (done)
- Update README/readme.txt to document passkeys, 2FA setup, and new settings. (done)

## New backlog

- Add end-to-end auth tests (password, 2FA app/email, passkey passwordless, passkey 2FA, trusted-device skip, lockout, backup codes, logout prompt).
- Add security log retention controls and clear action for security logs.
- Handle duplicate passkey credential IDs server-side with a clear message.
- Add conditional passkey UI to the custom login block flow.
- Add per-IP throttling for passkey login attempts and back-off on repeated failures.
- Expand documentation with Security Features and Troubleshooting sections (HTTPS, RP ID, browser support).

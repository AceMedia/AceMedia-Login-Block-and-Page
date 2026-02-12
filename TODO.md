# TODO

## Next steps

- Add trusted device support for 2FA (device token cookie + hashed user meta + expiry). (done)
- Add passkey policy settings (RP ID override, attestation policy, passwordless allowance per role). (done)
- Implement conditional UI for passkeys (autofill / mediation) on the login page. (done)
- Add security event logging (passkey add/remove, failed 2FA, lockout) with optional email alerts.
- Consolidate duplicate 2FA REST endpoints into a single source of truth and cover email 2FA.
- Update README/readme.txt to document passkeys, 2FA setup, and new settings.

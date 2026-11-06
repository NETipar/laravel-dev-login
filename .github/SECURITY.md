# Security Policy

**PLEASE DON'T DISCLOSE SECURITY-RELATED ISSUES PUBLICLY, [SEE BELOW](#reporting-a-vulnerability).**

## What this package is

`netipar/laravel-dev-login` is a deliberate authentication bypass for development: whoever reaches its URL while it is enabled can sign in as any user the `authorize` hook allows. "Anyone who can reach the URL can sign in" is the documented behaviour, not a vulnerability.

A vulnerability is a way around one of the package's own safeguards, for example:

- the route being reachable while `dev-login.enabled` is `false`, or under `APP_ENV=production` while `block_in_production` is `true`;
- a sign-in that bypasses the `authorize` hook, `allowed_ips`, the cross-site (`Sec-Fetch-Site`) refusal or the prefetch refusal;
- a session from the previous user surviving the switch;
- the `?to=` parameter redirecting to another host (open redirect).

## Supported Versions

Only the latest minor release line receives security fixes.

## Reporting a Vulnerability

Report it privately, **not in a public GitHub issue**:

1. **GitHub Private Vulnerability Reporting** (preferred): the "Report a vulnerability" button at <https://github.com/NETipar/laravel-dev-login/security/advisories/new>.
2. **Email**: `dev@netipar.hu`, with a description of the issue and its impact, steps to reproduce, and the affected version(s).

All security vulnerabilities will be promptly addressed.

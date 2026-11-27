# Security Policy

## Supported versions

| Version | Security fixes |
| --- | --- |
| Latest `0.1.0-beta.*` | Fixed in the next beta. Older betas are not patched: upgrade. |
| Untagged `develop` | Best effort; never a production dependency. |

## Reporting a vulnerability

Please do not open a public issue. Use GitHub's private vulnerability reporting
("Report a vulnerability" on the Security tab of
`qiick-io/laravel-audit`). We aim to acknowledge reports within 72 hours.

This package writes the security event log shared by every qiick product and
holds personal data. Reports about secrets or personal data reaching the log,
forged attribution (`api_key_id`, `ip`, `actor_kind`), rows stamped with or
visible to another organisation, erasure that misses rows or deletes evidence,
retention that purges early or never, or a log that can be turned off are
treated as critical.

Coordinate disclosure with `qiick/laravel-access` and `qiick/laravel-api-keys`
when a finding spans packages.

## Trusted release path

Tagged releases are cut from `main` with GitHub attestations / build provenance
where available. Packagist publish happens only after Luca’s explicit go. Never
trust an untagged `develop` commit as a production dependency.

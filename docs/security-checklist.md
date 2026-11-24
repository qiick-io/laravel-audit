# Security checklist

The risks of the design (`projects/qiick/packages/laravel-audit-design.md`
§7) and the tests that hold each one. Every item landed red first.

| # | Attack or failure | Tests |
| --- | --- | --- |
| A1 | a new secret column leaks into `attribute_changes` | `tests/Feature/LogsActivitySecretFloorTest.php` (create, update, delete, explicit / JSON / relation paths, emptied config), `tests/Unit/SecretAttributeFloorTest.php` |
| A2 | a caller forges `api_key_id`, `ip` or `actor_kind` in properties | `tests/Feature/AttributionTest.php` (`overwrites reserved keys a caller forged`, `removes forged credential keys`, `wins over a product beforeLogging callback`, buffered rows) |
| A3 | a caller stamps another tenant through `properties.organisation_id` | `tests/Feature/AttributionTest.php` (`never takes the organisation from properties`, `stamps an explicit organisation`, non-UUID refused) |
| A4 | a multi-organisation user's row gets the wrong organisation | `tests/Feature/AttributionTest.php` (`asks the bound OrganisationResolver`): the package uses the product resolver's answer; choosing the active team is the product resolver's job (design §6.1) |
| A5 | rows of organisation A are visible in organisation B | `tests/Feature/ActivityScopesTest.php` (`scopes rows to one organisation and never shows null-organisation rows`) |
| A6 | a bigint `api_key_id` or `api_key_created_by` leaves the database | `tests/Unit/AuditActorTest.php`, `tests/Feature/AuditContextTest.php`; `tests/Feature/IntegrationTest.php` (the row's bigint `id` is never serialised) |
| A7 | the anonymiser misses rows | `tests/Feature/ActivityAnonymiserTest.php` (morph map, causer and subject, every ref, nested keys, second run = 0, `anonymised_at`, configured columns, more than one chunk) |
| A8 | an erasure deletes evidence | `tests/Feature/ActivityAnonymiserTest.php` (`never deletes a row or changes its event, log, timestamps or morphs`), `tests/Feature/IntegrationTest.php` |
| A9 | the log is turned off or loses events | `tests/Unit/AuditBootChecksTest.php`, `tests/Feature/ProductionBootFailsTest.php` |
| A10 | log injection: CR/LF or HTML in `description` | `tests/Feature/IntegrationTest.php` (stored as is); escaping when rendered stays with the product's page |
| A11 | PostgreSQL type error on `causer_id` | `tests/Feature/ActivityScopesTest.php` (scopes, bindings, trait relation, eager loading), on PostgreSQL in CI |
| A12 | the externa backfill corrupts or skips rows | not in this package: `audit:backfill --verify` and the dual write stay with externa 1.6.0 (Luca, 2026-11-24) |
| A13 | timestamps in the wrong zone | `tests/Unit/AuditBootChecksTest.php`, `tests/Feature/BootFailsTest.php`, `tests/Feature/IntegrationTest.php` (RFC 3339 with `Z`) |
| A14 | PII left in a free-text `description` | `tests/Feature/ActivityAnonymiserTest.php` (`scrubs identifiers and contact patterns from the description`, `in any string value`) |

Retention (design §10): `tests/Feature/PurgeActivityLogCommandTest.php`,
`tests/Feature/PurgeScheduleConfigTest.php`.

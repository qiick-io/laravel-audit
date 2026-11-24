# Changelog

All notable changes to **laravel-audit** (`qiick/laravel-audit`) are
documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Schema (analysis of 2026-11-24): composite indexes
  `activity_log_organisation_created_at` and `activity_log_log_name_created_at`
  for the tenant page and the retention passes (they replace the single
  `organisation_id` and `log_name` indexes), and `request_id` widened to 128
  characters, the length qiick/laravel-http-basics accepts.
- Second re-review of #3: a secret value is logged as `[redacted]` instead of
  dropping its key, so a password or two-factor change is still recorded; a
  name with a secret part is not secret when it ends in `_at` / `_on` or is
  cast as a date, boolean or integer (`two_factor_confirmed_at` is logged
  with its time). New built-in names (`pwd`, `pass`, `otp`, `pin`, `cvv`,
  `iban`, `signing_key`, `dsn`) and parts (`passphrase`, `passwd`,
  `credential`, `authorization`, `cookie`, `session`, `segreto`), and
  `audit.sensitive_parts`. The write-once model refuses updates and deletes
  where Eloquent performs them, so `Model::withoutEvents()` no longer gets
  around it.
- Re-review of #3: description placeholders read only loaded attributes and
  relations and never call a model method (`:subject.delete.x` deleted the
  subject); floor names match in any spelling and by secret part
  (`privateKey`, `apiSecret`, `X-Api-Key`); `saveQuietly()`,
  `deleteQuietly()` and `Model::unguarded()` no longer get around the
  write-once model; `created_at` has no database default (the code writes it
  in UTC).
- Review of #2/#3: relations logged by name and models nested in a value are
  dropped, floor keys nested in JSON values are removed at any depth, and
  description placeholders never resolve a secret or a non-scalar. The
  activity_log timestamps are DATETIME (no 2038 limit) with a NOT NULL
  `created_at`, `down()` refuses to drop rows in production, and audit rows
  are written once (no model update or delete, package columns guarded, a
  caller's `public_id` replaced). `auditActivityLogOptions()` returns the
  trait defaults for models that add a log name or excludes.
- `Concerns\LogsActivity` (replaces each product's `LogsApplicationActivityTrait`):
  Spatie's trait with the fleet defaults (every dirty attribute, no empty
  changes, the `default` log) and a secret floor that drops every
  `encrypted*` / `hashed` cast and every `audit.sensitive_attributes` name from
  `attribute_changes`, including JSON paths and relation paths (A1). A product
  can add names; the built-in floor cannot be removed.
- String-safe `causedBy` / `forSubject` scopes and trait relation
  `activitiesAsSubject()` (PostgreSQL never compares `varchar = integer`,
  MySQL never compares the column as a number, A11), and the `forOrganisation`
  scope, which never shows rows without an organisation (A5).
- `audit.columns`: the morph relations, the scopes, the trait relation and the
  logger read and write the configured id columns (design §6.3). The package
  binds its `Support\ActivityLogger` in place of Spatie's, which reads
  `causer_id` by name. This needs `spatie/laravel-activitylog` `^5.1.3`.
- The `activity_log` migration (design §3, final for 1.0): Spatie's columns
  with `string(64)` morph ids, `public_id` (UUID v7, unique), `organisation_id`
  (uuid), `schema_version` (default 1), `request_id`, `anonymised_at`, the
  `subject` / `causer` / `created_at` / `event` / `log_name` /
  `organisation_id` / `request_id` indexes and no foreign keys. Loaded unless
  `audit.migrations.load` is false.
- `Models\Activity` (extends Spatie's, generates `public_id`) and
  `Actions\LogActivityAction` (fills `public_id` and writes `schema_version = 1`
  on the direct and the buffered path), set as Spatie's model and logging
  action unless the product configured its own.
- Package scaffold: auto-discovered `AuditServiceProvider`, the `audit` config
  with the design defaults (secret floor, retention 365 days and 730 days for
  the `auth` log), CI on PHP 8.4 / 8.5 × Laravel 12 / 13 (lowest and stable),
  the suite on SQLite, PostgreSQL, MySQL and MariaDB, sharded per-class
  mutation, and the qiick standards pack.

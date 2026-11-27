# Changelog

All notable changes to **laravel-audit** (`qiick/laravel-audit`) are
documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0-beta.2] - 2026-11-27

Second pre-release, the 1.0 release candidate contract: what freezes at
`1.0.0` is listed in `docs/public-api.md`.

### Upgrade notes

- No schema change: the `activity_log` migration is the same as in
  `0.1.0-beta.1`, so no database refresh is needed.
- Rename listeners and fakes of `ActivityAnonymised` to
  `ActivityAnonymisedEvent`, wire `AuditEraser` into the product's erasure
  orchestrator, and add `php artisan audit:check` to the deploy: see
  "From 0.1.0-beta.1" in `UPGRADING.md`.

### Added

- Fleet-standard `AuditEraser` for full and organisation-scoped erasure, with
  dry-run counts and a counts-only event after the audit transaction commits.
- `php artisan audit:check`, the deploy check (F2): it fails on invalid
  retention floors or purge time, and on an `activity_log` table that lacks
  the configured morph id columns.

### Fixed

- A `config/audit.php` published before a nested key existed no longer loses
  it: every key left out of `purge`, `migrations` or `columns` takes the
  package default, so an older file cannot silently stop the scheduled purge
  or skip the `activity_log` migration. A block that is not an array fails at
  boot with its key name (F1, #11).

- README threat model, `docs/standards-traceability.md` (MUST / SHOULD /
  LATER mapping) and the "Frozen at 1.0.0" contract in `docs/public-api.md`.
- CI on app-ui's shared package matrix, without PHP 8.3 (Spatie activitylog
  v5 requires 8.4), and a whole-source mutation gate.

### Changed

- **Breaking:** `Events\ActivityAnonymised` is now `Events\ActivityAnonymisedEvent`
  (fleet convention F5: event classes end in `Event`). The payload is the
  same. See UPGRADING.md.

### Changed

- **Breaking:** `Events\ActivityAnonymised` is now `Events\ActivityAnonymisedEvent`
  (fleet convention F5: event classes end in `Event`). The payload is the
  same. See UPGRADING.md.

## [0.1.0-beta.1] - 2026-11-26

First pre-release. Before `1.0.0` any minor version may break the public API
(see `UPGRADING.md`).

### Upgrade notes

- New install: `composer require qiick/laravel-audit:^0.1@beta`, then
  `php artisan vendor:publish --tag=audit-config` and `php artisan migrate`.
  Adopting it in a product replaces the product's own `activity_log`
  migration and Spatie configuration: follow "Adopting the package in a
  product" in `UPGRADING.md`.
- Migrations are not frozen before `1.0.0`: a later beta may change the
  `create_activity_log_table` migration, and upgrading to it may need a fresh
  database (`migrate:fresh`).
- The scheduled `audit:purge` uses `withoutOverlapping()->onOneServer()`: the
  servers must share a cache store that supports locks.

### Fixed

- `ActivityAnonymised` is dispatched after the outermost transaction of the
  audit connection commits: an erasure the product's transaction rolled back
  is no longer reported, even when another connection opens a transaction
  during the erasure and commits first (RC review M1).
- Concurrent erasures of two people who share a row no longer write back a
  name the other one scrubbed: the anonymiser locks the rows it reads. The README documents the transaction behaviour of logging,
  erasure and the purge (M2).
- The scheduled purge runs on one server and without overlapping itself
  (`withoutOverlapping()->onOneServer()`, 24-hour lock), and each chunk delete
  checks the cutoff again, so overlapping purges (a manual run during the
  scheduled one) still delete every old row once and never a row inside its
  floor (RC review M5).
- The purge reads the ids to delete from the write connection: with a read
  replica that lags, it kept reading rows already deleted and never finished
  (both `audit:purge` and `activitylog:clean`). Each chunk also starts after
  the last id read, so a purge inside a MySQL `REPEATABLE READ` transaction
  finishes when another session deletes the rows its snapshot still shows.

### Added

- `docs/public-api.md` lists the public API (classes, config keys, commands,
  events, migrations, formats), with an architecture test that every
  declaration is `@api` or `@internal` and every `@api` one is listed;
  `docs/reference-schema.md` documents the `activity_log` columns and indexes;
  the dependency register describes the purge command it now ships; the README
  states the erasure perimeter and the caller's duties;
  `docs/decisions/2026-11-25-standard-eraser-after-conformance.md` records that
  the fleet-standard `AuditEraser` lands after the conformance suite, before
  1.0 (beta.1 ships `AuditAnonymiser` only).
- Boot checks (design §4): the activity model, the logging action and the
  clean action must extend the package's, `app.timezone` must be `UTC` (A13), and in production
  the log must be enabled and unbuffered (A9). `Models\Activity` never
  serialises its bigint `id`. An architecture test allows query-builder
  writes to `activity_log` only in the anonymiser and the purge, and the
  README states the trust boundary (a database role without UPDATE /
  DELETE). `Concerns\CausesActivity` (string-safe
  `activitiesAsCauser()`, A11) and `Concerns\HasActivity` replace Spatie's
  traits, whose eager loads fail on PostgreSQL for bigint keys and whose
  HasActivity would bring LogsActivity without the secret floor. Adoption steps in UPGRADING.md and the A1–A14
  test map in docs/security-checklist.md.
- Spatie's `activitylog:clean` goes through the two retention floors: the
  package's `Actions\CleanActivityLogAction` is the configured clean action,
  so no path (not even `--days=1`) deletes a row younger than its floor; the
  warning on `activitylog:clean` is gone (analysis AU-l).
- Security logs are a list, `audit.security_log_names` (default `auth`,
  `security`, `access`, `api-keys`), all kept 730 days; it replaces
  `audit.security_log_name`. Log names are stored trimmed and lower-cased, so
  the floors match on every engine.
- Retention (Luca, 2026-11-23): `audit:purge` deletes rows outside the
  security logs older than `audit.retention_days` (365), then security log
  rows older than `audit.security_retention_days` (730), in chunks of
  `audit.purge.chunk_size` ids. The provider schedules it daily at
  `audit.purge.time` (03:00 UTC) unless `audit.purge.schedule` is false,
  and feeds `audit.retention_days` to `activitylog.clean_after_days`. Misconfigured floors (zero, negative, a
  security floor shorter than the general one) fail the command and delete
  nothing.
- Erasure replaces identifiers as whole words only (`Al` no longer corrupts
  `Alarm`) and scrubs `ip_address` by default.
- Erasure matches personal keys in any spelling (`firstName`, `last-name`,
  `Phone Number`), normalised like the secret floor (analysis AU-j).
- GDPR erasure (design §5): `Contracts\AuditAnonymiser` with `ErasureSubject`,
  `MorphRef` and `AnonymisationReport`, and the default
  `Anonymisation\ActivityAnonymiser`. For every row whose causer or subject
  is one of the person's refs (through `getMorphClass()`, so a morph map is
  honoured), it puts the tombstone on every personal key of `properties` and
  `attribute_changes` at any depth (`ip`, `user_agent`, `email`, `username`,
  `name`, `first_name`, `last_name`, `audit.personal_keys`), replaces the
  person's identifiers and every email address or phone-like sequence in
  every string and in `description`, and sets `anonymised_at`. It never
  deletes a row or changes its event, log, timestamps or morphs; a second run
  reports 0 rows (A7, A8, A14). `Events\ActivityAnonymised` reports each run.
- Server-side attribution on every logged row (design §3.1, §4), written by
  the package logging action after every product `beforeLogging` callback:
  the reserved property keys `ip`, `user_agent` (cut to
  `audit.user_agent_max`), `actor_kind`, `api_key_id`, `api_key_prefix`,
  `api_key_created_by` and `oauth_client_id` (caller values removed, A2); the
  causer rule (a bound credential's owner principal replaces the caller's
  causer; otherwise an explicit `causedBy()` / `causedByAnonymous()` is kept
  and the signed-in user is the default); `organisation_id` from
  `Audit::inOrganisation()` or the `OrganisationResolver`, never from
  properties (A3); `request_id` from the `request_id` key of Laravel's
  context. The ip and user agent come only from a routed HTTP request.
- Contracts `AuditActorResolver`, `AuditActor` (public UUIDs and public ids
  only, A6) and `OrganisationResolver`; `AuditContext::bindCredential()` for
  the key / OAuth middleware; defaults `RequestAttributeActorResolver` (reads
  `qiick/laravel-access`'s actor context when it is installed) and
  `NullOrganisationResolver`.
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

### Documentation

- Dependency register `docs/dependencies.md`: one row per runtime
  `composer.json` `require` entry with constraint rationale, usage,
  maintainer, license, known risk and an in-package exit plan.

[Unreleased]: https://github.com/qiick-io/laravel-audit/compare/v0.1.0-beta.2...HEAD
[0.1.0-beta.2]: https://github.com/qiick-io/laravel-audit/compare/v0.1.0-beta.1...v0.1.0-beta.2
[0.1.0-beta.1]: https://github.com/qiick-io/laravel-audit/releases/tag/v0.1.0-beta.1

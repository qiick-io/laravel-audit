# Changelog

All notable changes to **laravel-audit** (`qiick/laravel-audit`) are
documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

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

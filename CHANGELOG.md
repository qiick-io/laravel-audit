# Changelog

All notable changes to **laravel-audit** (`qiick/laravel-audit`) are
documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Package scaffold: auto-discovered `AuditServiceProvider`, the `audit` config
  with the design defaults (secret floor, retention 365 days and 730 days for
  the `auth` log), CI on PHP 8.4 / 8.5 × Laravel 12 / 13 (lowest and stable),
  the suite on SQLite, PostgreSQL, MySQL and MariaDB, sharded per-class
  mutation, and the qiick standards pack.

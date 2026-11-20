# Changelog

All notable changes to `laravel-zkteco` will be documented in this file.

## Unreleased (local Laravel 13 compatibility fork)

- Added Laravel 13 to the supported `illuminate/contracts` versions.
- Updated the development test stack and CI workflow to target Laravel 13.
- Fixed attendance timestamp deprecation warnings and reading the last complete attendance record.
- Hardened UDP sessions, attendance transfers and time acknowledgements, with configurable receive and total operation deadlines.
- Encode and decode terminal wall-clock values independently of PHP's default timezone.

## v1.0.1 - 2024-06-13

**Full Changelog**: https://github.com/mehedijaman/laravel-zkteco/compare/v.1.0.0...v1.0.1

## v1.0.0 - 2024-05-25

First Version

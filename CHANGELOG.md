# Changelog

All notable changes to `orbit-core` will be documented in this file.

## Release 0.0.6 - 2026-01-23

### Added

- Directory picker for Site Paths configuration - click the folder icon to browse and select directories via a modal interface
- Toast notifications for configuration save feedback

### Fixed

- Config loading now uses correct `/api/` prefix (was returning 405 error)

## Release 0.0.5 - 2026-01-22

### What's Changed

- Fix TypeScript error in Layout.vue navigation check
- Remove unused lang/php_en.json file
- Improve optional chaining for navigation footer items

### Bug Fixes

- Fixed TypeScript compilation error when building assets
- Added proper null checking for navigation.app.footer.items

## Unreleased

### Added

- Per-environment editor preference - each environment can now have its own preferred code editor for "Open in Editor" URLs. Falls back to global setting if not configured.

## 0.0.1 - 2026-01-18

Initial release of orbit-core package

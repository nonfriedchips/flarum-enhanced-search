# Changelog

All notable changes to this project are documented in this file.

## [Unreleased]

## [1.1.0] - 2026-08-18

### Added

- Added MariaDB support through collision-free, fixed-length Unicode bigram tokens stored in a standard InnoDB FULLTEXT index.
- Added real MySQL 8.4, MariaDB 10.11, and MariaDB 11.8 database contract and Flarum integration gates to CI and release workflows.

### Changed

- Kept existing Oracle MySQL installations on the native `ngram` parser path, so upgrading does not rebuild their derived search table.
- Reindex now validates the active database FULLTEXT settings before clearing derived documents and keeps the index marked dirty when they are incompatible.

## [1.0.1] - 2026-08-18

### Changed

- Updated the `webpack-cli` build dependency to 7.2.2 without changing the compiled admin bundle.
- Kept automated dependency updates on the Flarum 1.x-compatible `flarum/core` and `flarum-webpack-config` release lines.

## [1.0.0] - 2026-08-17

### Added

- Hybrid native FULLTEXT and MySQL n-gram candidate retrieval for Flarum 1.8.
- Unicode typo tolerance with bounded edit distance and adjacent transpositions.
- CJK title, post-content, username, and display-name search.
- Permission-preserving discussion/post filtering and `mostRelevantPost` support.
- Incremental indexing, dirty-index fallback, and a concurrency-safe reindex command.
- English and Simplified Chinese admin settings.
- Unit, boot-smoke, MySQL integration, CI, and tag-based release workflows.

[Unreleased]: https://github.com/nonfriedchips/flarum-enhanced-search/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/nonfriedchips/flarum-enhanced-search/compare/v1.0.1...v1.1.0
[1.0.1]: https://github.com/nonfriedchips/flarum-enhanced-search/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/nonfriedchips/flarum-enhanced-search/releases/tag/v1.0.0

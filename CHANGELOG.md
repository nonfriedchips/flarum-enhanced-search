# Changelog

All notable changes to this project are documented in this file.

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

[1.0.1]: https://github.com/nonfriedchips/flarum-enhanced-search/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/nonfriedchips/flarum-enhanced-search/releases/tag/v1.0.0

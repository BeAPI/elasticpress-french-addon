# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Security

- Align network-mode settings with ElasticPress: store `epfr_settings` as a `site_option`, gate the admin UI with `manage_network_elasticpress` (via `Utils\get_network_capability()`), and register the menu under Network Admin when `EP_IS_NETWORK` is active.
- Align site-mode capability with ElasticPress `Utils\get_capability()` instead of hard-coded `manage_options`.
- Harden `Settings::sanitize()` against a missing POST field (`mixed` input) to avoid a PHP 8 TypeError on forged admin saves.

## [1.0.0] — 2026-07-29

Initial public release.

### Added

- French analyzer pipeline on ElasticPress `default` / `default_search`: elision, lowercase, asciifolding, stopwords, optional stemmer.
- Configurable stemmer: `none`, `minimal_french`, `light_french` (default), `french`.
- `stem_exclusion` via Elasticsearch `keyword_marker` (e.g. protect `croix` from colliding with `croissant`).
- Optional dual analyzers: light chain on main text fields (precision) + `epfr_heavy` on `.stemmed` multi-fields (recall), with reduced boost at query time.
- Query fuzziness override: `auto` / `0` / `1` / `2`.
- Extra stopwords on top of ElasticPress `_french_` list.
- Admin screen under **ElasticPress > French Addon** (`epfr_settings` option).
- Forces analyzer language to French while the addon is enabled (`ep_analyzer_language`).
- Extensibility filter `epfr_mapping` for advanced mapping tweaks.
- Local DDEV stack, search corpus fixtures, and Composer verify/compare scripts.
- French gettext translations (`languages/`).
- CI quality workflow and GitHub Release ZIP workflow (tag → `elasticpress-french-addon.zip`).

### Notes

- Analyzer / mapping changes require a full reindex (`wp elasticpress index --setup`). A plain sync is not enough.
- Synonyms remain the responsibility of ElasticPress’s native Synonyms feature.

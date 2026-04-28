# Changelog

## [1.3.1](https://github.com/lemachinarbo/LetMeDown/compare/v1.3.0...v1.3.1) (2026-04-28)


### Bug Fixes

* add raw html parser toggle ([ca3fe5f](https://github.com/lemachinarbo/LetMeDown/commit/ca3fe5f89c4a4ed02c2886dce109113792896148))
* disable Parsedown SafeMode to allow raw HTML in content fields ([8858728](https://github.com/lemachinarbo/LetMeDown/commit/88587282d1a31f5ea21df40672bb7f05f7a9389b))

## [1.3.0](https://github.com/lemachinarbo/LetMeDown/compare/v1.2.3...v1.3.0) (2026-04-19)


### Features

* integrate release-please for automated versioning and release management ([341bea5](https://github.com/lemachinarbo/LetMeDown/commit/341bea552127dacd3893c50efaa4ac6626109971))


### Bug Fixes

* prevent path traversal in markdown loader ([7be153f](https://github.com/lemachinarbo/LetMeDown/commit/7be153fd1d72497801e70732e4b501813fbf8c26))
* prevent phar deserialization vulnerability in load() ([f2bfc09](https://github.com/lemachinarbo/LetMeDown/commit/f2bfc09e38279f8ceb2c755ed44acbc3d1c6412a))
* remove redundant id from release-please job in release workflow ([5691bb4](https://github.com/lemachinarbo/LetMeDown/commit/5691bb47b167343065277da7a95a6b56718f26bc))
* sanitize unsafe url schemes in link fields to prevent xss ([90c03c6](https://github.com/lemachinarbo/LetMeDown/commit/90c03c629c8fe0384fa4e45fd0a72c1f9d4aea0b))
* update release configuration ([478650e](https://github.com/lemachinarbo/LetMeDown/commit/478650e9c919829982e641b98ccd3f70e6e9d9dc))


### Miscellaneous Chores

* add agents configuration file ([a99ed2b](https://github.com/lemachinarbo/LetMeDown/commit/a99ed2b9bacbf84536b35c5f6a45380de400f5f8))

## v1.2.3
- added `ContentData::data()` for top-level named section projection
- made `data()` recursive and associative across sections, subsections, field containers, and fields
- field containers now serialize as structural nodes with named children and ordered `items`
- iterable fields expose `items`, while scalar fields keep associative field payloads without positional array flattening
- section-like nodes now expose `subsections` instead of legacy subsection `items`
- tightened sparse output so empty string values are omitted from public `data()` payloads

## v1.2.2
- fixed `data()` projection for structured fields so list and image payloads keep predictable named keys

## v1.2.1
- fixed hyphenated section and subsection markers like `<!-- section:feature-grid -->` and `<!-- sub:name-of-section -->`
- made marker name matching consistent across sections, subsections, and fields

## v1.2

- fixed Composer autoload so LetMeDown classes load correctly from one-file source
- added a plain `data()` view for content structure of sections and fields

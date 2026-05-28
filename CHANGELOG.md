# Changelog

## [1.5.1](https://github.com/lemachinarbo/MarkdownToFields/compare/v1.5.0...v1.5.1) (2026-05-28)


### Miscellaneous Chores

* bump version to 1.5.0 and wire module.php to release-please ([a70b165](https://github.com/lemachinarbo/MarkdownToFields/commit/a70b1658acdf1e0d4dc3aa7cd78fd53dcb9d66f8))

## [1.5.0](https://github.com/lemachinarbo/MarkdownToFields/compare/v1.4.4...v1.5.0) (2026-05-28)


### Features

* introduce MarkdownHtmlElement for semantic rendering and update dataset projection to return element objects ([c0bfbed](https://github.com/lemachinarbo/MarkdownToFields/commit/c0bfbed46d21ddd0e19d3e8f055cf1ac17547853))


### Miscellaneous Chores

* remove vendor files ([d4568a6](https://github.com/lemachinarbo/MarkdownToFields/commit/d4568a64c70549e8dd9cb103ade8c7251c20ecab))
* sync conductor protocol and commit rules ([f549020](https://github.com/lemachinarbo/MarkdownToFields/commit/f5490209749325964cc2d011ea63f66f45f9851b))

## [1.4.4](https://github.com/lemachinarbo/MarkdownToFields/compare/v1.4.3...v1.4.4) (2026-05-17)


### Bug Fixes

* integrate html purifier for secure markdown rendering ([43908e5](https://github.com/lemachinarbo/MarkdownToFields/commit/43908e54774cc48f2871fd809d6482731e30f92e))
* prevent crash on templates without fieldgroups ([e958d87](https://github.com/lemachinarbo/MarkdownToFields/commit/e958d8796059da9ac83c79113d6fccc2c77fd923))
* remove defensive fallback to stored hash ([7655781](https://github.com/lemachinarbo/MarkdownToFields/commit/7655781380fbed42913bfb7f686ecf299907e1e8))


### Performance Improvements

* batch fetch fields in uninstall check to eliminate N+1 queries ([b71c689](https://github.com/lemachinarbo/MarkdownToFields/commit/b71c68951ebcd37c6ff22373c49f071de5bb99d3))


### Miscellaneous Chores

* codify Leashed Delegation protocol in AGENTS.md ([cb30696](https://github.com/lemachinarbo/MarkdownToFields/commit/cb306961fd86829094c02ff8c21078ab55c51d86))
* initialize golden file testing track ([26d4560](https://github.com/lemachinarbo/MarkdownToFields/commit/26d4560f0ee33fd05d796ef8c91b0550173831b8))
* lock new active jules tracks in ledger ([596c497](https://github.com/lemachinarbo/MarkdownToFields/commit/596c497fbea26e1910f6f90a1e15a9bb4c0bf243))
* lock verified Task 1 session in tracks.md ([8530f8b](https://github.com/lemachinarbo/MarkdownToFields/commit/8530f8bcd27b8989cae3dfef683f0ae91d0b00f8))
* move Task 1 to Completed in tracks.md ([af887fa](https://github.com/lemachinarbo/MarkdownToFields/commit/af887fac5e06b614a121248ce1dbbb4f629ed522))
* move Task 4 to Completed in tracks.md ([6ddfe46](https://github.com/lemachinarbo/MarkdownToFields/commit/6ddfe468733d56b8d084f3299fd2e7f449b2775b))
* track final enriched delegation sessions on Jules ([079a4f0](https://github.com/lemachinarbo/MarkdownToFields/commit/079a4f0a9b4eeb92313c36b339edb517bba47882))
* track final full-content performance and health sessions on Jules ([3473435](https://github.com/lemachinarbo/MarkdownToFields/commit/3473435d6c52190f8746f7073bb2ce87c948b9f3))
* track new code health cleanup sessions on Jules ([5a6eaba](https://github.com/lemachinarbo/MarkdownToFields/commit/5a6eabaafb081230cdf9e48c15dad483c2abd7db))
* track new performance optimization sessions on Jules ([8c94459](https://github.com/lemachinarbo/MarkdownToFields/commit/8c9445965138af80885d10394250f179927e9455))
* track verified performance and health sessions on Jules ([f9c7988](https://github.com/lemachinarbo/MarkdownToFields/commit/f9c798856e286410bb0f68f8208e80e5f820e9da))
* update tracks ledger with high-fidelity sessions for tasks 4 and 5 ([b1a52c3](https://github.com/lemachinarbo/MarkdownToFields/commit/b1a52c3ff1d9de2c457d2a08e6dd969502de7a75))
* update tracks.md with isset implementation completion ([259c7cd](https://github.com/lemachinarbo/MarkdownToFields/commit/259c7cd6744988e2cea78e48f4a52127624d7c3e))
* update tracks.md with jules security and performance task completion ([28d176f](https://github.com/lemachinarbo/MarkdownToFields/commit/28d176f65387fbddb754c31026f21689560fe4d8))

## [1.4.3](https://github.com/lemachinarbo/MarkdownToFields/compare/v1.4.2...v1.4.3) (2026-05-11)


### Bug Fixes

* ensure localized property changes trigger markdown sync ([9721daf](https://github.com/lemachinarbo/MarkdownToFields/commit/9721dafd23c232bc3dee776fb687b193b8127a1b))
* mitigate rce in documentation playground ([f8829e7](https://github.com/lemachinarbo/MarkdownToFields/commit/f8829e76e91069fe50e9a0182fe2d926639d95fc))
* resolve name-reversion and path-caching during page renames ([1d24606](https://github.com/lemachinarbo/MarkdownToFields/commit/1d24606c345426419e3f1cc7a9762e826cbdaaf1))
* use default language for all filenames ([b2c9c4d](https://github.com/lemachinarbo/MarkdownToFields/commit/b2c9c4d7898a0f2c33f366f22d1bd5c45b1571da))


### Performance Improvements

* optimize N+1 query in module uninstall routine ([c0aa58b](https://github.com/lemachinarbo/MarkdownToFields/commit/c0aa58b9dd01ce9ccb207065989f982bb8d49c99))


### Miscellaneous Chores

* initialize conductor-jules project management framework and pull request template ([7cd1637](https://github.com/lemachinarbo/MarkdownToFields/commit/7cd1637af9a8769bd05aa32001bac4639d44752c))
* update agents.md with processwire template refinements ([2643685](https://github.com/lemachinarbo/MarkdownToFields/commit/26436859f0ebc9e31a1b47db1aa54ce9daf89a80))

## [1.4.2](https://github.com/lemachinarbo/MarkdownToFields/compare/v1.4.1...v1.4.2) (2026-05-04)


### Miscellaneous Chores

* add core module patching workflow to agents.md ([7e0e80f](https://github.com/lemachinarbo/MarkdownToFields/commit/7e0e80f992ed316846f0c49ee2699c761addf8f0))
* bump version to 1.3.2 and update documentation comments ([9590bb8](https://github.com/lemachinarbo/MarkdownToFields/commit/9590bb82082aebead312fd34c59597f76652faa6))
* upgrade Parsedown to 1.8.0 and include block-level parsing enhancements ([ef5d404](https://github.com/lemachinarbo/MarkdownToFields/commit/ef5d40415ac7177a0e85d3030631854869a29928))

## [1.4.1](https://github.com/lemachinarbo/MarkdownToFields/compare/v1.4.0...v1.4.1) (2026-04-28)


### Bug Fixes

* opt into raw html parser mode ([06bec18](https://github.com/lemachinarbo/MarkdownToFields/commit/06bec18771bfd0d694e33715483b0bac0ac1bca8))
* update letmedown dependency ([cc1266b](https://github.com/lemachinarbo/MarkdownToFields/commit/cc1266b98622d783777f010a43a28723367553fa))

## [1.4.0](https://github.com/lemachinarbo/MarkdownToFields/compare/v1.3.7...v1.4.0) (2026-04-28)


### Features

* implement automated documentation builder and api reflection ([8dda5a6](https://github.com/lemachinarbo/MarkdownToFields/commit/8dda5a6036553903a210483dc7641c687eebb1b3))
* implement markdownblockview and unify view layer api ([5efc64c](https://github.com/lemachinarbo/MarkdownToFields/commit/5efc64ce8a9c3fbc0ba96f55f335cb27c9450ddc))
* test release workflow ([c97f826](https://github.com/lemachinarbo/MarkdownToFields/commit/c97f826cc1c1051f13a013e0fe15e753202e9799))


### Bug Fixes

* documentation builder logic, update snapshots, and enhance test discovery for content elements ([c09c111](https://github.com/lemachinarbo/MarkdownToFields/commit/c09c111d332a3aa778deca99ada2e93a9829642a))
* resolve projection crashes and secure data flow ([6f82430](https://github.com/lemachinarbo/MarkdownToFields/commit/6f82430f405aa1cc2e8c64a2c273e0203e94b9fe))


### Miscellaneous Chores

* add module context and developer guidelines to AGENTS.md ([3de20cf](https://github.com/lemachinarbo/MarkdownToFields/commit/3de20cfd13b482aa56db7d7d2316c8cca35016fe))
* configure release-please and update release workflow for automated versioning ([6ae7759](https://github.com/lemachinarbo/MarkdownToFields/commit/6ae7759c62bb0583529fef7ede3bd2e43f23703a))
* update release branch ([79bb145](https://github.com/lemachinarbo/MarkdownToFields/commit/79bb145c4900b3b7e3f04d0c78509ff5c4a69b72))

## v1.3.7

- Refactor `renderConfigurationReference()` into smaller focused methods for easier maintenance.
- Refactor `doSyncFromMarkdown()` into clearer sub-responsibilities to improve readability and reduce method complexity.
- Optimize field map configuration parsing in `MarkdownConfig` with caching to reduce repeated parse overhead.

## v1.3.6

- Fix page creation errors on unsaved pages by skipping page file access until the page has an ID.

- Fixes four separate ways a page save could silently destroy markdown content:

  - **Empty post payload**: empty raw markdown submitted by the form no longer deletes the existing file.
  - **Empty computed document**: clearing all fields no longer removes the file; files are preserved by default.
  - **Source path collision (pages)**: saving is blocked when two managed pages resolve to the same markdown file path.
  - **Source path collision (orphans)**: renaming a page onto a slug whose markdown file already exists is also blocked, preventing silent overwrites of orphaned files.

## v1.3.5

- Fix broken links that lost query parameters or anchors during href replacement, ensuring links retain their queries and fragments.
- Fix ProcessWire page creation for templates with ID-based `contentSource()` overrides by falling back to the default page-name source until the page has been saved.
- Fix false "missing markdown file" detection during auto-create on multilingual sites by checking normalized language codes, so existing default-language files are not overwritten with empty stubs.

## v1.3.4

- Templates can now call ->dataSet() on markdown content to get opinionated ProcessWire-friendly dataset objects and project content as HTML or plain text for easier rendering.

## v1.3.3

- `content()->data()` is now a stable structural projection boundary for templates.
- Section and subsection data now always expose `key`, `area`, and `subsections`.
- Field container data now behaves as a structural node with named children plus ordered `items`.
- Field data now keeps associative shape consistently when reached directly or through parent `data()`.
- Empty public `blocks` output was removed from the `data()` contract.

## v1.3.1

- Missing internal-link indexes are now rebuilt during module refresh (no need to visit each page). 
- Logs are quieter. Repeated image-rewrite messages have been removed.

## v1.3

- BREAKING: MarkdownToFields no longer includes the backend visual editor. For rich text editing, use MarkdownToFieldsFrontEditor.
- The backend now edits markdown directly, so there is only one real version of the content instead of two competing ones.
- Old `md_editor` fields are removed during module sync because they are no longer used.
- Add config option to control whether markdown links should be automatically updated when the linked page moves (linkSync).
- Fixes stale internal link updates when pages or their parents move, and allows the homepage name to be empty so the root URL stays /.
- Markdown field content now updates when internal links are rewritten, avoiding stale content.
- Logging is quieter and only emits debug details when enabled.

## v1.2.16

- Added a page-aware content data bridge on top of LetMeDown
- Added editable area identity
- Image data from markdown can now be adapted into ProcessWire image objects

## v1.2.15

- Add sync feature for markdown referenced images when source content changes (Trigger by module refresh or Config UI button).
- Avoid redundant fieldgroup writes during template sync by saving only when markdown/editor fields actually changed.
- Added change tracking in `syncTemplateFieldgroup()` to reduce duplicate insert race risk during module init.
- Added `resolveImageForInsertion()` public method to enable frontend editors to resolve images from configurable source paths and copy them to ProcessWire assets before insertion.

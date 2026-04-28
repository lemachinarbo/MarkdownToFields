# Changelog

## [1.4.2](https://github.com/lemachinarbo/MarkdownToFields/compare/v1.4.1...v1.4.2) (2026-04-28)


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

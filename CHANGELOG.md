# Changelog

## v1.3.2

- `content()->data()` is now a stable structural projection boundary for templates.
- Section and subsection data now always expose `key`, `area`, and `subsections`.
- Field container data now behaves as a structural node with named children plus ordered `items`.
- Field data now keeps associative shape consistently when reached directly or through parent `data()`.
- Empty public `blocks` output was removed from the `data()` contract.
- Added starter documentation for the `data()` contract and its structural/non-semantic boundary.

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

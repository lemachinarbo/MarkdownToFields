# Changelog

## v1.2.16

- Added a page-aware content data bridge on top of LetMeDown
- Added editable area identity
- Image data from markdown can now be adapted into ProcessWire image objects

## v1.2.15

- Add sync feature for markdown referenced images when source content changes (Trigger by module refresh or Config UI button).
- Avoid redundant fieldgroup writes during template sync by saving only when markdown/editor fields actually changed.
- Added change tracking in `syncTemplateFieldgroup()` to reduce duplicate insert race risk during module init.
- Added `resolveImageForInsertion()` public method to enable frontend editors to resolve images from configurable source paths and copy them to ProcessWire assets before insertion.

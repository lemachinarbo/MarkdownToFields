# Changelog

## v1.2.14

- Avoid redundant fieldgroup writes during template sync by saving only when markdown/editor fields actually changed.
- Added change tracking in `syncTemplateFieldgroup()` to reduce duplicate insert race risk during module init.

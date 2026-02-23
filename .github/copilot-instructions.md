# Copilot Instructions for MarkdownToFields

## Project Overview

- **Purpose:** Sync markdown files with ProcessWire fields, using markdown as the canonical content source. Structure is defined by simple HTML comment tags in markdown.
- **Major Components:**
  - `MarkdownDocumentParser.php`: Parses markdown files into structured content trees.
  - `MarkdownContent.php`: Trait for Page classes, exposes `$page->content()` for accessing parsed content.
  - `MarkdownFieldSync.php`, `MarkdownBatchSync.php`: Handle syncing between markdown and ProcessWire fields.
  - `MarkdownFileIO.php`, `MarkdownHashTracker.php`: File IO and change tracking.
  - `MarkdownHtmlConverter.php`: Converts markdown to HTML for output.
  - `MarkdownConfig.php`: Handles configuration (e.g., content folder path).

## Key Patterns & Conventions

- **No enterprise abstractions:** Follow ProcessWire module contract (see `processwire-contract.md`). Avoid service layers, factories, or helpers unless exposing data.
- **Canonical data:** Parsed markdown is immutable after creation. No post-parse mutation.
- **Content tags:** Structure markdown with HTML comments (e.g., `<!-- section:name -->`, `<!-- field:name -->`). See `docs/guide.md` for tag types and usage.
- **Sync logic:** Only text fields are synced. Frontmatter can map to ProcessWire fields.
- **Enable per-template:** Module is enabled for templates via admin UI. Markdown files are mapped by page name by default, but can be customized in Page classes.
- **Trait usage:** Add `use MarkdownContent;` to Page classes to enable `$page->content()`.
- **No defensive fallbacks:** Fail fast on invalid state in core logic. Only boundary layers (IO, user input) may use fallbacks.

## Developer Workflows

- **Install:** Copy to `site/modules/MarkdownToFields/` and install via ProcessWire admin.
- **Add trait:** Add `use MarkdownContent;` to your Page class.
- **Write markdown:** Place markdown files in the configured content folder (default: `site/content/`).
- **Sync:** Sync is automatic when editing via admin or saving markdown files.
- **Config:** Change content folder via `$config->MarkdownToFields['sourcePath']` in `site/config.php`.
- **Debug:** No custom build/test scripts. Debug via ProcessWire admin and PHP error logs.

## Integration Points

- **ProcessWire API:** Trust and use ProcessWire APIs for all persistence, IO, and lifecycle.
- **LetMeDown parser:** Used for markdown parsing (see `README.md`).

## Examples

- See `docs/guide.md` for detailed usage and tag examples.
- Example Page class:
  ```php
  class DefaultPage extends Page {
    use MarkdownContent;
  }
  ```
- Example template usage:
  ```php
  $content = $page->content();
  echo $content->hero->title->text;
  ```

## References

- [processwire-contract.md](processwire-contract.md): Coding contract and architectural rules
- [README.md](../README.md): Project intro and quickstart
- [docs/guide.md](../docs/guide.md): Full documentation and examples

---

**If unsure, prefer explicit, boring, ProcessWire-native code.**

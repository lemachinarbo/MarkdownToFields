# Audit Ledger - 2026-05-30 main

## Scope

- Range: `origin/main..HEAD`
- Commits reviewed: 10
- Files reviewed: `MarkdownDocumentParser.php`, `MarkdownFileIO.php`, `MarkdownHtmlConverter.php`, `MarkdownSyncEngine.php`, `MarkdownSyncHooks.php`, `composer.json`
- Jules session: `7039359998451057408`

## Verdict

Merge after fixes.

## Confirmed Findings

### AUD-001 - HIGH: Frontmatter opening fence no longer tolerates trailing whitespace

- **Location:** `MarkdownDocumentParser.php` (line 14)
- **Source:** Jules
- **Failure Mode:** Documents whose opening frontmatter delimiter is written as `--- ` before the newline no longer parse as frontmatter. The parser falls back to treating the full file as body content, so mapped fields and frontmatter-derived sync state load as empty.
- **Why this is real:** The new parser requires `preg_match('/\A---\r?\n/', $document)` and the full split regex also assumes an exact opening fence. The previous parser only checked `strncmp($document, '---', 3)` and therefore tolerated extra whitespace after the opening delimiter.

### AUD-002 - HIGH: Nested `contentSource()` paths now throw invalid source errors

- **Location:** `MarkdownFileIO.php` (line 27)
- **Source:** Local + Jules
- **Failure Mode:** Any page class that returns a nested markdown path such as `src/about/us/theaboutpage.md` from `contentSource()` now throws `Invalid markdown source...` during load, save, rename, hash calculation, and other sync flows.
- **Why this is real:** `isValidSource()` now rejects any source whose trimmed value differs from `basename(...)`, and `requireValidSource()` is enforced inside `getMarkdownFilePath()`. The guide still documents nested `contentSource()` paths as supported.

### AUD-003 - HIGH: Multiline field binders no longer update

- **Location:** `MarkdownSyncEngine.php` (line 745)
- **Source:** Local + Jules
- **Failure Mode:** Bindings where the field marker and emphasized value are separated by a newline or intervening prose stop syncing, leaving stale rendered text in markdown bodies even when frontmatter or ProcessWire field values change.
- **Why this is real:** `replaceBindingValue()` now limits the closing delimiter search to the current line after requiring the delimiter to begin immediately after the marker (ignoring only spaces/tabs). The guide documents the marker on one line and the emphasized bound value later in the body.

### AUD-004 - MEDIUM: Image hash resync still fails open on filesystem errors

- **Location:** `MarkdownHtmlConverter.php` (line 381)
- **Source:** Jules
- **Failure Mode:** During `resyncImageHashesForPage()`, directory creation, image copy, and final hash-file write can fail silently. The method still records updated hashes and continues, leaving page assets and `image-hashes.json` out of sync with no surfaced error.
- **Why this is real:** The resync path still ignores the results of `mkdir`, `@copy`, and `@file_put_contents`, even though the interactive image resolution path in the same diff was hardened to throw on those same operations.

## Discarded Candidates

### DISC-001 - Empty posted markdown delete branch

- **Status:** Not promoted
- **Reason:** The behavior change is real, but I did not verify a concrete stale-payload path that proves this is an unintended regression rather than the intended fix for explicit empty submission handling.

### DISC-002 - PHP 8.2 requirement bump

- **Status:** Invalid
- **Reason:** The diff adds `symfony/yaml:^7.4`, and that dependency itself requires `php >=8.2`, so the composer requirement bump is justified.

## Notes

- The on-disk `jules-findings-raw.md` artifact in this audit directory was stale from an earlier wrong-scope pull. The final Jules findings below were reconstructed from the completed session output provided by the user.
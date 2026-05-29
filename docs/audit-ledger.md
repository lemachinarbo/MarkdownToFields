### MTF-001 - CRITICAL: Single-Language Rename Leaves Orphaned Source File
* **Location:** `MarkdownSyncHooks.php` (Lines 451-469)
* **Failure Mode:** `handleRenameFiles()` returns immediately when the languages service is absent, so single-language page renames never move the markdown file. After a page rename, the module points at the new filename while the old markdown file remains on disk with any unmapped content stranded.
* **Impact:** Data loss risk and permanent content drift between ProcessWire state and the canonical markdown source.
* **Required Fix:** Run rename handling for single-language sites by falling back to the default page name path instead of returning when `$languages` is missing.
* **Required Fixture:** `tests/fixtures/06-single-language-rename-preserves-source/` covering a renamed single-language page with unmapped body content in the original file.
* **Status:** Resolved

### MTF-002 - CRITICAL: Markdown Source Path Override Is Never Validated
* **Location:** `MarkdownFileIO.php` (Lines 16-143)
* **Failure Mode:** `isValidSource()` exists but is never enforced, and `getMarkdownFilePath()` only strips a leading slash before composing the filesystem path. A page-level `contentSource()` override can return traversal segments, dotfiles, nested arbitrary paths, or non-canonical filenames that the module will read, write, rename, and delete.
* **Impact:** Security risk and filesystem integrity risk from writes outside the configured content root.
* **Required Fix:** Validate every resolved source with a strict allowlist before path composition, reject traversal and non-`.md` targets, and fail hard on invalid overrides.
* **Required Fixture:** `tests/fixtures/07-invalid-content-source-rejected/` covering `../escape.md`, `.hidden.md`, and `content.txt` override values.
* **Status:** Resolved

### MTF-003 - CRITICAL: Posted Markdown Write Errors Are Silently Swallowed
* **Location:** `MarkdownSyncEngine.php` (Lines 248-296)
* **Failure Mode:** The initial posted-markdown write path wraps filesystem writes in a blanket `try/catch` and discards every exception. When the destination is unwritable or path resolution fails, the save pipeline continues without surfacing the failure or aborting subsequent sync logic.
* **Impact:** Silent failure and hash/state divergence between disk, session state, and persisted page fields.
* **Required Fix:** Remove the empty catch, propagate write failures as `WireException`, and abort the save before hashes or field values are updated.
* **Required Fixture:** `tests/fixtures/08-posted-markdown-write-failure/` covering an unwritable markdown destination during `syncToMarkdown()`.
* **Status:** Resolved

### MTF-004 - HIGH: contentSource Override Exceptions Fall Back to a Different File
* **Location:** `MarkdownFileIO.php` (Lines 43-67)
* **Failure Mode:** Exceptions thrown by a page-specific `contentSource()` override are swallowed and the module silently falls back to the page-name-derived filename. A broken override therefore redirects reads and writes to a different source file instead of failing at the call site.
* **Impact:** Silent source fork and unexpected file creation or overwrite.
* **Required Fix:** Only tolerate override exceptions for unsaved pages if that state is explicitly intended; otherwise throw and stop the sync.
* **Required Fixture:** `tests/fixtures/09-content-source-override-exception/` covering a saved page whose `contentSource()` override throws.
* **Status:** Resolved

### MTF-005 - HIGH: Non-Core Field Save Failures Do Not Abort Sync
* **Location:** `MarkdownSyncEngine.php` (Lines 154-203)
* **Failure Mode:** `saveDirtyFields()` records all field save exceptions, but `handleFailedFields()` only throws for `name` and `title`. Any other mapped field can fail to persist while the markdown file and in-memory values continue to advance.
* **Impact:** Silent partial save and long-lived divergence between markdown frontmatter and ProcessWire fields.
* **Required Fix:** Treat every mapped field save failure as a sync failure or explicitly roll back file and in-memory mutations before continuing.
* **Required Fixture:** `tests/fixtures/10-noncore-field-save-failure-aborts-sync/` covering a mapped custom field that throws during `$page->save($field)`.
* **Status:** Resolved

### MTF-006 - HIGH: Frontmatter Parser Corrupts Legitimate YAML-Like Input
* **Location:** `MarkdownDocumentParser.php` (Lines 7-186)
* **Failure Mode:** `splitDocument()` treats any inline `---` as a closing fence and strips indentation from frontmatter lines, while `parseFrontmatterRaw()` only supports a flat key/value plus list subset and coerces numeric, boolean, and `null` strings automatically. Inputs like `title: ACME --- Europe`, nested maps, preserved indentation, and quoted numeric strings do not round-trip safely.
* **Impact:** Content corruption and irreversible frontmatter shape changes on read/write cycles.
* **Required Fix:** Replace the ad hoc parser with a strict parser that only closes on standalone fences, preserves indentation, and round-trips scalar strings without lossy coercion.
* **Required Fixture:** `tests/fixtures/11-frontmatter-roundtrip-edge-cases/` covering inline `---`, nested structures, indented blocks, quoted numerics, booleans, and leading-zero strings.
* **Status:** Resolved

### MTF-007 - HIGH: Rename Collision Logic Adopts Existing Files With Weak Ownership Proof
* **Location:** `MarkdownSyncHooks.php` (Lines 386-430)
* **Failure Mode:** When a rename targets an existing markdown file, the code accepts it if the frontmatter `name` matches any current localized page name and otherwise only warns before proceeding. That permits a page rename to attach to an unrelated orphan or stale file without a hard collision failure.
* **Impact:** Silent file ownership collision and source-of-truth hijack.
* **Required Fix:** Require a strong ownership check before adoption, and throw on any pre-existing target file that cannot be conclusively linked to the same page.
* **Required Fixture:** `tests/fixtures/12-rename-collision-rejected/` covering rename onto an unrelated existing markdown file and a verified same-page orphan reunion.
* **Status:** Resolved

### MTF-008 - HIGH: Link Rewrite Path Overwrites Referencing Pages Without Concurrency Guard
* **Location:** `MarkdownBoundLinks.php` (Lines 118-204)
* **Failure Mode:** `refreshReferencesForPage()` rewrites another page's markdown file, updates its markdown field, and persists its link index without any hash comparison or rollback boundary. If the referencing page changed concurrently or one step fails mid-sequence, the module overwrites external edits or leaves a partial rewrite behind.
* **Impact:** Silent cross-page overwrite and partial tree mutation.
* **Required Fix:** Add optimistic concurrency checks before rewriting referencing pages and make file, field, and index updates fail atomically for each affected page.
* **Required Fixture:** `tests/fixtures/13-link-rewrite-conflict-detection/` covering a renamed target page while a referencing page has a newer unsynced markdown revision.
* **Status:** Open

### MTF-009 - MEDIUM: Unreadable Markdown Files Are Treated as Missing
* **Location:** `MarkdownFileIO.php` (Lines 173-191)
* **Failure Mode:** `loadLanguageMarkdown()` suppresses `file_get_contents()` errors and returns `null` on read failure. Permission errors and transient IO failures therefore collapse into the same code path as a genuinely missing file.
* **Impact:** Misdiagnosis of IO failures and downstream orphan-recovery or file-recreation behavior on the wrong premise.
* **Required Fix:** Distinguish unreadable files from missing files and throw a dedicated exception for read failures.
* **Required Fixture:** `tests/fixtures/14-unreadable-markdown-file/` covering an existing markdown file with denied read permission.
* **Status:** Open

### MTF-010 - MEDIUM: Binder Sync Regex Rejects Valid Bound Values
* **Location:** `MarkdownSyncEngine.php` (Lines 681-697)
* **Failure Mode:** The binder sync regex only matches emphasized values that do not contain `_` or `*`. Bound values containing underscores, markdown emphasis characters, or similar punctuation stop syncing even though the field binding marker is present.
* **Impact:** Silent binder drift between frontmatter values and rendered markdown body text.
* **Required Fix:** Replace the regex-only binder matcher with a parser that isolates the emphasized token without forbidding legitimate content characters.
* **Required Fixture:** `tests/fixtures/15-field-binder-special-characters/` covering bound values such as `price_code`, `a*b`, and mixed emphasis characters.
* **Status:** Open

### MTF-011 - MEDIUM: Image Asset Copy and Hash Persistence Fail Open
* **Location:** `MarkdownHtmlConverter.php` (Lines 141-301)
* **Failure Mode:** Image copy failures and sidecar hash writes are suppressed, and the resolver can still return a public asset URL after a failed copy. The module therefore reports a usable image path even when the underlying file or `image-hashes.json` state was never written successfully.
* **Impact:** Broken frontend asset URLs and stale image refresh state with no surfaced error.
* **Required Fix:** Check copy and hash-write return values explicitly, throw on failed asset materialization, and only return a ProcessWire URL after the destination file exists.
* **Required Fixture:** `tests/fixtures/16-image-copy-and-hash-write-failure/` covering a non-writable assets directory and a non-writable `image-hashes.json` sidecar.
* **Status:** Open

### MTF-012 - MEDIUM: Empty Posted Markdown Cannot Intentionally Delete the Document
* **Location:** `MarkdownSyncEngine.php` (Lines 271-275)
* **Failure Mode:** An empty posted markdown payload is always ignored as stale form noise, so an explicit user action that clears the document is indistinguishable from a partial submission. The existing markdown file remains on disk with no deterministic delete behavior.
* **Impact:** Inability to express intentional deletion and silent retention of stale source content.
* **Required Fix:** Differentiate explicit empty submissions from missing form values and define a hard delete-or-empty-document policy for canonical markdown files.
* **Required Fixture:** `tests/fixtures/17-explicit-empty-markdown-submission/` covering a deliberate full-document clear from the editor.
* **Status:** Open
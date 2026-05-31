## Critical

- **File:** `MarkdownSyncEngine.php` (line 268)
- **Severity:** critical
- **Category:** Functional Bug
- **Description:** The diff changed an empty posted markdown value from a no-op into an unconditional delete. `MarkdownInputCollector::collectSubmittedLanguageValues()` still records any present empty POST value as a submitted language value, and the old code path explicitly documented that stale/partial form payloads can produce empty raw markdown. This branch now treats those payloads as an explicit delete.
- **Failure Mode:** A page save that includes an empty `md_markdown` POST value for a language will unlink the existing markdown file on disk and persist an empty markdown/hash state, causing destructive data loss instead of ignoring the stale payload.

## High

- **File:** `MarkdownFileIO.php` (line 27)
- **Severity:** high
- **Category:** Functional Bug
- **Description:** The new basename check rejects any `contentSource()` override containing path separators. That breaks the module's documented contract for custom nested sources such as `src/about/us/theaboutpage.md` and `src/about/{$this->name}.md`, which were previously accepted and resolved under the configured source root.
- **Failure Mode:** Any page class using a documented nested `contentSource()` path now throws `Invalid markdown source...` during load, save, rename, hash calculation, and collision checks, so those pages can no longer read or write their markdown files.

- **File:** `MarkdownSyncEngine.php` (line 759)
- **Severity:** high
- **Category:** Functional Bug
- **Description:** The new binder matcher only skips spaces and tabs after `<!-- field:name -->` and then requires the emphasis delimiter to start immediately at that position. The documented binder syntax allows normal prose or a newline between the marker and the emphasized value.
- **Failure Mode:** Bindings like the guide's `<!-- field:price -->` followed later by `Our premium package costs *USD 5500*` stop syncing entirely, so frontmatter and ProcessWire field changes no longer update the rendered bound text in the markdown body.

## Medium

- **File:** `composer.json` (line 18)
- **Severity:** medium
- **Category:** Functional Bug
- **Description:** The package requirement was raised from `php >=8.0` to `php >=8.2` even though the module's own repository contract still declares PHP 8.0+ support and the reviewed code changes do not introduce an 8.2-only language dependency.
- **Failure Mode:** Composer install/update now fails outright on PHP 8.0 and 8.1 environments that were previously supported, blocking deployment of the module before any code can run.

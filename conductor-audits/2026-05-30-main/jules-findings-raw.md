- **File:** `MarkdownDocumentParser.php` (line 14)
- **Severity:** high
- **Category:** Functional Bug
- **Description:** The new regex `/\A---\r?\n(.*?)\r?\n---(?:\r?\n|\z)(.*)\z/s` used to match frontmatter fails to parse documents that have trailing spaces on the first `---` line (e.g., `--- \r\n`). The previous logic handled this by trimming the document and lines before matching `---`. Because it fails to match, the parser incorrectly treats the entire document as body content and returns empty frontmatter.
- **Failure Mode:** Documents with trailing whitespace on the opening frontmatter delimiter will fail to parse frontmatter entirely, resulting in data loss or failure to sync mapped fields correctly when loading from markdown.

- **File:** `MarkdownFileIO.php` (line 27)
- **Severity:** high
- **Category:** Functional Bug
- **Description:** The `requireValidSource` method calls `isValidSource`, which now explicitly rejects paths containing slashes (`if ($trimmed !== basename(str_replace('\\', '/', $trimmed))) { return false; }`). Previously, the `ltrim($source, '/')` call in `getMarkdownFilePath` meant the system supported directory paths (like `category/page.md`). This new validation breaks backwards compatibility and causes runtime exceptions for any page class that overrides `contentSource()` to return a path containing a directory separator.
- **Failure Mode:** A `WireException` is thrown when attempting to load, save, or sync markdown for any page that relies on an overridden `contentSource()` returning a subdirectory path, breaking synchronization for these pages.

- **File:** `MarkdownSyncEngine.php` (line 745)
- **Severity:** high
- **Category:** Functional Bug
- **Description:** The `replaceBindingValue` method logic was rewritten and intentionally limits its search for the closing delimiter to the end of the current line (`strpos($bodyContent, "\n", $valueStart)`). The previous regex implementation using the `/s` modifier correctly matched field bindings that spanned multiple lines.
- **Failure Mode:** Field bindings (e.g., `<!-- field:name --> *value*`) that span multiple lines will no longer be matched or updated, leaving stale values in the markdown document.

- **File:** `MarkdownHtmlConverter.php` (line 381)
- **Severity:** medium
- **Category:** Silent Failure
- **Description:** Inside `resyncImageHashesForPage`, failures from `mkdir` and `@copy` are silently ignored. While identical operations in `resolveImageForPage` (lines 191-197) were updated in this diff to throw `WireException` on failure, these occurrences were missed.
- **Failure Mode:** If the server lacks permissions to create the directory or copy the image asset during a bulk hash resync, the operations will silently fail, the file will not be placed in the page assets directory, but the logic will continue as if it succeeded.

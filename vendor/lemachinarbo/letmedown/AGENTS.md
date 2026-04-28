# LetMeDown Agent Guide

## Enforce Project Snapshot

- Language: PHP 8.1+
- Type: library
- Main dependency: `erusev/parsedown`
- Tests: PHPUnit 9
- Main implementation lives in one file: `src/LetMeDown.php`
- Environment: This project uses DDEV. PHP is accessible via `ddev php`.

## Preserve Core Parser Behavior

LetMeDown parses markdown into a structured content model with:

- sections (`<!-- section:name -->`, `<!-- section -->`)
- subsections (`<!-- sub:name -->`, closers `<!-- /sub -->` and `<!-- /sub:name -->`)
- fields (`<!-- fieldname -->`, extended containers `<!-- fieldname... -->`, bindings `<!-- field:name -->`)

It supports:

- frontmatter parsing (`---` blocks)
- hierarchical heading **blocks**
- typed field extraction (text, link(s), image(s), list, binding)
- structured data projection via `data()` methods

## Use This Architecture Map

Everything is defined in `src/LetMeDown.php`:

- `LetMeDown`: parser entrypoint (`load`, `loadFromString`)
  - constructor supports `basePath` and `allowRawHtml`
- `ContentData`: top-level parsed document API
- `Section`: section/subsection container
- `Block`: heading/content hierarchy node
- `FieldData`: typed field value
- `FieldContainer`: structural field (`...` syntax)
- `ContentElement`, `HeadingElement`, `ContentElementCollection`
- `PlainDataProjector`: `data()` contract projection

## Follow Compatibility Rules

When changing parser behavior, treat these as compatibility constraints:

1. Keep section order stable and numeric (`sections`), while named lookup remains first-win via `sectionsByName`.
2. Do not break magic access patterns like `$content->hero`, `$section->title`, `$block->allImages`, `$field->items`.
3. Preserve `data()` projection shape:
   - content returns named sections
   - section includes `key` and `subsections`
   - iterable fields expose `items`
4. Keep security behavior intact:
   - Parsedown safe mode enabled by default
   - raw HTML allowed only when `allowRawHtml` is explicitly enabled
   - unsafe URI schemes sanitized in links
   - path traversal blocked in `load()` when base path is set
5. Keep subsection parsing boundaries and closer behavior (`/sub`, `/sub:name`) consistent.

## Protect Large-File Edits (Critical)

`src/LetMeDown.php` is a large, multi-class file. Treat it as a high-risk edit target.

1. Never do broad replacements across the whole file without tight anchors.
2. Read and patch in small windows around the target function/class.
3. Re-open nearby context after each patch to detect accidental drift.
4. Avoid "cleanups" outside requested scope in this file.
5. If multiple nearby edits are needed, patch them in one coherent hunk to reduce offset drift.
6. After edits, run focused tests for the touched behavior before full suite.

## Run Tests Before And After Changes

Run before and after parser changes:

```bash
ddev composer test
```

Useful focused runs:

```bash
ddev php vendor/bin/phpunit --configuration phpunit.xml.dist tests/SecurityXssTest.php
ddev php vendor/bin/phpunit --configuration phpunit.xml.dist tests/DataContractTest.php
ddev php vendor/bin/phpunit --configuration phpunit.xml.dist tests/IntegrationTest.php
```

## Apply Editing Rules

1. Make minimal, localized changes. This parser has many cross-cutting tests.
2. Prefer updating existing logic over large rewrites in `src/LetMeDown.php`.
3. Add/adjust tests in `tests/` with every behavior change.
4. Do not introduce BC breaks in public method names or magic access without explicit request.
5. Keep security checks explicit and close to data ingestion/parsing boundaries.

## Watch Regression Hotspots

- marker classification and range matching (`findAllMarkers`, `buildFieldRanges`)
- subsection extraction boundaries in `extractDefaults`
- heading hierarchy synthesis in `parseBlocks` and `buildHierarchy`
- data projection consistency in `PlainDataProjector`
- `FieldData::items()` behavior and type-specific item transformation

## Run Quick Validation Checklist

- no new exceptions in `load()` and `loadFromString()` paths
- no merge of subsection content back into main section unexpectedly
- `field('name')` and magic property access still return same object types
- `data()` output keys unchanged unless intentionally expanded
- security tests continue to pass

## Respect Release And CI Rules

- CI automation is handled by Release Please in `.github/workflows/release.yml`.
- Releasing triggers on push to `main`:
  - creating/updating a Release PR for manual approval.
  - on merge, it automates version tagging, changelog generation, and asset packaging.
- Releases use the `CI_TOKEN` secret for authenticated actions.
- Tests are enforced during the asset packaging job for every automated release.

If you change parser behavior, update tests first (or in the same change) and keep commits small and explainable.

## Git & Commit Standards
- **No Auto-Committing**: Never run `git commit` autonomously. Instead, suggest that changes are ready and propose exactly what the commit message should be in a code block for the user to execute manually.
- **Flat History Only**: Never create merge commits. Always squash or rebase to maintain a linear timeline.
- **Commit Format**: Strictly follow the Conventional Commits specification. This drives the automated changelog.
- **Translation Logic (Strict Mapping)**:
   - `add` -> commit as `feat: [description]`
   - `fix` -> commit as `fix: [description]`
   - `remove`, `update`, or `refactor` -> commit as `refactor: [description]`
   - `chore` -> commit as `chore: [description]` (for internal config, tooling, and repo maintenance).
   - **Constraint**: If a request uses a verb outside this list, stop and ask for the correct mapping. Do not infer.
- **Message Structure (Required)**:
   - Subject line must be exactly: `<type>: <imperative description>`
   - Add a blank line after subject
   - Add a short bullet body describing concrete changes
   - Use `- ` bullets only (no nested lists)
- **Style Rules**:
   - **Case**: The entire subject line must be lowercase.
   - **No Fluff**: No emojis, no "AI-generated" or "Verified" footers, and no trailing periods.
   - **Length**: Keep the subject line concise (under 50 characters).
- **Example**:

```text
- **Example**:
  feat: add input validation to hastemplate
  
  - this check uses a regex whitelist to prevent directory traversal
  - it ensures template names only contain alphanumeric characters, underscores, and hyphens
  - aligns validation with existing logic in the render method
```

## Maintenance & Sync

- **Self-Documenting Rule**: You are responsible for keeping this file accurate. 
- **Definition of Done**: A task is only complete if any changes to public methods, logic boundaries, or architecture are reflected in this file.
- **Audit Trigger**: If you detect a mismatch between the code and this guide, update the guide immediately before proceeding with other changes.
- **Scope**: Keep descriptions clinical. Do not change the underlying logic when updating this file.

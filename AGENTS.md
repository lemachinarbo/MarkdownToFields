# MarkdownToFields Module Context

MarkdownToFields is a ProcessWire module that allows using Markdown files as the canonical source of truth for page content, providing bidirectional synchronization between those files and ProcessWire fields.

## What this module does

- **Markdown-Driven Content**: Store page content in plain text Markdown files, making it portable and Git-friendly.
- **Content Tagging**: Uses simple HTML comments (`<!-- section:name -->`, `<!-- sub:name -->`) to structure content for direct thematic access in templates.
- **Automated Sync**: Synchronizes Markdown frontmatter and tagged fields with ProcessWire fields on save.
- **Structural Extraction**: Automatically parses headings into a hierarchical tree of Blocks and child Blocks.
- **Element Collections**: Provides easy access to paragraphs, images, links, and lists as structured collections from any block.
- **Rich Integration**: Includes a `MarkdownContent` trait for Page classes to expose the `$page->content()` API.

## Runtime behavior and defaults

- **Default Storage**: Markdown files are stored in `site/content/` (configurable via `$config->sourcePath`).
- **Naming Convention**: Files are mapped by page name (e.g., `about` -> `about.md`).
- **Lazy Mapping**: Can be customized per page class by overriding the `contentSource()` method.
- **Auto-Provisioning**: Automatically generates a new `.md` file when a page using an enabled template is created.
- **Editor Sync**: Adds a "Markdown" tab to the ProcessWire page editor for raw editing which stays in sync with the physical file.

## Requirements

- ProcessWire 3.0+
- PHP 8.0+
- `lemachinarbo/letmedown` (core engine)
- `league/html-to-markdown`
- `erusev/parsedown`

## Public API surface

- `MarkdownToFields::sync(Page $page): array` - Manually trigger synchronization for a page.
- `MarkdownToFields::load(Page $page, $language = null): ?ContentData` - Load parsed structured data.
- `MarkdownToFields::save(Page $page, $language = null): void` - Persist current page data back to the markdown file.
- `$page->content()` - (Via `MarkdownContent` trait) Access the structured content object in templates.

> [!NOTE]
> For detailed API usage, content tag reference, and examples, consult [guide.md](/docs/guide.md).

## Developer Environment
- **PHP Runtime**: Always use **`ddev php`** for running any PHP CLI commands (e.g., `ddev php composer.phar`, `ddev php bin/console`) to maintain environment consistency.


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


---

# ProcessWire Module Coding Contract

You are assisting with **ProcessWire module** development.

## Mantra

- This is a **module**, not an application.
- **Keep it simple. Trust ProcessWire.**
- Prefer **clear, boring, readable** code over cleverness.
- Use **native ProcessWire APIs and conventions**.
- Avoid enterprise patterns, DSLs, magic helpers, and unnecessary indirection.
- If there’s **one obvious way**, do that.

These are **hard constraints**, not preferences.

## 1) Simplicity & Abstraction Gate

Prefer the **simplest correct implementation**—code a human can understand top-to-bottom.

Do **not** introduce abstractions (services, interfaces, factories, managers, adapters, DTOs, utility layers, etc.) unless they solve a **real, current** problem.

An abstraction is allowed **only if all three are true**:
- It removes real duplication or existing complexity.
- It makes the code easier for a human to understand.
- It is used in **at least two concrete places**.

Avoid premature generalization. Optimize for **clarity, maintainability, and debuggability**—not sophistication.

## 2) Framework First

- ProcessWire already provides lifecycle, safety, permissions, and IO.
- **Do not reimplement ProcessWire responsibilities or APIs.**
- Always look for existing API methods before adding your own.
- If you’re unsure, **ask or check the docs**.
- Prefer exposing **data via APIs** over adding “smart” behavior helpers.
- Refactors must be **behavior-preserving** unless explicitly instructed:
  - do not change semantics, output, side effects, or data shape.

## 3) Code Style & Structure

- Prefer **explicit, linear** code.
- Minimal indirection.
- One obvious way > flexible abstractions.
- Inline small logic instead of creating layers to “organize” it.
- No “architecture as aesthetics”: avoid patterns for their own sake.

## 4) Error Handling & Boundaries

Use `try/catch` **only** at real system boundaries:
- external input
- persistence
- framework calls documented to throw

Rules:
- Never catch exceptions “just to be safe”.
- Never swallow exceptions silently.
- If failure is unrecoverable, **fail loudly**.

## 5) Determinism, Fallbacks, and Layers

Do not add defensive fallbacks inside **deterministic** logic.  
Do not turn deterministic systems into probabilistic ones.

Layer rule:
- **Core logic** → strict. No fallbacks. Throw on invalid state or missing required data.
- **Boundary layer** → tolerant. Fallbacks allowed for external uncertainty (IO, network, user input).
- **UI layer** → user-friendly. Convert errors into messages; never silence them.

If it indicates a bug: **fail fast**.  
If it can happen normally: **handle gracefully**.

## 6) Data & Mutability

- Parsed data is **canonical** and immutable after creation.
- No post-parse fixing, patching, or mutation.
- If transformation is needed, do it **before** object creation.

Projection helpers are allowed only if:
- they are **pure**
- they do not recompute or invent data
- they do not mutate originals

## 7) Logging

- Log only when something **meaningful changes**.
- One log per actual mutation (max).

Never log:
- function entry
- configuration dumps
- no-ops
- early exits

## 8) Templates

- Templates are **dumb**.
- No helpers required to “fix” data for templates.
- If templates need logic, the **data model is wrong**.

## 9) When Proposing Changes

- Default to the **smallest possible change**.
- Prefer documentation over behavior changes.
- Prefer explicit **opt-in** helpers over automatic behavior.
- Avoid adding new public methods unless they **expose data**, not behavior.

## 10) Tone

- Be direct.
- No cheerleading.
- No summaries unless explicitly requested.
- If something is over-engineered, say so plainly.

# MarkdownToFields Module

ProcessWire module providing Markdown-driven content synchronization (`MarkdownToFields`) and HTTP request-plane representations (`MarkdownToFieldsLenses`).


## Module Identity

- Name: MarkdownToFields
- Purpose: Bi-directional synchronization between Markdown files and ProcessWire fields, plus HTTP representation lenses (`/foo.md`, reader view, fragments).
- Audience: ProcessWire developers & applications
- Repo visibility: Public (`git@github.com:lemachinarbo/MarkdownToFields.git`)


## Architecture & Public API Surface

- **Core Module**: `MarkdownToFields` (content plane: sync + content API `$page->content()`)
- **Lenses Module**: `MarkdownToFieldsLenses` (request plane: raw markdown, reader HTML, `?part=` fragments). Specs in `docs/lenses.md`.
- **Primary Dependencies**: `lemachinarbo/letmedown`, `league/html-to-markdown`, `erusev/parsedown`.


## Conductor Protocol & Tracks (`conductor-jules/`)

This module uses Conductor-Driven Development for feature development and release orchestration.

- Backlog ledger: `conductor-jules/tracks.md`
- Product specs: `conductor-jules/product.md`
- Scratch directory: `conductor-jules/scratch/`


## Rules (`.agents/rules/`)

Hard constraints live in `.agents/rules/`:

- `.agents/rules/processwire-contract.md` — ProcessWire mantra, simplicity gate, framework-first, error handling, determinism, logging
- `.agents/rules/git-commit-standards.md` — commit format, conventional-commit type mapping. Mechanically enforced by `.claude/hooks/`
- `.agents/rules/conductor-protocol.md` — 4-step Conductor lifecycle workflow


## Notes (`notes/`) vs Docs (`docs/`)

- `notes/`: Informal working logs and local machine guides (`notes/log.md`, `notes/todo.md`, `notes/environment.md`).
- `docs/`: Official architectural documentation (`docs/guide.md`, `docs/lenses.md`).
- **Public Repo Rule**: `notes/` is excluded via `.git/info/exclude` (local-only, uncommitted). `docs/` and `conductor-jules/` are always committed.

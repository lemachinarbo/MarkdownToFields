# Conductor Tracks

This file tracks the active development tracks for Jules and other agents.

## Active Tracks

- [ ] **Uninstall Field Check Optimization** (Integrated: b71c689)
- [ ] **Docs Reflection Optimization** (Session: 10046554523363063519)
- [ ] **Cleanup: Unused markdownToHtml** (Session: 13296942377525011433)
- [ ] **Cleanup: Unused normalizeMarkdownBody** (Integrated: 07dac0d)

## Completed

- [x] **Uninstall Field Check Optimization** (b71c689)
  - [x] Batch fetch fields using $fields->find() to eliminate N+1 queries

- [x] **Cleanup: Unused normalizeMarkdownBody** (07dac0d)
  - [x] Remove unused method from MarkdownHtmlConverter
  - [x] Cleanup dead dependency tidyMarkdownSpacing

- [x] **Conductor Environment Setup** (Session: 050cdc09-a555-4d05-8c5c-fdb211fe2e68)
  - [x] Skill Installation
  - [x] Directory Structure
  - [x] Ledger Migration
  - [x] AGENTS.md Refresh
  - [x] PR Template Deployment
- [x] **Performance Optimization Pass** (Sessions: 8161783200084660431, 18008867093354405958, 13122546743955225133)
  - [x] Reflection caching in doc builder
  - [x] Language caching in resolver
  - [x] Batch sync optimization
- [x] **Code Health & Security Hardening** (Sessions: 13660741353584511202, 6052995804715138381, 5317230512298581675)
  - [x] Dead code removal
  - [x] HTML purification integration
- [x] **isset() implementation** (LetMeDown 505916c)
  - [x] Add __isset() to Section and ContentData
  - [x] Verified via diagnostic check
- [x] **Documentation Playground Hardening** (9aa2f57)
  - [x] Remove eval() and PHP execution logic
- [x] **Uninstall Optimization** (506edbd)
  - [x] Batch fieldgroup saves to minimize database writes

## Backlog

- [ ] **Home image refresh logic**: Image logic is disconnected from the module refresh function. Pages still load old variants after refresh.
- [ ] **dataSet('html') links rendering**: `intro` rendered as `MarkdownDataSet` even if it only contains normal links.
- [ ] **Field Binder / Sync**: Concept for `<!-- page:1234:title -->` to sync text to original page/field to avoid inconsistency.
- [ ] **Hardening pass for sync reliability**: Split `syncToMarkdown()` into steps, remove silent catches, add regression tests for rename/stale payloads/multilingual saves.
- [ ] **ProcessWire-like API**: Explore `page(123)->posts` style access while keeping markdown as source.
- [ ] **Ergonomic Frontmatter access**: `frontmatter->menu` instead of `frontmatter['menu']`.
- [ ] **Content loading ergonomics**: Avoid manual `MarkdownContentView` wrapping when consuming other pages.
- [ ] **Responsive Image Helpers**: Decide if MF should handle `srcset`/sizes or leave it to templates.
- [ ] **Frontend Markdown Caching**: Add optional cache keyed by page+language+mtime if profiling shows bottleneck.
- [ ] **Review guide examples**: Ensure `innerHtml` examples don't show full wrapper tag.

## Future / Exploration

- [ ] **Beauty Sitemap**: Experience with thumbnails and descriptions.
- [ ] **Cross-page reference fields**: `pages('somepage')->content->hero->title`.
- [ ] **Content Revisions Tab**: As seen on Typemill.
- [ ] **Block Editor**: Convert content tab into block editor (Ghost/Koenig style).
- [ ] **Inline Frontend Editor**: Simple automad-style experience.
- [ ] **Markdown-driven routing**: "Big Idea" where the markdown folder structure creates the pages automatically.

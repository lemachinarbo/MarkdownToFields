# Conductor Tracks

This file tracks the active development tracks for Jules and other agents.

## Active Tracks

## Internal Review Queue

- [ ] **Performance: Cache snapshot index file reads** (Session: 12712479203828006873)
- [ ] **Performance: Optimize DOM query loop in collectReadOnlyHostMounts** (Session: 15552125936496673555)
- [ ] **Code Health: Remove leftover console statement** (Session: 8475759274777109420)
- [ ] **Testing Improvement: Missing tests for buildScopeKeyFromMeta** (Session: 15009249872801566978)
- [ ] **Testing Improvement: Missing test file for markdown-text-utils.js** (Session: 13446809845484129784)
- [ ] **Test Case: Frontmatter Sync** (Session: 4188880691859131714)
- [ ] **Test Case: Section Tagging** (Session: 9626251438065124631)
- [ ] **Test Case: Block Hierarchy** (Session: 7430663594449533691)
- [ ] **Test Case: Element Collections** (Session: 12603270156779872681)
- [ ] **Test Case: Empty States** (Session: 2569144650059404077)
- [ ] **Cleanup: Unused markdownToHtml** (Integrated: 9762166)
- [ ] **Cleanup: Unused normalizeMarkdownBody** (Integrated: 07dac0d)

## Completed

- [x] **[audit] main** (Session: 7039359998451057408)
  - [x] Review-only audit completed for `origin/main..HEAD`
  - [x] Ledger written to `conductor-audits/2026-05-30-main/audit-ledger.md`
  - [x] Verdict: Merge after fixes

- [x] **fix: preserve single-language renames** (Commit: 878e20b)
- [x] **fix: reject invalid markdown sources** (Commit: 54a1b64)
- [x] **fix: abort on markdown write failure** (Commit: 6e40457)
- [x] **fix: record override exception coverage** (Commit: 34b2e54)
- [x] **fix: abort on mapped field save failure** (Commit: 82a9f50)
- [x] **fix: harden frontmatter round trips** (Commit: add9df1)
- [x] **fix: reject weak rename adoption** (Commit: f62d52f)
- [x] **fix: support special binder values** (Commit: ed96804)
- [x] **fix: fail loud on image assets** (Commit: 89419ff)
- [x] **fix: delete explicit empty markdown** (Commit: 0b5ccb5)

- [x] **Code Health: Document Silenced Exceptions** (Integrated: 13216917539890387501)
  - [x] Add comment in MarkdownContent.php explaining silenced exceptions during unsaved page creation
  - [x] Add comment in MarkdownFileIO.php documenting silenced exceptions when checking class overrides on unsaved pages

- [x] **Cleanup: Unused markdownToHtml** (9762166)
  - [x] Remove unused method from MarkdownHtmlConverter
  - [x] Remove dead dependencies: Parsedown and ensureStructuralBreaksForRender

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

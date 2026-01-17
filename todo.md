# Todo

- [ ] On module UI: Explain why one has to select templates so 'content tags' are available (Which I don't know if it's true. I have to double check that the module is only hooked to selected templates and not to any page)
- [ ] The module allows changing the name of the md_editor. When I switch from `md_editor` to `foo`, the former isn't added to the template automatically; it's only added if one unselects and reselects the templates.
- [ ] The markdown files has links as /en/foo. How we translate that to processwire page references?
- [ ] do we need to do  self::ensureMarkdownSyncer(); ?
- [ ] when templates are called using 'enabledTemplates' => ['home'] the amrkdown fields and tab isnt added to template
- [ ] If a template has a field for content defined in config 'htmlField' => 'body', when selecting the template in the module it stills adds the mc_editor field, that wasnt expected
- [ ] If.... module loads the markdown and doent rely on processwire fields to show the frontend... is content cached? or are we making petitions every single time?

# New features

- Access reference fields from another page. pages('somepage')->content->hero->title and access files $page->loadContent('site.md');
- A tab for content revisions, as seen on [Typemill](https://try.typemill.net/tm/content/visual/free-features)
- Convert the content tab into a block editor, as seen on [Koenig](https://koenig.ghost.org)
- Inline frontend editor. Like [automad](https://try.automad.org/) but a simple experience
- Big idea: What if... instead of making pages in processwire, the markdown folder creates the pages, where each markdown file is a page. BOOM
- [ ] Explore staging/hotfix workflow: allow major edits in staging, but enable quick typo fixes directly in production, with a way to sync hotfixes back to the repo.
- [x] **Image asset handling - Config infrastructure**: Added `imageBaseUrl` config option to MarkdownToFields with `{pageId}` placeholder support. Allows specifying where images should be resolved from. Usage: `'imageBaseUrl' => $config->urls->files . '{pageId}/',`
- [ ] **Image asset handling - Processing**: Implement automatic image detection and processing in markdown. Still needed:
  - Auto-detect image references in markdown (e.g., `![alt](myfolder/foo.jpg)`)
  - Move/copy images to ProcessWire's page assets folder (site/assets/files/{pageId}/)
  - Update image URLs in both markdown and HTML to point to correct asset paths
  - Generate responsive image variations using PW's Pageimage system
  - Built-in helper for generating responsive image URLs that integrate with PW's image manipulation
- [ ] **ContentData caching optimization**: `$page->content()` parses markdown on every request (bypasses PW fields). This is by design for markdown-first workflow, but consider adding optional caching layer:
  - Cache ContentData objects keyed by file mtime + page ID
  - Invalidate on file changes or manual flush
  - Config option to enable/disable (off by default to preserve current behavior)
  - Note: ProCache or page-level caching may be sufficient for most cases
  - Trade-off: Markdown parsing is fast (~1-5ms), caching adds complexity
  - Monitor: Only optimize if profiling shows this as actual bottleneck
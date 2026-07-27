# Lenses: multiple representations over one URL

`MarkdownToFieldsLenses` is the request-plane companion module to
MarkdownToFields. Install it and every markdown-backed page answers in
alternate representations — no project code required.

| Request | Response |
|---|---|
| `/foo.md` (agent, script, curl) | Raw clean markdown |
| `/foo.md` (browser, `Accept: text/html`) | Reader view — low-noise HTML |
| `/foo/?format=md` | Raw markdown, always, regardless of Accept |
| `/foo/` + `Accept: text/markdown` | Raw markdown via content negotiation |
| `/foo/?section=hero,cta` | Just those named sections, as HTML |
| `/foo/?section=hero&format=md` | Just those sections, as markdown |
| `/foo/` (normal) | Normal render + `Link: rel="alternate"` markdown header |

The homepage has no URL segment, so it is served at `/home.md`
(configurable).

## Decision rules

- **`?format=md` is the explicit override.** It always means raw markdown.
  Only the `.md` URL has browser-vs-agent dual behavior.
- **Exposure**: a lens answers only for pages that are markdown-backed
  (MarkdownToFields recognizes them and the source file exists), viewable
  by the current user, and not on an excluded template. Unpublished pages
  404 — a `.md` URL never leaks a draft.
- **404s answer in the lens's own format**: markdown 404 for agents,
  reader-styled 404 for browsers.
- **SEO hygiene**: every lens response is `X-Robots-Tag: noindex` and
  carries a canonical `Link` header to the real page.
- **Caching**: every response gets a body-derived `ETag` and honors
  `If-None-Match` with `304`. `/foo.md` is a real query-free URL (a
  ProcessWire URL hook, not a rewrite), so front caches can treat it as a
  first-class resource. Cache policy is explicit: guests get
  `Cache-Control: public, max-age=300` on successful responses (shared
  caches and CDNs may cache them; the ETag keeps revalidation cheap);
  logged-in users get `private, no-store` (they may be viewing pages
  guests cannot); errors are `no-cache`.

## Raw markdown responses

Clean markdown (structural `<!-- section -->` comments stripped) behind a
thin curated frontmatter block:

```markdown
---
title: About the Playground
description: What this playground is for.
canonical: https://example.com/about/
---
```

`title` falls back through frontmatter `seo_title` → `title` → the
ProcessWire title. `description` comes from frontmatter `description` →
`summary`. Root-relative links and images are absolutized (agents have no
base URL to resolve against), bare image filenames resolve to the page's
own assets — served markdown never references an image the consumer can't
fetch — and same-page fragments are pinned to the page they were authored
on. The same resolvers apply to content fragments. To serve processed
variants instead (responsive sizes, WebP), hook
`MarkdownToFieldsLenses::resolvers` — one hook covers documents and
fragments — or `markdownFor` for full control of whole documents.

To change how markdown or metadata is produced (custom image pipeline,
different fields), hook the module:

```php
$wire->addHookAfter('MarkdownToFieldsLenses::markdownFor', function ($event) {
  $page = $event->arguments(0);
  $event->return = ...; // your markdown
});
// likewise MarkdownToFieldsLenses::frontmatterFor
```

## Reader view

Browsers landing on `/foo.md` get a self-contained reading page: content,
top-level nav, brand, footer, minimal inline CSS with automatic dark mode.
Internal links are rewritten to their `.md` counterparts so navigation
stays in reader mode.

Customize in two ways:

- **Hook the slots** — `MarkdownReaderView::slots` returns the named parts
  (`brandHtml`, `nav`, `contentHtml`, `footerHtml`, `title`, ...); hook
  after it to change any of them.
- **Replace the template** — copy the module's `markdown-reader.php` to
  `site/templates/markdown-reader.php` and restyle freely. It receives the
  slots as plain variables. No template engine is involved.

### Navigation

Nav items come from `MarkdownSiteStructure`:

1. If the content directory has a `site.md` (file name configurable) with
   a `<!-- section:nav -->` holding a markdown list of links, that is the
   nav. The section name is configurable too (`navSection`, default `nav`)
   — set it to `mynav` and the module reads `<!-- section:mynav -->`.
2. Otherwise the nav is derived from the page tree: home plus its
   markdown-backed viewable children, labeled by each page's frontmatter
   `title`.

The nav section's first heading, when present, is the site brand:

```markdown
<!-- section:nav -->

# [Acme](/)

- [Home](/)
- [Services](/services/)
```

The heading link never becomes a nav item — only list links do. A heading
without a link keeps the label and points to home. Without a structure
file (or without a heading) the brand falls back to the homepage title
linked to home.

## Content fragments

`?section=hero,cta` returns the named sections authored in the markdown
(`<!-- section:hero -->`), joined, as semantic HTML — or as clean markdown
when combined with `?format=md` / `Accept: text/markdown`. Unknown section
names 404. Fragments address the *content model*, never template
internals; styled template fragments (Latte blocks, Datastar chrome)
belong to a project-registered lens.

## Registering a project lens

```php
// site/ready.php
$modules->get('MarkdownToFieldsLenses')->addLens(new MyDatastarLens());
```

A lens implements the two-method `MarkdownLens` interface (`wants()`,
`render()`) and returns a `MarkdownLensResponse` (body, content type,
status, headers — never echoes). Registered lenses are checked before the
built-in ones on every page render, so they can also override built-in
behavior.

## Module configuration

- **Raw markdown / Reader view / Content fragments** — independent
  toggles, all on by default.
- **Structure file** — default `site.md`.
- **Homepage markdown name** — default `home` (`/home.md`).
- **Excluded templates** — templates that must never answer through a
  lens.

## Testing

`tests/lenses-http-matrix.sh` runs the full HTTP matrix (formats,
negotiation, fragments, 404s, exposure, headers, conditional GET) against
a live install, default `https://pwlayground.ddev.site`.

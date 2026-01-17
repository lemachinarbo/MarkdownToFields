# MarkdownToFields, DOCS

Use markdown as your content. Structure it with simple tags, and enjoy the markdown <-> ProcessWire fields sync. That’s MarkdownToFields.
For a more detailed explanation, check the [readme](../README.md)

## Why use this?

I’m not going to try to convince you to switch to a Markdown-driven content experience, or explain why this it’s “better”.

Before this module, I had zero experience with the whole “markdown / Flat-CMS” world. But! when I was working on a website in 5 languages where each page had 10 different blocks with different layouts, I was sure I didn't want to be jumping 'pages/fields/languages/tabs' to update the content.

And, Having all living in text files, felt like something I wanted to try.

So, if you have a website with heavy text layouts, lots of content blocks, multilanguage; the versatility of using a single text file to **write** and **structure** content, your way, to make it semantic for your frontend, just feels… good.

Also, markdown lives well in git. You can diff it, review it, move it, generate it, sync it.

## Concepts

These are the few concepts you need to understand to get the module aha moment:

### Content file

The content is just an *average Joe* markdown file _with a little help from my friends_, the `content tags`, which allow you to `mark` segments of the content, giving it structure that actually matches the layout you want to show on the frontend.

Check this simple example and pay attention to the <!-- tags -->. 
The content indentation is *unnecessary*, it's just to highlight the tags.


<a id="fig-1"></a> **FIG 1:** Markdown example

```
---
title: The Urban Farm Studio.
---

<!-- section:hero -->

<!-- title -->
  # The Urban Farm

<!-- intro... -->
  We grow food and ideas in the city.
  From rooftop gardens to indoor farms, we craft systems that actually produce.

  We work where soil, design, and tech collide.

<!-- section:columns -->

<!-- sub:left -->
  ### What we grow

  ![Plants](https://picsum.photos/id/309/400/200)

  - Leafy greens
  - Microgreens and herbs
  - Small fruit plants
  - Mushrooms and sprouts

<!-- sub:right -->
  ### How we work

  - Quick planting cycles
  - Minimal waste, maximal yield
  - Transparent tracking
  - Systems that last

  ![Greenhouse](https://picsum.photos/id/116/400/200)

<!-- section:body -->

  ## Forget industrial farms *and rigid layouts*.

<!-- field:teams -->
  We had help more than *500* companies across the world.

  Our approach is simple:

  - Modular growing setups
  - Tools that fit your city space
  - Everything tracked and measurable
  - Systems that scale without chaos

  Every plot starts small. Every harvest **stays predictable**.
```

The only extra work you really do is sprinkling tags where they actually make sense for your layout.
In `<!-- section:hero -->` I tagged `<!-- title -->` and `<!-- intro -->` because I want direct access to them
($content->hero->title->text, $content->hero->intro->html).

In `<!-- section:body -->` I didn’t tag anything on purpose, because grabbing the whole thing as HTML is enough for my layout
($content->body->html).

You tag what you need, skip what you don’t. The structure follows the layout, not the other way around.

### Content tags

The module treats markdown with a few basic premises:

- Markdown is THE content: a *title* must be the title, not a placeholder.
- Markdown stays readable while tags remain invisible.
- The syntax is strictly to *tag* data. This isn’t an alternative template system.

So what we call “content tags” are just simple HTML comments to tame the tree structure your way:

**Sections** – your main content areas  
- Section: a top-level container defined with `<!-- section:name -->`.
- Subsection: a nested container `<!-- sub:name -->` inside a section.

**Blocks** – document units created from headings  
- Block: a unit created from headings (`#`, `##`, …). Blocks may contain extracted elements (lists, paragraphs, images) and children.
  - Orphan blocks: content before a section’s first heading; `heading` can be `null`.
- Child block: a sub-block inside a parent block (heading hierarchy).

**Fields** – labels to tag elements with layout-friendly names  
- Tag: `<!-- name -->` tags the next element.
- Container: `<!-- name... -->` + `<!-- / -->` captures blocks between them.
- Binder: `<!-- field:name -->` syncs a single value between markdown, frontmatter, and a ProcessWire field.

Use **Sections** to organize, **Fields** to tag specific parts, and **Blocks** to access the tree structure.

### Structure overview:

- section  
  - sub  
    - block  
      - elements: paragraphs, images, links, lists  
      - children (nested blocks)  
  - fields: containers, tags, binder  


## Getting started

### Installation

Bla bla

### Setting up the content

By default the module will look for your markdown files in `site/content/`.
Markdown Files are mapped per **page** and each page uses its page name by default (for example, `about` → `about.md`).

<a id="fig-2"></a> **FIG 2:** Markdown files per page

```
site
├── templates
├── content
│   ├── about.md      # used by page name 'about'
│   ├── home.md       # used by page name 'home'
│   └── services.md   # used by page name 'services'
```

You can add content files at any time, even empty ones. When you create a `Page` with a template enabled in the [module config](#config), the module will [automatically create](#pending) a `pagename.md`, if it doesn’t exist.

#### Custom content folder

You can change the `content folder` (where your markdown files are stored) using the config setting `sourcePath` in your `site/config.php` file.

For example, to use `site/templates/markdown` you can do:

<a id="fig-3"></a> **FIG 3:** Custom content folder source path

```
$config->MarkdownToFields = [
  'sourcePath' => 'templates/markdown/',
];

```

`site/` is used as the root by default, but you’re not limited to it. You can point outside the site folder if you want. For example, `../src/content` will resolve to:

<a id="fig-4"></a> **FIG 4:** Path outside site folder

```
site
├── templates
src
└── content
    └── about.md
```

It’s obvious, but worth to mention it.

#### Custom content source

The module uses `contentSource()` to get your markdown files, and by default uses `$page->name . '.md'` (e.g. `about.md` for page `about`). If you want something different, you can override the value in your Page Class:

<a id="fig-5"></a> **FIG 5:** Customizing the content file path in a Page Class

```php
class AboutPage extends DefaultPage {

  public function contentSource(): string {
    return 'src/about/us/theaboutpage.md';
  }

}
```

Or, if multiple pages use the same template:

<a id="fig-6"></a> **FIG 6:** Dynamic content file path based on page name

```php
class AboutPage extends DefaultPage {

 public function contentSource(): string {
    return 'src/about/' . $this->name . '.md';
  }

}
```

Logic, names, and folder structure are totally up to you.

### Images (Markdown → ProcessWire assets)

Keep authoring simple: write standard markdown images, e.g. `![Hero](hero.jpg)` or with subfolders `![Hero](images/hero/01.jpg)`. The module copies those source files into the page’s assets folder (`site/assets/files/{pageId}/`) and rewrites the URLs, so the editor preview and frontend use ProcessWire-served files.

Configuration (in `site/config.php`):

```
$config->MarkdownToFields = [
  'imageSourcePaths' => [
    $config->paths->site . 'images/', // search paths (relative or absolute), subfolders allowed
  ],
  'imageBaseUrl' => $config->urls->files . '{pageId}/', // served URL prefix; {pageId} is replaced automatically
];
```

Defaults: if `imageSourcePaths` is omitted, `site/images/` is used. Only the original image URL is emitted; no automatic variants are generated unless you add your own Pageimage options later.

---

### Getting the content

Finally, the fun part!  
Once you have your markdown files, include the trait in your  
[default PageClass](https://processwire.com/blog/posts/pw-3.0.152/#new-ability-to-specify-custom-page-classes).  

This trait adds the `content()` method to your Page class, so templates that extend `DefaultPage` can read markdown content.  

Then you can access it from any template using `$page->content()`.

<a id="fig-7"></a> **FIG 7:** Accessing the content of [this markdown](#fig-1)

/site/classes/DefaultPage.php
```php
<?php

namespace ProcessWire;

class DefaultPage extends Page {
  use MarkdownContent;
}

```

  /site/classes/HomePage.php
```php
<?php

namespace ProcessWire;

class HomePage extends DefaultPage {
}
```

/site/templates/home.php
```php
<?php namespace ProcessWire;
  $content = $page->content();
?>

<section class="hero">
  <h1><?= $content->hero->title->text ?></h1>
  <?= $content->hero->intro->html ?>
</section>

<section class="columns">
  <?= $content->columns->left->html ?>
  <?= $content->columns->right->html ?>
</section>

<section class="body">
  <?= $content->body->html ?>
</section>
```

### Content tags

#### Sections

Sections are top-level containers defined with `<!-- section:name -->`.
They group related content until the next section marker (or end of file).

Sections are independent content areas you can render separately.

<a id="fig-9"></a> **FIG 9:** Markdown with two sections

```markdown
<!-- section:hello -->

# Hello

Intro text for hello section.

## Hello foo

- One foo
- One bar

<!-- section:bye -->

# Bye

Short bye text.
```

<a id="fig-10"></a> **FIG 10:** Rendering sections

```php
<?php namespace ProcessWire;
  $content = $page->content();
?>

$hello = $content->hello;
$bye = $content->bye;
```

If content appears **before the first section tag**, it is collected into an **orphan section**.
This orphan section has no name and is accessible by index.

<a id="fig-11"></a> **FIG 11:** Content without a section

````markdown
# No content tag before this title

Intro text outside any section.

<!-- section:bye -->

# Tagged section here

Short text.
``

<a id="fig-12"></a> **FIG 10:** Rendering an orphan section

```php
<?php namespace ProcessWire;
  $content = $page->content();
?>

$orphan = $content->sections[0];
```

### Subsections

Subsections `<!-- sub:name -->` are nested containers, or sublevels, inside a section. A subsection captures all content until the next subsection marker or the end of the current section.

Example:

```markdown
<!-- section:columns -->

# Our Team

<!-- sub:left -->

### Jane Doe

Jane is our lead horticulturist with 10+ years of experience in urban farming.

![Jane tending plants](jane-plants.jpg)

<!-- sub:right -->

### John Smith

John manages our technology and automation.

- IoT sensor integration
- Automated watering
- Data-driven crop planning

Always ready to help troubleshoot or optimize a setup!
```
````

Access:

```php
<?php namespace ProcessWire;
  $content = $page->content();
?>

$jane = $content->columns->left->html;
$john = $content->columns->right->html;
```

### Blocks & children

Blocks and children are **not tagged**. They are generated automatically from headings (`#` to `######`).

Each heading starts a **block**.

- A higher-level heading becomes a **parent block**
- Lower-level headings under it become **child blocks**

Every block holds:

- `text` and `html`
- extracted elements like images, lists, links, paragraphs

You can think of it as the heading hierarchy turned into a tree.

---

Example

```markdown
<!-- section:about -->

# About Us

Welcome to our studio! We create digital experiences for modern brands.

## Our Services

- Web design
- App development
- Content strategy

![Team at work](team.jpg)

# What We Value

## Collaboration

We believe great work comes from working together.

### Innovation together

- Always learning
- Embracing new tech
```

This produces **two top-level blocks** inside the `about` section:

- `# About Us`
- `# What We Value`

---

#### Accessing blocks

```php
$content = $page->content();
$blocks = $content->about->blocks;
```

You can also access blocks by index, without using the section name:

```php
$content = $page->content();
$blocks = $content->section[0]->blocks;
```

#### Children blocks

Blocks can contain children when the heading level increases.

```php
$content = $page->content();
$values = $content->about->blocks[1];          // # What We Value
$collaboration = $values->children[0];         // ## Collaboration
$innovation = $collaboration->children[0];     // ### Innovation together
```

### Fields & containers

Fields tags specific elements for direct access.

- Regular field: `<!-- name -->` captures a single block (stops at first blank line).
- Container field: `<!-- name... -->` collects multiple blocks until `<!-- / -->` (or named closer).

They are similar bu theres a important disctintion. Look at this example

Example

```markdown
<!-- title -->

# Hello

<!-- summary -->

Paragraph one.

Paragraph two.

Paragraph three.
```

```php
$content = $page->content();
```

#### Field bindings

Sometimes you want a value from frontmatter to appear in your content body, and you want it to stay in sync automatically. That's what field bindings are for.

Use `<!-- field:name -->` followed by emphasized text (`*value*` or `__value__`) to create a binding. When you update the frontmatter value (or the corresponding ProcessWire field), the emphasized text updates automatically in both directions.

Example

```markdown
---
price: USD 5500
---

<!-- section:intro -->

# Premium Package

Our premium package costs <!-- field:price -->_USD 5500_.
```

Now if you change `price: USD 6000` in the frontmatter (or edit the `price` field in ProcessWire), the text in the body automatically updates to `*USD 6000*`. No manual find-and-replace needed.

Useful for prices, dates, version numbers, or any value that appears multiple times in your content.

HERE I AM!

Access: `$content->hello->title->text`, `$content->hello->summary->text`.

```php
$title = $content->about->blocks[1];          // # What We Value
$collaboration = $values->children[0];         // ## Collaboration
$innovation = $collaboration->children[0];     // ### Innovation together
```

Rules: first occurrence wins within a section; container fields preserve block structure.

### Frontmatter

Frontmatter is an optional `---` YAML-like block at the top of the file. It's used for metadata (title, name, summary). The parser converts simple K/V pairs to arrays; raw content is also available.

Example

```markdown
---
title: The Urban Farm Studio.
---
```

Access: `$content->getFrontmatter()` and `$content->getFrontmatterRaw()`.

Note: mapping frontmatter keys to ProcessWire page fields is configurable; changing the page `name` requires a module sync to update routing.

---

## How to access content

### Semantic (recommended — stable)

```php
// explicit (works)
$title = $content->section('hero')->field('title')->text;
// preferred: magic shorthand
echo $content->hero->title->html;
```

### Positional (for loops)

```php
$section = $content->sections[0];
$block = $section->blocks[0];
echo $block->children[0]->text;
```

### Convenience

- `content->section($nameOrIndex)` accepts a string name or numeric index.
- `content->section[0]` is an alias for indexed access.

Tip: prefer semantic access — it survives edits.

---

## Blocks & extracted elements

Block properties:

- `heading` (HeadingElement) — use `->text` or `->html`
- `level` (1..6 or `null`)
- `html`, `text`, `markdown`
- `children` (nested blocks)

Element collections (each is a ContentElementCollection):

- `images`, `links`, `lists`, `paragraphs`
- Collections support `->html` and `->text` for aggregated output

Example:

```php
$first = $content->sections[0]->blocks[0];
echo $first->heading->text;
foreach ($first->images as $img) echo $img->src;
```

---

## Fields & containers

Regular field (`<!-- name -->`)

- Captures the following block (stops at the first blank line).

Container field (`<!-- name... -->`)

- Captures multiple blocks until `<!-- / -->` or a named closer.

FieldData provides:

- `text`, `html`, `markdown`, `type` and `items()` (for multi items)

Rules:

- **First occurrence wins** per section (no automatic merging).
- Leading markers before the first heading attach to the first block only when the pre-heading contains only markers (convenience behavior).

Example:

```markdown
<!-- title -->

# Hello

<!-- summary... -->

Para 1

Para 2

<!-- / -->
```

```php
echo $content->intro->title->text;
echo $content->intro->summary->text;
```

---

## Frontmatter & syncing

- YAML-like frontmatter at top (`---` block) is parsed when simple K/V pairs are found.
- API: `$content->getFrontmatter()` and `$content->getFrontmatterRaw()`.
- Module maps frontmatter keys to page fields (configurable) in `MarkdownContent::getMarkdownSyncMap()`.
- Changes in markdown frontmatter are synced to page fields when you edit the raw markdown field (`md_markdown`) in the backend and save—the values from the markdown always win.
- Changing a page `name` (slug/url) in frontmatter requires re-sync/module refresh to update the page routing.

Tip: use frontmatter for metadata (title, summary, tags), not block content.

**Adding more frontmatter fields:** By default, `title` and `name` are synced. To add more (e.g., `description`, `summary`), extend the `frontmatter` array in your page class's `MarkdownContent::getMarkdownSyncMap()`:

```php
'frontmatter' => [
  'title' => 'title',
  'name' => 'name',
  'description' => 'description',  // Add custom mappings
  'summary' => 'summary',
],
```

Now frontmatter keys are synced to the corresponding page fields.

---

## Multilanguage

- Put content per-locale, e.g. `content/en/about.md`, `content/it/about.md`.
- Module loads language-specific file; API is the same per language.
- Use `MarkdownFileIO::loadMarkdown($page, $language)` to load language-aware content.

---

## API & helpers (quick list)

- `LetMeDown::load(path): ContentData` — parse a file
- `MarkdownFileIO::loadMarkdown(Page $page, ?string $lang): ?ContentData`
- `MarkdownSyncEngine::syncToMarkdown(Page $page, ?string $lang)`

ContentData

- `.text`, `.html`, `.markdown`
- `.sections` — ordered numeric array (use for iteration)
- `.sectionsByName` — named lookup (first occurrence wins)
- `section(string|int $nameOrIndex): ?Section` — returns Section by name or index
- magic property: `$content->hero`

Section

- `field('name')`, `subsection('name')`, `getRealBlocks()`

Block

- `heading`, `level`, `html`, `text`, `markdown`, `children`, `field(name)`

FieldData

- `text`, `html`, `markdown`, `type`, `items()`

ContentElementCollection

- aggregated `->html` and `->text` helpers

Note: prefer `section('name')` or magic property for named access rather than indexing into `sections` by name.

---

## Tiny examples

Title + description

```markdown
<!-- section:intro -->
<!-- title -->

# Welcome

<!-- description -->

A short intro paragraph.
```

```php
echo $content->intro->title->text;
echo $content->intro->description->html;
```

Container example

```markdown
<!-- notes... -->

One.

Two.

<!-- / -->
```

```php
echo $content->section('foo')->field('notes')->text;
```

Frontmatter

```markdown
---
title: New title
---
```

Change frontmatter and run sync to update the page data.

Note: syncing `name` (page slug) can move URLs. By default we skip empty `name` values and avoid renaming unless you set a non-empty `name` in frontmatter and run a sync.

# config

$config->MarkdownToFields = [
'htmlField' => 'md_editor', // Editor field (TinyMCE)
'markdownField' => 'md_markdown', // Raw markdown content
'hashField' => 'md_markdown_hash', // Sync state tracker
'sourcePageField' => 'md_markdown_source', // File reference
'sourcePath' => 'content/', // Base path for markdown files (relative to site/)
'debug' => true, // Enable debug logging
];

---

## Best practices (short)

- Use semantic access: `section('name')` or `$content->name`.
- Use container fields for multi-paragraph data.
- Keep field names unique per section.
- Put field markers where you expect them (after heading for block attachment) or use marker-only pre-heading to attach.
- Compare `->text` for content checks to avoid HTML-entity differences.

### Locking raw markdown edits 🔒

You can enable a transient **Markdown editor** mode for a single save using the _Markdown editor_ checkbox on the Markdown tab. When checked for a save, the raw `md_markdown` field is treated as authoritative for that save only (posted markdown will be written to disk and take priority over editor fields). The toggle is **transient** — it applies only to the current save and is not persisted on the page.

---

## Troubleshooting quick

- Null heading? → pre-heading/orphan block exists before first heading.
- Marker not attached? → check pre-heading contains only markers (otherwise becomes orphan).
- Need to update page URL/name? → change frontmatter + run module sync.

---

## Migration notes

- `sections` is now a deduplicated numeric list — `count($content->sections)` matches logical sections.
- Use `section('name')` or magic `$content->name` instead of `$content->sections['name']`.
- Search and replace occurrences of `sections['` when migrating.

---

## References

- Parsing internals: LetMeDown README (`LetMeDown/readme.md`)
- Examples: `LetMeDown/scripts/` and `examples/` folders

---

If you want I can add a small `docs/sidebar.md` and a single index page for the module UI. Want that?

#### Automatic generation of markdown files

When a page is created in the backend, markdown files are automatically created on disk (if they don't exist). This allows field-first workflows where users can start editing in the admin without pre-existing markdown files. Empty fields don't create files (prevents clutter). On next page load, the created markdown file becomes the source of truth for subsequent syncs.

Should explain this clearly so users understand the workflow and file creation behavior.

---


Promotional message: Check out MarkdownSync, a companion module that lets you commit content changes made in the ProcessWire backend straight to GitHub. Edit content, commit, done. Your repo stays in sync with production, which feels oddly satisfying. Yei.

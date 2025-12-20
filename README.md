# Markdown to fields
*Parse markdown into a ProcessWire-style content API and sync it bidirectionally with ProcessWire fields.*

## The Long intro

The basic idea of this module is that you can convert any markdown file into a “structured content source” where content can be extracted via an API-style access, e.g. `$content->field->text`, by using the [LetMeDown parser](https://github.com/lemachinarbo/LetMeDown) (what’s in a name!).

The markdown content gets synced into a single ProcessWire text field and we keep it in sync, no matter if content is edited in markdown or in the admin interface. Changes flow both ways with hash-based conflict detection.

So, basically, what you write in markdown stays in… markdown, but gets synced to some* ProcessWire fields, and vice versa. Cool, right? Unnecessarily… but cool, right?

You can also use frontmatter as page metadata, so things like `title`, `name`, `description`, etc. get synced to their own fields.

### Quick FAQ

**Q: Wait, what?**

So you’re telling me that the whole markdown is synced to just ONE text field? And that you only support title, name, description and the most basic text fields in the world?

**A:** Yes.

**Q: So it’s not Markdown to fields, but markdown to FIELD. Where are the repeaters, the page reference fields, the juicy ones? What’s the point?**

**A:** Imagine this: you’re designing a website where each page has multiple blocks of content. Some blocks are text, some are images and text, some sliders, the whole marketing “I-want-a-page-like-an-Apple-website” package.

You start planning fields, repeaters, combos, multipliers, page references, the whole shebang. You think about how to recycle them, how to make them flexible, how to make them fit into any design. You end up with an admin page with 15 fields, some repeaters inside multipliers with page references inside. It works, it’s cool, but somehow all those fields make every edit page look like the screen of the person who helps you at the bank.

One day, while reading the Latte docs (https://latte.nette.org/en/guide) I noticed this message:

```
Found a problem with this page?
Show on GitHub (then press E to edit)
```

The whole content of the page was text files. And I felt the darkest envy. 
But from that evil envy, a question arose: Can I reduce all that PW multiple-field experience to a single field/text experience?

So there I was, writing my page in markdown like the “first” human who ever had the idea:

```
---
title: I wasn’t copying the Apple page
name: about-us
---
<!-- section:hero -->

# Welcome
<!-- description -->
It all started with a pineapple…

<!-- slider -->
![First slider image](hero.jpg)
![Second slider image](hero.jpg)

<!-- section:features -->
## Our Features

<!-- left -->
Some text…

<!-- right -->
- Feature one
- Feature two
```

**Q: Holy guacamole! Now I get your point. So what about the experience? Does it feel better? Do your clients love it? What does the content person say?**

**A:** Well. The experience… is different. Whatever that means. And when you say “client”, what do you mean? Me? Yeah. I love it.

**Q: So, are you aware that by writing content in markdown files and skipping the “real” fields, you basically lose all the benefits of a database, right?**

**A:** Oh crap… I basically neutered ProcessWire.


## Quick Start

### 1. Install

Copy module to `site/modules/MarkdownToFields/` and install via Admin → Modules → Refresh.

**Dependencies:** The module bundles LetMeDown parser and its dependencies via Composer.

### 2. Set Up Content Directory

Create a content folder structure (mine lokks like this, but do as you please):

```
src/site/content/
  en/
    about/
      about.md
    services/
      sessions.md
  it/
    about/
      about.md
```

### 3. Create a Page Class

```php
<?php
namespace ProcessWire;

class AboutPage extends DefaultPage {
  public function getContentSource(): string {
    return 'about/about.md';
  }

  public function getTemplateParams(): array {
    $content = $this->loadContent($this->getContentSource());
    
    return [
      'title' => $content->hero->title->text,
      'description' => $content->hero->description->html
    ];
  }
}
```

### 4. Write Structured Markdown

**about/about.md:**
```markdown
---
title: About Us
name: about
summary: Learn about our story
---

<!-- section:hero -->

<!-- title -->
# Welcome to Our Company

<!-- description -->
Our story begins in 2020 when we decided to make a difference...

<!-- image -->
![Team photo](team.jpg)
```

### 5. Access in Templates

```php
$content = $this->loadContent($this->getContentSource());

// Access structured content
echo $content->hero->title->text;        // "Welcome to Our Company"
echo $content->hero->description->html;  // <p>Our story begins...</p>
echo $content->hero->image->src;         // "team.jpg"
```

That's it! The module handles syncing between markdown and ProcessWire fields automatically.

## How It Works

### The Flow

**On Page Edit (Opening the Edit Screen):**
1. Module reads the markdown file
2. Checks hash against last known state
3. If file changed externally → syncs markdown → ProcessWire fields
4. Displays fields in admin for editing

**On Page Save:**
1. Collects field values from the form
2. Syncs ProcessWire fields → markdown file
3. Updates frontmatter and content
4. Stores new hash for conflict detection

**Conflict Detection:**
- If markdown file changes while you're editing in admin, you get a warning
- Session storage preserves your unsaved admin changes
- You choose: keep your changes or reload from file

### Bidirectional Sync

Edit in **markdown:**
```markdown
<!-- title -->
# New Title
```
Save file → Open page in admin → Title field shows "New Title"

Edit in **admin:**
Change title field to "Admin Title" → Save page → Markdown file updates automatically

### Multi-Language Support

The module respects ProcessWire's language system:

```
content/
  en/
    about.md    → English content
  it/
    about.md    → Italian content
  de/
    about.md    → German content
```

Same API in templates, different content per language. Language switching happens automatically based on user language.

## Markdown Format

MarkdownToFields uses [LetMeDown](https://github.com/lemachinarbo/LetMeDown) for parsing, which provides:

### Frontmatter → Page Fields

```markdown
---
title: About Us
name: about
shortname: About
summary: Our story
meta_image: hero.jpg
---
```

Maps directly to ProcessWire page fields (configurable via `getMarkdownSyncMap()`).

**Important:** If you change the name, run a modules refresh.
name isn’t treated as content in ProcessWire, it’s part of the page’s structure, so it needs a rebuild pass.


## Requirements

- ProcessWire 3.x
- PHP >= 8.0
- Composer (for installing module dependencies)

## License

DAYP (Do as you please).
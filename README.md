# Markdown to fields
Use markdown as your content. Structure it with simple tags, and enjoy the markdown <-> ProcessWire fields sync. That’s MarkdownToFields.

Need help? See:

- The [Why it's so long intro](#the-long-intro)
- The [I'm lazy resume all to me, guide](#lazy-guide)
- The [MarkdownToFields survival guide](./docs/guide.md) (aka The Docs)

## The Long intro

The basic idea of this module is that you can convert any markdown file into a “structured content source” where content can be extracted via an API-style access, e.g. `$content->field->text`. We use the [LetMeDown parser](https://github.com/lemachinarbo/LetMeDown) (what’s in a name!) to turn your file into a tree of sections, blocks, and elements.

The markdown content can be edited in the Processwire admin or in your favorite editor, and you even can sync specific parts to Processwire fields (we only support text fields at the moment).

So, basically, what you write in markdown stays in… markdown, but gets synced to some* ProcessWire fields, and vice versa. Cool, right? Unnecessarily… but cool, right?

You can also use frontmatter as page metadata. So things like `title`, `name` can get synced to their respective fields.

### Quick FAQ

**Q: Wait, what? So you’re telling me that the whole markdown is the content, not the Procewsswire Fields, and that you only support syncing of basic text fields?**

**A:** Yes.

**Q: So it’s not Markdown to fields, but markdown to FIELD. Where are the repeaters, the page reference fields, the juicy ones? What’s the point?**

**A:** Imagine this: you’re designing a website where each page has multiple blocks of content. Some blocks are text, some are images and text, some sliders, the whole marketing “I-want-a-page-like-an-Apple-website” package.

You start planning fields, repeaters, combos, multipliers, page references, the whole shebang. You think about how to recycle them, how to make them flexible, how to make them fit into any design. You end up with an admin page with 15 fields, some repeaters inside multipliers with page references inside. It works, it’s cool, but somehow all those fields make every edit page look like the screen of the person who helps you at the bank (whatever that means).

One day, while reading the [Latte docs](https://latte.nette.org/en/guide) I noticed this message:

```
Found a problem with this page?
Show on GitHub (then press E to edit)
```

The whole content of the page was text files. And I felt the darkest envy. 

But from that evil envy, a question arose: Can I reduce all that PW multiple-field experience to a single field/text experience?

So there I was, writing my page in markdown like the “first” human who ever had the idea:

```markdown
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

**Q: Now I get your point. But are you aware that by writing content in markdown files and skipping the “real” fields, you basically lose all the benefits of a database, right?**

**A:** Oh crap… I think, er, no, I mean, er, yes, but it's all wrong. That is, I think I disagree.

## Lazy guide

Full docs live in [The MarkdownToFields survival guide](./docs/guide.md)

### 1. Install

Copy module to `site/modules/MarkdownToFields/` and install via Admin → Modules.

### 2. Add the trait

Add the trait to your Page class:

```php
<?php namespace ProcessWire;

class DefaultPage extends Page {
  use MarkdownContent;
}
```

Templates extending this class get `$page->content()`.

### 3. Write markdown

Create a markdown file for your page.

Example: `site/content/about.md`

```markdown
---
title: About Us
---

<!-- section:hero -->

<!-- title -->
# Welcome

<!-- description -->
Our story begins in 2020…

<!-- team -->
![Team photo](team.jpg)
```

### 4. Use it in templates

Example: `about.php`

```php
<?php namespace ProcessWire;

$content = $page->content();

echo $content->hero->title->text;
echo $content->hero->description->html;
echo $content->hero->team->img->url;
```

That’s it.


## Requirements

- ProcessWire 3.x
- PHP >= 8.0


## License

UBC+P.

Use it.
Break it.
Change it.
And if you make money, buy us some pizza.
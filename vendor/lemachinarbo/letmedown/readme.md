# ~~Dont~~ LetMeDown:  a Markdown parser that probably will.

Parsedown → turns Markdown into HTML.  
LetMeDown → turns Markdown into a structured content tree (sections, fields, blocks, etc).

Parsedown makes Markdown pretty.  
LetMeDown makes Markdown complicated.


This guide explains how to use the `LetMeDown` to parse structured markdown and access its content.

## Quick Start

```php
$processor = new LetMeDown();
$content = $processor->load('path/to/your/markdown.md');

// Raw markdown (frontmatter stripped) for syncing or previewing
$rawMarkdown = $content->getMarkdown();

// Frontmatter as array (when YAML) or raw string fallback
$frontmatter = $content->getFrontmatter();

// Original document including frontmatter and body
$rawDocument = $content->getRawDocument();
```

## Markdown Format

The processor recognizes special HTML comments to structure the content.

### Sections and Fields

- **Sections**: Divide your document into large parts using `<!-- section:name -->` or `<!-- section -->`.
- **Fields**: Tag specific elements within a section using `<!-- fieldname -->`.
  - **Regular fields** (`<!-- fieldname -->`) — auto-close at the first blank line
  - **Extended fields** (`<!-- fieldname... -->`) — bleed until `<!-- / -->` or next marker

#### Regular vs Extended Fields

**Regular fields** are perfect for single-block content (one paragraph, one image, one list):

```markdown
<!-- title -->
# My Heading

<!-- description -->
A single paragraph that stops at the blank line.

More content here (not part of description field)
```

**Extended fields** let you capture multiple blocks until you explicitly close them:

```markdown
<!-- description... -->
First paragraph of the description.

Second paragraph, still part of description.

Third paragraph too!
<!-- / -->

This content is NOT part of description.
```

Use extended fields when you need to group multiple paragraphs, lists, or other elements under one field name.

### Sub-sections

You can create nested content structures using `<!-- sub:name -->` markers within a section. This allows for more granular content grouping and clearer access.

**Important:** By design, sections and subsections "bleed" — they extend until the next section/subsection marker or the end of the document. This gives you flexibility but requires explicit boundaries when needed.

#### Closing Subsections

To prevent subsections from bleeding into unwanted content, you can use closing markers:

- `<!-- / -->` — closes the most recent subsection (terse)
- `<!-- /sub -->` — closes the most recent subsection (explicit)
- `<!-- /sub:name -->` — closes a specific named subsection (most explicit)

**Example without closers (bleeding):**

```markdown
<!-- section:boom -->
# boom

<!-- sub:one -->
Para 1

Para 2

<!-- sub:two -->
- A list
- Another item

<!-- sub:three -->
Different content
```

Here, each `sub` extends until the next `sub` marker or end of section.

**Example with closers (controlled):**

```markdown
<!-- section:boom -->
# boom

<!-- sub:one -->
Para 1

Para 2
<!-- / -->

<!-- sub:two -->
- A list
- Another item
<!-- /sub -->

<!-- sub:three -->
Different content
<!-- No closer = extends to end of section -->
```

Now each subsection contains only its intended content.

**Accessing subsections:**

```markdown
<!-- section:parent -->
# Parent Section

<!-- sub:child -->
### Child Sub-section

<!-- item -->
This is an item in the child sub-section.
<!-- / -->
```

Access them like this:

```php
$itemText = $content->sections['parent']->child->field('item')->text;
```

#### The Closing System

LetMeDown uses a **universal closer** with optional **named closers** for clarity:

1. **`<!-- / -->`** — Universal closer. Closes the **most recently opened** field or subsection.
   ```markdown
   <!-- sub:intro -->
     <!-- description... -->
       Multiple paragraphs of content
     <!-- / -->  ← closes description
     
     More content in intro
   <!-- / -->  ← closes intro
   ```

2. **`<!-- /sub -->`** — Explicit subsection closer. Closes the most recent subsection, **skipping any open fields**.
   ```markdown
   <!-- sub:features -->
     <!-- description... -->
       Content here (unclosed field)
   <!-- /sub -->  ← closes features subsection, leaving description to bleed
   ```

3. **Named closers** — `<!-- /sub:name -->` or `<!-- /fieldname -->`. Close a **specific** named item, useful in complex documents.
   ```markdown
   <!-- sub:introduction -->
     <!-- description... -->
       Lots of content
     <!-- /description -->  ← closes only the description field
     
     More intro content
   <!-- /sub:introduction -->  ← closes the specific subsection
   ```

**Why both?**
- `<!-- / -->` is **quick and clean** for simple, clearly-nested content
- Named closers provide **clarity** in large documents where it's hard to track what's open

**Example showing different strategies:**

```markdown
<!-- section:services -->
## Our Services

<!-- sub:consulting -->
### Consulting

<!-- description... -->
We offer comprehensive consulting.
Our team has decades of experience.
<!-- / -->  ← Universal closer (closes description)

<!-- benefits... -->
- Benefit 1
- Benefit 2
<!-- /benefits -->  ← Named closer (explicit)

<!-- cta -->
[Book now](/contact)
<!-- /sub:consulting -->  ← Named subsection closer (clear in long docs)

<!-- sub:training -->
### Training
Content here
<!-- / -->  ← Universal closer (closes training)
```

**Notes:**
- Closers are **optional**. Unclosed subsections extend until the next subsection or end of section.
- Orphan closers (closers without a matching opener) are silently ignored.
- **`<!-- / -->` closes the most recently opened field or subsection** (universal closer).
- **Named closers** (`<!-- /sub:name -->`, `<!-- /fieldname -->`) close specific items, useful in complex nested documents.
- Fields (`<!-- fieldname -->`) auto-close at the first blank line — they don't need closers.
- Extended fields (`<!-- fieldname... -->`) bleed until closed.

#### Extended Fields in Subsections

A powerful pattern is combining subsections with extended fields:

```markdown
<!-- section:services -->
## Our Services

<!-- sub:consulting -->
### Consulting Services

<!-- description... -->
We offer comprehensive consulting across multiple domains.

Our team brings decades of experience in strategic planning.

We work with you to develop custom solutions.
<!-- / -->

<!-- cta -->
[Book a consultation](/contact)
```

Access it like this:

```php
$text = $content->services->consulting->description->text;  // All three paragraphs
$link = $content->services->consulting->cta->href;          // The link
```

This gives you both **hierarchical structure** (subsections) and **flexible content grouping** (extended fields) without needing nested subsections.

### Quick Reference: All Markers

| Marker | Purpose | Auto-closes? | Needs closer? |
|--------|---------|--------------|---------------|
| `<!-- section:name -->` | Start a named section | No, bleeds | Optional |
| `<!-- section -->` | Start unnamed section | No, bleeds | Optional |
| `<!-- sub:name -->` | Start a subsection | No, bleeds | Optional |
| `<!-- fieldname -->` | Regular field (single block) | Yes, at blank line | No |
| `<!-- fieldname... -->` | Extended field (multi-block) | No, bleeds | Yes |
| `<!-- / -->` | Close most recently opened field or subsection | N/A | N/A |
| `<!-- /sub -->` | Close most recent subsection (skip fields) | N/A | N/A |
| `<!-- /sub:name -->` | Close specific named subsection | N/A | N/A |
| `<!-- /fieldname -->` | Close specific named extended field | N/A | N/A |

Here is a minimal example:

```markdown
<!-- section:intro --> // A named 'section'

<!-- title --> // A 'field' for the heading element
# Welcome to our page // A 'block' starts with a heading, this is also a heading 'element'

<!-- summary --> // A 'field' for the paragraph element
This is a summary of the page content. // This is a paragraph 'element'
```

## Accessing Content

There are three primary ways to access content:

1.  **Original Array/Method Mix**: `$content->sections['foo']->roo->field('description')`
2.  **Explicit Method Chain**: `$content->section('foo')->subsection('roo')->field('description')`
3.  **Magic Property Access**: `$content->foo->roo->description`

### 1. Semantic Access (Recommended)

Access content by the names you provided for sections and fields. This method is stable and easy to read.

```php
// Access a section by name, then a field by its tag
$title = $content->sections['intro']->field('title')->text;

// Get the 'src' from an image field
$imageUrl = $content->sections['intro']->field('image')->src;

// Get items from a multi-item field (e.g., a list of links or images)
$links = $content->sections['intro']->field('ctas')->items;
foreach ($links as $link) {
  echo $link->href;
}
```

### 2. Positional Access

Access content by its numerical order. This is useful for looping but can be brittle if the markdown structure changes.

Here's a quick cheatsheet:

```php
$content->sections[0];                // First section in the document.
$content->sections[0]->blocks[0];     // First block in the first section.
$content->sections[0]->blocks[0]->children[0]; // First child of the first block.
$content->sections[0]->images[0];     // First image found in the first section.
$content->sections[0]->paragraphs;     // All paragraphs of first section.
```

You can also get global collections of all elements across the entire document:

```php
$content->images;     // Array of all images.
$content->links;      // Array of all links.
$content->headings;   // Array of all headings.
$content->lists;      // Array of all lists.
$content->paragraphs; // Array of all paragraphs.
```

> **Note on Collections:** Properties that return a list of elements (like `$section->paragraphs` or `$block->images`) now return a special `ContentElementCollection`. You can still loop through it like a normal array, but you can also access `->html` or `->text` on the collection itself to get the combined content of all its items.

## The Field Object

When you access a field with `$section->field('name')`, you get a `FieldData` object with the following properties:

-   `->text`: The plain text content.
-   `->html`: The rendered HTML content.
-   `->markdown`: The original markdown source.
-   `->type`: The auto-detected type (`image`, `link`, `list`, `heading`, `text`).
-   `->src`, `->alt`: For `image` type fields.
-   `->href`: For `link` type fields.
-   `->items`: For fields with multiple items (like a list of images or links) or for `list` type fields.

```php
$imageField = $content->sections['hero']->field('image');

echo $imageField->src; // "path/to/image.jpg"
echo $imageField->alt; // "Hero Image"
```

### Handling List Fields

When a field is a `list`, each item in the `data` array is a structured object containing the full `html` and `text` of the `<li>` element, plus `links` and `images` arrays for any media inside.

**Example:**

Given this Markdown:

```markdown
<!-- my_list -->
- Just some text.
- A link to [Google](https://google.com).
- An image: ![alt text](image.jpg)
- Both: [link](a) and ![img](b)
```

The `data` for the `my_list` field will be structured like this (omitting `html` for brevity):

```json
[
  {
    "text": "Just some text.",
    "links": [],
    "images": []
  },
  {
    "text": "A link to Google.",
    "links": [
      { "text": "Google", "href": "https://google.com" }
    ],
    "images": []
  },
  {
    "text": "An image:",
    "links": [],
    "images": [
      { "src": "image.jpg", "alt": "alt text" }
    ]
  },
  {
    "text": "Both: link and",
    "links": [
      { "text": "link", "href": "a" }
    ],
    "images": [
      { "src": "b", "alt": "img" }
    ]
  }
]
```


Thats it.



> And for the first time that I really done it  
> Oh, I done it, and it parsed it good.
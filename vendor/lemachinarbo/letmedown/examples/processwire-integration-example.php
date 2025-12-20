<?php namespace ProcessWire;

use ProcessWire\LetMeDown;

// Initialize the processor
$processor = new LetMeDown();

// Load the test markdown file
$content = $processor->load(
  $config->paths->projectContent . 'test-markdown.md',
);

// Read the raw markdown for display
$markdownSource = file_get_contents(
  $config->paths->projectContent . 'test-markdown.md',
);

// Helper function to display text with visible line breaks
function displayText($text, $maxLength = 100)
{
  $truncated = substr($text, 0, $maxLength);
  if (strlen($text) > $maxLength) {
    $truncated .= '...';
  }
  return '<pre class="text-preview">' . htmlspecialchars($truncated) . '</pre>';
}

// Helper function to display HTML
function displayHtml($html, $maxLength = 200)
{
  $truncated = substr($html, 0, $maxLength);
  if (strlen($html) > $maxLength) {
    $truncated .= '...';
  }
  return '<pre class="html-preview">' . htmlspecialchars($truncated) . '</pre>';
}
?>


<style>
    body {
        font-family: monospace;
        margin: 20px;
        background: #f5f5f5;
        font-size: larger;
    }

    .section {
        background: white;
        margin: 20px 0;
        padding: 20px;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }

    .section h2 {
        color: #333;
        border-bottom: 2px solid #007cba;
        padding-bottom: 10px;
    }

    .code {
        background: #f8f8f8;
        padding: 10px;
        border-left: 4px solid #007cba;
        margin: 10px 0;
    }

    pre {
        background: #2d3748;
        color: #e2e8f0;
        padding: 15px;
        border-radius: 4px;
        overflow-x: auto;
    }

    .text-preview {
        font-size: 12px;
        max-height: 150px;
        overflow-y: auto;
        white-space: pre-wrap;
        background: #f8f8f8;
        color: #212529;
        border: 1px solid #dee2e6;
        padding: 10px;
        border-radius: 4px;
    }

    .html-preview {
        font-size: 12px;
        max-height: 150px;
        overflow-y: auto;
        background: #2d3748;
        color: #e2e8f0;
        padding: 15px;
        border-radius: 4px;
        overflow-x: auto;
    }

    .markdown-source {
        background: #f8f9fa;
        border: 1px solid #dee2e6;
    }

    .markdown-source pre {
        background: #f8f9fa;
        color: #212529;
        border: none;
        white-space: pre-wrap;
        font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
    }

    .markdown-source .filename {
        background: #e9ecef;
        padding: 8px 12px;
        border-bottom: 1px solid #dee2e6;
        font-weight: bold;
        color: #495057;
    }

    .success {
        color: #38a169;
        font-weight: bold;
    }
</style>

<h1>MarkdownProcessor API</h1>

<div class="section">
    <h2>TL;DR: How to Access Your Content</h2>
    <p>Your markdown file is parsed into a structured hierarchy of sections, blocks, and elements. Here’s a quick guide on how to access your content:</p>

    <h3>Markdown Structure Example</h3>
    <p>Here’s a minimal example of a markdown file using sections and fields:</p>
    <div class="code">
        <pre>
&lt;!-- section:intro --&gt; // A named 'section'

&lt;!-- title --&gt; // A 'field' for the heading element
# Welcome to our page // A 'block' starts with a heading, this is also a heading 'element'

&lt;!-- summary --&gt; // A 'field' for the paragraph element
This is a summary of the page content. // This is a paragraph 'element'
        </pre>
    </div>

    <h3>1. Semantic Syntax (Recommended)</h3>
    <p>Tag content blocks in your markdown with <code>&lt;!-- fieldname --&gt;</code> markers to access them by name. This is the most reliable method.</p>
    <div class="code">
        <pre>// Get a section by name, then access a field by its tag
$title = $content->sections['hero']->field('title')->text;

// Get the src from an image tagged as 'image'
$imageUrl = $content->sections['hero']->field('image')->src;

// Get items from a list tagged as 'list'
$listItems = $content->sections['content']->field('list')->items;</pre>
    </div>

    <h3>2. Positional Syntax (Cheatsheet)</h3>
    <p>Access content by its numerical order. This is useful for looping but can be brittle if the markdown structure changes.</p>
    <div class="code">
        <pre>$content->sections[0];                // First section
$content->sections[0]->blocks[0];     // First block in the first section
$content->sections[0]->images[0];     // First image in the first section
$content->sections[0]->blocks[0]->children[0]; // First child block
$content->images[0]->src;             // 'src' of the first image in the whole document</pre>
    </div>
</div>

<h1>API guide</h1>

<p>This demo showcases how to use the MarkdownProcessor to parse structured markdown content.</p>

<div class="section">
    <h2>Content Organization</h2>
    <p>The MarkdownProcessor organizes content hierarchically:</p>

    <h3>Structure Overview</h3>
    <pre style="background: #f8f9fa; color: #212529; border: 1px solid #dee2e6; padding: 15px; border-radius: 4px; font-family: monospace;">
Section (&lt;!-- section:name --&gt; or &lt;!-- section --&gt;)
├── Block (created from # ## ### headings)
│   ├── HTML content
│   ├── Text content  
│   ├── Extracted elements (images, links, lists)
│   └── Child Blocks (sub-headings)
└── More Blocks...
    </pre>

    <div class="section">
        <h2>🔍 Simple Markdown Example</h2>
        <p>Here's a minimal markdown snippet that demonstrates the structure:</p>

        <div style="background: #f8f9fa; border: 1px solid #dee2e6; padding: 15px; border-radius: 4px; margin: 15px 0;">
            <pre style="margin: 0; font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace; font-size: 13px; line-height: 1.4;">
&lt;!-- section:example --&gt;
# Main Topic

This is the main content for the topic.

## Sub Topic

Here we dive deeper into the sub topic.

- Point one
- Point two

![Sample image](sample.jpg)

[Learn more](https://example.com)

## Detailed Sub Topic

Even more specific information here.

</pre>
        </div>

        <p><strong>How this maps to the structure:</strong></p>
        <div style="background: #e8f5e8; border: 1px solid #4caf50; padding: 15px; border-radius: 4px; margin: 15px 0;">
            <pre style="margin: 0; font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace; font-size: 13px; line-height: 1.4; color: #e3f2fd;">
Section "example" (&lt;!-- section:example --&gt;)
├── Block "Main Topic" (# Main Topic)
│   ├── HTML content (paragraphs)
│   ├── Text content (readable text)
│   ├── Extracted elements (1 image, 1 link, 1 list)
│   └── Child Blocks
│       ├── Block "Sub Topic" (## Sub Topic)
│       │   ├── HTML content
│       │   ├── Text content
│       │   ├── Extracted elements (list)
│       │   └── Child Blocks
│       │       └── Block "Detailed Sub Topic" (### Detailed Sub Topic)
│       │           ├── HTML content
│       │           └── Text content
└── More Blocks...
</pre>
        </div>
    </div>

    <h3>Key Concepts</h3>
    <ul>
        <li><strong>Sections:</strong> Top-level content containers divided by <code>&lt;!-- section:name --&gt;</code> markers</li>
        <li><strong>Blocks:</strong> Content organized by headings (# ## ###), each block represents a heading + its content</li>
        <li><strong>Children:</strong> Sub-blocks nested under parent blocks (based on heading levels)</li>
        <li><strong>Elements:</strong> Individual content pieces (paragraphs, images, links, lists) extracted from blocks</li>
    </ul>
</div>

<div class="section markdown-source">
    <h2>Extracting content</h2>
    <p>Imaging we have the following markdown file.</p>
    <p>Notice the <code>&lt;!-- section:name --&gt;</code> markers that define sections and <code>&lt;!-- fieldname --&gt;</code> markers that tag specific content:</p>
    <div class="filename">📄 src/site/content/test-markdown.md</div>
    <pre><code><?= htmlspecialchars($markdownSource) ?></code></pre>
</div>

<div class="section">
    <p>To grab the content we have two main options:</p>
    <ul>
        <li><strong>Positional Syntax:</strong> Access sections, blocks, children, and elements by their order/index.</li>
        <li><strong>SEMANTIC SYNTAX:</strong> Access tagged content using field names for a more semantic approach.</li>
    </ul>
</div>
<div class="section">
    <h2>1. POSITIONAL SYNTAX</h2>

    <p>As we saw before there are three main ways blocks of content:</p>
    <ul>
        <li><strong>Sections:</strong> Top-level divisions of content marked by <code>&lt;!-- section:name --&gt;</code></li>
        <li><strong>Blocks:</strong> Subdivisions within sections created by headings (#, ##, ###)</li>
        <li><strong>Children:</strong> Nested blocks under parent blocks based on heading hierarchy</li>
        <li><strong>Elements:</strong> Individual content pieces like paragraphs, images, links, lists within blocks</li>
    </ul>

    <p>Here's how to access them, with positional syntax:</p>

    <h3>Sections</h3>
    <p>You can access any section by index</p>

    <div class="code">
        $section = $content->sections[0]; // Get first section<br>
        $section = $content->sections[1]; // Get second section
    </div>

    <h4>Section Properties</h4>
    <p>each section has three main properties:</p>
    <div class="code">
        $section->title // Section title (string)<br>
        $section->html // Full HTML content (string)<br>
        $section->text // Plain text content (string)
    </div>

    <h4>Section object:</h4>

    <p>You can get all the sections with the section object:</p>
    <div class="code">
        $content->sections
    </div>
    <?php dump($content->sections); ?>

    <p>Get only one specific section:</p>
    <div class="code">
        $content->sections[0]
    </div>
    <?php dump($content->sections[0]); ?>

    <h4>Section html:</h4>
    <p>Get the html of one section:</p>
    <div class="code">
        $content->sections[0]->html
    </div>
    <?= displayHtml($content->sections[0]->html, 300) ?>

    <h4>Section text:</h4>
    <p>Get only the text of that section:</p>
    <div class="code">
        $content->sections[0]->text
    </div>
    <?= displayText($content->sections[0]->text, 300) ?>


    <div style="background: #e3f2fd; padding: 15px; border-radius: 4px; margin-top: 20px;">
        <strong>💡 Tip:</strong> Use positional syntax when you need to iterate through sections in order, or when working with existing code that uses index-based access.
    </div>

    <h3>Blocks</h3>
    <p>Accessing blocks by index</p>

    <div class="code">
        $block = $content->sections[0]->blocks[0]; // Get first block in first section<br>
        $block = $content->sections[0]->blocks[1]; // Get second block in first section<br>
    </div>

    <h4>Block Properties</h4>
    <div class="code">
        $block->heading // Block heading (HeadingElement)<br>
        $block->level // Heading level (int)<br>
        $block->html // Full HTML content (string)<br>
        $block->text // Plain text content (string)
    </div>

    <h4>Block object:</h4>

    <p>Get blocks object in the third section:</p>
    <div class="code">
        $content->sections[2]->blocks
    </div>
    <?php dump($content->sections[2]->blocks); ?>

    <p>Get second block in the third section:</p>
    <div class="code">
        $content->sections[2]->blocks[1]
    </div>
    <?php dump($content->sections[2]->blocks[1]); ?>


    <h4>Block html:</h4>
    <p>Get second block in the third section html:</p>
    <div class="code">
        $content->sections[2]->blocks[1]->html
    </div>
    <?php dump($content->sections[2]->blocks[1]->html); ?>

    <h3>Block text:</h3>
    <p>Get second block in the third section text:</p>
    <div class="code">
        $content->sections[2]->blocks[1]->text
    </div>
    <?php dump($content->sections[2]->blocks[1]->text); ?>


    <div style="background: #e3f2fd; padding: 15px; border-radius: 4px; margin-top: 20px;">
        <strong>💡 Tip:</strong> Use block syntax when you need to access specific content elements directly, or when working with code that uses field-based access.
    </div>

    <h3>Child blocks</h3>
    <p>Accessing child blocks within a parent block</p>

    <div class="code">
        $parentBlock = $content->sections[2]->blocks[0]; // Get parent block<br>
        $childBlock = $parentBlock->children[0]; // Get first child block
    </div>

    <h4>Child Block Properties</h4>
    <div class="code">
        $childBlock->heading // Child block heading (HeadingElement)<br>
        $childBlock->level // Child block heading level (int)<br>
        $childBlock->html // Child block HTML content (string)<br>
        $childBlock->text // Child block plain text content (string)
    </div>

    <h4>Child object:</h4>

    <p>Get the child blocks of the first block of the second section:</p>
    <div class="code">
        $content->sections[2]->blocks[0]->children
    </div>
    <?php dump($content->sections[2]->blocks[0]->children); ?>

    <p>Get first child block:</p>
    <div class="code">
        $content->sections[2]->blocks[0]->children[0]
    </div>
    <?php dump($content->sections[2]->blocks[0]->children[0]); ?>

    <h4>Child block html:</h4>
    <p>Get first child block html:</p>
    <div class="code">
        $content
    </div>
    <?= displayHtml($content->sections[2]->blocks[0]->children[0]->html, 300) ?>

    <h4>Child block text:</h4>
    <p>Get first child block text:</p>
    <div class="code">
        $content->sections[2]->blocks[0]->children[0]->text
    </div>
    <?= displayText($content->sections[2]->blocks[0]->children[0]->text, 300) ?>

    <div style="background: #e3f2fd; padding: 15px; border-radius: 4px; margin-top: 20px;">
        <strong>💡 Tip:</strong> Child blocks are nested under parent blocks based on heading levels (e.g., ## under #, ### under ##).
    </div>

    <h3>Elements</h3>
    <p>Accessing individual content elements within blocks</p>

    <div class="code">
        $block = $content->sections[2]->blocks[1]; // Get section 2 block 1 (has more content)<br>
        $images = $block->images; // Get all images<br>
        $paragraphs = $block->paragraphs; // Get all paragraphs<br>
        $links = $block->links; // Get all links<br>
        $lists = $block->lists; // Get all lists
    </div>

    <h3>Element Properties</h3>
    <div class="code">
        $headings->text // Heading text content<br>
        $headings->html // Heading HTML content<br>
        $paragraph->text // Paragraph text content<br>
        $paragraph->html // Paragraph HTML content<br>
        $link->text // Link text<br>
        $link->href // Link URL<br>
        $list->items // Array of list items
        $images // Array of images
    </div>

    <h4>Heading Elements:</h4>

    <p>Get all headings:</p>
    <div class="code">
        $content->headings
    </div>
    <?php dump($content->headings); ?>

    <p>Get all headings in a block:</p>
    <div class="code">
        $content->sections[2]->blocks[0]->headings
    </div>
    <?php dump($content->sections[2]->blocks[0]->headings); ?>

    <h4>Paragraph Elements:</h4>

    <p>Get all paragraphs:</p>
    <div class="code">
        $content->paragraphs
    </div>
    <?php dump($content->paragraphs); ?>

    <p>Get a paragraph in a block:</p>
    <div class="code">
        $content->sections[2]->blocks[0]->paragraphs[0]
    </div>
    <?php dump($content->sections[2]->blocks[1]->paragraphs[0]); ?>

    <p>Get html of a paragraph in a block:</p>
    <div class="code">
        $content->sections[2]->blocks[0]->paragraphs[0]->html
    </div>
    <?= displayHtml(
      $content->sections[2]->blocks[1]->paragraphs[0]->html,
      200,
    ) ?>

    <p>Get text of a paragraph in a block:</p>
    <div class="code">
        $content->sections[2]->blocks[0]->paragraphs[0]->text
    </div>
    <?= displayText(
      $content->sections[2]->blocks[1]->paragraphs[0]->text,
      200,
    ) ?>


    <h4>Link Elements:</h4>

    <p>Get all links:</p>
    <div class="code">
        $content->links
    </div>
    <?php dump($content->links); ?>

    <p>Get a link in a block:</p>
    <div class="code">
        $content->sections[2]->blocks[0]->links[0]
    </div>
    <?php dump($content->sections[2]->blocks[1]->links[0]); ?>

    <p>Get HTML of a link in a block:</p>
    <div class="code">
        $content->sections[2]->blocks[0]->links[0]->html
    </div>
    <?= displayHtml(
      $content->sections[2]->blocks[1]->links[0]->html ?? '',
      200,
    ) ?>

    <p>Get text and href of a link in a block:</p>
    <div class="code">
        $content->sections[2]->blocks[0]->links[0]->text<br>
        $content->sections[2]->blocks[0]->links[0]->href
    </div>
    <?= displayText(
      $content->sections[2]->blocks[1]->links[0]->text ?? '',
      200,
    ) ?>
    <?= displayText(
      $content->sections[2]->blocks[1]->links[0]->href ?? '',
      200,
    ) ?>

    <h4>List Elements:</h4>

    <p>Get all lists in the document:</p>
    <div class="code">
        $content->lists
    </div>
    <?php dump($content->lists); ?>

    <p>Get a list in a block:</p>
    <div class="code">
        $content->sections[2]->blocks[1]->lists[0]
    </div>
    <?php dump($content->sections[2]->blocks[1]->lists[0]); ?>

    <p>Get items of the list:</p>
    <div class="code">
        $content->sections[2]->blocks[1]->lists[0]->items
    </div>
    
    <?php dump($content->sections[2]->blocks[1]->lists[0]->items); ?>


    <p>Get HTML of the list in a block:</p>
    <div class="code">
        $content->sections[2]->blocks[1]->lists[0]->html
    </div>
    <?= displayHtml(
      $content->sections[2]->blocks[1]->lists[0]->html ?? '',
      300,
    ) ?>

    <p>Get a textual preview of the list items:</p>
    <div class="code">
        $content->sections[2]->blocks[1]->lists[0]->items[0]
    </div>
    <?= displayText(
      $content->sections[2]->blocks[1]->lists[0]->items[0],
      300,
    ) ?>


    <h4>Image Elements (attributes)</h4>

    <p>Get all images in the document:</p>
    <div class="code">
        $content->images
    </div>
    <?php dump($content->images); ?>

    <p>Get an image in a block:</p>
    <div class="code">
        $content->sections[1]->images[0]
    </div>
    <?php dump($content->sections[1]->images[0]); ?>

    <p>Get common image attributes:</p>
    <div class="code">
        $img = $content->sections[1]->images[0];<br>
        $img->src // Image URL<br>
        $img->alt // Alt text<br>
        $img->html // Full &lt;img&gt; HTML
    </div>

    <?php $img = $content->sections[1]->images[0]; ?>

    <p><strong>src:</strong></p>
    <?= displayText($img?->src ?? '', 200) ?>

    <p><strong>alt:</strong></p>
    <?= displayText($img?->alt ?? '', 200) ?>

    <p><strong>html:</strong></p>
    <?= displayHtml($img?->html ?? '', 300) ?>

</div>

<div class="section">
    <h2>2. SEMANTIC SYNTAX</h2>
    <p>Positional syntax sounds cool until you realize that the moment you add a new element, all the existing elements shift down, and you have to update all the positions manually. How nice.</p>
    <p>Thats why we have the semantic syntax, which allows you to tag specific content with markers (fields), so you can access any element directly by its field name.</p>
    <p>To use it you just need to add <code>&lt;!-- fieldname --&gt;</code> markers before any element you want to tag.</p>
    <div style="background: #f8f9fa; border: 1px solid #dee2e6; padding: 15px; border-radius: 4px; margin: 15px 0;">
        <pre style="margin: 0; font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace; font-size: 13px; line-height: 1.4;">
&lt;!-- section:example --&gt; // You can define sections without name, but trust me, a name helps

&lt;!-- mytitle --&gt; // here you are creating a field to get the title
# Main Topic

&lt;!-- thetext --&gt; // you can add fields, or not, to any element 
This is the main content for the topic.

&lt;!-- section:foo --&gt; 

&lt;!-- mytitle --&gt; // This field belongs to section foo
# Another Topic
Foo

</pre>
    </div>

    <h3>Sections</h3>
    <p>You can get sections by name:</p>
    <div class="code">
        $content->sections['hero']
    </div>
    <?php dump($content->sections['hero']); ?>

    <h3>Elements</h3>
    <p>You can get any element by its field name:</p>
    <div class="code">
        $content->sections['hero']->field('title')
    </div>
    <?php dump($content->sections['hero']->field('title')); ?>
    <div class="code">
        $content->sections['hero']->field('subtitle')->text
    </div>
    <?php dump($content->sections['hero']->field('subtitle')->text); ?>
    <?= displayText(
      $content->sections['hero']->field('subtitle')->text ?? '',
      300,
    ) ?>

    <div class="code">
        $content->sections['content']->field('list')->html
    </div>
    <?= displayHtml(
      $content->sections['content']->field('list')->html ?? '',
      300,
    ) ?>

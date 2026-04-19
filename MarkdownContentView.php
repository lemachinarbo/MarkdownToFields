<?php

namespace ProcessWire;

use LetMeDown\ContentData;
use LetMeDown\FieldContainer;
use LetMeDown\FieldData;
use LetMeDown\Section;

interface MarkdownContentViewNode
{
  /**
   * Returns the canonical area path string for this node.
   * Used for identifying targets in front-end editing integrations.
   * 
   * @return string
   */
  public function area(): string;

  /**
   * Returns a WireData wrapper for fluent data access. 
   * Accepts 'html' or 'text' to automatically collapse simple fields 
   * to their respective string values.
   */
  public function dataSet(?string $mode = null): MarkdownDataSet;

  /**
   * Returns the node data as a plain associative array.
   */
  public function data(): array;
}

class MarkdownContentView extends ContentData implements MarkdownContentViewNode
{
  protected Page $page;
  protected string $nodeArea;

  public function __construct(Page $page, ContentData $content, string $area = '')
  {
    $this->page = $page;
    $this->nodeArea = $area;

    $wrappedSectionsByName = [];
    foreach ($content->sectionsByName as $name => $section) {
      $wrappedSectionsByName[$name] = MarkdownSectionView::fromRaw($page, $section, (string) $name, (string) $name);
    }

    $wrappedSections = [];
    foreach ($content->sections as $section) {
      $found = null;
      foreach ($content->sectionsByName as $name => $namedSection) {
        if ($namedSection === $section) {
          $found = $wrappedSectionsByName[$name] ?? null;
          break;
        }
      }

      if ($found instanceof Section) {
        $wrappedSections[] = $found;
        continue;
      }

      $wrappedSections[] = MarkdownSectionView::fromRaw($page, $section, null, '');
    }

    parent::__construct([
      'text' => $content->text,
      'html' => $content->html,
      'sections' => $wrappedSections,
      'sectionsByName' => $wrappedSectionsByName,
      'markdown' => $content->markdown,
      'frontmatter' => $content->getFrontmatter(),
      'frontmatterRaw' => $content->getFrontmatterRaw(),
    ]);
  }

  /**
   * Returns the canonical area path string for this node.
   */
  public function area(): string
  {
    return $this->nodeArea;
  }

  /**
   * Returns the node data as a plain associative array.
   */
  public function data(): array
  {
    $data = $this->getFrontmatter() ?: [];
    if (!is_array($data)) {
      $data = ['_frontmatter' => $data];
    }
    return MarkdownNodeData::adaptData($this->page, $this, $data, $this->nodeArea);
  }

  /**
   * Returns a WireData wrapper for fluent data access. 
   * Accepts 'html' or 'text' to automatically collapse simple fields 
   * to their respective string values.
   */
  public function dataSet(?string $mode = null): MarkdownDataSet
  {
    $dataSet = new MarkdownDataSet($this->data());
    return $dataSet->project($mode);
  }
}

class MarkdownSectionView extends Section implements MarkdownContentViewNode
{
  protected Page $page;
  protected string $nodeArea;

  public static function fromRaw(Page $page, Section $section, ?string $key = null, string $area = ''): self
  {
    $wrappedFields = [];
    foreach ($section->fields as $name => $field) {
      $fieldArea = self::joinArea($area, (string) $name);
      if ($field instanceof FieldData) {
        $wrappedFields[$name] = MarkdownFieldDataView::fromRaw($page, $field, (string) $name, $fieldArea);
      } elseif ($field instanceof FieldContainer) {
        $wrappedFields[$name] = MarkdownFieldContainerView::fromRaw($page, $field, (string) $name, $fieldArea);
      } else {
        $wrappedFields[$name] = $field;
      }
    }

    $wrappedSubsections = [];
    foreach ($section->subsections as $name => $subsection) {
      $wrappedSubsections[$name] = self::fromRaw($page, $subsection, (string) $name, self::joinArea($area, (string) $name));
    }

    $wrappedBlocks = [];
    foreach ($section->getRealBlocks() as $index => $block) {
      $blockArea = self::joinArea($area, "block_" . $index);
      $wrappedBlocks[] = MarkdownBlockView::fromRaw($page, $block, $blockArea);
    }

    return new self(
      $page,
      $section->html,
      $section->text,
      $section->markdown,
      $wrappedBlocks,
      $wrappedFields,
      $wrappedSubsections,
      $key,
      $area
    );
  }

  public function __construct(
    Page $page,
    string $html,
    string $text,
    string $markdown,
    array $blocks,
    array $fields = [],
    array $subsections = [],
    ?string $key = null,
    string $area = ''
  ) {
    $this->page = $page;
    $this->nodeArea = $area;
    parent::__construct($html, $text, $markdown, $blocks, $fields, $subsections, $key);
  }

  /**
   * Returns the canonical area path string for this node.
   */
  public function area(): string
  {
    return $this->nodeArea;
  }

  /**
   * Returns the node data as a plain associative array.
   */
  public function data(): array
  {
    $data = $this->frontmatter ?: [];
    if (!is_array($data)) {
      $data = ['_frontmatter' => $data];
    }

    $data = array_merge([
      'html' => $this->html,
      'text' => $this->text,
      'markdown' => $this->markdown,
    ], $data);

    return MarkdownNodeData::adaptData($this->page, $this, $data, $this->nodeArea);
  }

  /**
   * Returns a WireData wrapper for fluent data access. 
   * Accepts 'html' or 'text' to automatically collapse simple fields 
   * to their respective string values.
   */
  public function dataSet(?string $mode = null): MarkdownDataSet
  {
    $dataSet = new MarkdownDataSet($this->data());
    return $dataSet->project($mode);
  }

  protected static function joinArea(string $base, string $segment): string
  {
    $segment = trim($segment, '/');
    if ($segment === '') {
      return $base;
    }
    if ($base === '') {
      return $segment;
    }
    return $base . '/' . $segment;
  }
}

class MarkdownBlockView extends \LetMeDown\Block implements MarkdownContentViewNode
{
  protected Page $page;
  protected string $nodeArea;

  public static function fromRaw(Page $page, \LetMeDown\Block $block, string $area = ''): self
  {
    return new self($page, $block, $area);
  }

  public function __construct(Page $page, \LetMeDown\Block $block, string $area = '')
  {
    $this->page = $page;
    $this->nodeArea = $area;

    parent::__construct(
      $block->heading,
      $block->level,
      $block->content,
      $block->html,
      $block->text,
      $block->markdown,
      $block->paragraphs,
      $block->images,
      $block->links,
      $block->lists,
      $block->children,
      $block->fields
    );
  }

  /**
   * Returns the canonical area path string for this node.
   */
  public function area(): string
  {
    return $this->nodeArea;
  }

  public function data(): array
  {
    $data = $this->fields ?: [];
    if (!is_array($data)) {
      $data = ['_fields' => $data];
    }

    $data = array_merge([
      'html' => $this->html,
      'text' => $this->text,
      'markdown' => $this->markdown,
    ], $data);

    return MarkdownNodeData::adaptData($this->page, $this, $data, $this->nodeArea);
  }

  /**
   * Returns a WireData wrapper for fluent data access. 
   * Accepts 'html' or 'text' to automatically collapse simple fields 
   * to their respective string values.
   */
  public function dataSet(?string $mode = null): MarkdownDataSet
  {
    $dataSet = new MarkdownDataSet($this->data());
    return $dataSet->project($mode);
  }
}

class MarkdownFieldDataView extends FieldData implements MarkdownContentViewNode
{
  protected Page $page;
  protected string $nodeArea;

  public static function fromRaw(Page $page, FieldData $field, ?string $key = null, string $area = ''): self
  {
    return new self(
      $page,
      $field->name,
      $field->markdown,
      $field->html,
      $field->text,
      $field->type,
      $field->data,
      $key,
      $area
    );
  }

  public function __construct(
    Page $page,
    string $name,
    string $markdown,
    string $html,
    string $text,
    string $type,
    array $data = [],
    ?string $key = null,
    string $area = ''
  ) {
    $this->page = $page;
    $this->nodeArea = $area;
    parent::__construct($name, $markdown, $html, $text, $type, $data, $key);
  }

  /**
   * Returns the canonical area path string for this node.
   */
  public function area(): string
  {
    return $this->nodeArea;
  }

  public function data(): array
  {
    $data = $this->data ?: [];
    if (!is_array($data)) {
      $data = ['_value' => $data];
    }

    $data = array_merge([
      'html' => $this->html,
      'text' => $this->text,
      'markdown' => $this->markdown,
    ], $data);

    return MarkdownNodeData::adaptData($this->page, $this, $data, $this->nodeArea);
  }

  /**
   * Returns a WireData wrapper for fluent data access. 
   * Accepts 'html' or 'text' to automatically collapse simple fields 
   * to their respective string values.
   */
  public function dataSet(?string $mode = null): MarkdownDataSet
  {
    $dataSet = new MarkdownDataSet($this->data());
    return $dataSet->project($mode);
  }
}

class MarkdownFieldContainerView extends FieldContainer implements MarkdownContentViewNode
{
  protected Page $page;
  protected string $nodeArea;

  public static function fromRaw(Page $page, FieldContainer $field, ?string $key = null, string $area = ''): self
  {
    return new self(
      $page,
      $field->name,
      $field->markdown,
      $field->html,
      $field->text,
      $field->blocks,
      $key,
      $area
    );
  }

  public function __construct(
    Page $page,
    string $name,
    string $markdown,
    string $html,
    string $text,
    array $blocks,
    ?string $key = null,
    string $area = ''
  ) {
    $this->page = $page;
    $this->nodeArea = $area;
    parent::__construct($name, $markdown, $html, $text, $blocks, $key);
  }

  /**
   * Returns the canonical area path string for this node.
   */
  public function area(): string
  {
    return $this->nodeArea;
  }

  /**
   * Returns the node data as a plain associative array.
   */
  public function data(): array
  {
    $data = [
      'html' => $this->html,
      'text' => $this->text,
      'markdown' => $this->markdown,
    ];
    return MarkdownNodeData::adaptData($this->page, $this, $data, $this->nodeArea);
  }

  /**
   * Returns a WireData wrapper for fluent data access. 
   * Accepts 'html' or 'text' to automatically collapse simple fields 
   * to their respective string values.
   */
  public function dataSet(?string $mode = null): MarkdownDataSet
  {
    $dataSet = new MarkdownDataSet($this->data());
    return $dataSet->project($mode);
  }

  private function joinArea(string $base, string $segment): string
  {
    $segment = trim($segment, '/');
    if ($segment === '') {
      return $base;
    }
    if ($base === '') {
      return $segment;
    }
    return $base . '/' . $segment;
  }
}

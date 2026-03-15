<?php

namespace ProcessWire;

use LetMeDown\ContentData;
use LetMeDown\FieldContainer;
use LetMeDown\FieldData;
use LetMeDown\Section;

interface MarkdownContentViewNode
{
  public function area(): string;
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

  public function area(): string
  {
    return $this->nodeArea;
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

    return new self(
      $page,
      $section->html,
      $section->text,
      $section->markdown,
      $section->getRealBlocks(),
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

  public function area(): string
  {
    return $this->nodeArea;
  }

  public function data(): array
  {
    return MarkdownNodeData::adaptData($this->page, $this, parent::data(), $this->nodeArea);
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

  public function area(): string
  {
    return $this->nodeArea;
  }

  public function data()
  {
    return MarkdownNodeData::adaptData($this->page, $this, parent::data(), $this->nodeArea);
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

  public function area(): string
  {
    return $this->nodeArea;
  }

  public function data(): array
  {
    return MarkdownNodeData::adaptData($this->page, $this, parent::data(), $this->nodeArea);
  }
}

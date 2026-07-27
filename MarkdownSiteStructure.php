<?php

namespace ProcessWire;

/**
 * Top-level site navigation as data, markdown-first: if the content
 * directory has a structure file (default site.md, configurable) with a
 * named nav section, its links are the nav. Otherwise the nav is derived
 * from the page tree — home plus its markdown-backed viewable children,
 * labeled by each page's own frontmatter title when present.
 *
 * The nav section's first heading, when present, is the site brand
 * (`# [Acme](/)`) — see brand().
 *
 * Returns plain items: [['label' => ..., 'url' => ...], ...]. URLs are
 * regular page URLs; the reader view converts them to .md hrefs itself.
 */
class MarkdownSiteStructure
{
  public static function nav(Page $context, MarkdownToFieldsLenses $module): array
  {
    $items = self::fromFile($context, $module);
    return $items !== null ? $items : self::fromTree($context, $module);
  }

  /**
   * The brand from the structure file's nav section: its first heading,
   * e.g. `# [Acme](/)`. Heading links stay out of the section's link
   * collection, so the brand never doubles as a nav item. A heading
   * without a link keeps the label and points to home. Returns
   * ['label' => ..., 'url' => ...], or null when the file, section or
   * heading is absent — callers fall back to their own default.
   */
  public static function brand(Page $context, MarkdownToFieldsLenses $module): ?array
  {
    $section = self::fileNavSection($context, $module);
    $heading = $section?->headings[0] ?? null;
    if ($heading === null || trim($heading->text) === '') return null;

    $url = $heading->href ?? $context->wire()->pages->get('/')->url;

    return [
      'label' => trim($heading->text),
      'url' => self::absoluteUrl($context, $url),
    ];
  }

  /** Nav from the structure file's own nav section, or null if absent. */
  protected static function fromFile(Page $context, MarkdownToFieldsLenses $module): ?array
  {
    $section = self::fileNavSection($context, $module);
    if ($section === null) return null;

    $items = [];
    foreach ($section->links as $link) {
      $items[] = [
        'label' => (string) $link->text,
        'url' => self::absoluteUrl($context, (string) $link->href),
      ];
    }

    return $items;
  }

  /** The structure file's nav section, or null when file/section is absent. */
  protected static function fileNavSection(Page $context, MarkdownToFieldsLenses $module): ?\LetMeDown\Section
  {
    $home = $context->wire()->pages->get('/');
    if (!MarkdownConfig::supportsPage($home)) return null;

    $fileName = $module->structureFileName();

    try {
      $path = MarkdownFileIO::getMarkdownFilePath($home, null, $fileName);
    } catch (\Throwable $e) {
      // Invalid configured name; fall back to the derived nav.
      return null;
    }

    if (!is_file($path)) return null;

    // loadFromString, not load(): load() without a basePath rejects file
    // paths by design (LetMeDown's traversal protection).
    $content = (new \LetMeDown\LetMeDown())->loadFromString((string) file_get_contents($path));
    return $content->sectionsByName[$module->navSectionName()] ?? null;
  }

  /** Root-relative structure-file paths become absolute site URLs. */
  protected static function absoluteUrl(Page $context, string $url): string
  {
    if (!str_starts_with($url, '/')) return $url;
    return rtrim($context->wire()->urls->httpRoot, '/') . $url;
  }

  /** Nav derived from the page tree: home plus its servable children. */
  protected static function fromTree(Page $context, MarkdownToFieldsLenses $module): array
  {
    $home = $context->wire()->pages->get('/');
    $items = [];

    foreach ($home->and($home->children()) as $page) {
      if (!$module->servable($page)) continue;
      $frontmatter = $page->content()->frontmatter ?? [];
      $items[] = [
        'label' => (string) ($frontmatter['title'] ?? $page->title),
        'url' => $page->httpUrl,
      ];
    }

    return $items;
  }
}

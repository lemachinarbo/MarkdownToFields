<?php

namespace ProcessWire;

/**
 * Top-level site navigation as data, markdown-first: if the content
 * directory has a structure file (default site.md, configurable) with a
 * named nav section, its links are the nav. Otherwise the nav is derived
 * from the page tree — home plus its markdown-backed viewable children,
 * labeled by each page's own frontmatter title when present.
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

  /** Nav from the structure file's own nav section, or null if absent. */
  protected static function fromFile(Page $context, MarkdownToFieldsLenses $module): ?array
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
    $section = $content->sectionsByName['nav'] ?? null;
    if ($section === null) return null;

    $httpRoot = $context->wire()->urls->httpRoot;
    $items = [];
    foreach ($section->links as $link) {
      $url = (string) $link->href;
      if (str_starts_with($url, '/')) {
        $url = rtrim($httpRoot, '/') . $url;
      }
      $items[] = ['label' => (string) $link->text, 'url' => $url];
    }

    return $items;
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

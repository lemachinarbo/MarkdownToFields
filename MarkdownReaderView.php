<?php

namespace ProcessWire;

/**
 * The reader view: a low-noise, self-contained HTML page for humans who
 * land on a .md URL with a browser. Renders through a slot model so
 * projects can restyle or replace any part without forking the whole
 * template:
 *
 * - hook MarkdownReaderView::slots to change nav, brand, footer, title...
 * - drop site/templates/markdown-reader.php to replace the markup/theme
 *   entirely (it receives the slots as extracted variables)
 *
 * The view renders plain PHP only. No template engine is ever involved —
 * it must work on any ProcessWire site regardless of rendering stack.
 */
class MarkdownReaderView extends Wire
{
  public const TEMPLATE_NAME = 'markdown-reader.php';

  /**
   * The named parts the template receives. Hook after this to change any
   * of them (swap nav items, add a logo to brandHtml, adjust the title).
   */
  public function ___slots(Page $page, string $contentHtml): array
  {
    $module = $this->wire()->modules->get('MarkdownToFieldsLenses');
    $meta = $module->frontmatterFor($page);
    $home = $this->wire()->pages->get('/');

    $nav = [];
    foreach (MarkdownSiteStructure::nav($page, $module) as $item) {
      $nav[] = ['label' => $item['label'], 'href' => self::readerHref($page, $item['url'])];
    }

    $brand = MarkdownSiteStructure::brand($page, $module)
      ?? ['label' => (string) $home->title, 'url' => $home->url];

    return [
      'lang' => MarkdownLanguageResolver::getLanguageCode($page),
      'title' => $meta['title'],
      'canonicalUrl' => $page->httpUrl,
      'rawMarkdownUrl' => $page->httpUrl . '?format=md',
      'brandHtml' => '<a href="' . self::readerHref($page, $brand['url']) . '">'
        . $this->wire()->sanitizer->entities($brand['label']) . '</a>',
      'nav' => $nav,
      'contentHtml' => $contentHtml,
      'footerHtml' => '<a href="' . $page->httpUrl . '">Full site</a> · '
        . '<a href="' . $page->httpUrl . '?format=md">Markdown</a>',
    ];
  }

  public function ___render(Page $page, string $contentHtml): string
  {
    $config = $this->wire()->config;
    $file = $config->paths->templates . self::TEMPLATE_NAME;
    if (!is_file($file)) {
      $file = __DIR__ . '/' . self::TEMPLATE_NAME;
    }

    // Plain buffered include, not $files->render(): the module directory
    // may be a symlink resolving outside the ProcessWire root (dev setups),
    // which fails WireFileTools' allowed-paths check.
    extract($this->slots($page, $contentHtml));
    ob_start();
    include $file;
    return ob_get_clean();
  }

  /**
   * A page URL or root-relative path on this site -> its reader (.md)
   * counterpart, preserving query string and fragment. The homepage has
   * no path segment to hang .md off, so it maps to the configured
   * homepage name (default /home.md).
   */
  public static function readerHref(Page $context, string $urlOrPath): string
  {
    $httpRoot = $context->wire()->urls->httpRoot;
    $module = $context->wire()->modules->get('MarkdownToFieldsLenses');
    $parts = parse_url($urlOrPath);
    $path = rtrim($parts['path'] ?? '/', '/');
    $suffix = (isset($parts['query']) ? '?' . $parts['query'] : '')
      . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');

    // Strip a subdirectory-install root prefix so the .md suffix lands on
    // the page path, then rebuild against httpRoot.
    $rootPath = rtrim(parse_url($httpRoot, PHP_URL_PATH) ?? '/', '/');
    if ($rootPath !== '' && str_starts_with($path, $rootPath)) {
      $path = substr($path, strlen($rootPath));
    }

    $href = $path === ''
      ? $httpRoot . $module->homepageName() . '.md'
      : $httpRoot . ltrim($path, '/') . '.md';

    return $href . $suffix;
  }

  /**
   * Keeps navigation inside the reader: rewrites internal page links in
   * rendered content to their .md counterparts, so clicking a link stays
   * in reader mode instead of dropping into the full HTML site. Asset
   * links and already-.md links pass through untouched. Only the reader
   * uses this — raw markdown keeps real page URLs, agents expect them.
   */
  public static function rewriteLinks(Page $context, string $html): string
  {
    $httpRoot = $context->wire()->urls->httpRoot;
    $assetsUrl = $context->wire()->config->urls->assets;

    $rewritten = preg_replace_callback(
      '/(<a\b[^>]*\bhref\s*=\s*)([\'"])(.*?)\2/i',
      function (array $match) use ($context, $httpRoot, $assetsUrl): string {
        [$full, $prefix, $quote, $href] = $match;

        if (
          !str_starts_with($href, $httpRoot)
          || str_contains($href, $assetsUrl)
          || str_contains($href, '.md')
        ) {
          return $full;
        }

        return $prefix . $quote . self::readerHref($context, $href) . $quote;
      },
      $html,
    );

    return $rewritten ?? $html;
  }
}

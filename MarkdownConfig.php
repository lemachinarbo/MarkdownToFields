<?php

namespace ProcessWire;

class MarkdownConfig extends MarkdownLanguageResolver
{
  protected static array $configCache = [];

  /** Checks whether markdown sync is configured for the page. */
  public static function supportsPage(Page $page): bool
  {
    if (self::isAdminPage($page)) {
      return false;
    }

    return self::config($page) !== null;
  }

  protected static function config(Page $page): ?array
  {
    $pageId = (int) $page->id;
    if ($pageId > 0 && array_key_exists($pageId, self::$configCache)) {
      return self::$configCache[$pageId];
    }

    if (!method_exists($page, 'getMarkdownSyncMap')) {
      if ($pageId > 0) self::$configCache[$pageId] = null;
      return null;
    }

    $map = $page->getMarkdownSyncMap();
    if (!is_array($map)) {
      if ($pageId > 0) self::$configCache[$pageId] = null;
      return null;
    }

    $source =
      isset($map['source']) && is_array($map['source']) ? $map['source'] : [];

    $path = trim((string) ($source['path'] ?? ''));
    $markdownField = trim((string) ($map['markdownField'] ?? ''));

    if ($path === '' || $markdownField === '') {
      if ($pageId > 0) self::$configCache[$pageId] = null;
      return null;
    }

    $config = [
      'source' => [
        'path' => self::withTrailingSlash($path),
        'pageField' => self::normalizeFieldName($source['pageField'] ?? null),
        'fallback' => (string) ($source['fallback'] ?? ''),
      ],
      'markdownField' => $markdownField,
      'hashField' => self::normalizeFieldName($map['hashField'] ?? null),
      'frontmatter' => self::normalizeFrontmatter($map['frontmatter'] ?? []),
      'imageBaseUrl' => self::normalizeUrlBase($map['imageBaseUrl'] ?? null),
      'imageSourcePaths' => self::normalizeSourcePaths($map['imageSourcePaths'] ?? null),
    ];

    if ($pageId > 0) {
      self::$configCache[$pageId] = $config;
    }

    return $config;
  }

  public static function isLinkSyncEnabled(Page $page): bool
  {
    $siteConfig = $page->wire('config')->MarkdownToFields ?? [];
    if (array_key_exists('linkSync', $siteConfig)) {
      return (bool) $siteConfig['linkSync'];
    }

    $moduleConfig = $page->wire('modules')->getConfig('MarkdownToFields') ?? [];
    return (bool) ($moduleConfig['linkSync'] ?? false);
  }

  protected static function requireConfig(Page $page): array
  {
    $config = self::config($page);
    if ($config === null) {
      throw new WireException(
        sprintf('Markdown sync is not configured for page %s.', $page->path),
      );
    }

    return $config;
  }

  protected static function normalizeFieldName($field): ?string
  {
    if (!is_string($field)) {
      return null;
    }

    $name = trim($field);
    return $name === '' ? null : $name;
  }

  protected static function normalizeUrlBase($value): ?string
  {
    if (!is_string($value)) {
      return null;
    }

    $url = trim($value);
    if ($url === '') {
      return null;
    }

    return rtrim($url, '/') . '/';
  }

  protected static function normalizeSourcePaths($paths): array
  {
    if (!is_array($paths)) {
      if (!is_string($paths)) {
        return [];
      }

      $paths = [$paths];
    }

    static $cache = [];
    $cacheKey = serialize($paths);
    if (isset($cache[$cacheKey])) {
      return $cache[$cacheKey];
    }

    $config = wire('config');
    $normalized = [];

    foreach ($paths as $path) {
      $p = trim((string) $path);
      if ($p === '') {
        continue;
      }

      // Convert relative paths to absolute using site root when possible
      if ($p[0] !== '/' && $config && isset($config->paths->site)) {
        $p = $config->paths->site . ltrim($p, '/');
      }

      $normalized[] = self::withTrailingSlash($p);
    }

    $result = array_values(array_unique($normalized));
    $cache[$cacheKey] = $result;
    return $result;
  }

  protected static function normalizeFrontmatter($frontmatter): array
  {
    static $cache = [];

    if (is_string($frontmatter)) {
      if (isset($cache[$frontmatter])) {
        return $cache[$frontmatter];
      }

      $rawString = $frontmatter;
      $lines = explode("\n", $frontmatter);
      $frontmatter = [];
      foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, ':') === false) continue;
        [$field, $key] = explode(':', $line, 2);
        $frontmatter[trim($field)] = trim($key);
      }
    } else {
      $rawString = null;
    }

    if (!is_array($frontmatter)) {
      return [];
    }

    $cacheKey = $rawString ?? serialize($frontmatter);
    if (isset($cache[$cacheKey])) {
      return $cache[$cacheKey];
    }

    $normalized = [];
    foreach ($frontmatter as $field => $key) {
      $fieldName = trim((string) $field);
      $frontKey = trim((string) $key);

      if ($fieldName === '' || $frontKey === '') {
        continue;
      }

      $normalized[$fieldName] = $frontKey;
    }

    $cache[$cacheKey] = $normalized;
    return $normalized;
  }

  protected static function markdownFieldName(
    Page $page,
    ?string $fieldName,
  ): string {
    $candidate = trim((string) ($fieldName ?? ''));
    if ($candidate !== '') {
      return $candidate;
    }

    $config = self::requireConfig($page);
    return $config['markdownField'];
  }

  protected static function hashFieldName(
    Page $page,
    ?string $fieldName,
  ): string {
    $candidate = trim((string) ($fieldName ?? ''));
    if ($candidate !== '') {
      return $candidate;
    }

    $config = self::requireConfig($page);
    $hashField = $config['hashField'];

    return $hashField ?? $config['markdownField'];
  }

  /** Returns the configured markdown field name for the page. */
  public static function getMarkdownField(Page $page): ?string
  {
    $config = self::config($page);
    return $config['markdownField'] ?? null;
  }

  /** Returns the configured hash field name for the page. */
  public static function getHashField(Page $page): ?string
  {
    $config = self::config($page);
    return $config['hashField'] ?? null;
  }

  /** Returns the frontmatter mapping defined for the page. */
  public static function getFrontmatterMap(Page $page): array
  {
    $config = self::config($page);
    return $config['frontmatter'] ?? [];
  }

  /** Returns the image base URL prefix defined for the page. */
  public static function getImageBaseUrl(Page $page): ?string
  {
    $config = self::config($page);
    return $config['imageBaseUrl'] ?? null;
  }

  /** Returns the configured image source paths for the page. */
  public static function getImageSourcePaths(Page $page): array
  {
    $config = self::config($page);
    return $config['imageSourcePaths'] ?? [];
  }

  protected static function fieldMap(Page $page): array
  {
    $config = self::config($page);
    return $config['frontmatter'] ?? [];
  }
}

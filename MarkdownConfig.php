<?php

namespace ProcessWire;

class MarkdownConfig extends MarkdownLanguageResolver
{
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
    if (!method_exists($page, 'getMarkdownSyncMap')) {
      return null;
    }

    $map = $page->getMarkdownSyncMap();
    if (!is_array($map)) {
      return null;
    }

    $source =
      isset($map['source']) && is_array($map['source']) ? $map['source'] : [];
    $assets =
      isset($map['assets']) && is_array($map['assets']) ? $map['assets'] : [];

    $path = trim((string) ($source['path'] ?? ''));
    $markdownField = trim((string) ($map['markdownField'] ?? ''));

    if ($path === '' || $markdownField === '') {
      return null;
    }

    return [
      'source' => [
        'path' => self::withTrailingSlash($path),
        'pageField' => self::normalizeFieldName($source['pageField'] ?? null),
        'fallback' => (string) ($source['fallback'] ?? ''),
      ],
      'markdownField' => $markdownField,
      'htmlField' => self::normalizeFieldName($map['htmlField'] ?? null),
      'hashField' => self::normalizeFieldName($map['hashField'] ?? null),
      'frontmatter' => self::normalizeFrontmatter($map['frontmatter'] ?? []),
      'assets' => [
        'imageBaseUrl' => self::normalizeUrlBase(
          $assets['imageBaseUrl'] ?? null,
        ),
      ],
    ];
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

  protected static function normalizeFrontmatter($frontmatter): array
  {
    if (!is_array($frontmatter)) {
      return [];
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

  /** Returns the configured HTML field name for the page. */
  public static function getHtmlField(Page $page): ?string
  {
    $config = self::config($page);
    return $config['htmlField'] ?? null;
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

  protected static function fieldMap(Page $page): array
  {
    $config = self::config($page);
    return $config['frontmatter'] ?? [];
  }
}

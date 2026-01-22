<?php

namespace ProcessWire;

class MarkdownUtilities
{
  protected const DEBUG_CONFIG_FLAG = 'debug';
  protected const LOG_CHANNEL = 'markdown-sync';

  protected static function logDebug(
    ?Page $page,
    string $message,
    array $context = [],
  ): void {
    $config = wire('config');
    $enabled = false;

    if ($config) {
      // Check module config first (MarkdownToFields.debug)
      $moduleConfig = $config->MarkdownToFields ?? [];
      $enabled = (bool) ($moduleConfig['debug'] ?? false);
    }

    if (!$enabled) {
      return;
    }

    self::writeLog($page, $message, $context);
  }

  protected static function logInfo(
    ?Page $page,
    string $message,
    array $context = [],
  ): void {
    self::writeLog($page, $message, $context);
  }

  private static function writeLog(
    ?Page $page,
    string $message,
    array $context = [],
  ): void {
    $parts = [];

    if ($page instanceof Page) {
      $parts[] = sprintf('page=%s', (string) $page->path);
    }

    $parts[] = $message;

    if ($context) {
      $contextParts = [];
      foreach ($context as $key => $value) {
        $contextParts[] = sprintf('%s=%s', $key, self::summarizeValue($value));
      }

      if ($contextParts) {
        $parts[] = '[' . implode(' ', $contextParts) . ']';
      }
    }

    $log = wire('log');
    if ($log) {
      $log->save(self::LOG_CHANNEL, implode(' ', $parts));
    }
  }

  protected static function summarizeValue($value): string
  {
    if ($value === null) {
      return 'null';
    }

    if (is_string($value)) {
      $trimmed = trim($value);
      if (strlen($trimmed) > 120) {
        return substr($trimmed, 0, 117) . '…';
      }

      return $trimmed === '' ? '""' : $trimmed;
    }

    if (is_scalar($value)) {
      return (string) $value;
    }

    if (is_array($value)) {
      return sprintf('array(%d)', count($value));
    }

    if ($value instanceof Page) {
      return sprintf('page#%d', (int) $value->id);
    }

    return gettype($value);
  }

  protected static function valueLength($value): int
  {
    if ($value === null) {
      return 0;
    }

    if (is_string($value)) {
      return strlen($value);
    }

    if (is_array($value)) {
      return count($value);
    }

    if (is_scalar($value)) {
      return strlen((string) $value);
    }

    if ($value instanceof Page) {
      return 1;
    }

    return 0;
  }

  protected static function valueHasContent($value): bool
  {
    if ($value === null) {
      return false;
    }

    if (is_array($value)) {
      foreach ($value as $item) {
        if (self::valueHasContent($item)) {
          return true;
        }
      }

      return false;
    }

    if ($value instanceof \DateTimeInterface) {
      return true;
    }

    if (is_object($value) && method_exists($value, '__toString')) {
      $value = (string) $value;
    }

    if (is_string($value)) {
      return trim($value) !== '';
    }

    if (is_scalar($value)) {
      return (string) $value !== '';
    }

    return false;
  }

  protected static function stringifyScalar($value): string
  {
    if (is_bool($value)) {
      return $value ? 'true' : 'false';
    }

    if ($value === null) {
      return 'null';
    }

    return (string) $value;
  }

  protected static function stringifyValue($value): string
  {
    if ($value instanceof Page) {
      return (string) $value->id;
    }

    if (is_object($value) && method_exists($value, '__toString')) {
      return (string) $value;
    }

    if (is_scalar($value) || $value === null) {
      return self::stringifyScalar($value);
    }

    return json_encode($value) ?: '';
  }

  protected static function isCoreMappedField(string $field): bool
  {
    return in_array($field, ['title', 'name'], true);
  }

  protected static function pageSupportsMappedField(
    Page $page,
    string $field,
  ): bool {
    if ($field === '') {
      return false;
    }

    if (self::isCoreMappedField($field)) {
      return true;
    }

    return $page->hasField($field);
  }

  protected static function sanitizePageNameValue(Page $page, $value): string
  {
    $stringValue = is_scalar($value) ? (string) $value : '';
    $sanitizer = $page->wire('sanitizer');

    if ($sanitizer && method_exists($sanitizer, 'pageName')) {
      $sanitized = $sanitizer->pageName($stringValue, true);

      if ($sanitized !== $stringValue) {
        self::logDebug($page, 'sanitize page name value', [
          'input' => $stringValue,
          'sanitized' => $sanitized,
        ]);
      }

      return $sanitized;
    }

    return $stringValue;
  }

  protected static function withTrailingSlash(string $path): string
  {
    if ($path === '') {
      return '';
    }

    return rtrim($path, '\\/') . '/';
  }

  protected static function startsWith(string $value, string $prefix): bool
  {
    if ($prefix === '') {
      return true;
    }

    return strncmp($value, $prefix, strlen($prefix)) === 0;
  }

  protected static function endsWith(string $value, string $suffix): bool
  {
    if ($suffix === '') {
      return true;
    }

    $length = strlen($suffix);
    if ($length === 0) {
      return true;
    }

    return substr($value, -$length) === $suffix;
  }

  protected static function isAdminPage(Page $page): bool
  {
    $template = $page->template ?? null;
    if ($template instanceof Template) {
      $flags = (int) $template->flags;

      $flagAdmin = defined('ProcessWire\\Template::flagAdmin')
        ? constant('ProcessWire\\Template::flagAdmin')
        : null;

      if ($flagAdmin !== null && ($flags & $flagAdmin) === $flagAdmin) {
        return true;
      }

      $flagSystem = defined('ProcessWire\\Template::flagSystem')
        ? constant('ProcessWire\\Template::flagSystem')
        : null;

      if ($flagSystem !== null && ($flags & $flagSystem) === $flagSystem) {
        return true;
      }

      $name = (string) $template->name;
      if ($name !== '' && $name === 'admin') {
        return true;
      }
    }

    $rootParent = $page->rootParent ?? null;
    if ($rootParent instanceof Page && (int) $rootParent->id === 2) {
      return true;
    }

    return false;
  }

  /**
   * Resolve a ProcessWire Pageimage from a src string or image element.
   * Creates a minimal Pageimage-compatible object if no managed field exists.
   */
  /**
   * Create a Pageimage object from an image src string or element with data['src'].
   * Uses native ProcessWire Pageimages/Pageimage classes.
   */
  public static function pageimage(Page $page, $srcOrElement): ?Pageimage
  {
    // Extract src from string or element.data['src']
    $src = is_string($srcOrElement) ? $srcOrElement : null;
    if (!$src && is_object($srcOrElement) && isset($srcOrElement->data['src'])) {
      $src = $srcOrElement->data['src'];
    }
    if (!$src) {
      return null;
    }

    // Build full path and verify file exists
    $fullPath = $page->filesManager()->path() . basename($src);
    if (!is_file($fullPath)) {
      return null;
    }

    // Create Pageimage with Pageimages container
    $pageimages = new Pageimages($page);
    return new Pageimage($pageimages, $fullPath);
  }
}

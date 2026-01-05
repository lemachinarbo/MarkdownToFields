<?php

namespace ProcessWire;

use LetMeDown\ContentData;
use LetMeDown\LetMeDown;

class MarkdownFileIO extends MarkdownConfig
{
  protected static function defaultSourceForPage(Page $page): string
  {
    $pageName = trim((string) $page->name);
    return $pageName !== '' ? ($pageName . '.md') : 'index.md';
  }

  protected static function isValidSource(?string $source): bool
  {
    if (!is_string($source)) {
      return false;
    }

    $trimmed = trim($source);
    if ($trimmed === '') {
      return false;
    }

    $ext = strtolower(pathinfo($trimmed, PATHINFO_EXTENSION));
    if ($ext !== 'md') {
      return false;
    }

    $basename = pathinfo($trimmed, PATHINFO_FILENAME);
    if ($basename === '' || self::startsWith($basename, '.')) {
      return false;
    }

    return true;
  }

  protected static $gettingContentSource = [];

  /** Returns the markdown source filename for the page. */
  public static function contentSource(Page $page): string
  {
    $pageId = $page->id;
    if (isset(self::$gettingContentSource[$pageId])) {
      $config = self::requireConfig($page);
      $source = $config['source'];
      $fallback = trim((string) $source['fallback']);
      if ($fallback !== '') {
        return $fallback;
      }
      throw new WireException(
        sprintf('Recursion detected getting source for page %s.', $page->path),
      );
    }

    self::$gettingContentSource[$pageId] = true;
    try {
      // Check if page class has overridden contentSource()
      if (self::hasContentSourceOverride($page)) {
        try {
          $override = $page->contentSource();
          // Only use if it returns a non-empty string; otherwise fall through to field/default
          if (is_string($override) && $override !== '') {
            return $override;
          }
        } catch (\Throwable $e) {
          // Override exists but implementation failed; fall through to field/default
        }
      }

      $config = self::requireConfig($page);
      $source = $config['source'];

      $fieldName = $source['pageField'];
      if ($fieldName && $page->hasField($fieldName)) {
        $document = trim((string) $page->get($fieldName));
        if (self::isValidSource($document)) {
          return $document;
        }

        // Invalid value present in field; reset to default name-based source
        $defaultSource = self::defaultSourceForPage($page);
        self::logInfo($page, 'reset invalid source field', [
          'field' => $fieldName,
          'was' => $document,
          'now' => $defaultSource,
        ]);
        self::saveField(
          $page,
          $fieldName,
          $defaultSource,
          'reset invalid source to default',
        );
        return $defaultSource;
      }

      // Try frontmatter 'name' field in case page name was changed via markdown
      try {
        $content = self::loadLanguageMarkdown($page, null);
        if ($content instanceof ContentData) {
          $frontmatter = $content->getFrontmatter();
          if (is_array($frontmatter) && isset($frontmatter['name'])) {
            $frontmatterName = trim((string) $frontmatter['name']);
            if ($frontmatterName !== '') {
              return $frontmatterName . '.md';
            }
          }
        }
      } catch (\Throwable $e) {
        // If we can't load markdown to check frontmatter, continue to fallback
      }

      $fallback = trim((string) $source['fallback']);
      if (self::isValidSource($fallback)) {
        return $fallback;
      }

      // Default: use page name with .md extension
      $pageName = trim((string) $page->name);
      if ($pageName !== '') {
        self::logInfo($page, 'contentSource: using page name default', [
          'pageName' => $pageName,
          'source' => $pageName . '.md',
        ]);
        return $pageName . '.md';
      }

      throw new WireException(
        sprintf('No markdown source configured for page %s.', $page->path),
      );
    } finally {
      unset(self::$gettingContentSource[$pageId]);
    }
  }

  /**
   * Check if a page class has overridden contentSource().
   * Uses reflection to detect actual method overrides, not inheritance or traits.
   */
  protected static function hasContentSourceOverride(Page $page): bool
  {
    if (!method_exists($page, 'contentSource')) {
      return false;
    }

    try {
      $reflClass = new \ReflectionClass($page);
      $method = $reflClass->getMethod('contentSource');
      $declaringClass = $method->getDeclaringClass()->getName();
      return $declaringClass !== 'ProcessWire\Page';
    } catch (\Throwable $e) {
      return false;
    }
  }

  /** Returns the filesystem path to the markdown file for a page and language. */
  public static function getMarkdownFilePath(
    Page $page,
    ?string $languageCode = null,
    ?string $source = null,
  ): string {
    $config = self::requireConfig($page);

    $languageCode ??= self::getLanguageCode($page);
    $source ??= self::contentSource($page);

    $root = $config['source']['path'];
    $source = ltrim($source, '/');

    $languages = $page->wire('languages');
    $isMultilingual = $languages && count($languages) > 1;
    
    if ($isMultilingual) {
      return $root . $languageCode . '/' . $source;
    } else {
      return $root . $source;
    }
  }

  /** Loads parsed markdown content for a page and language. */
  public static function loadMarkdown(
    Page $page,
    ?string $source = null,
    $language = null,
  ): ContentData {
    $content = self::loadLanguageMarkdown($page, $language, $source);
    if ($content !== null) {
      return $content;
    }

    $languageCode = self::getLanguageCode($page, $language);
    $source ??= self::contentSource($page);
    $path = self::getMarkdownFilePath($page, $languageCode, $source);

    self::redirectToDefaultLanguage($page, $languageCode);
    throw new WireException(
      sprintf(
        'Markdown file not found for %s (source=%s, language=%s, path=%s).',
        $page->path,
        $source,
        $languageCode,
        $path,
      ),
    );
  }

  /** Loads markdown content for a specific language or returns null if not found. */
  public static function loadLanguageMarkdown(
    Page $page,
    $language = null,
    ?string $source = null,
  ): ?ContentData {
    $languageCode = self::determineLanguageCode($page, $language);
    $source ??= self::contentSource($page);

    $path = self::getMarkdownFilePath($page, $languageCode, $source);
    if (!is_file($path)) {
      self::logDebug(
        $page,
        sprintf('markdown file not found for language %s', $languageCode),
        [
          'path' => $path,
          'source' => $source,
          'language' => $languageCode,
          'pageName' => (string) $page->name,
          'exists' => file_exists($path) ? 'yes' : 'no',
        ],
      );
      return null;
    }

    $parser = new LetMeDown();
    return $parser->load($path);
  }

  /** Saves markdown document to the filesystem for a page and language. */
  public static function saveLanguageMarkdown(
    Page $page,
    string $document,
    $language = null,
    ?string $source = null,
  ): void {
    $languageCode = self::determineLanguageCode($page, $language);
    $source ??= self::contentSource($page);
    $path = self::getMarkdownFilePath($page, $languageCode, $source);

    self::ensureDirectory($path);

    [$frontRaw, $body] = self::splitDocument($document);
    self::logDebug($page, 'saveLanguageMarkdown', [
      'language' => $languageCode,
      'len' => strlen($document),
      'frontmatter' => $frontRaw !== '' ? 1 : 0,
    ]);

    if (wire('files')->filePutContents($path, $document) === false) {
      throw new WireException(
        sprintf('Unable to write markdown file at %s', $path),
      );
    }
  }

  /** Deletes the markdown file for a page and language. */
  public static function deleteLanguageMarkdown(
    Page $page,
    $language = null,
    ?string $source = null,
  ): void {
    $languageCode = self::determineLanguageCode($page, $language);
    $source ??= self::contentSource($page);
    $path = self::getMarkdownFilePath($page, $languageCode, $source);

    if (!is_file($path)) {
      return;
    }

    if (!@unlink($path)) {
      throw new WireException(
        sprintf('Unable to delete markdown file at %s', $path),
      );
    }

    self::logDebug(
      $page,
      sprintf('deleted markdown file for language %s', $languageCode),
      ['path' => $path],
    );
  }

  /** Checks if a markdown file exists for a page and language. */
  public static function hasLanguageMarkdown(
    Page $page,
    $language = null,
    ?string $source = null,
  ): bool {
    $languageCode = self::determineLanguageCode($page, $language);
    $source ??= self::contentSource($page);

    $path = self::getMarkdownFilePath($page, $languageCode, $source);
    return is_file($path);
  }

  protected static function ensureDirectory(string $path): void
  {
    $directory = dirname($path);
    if (!is_dir($directory)) {
      wire('files')->mkdir($directory, true);
    }
  }

  /**
   * Save a field value with centralized error handling and logging.
   * WARNING: This method persists data to the database immediately!
   */
  protected static function saveField(
    Page $page,
    string $fieldName,
    mixed $fieldValue,
    ?string $logAction = null,
  ): bool {
    $page->set($fieldName, $fieldValue);

    try {
      // Full save() instead of field-scoped to ensure hooks run and consistency
      $page->save();

      if ($logAction !== null) {
        self::logInfo($page, $logAction, [
          'field' => $fieldName,
          'value' => $fieldValue,
        ]);
      }

      return true;
    } catch (\Throwable $e) {
      self::logInfo($page, 'failed to save field', [
        'field' => $fieldName,
        'action' => $logAction,
        'error' => $e->getMessage(),
      ]);

      return false;
    }
  }
}

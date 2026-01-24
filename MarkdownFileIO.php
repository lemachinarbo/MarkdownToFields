<?php

namespace ProcessWire;

use LetMeDown\ContentData;
use LetMeDown\LetMeDown;

class MarkdownFileIO extends MarkdownConfig
{
  protected static function defaultSourceForPage(Page $page): string
  {
    $pageName = trim((string) $page->name);
    return $pageName !== '' ? $pageName . '.md' : 'index.md';
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
    self::logDebug($page, 'contentSource: resolving for page', [
      'pageName' => $page->name,
      'pagePath' => $page->path,
    ]);

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
          // Override exists but implementation failed; fall through to frontmatter/default
        }
      }

      $config = self::requireConfig($page);
      $source = $config['source'];

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

    // Read and process markdown before parsing
    $markdown = @file_get_contents($path);
    if ($markdown === false) {
      return null;
    }

    // Rewrite markdown image references to page assets before parsing so
    // readonly LetMeDown elements are created with final HTML already.
    $processedMarkdown = self::processImagesInMarkdown($page, $markdown);

    $parser = new LetMeDown();

    // Parse processed markdown directly in-memory so readonly elements are
    // created with final HTML. Prefer in-memory over temp files for cleanliness.
    $content = $parser->loadFromString($processedMarkdown);
    self::logDebug($page, 'loaded processed markdown from memory', [
      'language' => $languageCode,
    ]);

    self::logInfo(
      $page,
      sprintf('loaded markdown [%s]: %s', $languageCode, $path),
      ['language' => $languageCode],
    );

    // Post-process ContentData to handle image URLs in HTML properties
    self::processContentDataImages($page, $content);

    return $content;
  }

  /**
   * Process image references in markdown source before parsing.
   * Converts ![](01.jpg) to ![](/site/assets/files/1/01.jpg)
   */
  protected static function processImagesInMarkdown(
    Page $page,
    string $markdown,
  ): string {
    $config = self::requireConfig($page);
    $imageSources = $config['imageSourcePaths'] ?? [];
    $imageBaseUrl = $config['imageBaseUrl'] ?? null;

    if (empty($imageSources) || !$imageBaseUrl) {
      return $markdown;
    }

    $imageBaseUrl = str_replace('{pageId}', $page->id, $imageBaseUrl);
    $pagePath = $page->filesManager()->path();

    // Regex to find markdown image syntax: ![alt](src)
    $pattern = '/!\[([^\]]*)\]\(([^)]+)\)/';

    $markdown = preg_replace_callback(
      $pattern,
      function ($matches) use ($page, $imageSources, $imageBaseUrl, $pagePath) {
        $alt = $matches[1];
        $src = $matches[2];

        // Skip absolute URLs, data URIs, protocol-relative URLs, and already-processed paths
        if (
          preg_match('~^(?:https?:|data:|//)~i', $src) ||
          preg_match('~^/~', $src)
        ) {
          return $matches[0]; // Return unchanged
        }

        // Search for image in source paths
        foreach ($imageSources as $sourcePath) {
          $fullPath = $sourcePath . $src;
          if (file_exists($fullPath)) {
            // Copy to page assets
            $destPath = $pagePath . basename($src);
            if (!file_exists($destPath)) {
              @copy($fullPath, $destPath);
            }
            // Return processed URL
            $processedUrl = $imageBaseUrl . basename($src);
            return sprintf('![%s](%s)', $alt, $processedUrl);
          }
        }

        return $matches[0]; // Return unchanged if not found
      },
      $markdown,
    );

    return $markdown;
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

  /**
   * Process images in ContentData HTML properties.
   * Recursively walks through sections, subsections, and fields to rewrite image URLs.
   */
  protected static function processContentImages(
    Page $page,
    ContentData $content,
  ): void {
    // Process top-level HTML
    if (isset($content->html) && is_string($content->html)) {
      $content->html = MarkdownHtmlConverter::processImagesToPageAssets(
        $page,
        $content->html,
      );
      self::logDebug($page, 'processContentImages: rewrote content->html', [
        'length' => strlen($content->html),
      ]);
    }

    // Process all properties on ContentData recursively
    $reflection = new \ReflectionObject($content);
    $seen = [];
    foreach ($reflection->getProperties() as $property) {
      $property->setAccessible(true);
      try {
        $value = $property->getValue($content);
      } catch (\Throwable $e) {
        // Skip inaccessible properties
        continue;
      }

      if (
        $value instanceof ContentData ||
        is_object($value) ||
        is_array($value)
      ) {
        self::processBlockImages($page, $value, $seen);
      }
    }
  }

  /**
   * List of LetMeDown object types that are readonly and should not be modified.
   * Currently only headings are immutable by design.
   */
  protected static array $readonlyLetMeDownTypes = [
    'LetMeDown\\HeadingElement',
  ];

  /**
   * Recursively process images in all objects and arrays.
   * Skips readonly LetMeDown types, attempts to modify writable types.
   */
  protected static function processBlockImages(Page $page, $item, array &$seen = []): void
  {
    // Prevent infinite recursion on cyclic object graphs
    if (is_object($item)) {
      $oid = spl_object_id($item);
      if (isset($seen[$oid])) {
        return;
      }
      $seen[$oid] = true;
      $className = get_class($item);

      // Skip readonly LetMeDown types entirely
      if (in_array($className, self::$readonlyLetMeDownTypes)) {
        // Still recurse through their properties to find nested items
        $reflection = new \ReflectionObject($item);
        foreach ($reflection->getProperties() as $property) {
          $property->setAccessible(true);
          try {
            $value = $property->getValue($item);

            if (is_array($value)) {
              foreach ($value as $nested) {
                self::processBlockImages($page, $nested, $seen);
              }
            } elseif (is_object($value)) {
              self::processBlockImages($page, $value, $seen);
            }
          } catch (\Throwable $e) {
            // Skip properties that can't be accessed
          }
        }
        return;
      }

      // Try to process html property on non-readonly objects
      if (isset($item->html) && is_string($item->html)) {
        $processedHtml = MarkdownHtmlConverter::processImagesToPageAssets(
          $page,
          $item->html,
        );
        try {
          $item->html = $processedHtml;
        } catch (\Throwable $e) {
          // Property is readonly, skip
        }
      }

      // Recursively process all properties that are arrays or objects
      $reflection = new \ReflectionObject($item);
      foreach ($reflection->getProperties() as $property) {
        $property->setAccessible(true);
        try {
          $value = $property->getValue($item);

          if (is_array($value)) {
            foreach ($value as $nested) {
              self::processBlockImages($page, $nested, $seen);
            }
          } elseif (is_object($value)) {
            self::processBlockImages($page, $value, $seen);
          }
        } catch (\Throwable $e) {
          // Skip properties that can't be accessed
        }
      }
    } elseif (is_array($item)) {
      // Process arrays recursively
      foreach ($item as $nested) {
        self::processBlockImages($page, $nested, $seen);
      }
    }
  }

  /**
   * Process images in a Section object, including nested arrays and objects.
   */
  protected static function processSectionImages(Page $page, $section): void
  {
    if (!is_object($section)) {
      return;
    }

    // Process section HTML
    if (isset($section->html) && is_string($section->html)) {
      $section->html = MarkdownHtmlConverter::processImagesToPageAssets(
        $page,
        $section->html,
      );
    }

    // Process section heading HTML if it has one
    if (isset($section->heading) && is_object($section->heading)) {
      if (
        isset($section->heading->html) &&
        is_string($section->heading->html)
      ) {
        $section->heading->html = MarkdownHtmlConverter::processImagesToPageAssets(
          $page,
          $section->heading->html,
        );
      }
    }

    // Process fields
    if (isset($section->fields) && is_array($section->fields)) {
      foreach ($section->fields as $field) {
        if (
          is_object($field) &&
          isset($field->html) &&
          is_string($field->html)
        ) {
          $field->html = MarkdownHtmlConverter::processImagesToPageAssets(
            $page,
            $field->html,
          );
        }
      }
    }

    // Process blocks array (and any other nested arrays)
    if (isset($section->blocks) && is_array($section->blocks)) {
      $seen = [];
      foreach ($section->blocks as $block) {
        self::processBlockImages($page, $block, $seen);
      }
    }

    // Process subsections
    if (isset($section->subsections) && is_array($section->subsections)) {
      foreach ($section->subsections as $subsection) {
        self::processSectionImages($page, $subsection);
      }
    }
  }

  /**
   * Process images in ContentData after LetMeDown parsing.
   * Attaches Pageimage objects only; URL rewriting happens during final HTML render.
   */
  protected static function processContentDataImages(Page $page, $content): void
  {
    if (!$content) {
      return;
    }

    $config = self::requireConfig($page);
    $imageSources = $config['imageSourcePaths'] ?? [];
    $imageBaseUrl = $config['imageBaseUrl'] ?? '';

    // Substitute pageId in URL if configured
    if ($imageBaseUrl) {
      $imageBaseUrl = str_replace('{pageId}', $page->id, $imageBaseUrl);
    }

    self::logInfo($page, 'processContentDataImages: starting', [
      'hasSources' => !empty($imageSources),
      'hasUrl' => !empty($imageBaseUrl),
    ]);

    // Attach Pageimage objects (asset binding)
    self::walkContent($page, $content, $imageSources, $imageBaseUrl);

    // Rewrite HTML properties for writable elements. Readonly elements are
    // intentionally created with final HTML (see processing before parse).
    self::processContentImages($page, $content);

    self::logInfo($page, 'processContentDataImages: complete', [
      'pageId' => $page->id,
    ]);
  }

  /**
   * Get protected blocks array from Section object via Reflection.
   * Falls back to magic property if Reflection fails, with debug logging.
   */
  private static function getSectionBlocks($page, $item): ?array
  {
    if (!($item instanceof \LetMeDown\Section)) {
      return null;
    }

    try {
      $reflection = new \ReflectionClass($item);
      $blocksProperty = $reflection->getProperty('blocks');
      $blocksProperty->setAccessible(true);
      $blocks = $blocksProperty->getValue($item);
      return is_array($blocks) || $blocks instanceof \ArrayObject
        ? $blocks
        : null;
    } catch (\Exception $e) {
      self::logDebug(
        $page,
        'Reflection failed for Section::blocks, using magic fallback',
        [
          'error' => $e->getMessage(),
          'class' => get_class($item),
        ],
      );
      if (isset($item->blocks)) {
        $blocks = $item->blocks;
        return is_array($blocks) || $blocks instanceof \ArrayObject
          ? $blocks
          : null;
      }
    }

    return null;
  }

  /**
   * Walk ContentData tree, processing html in each LetMeDown object and attaching Pageimage.
   */
  private static function walkContent(
    $page,
    $item,
    array $imageSources,
    string $imageBaseUrl,
  ): void {
    if (
      !is_object($item) &&
      !is_array($item) &&
      !($item instanceof \ArrayObject)
    ) {
      return;
    }

    // Skip HeadingElement (immutable structural node)
    if (is_object($item) && $item instanceof \LetMeDown\HeadingElement) {
      return;
    }

    // Attach Pageimage if src exists
    if (is_object($item) && isset($item->data['src']) && $item->data['src']) {
      $img = MarkdownUtilities::pageimage($page, $item);
      if ($img instanceof Pageimage) {
        $item->data['img'] = $img;
        self::logDebug($page, 'Attached Pageimage to ' . get_class($item), [
          'src' => $item->data['src'],
        ]);
      }
    }

    // Recursively process arrays / ArrayObjects
    if (is_array($item) || $item instanceof \ArrayObject) {
      foreach ($item as $child) {
        self::walkContent($page, $child, $imageSources, $imageBaseUrl);
      }
    }

    // Recursively process object properties
    if (is_object($item)) {
      foreach (get_object_vars($item) as $value) {
        if ($value !== null) {
          self::walkContent($page, $value, $imageSources, $imageBaseUrl);
        }
      }

      // Special handling for Section blocks
      if ($item instanceof \LetMeDown\Section) {
        $blocks = self::getSectionBlocks($page, $item);
        if ($blocks !== null) {
          self::walkContent($page, $blocks, $imageSources, $imageBaseUrl);
        }
      } elseif (isset($item->blocks)) {
        $blocks = $item->blocks;
        if ($blocks !== null) {
          self::walkContent($page, $blocks, $imageSources, $imageBaseUrl);
        }
      }
    }
  }
}

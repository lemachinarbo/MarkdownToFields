<?php

namespace ProcessWire;

use League\HTMLToMarkdown\HtmlConverter;
use LetMeDown\ContentData;
use LetMeDown\LetMeDown;
use Parsedown;
use ProcessWire\Template;
use ProcessWire\WireInput;

class MarkdownSyncer
{
  protected const FALLBACK_LANGUAGE = 'en';
  protected const SESSION_NAMESPACE = 'markdown_sync';
  protected const SESSION_PENDING_BODY = 'markdown_pending_body';
  protected const SESSION_PENDING_FIELDS = 'markdown_pending_fields';
  protected const DEBUG_CONFIG_FLAG = 'markdownSyncDebug';
  protected const LOG_CHANNEL = 'markdown-sync';

  protected static ?Parsedown $parsedown = null;
  protected static ?HtmlConverter $htmlConverter = null;

  protected const COMMENT_PLACEHOLDER_CLASS = 'md-comment-placeholder';

  protected static $gettingContentSource = [];

  public static function supportsPage(Page $page): bool
  {
    if (self::isAdminPage($page)) {
      return false;
    }

    return self::config($page) !== null;
  }

  protected static function postedLanguageValue(
    array $values,
    string $languageCode,
  ) {
    if (array_key_exists($languageCode, $values)) {
      return $values[$languageCode];
    }

    return null;
  }

  public static function contentSource(Page $page): string
  {
    // Prevent infinite recursion
    $pageId = $page->id;
    if (isset(self::$gettingContentSource[$pageId])) {
      // We're already getting the source for this page - use fallback only
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
        if ($document !== '') {
          return $document;
        }
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
      if ($fallback !== '') {
        return $fallback;
      }

      // Default: use page name with .md extension
      $pageName = trim((string) $page->name);
      if ($pageName !== '') {
        return $pageName . '.md';
      }

      throw new WireException(
        sprintf('No markdown source configured for page %s.', $page->path),
      );
    } finally {
      unset(self::$gettingContentSource[$pageId]);
    }
  }

  public static function getLanguageCode(Page $page, $language = null): string
  {
    $fallback = self::getDefaultLanguageCode($page);

    if ($language instanceof Language) {
      return self::languageCodeFromLanguage($language, $fallback);
    }

    if (is_string($language) && $language !== '') {
      return $language === 'default' ? $fallback : $language;
    }

    $current = $page->wire('user')->language ?? null;
    if ($current instanceof Language) {
      return self::languageCodeFromLanguage($current, $fallback);
    }

    return $fallback;
  }

  protected static function resolveLanguage(Page $page, $language): ?Language
  {
    if ($language instanceof Language) {
      return $language;
    }

    if ($language === null || $language === 'default') {
      return self::getDefaultLanguage($page);
    }

    $languages = $page->wire('languages');
    if (!$languages) {
      return null;
    }

    $resolved = $languages->get($language);

    if (!$resolved instanceof Language && is_string($language)) {
      $sanitizer = $languages->wire('sanitizer');
      $selectorValue = $sanitizer
        ? $sanitizer->selectorValue($language)
        : trim((string) $language);

      if ($selectorValue !== '') {
        $resolved = $languages->get('code=' . $selectorValue);
      }
    }

    return $resolved instanceof Language ? $resolved : null;
  }

  protected static function determineLanguageCode(Page $page, $language): string
  {
    $resolved = self::resolveLanguage($page, $language);
    if ($resolved instanceof Language) {
      return self::languageCodeFromLanguage($resolved, self::FALLBACK_LANGUAGE);
    }

    if (is_string($language) && $language !== '') {
      return self::getLanguageCode($page, $language);
    }

    return self::getDefaultLanguageCode($page);
  }

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

    // Only append language code if site actually uses multiple languages
    $languages = $page->wire('languages');
    $isMultilingual = $languages && count($languages) > 1;
    
    if ($isMultilingual) {
      return $root . $languageCode . '/' . $source;
    } else {
      // Single language site: no language subfolder
      return $root . $source;
    }
  }

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

    self::redirectToDefaultLanguage($page, $languageCode);
    throw new WireException(
      sprintf('Markdown file not found for %s (%s).', $page->path, $source),
    );
  }

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
        ['path' => $path, 'source' => $source, 'exists' => file_exists($path) ? 'yes' : 'no'],
      );
      return null;
    }

    $parser = new LetMeDown();
    return $parser->load($path);
  }

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

    // Log document details (length and frontmatter presence) to detect overwrites
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

  public static function availableLanguageCodes(Page $page): array
  {
    $codes = [];

    $defaultCode = self::getDefaultLanguageCode($page);
    $codes[] = $defaultCode;

    $languages = $page->wire('languages');
    if ($languages) {
      $default = $languages->getDefault();
      foreach ($languages as $language) {
        if (!$language instanceof Language) {
          continue;
        }

        if ($default instanceof Language && $language->id === $default->id) {
          continue;
        }

        $codes[] = self::languageCodeFromLanguage(
          $language,
          self::FALLBACK_LANGUAGE,
        );
      }
    }

    return array_values(array_unique($codes));
  }

  private static array $syncingFromMarkdown = [];

  public static function syncFromMarkdown(Page $page): array
  {
    $config = self::config($page);
    if ($config === null) {
      return [];
    }

    // Mark this page as being synced from markdown to skip hash checks during field saves
    self::$syncingFromMarkdown[$page->id] = true;

    try {
      return self::doSyncFromMarkdown($page);
    } finally {
      unset(self::$syncingFromMarkdown[$page->id]);
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

  /**
   * Determine whether raw markdown or form fields should be the source of truth
   * for this save operation.
   *
   * When a user explicitly unlocks md_markdown (via checkbox overlay), markdown
   * becomes authoritative because some HTML editors report changes on submit
   * even when the user didn't actually edit them.
   *
   * When md_markdown is not unlocked, form fields are the source of truth.
   *
   * This is the single point where UI intent (unlock checkbox) influences
   * the entire sync decision tree.
   *
   * @param bool|null $rawPriorityOverride Value of overlay unlock checkbox (or null if not set)
   * @param string|null $postedMarkdown The raw markdown submitted (or null if not posted)
   * @return bool True if markdown should be authoritative for this save
   */
  protected static function shouldPreferMarkdownForSync(
    ?bool $rawPriorityOverride,
    ?string $postedMarkdown,
  ): bool {
    // Normalize the override flag
    $rawPriority = $rawPriorityOverride ?? false;

    // Markdown is only authoritative if:
    // 1. User explicitly unlocked md_markdown (rawPriority = true), AND
    // 2. Markdown content was actually posted for this save
    return $rawPriority && $postedMarkdown !== null;
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

  private static function doSyncFromMarkdown(Page $page): array
  {
    $config = self::config($page);
    if ($config === null) {
      return [];
    }

    $page->of(false);

    $markdownField = $config['markdownField'];
    $htmlField = $config['htmlField'] ?? null;
    $sourcePageField = $config['source']['pageField'] ?? null;

        $dirtyFields = [];

    // Sync source field: programmatic override takes priority, then user input, then default
    if ($sourcePageField && $page->hasField($sourcePageField)) {
      $effectiveSource = self::contentSource($page);
      $currentSource = trim((string) $page->get($sourcePageField));
      $hasOverride = self::hasContentSourceOverride($page);

      if ($hasOverride && $currentSource !== $effectiveSource) {
        // Programmatic override present - sync it to field immediately
        self::saveField(
          $page,
          $sourcePageField,
          $effectiveSource,
          'synced programmatic source to field',
        );
      } elseif (!$hasOverride && $currentSource === '') {
        // No override, field is empty - populate with default
        if ($effectiveSource !== '') {
          self::saveField(
            $page,
            $sourcePageField,
            $effectiveSource,
            'populated source field with default',
          );
        }
      }
    }

    $languageCodes = self::availableLanguageCodes($page);
    $defaultCode = self::getDefaultLanguageCode($page);

    foreach ($languageCodes as $languageCode) {
      $language = self::resolveLanguage($page, $languageCode);
      $isDefaultLanguage = $languageCode === $defaultCode;

      if (!$isDefaultLanguage && !$language instanceof Language) {
        self::logDebug(
          $page,
          sprintf(
            'skip language %s: not configured in ProcessWire',
            $languageCode,
          ),
        );
        continue;
      }

      $content = $isDefaultLanguage
        ? self::loadMarkdown($page)
        : self::loadLanguageMarkdown($page, $language);

      if (!$content instanceof ContentData) {
        if (!$isDefaultLanguage) {
          self::logDebug(
            $page,
            sprintf('missing markdown file for language %s', $languageCode),
            ['field' => $markdownField],
          );
        }
        continue;
      }

      $applied = self::applyFrontmatter($page, $content, $language);
      if ($applied) {
        $dirtyFields = array_merge($dirtyFields, $applied);
      }

      $document = $content->getRawDocument();
      $storedMarkdown = (string) self::getFieldValueForLanguage(
        $page,
        $markdownField,
        $language,
      );

      $normalizedDocument = self::normalizeDocumentForComparison(
        $page,
        $document,
      );
      $normalizedStored = self::normalizeDocumentForComparison(
        $page,
        $storedMarkdown,
      );

      if ($normalizedStored !== $normalizedDocument) {
        // Persist the canonical normalized representation to avoid
        // toggling content differences due to whitespace or formatting.
        self::setFieldValueForLanguage(
          $page,
          $markdownField,
          $normalizedDocument,
          $language,
        );
        $dirtyFields[] = $markdownField;
      }

      if ($htmlField && $page->hasField($htmlField)) {
        $htmlDocument = self::markdownToHtml($content->getMarkdown(), $page);
        $preparedHtml = self::commentsToEditorPlaceholders($htmlDocument);
        $storedHtml = (string) self::getFieldValueForLanguage(
          $page,
          $htmlField,
          $language,
        );

        $normalizedStoredHtml = self::normalizeHtmlForComparison($storedHtml);
        $normalizedPreparedHtml = self::normalizeHtmlForComparison(
          $preparedHtml,
        );

        if ($normalizedStoredHtml !== $normalizedPreparedHtml) {
          self::setFieldValueForLanguage(
            $page,
            $htmlField,
            $preparedHtml,
            $language,
          );
          $dirtyFields[] = $htmlField;
        }
      }
    }

    if (!$dirtyFields) {
      return [];
    }

    $savedFields = [];
    $failedFields = [];

    foreach (array_unique($dirtyFields) as $field) {
      $supports = self::pageSupportsMappedField($page, $field);
      if (!$supports) {
        self::logInfo($page, 'skipping field: not supported', ['field' => $field]);
        continue;
      }

      try {
        self::logInfo(
          $page,
          sprintf('saving field %s after markdown sync', $field),
        );
        $page->save($field);
        $savedFields[] = $field;
      } catch (\Throwable $e) {
        $failedFields[$field] = $e->getMessage();
        self::logInfo(
          $page,
          sprintf('failed to save field %s: %s', $field, $e->getMessage()),
        );
      }
    }

    // Log summary of what changed (production-level)
    if ($savedFields) {
      self::logInfo(
        $page,
        sprintf('synced from markdown: %d fields updated', count($savedFields)),
        ['fields' => implode(', ', $savedFields)],
      );
    }

    // If protected fields failed to save, throw to prevent hash update
    if ($failedFields) {
      $protectedFields = array_intersect(
        ['name', 'title'],
        array_keys($failedFields),
      );
      if ($protectedFields) {
        // Extract the actual error message for better diagnostics
        $firstError = reset($failedFields);
        $errorPreview = is_string($firstError)
          ? (strlen($firstError) > 200
            ? substr($firstError, 0, 200) . '...'
            : $firstError)
          : json_encode($firstError);

        $errorMsg = sprintf(
          'Failed to save protected fields (%s) during markdown sync: %s',
          implode(', ', $protectedFields),
          $errorPreview,
        );

        self::logInfo($page, $errorMsg);
        throw new WireException($errorMsg);
      }
    }

    return $savedFields;
  }

  public static function syncToMarkdown(
    Page $page,
    ?string $expectedHash = null,
    ?array $languageScope = null,
    array $postedLanguageValues = [],
    ?bool $rawPriorityOverride = null,
  ): void {
    $config = self::config($page);
    if ($config === null) {
      return;
    }

    $expectedHash ??= '';
    $expectedHashes = self::decodeHashPayload($page, $expectedHash);

    $normalizedScope = self::normalizeLanguageScope($page, $languageScope);
    $languageCodes = $normalizedScope
      ? $normalizedScope
      : self::availableLanguageCodes($page);

    $expectedSubset = $expectedHashes;
    if ($normalizedScope) {
      $expectedSubset = array_intersect_key(
        $expectedHashes,
        array_flip($languageCodes),
      );
    }

    $map = self::fieldMap($page);
    $markdownField = $config['markdownField'];
    $htmlField = $config['htmlField'] ?? null;

    $postedByField = [];
    if ($postedLanguageValues) {
      foreach ($postedLanguageValues as $fieldName => $values) {
        if (!is_array($values)) {
          continue;
        }
        $postedByField[$fieldName] = $values;
      }
    }

    // Normalize posted markdown map for easy per-language access
    $postedMarkdownMap = $postedByField[$markdownField] ?? [];
    if ($postedMarkdownMap && !is_array($postedMarkdownMap)) {
      $defaultCode = self::getDefaultLanguageCode($page);
      $postedMarkdownMap = [$defaultCode => (string) $postedMarkdownMap];
    }

    $postedWrittenLanguages = [];
    if (!empty($postedMarkdownMap)) {
      self::logInfo($page, 'postedMarkdown', ['langs' => array_keys($postedMarkdownMap), 'wrote' => $postedWrittenLanguages]);
    }

    $currentHashes = self::languageFileHashes($page, $languageCodes);

    // If page indicates raw markdown should be authoritative (checkbox checked),
    // write any posted markdown content now (one central place) and update hashes.
    try {
      if ($rawPriorityOverride !== null) {
        self::logInfo($page, 'rawPriorityOverrideUsed', ['value' => $rawPriorityOverride ? 1 : 0]);
      }

      if (!empty($postedMarkdownMap)) {
        foreach ($languageCodes as $languageCode) {
          if (!array_key_exists($languageCode, $postedMarkdownMap)) continue;
          $postedMarkdown = (string) $postedMarkdownMap[$languageCode];

          // Only persist posted markdown immediately if it's the authoritative source
          if (!self::shouldPreferMarkdownForSync($rawPriorityOverride, $postedMarkdown)) {
            continue;
          }

          // Empty posted markdown indicates an explicit intent to delete the file.
          if ($postedMarkdown === '') {
            $path = self::getMarkdownFilePath($page, $languageCode);
            if (is_file($path)) {
              try {
                self::deleteLanguageMarkdown($page, $languageCode);
                self::rememberFileHash($page, [$languageCode => null]);
                $postedWrittenLanguages[] = $languageCode;
                self::logInfo($page, 'deletedPostedMarkdown', ['lang' => $languageCode]);
              } catch (\Throwable $e) {
                self::logInfo($page, 'deletePostedError', ['lang' => $languageCode, 'error' => $e->getMessage()]);
              }
            } else {
              self::logInfo($page, 'postedEmptyNoFile', ['lang' => $languageCode]);
            }

            continue;
          }

          $path = self::getMarkdownFilePath($page, $languageCode);
          $existing = is_file($path) ? file_get_contents($path) : '';

          if ($postedMarkdown !== $existing) {
            self::ensureDirectory($path);
            wire('files')->filePutContents($path, $postedMarkdown);
            $hash = md5($postedMarkdown);
            self::rememberFileHash($page, [$languageCode => $hash]);
            // Stage the page fields so the ongoing save will persist them (avoid nested saves here)
            $page->of(false);
            $page->set($markdownField, [$languageCode => $postedMarkdown]);
            $hashField = self::hashFieldName($page, null);
            if ($hashField) {
              $page->set($hashField, self::encodeHashPayload($page, [$languageCode => $hash]));
            }
            $postedWrittenLanguages[] = $languageCode;
            self::logInfo($page, 'wrotePostedMarkdown', ['lang' => $languageCode]);
          } else {
            self::logInfo($page, 'postedMatchesExisting', ['lang' => $languageCode]);
          }
        }
      }
    } catch (\Throwable $e) {
      self::logInfo($page, 'writePostedError', ['error' => $e->getMessage()]);
    }

    if (!empty($postedWrittenLanguages)) {
      self::logInfo($page, 'wrotePostedMarkdowns', ['langs' => $postedWrittenLanguages]);
    }

    // Skip hash mismatch check if we're currently syncing FROM markdown
    // (the sync operation itself updates both content and hash atomically)
    $skipHashCheck = isset(self::$syncingFromMarkdown[$page->id]);

    if ($expectedSubset && !$skipHashCheck) {
      $mismatch = self::detectHashMismatchLanguage(
        $page,
        $expectedSubset,
        $currentHashes,
      );

      if ($mismatch !== null) {
        $hasMarkdownPost = false;
        $defaultCode = self::getDefaultLanguageCode($page);
        $markdownFieldPosts = $postedByField[$markdownField] ?? [];
        $languagePost = $markdownFieldPosts[$mismatch] ?? null;

        if (
          $languagePost === null &&
          $mismatch === $defaultCode &&
          isset($markdownFieldPosts[$defaultCode])
        ) {
          $languagePost = $markdownFieldPosts[$defaultCode];
        }

        if ($languagePost !== null) {
          $hasMarkdownPost = true;
        } else {
          $htmlFieldPosts = $htmlField ? $postedByField[$htmlField] ?? [] : [];
          $htmlPost = $htmlFieldPosts[$mismatch] ?? null;
          if (
            $htmlPost === null &&
            $mismatch === $defaultCode &&
            $htmlField &&
            isset($htmlFieldPosts[$defaultCode])
          ) {
            $htmlPost = $htmlFieldPosts[$defaultCode];
          }

          if ($htmlPost !== null) {
            $hasMarkdownPost = true;
          }
        }

        if ($hasMarkdownPost) {
          self::logDebug(
            $page,
            'skip hash mismatch: markdown posted for language',
            ['language' => $mismatch],
          );
        } else {
          $language = self::resolveLanguage($page, $mismatch);
          $label =
            $language instanceof Language
              ? trim((string) ($language->title ?: $language->name))
              : (string) $mismatch;

          throw new WireException(
            sprintf(
              __(
                'The markdown file for language “%s” changed outside this editor. Please reload before saving again.',
              ),
              $label !== '' ? $label : (string) $mismatch,
            ),
          );
        }
      }
    }

    $markdownChanged = $page->isChanged($markdownField);
    $htmlChanged = false;

    if ($htmlField && !$markdownChanged && $page->hasField($htmlField)) {
      $htmlChanged = $page->isChanged($htmlField);
    }

    $postedMarkdownMap = $postedByField[$markdownField] ?? [];
    $postedHtmlMap = $htmlField ? $postedByField[$htmlField] ?? [] : [];

    $bodyChanged =
      $markdownChanged ||
      $htmlChanged ||
      !empty($postedMarkdownMap) ||
      !empty($postedHtmlMap);
    $fieldsChanged = self::mappedFieldsChanged($page, array_keys($map));

    if (!$bodyChanged && !$fieldsChanged) {
      return;
    }

    $defaultCode = self::getDefaultLanguageCode($page);

    foreach ($languageCodes as $languageCode) {
      $language = self::resolveLanguage($page, $languageCode);
      $isDefaultLanguage = self::isDefaultLanguage($page, $language);

      if (!$isDefaultLanguage && !$language instanceof Language) {
        continue;
      }

      $page->of(false);

      $languageContent = $isDefaultLanguage
        ? self::loadMarkdown($page)
        : self::loadLanguageMarkdown($page, $language);

      $existingDocument =
        $languageContent instanceof ContentData
          ? $languageContent->getRawDocument()
          : null;

      $existingFrontmatterNormalized = [];
      if ($languageContent instanceof ContentData) {
        $existingFrontmatter = $languageContent->getFrontmatter();
        if (is_array($existingFrontmatter)) {
          foreach ($existingFrontmatter as $key => $value) {
            $normalized = self::normalizeFrontmatterAssignmentValue($value);
            if ($key === 'name' && !is_array($normalized)) {
              $normalized = self::sanitizePageNameValue($page, $normalized);
            }
            $existingFrontmatterNormalized[$key] = $normalized;
          }
        }
      }

      $postedMarkdown = self::postedLanguageValue(
        $postedByField[$markdownField] ?? [],
        $languageCode,
      );

      if ($postedMarkdown !== null) {
        $markdownValue = (string) $postedMarkdown;
      } elseif ($existingDocument !== null) {
        $markdownValue = $existingDocument;
      } else {
        // ProcessWire issues a second processing pass without reposting the
        // localized markdown; fall back to the in-memory field value so we
        // don't wipe the language-specific document.
        $storedLanguageMarkdown = self::getFieldValueForLanguage(
          $page,
          $markdownField,
          $language,
        );

        if (
          is_string($storedLanguageMarkdown) &&
          $storedLanguageMarkdown !== ''
        ) {
          $markdownValue = $storedLanguageMarkdown;
        } else {
          self::logDebug(
            $page,
            sprintf(
              'skip sync for %s: no markdown input available',
              self::languageLogLabel($page, $language),
            ),
            ['field' => $markdownField],
          );
          continue;
        }
      }

      [$frontRaw, $bodyContent] = self::splitDocument($markdownValue);
      // Uncomment for deep debugging:
      // self::logDebug($page, 'syncToMarkdown document snapshot', [
      //   'language' => self::languageLogLabel($page, $language),
      //   'postedLen' => strlen($markdownValue),
      //   'frontLen' => strlen($frontRaw),
      //   'bodyLen' => strlen($bodyContent),
      // ]);

      $postedHtml = $htmlField
        ? self::postedLanguageValue(
          $postedByField[$htmlField] ?? [],
          $languageCode,
        )
        : null;

      $storedHtmlRaw = null;
      $storedHtmlString = null;

      if ($htmlField && $page->hasField($htmlField)) {
        $storedHtmlRaw = self::getFieldValueForLanguage(
          $page,
          $htmlField,
          $language,
        );

        if (
          is_scalar($storedHtmlRaw) ||
          $storedHtmlRaw === null ||
          (is_object($storedHtmlRaw) &&
            method_exists($storedHtmlRaw, '__toString'))
        ) {
          $storedHtmlString = (string) $storedHtmlRaw;
        } else {
          $storedHtmlString = null;
        }
      }

      if ($postedHtml !== null) {
        $normalizedHtml = (string) $postedHtml;
        $trimmedHtml = trim($normalizedHtml);

        if (self::shouldPreferMarkdownForSync($rawPriorityOverride, $postedMarkdown)) {
          self::logDebug(
            $page,
            'skip html override: raw priority and posted markdown present',
            ['language' => self::languageLogLabel($page, $language)],
          );
        } elseif ($trimmedHtml === '' && $postedMarkdown !== null) {
          self::logDebug(
            $page,
            'skip html fallback: empty submission with markdown input',
            ['language' => self::languageLogLabel($page, $language)],
          );
        } else {
          self::logDebug($page, 'syncToMarkdown html input', [
            'language' => self::languageLogLabel($page, $language),
            'len' => strlen($normalizedHtml),
            'preview' => substr(strip_tags($normalizedHtml), 0, 80),
          ]);

          $convertedMarkdown = self::htmlToMarkdown($normalizedHtml, $page);

          self::logDebug($page, 'syncToMarkdown html converted', [
            'language' => self::languageLogLabel($page, $language),
            'len' => strlen($convertedMarkdown),
            'preview' => substr($convertedMarkdown, 0, 80),
          ]);

          $bodyContent = $convertedMarkdown;
        }
      } elseif (
        $htmlChanged &&
        $htmlField &&
        $isDefaultLanguage &&
        $postedMarkdown === null
      ) {
        $htmlValue = $storedHtmlString;
        if ($htmlValue === null) {
          $htmlValue = (string) self::getFieldValueForLanguage(
            $page,
            $htmlField,
            $language,
          );
        }

        $bodyContent = self::htmlToMarkdown($htmlValue, $page);
      }

      $frontmatter =
        $frontRaw !== '' ? self::parseFrontmatterRaw($frontRaw) : [];

      if (!is_array($frontmatter)) {
        $frontmatter = [];
      }

      $frontmatterUpdates = [];

      foreach ($map as $field => $frontKey) {
        if (!self::pageSupportsMappedField($page, $field)) {
          continue;
        }

        $postedFieldValues = $postedByField[$field] ?? null;
        $postedFrontRaw = is_array($postedFieldValues)
          ? self::postedLanguageValue($postedFieldValues, $languageCode)
          : null;

        // When raw markdown is the authoritative source for this save,
        // ignore form field values for frontmatter fields and use only
        // the values extracted from the markdown document itself
        if (self::shouldPreferMarkdownForSync($rawPriorityOverride, $postedMarkdown)) {
          $postedFrontRaw = null;
        }

        $normalizedPosted =
          $postedFrontRaw !== null
            ? self::normalizeFrontmatterAssignmentValue($postedFrontRaw)
            : null;

        if (
          $field === 'name' &&
          $normalizedPosted !== null &&
          !is_array($normalizedPosted)
        ) {
          $normalizedPosted = self::sanitizePageNameValue(
            $page,
            $normalizedPosted,
          );
        }

        $currentRaw = self::getFieldValueForLanguage($page, $field, $language);
        $currentValue = self::normalizeFrontmatterAssignmentValue($currentRaw);

        if ($field === 'name' && !is_array($currentValue)) {
          $currentValue = self::sanitizePageNameValue($page, $currentValue);
        }

        $hasDocumentFrontmatter = array_key_exists($frontKey, $frontmatter);
        $documentValue = null;

        if ($hasDocumentFrontmatter) {
          $documentValue = self::normalizeFrontmatterAssignmentValue(
            $frontmatter[$frontKey],
          );

          if (
            $field === 'name' &&
            $documentValue !== null &&
            !is_array($documentValue)
          ) {
            $documentValue = self::sanitizePageNameValue($page, $documentValue);
          }
        }

        $previousValue = $existingFrontmatterNormalized[$frontKey] ?? null;

        if (
          $field === 'name' &&
          $previousValue !== null &&
          !is_array($previousValue)
        ) {
          $previousValue = self::sanitizePageNameValue($page, $previousValue);
        }

        $fieldChanged =
          $normalizedPosted !== null
            ? self::frontmatterChangeDetected($previousValue, $normalizedPosted)
            : false;

        $markdownChanged =
          $documentValue !== null
            ? self::frontmatterChangeDetected($previousValue, $documentValue)
            : false;

        if ($field === 'name') {
          self::logDebug($page, 'syncToMarkdown name state', [
            'language' => $languageCode,
            'postedRaw' => $postedFrontRaw,
            'normalizedPosted' => $normalizedPosted,
            'documentValue' => $documentValue,
            'currentValue' => $currentValue,
            'previousValue' => $previousValue,
            'fieldChanged' => $fieldChanged,
            'markdownChanged' => $markdownChanged,
          ]);
        }

        if ($field === 'title') {
          self::logInfo($page, 'syncToMarkdown title frontmatter start', [
            'language' => $languageCode,
            'frontKey' => $frontKey,
            'preferMarkdown' => self::shouldPreferMarkdownForSync($rawPriorityOverride, $postedMarkdown) ? 1 : 0,
            'postedFrontRaw' => $postedFrontRaw,
            'normalizedPosted' => $normalizedPosted,
            'currentValue' => $currentValue,
            'documentValue' => $documentValue,
            'previousValue' => $previousValue,
            'fieldChanged' => $fieldChanged,
            'markdownChanged' => $markdownChanged,
          ]);
        }

        if ($fieldChanged && $markdownChanged) {
          if (
            $existingDocument !== null &&
            $existingDocument === $markdownValue
          ) {
            $markdownChanged = false;
          }
        }

        if ($fieldChanged && $markdownChanged) {
          $valuesDiffer =
            $normalizedPosted !== null &&
            $documentValue !== null &&
            self::frontmatterValuesDiffer($normalizedPosted, $documentValue);

          if ($valuesDiffer) {
            $label = $field === 'title' ? __('title') : $field;
            throw new WireException(
              sprintf(
                __(
                  'Field “%s” was modified in both the markdown and the form. Please adjust only one version before saving.',
                ),
                $label,
              ),
            );
          }
        }

        $finalValue = null;

        if ($fieldChanged) {
          $finalValue = $normalizedPosted;
        } elseif ($markdownChanged) {
          $finalValue = $documentValue;
        } elseif ($hasDocumentFrontmatter) {
          $finalValue = $documentValue;
        } else {
          $fallbackValue = self::frontmatterValue($page, $field, $language);
          $finalValue = self::normalizeFrontmatterAssignmentValue(
            $fallbackValue,
          );
        }

        if ($finalValue === null) {
          $finalValue = '';
        }

        if ($field === 'name' && !is_array($finalValue)) {
          $finalValue = self::sanitizePageNameValue($page, $finalValue);
        }

        $frontmatter[$frontKey] = $finalValue;

        if (self::frontmatterValuesDiffer($finalValue, $currentValue)) {
          $frontmatterUpdates[$field] = $finalValue;
          
          if ($field === 'title') {
            self::logInfo($page, 'syncToMarkdown title will update', [
              'language' => $languageCode,
              'from' => $currentValue,
              'to' => $finalValue,
            ]);
          }
        }

        if ($field === 'title') {
          self::logInfo($page, 'syncToMarkdown title decision', [
            'language' => $languageCode,
            'finalValue' => $finalValue,
            'source' => $fieldChanged ? 'posted' : ($markdownChanged ? 'markdown' : 'fallback'),
            'currentValue' => $currentValue,
          ]);
        }

        if ($field === 'name') {
          self::logDebug($page, 'syncToMarkdown name decision', [
            'language' => $languageCode,
            'finalValue' => $finalValue,
            'source' => $fieldChanged
              ? 'posted'
              : ($markdownChanged
                ? 'markdown'
                : 'fallback'),
          ]);
        }
      }

      if ($frontmatterUpdates) {
        foreach ($frontmatterUpdates as $field => $value) {
          if ($field === 'title') {
            self::logInfo($page, 'syncToMarkdown title applying update', [
              'language' => $languageCode,
              'value' => $value,
            ]);
          }
          self::setFieldValueForLanguage($page, $field, $value, $language);
        }
      }

      $hasDocumentContent = self::documentHasContent(
        $frontmatter,
        $bodyContent,
      );

      $document = $hasDocumentContent
        ? self::composeDocument($frontmatter, $bodyContent)
        : '';

      if (array_key_exists('title', $frontmatter)) {
        self::logInfo($page, 'syncToMarkdown title in composed document', [
          'language' => $languageCode,
          'frontmatterTitle' => $frontmatter['title'] ?? null,
          'documentLen' => strlen($document),
        ]);
      }

      // Uncomment for deep debugging:
      // self::logDebug($page, 'syncToMarkdown compose result', [
      //   'language' => self::languageLogLabel($page, $language),
      //   'documentLen' => strlen($document),
      //   'hasContent' => $hasDocumentContent ? 1 : 0,
      //   'bodyLen' => strlen($bodyContent),
      //   'bodyPreview' => substr($bodyContent, 0, 80),
      // ]);

      $storedMarkdown = (string) self::getFieldValueForLanguage(
        $page,
        $markdownField,
        $language,
      );

      if ($storedMarkdown !== $document) {
        self::setFieldValueForLanguage(
          $page,
          $markdownField,
          $document,
          $language,
        );
        
        if (array_key_exists('title', $frontmatter)) {
          self::logInfo($page, 'syncToMarkdown markdown field updated with new document', [
            'language' => $languageCode,
            'frontmatterTitle' => $frontmatter['title'] ?? null,
            'oldLen' => strlen($storedMarkdown),
            'newLen' => strlen($document),
          ]);
        }
      }

      if ($htmlField && $page->hasField($htmlField)) {
        $htmlDocument = self::markdownToHtml($bodyContent, $page);
        $preparedHtml = self::commentsToEditorPlaceholders($htmlDocument);
        $storedHtml = $storedHtmlString;
        if ($storedHtml === null) {
          $storedHtml = (string) self::getFieldValueForLanguage(
            $page,
            $htmlField,
            $language,
          );
        }

        if ($storedHtml !== $preparedHtml) {
          self::setFieldValueForLanguage(
            $page,
            $htmlField,
            $preparedHtml,
            $language,
          );
        }
      }

      if ($hasDocumentContent) {
        $shouldWriteFile = true;

        if ($existingDocument !== null && $existingDocument === $document) {
          $shouldWriteFile = false;
        }

        if ($shouldWriteFile) {
          self::saveLanguageMarkdown(
            $page,
            $document,
            $isDefaultLanguage ? null : $language,
          );
        }
      } else {
        $hasFile =
          $existingDocument !== null ||
          self::hasLanguageMarkdown($page, $language);

        if ($hasFile) {
          self::deleteLanguageMarkdown(
            $page,
            $isDefaultLanguage ? null : $language,
          );
        }
      }
    }
  }

  public static function getMarkdownFileHash(
    Page $page,
    ?string $source = null,
    ?string $languageCode = null,
  ): ?string {
    if (!self::supportsPage($page)) {
      return null;
    }

    $source ??= self::contentSource($page);
    $languageCode ??= self::getLanguageCode($page);
    $path = self::getMarkdownFilePath($page, $languageCode, $source);

    if (!is_file($path)) {
      return null;
    }

    $hash = md5_file($path);
    return $hash !== false ? $hash : null;
  }

  public static function languageFileHashes(
    Page $page,
    ?array $languageCodes = null,
  ): array {
    $codes = self::normalizeLanguageScope($page, $languageCodes);
    if (!$codes) {
      $codes = self::availableLanguageCodes($page);
    }

    $hashes = [];

    foreach ($codes as $languageCode) {
      $hashes[$languageCode] = self::getMarkdownFileHash(
        $page,
        null,
        $languageCode,
      );
    }

    return $hashes;
  }

  public static function buildHashPayload(Page $page): string
  {
    return self::encodeHashPayload($page, self::languageFileHashes($page));
  }

  /**
   * Sync all markdown-managed pages and persist a last-known file hash
   * to each page's configured hash field (if the field exists on the page).
   * Returns the number of pages processed.
   *
   * @param int $limit Maximum number of pages to process (default 10000)
   * @return int number of pages processed
   */
  public static function syncAllManagedPages(
    int $limit = 10000,
    ?string $hashFieldName = null,
    ?string $logChannel = null,
    bool $persist = true,
    int $ttlSeconds = 0,
    bool $useLock = true,
  ): int {
    $pages = wire('pages')->find("limit={$limit}");
    $processed = 0;
    $updated = 0;
    $updatedPages = [];
    $logChannel = $logChannel ?? 'migrate-markdown';
    $log = wire('log');

    $cacheKey = 'markdown-sync-last-run';
    $apcuKey = 'markdown-sync-lock';

    try {
      $log->save($logChannel, 'Starting markdown sync migration');
    } catch (\Throwable $_e) {
      // Logging is best-effort; ignore errors
    }

    // If a global hash field name is supplied but doesn't exist in fields, disable persistence.
    if ($hashFieldName !== null) {
      try {
        $fieldsModule = wire('fields');
        if (!$fieldsModule->get($hashFieldName)) {
          $persist = false;
          try {
            self::logDebug(null, 'hash field missing; skipping persistence', [
              'field' => $hashFieldName,
            ]);
          } catch (\Throwable $_e) {
            // ignore
          }
        }
      } catch (\Throwable $_e) {
        // ignore
      }
    }

    // If TTL is set, skip if we recently ran (quick pre-check)
    if ($ttlSeconds > 0) {
      try {
        $last = wire('cache')->get($cacheKey) ?? 0;
        if (time() - (int) $last < $ttlSeconds) {
          try {
            self::logDebug(
              null,
              'Skipping markdown sync: recent run within TTL',
            );
          } catch (\Throwable $_e) {
          }
          return 0;
        }
      } catch (\Throwable $_e) {
      }
    }

    // Acquire a lock if requested. Prefer APCu, fallback to file lock.
    $lockFp = null;
    $gotLock = false;
    if ($useLock) {
      try {
        if (function_exists('apcu_add')) {
          @$gotLock = (bool) \call_user_func(
            'apcu_add',
            $apcuKey,
            1,
            max(30, $ttlSeconds),
          );
        }
      } catch (\Throwable $_e) {
        $gotLock = false;
      }

      if (!$gotLock) {
        try {
          $lockFile = wire('config')->paths->cache . 'markdown-sync.lock';
          $lockFp = fopen($lockFile, 'c');
          if ($lockFp) {
            $gotLock = flock($lockFp, LOCK_EX | LOCK_NB);
          }
        } catch (\Throwable $_e) {
          $gotLock = false;
        }
      }

      if (!$gotLock) {
        try {
          self::logDebug(null, 'Skipping markdown sync: failed to obtain lock');
        } catch (\Throwable $_e) {
        }
        return 0;
      }

      // re-check TTL after obtaining lock
      if ($ttlSeconds > 0) {
        try {
          $last = wire('cache')->get($cacheKey) ?? 0;
          if (time() - (int) $last < $ttlSeconds) {
            if ($lockFp) {
              @flock($lockFp, LOCK_UN);
              @fclose($lockFp);
              @unlink(wire('config')->paths->cache . 'markdown-sync.lock');
            }
            if (function_exists('apcu_delete')) {
              \call_user_func('apcu_delete', $apcuKey);
            }
            try {
              self::logDebug(
                null,
                'Skipping markdown sync after re-check: recent run within TTL',
              );
            } catch (\Throwable $_e) {
            }
            return 0;
          }
        } catch (\Throwable $_e) {
        }
      }
    }

    $didRun = false;
    try {
      $didRun = true;
      foreach ($pages as $p) {
        try {
          if (!self::supportsPage($p)) {
            continue;
          }
          // Determine the target hash field and persisted payload (if any)
          $targetField = $hashFieldName ?? self::getHashField($p);
          $existingPayload = null;
          try {
            if (
              is_string($targetField) &&
              $targetField !== '' &&
              $p->hasField($targetField)
            ) {
              $existingPayload = $p->get($targetField);
            } else {
              // fallback to session or site cache if the template does not have the hash field
              $existingPayload = self::recallFileHash($p, $targetField);
              if ($existingPayload === null || $existingPayload === '') {
                // site cache fallback per-page
                $cacheKeyPage = 'markdown-sync-hash-' . (int) $p->id;
                $cached = wire('cache')->get($cacheKeyPage);
                if (is_string($cached) && $cached !== '') {
                  $existingPayload = $cached;
                }
              }
            }
          } catch (\Throwable $_e) {
            $existingPayload = null;
          }

          // Build normalized payloads and compare to skip unnecessary sync
          $currentHashes = self::languageFileHashes($p);
          $currentEncoded = self::encodeHashPayload($p, $currentHashes);
          $existingDecoded = self::decodeHashPayload($p, $existingPayload);
          $existingEncoded = self::encodeHashPayload($p, $existingDecoded);

          if ($existingEncoded !== '' && $currentEncoded === $existingEncoded) {
            // Uncomment for deep debugging to see all skipped pages:
            // self::logDebug($p, 'skip page: file hash unchanged', [
            //   'path' => (string) $p->path,
            // ]);
            $processed++;
            continue;
          }
          $dirtyFields = self::syncFromMarkdown($p);

          // If syncFromMarkdown threw an exception (e.g., protected field save failed),
          // it will be caught in the outer catch block and we skip hash updates.
          // This ensures we don't persist a hash when critical fields couldn't be saved.

          $payload = self::buildHashPayload($p);

          $didHashSave = false;
          $languageChanges = [];
          if ($payload !== '') {
            $targetField = $hashFieldName ?? self::getHashField($p);

            // Determine previous payload (DB-stored hash if present, else session fallback)
            $existingPayload = null;
            try {
              if (
                is_string($targetField) &&
                $targetField !== '' &&
                $p->hasField($targetField)
              ) {
                $existingPayload = $p->get($targetField);
              } else {
                $existingPayload = self::recallFileHash($p, $targetField);
              }
            } catch (\Throwable $_e) {
              $existingPayload = null;
            }

            $oldHashes = self::decodeHashPayload($p, $existingPayload);
            $newHashes = self::decodeHashPayload($p, $payload);

            // detect language changes
            $codes = array_unique(
              array_merge(array_keys($oldHashes), array_keys($newHashes)),
            );
            foreach ($codes as $code) {
              $old = $oldHashes[$code] ?? null;
              $new = $newHashes[$code] ?? null;
              if ($old !== $new) {
                if ($old === null && $new !== null) {
                  $languageChanges[$code] = 'added';
                } elseif ($old !== null && $new === null) {
                  $languageChanges[$code] = 'removed';
                } else {
                  $languageChanges[$code] = 'changed';
                }
              }
            }

            if (
              $persist &&
              is_string($targetField) &&
              $targetField !== '' &&
              $p->hasField($targetField)
            ) {
              // Only persist the hash if it is different to the existing value
              $existingPayloadNormalized = $existingPayload === null ? '' : (string) $existingPayload;
              $payloadStr = (string) $payload;
              if ($existingPayloadNormalized !== $payloadStr) {
                $p->of(false);
                $p->set($targetField, $payloadStr);
                $p->save($targetField);
                $didHashSave = true;
                // Clear stale cache fallback if we persisted to the field
                wire('cache')->delete('markdown-sync-hash-' . (int) $p->id);
              }
            }

            // If we couldn't write the hash to the page field but persistence is desired,
            // store the value in site cache so subsequent runs are stable.
            if ($persist && !$didHashSave) {
              $cacheKeyPage = 'markdown-sync-hash-' . (int) $p->id;
              if ($payload !== '') {
                wire('cache')->save($cacheKeyPage, (string) $payload, 0);
              }
            }
          }
          $didDirtySave = !empty($dirtyFields);
          $didAnySave = $didDirtySave || !empty($didHashSave);

          if ($didAnySave) {
            // Only log & count updated pages if we actually persisted something
            if ($didDirtySave && !empty($dirtyFields)) {
              self::logDebug($p, 'fields updated after markdown sync', [
                'fields' => implode(',', $dirtyFields),
              ]);
            }
            if ($languageChanges) {
              $labelParts = [];
              foreach ($languageChanges as $code => $type) {
                $labelParts[] =
                  $code . ($type === 'changed' ? '' : "({$type})");
              }
              $label = implode(', ', $labelParts);
            } else {
              $label = 'hash';
            }
            $updated++;
            $updatedPages[] = sprintf('%s [%s]', (string) $p->path, $label);

            // Production-level log for actual updates
            self::logInfo($p, 'page synced from markdown', [
              'changes' => $label,
            ]);

            try {
              $log->save(
                $logChannel,
                sprintf('Updated %s: %s', (string) $p->path, $label),
              );
            } catch (\Throwable $_e) {
            }
          }

          $processed++;
        } catch (\Throwable $e) {
          $isProtectedFieldError = str_contains(
            $e->getMessage(),
            'protected fields',
          );

          if ($isProtectedFieldError) {
            // Log prominently for protected field failures
            $log->save(
              $logChannel,
              sprintf(
                'ERROR: Failed to sync %s - %s',
                (string) $p->path,
                $e->getMessage(),
              ),
            );
          }

          self::logDebug($p, 'syncAllManagedPages failed', [
            'message' => $e->getMessage(),
            'protectedFieldError' => $isProtectedFieldError,
          ]);
        }
      }
    } finally {
      // Release any locks and persist TTL marker if requested
      if ($useLock && $gotLock) {
        // Release file lock if acquired
        if (isset($lockFp) && $lockFp) {
          @flock($lockFp, LOCK_UN);
          @fclose($lockFp);
          @unlink(wire('config')->paths->cache . 'markdown-sync.lock');
        }

        // Release APCu lock if available (independent of file lock)
        if (function_exists('apcu_delete')) {
          @\call_user_func('apcu_delete', $apcuKey);
        }
      }

      if ($didRun && $ttlSeconds > 0) {
        wire('cache')->save($cacheKey, time(), $ttlSeconds);
      }
    }

    try {
      $log->save(
        $logChannel,
        sprintf(
          'Markdown sync migration done (%d pages; %d updated)',
          $processed,
          $updated,
        ),
      );
      if ($updated > 0 && $updatedPages) {
        $limit = 30;
        $summaryList = $updatedPages;
        $more = 0;
        if (count($summaryList) > $limit) {
          $more = count($summaryList) - $limit;
          $summaryList = array_slice($summaryList, 0, $limit);
        }
        $message = 'Updated pages: ' . implode('; ', $summaryList);
        if ($more > 0) {
          $message .= sprintf(' ... +%d more', $more);
        }
        $log->save($logChannel, $message);
      }
    } catch (\Throwable $_e) {
      // ignore
    }

    return $processed;
  }

  protected static function encodeHashPayload(Page $page, array $hashes): string
  {
    $normalized = self::normalizeHashMap($page, $hashes);
    ksort($normalized);

    $encoded = json_encode($normalized);
    return $encoded === false ? '' : $encoded;
  }

  protected static function decodeHashPayload(
    Page $page,
    ?string $payload,
  ): array {
    if ($payload === null || $payload === '') {
      return [];
    }

    $decoded = json_decode($payload, true);
    if (!is_array($decoded)) {
      $code = self::getDefaultLanguageCode($page);
      return [$code => (string) $payload];
    }

    return self::normalizeHashMap($page, $decoded);
  }

  protected static function normalizeHashMap(Page $page, array $hashes): array
  {
    $normalized = [];

    foreach ($hashes as $languageKey => $value) {
      $code = self::determineLanguageCode($page, $languageKey);

      if ($value === null || $value === '') {
        $normalized[$code] = $value === null ? null : (string) $value;
        continue;
      }

      $normalized[$code] = (string) $value;
    }

    return $normalized;
  }

  protected static function normalizeLanguageScope(
    Page $page,
    ?array $languageCodes,
  ): array {
    if ($languageCodes === null) {
      return [];
    }

    $normalized = [];

    foreach ($languageCodes as $candidate) {
      if ($candidate instanceof Language) {
        $normalized[] = self::languageCodeFromLanguage(
          $candidate,
          self::getDefaultLanguageCode($page),
        );
        continue;
      }

      if (is_string($candidate) || is_int($candidate)) {
        $code = self::determineLanguageCode($page, (string) $candidate);
        if ($code !== '') {
          $normalized[] = $code;
        }
      }
    }

    if (!$normalized) {
      return [];
    }

    return array_values(array_unique($normalized));
  }

  protected static function detectHashMismatchLanguage(
    Page $page,
    array $expected,
    array $current,
  ): ?string {
    $codes = array_unique(
      array_merge(array_keys($expected), array_keys($current)),
    );

    foreach ($codes as $code) {
      $expectedValue = $expected[$code] ?? null;
      $currentValue = $current[$code] ?? null;

      if ($expectedValue !== $currentValue) {
        self::logDebug($page, 'markdown hash mismatch detected', [
          'language' => $code,
          'expected' => $expectedValue,
          'current' => $currentValue,
        ]);
        return (string) $code;
      }
    }

    return null;
  }

  public static function normalizeSubmittedFieldValues(
    Page $page,
    $value,
  ): array {
    return self::normalizePostedLanguageInput($page, $value);
  }

  public static function detectEditedLanguages(Page $page, array $fields): array
  {
    if (!$fields) {
      return [];
    }

    $codes = [];
    $requireAllLanguages = false;
    $defaultCode = self::getDefaultLanguageCode($page);

    foreach ($fields as $field => $value) {
      if ($field === null || $field === '') {
        continue;
      }

      $postedValues = self::normalizePostedLanguageInput($page, $value);
      if (!$postedValues) {
        continue;
      }

      $fieldObject = null;
      if ($field === 'title' || $page->hasField($field)) {
        $fieldObject = $page->getField($field);
      }

      $isTranslatable =
        $fieldObject instanceof Field &&
        $fieldObject->type instanceof FieldtypeLanguageInterface;

      foreach ($postedValues as $code => $submitted) {
        $language =
          $code === $defaultCode ? null : self::resolveLanguage($page, $code);
        if ($code !== $defaultCode && !$language instanceof Language) {
          continue;
        }

        $currentValue = self::getFieldValueForLanguage(
          $page,
          $field,
          $language,
        );
        $normalizedCurrent = self::stringifyPendingFieldValue($currentValue);

        if ($submitted !== $normalizedCurrent) {
          if ($isTranslatable) {
            $codes[$code] = true;
          } else {
            $requireAllLanguages = true;
          }
        }
      }
    }

    if ($requireAllLanguages) {
      return self::availableLanguageCodes($page);
    }

    if (!$codes) {
      return [];
    }

    return array_values(array_keys($codes));
  }

  protected static function collectLanguageFieldValues(
    Page $page,
    string $field,
  ): array {
    $values = [];
    $defaultCode = self::getDefaultLanguageCode($page);

    foreach (self::availableLanguageCodes($page) as $languageCode) {
      $language =
        $languageCode === $defaultCode
          ? null
          : self::resolveLanguage($page, $languageCode);

      if ($languageCode !== $defaultCode && !$language instanceof Language) {
        continue;
      }

      $raw = self::getFieldValueForLanguage($page, $field, $language);
      $values[$languageCode] = self::stringifyPendingFieldValue($raw);
    }

    if (!$values) {
      $values[$defaultCode] = self::stringifyPendingFieldValue(
        $page->get($field),
      );
    }

    return $values;
  }

  protected static function normalizePendingLanguageMap(
    Page $page,
    array $values,
  ): array {
    $normalized = [];

    foreach ($values as $languageKey => $value) {
      if (is_array($value)) {
        $value = reset($value);
      }

      if (!is_scalar($value) && $value !== null && !is_object($value)) {
        continue;
      }

      $code = self::resolvePostedLanguageCode($page, (string) $languageKey);
      if ($code === null || $code === '') {
        continue;
      }

      $normalized[$code] = self::stringifyPendingFieldValue($value);
    }

    if (!$normalized && $values) {
      $first = reset($values);
      $defaultCode = self::getDefaultLanguageCode($page);
      $normalized[$defaultCode] = self::stringifyPendingFieldValue($first);
    }

    return $normalized;
  }

  public static function collectSubmittedLanguageValues(
    Page $page,
    string $field,
    WireInput $input,
  ): array {
    $collected = [];
    $defaultValue = $input->post($field);

    if ($field === 'name') {
      if ($defaultValue === null) {
        $defaultValue = $input->post('_pw_page_name');
      }

      $rawKeys = array_keys($_POST ?? []);
      $postKeys = array_slice($rawKeys, 0, 20);
      $nameKeys = array_values(
        array_filter(
          $rawKeys,
          static fn($key) => stripos((string) $key, 'name') !== false,
        ),
      );
      $nameSamples = [];
      foreach (array_slice($nameKeys, 0, 5) as $key) {
        $value = $_POST[$key] ?? null;
        if (is_scalar($value)) {
          $description = (string) $value;
        } elseif (is_array($value)) {
          $description = 'array(' . count($value) . ')';
        } elseif (is_object($value)) {
          $description = 'object(' . get_class($value) . ')';
        } elseif ($value === null) {
          $description = 'null';
        } else {
          $description = gettype($value);
        }
        $nameSamples[] = $key . '=' . $description;
      }
      self::logDebug($page, 'collect submitted value default probe', [
        'field' => $field,
        'defaultPresent' => array_key_exists($field, $_POST ?? []),
        'defaultType' => is_object($defaultValue)
          ? get_class($defaultValue)
          : gettype($defaultValue),
        'defaultValue' => is_scalar($defaultValue)
          ? (string) $defaultValue
          : (is_array($defaultValue)
            ? 'array'
            : null),
        'postKeys' => implode(', ', $postKeys),
        'nameKeys' => implode(', ', array_slice($nameKeys, 0, 20)),
        'nameSamples' => $nameSamples ? implode(', ', $nameSamples) : null,
      ]);
    }

    if ($defaultValue !== null) {
      self::registerPostedLanguageValue($page, $collected, $defaultValue, null);
    }

    $languages = $page->wire('languages');
    if ($languages) {
      foreach ($languages as $language) {
        if (!$language instanceof Language || $language->isDefault()) {
          continue;
        }

        foreach (self::languagePostKeyVariants($field, $language) as $key) {
          if ($key === $field) {
            continue;
          }

          $value = $input->post($key);
          if ($value === null) {
            continue;
          }

          if (is_array($value)) {
            $context = ['keys' => array_keys($value)];
          } else {
            $context = ['value' => self::summarizeValue($value)];
          }

          self::logDebug(
            $page,
            sprintf('collect submitted value for %s via %s', $field, $key),
            $context,
          );

          self::registerPostedLanguageValue(
            $page,
            $collected,
            $value,
            $language,
          );
          break;
        }
      }
    }

    if ($collected) {
      return $collected;
    }

    if (is_array($defaultValue)) {
      return self::normalizePendingLanguageMap($page, $defaultValue);
    }

    return [];
  }

  protected static function registerPostedLanguageValue(
    Page $page,
    array &$target,
    $value,
    ?Language $language,
  ): void {
    if (is_array($value)) {
      $flattened = self::extractLanguageValueFromArray($value);

      if ($flattened !== null) {
        $code =
          $language instanceof Language
            ? self::languageCodeFromLanguage($language, self::FALLBACK_LANGUAGE)
            : self::getDefaultLanguageCode($page);

        $target[$code] = self::stringifyPendingFieldValue($flattened);
        return;
      }

      $normalized = self::normalizePendingLanguageMap($page, $value);
      foreach ($normalized as $code => $normalizedValue) {
        $target[$code] = $normalizedValue;
      }
      return;
    }

    if (!is_scalar($value) && $value !== null && !is_object($value)) {
      return;
    }

    $code =
      $language instanceof Language
        ? self::languageCodeFromLanguage($language, self::FALLBACK_LANGUAGE)
        : self::getDefaultLanguageCode($page);

    $target[$code] = self::stringifyPendingFieldValue($value);
  }

  protected static function languagePostKeyVariants(
    string $field,
    Language $language,
  ): array {
    $id = (int) $language->id;
    $name = (string) $language->name;

    $variants = array_unique(
      array_filter(
        [
          $field . $id,
          $field . '_' . $id,
          $field . '__' . $id,
          $field . '[' . $id . ']',
          $field . $name,
          $field . '_' . $name,
          $field . '__' . $name,
        ],
        static function ($variant) {
          return is_string($variant) && $variant !== '';
        },
      ),
    );

    if ($field === 'name') {
      $variants = array_merge(
        $variants,
        array_filter(
          [
            '_pw_page_name' . $id,
            '_pw_page_name_' . $id,
            '_pw_page_name__' . $id,
            '_pw_page_name[' . $id . ']',
            '_pw_page_name' . $name,
            '_pw_page_name_' . $name,
            '_pw_page_name__' . $name,
          ],
          static fn($variant) => is_string($variant) && $variant !== '',
        ),
      );
    }

    return array_values(array_unique($variants));
  }

  protected static function resolvePostedLanguageCode(
    Page $page,
    string $languageKey,
  ): ?string {
    $languageKey = trim($languageKey);
    if ($languageKey === '') {
      return null;
    }

    $defaultCode = self::getDefaultLanguageCode($page);
    $normalizedKey = strtolower($languageKey);

    if (
      $normalizedKey === 'default' ||
      $normalizedKey === 'data' ||
      $normalizedKey === $defaultCode
    ) {
      return $defaultCode;
    }

    $languages = $page->wire('languages');
    if ($languages) {
      $language = $languages->get($languageKey);
      if ($language instanceof Language) {
        return self::languageCodeFromLanguage(
          $language,
          self::FALLBACK_LANGUAGE,
        );
      }

      if (preg_match('/(\d+)$/', $languageKey, $match)) {
        $language = $languages->get((int) $match[1]);
        if ($language instanceof Language) {
          return self::languageCodeFromLanguage(
            $language,
            self::FALLBACK_LANGUAGE,
          );
        }
      }

      $sanitizer = $languages->wire('sanitizer');
      $selectorValue = $sanitizer
        ? $sanitizer->selectorValue($languageKey)
        : $languageKey;

      if ($selectorValue !== '') {
        $language = $languages->get('code=' . $selectorValue);
        if ($language instanceof Language) {
          return self::languageCodeFromLanguage(
            $language,
            self::FALLBACK_LANGUAGE,
          );
        }
      }
    }

    $code = self::determineLanguageCode($page, $languageKey);
    return $code !== '' ? $code : null;
  }

  protected static function stringifyPendingFieldValue($value): string
  {
    if ($value === null) {
      return '';
    }

    if (is_scalar($value)) {
      return (string) $value;
    }

    if (is_object($value) && method_exists($value, '__toString')) {
      return (string) $value;
    }

    return self::stringifyValue($value);
  }

  protected static function normalizePostedLanguageInput(
    Page $page,
    $value,
  ): array {
    if (is_array($value)) {
      return self::normalizePendingLanguageMap($page, $value);
    }

    if (is_scalar($value) || $value === null) {
      $code = self::getDefaultLanguageCode($page);
      return [$code => self::stringifyPendingFieldValue($value)];
    }

    return [];
  }

  public static function applyLanguageValues(
    Page $page,
    string $field,
    array $values,
  ): void {
    if (!$values) {
      return;
    }

    $defaultCode = self::getDefaultLanguageCode($page);

    foreach ($values as $code => $value) {
      $language = null;
      $languageCode = (string) $code;

      if ($languageCode !== $defaultCode) {
        $language = self::resolveLanguage($page, $languageCode);
        if (!$language instanceof Language) {
          continue;
        }
      }

      self::setFieldValueForLanguage($page, $field, $value, $language);
    }
  }

  public static function rememberFileHash(
    Page $page,
    $hash,
    ?string $fieldName = null,
  ): void {
    $field = self::hashFieldName($page, $fieldName);

    if (is_array($hash)) {
      $payload = self::encodeHashPayload($page, $hash);
    } elseif (is_string($hash)) {
      $payload = $hash;
    } elseif ($hash === null) {
      $payload = '';
    } else {
      $payload = (string) $hash;
    }

    $page->wire('session')->set(self::sessionKey($page, $field), $payload);
  }

  public static function recallFileHash(
    Page $page,
    ?string $fieldName = null,
  ): ?string {
    $field = self::hashFieldName($page, $fieldName);
    // Prefer a persisted page field value (if present); fall back to session value.
    try {
      if ($page->hasField($field)) {
        $persisted = $page->get($field);
        if (
          is_scalar($persisted) ||
          $persisted === null ||
          (is_object($persisted) && method_exists($persisted, '__toString'))
        ) {
          $str = $persisted === null ? null : (string) $persisted;
          if ($str !== null && $str !== '') {
            return $str;
          }
        }
      }
    } catch (\Throwable $_e) {
      // ignore and fallback to session
    }

    $value = $page->wire('session')->get(self::sessionKey($page, $field));

    if (is_array($value)) {
      return self::encodeHashPayload($page, $value);
    }

    return $value === null ? null : (string) $value;
  }

  public static function recallFileHashMap(
    Page $page,
    ?string $fieldName = null,
  ): array {
    $payload = self::recallFileHash($page, $fieldName);
    return self::decodeHashPayload($page, $payload);
  }

  public static function getHashFieldName(
    Page $page,
    ?string $fieldName = null,
  ): string {
    $field = self::hashFieldName($page, $fieldName);
    return sprintf('_markdown_hash_%d_%s', (int) $page->id, $field);
  }

  public static function getMarkdownField(Page $page): ?string
  {
    $config = self::config($page);
    return $config['markdownField'] ?? null;
  }

  public static function getHtmlField(Page $page): ?string
  {
    $config = self::config($page);
    return $config['htmlField'] ?? null;
  }

  public static function getHashField(Page $page): ?string
  {
    $config = self::config($page);
    return $config['hashField'] ?? null;
  }

  public static function getFrontmatterMap(Page $page): array
  {
    $config = self::config($page);
    return $config['frontmatter'] ?? [];
  }

  public static function getDefaultLanguageCode(Page $page): string
  {
    $languages = $page->wire('languages');
    if ($languages) {
      $default = $languages->getDefault();
      if ($default instanceof Language) {
        $code = self::languageCodeFromLanguage(
          $default,
          self::FALLBACK_LANGUAGE,
        );
        if ($code !== '') {
          return $code;
        }
      }
    }

    return self::FALLBACK_LANGUAGE;
  }

  protected static function getDefaultLanguage(Page $page): ?Language
  {
    $languages = $page->wire('languages');
    if (!$languages) {
      return null;
    }

    $default = $languages->getDefault();
    return $default instanceof Language ? $default : null;
  }

  public static function storePendingBody(
    Page $page,
    $body,
    ?string $fieldName = null,
  ): void {
    $field = self::markdownFieldName($page, $fieldName);
    $values = self::collectLanguageFieldValues($page, $field);
    $defaultCode = self::getDefaultLanguageCode($page);

    if (is_array($body)) {
      $values = array_merge(
        $values,
        self::normalizePendingLanguageMap($page, $body),
      );
    } else {
      $values[$defaultCode] = self::stringifyPendingFieldValue($body);
    }

    $page->wire('session')->set(self::pendingBodyKey($page, $field), $values);
  }

  public static function consumePendingBody(
    Page $page,
    ?string $fieldName = null,
  ): ?array {
    $field = self::markdownFieldName($page, $fieldName);
    $key = self::pendingBodyKey($page, $field);
    $session = $page->wire('session');

    $value = $session->get($key);
    if ($value === null) {
      return null;
    }

    $session->remove($key);
    if (is_array($value)) {
      $normalized = self::normalizePendingLanguageMap($page, $value);
      return $normalized ? $normalized : null;
    }

    $defaultCode = self::getDefaultLanguageCode($page);
    return [$defaultCode => (string) $value];
  }

  public static function storePendingFields(Page $page, array $values): void
  {
    if (!$values) {
      return;
    }

    $map = self::fieldMap($page);
    $htmlField = self::getHtmlField($page);

    if (!$map && !$htmlField && !array_key_exists('title', $values)) {
      return;
    }

    $pending = [];
    $defaultCode = self::getDefaultLanguageCode($page);

    $allowedFields = $map;
    if ($htmlField) {
      $allowedFields[$htmlField] = null;
    }

    if (array_key_exists('title', $values) && !isset($allowedFields['title'])) {
      $allowedFields['title'] = 'title';
    }

    if (array_key_exists('name', $values) && !isset($allowedFields['name'])) {
      $allowedFields['name'] = 'name';
    }

    foreach ($allowedFields as $field => $_frontKey) {
      $fieldName = (string) $field;
      if ($fieldName === '') {
        continue;
      }

      $isHtmlField = $htmlField && $fieldName === $htmlField;

      if (!$isHtmlField && !self::pageSupportsMappedField($page, $fieldName)) {
        continue;
      }

      if (!array_key_exists($fieldName, $values)) {
        continue;
      }

      $value = $values[$fieldName];
      $languageValues = self::collectLanguageFieldValues($page, $fieldName);

      if ($htmlField && $fieldName === $htmlField && is_string($value)) {
        $value = self::editorPlaceholdersToComments($value);
      }

      if (is_array($value)) {
        $languageValues = array_merge(
          $languageValues,
          self::normalizePendingLanguageMap($page, $value),
        );
      } elseif (is_scalar($value) || $value === null) {
        $scalarValue = $value === null ? '' : (string) $value;
        if ($fieldName === 'name') {
          $scalarValue = self::sanitizePageNameValue($page, $scalarValue);
        }
        $languageValues[$defaultCode] = $scalarValue;
      } else {
        continue;
      }

      if (!$languageValues) {
        continue;
      }

      $pending[$fieldName] = $languageValues;
    }

    if (!$pending) {
      return;
    }

    $page->wire('session')->set(self::pendingFieldsKey($page), $pending);
  }

  public static function consumePendingFields(Page $page): array
  {
    $session = $page->wire('session');
    $key = self::pendingFieldsKey($page);
    $stored = $session->get($key);

    $session->remove($key);

    if (!is_array($stored)) {
      return [];
    }

    $pending = [];
    $defaultCode = self::getDefaultLanguageCode($page);

    foreach ($stored as $field => $value) {
      $fieldName = (string) $field;
      if ($fieldName === '') {
        continue;
      }

      if (is_array($value)) {
        $normalized = self::normalizePendingLanguageMap($page, $value);
        if ($normalized) {
          $pending[$fieldName] = $normalized;
        }
        continue;
      }

      if (!is_scalar($value) && $value !== null) {
        continue;
      }

      $pending[$fieldName] = [
        $defaultCode => $value === null ? '' : (string) $value,
      ];
    }

    return $pending;
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

  protected static function sessionKey(Page $page, string $fieldName): string
  {
    return self::SESSION_NAMESPACE . '_' . (int) $page->id . '_' . $fieldName;
  }

  protected static function pendingBodyKey(
    Page $page,
    string $fieldName,
  ): string {
    return self::SESSION_PENDING_BODY .
      '_' .
      (int) $page->id .
      '_' .
      $fieldName;
  }

  protected static function pendingFieldsKey(Page $page): string
  {
    return self::SESSION_PENDING_FIELDS . '_' . (int) $page->id;
  }

  protected static function languageCodeFromLanguage(
    Language $language,
    string $fallback,
  ): string {
    $locale = $language->getLocale();
    if ($locale) {
      return strtolower(substr($locale, 0, 2));
    }

    $code = (string) ($language->code ?? '');
    if ($code !== '' && $code !== 'default') {
      return $code;
    }

    $name = (string) ($language->name ?? '');
    if ($name !== '' && $name !== 'default') {
      return $name;
    }

    return $fallback;
  }

  protected static function redirectToDefaultLanguage(
    Page $page,
    string $languageCode,
  ): void {
    $defaultCode = self::getDefaultLanguageCode($page);
    if ($languageCode === $defaultCode) {
      return;
    }

    $languages = $page->wire('languages');
    if (!$languages) {
      return;
    }

    $default = $languages->getDefault();
    if (!$default instanceof Language) {
      return;
    }

    $url = $page->localUrl($default);
    if ($url) {
      $page->wire('session')->redirect($url, false);
    }
  }

  protected static function ensureDirectory(string $path): void
  {
    $directory = dirname($path);
    if (!is_dir($directory)) {
      wire('files')->mkdir($directory, true);
    }
  }

  protected static function markdownToHtml(
    string $markdown,
    ?Page $page = null,
  ): string {
    if ($markdown === '') {
      return '';
    }

    $html = self::parsedown()->text($markdown);

    if ($page) {
      $config = self::config($page);
      $baseUrl = is_array($config)
        ? $config['assets']['imageBaseUrl'] ?? null
        : null;
      if ($baseUrl) {
        $html = self::applyImageBaseUrl($html, $baseUrl);
      }
    }

    return $html;
  }

  protected static function htmlToMarkdown(
    string $html,
    ?Page $page = null,
  ): string {
    if ($html === '') {
      return '';
    }

    if ($page) {
      $config = self::config($page);
      $baseUrl = is_array($config)
        ? $config['assets']['imageBaseUrl'] ?? null
        : null;
      if ($baseUrl) {
        $html = self::stripImageBaseUrl($html, $baseUrl);
      }
    }

    $html = self::editorPlaceholdersToComments($html);

    [$prepared, $comments] = self::replaceCommentsForConversion($html);
    [$prepared, $inlineTokens] = self::protectInlineHtml($prepared);

    $markdown = self::htmlConverter()->convert($prepared);
    $markdown = self::restoreCommentsAfterConversion($markdown, $comments);
    $markdown = self::restoreInlineHtml($markdown, $inlineTokens);

    return self::normalizeMarkdownBody($markdown);
  }

  public static function commentsToEditorPlaceholders(string $html): string
  {
    if ($html === '') {
      return '';
    }

    return preg_replace_callback(
      '/<!--(.*?)-->/s',
      function ($match) {
        $raw = $match[0];
        $label = self::formatCommentLabel((string) $match[1]);
        $encoded = base64_encode($raw);

        $dataAttr = htmlspecialchars(
          $encoded,
          ENT_QUOTES | ENT_SUBSTITUTE,
          'UTF-8',
        );

        $labelText = htmlspecialchars(
          $label,
          ENT_QUOTES | ENT_SUBSTITUTE,
          'UTF-8',
        );

        $classes = [self::COMMENT_PLACEHOLDER_CLASS];
        $typeClass = self::commentPlaceholderTypeClass((string) $match[1]);
        if ($typeClass !== '') {
          $classes[] = $typeClass;
        }

        return sprintf(
          '<p class="%s" data-md-comment="%s" data-mce-noneditable="true" contenteditable="false"><span>%s</span></p>',
          implode(' ', $classes),
          $dataAttr,
          $labelText,
        );
      },
      $html,
    );
  }

  public static function editorPlaceholdersToComments(string $html): string
  {
    if ($html === '') {
      return '';
    }

    $pattern =
      '/<(?P<tag>[a-zA-Z0-9]+)[^>]*data-md-comment\s*=\s*(?:"(?P<data_dq>[^"]+)"|\'(?P<data_sq>[^\']+)\'|(?P<data_unquoted>[^\s>]+))[^>]*>.*?<\/(?P=tag)>/is';

    return preg_replace_callback(
      $pattern,
      function (array $match): string {
        $dataValue = $match['data_dq'] ?? '';
        if ($dataValue === '' && !empty($match['data_sq'])) {
          $dataValue = $match['data_sq'];
        }
        if ($dataValue === '' && !empty($match['data_unquoted'])) {
          $dataValue = $match['data_unquoted'];
        }

        $encoded = html_entity_decode(
          $dataValue,
          ENT_QUOTES | ENT_SUBSTITUTE,
          'UTF-8',
        );

        $raw = base64_decode($encoded, true);
        if ($raw === false) {
          $decodedLabel = trim($encoded);
          if ($decodedLabel === '') {
            return '';
          }

          return "\n<!-- " . $decodedLabel . " -->\n";
        }

        return "\n" . $raw . "\n";
      },
      $html,
    );
  }

  public static function applyEditorPlaceholdersToInputfield(
    Inputfield $inputfield,
  ): void {
    if ($inputfield instanceof InputfieldWrapper) {
      foreach ($inputfield as $child) {
        if ($child instanceof Inputfield) {
          self::applyEditorPlaceholdersToInputfield($child);
        }
      }
      return;
    }

    $value = $inputfield->value;
    if (!is_string($value) || $value === '') {
      $attrValue = $inputfield->attr('value');
      $value = is_string($attrValue) ? $attrValue : (string) $attrValue;
    }

    if (!is_string($value) || $value === '') {
      return;
    }

    $prepared = self::commentsToEditorPlaceholders($value);
    if ($prepared === $value) {
      return;
    }

    $inputfield->value = $prepared;
    $inputfield->attr('value', $prepared);
  }

  protected static function replaceCommentsForConversion(string $html): array
  {
    $comments = [];

    $prepared = preg_replace_callback(
      '/<!--.*?-->/s',
      function ($match) use (&$comments) {
        $index = count($comments);
        $comments[$index] = $match[0];
        return "<md-comment data-md-index=\"{$index}\"></md-comment>";
      },
      $html,
    );

    return [$prepared ?? $html, $comments];
  }

  protected static function restoreCommentsAfterConversion(
    string $markdown,
    array $comments,
  ): string {
    if (!$comments) {
      return $markdown;
    }

    return preg_replace_callback(
      '/<md-comment data-md-index="(\d+)"><\/md-comment>/',
      function ($match) use ($comments) {
        $index = (int) ($match[1] ?? 0);
        $comment = $comments[$index] ?? '';
        if ($comment === '') {
          return '';
        }

        return "\n" . trim($comment) . "\n";
      },
      $markdown,
    );
  }

  protected static function normalizeMarkdownBody(string $markdown): string
  {
    if ($markdown === '') {
      return '';
    }

    $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
    $markdown = str_replace('&nbsp;', "\n\n", $markdown);
    $markdown = str_replace("\xc2\xa0", "\n\n", $markdown);
    $markdown = preg_replace('/(<!--.*?-->)/s', "\n$1\n", $markdown);
    $markdown = preg_replace('/([^\n])\n<!--/s', "$1\n\n<!--", $markdown);
    $markdown = preg_replace(
      '/(<!--.*?-->)\n(?!\n|<!--)/s',
      "$1\n\n",
      $markdown,
    );

    $markdown = self::tidyMarkdownSpacing($markdown);

    return trim($markdown, "\n");
  }

  protected static function tidyMarkdownSpacing(string $markdown): string
  {
    if ($markdown === '') {
      return '';
    }

    $markdown = preg_replace('/^[ \t]+$/m', '', $markdown);
    $markdown = preg_replace("/\n{3,}/", "\n\n", $markdown);
    $markdown = preg_replace("/\n{2,}(<!--.*?-->)/s", "\n\n$1", $markdown);
    $markdown = preg_replace("/(<!--.*?-->)\n{2,}/s", "$1\n\n", $markdown);

    return $markdown;
  }

  /**
   * Normalize a full markdown document (frontmatter + body) for comparisons.
   * This keeps canonical formatting and normalizes whitespace, frontmatter order,
   * and body spacing using existing compose/parse helpers.
   */
  protected static function normalizeDocumentForComparison(
    Page $page,
    string $document,
  ): string {
    if ($document === null || $document === '') {
      return '';
    }

    [$frontRaw, $body] = self::splitDocument($document);
    $bodyNorm = self::normalizeMarkdownBody($body);
    $front = [];
    if ($frontRaw !== '') {
      $front = self::parseFrontmatterRaw($frontRaw);
    }

    if (is_array($front) && $front) {
      ksort($front);
    }

    $result = self::composeDocument($front, $bodyNorm);
    return rtrim($result, "\r\n") . "\n";
  }

  /**
   * Normalize HTML for string comparison to minimize spurious differences
   * caused by whitespace or EOLs. This is intentionally conservative.
   */
  protected static function normalizeHtmlForComparison(string $html): string
  {
    if ($html === null || $html === '') {
      return '';
    }

    $s = str_replace(["\r\n", "\r"], "\n", $html);
    $s = trim($s);
    // Collapse all whitespace runs into a single space to avoid trivial diffs
    $s = preg_replace('/\s+/', ' ', $s);
    return (string) $s;
  }

  protected static function protectInlineHtml(string $html): array
  {
    $tokens = [];
    $index = 0;

    $protected = preg_replace_callback(
      '/<br\s*\/?\s*>/i',
      function (array $match) use (&$tokens, &$index) {
        $tokens[$index] = $match[0];
        $placeholder = sprintf(
          '<md-inline data-md-token="%d"></md-inline>',
          $index,
        );
        $index++;
        return $placeholder;
      },
      $html,
    );

    return [$protected ?? $html, $tokens];
  }

  protected static function restoreInlineHtml(
    string $markdown,
    array $tokens,
  ): string {
    if ($tokens) {
      $markdown =
        preg_replace_callback(
          '/<md-inline data-md-token="(\d+)"><\/md-inline>/',
          function (array $match) use ($tokens) {
            $index = (int) ($match[1] ?? -1);
            return $tokens[$index] ?? '';
          },
          $markdown,
        ) ?? $markdown;
    }

    $decoded = html_entity_decode($markdown, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $markdown = self::escapeLooseAngleBrackets($decoded);

    return $markdown;
  }

  protected static function escapeLooseAngleBrackets(string $markdown): string
  {
    if ($markdown === '') {
      return '';
    }

    $length = strlen($markdown);
    $result = '';
    $index = 0;

    while ($index < $length) {
      $char = $markdown[$index];

      if ($char === '<') {
        $matchedTag = self::matchHtmlTagAt($markdown, $index);

        if ($matchedTag !== null) {
          $result .= $matchedTag['text'];
          $index = $matchedTag['end'] + 1;
          continue;
        }

        $result .= '&lt;';
        $index++;
        continue;
      }

      if ($char === '>') {
        $result .= '&gt;';
        $index++;
        continue;
      }

      $result .= $char;
      $index++;
    }

    return $result;
  }

  protected static function matchHtmlTagAt(string $source, int $offset): ?array
  {
    if ($offset < 0 || $offset >= strlen($source) || $source[$offset] !== '<') {
      return null;
    }

    $substring = substr($source, $offset);
    if ($substring === '') {
      return null;
    }

    $patterns = [
      '/^<!--.*?-->/s',
      '/^<!\[CDATA\[.*?\]\]>/s',
      '/^<!DOCTYPE[^>]*>/i',
      '/^<\?[\s\S]*?\?>/',
      '/^<\s*\/?[A-Za-z][\w:-]*(?:\s+(?:"[^"]*"|\'[^\']*\'|[^\'">]+))*\s*\/?>(?:\s*)/u',
    ];

    foreach ($patterns as $pattern) {
      if (preg_match($pattern, $substring, $match)) {
        return [
          'text' => $match[0],
          'end' => $offset + strlen($match[0]) - 1,
        ];
      }
    }

    return null;
  }

  protected static function commentPlaceholderTypeClass(string $comment): string
  {
    $text = strtolower(trim($comment));
    if ($text === '') {
      return '';
    }

    if ($text === '/' || str_starts_with($text, '/')) {
      return self::COMMENT_PLACEHOLDER_CLASS . '--close';
    }

    $prefix = $text;
    if (strpos($text, ':') !== false) {
      $prefix = substr($text, 0, strpos($text, ':'));
    }

    switch ($prefix) {
      case 'section':
        return self::COMMENT_PLACEHOLDER_CLASS . '--section';
      case 'sub':
      case 'subsection':
        return self::COMMENT_PLACEHOLDER_CLASS . '--sub';
      case 'field':
        return self::COMMENT_PLACEHOLDER_CLASS . '--field';
      default:
        return self::COMMENT_PLACEHOLDER_CLASS . '--field';
    }
  }

  protected static function applyImageBaseUrl(
    string $html,
    string $baseUrl,
  ): string {
    $normalizedBase = self::normalizeUrlBase($baseUrl);
    if (!$normalizedBase) {
      return $html;
    }

    return preg_replace_callback(
      "/(<img\\b[^>]*\\bsrc\\s*=\\s*)(['\"])([^'\"]+)(\\2)/i",
      function (array $match) use ($normalizedBase) {
        $prefix = $match[1];
        $quote = $match[2];
        $src = trim($match[3]);

        if (
          $src === '' ||
          preg_match('#^(?:[a-z][a-z0-9+.-]*:|//|/)#i', $src) ||
          strpos($src, '../') === 0 ||
          strpos($src, $normalizedBase) === 0
        ) {
          return $match[0];
        }

        $relative = preg_replace('#^\./#', '', $src);
        if ($relative === '') {
          return $match[0];
        }

        if (strpos($relative, '/') !== false) {
          return $match[0];
        }

        $resolved = $normalizedBase . ltrim($relative, '/');

        return $prefix . $quote . $resolved . $quote;
      },
      $html,
    ) ?? $html;
  }

  protected static function stripImageBaseUrl(
    string $html,
    string $baseUrl,
  ): string {
    $normalizedBase = self::normalizeUrlBase($baseUrl);
    if (!$normalizedBase) {
      return $html;
    }

    $pattern = sprintf(
      "/(<img\\b[^>]*\\bsrc\\s*=\\s*)(['\"])%s([^'\"]+)(\\2)/i",
      preg_quote($normalizedBase, '/'),
    );

    return preg_replace_callback(
      $pattern,
      function (array $match) {
        $prefix = $match[1];
        $quote = $match[2];
        $relative = $match[3];

        return $prefix . $quote . $relative . $quote;
      },
      $html,
    ) ?? $html;
  }

  protected static function parsedown(): Parsedown
  {
    if (!self::$parsedown) {
      self::$parsedown = new Parsedown();
    }

    return self::$parsedown;
  }

  protected static function htmlConverter(): HtmlConverter
  {
    if (!self::$htmlConverter) {
      self::$htmlConverter = new HtmlConverter([
        'remove_nodes' => 'head script style',
        'header_style' => 'atx',
        'strip_placeholder_tags' => false,
      ]);
    }

    return self::$htmlConverter;
  }

  protected static function formatCommentLabel(string $comment): string
  {
    $label = trim(preg_replace('/\s+/', ' ', $comment));
    if ($label === '') {
      $label = 'comment';
    }

    if (strlen($label) > 48) {
      $label = substr($label, 0, 45) . '…';
    }

    return '[' . $label . ']';
  }

  protected static function applyFrontmatter(
    Page $page,
    ContentData $content,
    ?Language $language = null,
  ): array {
    $frontmatter = $content->getFrontmatter();
    if (!is_array($frontmatter) || !$frontmatter) {
      return [];
    }

    return self::applyFrontmatterFields(
      $page,
      $frontmatter,
      false,
      null,
      $language,
    );
  }

  public static function syncFieldsFromMarkdown(
    Page $page,
    string $document,
  ): array {
    if (!self::supportsPage($page)) {
      return [];
    }

    $map = self::fieldMap($page);

    if (!$map) {
      return [];
    }

    [$frontRaw, $bodyContent] = self::splitDocument($document);
    $frontmatter = $frontRaw !== '' ? self::parseFrontmatterRaw($frontRaw) : [];

    $page->of(false);

    if (!is_array($frontmatter) || !$frontmatter) {
      return [];
    }

    return self::applyFrontmatterFields($page, $frontmatter, true, $map);
  }

  protected static function fieldMap(Page $page): array
  {
    $config = self::config($page);
    return $config['frontmatter'] ?? [];
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

  protected static function applyFrontmatterFields(
    Page $page,
    array $frontmatter,
    bool $respectChanges,
    ?array $map = null,
    ?Language $language = null,
  ): array {
    $map ??= self::fieldMap($page);
    if (!$map) {
      return [];
    }

    $updated = [];

    foreach ($map as $field => $frontKey) {
      if (!self::pageSupportsMappedField($page, $field)) {
        continue;
      }

      if (!array_key_exists($frontKey, $frontmatter)) {
        continue;
      }

      $value = $frontmatter[$frontKey];
      if (!is_scalar($value) && $value !== null && !is_array($value)) {
        continue;
      }

      if (is_array($value)) {
        $pageValue = $value;
      } else {
        $pageValue = $value === null ? '' : (string) $value;
        if ($field === 'name') {
          $pageValue = self::sanitizePageNameValue($page, $pageValue);
        }
      }

      $changedViaForm =
        $respectChanges && self::fieldChangedViaForm($page, $field);

      if ($changedViaForm) {
        self::logDebug(
          $page,
          sprintf(
            'skip field %s for %s: changed via form',
            $field,
            self::languageLogLabel($page, $language),
          ),
        );
        continue;
      }

      $currentValue = self::getFieldValueForLanguage($page, $field, $language);

      if ($field === 'name') {
        self::logDebug($page, 'applyFrontmatterFields name', [
          'language' => self::languageLogLabel($page, $language),
          'frontmatterValue' => $pageValue,
          'currentValue' => $currentValue,
          'respectChanges' => $respectChanges,
          'changedViaForm' => $changedViaForm,
        ]);

        // Log path change if name will change
        if ((string) $currentValue !== (string) $pageValue) {
          $oldPath = $page->path;
          $parentPath = rtrim(dirname($oldPath), '/');
          $newPath = $parentPath . '/' . $pageValue . '/';
          self::logInfo($page, 'name field changing', [
            'from' => $currentValue,
            'to' => $pageValue,
            'oldPath' => $oldPath,
            'newPath' => $newPath,
          ]);
        }
      }

      if (is_array($pageValue)) {
        if ($currentValue === $pageValue) {
          self::logDebug(
            $page,
            sprintf(
              'skip field %s for %s: array unchanged',
              $field,
              self::languageLogLabel($page, $language),
            ),
          );
          continue;
        }
      } elseif ((string) $currentValue === (string) $pageValue) {
        self::logDebug(
          $page,
          sprintf(
            'skip field %s for %s: value unchanged',
            $field,
            self::languageLogLabel($page, $language),
          ),
        );
        continue;
      }

      self::setFieldValueForLanguage($page, $field, $pageValue, $language);

      // Log path change after setting name field
      if ($field === 'name') {
        $newPath = $page->path;
        $parentPath = rtrim(dirname($oldPath ?? ''), '/');
        $expectedPath = $parentPath . '/' . $pageValue . '/';
        if ($oldPath !== $newPath) {
          self::logInfo(
            $page,
            sprintf('path updated: %s → %s', $oldPath, $newPath),
            [
              'field' => 'name',
              'value' => $pageValue,
            ],
          );
        }
      }

      // Uncomment for deep debugging to see all field assignments:
      // self::logDebug(
      //   $page,
      //   sprintf(
      //     'set field %s for %s',
      //     $field,
      //     self::languageLogLabel($page, $language),
      //   ),
      //   ['value' => self::summarizeValue($pageValue)],
      // );
      $updated[] = $field;
    }

    return $updated;
  }

  protected static function isDefaultLanguage(
    Page $page,
    ?Language $language,
  ): bool {
    if ($language === null) {
      return true;
    }

    $default = self::getDefaultLanguage($page);
    if (!$default instanceof Language) {
      return false;
    }

    return (int) $default->id === (int) $language->id;
  }

  protected static function getFieldValueForLanguage(
    Page $page,
    string $field,
    ?Language $language = null,
  ) {
    // Get default language if none specified or if specified language is the default
    $isDefault =
      $language === null || self::isDefaultLanguage($page, $language);

    // For default language or no language specified
    if ($isDefault) {
      if ($language instanceof Language) {
        // Explicitly get the value for the default language
        $fieldName = $field === 'title' ? 'title' : $field;
        if ($page->hasField($fieldName) || $fieldName === 'title') {
          // Use getUnformatted with language ID appended
          $languageFieldName = $fieldName . $language->id;
          $val = $page->getUnformatted($languageFieldName);
          if (is_array($val)) {
            $defaultCode = self::getDefaultLanguageCode($page);
            if (isset($val[$defaultCode]) && is_scalar($val[$defaultCode])) {
              return (string) $val[$defaultCode];
            }
            foreach ($val as $v) {
              if (is_scalar($v)) return (string) $v;
            }
            return '';
          }
          return (string) $val;
        }
      }

      $val = $field === 'title' ? $page->get('title') : $page->get($field);
      if (is_array($val)) {
        $defaultCode = self::getDefaultLanguageCode($page);
        if (isset($val[$defaultCode]) && is_scalar($val[$defaultCode])) {
          return (string) $val[$defaultCode];
        }
        foreach ($val as $v) {
          if (is_scalar($v)) return (string) $v;
        }
        return '';
      }

      return (string) $val;
    }

    // For non-default languages
    if ($field === 'name' && $language instanceof Language) {
      $localized = self::localizePageName($page, $language);
      if ($localized !== null) {
        return $localized;
      }

      return $page->get('name');
    }

    // Try ProcessWire's standard language field access: fieldname + language ID
    if ($language instanceof Language) {
      $languageFieldName = $field . $language->id;

      if ($field === 'title') {
        return (string) $page->getUnformatted($languageFieldName);
      }

      if ($page->hasField($field)) {
        $value = $page->getUnformatted($languageFieldName);
        return (string) $value;
      }
    }

    return (string) $page->get($field);
  }

  protected static function setFieldValueForLanguage(
    Page $page,
    string $field,
    $value,
    ?Language $language = null,
  ): void {
    $languageLabel = self::languageLogLabel($page, $language);

    if ($field === 'name' && !is_array($value)) {
      $value = self::sanitizePageNameValue($page, $value);
    }

    if ($language === null || self::isDefaultLanguage($page, $language)) {
      if ($field === 'title') {
        $page->set('title', $value);
      } else {
        $page->set($field, $value);
      }
      // Uncomment for deep debugging to see all field writes:
      // self::logDebug(
      //   $page,
      //   sprintf('write %s (%s) default context', $field, $languageLabel),
      //   [
      //     'len' => self::valueLength($value),
      //     'value' => self::summarizeValue($value),
      //   ],
      // );
      return;
    }

    if (!self::pageSupportsMappedField($page, $field)) {
      self::logDebug(
        $page,
        sprintf(
          'skip writing %s (%s): field missing on template',
          $field,
          $languageLabel,
        ),
      );
      return;
    }

    $written = false;

    if (method_exists($page, 'setLanguageValue')) {
      if ($field === 'title') {
        $page->setLanguageValue($language, 'title', $value);
      } elseif ($field === 'name') {
        $page->setLanguageValue($language, 'name', $value);
      } else {
        $page->setLanguageValue($language, $field, $value);
      }
      $written = true;
    }

    if (!$written && $language instanceof Language) {
      $written = self::writeLanguageFieldValueFallback(
        $page,
        $field,
        $value,
        $language,
      );
    }

    if ($written) {
      self::logDebug(
        $page,
        sprintf('write %s (%s) language value', $field, $languageLabel),
        [
          'len' => self::valueLength($value),
          'value' => self::summarizeValue($value),
        ],
      );
      return;
    }

    self::logDebug(
      $page,
      sprintf(
        'skip writing %s (%s): language API unavailable',
        $field,
        $languageLabel,
      ),
    );
  }

  protected static function writeLanguageFieldValueFallback(
    Page $page,
    string $field,
    $value,
    Language $language,
  ): bool {
    $fieldValue = $field === 'title' ? $page->get('title') : $page->get($field);

    if ($field === 'name' && $language instanceof Language) {
      $page->set(self::languagePageNameColumn($language), $value);
      return true;
    }

    if (
      is_object($fieldValue) &&
      method_exists($fieldValue, 'setLanguageValue')
    ) {
      $fieldValue->setLanguageValue($language, $value);

      if ($field === 'title') {
        $page->set('title', $fieldValue);
      } else {
        $page->set($field, $fieldValue);
      }

      return true;
    }

    return false;
  }

  protected static function localizePageName(
    Page $page,
    Language $language,
  ): ?string {
    if (method_exists($page, 'getLanguageValue')) {
      try {
        $value = $page->getLanguageValue($language, 'name');
        if (is_string($value) && $value !== '') {
          return $value;
        }
      } catch (\Throwable $exception) {
        // fall through to other strategies
      }
    }

    if (method_exists($page, 'localName')) {
      $localized = $page->localName($language, true);
      if (is_string($localized) && $localized !== '') {
        return $localized;
      }
    }

    $column = self::languagePageNameColumn($language);
    $columnValue = $page->get($column);
    if (is_string($columnValue) && $columnValue !== '') {
      return $columnValue;
    }

    return null;
  }

  protected static function languagePageNameColumn(Language $language): string
  {
    return 'name' . (int) $language->id;
  }

  protected static function extractLanguageValueFromArray(array $value)
  {
    $candidates = ['data', 'value', 'text', 'body', 'markup'];

    foreach ($candidates as $key) {
      if (array_key_exists($key, $value)) {
        $candidate = $value[$key];
        if (
          is_scalar($candidate) ||
          $candidate === null ||
          (is_object($candidate) && method_exists($candidate, '__toString'))
        ) {
          return $candidate;
        }
      }
    }

    if (count($value) === 1) {
      $first = reset($value);
      if (
        is_scalar($first) ||
        $first === null ||
        (is_object($first) && method_exists($first, '__toString'))
      ) {
        return $first;
      }
    }

    return null;
  }

  protected static function fieldChangedViaForm(Page $page, string $field): bool
  {
    if (self::isCoreMappedField($field)) {
      return $page->isChanged($field);
    }

    return $page->hasField($field) ? $page->isChanged($field) : false;
  }

  protected static function frontmatterValue(
    Page $page,
    string $field,
    ?Language $language = null,
  ) {
    $value = self::getFieldValueForLanguage($page, $field, $language);

    if ($value instanceof \DateTimeInterface) {
      return $value->format('c');
    }

    if ($value instanceof WireArray) {
      $items = [];
      foreach ($value as $item) {
        $items[] = self::stringifyValue($item);
      }
      return $items;
    }

    if ($value instanceof Page) {
      return (string) $value->id;
    }

    if (is_object($value) && method_exists($value, '__toString')) {
      return (string) $value;
    }

    if (is_scalar($value) || $value === null) {
      return $value;
    }

    return (string) $value;
  }

  protected static function mappedFieldsChanged(Page $page, array $fields): bool
  {
    foreach ($fields as $field) {
      if ($field === '') {
        continue;
      }

      if (
        self::pageSupportsMappedField($page, $field) &&
        $page->isChanged($field)
      ) {
        return true;
      }
    }

    return false;
  }

  protected static function logDebug(
    ?Page $page,
    string $message,
    array $context = [],
  ): void {
    $config = wire('config');
    $enabled = false;

    if ($config) {
      $flag = self::DEBUG_CONFIG_FLAG;
      if (isset($config->$flag)) {
        $enabled = (bool) $config->$flag;
      }
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

  protected static function languageLogLabel(
    Page $page,
    ?Language $language,
  ): string {
    if (!$language instanceof Language) {
      return self::getDefaultLanguageCode($page) . '(default)';
    }

    return self::determineLanguageCode($page, $language);
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

  protected static function documentHasContent(
    array $frontmatter,
    string $bodyContent,
  ): bool {
    if (trim($bodyContent) !== '') {
      return true;
    }

    foreach ($frontmatter as $value) {
      if (self::valueHasContent($value)) {
        return true;
      }
    }

    return false;
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

  protected static function normalizeFrontmatterAssignmentValue($value)
  {
    if ($value instanceof \DateTimeInterface) {
      return $value->format('c');
    }

    if ($value instanceof WireArray) {
      $normalized = [];
      foreach ($value as $item) {
        $normalized[] = self::normalizeFrontmatterAssignmentValue($item);
      }
      return $normalized;
    }

    if (is_array($value)) {
      $normalized = [];
      foreach ($value as $key => $item) {
        $normalized[$key] = self::normalizeFrontmatterAssignmentValue($item);
      }
      return $normalized;
    }

    if (is_object($value) && method_exists($value, '__toString')) {
      return (string) $value;
    }

    if (is_scalar($value) || $value === null) {
      return $value === null ? '' : (string) $value;
    }

    return self::stringifyValue($value);
  }

  protected static function frontmatterValuesDiffer($a, $b): bool
  {
    return self::frontmatterComparableValue($a) !==
      self::frontmatterComparableValue($b);
  }

  protected static function frontmatterChangeDetected(
    $previous,
    $candidate,
  ): bool {
    if ($candidate === null) {
      return false;
    }

    if ($previous === null) {
      return self::valueHasContent($candidate);
    }

    return self::frontmatterValuesDiffer($previous, $candidate);
  }

  protected static function frontmatterComparableValue($value): string
  {
    if ($value instanceof WireArray) {
      $normalized = [];
      foreach ($value as $item) {
        $normalized[] = self::frontmatterComparableValue($item);
      }
      return json_encode($normalized) ?: '';
    }

    if ($value instanceof \DateTimeInterface) {
      return $value->format('c');
    }

    if (is_array($value)) {
      $normalized = [];
      foreach ($value as $key => $item) {
        $normalized[$key] = self::frontmatterComparableValue($item);
      }
      ksort($normalized);
      return json_encode($normalized) ?: '';
    }

    if (is_object($value) && method_exists($value, '__toString')) {
      return (string) $value;
    }

    if (is_scalar($value) || $value === null) {
      return $value === null ? '' : (string) $value;
    }

    return self::stringifyValue($value);
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

  protected static function splitDocument(string $document): array
  {
    $document = ltrim($document, "\xEF\xBB\xBF");

    if (strncmp($document, '---', 3) !== 0) {
      return ['', ltrim($document, "\r\n")];
    }

    $length = strlen($document);
    $cursor = 3;
    $frontLines = [];

    while ($cursor < $length) {
      $newlinePos = strpos($document, "\n", $cursor);
      if ($newlinePos === false) {
        $line = substr($document, $cursor);
        $cursor = $length;
      } else {
        $line = substr($document, $cursor, $newlinePos - $cursor);
        $cursor = $newlinePos + 1;
      }

      $normalizedLine = rtrim($line, "\r");

      if (preg_match('/^\s*---\s*$/', $normalizedLine)) {
        $body = substr($document, $cursor);
        $frontRaw = implode("\n", $frontLines);

        return [rtrim($frontRaw, "\r\n"), ltrim((string) $body, "\r\n")];
      }

      $inlineClosingPos = strpos($line, '---');
      if ($inlineClosingPos !== false) {
        $frontPart = substr($line, 0, $inlineClosingPos);
        $frontPartTrimmed = trim($frontPart);
        if ($frontPartTrimmed !== '') {
          $frontLines[] = $frontPartTrimmed;
        }

        $bodyRemainder = substr($line, $inlineClosingPos + 3);
        $body = $bodyRemainder;
        if ($cursor < $length) {
          $body .= substr($document, $cursor);
        }

        $frontRaw = implode("\n", $frontLines);

        return [rtrim($frontRaw, "\r\n"), ltrim((string) $body, "\r\n")];
      }

      $frontLines[] = ltrim($normalizedLine);
    }

    return ['', ltrim($document, "\r\n")];
  }

  protected static function composeDocument(
    array $frontmatter,
    string $body,
  ): string {
    $frontRaw = self::buildFrontmatterRaw($frontmatter);

    $document = "---\n";
    if ($frontRaw !== '') {
      $document .= $frontRaw . "\n";
    }
    $document .= "---\n";

    $body = ltrim($body, "\r\n");
    if ($body !== '') {
      $document .= "\n" . rtrim($body, "\r\n");
    }

    return rtrim($document, "\r\n") . "\n";
  }

  protected static function parseFrontmatterRaw(?string $raw): array
  {
    if ($raw === null) {
      return [];
    }

    $raw = trim($raw);
    if ($raw === '') {
      return [];
    }

    $lines = preg_split('/\r?\n/', $raw) ?: [];
    $data = [];
    $currentKey = null;

    foreach ($lines as $line) {
      $trimmed = trim($line);
      if ($trimmed === '' || self::startsWith($trimmed, '#')) {
        continue;
      }

      if (preg_match('/^([A-Za-z0-9_\-]+):\s*(.*)$/', $line, $match)) {
        $key = (string) $match[1];
        $value = (string) $match[2];

        if ($value === '') {
          $data[$key] = '';
          $currentKey = $key;
          continue;
        }

        $data[$key] = self::parseFrontmatterScalar($value);
        $currentKey = $key;
        continue;
      }

      if ($currentKey !== null && preg_match('/^\s*-\s*(.*)$/', $line, $item)) {
        if (!isset($data[$currentKey]) || !is_array($data[$currentKey])) {
          $data[$currentKey] = [];
        }

        $data[$currentKey][] = self::parseFrontmatterScalar((string) $item[1]);
        continue;
      }

      $currentKey = null;
    }

    return $data;
  }

  protected static function buildFrontmatterRaw(array $frontmatter): string
  {
    if (!$frontmatter) {
      return '';
    }

    $lines = [];
    foreach ($frontmatter as $key => $value) {
      $frontKey = (string) $key;
      if ($frontKey === '') {
        continue;
      }

      if (is_array($value)) {
        $lines[] = $frontKey . ':';
        foreach ($value as $item) {
          if (!is_scalar($item) && $item !== null) {
            continue;
          }

          $lines[] = '  - ' . self::stringifyScalar($item);
        }
        continue;
      }

      if (is_scalar($value) || $value === null) {
        $lines[] = $frontKey . ': ' . self::stringifyScalar($value);
        continue;
      }

      $lines[] = $frontKey . ': ' . self::stringifyValue($value);
    }

    return implode("\n", $lines);
  }

  protected static function parseFrontmatterScalar(string $value)
  {
    $value = trim($value);
    if ($value === '') {
      return '';
    }

    $lower = strtolower($value);
    if ($lower === 'true' || $lower === 'false') {
      return $lower === 'true';
    }

    if ($lower === 'null') {
      return null;
    }

    if (is_numeric($value)) {
      return strpos($value, '.') !== false ? (float) $value : (int) $value;
    }

    if (
      (self::startsWith($value, '"') && self::endsWith($value, '"')) ||
      (self::startsWith($value, "'") && self::endsWith($value, "'"))
    ) {
      return substr($value, 1, -1);
    }

    return $value;
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
}

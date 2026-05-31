<?php

namespace ProcessWire;

use LetMeDown\ContentData;

/**
 * MarkdownSyncEngine - Core bidirectional synchronization orchestration
 *
 * This class handles the core sync logic for bidirectional markdown ↔ field
 * synchronization, detecting conflicts and determining source of truth.
 */
class MarkdownSyncEngine extends MarkdownSessionManager
{
  /**
   * Pages currently being synced from markdown (prevents recursive hash checks)
   * @var array<int, bool>
   */
  protected static array $syncingFromMarkdown = [];

  /** Syncs page fields from markdown files. */
  protected static function fieldMap(Page $page): array
  {
    $map = MarkdownConfig::getFrontmatterMap($page);
    $map['name'] = 'name';
    return $map;
  }

  public static function syncFromMarkdown(Page $page, bool $save = true): array
  {
    $config = self::config($page);
    if ($config === null) {
      return [];
    }

    self::$syncingFromMarkdown[$page->id] = true;

    try {
      return self::doSyncFromMarkdown($page, $save);
    } finally {
      unset(self::$syncingFromMarkdown[$page->id]);
    }
  }

  private static function doSyncFromMarkdown(Page $page, bool $save = true): array
  {
    $config = self::config($page);
    if ($config === null) {
      return [];
    }

    $page->of(false);

    $markdownField = $config['markdownField'];
    $dirtyFields = self::processLanguagesForSync($page, $markdownField);

    if (!$dirtyFields) {
      return [];
    }

    $failedFields = [];
    $savedFields = self::saveDirtyFields($page, $dirtyFields, $failedFields, $save);

    if ($failedFields) {
      self::handleFailedFields($page, $failedFields);
    }

    return $savedFields;
  }

  private static function processLanguagesForSync(Page $page, string $markdownField): array
  {
    $dirtyFields = [];
    $languageCodes = self::availableLanguageCodes($page);
    $defaultCode = self::getDefaultLanguageCode($page);

    foreach ($languageCodes as $languageCode) {
      $language = self::resolveLanguage($page, $languageCode);
      $isDefaultLanguage = $languageCode === $defaultCode;

      if (!$isDefaultLanguage && !$language instanceof Language) {
        continue;
      }

      $content = self::loadLanguageMarkdown($page, $language);

      // Orphan discovery: If the primary file is missing, look for a file that identifies as this page
      if (!$content instanceof ContentData) {
        $orphanFile = self::findOrphanByFrontmatterName($page, $languageCode);
        if ($orphanFile) {
           $pathAttempted = MarkdownFileIO::getMarkdownFilePath($page, $languageCode);
           // Move the orphan to its canonical home before loading
           if (!is_file($pathAttempted)) {
             if (@rename(dirname($pathAttempted) . '/' . $orphanFile, $pathAttempted)) {
                $msg = sprintf("Moved markdown file '%s' to '%s' to match page rename.", $orphanFile, basename($pathAttempted));
                MarkdownUtilities::sessionMessage($msg);
                $content = self::loadLanguageMarkdown($page, $language); // Load from new canonical path
             }
           }
        }
      }

      if (!$content instanceof ContentData) {
        continue;
      }

      $applied = self::applyFrontmatter($page, $content, $language);
      if ($applied) {
        $dirtyFields = array_merge($dirtyFields, $applied);
      }

      // Sync field bindings in the markdown to match frontmatter values
      // This ensures all instances of a field binding display the current frontmatter value
      $frontmatter = $content->getFrontmatter() ?: [];
      $document = $content->getRawDocument();
      $bodyContent = $content->getMarkdown();

      if (!empty($frontmatter)) {
        $syncedBodyContent = self::syncBindingsToFrontmatter(
          $bodyContent,
          $frontmatter,
          $page,
        );
        if ($syncedBodyContent !== $bodyContent) {
          // Body content changed - compose new document with updated body
          $document = self::composeDocument($frontmatter, $syncedBodyContent);
          $bodyContent = $syncedBodyContent;
          
          // Write synced content back to the markdown file (source)
          self::saveLanguageMarkdown($page, $document, $language);
        }
      }
      $storedMarkdown = (string) self::getFieldValueForLanguage(
        $page,
        $markdownField,
        $language,
      );

      // Preserve authoring style: only write when actual content changes.
      if ((string) $storedMarkdown !== (string) $document) {
        self::setFieldValueForLanguage(
          $page,
          $markdownField,
          $document,
          $language,
        );
        $dirtyFields[] = $markdownField;
      }
    }

    return $dirtyFields;
  }

  private static function saveDirtyFields(Page $page, array $dirtyFields, array &$failedFields, bool $save = true): array
  {
    $savedFields = [];

    foreach (array_unique($dirtyFields) as $field) {
      $supports = self::pageSupportsMappedField($page, $field);
      if (!$supports) {
        continue;
      }

      $oldUrlsByPageId = [];

      try {
        if ($field === 'name' && MarkdownConfig::isLinkSyncEnabled($page)) {
          $oldUrlsByPageId = MarkdownBoundLinks::capturePageTreeUrls(
            $page->wire('pages')->get((int) $page->id),
          );
          MarkdownBoundLinks::persistLinkIndexFromStoredPage($page);
        }

        if ($save) {
          $page->save($field);
        }
        $savedFields[] = $field;

        if ($field === 'name' && MarkdownConfig::isLinkSyncEnabled($page) && $save) {
          MarkdownBoundLinks::refreshReferencesForPageTree($page, $oldUrlsByPageId);
        }
      } catch (\Throwable $e) {
        $failedFields[$field] = $e->getMessage();
      }
    }

    return $savedFields;
  }

  private static function handleFailedFields(Page $page, array $failedFields): void
  {
    if (!$failedFields) {
      return;
    }

    $firstError = reset($failedFields);
    $errorPreview = is_string($firstError)
      ? (strlen($firstError) > 200
        ? substr($firstError, 0, 200) . '...'
        : $firstError)
      : json_encode($firstError);

    $errorMsg = sprintf(
      'Failed to save mapped fields (%s) during markdown sync: %s',
      implode(', ', array_keys($failedFields)),
      $errorPreview,
    );

    throw new WireException($errorMsg);
  }

  /** Syncs page fields to markdown files. */
  public static function syncToMarkdown(
    Page $page,
    ?string $expectedHash = null,
    ?array $languageScope = null,
    array $postedLanguageValues = [],
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
    $postedByField = [];
    if ($postedLanguageValues) {
      foreach ($postedLanguageValues as $fieldName => $values) {
        if (!is_array($values)) {
          continue;
        }
        $postedByField[$fieldName] = $values;
      }
    }

    $postedMarkdownMap = $postedByField[$markdownField] ?? [];
    if ($postedMarkdownMap && !is_array($postedMarkdownMap)) {
      $defaultCode = self::getDefaultLanguageCode($page);
      $postedMarkdownMap = [$defaultCode => (string) $postedMarkdownMap];
    }

    $postedWrittenLanguages = [];
    $deletedPostedLanguages = [];

    $currentHashes = self::languageFileHashes($page, $languageCodes);

    if (!empty($postedMarkdownMap)) {
      foreach ($languageCodes as $languageCode) {
        if (!array_key_exists($languageCode, $postedMarkdownMap)) continue;
        $postedMarkdown = (string) $postedMarkdownMap[$languageCode];

        if ($postedMarkdown === '') {
          self::deleteLanguageMarkdown($page, $languageCode);

          $hash = self::getMarkdownFileHash($page, null, $languageCode);
          self::rememberFileHash($page, [$languageCode => $hash]);

          $page->of(false);
          $page->set($markdownField, [$languageCode => '']);

          $hashField = self::hashFieldName($page, null);
          if ($hashField) {
            $page->set($hashField, self::encodeHashPayload($page, [$languageCode => $hash]));
          }

          $postedWrittenLanguages[] = $languageCode;
          $deletedPostedLanguages[] = $languageCode;
          continue;
        }

        $path = self::getMarkdownFilePath($page, $languageCode);
        $existing = is_file($path) ? file_get_contents($path) : '';

        if ($postedMarkdown !== $existing) {
          self::ensureDirectory($path);

          if (wire('files')->filePutContents($path, $postedMarkdown) === false) {
            throw new WireException(
              sprintf('Unable to write markdown file at %s', $path),
            );
          }

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
        }
      }
    }

    // Skip hash mismatch check if we're currently syncing FROM markdown
    // (the sync operation itself updates both content and hash atomically)
    // Also skip on new pages (no ID yet) where no prior hash can exist.
    $skipHashCheck = !$page->id || isset(self::$syncingFromMarkdown[$page->id]);

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
        }

        if ($hasMarkdownPost) {
          // Proceed with save
        } else {
          $language = self::resolveLanguage($page, $mismatch);
          $label =
            $language instanceof Language
              ? trim((string) ($language->title ?: $language->name))
              : (string) $mismatch;

          $current = $currentHashes[(string)$mismatch] ?? null;
          $expected = $expectedHashes[(string)$mismatch] ?? null;

          // Special case: If the file is missing and we didn't have an expected hash anyway,
          // it's likely a rename or a new page. Let it pass.
          // ALSO: If we just renamed the page, the new path might already exist (moved by handleRenameFiles),
          // so we should be lenient.
          if ($page->isChanged('name') || ($current === 'missing' && ($expected === null || $expected === ''))) {
            // No conflict, allow save to proceed
          } else {
            throw new WireException(
              sprintf(
                __(
                  'The markdown file for language "%s" changed outside this editor. Please reload before saving again.',
                ),
                $label !== '' ? $label : (string) $mismatch,
              ),
            );
          }
        }
      }
    }

    $markdownChanged = $page->isChanged($markdownField);
    $postedMarkdownMap = $postedByField[$markdownField] ?? [];

    $bodyChanged =
      $markdownChanged ||
      !empty($postedMarkdownMap);
    $fieldsChanged = self::mappedFieldsChanged($page, array_keys($map));

    if (!$bodyChanged && !$fieldsChanged) {
      return;
    }

    $defaultCode = self::getDefaultLanguageCode($page);

    foreach ($languageCodes as $languageCode) {
      if (in_array($languageCode, $deletedPostedLanguages, true)) {
        continue;
      }

      $language = self::resolveLanguage($page, $languageCode);
      $isDefaultLanguage = self::isDefaultLanguage($page, $language);

      if (!$isDefaultLanguage && !$language instanceof Language) {
        continue;
      }

      $page->of(false);
      
      $isRenamingPage = $page->isChanged('name') || $page->get('_md_renaming_' . ($language ? $language->id : ''));
      $source = MarkdownFileIO::contentSource($page);
      $path = MarkdownFileIO::getMarkdownFilePath($page, $languageCode, $source);
      
      $languageContent = self::loadLanguageMarkdown($page, $language);
      // Orphan discovery: If the primary file is missing during write, look for an orphan to relocate.
      // This prevents duplicates like roo.md and foo.md existing simultaneously.
      if (!$languageContent instanceof ContentData && !$isRenamingPage) {
        $orphanFile = self::findOrphanByFrontmatterName($page, $languageCode);
        if ($orphanFile) {
          $pathAttempted = MarkdownFileIO::getMarkdownFilePath($page, $languageCode);
          if (!is_file($pathAttempted)) {
            if (@rename(dirname($pathAttempted) . '/' . $orphanFile, $pathAttempted)) {
               $msg = sprintf("Moved markdown file '%s' to '%s' to match page rename.", $orphanFile, basename($pathAttempted));
               MarkdownUtilities::sessionMessage($msg);
               self::logInfo($page, "Relocated orphan file during write sync", [
                 'from' => $orphanFile,
                 'to' => basename($pathAttempted)
               ]);
               // Load the newly relocated file
               $languageContent = self::loadLanguageMarkdown($page, $language);
            }
          }
        }
      }

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
          $markdownValue = '';
        }
      }

      [$frontRaw, $bodyContent] = self::splitDocument($markdownValue);

      $frontmatter =
        $frontRaw !== '' ? self::parseFrontmatterRaw($frontRaw) : [];

      if (!is_array($frontmatter)) {
        $frontmatter = [];
      }

      $originalFrontmatterComparable = self::filterOutModuleFrontmatterKeys(
        $page,
        $frontmatter,
      );

      $frontmatterUpdates = [];

      foreach ($map as $field => $frontKey) {
        if (!self::pageSupportsMappedField($page, $field)) {
          continue;
        }

        $postedFieldValues = $postedByField[$field] ?? null;
        $postedFrontRaw = is_array($postedFieldValues)
          ? self::postedLanguageValue($postedFieldValues, $languageCode)
          : null;

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

        if ($fieldChanged && $markdownChanged) {
          if (
            $existingDocument !== null &&
            $existingDocument === $markdownValue
          ) {
            $markdownChanged = false;
          }
        }

        $isRenaming = $field === 'name' && ($page->isChanged('name') || $page->get('_md_renaming_' . ($language ? $language->id : '')));

        if ($fieldChanged && $markdownChanged) {
          $valuesDiffer =
            $normalizedPosted !== null &&
            $documentValue !== null &&
            self::frontmatterValuesDiffer($normalizedPosted, $documentValue);

          if ($valuesDiffer) {
            // Special case: If we are renaming the page, the 'name' field mismatch is expected.
            // Don't treat it as a conflict.
            if ($isRenaming) {
              // Allow CMS to win
            } else {
              $label = $field === 'title' ? __('title') : $field;
              throw new WireException(
                sprintf(
                  __(
                    'Field "%s" was modified in both the markdown and the form. Please adjust only one version before saving.',
                  ),
                  $label,
                ),
              );
            }
          }
        }

        $finalValue = null;

        if ($fieldChanged || $isRenaming) {
          $finalValue = $normalizedPosted ?? self::frontmatterValue($page, $field, $language);
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
        }
      }

      // Sync field binding values in markdown body to match frontmatter values
      // This ensures all instances of a field binding display the current frontmatter value
      $bodyContent = self::syncBindingsToFrontmatter($bodyContent, $frontmatter, $page);

      if ($frontmatterUpdates) {
        foreach ($frontmatterUpdates as $field => $value) {
          self::setFieldValueForLanguage($page, $field, $value, $language);
        }
      }

      // Remove module-managed keys from frontmatter so we don't leak md_* entries
      $frontmatter = self::filterOutModuleFrontmatterKeys($page, $frontmatter);

      $preserveExistingFrontmatter =
        $frontRaw !== '' &&
        !self::frontmatterValuesDiffer($originalFrontmatterComparable, $frontmatter);

      $hasDocumentContent = self::documentHasContent(
        $frontmatter,
        $bodyContent,
      );

      if ($hasDocumentContent) {
        $documentFrontmatter = $preserveExistingFrontmatter
          ? rtrim($frontRaw, "\r\n")
          : self::buildFrontmatterRaw($frontmatter);

        $document = self::composeDocumentWithFrontmatterRaw(
          $documentFrontmatter,
          $bodyContent,
        );
      } else {
        $document = '';
      }

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
      }
    }
  }

  /** Extracts the posted value for a specific language from a language map. */
  protected static function postedLanguageValue(
    array $languageMap,
    string $languageCode,
  ) {
    return $languageMap[$languageCode] ?? null;
  }

  /**
   * Syncs field binding values in markdown body to match frontmatter.
   * 
   * Updates all occurrences of <!-- field:name -->*value* to match the
   * corresponding frontmatter value. This is the only binding sync direction—
   * frontmatter is the source of truth.
   * 
   * @param string|null $bodyContent The markdown body content
   * @param array $frontmatter The frontmatter values
   * @param Page $page The page being synced (for logging)
   * @return string|null The updated body content
   */
  protected static function syncBindingsToFrontmatter(
    ?string $bodyContent,
    array $frontmatter,
    Page $page,
  ): ?string {
    if (!$bodyContent || empty($frontmatter)) {
      return $bodyContent;
    }

    $bindingSyncCount = 0;
    foreach ($frontmatter as $fieldName => $frontValue) {
      $bodyContent = self::replaceBindingValue(
        $bodyContent,
        (string) $fieldName,
        (string) $frontValue,
        $bindingSyncCount,
      );
    }

    if ($bindingSyncCount > 0) {
      self::logDebug($page, 'field bindings synced', [
        'occurrences' => $bindingSyncCount,
      ]);
    }

    return $bodyContent;
  }

  protected static function replaceBindingValue(
    string $bodyContent,
    string $fieldName,
    string $frontValue,
    int &$bindingSyncCount,
  ): string {
    $pattern = '/<!--\s+field:' . preg_quote($fieldName, '/') . '\s+-->/';
    $offset = 0;

    while (preg_match($pattern, $bodyContent, $matches, PREG_OFFSET_CAPTURE, $offset)) {
      $markerText = (string) $matches[0][0];
      $markerOffset = (int) $matches[0][1];
      $searchStart = $markerOffset + strlen($markerText);
      $searchEnd = self::nextBindingMarkerOffset($bodyContent, $searchStart);

      $opening = self::findBindingOpeningDelimiter(
        $bodyContent,
        $searchStart,
        $searchEnd,
      );
      if ($opening === null) {
        $offset = $searchEnd;
        continue;
      }

      $valueStart = $opening['offset'];
      $delimiter = $opening['delimiter'];

      $lineEnd = strpos($bodyContent, "\n", $valueStart);
      if ($lineEnd === false || $lineEnd > $searchEnd) {
        $lineEnd = $searchEnd;
      }

      $valueEnd = strrpos(substr($bodyContent, 0, $lineEnd), $delimiter);
      if ($valueEnd === false || $valueEnd <= $valueStart) {
        $offset = $lineEnd;
        continue;
      }

      $replacement = $delimiter . $frontValue . $delimiter;
      $bodyContent =
        substr($bodyContent, 0, $valueStart) .
        $replacement .
        substr($bodyContent, $valueEnd + strlen($delimiter));

      $bindingSyncCount++;
      $offset = $valueStart + strlen($replacement);
    }

    return $bodyContent;
  }

  protected static function nextBindingMarkerOffset(string $bodyContent, int $offset): int
  {
    $nextMarker = strpos($bodyContent, '<!-- field:', $offset);
    if ($nextMarker === false) {
      return strlen($bodyContent);
    }

    return $nextMarker;
  }

  protected static function findBindingOpeningDelimiter(
    string $bodyContent,
    int $offset,
    int $searchEnd,
  ): ?array {
    $doubleOffset = strpos($bodyContent, '__', $offset);
    if ($doubleOffset === false || $doubleOffset >= $searchEnd) {
      $doubleOffset = null;
    }

    $singleOffset = strpos($bodyContent, '*', $offset);
    if ($singleOffset === false || $singleOffset >= $searchEnd) {
      $singleOffset = null;
    }

    if ($doubleOffset === null && $singleOffset === null) {
      return null;
    }

    if ($doubleOffset !== null && ($singleOffset === null || $doubleOffset <= $singleOffset)) {
      return [
        'offset' => $doubleOffset,
        'delimiter' => '__',
      ];
    }

    return [
      'offset' => $singleOffset,
      'delimiter' => '*',
    ];
  }

  protected static function bindingDelimiterAt(string $bodyContent, int $offset): ?string
  {
    if (substr($bodyContent, $offset, 2) === '__') {
      return '__';
    }

    if (substr($bodyContent, $offset, 1) === '*') {
      return '*';
    }

    return null;
  }

  /** Remove module-managed keys (markdown/html/hash + tab sentinels) from frontmatter. */
  protected static function filterOutModuleFrontmatterKeys(Page $page, array $frontmatter): array
  {
    $config = self::config($page) ?? [];
    $exclude = [];
    if (!empty($config['markdownField'])) $exclude[] = (string) $config['markdownField'];
    if (!empty($config['hashField'])) $exclude[] = (string) $config['hashField'];
    // Internal fieldset wrappers used by this module
    $exclude[] = 'md_markdown_tab';
    $exclude[] = 'md_markdown_tab_END';

    if (!$exclude) return $frontmatter;

    foreach ($exclude as $key) {
      if (array_key_exists($key, $frontmatter)) {
        unset($frontmatter[$key]);
      }
    }

    return $frontmatter;
  }

  private static function findOrphanByFrontmatterName(Page $page, ?string $languageCode = null): ?string
  {
    $langCode = $languageCode ?: MarkdownLanguageResolver::getDefaultLanguageCode($page);
    $expectedPath = MarkdownFileIO::getMarkdownFilePath($page, $langCode);
    $dir = dirname($expectedPath);
    
    if (!is_dir($dir)) return null;

    $files = glob($dir . '/*.md');
    if (!$files) return null;

    $language = MarkdownLanguageResolver::resolveLanguage($page, $langCode);
    $pageName = trim((string)$page->getLanguageValue($language, 'name'));
    if ($pageName === '') {
      $pageName = (string)$page->name;
    }

    foreach ($files as $file) {
      $filename = basename($file);
      // Skip the expected filename (we already tried it)
      if ($filename === $pageName . '.md') continue;

      try {
        // We use a low-level load to avoid recursion or heavy logic
        $content = self::loadLanguageMarkdown($page, $language, $filename);
        if ($content) {
          $fm = $content->getFrontmatter();
          $fmName = trim((string)($fm['name'] ?? ''));
          if ($fmName === $pageName) {
            return $filename;
          }
        }
      } catch (\Throwable $_e) {
        continue;
      }
    }

    return null;
  }
}

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
  public static function syncFromMarkdown(Page $page): array
  {
    self::logInfo($page, 'syncFromMarkdown: starting sync', ['pageName' => $page->name, 'pagePath' => $page->path]);
    
    $config = self::config($page);
    if ($config === null) {
      self::logInfo($page, 'syncFromMarkdown: no config found, returning empty');
      return [];
    }

    self::logInfo($page, 'syncFromMarkdown: config found', ['markdownField' => $config['markdownField'] ?? '?']);
    self::$syncingFromMarkdown[$page->id] = true;

    try {
      return self::doSyncFromMarkdown($page);
    } finally {
      unset(self::$syncingFromMarkdown[$page->id]);
    }
  }

  private static function doSyncFromMarkdown(Page $page): array
  {
    $config = self::config($page);
    if ($config === null) {
      self::logInfo($page, 'doSyncFromMarkdown: no config found');
      return [];
    }

    $page->of(false);

    $markdownField = $config['markdownField'];
    $htmlField = $config['htmlField'] ?? null;
    self::logInfo($page, 'doSyncFromMarkdown: fields to sync', ['markdownField' => $markdownField, 'htmlField' => $htmlField]);

    $dirtyFields = [];

    $languageCodes = self::availableLanguageCodes($page);
    $defaultCode = self::getDefaultLanguageCode($page);

    foreach ($languageCodes as $languageCode) {
      $language = self::resolveLanguage($page, $languageCode);
      $isDefaultLanguage = $languageCode === $defaultCode;

      if (!$isDefaultLanguage && !$language instanceof Language) {
        self::logInfo(
          $page,
          sprintf(
            'skip language %s: not configured in ProcessWire',
            $languageCode,
          ),
        );
        continue;
      }

      self::logInfo($page, 'doSyncFromMarkdown: loading content for language', ['language' => $languageCode, 'isDefault' => $isDefaultLanguage]);
      
      $content = $isDefaultLanguage
        ? self::loadMarkdown($page)
        : self::loadLanguageMarkdown($page, $language);

      if (!$content instanceof ContentData) {
        if (!$isDefaultLanguage) {
          self::logInfo(
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

      // Sync field bindings in the markdown to match frontmatter values
      // This ensures all instances of a field binding display the current frontmatter value
      $frontmatter = $content->getFrontmatter() ?: [];
      $document = $content->getRawDocument();
      if (!empty($frontmatter)) {
        $bodyContent = $content->getMarkdown();
        $syncedBodyContent = self::syncBindingsToFrontmatter($bodyContent, $frontmatter, $page);
        if ($syncedBodyContent !== $bodyContent) {
          // Body content changed - compose new document with updated body
          self::logDebug($page, 'syncFromMarkdown: binding sync changed body content', [
            'bodyLengthBefore' => strlen($bodyContent),
            'bodyLengthAfter' => strlen($syncedBodyContent),
          ]);
          $document = self::composeDocument($frontmatter, $syncedBodyContent);
          
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

    if ($savedFields) {
      self::logInfo(
        $page,
        sprintf('synced from markdown: %d fields updated', count($savedFields)),
        ['fields' => implode(', ', $savedFields)],
      );
    }

    if ($failedFields) {
      $protectedFields = array_intersect(
        ['name', 'title'],
        array_keys($failedFields),
      );
      if ($protectedFields) {
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

    if ($savedFields) {
      self::logDebug(
        $page,
        sprintf('synced %d fields from markdown', count($savedFields)),
        ['fields' => $savedFields],
      );
    } else {
      self::logDebug($page, 'no fields changed from markdown sync');
    }

    return $savedFields;
  }

  /** Syncs page fields to markdown files. */
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
          self::logInfo(
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
                'The markdown file for language "%s" changed outside this editor. Please reload before saving again.',
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
          self::logInfo(
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
          self::logInfo(
            $page,
            'skip html override: raw priority and posted markdown present',
            ['language' => self::languageLogLabel($page, $language)],
          );
        } elseif ($trimmedHtml === '' && $postedMarkdown !== null) {
          self::logInfo(
            $page,
            'skip html fallback: empty submission with markdown input',
            ['language' => self::languageLogLabel($page, $language)],
          );
        } else {
          self::logInfo($page, 'syncToMarkdown html input', [
            'language' => self::languageLogLabel($page, $language),
            'len' => strlen($normalizedHtml),
            'preview' => substr(strip_tags($normalizedHtml), 0, 80),
          ]);

          $convertedMarkdown = self::htmlToMarkdown($normalizedHtml, $page);

          self::logInfo($page, 'syncToMarkdown html converted', [
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
          self::logInfo($page, 'syncToMarkdown name state', [
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
                  'Field "%s" was modified in both the markdown and the form. Please adjust only one version before saving.',
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
          self::logInfo($page, 'syncToMarkdown name decision', [
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

      // Sync field binding values in markdown body to match frontmatter values
      // This ensures all instances of a field binding display the current frontmatter value
      $bodyContent = self::syncBindingsToFrontmatter($bodyContent, $frontmatter, $page);

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

      // Remove module-managed keys from frontmatter so we don't leak md_* entries
      $frontmatter = self::filterOutModuleFrontmatterKeys($page, $frontmatter);

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

  /** Determines whether raw markdown should be the authoritative source. */
  protected static function shouldPreferMarkdownForSync(
    ?bool $rawPriorityOverride,
    ?string $postedMarkdown,
  ): bool {
    $rawPriority = $rawPriorityOverride ?? false;

    return $rawPriority && $postedMarkdown !== null;
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
      $pattern = "/(<!--\s+field:" . preg_quote($fieldName, "/") . "\s+-->.*?)(\*|__)([^*_]+)\\2/s";
      $beforeSync = $bodyContent;
      $bodyContent = preg_replace_callback($pattern, function($matches) use ($frontValue, &$bindingSyncCount) {
        $bindingSyncCount++;
        return $matches[1] . $matches[2] . $frontValue . $matches[2];
      }, $bodyContent);
      
      if ($bodyContent !== $beforeSync) {
        self::logDebug($page, "syncBindingsToFrontmatter field synced", [
          "field" => $fieldName,
          "value" => $frontValue,
          "occurrences" => $bindingSyncCount,
        ]);
      }
    }

    if ($bindingSyncCount > 0) {
      self::logInfo($page, "syncToMarkdown field bindings updated", ["count" => $bindingSyncCount]);
    }

    return $bodyContent;
  }

  /** Remove module-managed keys (markdown/html/hash + tab sentinels) from frontmatter. */
  protected static function filterOutModuleFrontmatterKeys(Page $page, array $frontmatter): array
  {
    $config = self::config($page) ?? [];
    $exclude = [];
    if (!empty($config['markdownField'])) $exclude[] = (string) $config['markdownField'];
    if (!empty($config['htmlField'])) $exclude[] = (string) $config['htmlField'];
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
}

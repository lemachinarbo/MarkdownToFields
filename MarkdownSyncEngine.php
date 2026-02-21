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
    $config = self::config($page);
    if ($config === null) {
      return [];
    }

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
      return [];
    }

    $page->of(false);

    $markdownField = $config['markdownField'];
    $htmlField = $config['htmlField'] ?? null;

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
      $bodyContent = $content->getMarkdown();

      if (!empty($frontmatter)) {
        $syncedBodyContent = self::syncBindingsToFrontmatter(
          $bodyContent,
          $frontmatter,
          $page,
        );
        if ($syncedBodyContent !== $bodyContent) {
          // Body content changed - compose new document with updated body
          self::logDebug($page, 'syncFromMarkdown: binding sync changed body content', [
            'bodyLengthBefore' => strlen($bodyContent),
            'bodyLengthAfter' => strlen($syncedBodyContent),
          ]);
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

      if ($htmlField && $page->hasField($htmlField)) {
        $htmlDocument = self::markdownToHtml($bodyContent, $page, $languageCode);
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
        self::logDebug($page, 'skipping field: not supported', ['field' => $field]);
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

    $currentHashes = self::languageFileHashes($page, $languageCodes);

    try {
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
              } catch (\Throwable $e) {
              }
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
          }
        }
      }
    } catch (\Throwable $e) {
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
        } elseif ($trimmedHtml === '' && $postedMarkdown !== null) {
        } else {
          $convertedMarkdown = self::htmlToMarkdown($normalizedHtml, $page);

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

      $hasDocumentContent = self::documentHasContent(
        $frontmatter,
        $bodyContent,
      );

      $document = $hasDocumentContent
        ? self::composeDocument($frontmatter, $bodyContent)
        : '';

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

      if ($htmlField && $page->hasField($htmlField)) {
        $htmlDocument = self::markdownToHtml($bodyContent, $page, $languageCode);
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

<?php

namespace ProcessWire;

use LetMeDown\ContentData;

class MarkdownFieldSync extends MarkdownHashTracker
{
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

  /** Applies language-specific values to a page field. */
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

  protected static function applyFrontmatter(
    Page $page,
    ContentData $content,
    ?Language $language = null,
  ): array {
    $frontmatter = $content->getFrontmatter();
    if (!is_array($frontmatter) || !$frontmatter) {
      return [];
    }

    $updated = self::applyFrontmatterFields(
      $page,
      $frontmatter,
      false,
      null,
      $language,
    );

    return $updated;
  }

  /** Syncs page fields from a markdown document string. */
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

      // Skip empty frontmatter assignments to preserve existing field values.
      // For `name`, require an explicit non-empty value to rename.
      if (!is_array($pageValue) && trim((string) $pageValue) === '') {
        self::logDebug(
          $page,
          sprintf(
            'skip field %s for %s: empty frontmatter value',
            $field,
            self::languageLogLabel($page, $language),
          ),
        );
        continue;
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

      $updated[] = $field;
    }

    return $updated;
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

}

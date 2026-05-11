<?php

namespace ProcessWire;

/**
 * MarkdownInputCollector - POST data processing and language map normalization
 *
 * This class handles collection and normalization of submitted form data,
 * resolving language-specific POST keys and converting raw input into
 * normalized language maps.
 */
class MarkdownInputCollector extends MarkdownFieldSync
{
  /** Collects submitted language-specific values from POST data. */
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

  /** Normalizes submitted field values into a language map. */
  public static function normalizeSubmittedFieldValues(
    Page $page,
    $value,
  ): array {
    return self::normalizePostedLanguageInput($page, $value);
  }

  /**
   * Normalize posted language input into language code => value map
   *
   * @param Page $page
   * @param mixed $value Raw POST value (scalar, array, or null)
   * @return array Normalized language map (code => string)
   */
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

  /**
   * Normalize pending language map (handles various language key formats)
   *
   * @param Page $page
   * @param array $values Raw map with potentially non-normalized keys
   * @return array Normalized map (language code => stringified value)
   */
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

  /**
   * Register a posted language value into the target collection
   *
   * @param Page $page
   * @param array $target Target array to populate (passed by reference)
   * @param mixed $value Value to register (scalar, array, or object)
   * @param Language|null $language Target language (null for default)
   */
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

  /** Detects which languages have been edited based on field changes. */
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

        $isFieldChanged = $page->isChanged($field);
        if (!$isFieldChanged && $language instanceof Language && !$language->isDefault()) {
          $isFieldChanged = $page->isChanged($field . $language->id);
        }

        if ($submitted !== $normalizedCurrent || $isFieldChanged) {
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

  /**
   * Stringify a pending field value for comparison
   *
   * @param mixed $value Value to stringify
   * @return string Stringified value
   */
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
}

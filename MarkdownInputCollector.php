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

  /**
   * Resolve posted language code from various key formats
   *
   * @param Page $page
   * @param string $languageKey Raw language key from POST data
   * @return string|null Resolved language code or null
   */
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
        foreach ($languages as $lang) {
          if (!$lang instanceof Language) {
            continue;
          }
          $langCode = trim((string) $lang->get('code'));
          if ($langCode !== '' && $langCode === $selectorValue) {
            return self::languageCodeFromLanguage(
              $lang,
              self::FALLBACK_LANGUAGE,
            );
          }
        }
      }
    }

    $code = self::determineLanguageCode($page, $languageKey);
    return $code !== '' ? $code : null;
  }

  /**
   * Generate POST key variants for a field and language
   *
   * @param string $field Base field name
   * @param Language $language Target language
   * @return array List of possible POST keys
   */
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

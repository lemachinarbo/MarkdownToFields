<?php

namespace ProcessWire;

/**
 * MarkdownSessionManager - Session storage for pending values
 *
 * This class handles temporary storage of pending markdown body and field values
 * in the session, allowing values to persist across requests during edit workflows.
 */
class MarkdownSessionManager extends MarkdownInputCollector
{
  /** Stores pending markdown content in the session. */
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

  /** Retrieves and removes pending markdown content from the session. */
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

  /** Stores pending field values in the session. */
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

  /** Retrieves and removes pending field values from the session. */
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

  protected static function pendingBodyKey(Page $page, string $field): string
  {
    return sprintf(
      '%s_%d_%s',
      self::SESSION_NAMESPACE,
      (int) $page->id,
      $field,
    );
  }

  protected static function pendingFieldsKey(Page $page): string
  {
    return sprintf('%s_fields_%d', self::SESSION_NAMESPACE, (int) $page->id);
  }
}

<?php

namespace ProcessWire;

class MarkdownHashTracker extends MarkdownHtmlConverter
{
  /** Returns the MD5 hash of the markdown file for a page and language. */
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

  /** Returns a map of language codes to file hashes for a page. */
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

  /** Builds a JSON-encoded hash payload for all languages. */
  public static function buildHashPayload(Page $page): string
  {
    return self::encodeHashPayload($page, self::languageFileHashes($page));
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

  /** Detects the first language with a hash mismatch between expected and current values. */
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

  /** Stores the file hash in the session for later comparison. */
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

  /** Retrieves the stored file hash from the page field or session. */
  public static function recallFileHash(
    Page $page,
    ?string $fieldName = null,
  ): ?string {
    $field = self::hashFieldName($page, $fieldName);
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

  /** Retrieves the stored hash payload as a decoded language map. */
  public static function recallFileHashMap(
    Page $page,
    ?string $fieldName = null,
  ): array {
    $payload = self::recallFileHash($page, $fieldName);
    return self::decodeHashPayload($page, $payload);
  }

  /** Returns the session key name for the hash field. */
  public static function getHashFieldName(
    Page $page,
    ?string $fieldName = null,
  ): string {
    $field = self::hashFieldName($page, $fieldName);
    return sprintf('_markdown_hash_%d_%s', (int) $page->id, $field);
  }

  protected const SESSION_NAMESPACE = 'markdown_sync';

  protected static function sessionKey(Page $page, string $fieldName): string
  {
    return self::SESSION_NAMESPACE . '_' . (int) $page->id . '_' . $fieldName;
  }
}

<?php

namespace ProcessWire;

class MarkdownLanguageResolver extends MarkdownDocumentParser
{
  protected const FALLBACK_LANGUAGE = 'en';

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
        // Prefer explicit code field when present, then name, then title.
        foreach ($languages as $lang) {
          if (!$lang instanceof Language) {
            continue;
          }
          $langCode = trim((string) $lang->get('code'));
          if ($langCode !== '' && $langCode === $selectorValue) {
            $resolved = $lang;
            break;
          }
        }
        if (!$resolved instanceof Language) {
          $resolved = $languages->get('name=' . $selectorValue);
        }
        if (!$resolved instanceof Language) {
          $resolved = $languages->get('title=' . $selectorValue);
        }
      }
    }

    return $resolved instanceof Language ? $resolved : null;
  }

  protected static function determineLanguageCode(Page $page, $language): string
  {
    $fallback = self::getDefaultLanguageCode($page);
    $resolved = self::resolveLanguage($page, $language);
    if ($resolved instanceof Language) {
      return self::languageCodeFromLanguage($resolved, $fallback);
    }

    return self::resolveLanguageIdentifier($page, $language, $fallback);
  }

  /** Returns the language code for a page and language identifier. */
  public static function getLanguageCode(Page $page, $language = null): string
  {
    return self::resolveLanguageIdentifier(
      $page,
      $language,
      self::getDefaultLanguageCode($page),
    );
  }

  /** Returns the default language code for the site. */
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

  protected static function languageCodeFromLanguage(
    Language $language,
    string $fallback,
  ): string {
    $code = trim((string) ($language->get('code') ?? ''));
    if ($code !== '') {
      return $code;
    }

    $name = trim((string) ($language->name ?? ''));
    return $name !== '' ? $name : $fallback;
  }

  /**
   * Resolve language identifier from provided input, using a single decision point.
   * Priority: explicit Language object (code field if present, else name), explicit string, user language, fallback.
   */
  protected static function resolveLanguageIdentifier(
    Page $page,
    $language,
    string $fallback,
  ): string {
    if ($language instanceof Language) {
      return self::languageCodeFromLanguage($language, $fallback);
    }

    if (is_string($language) && $language !== '') {
      return $language === 'default' ? $fallback : trim($language);
    }

    $current = $page->wire('user')->language ?? null;
    if ($current instanceof Language) {
      return self::languageCodeFromLanguage($current, $fallback);
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

  protected static function languageLogLabel(
    Page $page,
    ?Language $language,
  ): string {
    if (!$language instanceof Language) {
      return self::getDefaultLanguageCode($page) . '(default)';
    }

    return self::determineLanguageCode($page, $language);
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

  protected static function languagePageNameColumn(Language $language): string
  {
    return 'name' . (int) $language->id;
  }

  /** Returns all available language codes for the site. */
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
}

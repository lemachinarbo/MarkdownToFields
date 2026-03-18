<?php

namespace ProcessWire;

class MarkdownBoundLinks extends MarkdownFileIO
{
  private const LINK_PATTERN =
    '/(?<!\!)\[([^\]]*)\]\(([^)\s]+)(?:\s+"([^"]*)")?\)/';
  private const FIELD_NAME = 'md_link_index';

  public static function buildIndexPayload(Page $page): string
  {
    $linksByLanguage = [];

    foreach (self::languageIdentifiers($page) as $languageCode) {
      $markdown = self::readLanguageMarkdown($page, $languageCode);
      if ($markdown === null) {
        continue;
      }

      $links = self::extractResolvedLinks($page, $markdown, $languageCode);
      if ($links !== []) {
        $linksByLanguage[$languageCode] = $links;
      }
    }

    if ($linksByLanguage === []) {
      return '';
    }

    ksort($linksByLanguage);
    $encoded = json_encode(['links' => $linksByLanguage]);
    return $encoded === false ? '' : $encoded;
  }

  public static function persistLinkIndex(Page $page): bool
  {
    if (!$page->id || !$page->hasField(self::FIELD_NAME)) {
      return false;
    }

    if (!MarkdownConfig::isLinkSyncEnabled($page)) {
      $existing = (string) $page->get(self::FIELD_NAME);
      if ($existing === '') {
        return false;
      }
      $page->of(false);
      $page->set(self::FIELD_NAME, '');
      $page->save(self::FIELD_NAME);
      return true;
    }

    $payload = self::buildIndexPayload($page);
    $existing = (string) $page->get(self::FIELD_NAME);
    if ($existing === $payload) {
      return false;
    }

    $page->of(false);
    $page->set(self::FIELD_NAME, $payload);
    $page->save(self::FIELD_NAME);
    return true;
  }

  public static function persistLinkIndexFromStoredPage(Page $page): bool
  {
    if (
      !$page->id ||
      !$page->hasField(self::FIELD_NAME) ||
      !MarkdownConfig::isLinkSyncEnabled($page)
    ) {
      return false;
    }

    $storedPage = $page->wire('pages')->get((int) $page->id);
    if (!$storedPage instanceof Page || !$storedPage->id) {
      return false;
    }

    $payload = self::buildIndexPayload($storedPage);
    $existing = (string) $page->get(self::FIELD_NAME);
    if ($existing === $payload) {
      return false;
    }

    $page->of(false);
    $page->set(self::FIELD_NAME, $payload);
    $page->save(self::FIELD_NAME);
    return true;
  }

  public static function capturePageTreeUrls(Page $page): array
  {
    if (!$page->id) {
      return [];
    }

    $urlsByPageId = [];
    $targets = [$page];

    $descendants = $page->wire('pages')->find(
      'has_parent=' . (int) $page->id . ', include=all',
    );

    foreach ($descendants as $descendant) {
      if ($descendant instanceof Page && $descendant->id) {
        $targets[] = $descendant;
      }
    }

    foreach ($targets as $targetPage) {
      $urlsByPageId[(int) $targetPage->id] = self::pageUrlsByLanguage($targetPage);
    }

    return $urlsByPageId;
  }

  public static function refreshReferencesForPage(
    Page $targetPage,
    array $oldUrlsByPageId = [],
  ): array
  {
    if (!$targetPage->id) {
      return ['candidatePages' => 0, 'affectedPages' => 0, 'rewrittenLinks' => 0];
    }

    $templates = self::enabledTemplateNames($targetPage);
    if ($templates === []) {
      return ['candidatePages' => 0, 'affectedPages' => 0, 'rewrittenLinks' => 0];
    }

    $selector =
      'template=' .
      implode('|', $templates) .
      ', include=all, ' .
      self::FIELD_NAME .
      '!=';
    $pages = $targetPage->wire('pages')->find($selector);

    $candidatePages = 0;
    $affectedPages = 0;
    $rewrittenLinks = 0;

    foreach ($pages as $page) {
      if (!$page instanceof Page || !$page->id) {
        continue;
      }

      $payload = (string) $page->get(self::FIELD_NAME);
      if ($payload === '' || strpos($payload, '"pageId":' . (int) $targetPage->id) === false) {
        continue;
      }

      $candidatePages++;

      $index = self::decodeIndexPayload($payload);
      $linksByLanguage = $index['links'] ?? [];
      if (!is_array($linksByLanguage) || $linksByLanguage === []) {
        $linksByLanguage = [];
      }

      $pageChanged = false;
      $oldUrlsByLanguage = $oldUrlsByPageId[(int) $targetPage->id] ?? [];
      $languagesToCheck = array_keys($linksByLanguage);
      foreach (array_keys($oldUrlsByLanguage) as $languageCode) {
        if (!in_array($languageCode, $languagesToCheck, true)) {
          $languagesToCheck[] = $languageCode;
        }
      }

      if ($languagesToCheck === []) {
        continue;
      }

      foreach ($languagesToCheck as $languageCode) {
        $entries = $linksByLanguage[$languageCode] ?? [];
        $markdown = self::readLanguageMarkdown($page, (string) $languageCode);
        if ($markdown === null) {
          continue;
        }

        [$updated, $changedLinks] = self::refreshMarkdownLinks(
          $page,
          $targetPage,
          $markdown,
          (string) $languageCode,
          $entries,
          is_array($oldUrlsByLanguage) ? $oldUrlsByLanguage : [],
        );

        if ($updated === $markdown || $changedLinks === 0) {
          continue;
        }

        self::saveLanguageMarkdown($page, $updated, (string) $languageCode);
        self::syncMarkdownFieldFromDocument($page, $updated, (string) $languageCode);
        $pageChanged = true;
        $rewrittenLinks += $changedLinks;
      }

      if ($pageChanged) {
        self::persistLinkIndex($page);
        $affectedPages++;
      }
    }

    return [
      'candidatePages' => $candidatePages,
      'affectedPages' => $affectedPages,
      'rewrittenLinks' => $rewrittenLinks,
    ];
  }

  public static function refreshReferencesForPageTree(
    Page $page,
    array $oldUrlsByPageId = [],
  ): void
  {
    if (!$page->id) {
      return;
    }

    $candidatePages = 0;
    $affectedPages = 0;
    $rewrittenLinks = 0;

    $result = self::refreshReferencesForPage($page, $oldUrlsByPageId);
    $candidatePages += (int) ($result['candidatePages'] ?? 0);
    $affectedPages += (int) ($result['affectedPages'] ?? 0);
    $rewrittenLinks += (int) ($result['rewrittenLinks'] ?? 0);

    $descendants = $page->wire('pages')->find(
      'has_parent=' . (int) $page->id . ', include=all',
    );

    foreach ($descendants as $affectedPage) {
      if (!$affectedPage instanceof Page || !$affectedPage->id) {
        continue;
      }

      $result = self::refreshReferencesForPage($affectedPage, $oldUrlsByPageId);
      $candidatePages += (int) ($result['candidatePages'] ?? 0);
      $affectedPages += (int) ($result['affectedPages'] ?? 0);
      $rewrittenLinks += (int) ($result['rewrittenLinks'] ?? 0);
    }

    if ($candidatePages > 0 || $affectedPages > 0 || $rewrittenLinks > 0) {
      self::logDebug($page, 'link references checked', [
        'candidatePages' => $candidatePages,
        'affectedPages' => $affectedPages,
        'rewrittenLinks' => $rewrittenLinks,
      ]);
    }
  }

  private static function refreshMarkdownLinks(
    Page $contextPage,
    Page $targetPage,
    string $markdown,
    string $languageCode,
    array $entries,
    array $oldUrlsByLanguage = [],
  ): array {
    $replacements = [];

    foreach ($entries as $entry) {
      if (!is_array($entry)) {
        continue;
      }

      $pageId = (int) ($entry['pageId'] ?? 0);
      $href = trim((string) ($entry['href'] ?? ''));
      if ($pageId !== (int) $targetPage->id || $href === '') {
        continue;
      }

      $entryLanguage = trim((string) ($entry['language'] ?? ''));
      $resolvedHref = self::pageUrlForLanguage(
        $targetPage,
        $entryLanguage !== '' ? $entryLanguage : $languageCode,
      );
      if ($resolvedHref === '' || $resolvedHref === $href) {
        continue;
      }

      $replacements[$href] = $resolvedHref;
    }

    $oldHref = trim((string) ($oldUrlsByLanguage[$languageCode] ?? ''));
    if ($oldHref !== '') {
      $resolvedHref = self::pageUrlForLanguage($targetPage, $languageCode);
      if ($resolvedHref !== '' && $resolvedHref !== $oldHref) {
        $replacements[$oldHref] = $resolvedHref;
      }
    }

    if ($replacements === []) {
      return [$markdown, 0];
    }

    $replacementCount = 0;

    $updated = (string) preg_replace_callback(
      self::LINK_PATTERN,
      static function (array $matches) use ($replacements, &$replacementCount): string {
        $label = (string) ($matches[1] ?? '');
        $href = (string) ($matches[2] ?? '');
        $title = (string) ($matches[3] ?? '');

        if (!isset($replacements[$href])) {
          return $matches[0];
        }

        $replacementCount++;

        $markdown = '[' . $label . '](' . $replacements[$href];
        if ($title !== '') {
          $markdown .= ' "' . str_replace('"', '\"', $title) . '"';
        }
        $markdown .= ')';

        return $markdown;
      },
      $markdown,
    );

    return [$updated, $replacementCount];
  }

  private static function extractResolvedLinks(
    Page $page,
    string $markdown,
    string $languageCode,
  ): array {
    if ($markdown === '') {
      return [];
    }

    preg_match_all(self::LINK_PATTERN, $markdown, $matches, PREG_SET_ORDER);
    if ($matches === []) {
      return [];
    }

    $links = [];
    $seen = [];

    foreach ($matches as $match) {
      $href = trim((string) ($match[2] ?? ''));
      if (!self::isResolvableHref($href)) {
        continue;
      }

      $targetPage = self::resolveLinkedPage($page, $href);
      if (!$targetPage instanceof Page || !$targetPage->id) {
        continue;
      }

      $targetLanguage = self::resolveHrefLanguage($targetPage, $href, $languageCode);
      $key = $href . '|' . $targetPage->id . '|' . $targetLanguage;
      if (isset($seen[$key])) {
        continue;
      }

      $seen[$key] = true;
      $links[] = [
        'href' => $href,
        'pageId' => (int) $targetPage->id,
        'language' => $targetLanguage,
      ];
    }

    return $links;
  }

  private static function isResolvableHref(string $href): bool
  {
    if ($href === '' || $href[0] !== '/') {
      return false;
    }

    if (preg_match('~^(?:https?:|mailto:|tel:|#|//)~i', $href)) {
      return false;
    }

    if (strpos($href, '/page/edit/') !== false) {
      return false;
    }

    return true;
  }

  private static function resolveLinkedPage(Page $page, string $href): ?Page
  {
    $path = (string) parse_url($href, PHP_URL_PATH);
    if ($path === '') {
      return null;
    }

    $targetPage = $page->wire('pages')->get($path);
    return $targetPage instanceof Page && $targetPage->id ? $targetPage : null;
  }

  private static function resolveHrefLanguage(
    Page $targetPage,
    string $href,
    string $fallbackLanguage,
  ): string {
    $path = (string) parse_url($href, PHP_URL_PATH);
    $languages = $targetPage->wire('languages');

    if ($languages && count($languages) > 1) {
      foreach ($languages as $language) {
        if (!$language instanceof Language) {
          continue;
        }

        if ($targetPage->localUrl($language) === $path) {
          return self::languageCodeFromLanguage($language, $fallbackLanguage);
        }
      }
    }

    return $fallbackLanguage;
  }

  private static function pageUrlForLanguage(
    Page $targetPage,
    string $languageCode,
  ): string {
    if ($languageCode === self::getDefaultLanguageCode($targetPage)) {
      return (string) $targetPage->url;
    }

    $language = self::resolveLanguage($targetPage, $languageCode);
    if ($language instanceof Language) {
      return (string) $targetPage->localUrl($language);
    }

    return (string) $targetPage->url;
  }

  private static function syncMarkdownFieldFromDocument(
    Page $page,
    string $document,
    string $languageCode,
  ): void {
    $markdownField = self::getMarkdownField($page);
    if (
      !is_string($markdownField) ||
      $markdownField === '' ||
      !self::pageSupportsMappedField($page, $markdownField)
    ) {
      return;
    }

    $language = self::resolveLanguage($page, $languageCode);

    $page->of(false);

    if ($language instanceof Language && !self::isDefaultLanguage($page, $language)) {
      if (method_exists($page, 'setLanguageValue')) {
        $page->setLanguageValue($language, $markdownField, $document);
      } else {
        return;
      }
    } else {
      $page->set($markdownField, $document);
    }

    $page->save($markdownField);
  }

  private static function pageUrlsByLanguage(Page $page): array
  {
    $urls = [];

    foreach (self::languageIdentifiers($page) as $languageCode) {
      $urls[$languageCode] = self::pageUrlForLanguage($page, $languageCode);
    }

    return $urls;
  }

  private static function readLanguageMarkdown(
    Page $page,
    string $languageCode,
  ): ?string {
    $path = self::getMarkdownFilePath($page, $languageCode);
    if (!is_file($path)) {
      return null;
    }

    $markdown = @file_get_contents($path);
    return is_string($markdown) ? $markdown : null;
  }

  private static function decodeIndexPayload(string $payload): array
  {
    if ($payload === '') {
      return [];
    }

    $decoded = json_decode($payload, true);
    return is_array($decoded) ? $decoded : [];
  }

  private static function languageIdentifiers(Page $page): array
  {
    $languages = $page->wire('languages');
    if (!$languages || count($languages) < 2) {
      return [self::getDefaultLanguageCode($page)];
    }

    $codes = [];
    foreach ($languages as $language) {
      if (!$language instanceof Language) {
        continue;
      }
      $codes[] = self::languageCodeFromLanguage(
        $language,
        self::getDefaultLanguageCode($page),
      );
    }

    return array_values(array_unique(array_filter($codes)));
  }

  private static function enabledTemplateNames(Page $page): array
  {
    $moduleConfig = $page->wire('modules')->getConfig('MarkdownToFields') ?? [];
    $templates = $moduleConfig['templates'] ?? [];
    if (!is_array($templates)) {
      return [];
    }

    return array_values(
      array_filter(
        array_map('strval', $templates),
        static fn(string $name): bool => $name !== '',
      ),
    );
  }
}

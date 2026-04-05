<?php

namespace ProcessWire;

use ProcessWire\MarkdownEditor;

class MarkdownSyncHooks
{
  private static array $pendingLinkedPageRefresh = [];

  /** Disable raw markdown field in edit form. */
  public static function lockRawMarkdownField(HookEvent $event): void
  {
    $form = $event->return;
    if (!$form) {
      return;
    }

    $markdown = $form->get('md_markdown');
    if (!$markdown) {
      return;
    }

    $markdown->removeAttr('disabled');
    $markdown->description = 'Edit markdown directly here';
  }

  /** Sync template fields after module config save. */
  public static function handleSaveConfig(HookEvent $event): void
  {
    $moduleArg = $event->arguments(0);
    $moduleName = $event->arguments(1) ?? '';

    $module = $moduleArg instanceof MarkdownToFields
      ? $moduleArg
      : ($moduleArg === 'MarkdownToFields' || $moduleName === 'MarkdownToFields'
        ? $event->wire('modules')->get('MarkdownToFields')
        : null);
    if (!$module) return;

    $module->syncTemplateFields();

    MarkdownUtilities::logChannel(null, 'Template field sync complete.');
  }

  /** Prepare edit form with markdown field data and sync. */
  public static function prepareEditForm(HookEvent $event): void
  {
    $page = MarkdownEditor::pageFromProcess($event);
    if (!$page) {
      return;
    }

    if ($page->isTrash()) {
      return;
    }

    if (!MarkdownConfig::supportsPage($page)) {
      return;
    }

    // Double-check: template must be explicitly enabled in module config
    $templates = wire('modules')->getConfig('MarkdownToFields')['templates'] ?? [];
    if (!is_array($templates) || !in_array($page->template->name, $templates, true)) {
      return;
    }

    $documentField = MarkdownConfig::getMarkdownField($page);
    if (!$documentField) {
      return;
    }

    // Admin script registration moved to module init (assumes properly installed module).
    if ($event->wire('input')->requestMethod('POST')) {
      return;
    }

    $pendingBody = MarkdownSessionManager::consumePendingBody($page, $documentField);
    $pendingFields = MarkdownSessionManager::consumePendingFields($page);

    if ($pendingBody !== null || $pendingFields) {
      $page->of(false);
      $defaultCode = MarkdownLanguageResolver::getDefaultLanguageCode($page);

      if ($pendingBody !== null) {
        $values = $pendingBody;
        if (!is_array($values)) {
          $values = [$defaultCode => (string) $pendingBody];
        }

        MarkdownFieldSync::applyLanguageValues($page, $documentField, $values);
      }

      foreach ($pendingFields as $field => $value) {
        if ($field !== 'title' && !$page->hasField($field)) {
          continue;
        }

        $values = $value;
        if (!is_array($values)) {
          $values = [$defaultCode => (string) $value];
        }

        MarkdownFieldSync::applyLanguageValues($page, $field, $values);
      }

      return;
    }

    // Skip initial markdown pull when no file exists yet (new pages)
    if (!MarkdownFileIO::hasLanguageMarkdown($page)) {
      return;
    }

    try {
      $changedFields = MarkdownSyncEngine::syncFromMarkdown($page);
      MarkdownBoundLinks::persistLinkIndex($page);
      $coreChangedFields = array_values(
        array_intersect($changedFields, ['name'])
      );
      if (!empty($coreChangedFields)) {
        MarkdownUtilities::logChannel(
          $page,
          'prepareEditForm applied fields',
          ['fields' => implode(',', $coreChangedFields)],
        );
      }
      MarkdownEditor::rememberHash($page);
    } catch (\Throwable $e) {
      MarkdownUtilities::logChannel(
        $page,
        'prepareEditForm sync failed',
        ['message' => $e->getMessage()],
      );
      $event->wire('session')->error($e->getMessage());
    }
  }

  /** Append hidden hash field to edit form. */
  public static function appendHashField(HookEvent $event): void
  {
    $page = MarkdownEditor::pageFromProcess($event);
    if (!$page) {
      return;
    }

    $form = $event->return;
    if (!$form instanceof InputfieldForm) {
      return;
    }

    $field = MarkdownEditor::hashField($page);
    if ($form->get($field)) {
      return;
    }

    $hidden = $event->wire('modules')->get('InputfieldHidden');
    if (!$hidden) {
      return;
    }

    $hidden->attr('name', $field);
    $hidden->attr(
      'value',
      MarkdownHashTracker::recallFileHash($page) ??
        MarkdownHashTracker::buildHashPayload($page),
    );

    $form->add($hidden);
  }

  /** Handle page save: sync markdown to fields if needed. */
  public static function handleSaveReady(HookEvent $event): void
  {
    $page = MarkdownEditor::pageFromArgs($event);
    if (!$page) {
      return;
    }

    if ($page->isTrash()) {
      return;
    }

    $parentPrevious = $page->parentPrevious;
    if ($parentPrevious && $page->parent && $page->parent->isTrash()) {
      return;
    }

    if (!MarkdownConfig::supportsPage($page)) {
      return;
    }

    // Double-check: template must be explicitly enabled in module config
    $templates = wire('modules')->getConfig('MarkdownToFields')['templates'] ?? [];
    if (!is_array($templates) || !in_array($page->template->name, $templates, true)) {
      return;
    }

    $documentField = MarkdownConfig::getMarkdownField($page);
    if (!$documentField) {
      return;
    }

    // Auto-create markdown file on first save if it doesn't exist
    self::ensureMarkdownFileExists($page, $event);

    $input = $event->wire('input');
    $hashFieldName = MarkdownEditor::hashField($page);
    $expectedHash =
      $input->post($hashFieldName) ?? MarkdownHashTracker::recallFileHash($page);
    $postedLanguageValues = [];

    $bodyPost = $input->post($documentField);
    $bodyValues = MarkdownInputCollector::collectSubmittedLanguageValues(
      $page,
      $documentField,
      $input,
    );

    if ($bodyValues) {
      $postedLanguageValues[$documentField] = $bodyValues;
    }

    $titleValues = MarkdownInputCollector::collectSubmittedLanguageValues(
      $page,
      'title',
      $input,
    );

    if ($titleValues) {
      $postedLanguageValues['title'] = $titleValues;
    }

    foreach (
      MarkdownConfig::getFrontmatterMap($page)
      as $fieldName => $_frontKey
    ) {
      if ($fieldName === '' || $fieldName === 'title') {
        continue;
      }

      if (!$page->hasField($fieldName) && $fieldName !== 'name') {
        continue;
      }

      $values = MarkdownInputCollector::collectSubmittedLanguageValues(
        $page,
        $fieldName,
        $input,
      );

      if (!$values) {
        continue;
      }

      $postedLanguageValues[$fieldName] = $values;
    }

    $languageScope = MarkdownInputCollector::detectEditedLanguages(
      $page,
      $postedLanguageValues,
    );

    foreach ($postedLanguageValues as $fieldName => $languageValues) {
      MarkdownFieldSync::applyLanguageValues($page, $fieldName, $languageValues);
    }

    MarkdownSyncEngine::syncToMarkdown(
      $page,
      $expectedHash,
      $languageScope ?: null,
      $postedLanguageValues,
    );
    
    MarkdownEditor::rememberHash($page);

    $hashField = MarkdownConfig::getHashField($page);
    // Skip hash persistence until the page has an ID (new pages can't save individual fields yet)
    if ($page->id && $hashField && $page->hasField($hashField)) {
      $page->of(false);
      $page->set($hashField, MarkdownHashTracker::buildHashPayload($page));
      $page->save($hashField);
    }

    MarkdownBoundLinks::persistLinkIndex($page);
  }

  public static function trackLinkedPageSaveReady(HookEvent $event): void
  {
    $page = MarkdownEditor::pageFromArgs($event);
    if (!$page || !$page->id) {
      return;
    }

    if (!MarkdownConfig::isLinkSyncEnabled($page)) {
      unset(self::$pendingLinkedPageRefresh[(int) $page->id]);
      return;
    }

    if (!self::pageUrlMayHaveChanged($page)) {
      unset(self::$pendingLinkedPageRefresh[(int) $page->id]);
      return;
    }

    self::$pendingLinkedPageRefresh[(int) $page->id] = true;
  }

  /** Refresh bound page links after a ProcessWire page save. */
  public static function handleLinkedPageSaved(HookEvent $event): void
  {
    $page = MarkdownEditor::pageFromArgs($event);
    if (!$page || !$page->id) {
      return;
    }

    if (empty(self::$pendingLinkedPageRefresh[(int) $page->id])) {
      return;
    }

    unset(self::$pendingLinkedPageRefresh[(int) $page->id]);

    MarkdownBoundLinks::refreshReferencesForPageTree($page);
  }

  /** Refresh module auto-discovery when modules are loaded. */
  public static function handleModulesRefresh(HookEvent $event): void
  {
    // Delegates TTL, locking and logging logic to the batch sync helper
    try {
      $result = MarkdownBatchSync::syncAllManagedPages(
        10000,
        'md_markdown_hash',
        'markdown-sync',
        true,
        5,
        true,
      );
      MarkdownUtilities::logChannel(
        null,
        sprintf('syncAllManagedPages completed: %d pages synced', $result ?? 0),
      );
    } catch (\Throwable $e) {
      MarkdownUtilities::logChannel(
        null,
        'syncAllManagedPages error',
        ['message' => $e->getMessage()],
      );
    }
  }

  /**
   * Auto-create markdown file on first page save if it doesn't exist.
   * Creates minimal frontmatter with title and name fields.
   * Only applies to selected templates.
   */
  private static function ensureMarkdownFileExists(Page $page, HookEvent $event): void
  {
    try {
      // Check if file already exists in all languages
      $languages = $page->wire('languages');
      $isMultilingual = $languages && count($languages) > 1;

      $fileExists = false;
      if ($isMultilingual) {
        foreach (MarkdownLanguageResolver::availableLanguageCodes($page) as $languageCode) {
          $path = MarkdownFileIO::getMarkdownFilePath($page, $languageCode);
          if (is_file($path)) {
            $fileExists = true;
            break;
          }
        }
      } else {
        $path = MarkdownFileIO::getMarkdownFilePath($page);
        if (is_file($path)) {
          $fileExists = true;
        }
      }

      // File exists, nothing to do
      if ($fileExists) {
        return;
      }

      // Create minimal frontmatter with title and name
      $frontmatter = [
        'title' => (string) $page->get('title'),
        'name' => (string) $page->get('name'),
      ];

      // Build minimal document (frontmatter only)
      $frontRaw = "---\n";
      foreach ($frontmatter as $key => $value) {
        $frontRaw .= $key . ': ' . $value . "\n";
      }
      $frontRaw .= "---\n\n";
      $document = $frontRaw;

      // Write to file
      $defaultPath = MarkdownFileIO::getMarkdownFilePath($page);
      MarkdownFileIO::saveLanguageMarkdown($page, $document);

      // Notify user
      $event->wire('session')->message(
        sprintf('Created markdown file: %s', $defaultPath)
      );
      MarkdownUtilities::logChannel(
        $page,
        'Auto-created markdown file',
        ['title' => (string) $page->title, 'path' => $defaultPath],
      );
    } catch (\Throwable $e) {
      // Non-fatal: log the error but don't block page save
      MarkdownUtilities::logChannel(
        $page,
        'ensureMarkdownFileExists error',
        ['message' => $e->getMessage()],
      );
    }
  }

  private static function pageUrlMayHaveChanged(Page $page): bool
  {
    foreach (['name', 'status', 'parent_id', 'templates_id'] as $field) {
      if ($page->isChanged($field)) {
        return true;
      }
    }

    if ($page->parentPrevious && $page->parent && $page->parentPrevious->id !== $page->parent->id) {
      return true;
    }

    $languages = $page->wire('languages');
    if ($languages && count($languages) > 1) {
      foreach ($languages as $language) {
        if (!$language instanceof Language) {
          continue;
        }

        if ($page->isChanged('name' . $language->id)) {
          return true;
        }
      }
    }

    return false;
  }
}

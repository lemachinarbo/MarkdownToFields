<?php

namespace ProcessWire;

use ProcessWire\MarkdownEditor;

class MarkdownSyncHooks
{
  private static array $pendingLinkedPageRefresh = [];

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

    $documentField = MarkdownConfig::getMarkdownField($page);
    if (!$documentField) {
      return;
    }

    // Skip sync-from-markdown if we are currently receiving a form submission.
    // We want the UI values to win during a page save.
    if (count(wire('input')->post)) {
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

    self::assertUniqueMarkdownSource($page);

    self::handleRenameFiles($page);

    $input = $event->wire('input');
    $hashFieldName = MarkdownEditor::hashField($page);
    $expectedHash = $input->post($hashFieldName);
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

    $nameValues = MarkdownInputCollector::collectSubmittedLanguageValues(
      $page,
      'name',
      $input,
    );

    if ($nameValues) {
      $postedLanguageValues['name'] = $nameValues;
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
      false, // save=false
    );
    
    MarkdownEditor::rememberHash($page);

    $hashField = MarkdownConfig::getHashField($page);
    // Update hash field - ProcessWire will persist this as part of the ongoing save.
    if ($page->id && $hashField && $page->hasField($hashField)) {
      $page->of(false);
      $page->set($hashField, MarkdownHashTracker::buildHashPayload($page));
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

  private static function assertUniqueMarkdownSource(Page $page): void
  {
    $templates = wire('modules')->getConfig('MarkdownToFields')['templates'] ?? [];
    if (!is_array($templates) || !$templates) {
      return;
    }

    $selector = 'include=all';
    if ($page->id) {
      $selector .= ', id!=' . (int) $page->id;
    }
    $selector .= ', template=' . implode('|', $templates);

    $currentPaths = [];
    foreach (MarkdownLanguageResolver::availableLanguageCodes($page) as $languageCode) {
      $currentPaths[$languageCode] = MarkdownFileIO::getMarkdownFilePath($page, $languageCode);
    }

    foreach ($page->wire('pages')->find($selector) as $candidate) {
      if (!$candidate instanceof Page || $candidate->isTrash()) {
        continue;
      }

      if (!MarkdownConfig::supportsPage($candidate)) {
        continue;
      }

      foreach ($currentPaths as $languageCode => $currentPath) {
        $candidatePath = MarkdownFileIO::getMarkdownFilePath($candidate, $languageCode);
        if ($candidatePath !== $currentPath) {
          continue;
        }

        throw new WireException(
          sprintf(
            'Markdown source collision: page %s and page %s both resolve to %s for language %s. Rename one page or override contentSource() to a unique file.',
            $page->path,
            $candidate->path,
            $currentPath,
            $languageCode,
          ),
        );
      }
    }

    // Also guard against renaming onto an existing markdown file that no current
    // managed page uses (e.g. an orphan left by a previously-renamed page).
    // When 'name' changes, the new source path is different: any file already
    // there does not belong to this page.
    if ($page->id && $page->isChanged('name')) {
      $defaultCode = MarkdownLanguageResolver::getDefaultLanguageCode($page);
      $newPath = $currentPaths[$defaultCode] ?? null;

      // Detect if the rename actually changed the markdown source path.
      // If contentSource() is overridden to a static string, it won't change.
      $changes = $page->getChanges(true);
      $oldName = $changes['name'] ?? null;
      $pathChanged = true;

      if ($oldName !== null) {
        $oldPage = clone $page;
        $oldPage->name = $oldName;
        $oldPath = MarkdownFileIO::getMarkdownFilePath($oldPage, $defaultCode);
        if ($oldPath === $newPath) {
          $pathChanged = false;
        }
      }

      if ($pathChanged && $newPath !== null && is_file($newPath)) {
        // Orphan Adoption: If we can verify this is our file (via frontmatter), we allow it silently.
        try {
          $content = MarkdownFileIO::loadLanguageMarkdown($page, $defaultCode, basename($newPath));
          if ($content) {
            $frontmatter = $content->getFrontmatter();
            $fileStoredName = trim((string) ($frontmatter['name'] ?? ''));
            if ($fileStoredName !== '') {
              $currentNames = [];
              $languages = $page->wire('languages');
              if ($languages) {
                foreach ($languages as $lang) {
                  $n = (string) $page->get('name' . ($lang->isDefault() ? '' : $lang->id));
                  if ($n !== '') $currentNames[] = $n;
                }
              } else {
                $currentNames[] = (string) $page->name;
              }
              
              if (in_array($fileStoredName, array_unique($currentNames), true)) {
                return; // Direct reunion, allow silently
              }
            }
          }
        } catch (\Throwable $_e) {
          // Ignore read errors during adoption check
        }

        // If not a clear reunion, allow it but warn the user.
        // This fulfills the "you pick the name, you pick the file" philosophy
        // while remaining respectful by providing transparency.
        $page->wire('session')?->warning(
          sprintf(
            'Markdown: This page is now using "%s", an existing file found on the disk.',
            basename($newPath)
          )
        );
      }
    }
  }

  /**
   * Physically move markdown files when a page name changes.
   * This preserves unmapped content that would otherwise be lost if a new
   * blank file was created at the new path.
   */
  private static function handleRenameFiles(Page $page): void
  {
    $pageId = (int) $page->id;
    if (!$pageId) {
      return;
    }

    MarkdownConfig::forget($page);

    // Fetch the version currently in the DB using getFresh to bypass memory cache
    $dbPage = $page->wire('pages')->getFresh($pageId);
    if (!$dbPage || !$dbPage->id) {
      return;
    }

    $languages = $page->wire('languages');
    if (!$languages) {
      return;
    }

    $defaultLanguage = MarkdownLanguageResolver::getDefaultLanguage($page);
    $oldName = $defaultLanguage ? (string) $dbPage->getLanguageValue($defaultLanguage, 'name') : (string) $dbPage->name;
    $newName = $defaultLanguage ? (string) $page->getLanguageValue($defaultLanguage, 'name') : (string) $page->name;

    if ($oldName === '' || $newName === '' || $oldName === $newName) {
      return;
    }

    $oldPage = clone $dbPage;
    if ($defaultLanguage) {
      $oldPage->setLanguageValue($defaultLanguage, 'name', $oldName);
    } else {
      $oldPage->name = $oldName;
    }

    $renamedCount = 0;
    foreach (MarkdownLanguageResolver::availableLanguageCodes($page) as $langCode) {
      try {
        $language = MarkdownLanguageResolver::resolveLanguage($page, $langCode);

        $oldPath = MarkdownFileIO::getMarkdownFilePath($oldPage, $langCode);
        $newPath = MarkdownFileIO::getMarkdownFilePath($page, $langCode);

        if ($oldPath === $newPath) continue;

        if (is_file($oldPath)) {
          $canOverwrite = false;
          if (!is_file($newPath)) {
            $canOverwrite = true;
          } else {
            // Overwrite if orphan or same page
            $collidingPage = $page->wire('pages')->get("md_markdown=" . basename($newPath));
            if (!$collidingPage->id || (int)$collidingPage->id === (int)$page->id) {
              $canOverwrite = true;
            }
          }

          if ($canOverwrite) {
            MarkdownFileIO::ensureDirectory($newPath);
            if (rename($oldPath, $newPath)) {
              $msg = sprintf("Moved markdown file '%s' to '%s' to match page rename.", basename($oldPath), basename($newPath));
              MarkdownUtilities::sessionMessage($msg);
              $page->setQuietly('_md_renaming_' . $language->id, true);
              MarkdownUtilities::logChannel($page, 'Moved markdown file on rename', [
                'from' => $oldPath,
                'to' => $newPath,
                'language' => $langCode,
              ]);
              $renamedCount++;
            } else {
              MarkdownUtilities::logChannel($page, 'FAILED to move markdown file on rename', [
                'from' => $oldPath,
                'to' => $newPath,
                'language' => $langCode,
                'error' => error_get_last()['message'] ?? 'Unknown error',
              ]);
            }
          }
        }
      } catch (\Throwable $e) {
        MarkdownUtilities::logChannel($page, "Error processing language rename: $langCode", [
          'message' => $e->getMessage()
        ]);
      }
    }
  }
}

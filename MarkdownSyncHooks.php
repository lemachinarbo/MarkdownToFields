<?php

namespace ProcessWire;

use ProcessWire\MarkdownEditor;

class MarkdownSyncHooks
{
  /** Enqueue markdown editor asset files. */
  public static function enqueueAssets(HookEvent $event): void
  {
    $config = $event->wire('config');
    $url = $config->urls->MarkdownToFields ?? null;
    if (!$url) {
      return;
    }

    $config->scripts->add($url . 'assets/markdown-editor.js');
  }

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

    $markdown->attr('disabled', 'disabled');
    $markdown->description = 'Double-click the field to edit the Markdown content.
    While editing Markdown, do not modify the same content in other fields (such as the title or content editor) to avoid losing changes.';
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

    if ($event->wire('input')->post('configure_editor_field')) {
      $config = $event->wire('config');
      $mdConfig = $config->MarkdownToFields ?? [];
      $fieldName = $mdConfig['htmlField'] ?? 'md_editor';

      $module->repairMarkdownEditor($fieldName);
      $module->message("Editor field '{$fieldName}' has been configured with required TinyMCE settings.");
      $module->wire('log')->save('markdown-sync', "Editor field '{$fieldName}' configured with required TinyMCE settings.");
    }

    $module->wire('log')->save('markdown-sync', 'Template field sync complete.');
  }

  /** Prepare edit form with markdown field data and sync. */
  public static function prepareEditForm(HookEvent $event): void
  {
    wire('log')->save('markdown-sync', 'Hook: prepareEditForm triggered');
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

    // Ensure the page's selected editor field exists and is attached to the template
    try {
      $htmlFieldName = MarkdownConfig::getHtmlField($page);
      if ($htmlFieldName) {
        $fields = wire('fields');
        $modules = wire('modules');
        $mtf = $modules->get('MarkdownToFields');

        $field = $fields->get($htmlFieldName);
        if (!$field && $mtf) {
          // Create/repair the requested editor field
          $mtf->repairMarkdownEditor($htmlFieldName);
          $field = $fields->get($htmlFieldName);
        }

        // Attach to template if missing
        if ($field) {
          $fg = $page->template->fieldgroup;
          if ($fg && !$fg->has($field)) {
            $fg->add($field);
            $fg->save();
          }
        }

        // If the override is valid, align module config/htmlField so UI reflects programmatic choice
        if ($mtf && $field && $mtf->isMarkdownEditorCompatible($field)) {
          $currentConfig = $modules->getConfig($mtf) ?? [];
          $currentValue = $currentConfig['htmlField'] ?? null;
          if ($currentValue !== $htmlFieldName) {
            $currentConfig['htmlField'] = $htmlFieldName;
            $modules->saveConfig($mtf, $currentConfig);
            $mtf->htmlField = $htmlFieldName;
          }
        }
      }
    } catch (\Throwable $e) {
      // non-fatal: continue without blocking edit form
      wire('log')->save('markdown-sync', 'Editor ensure failed: ' . $e->getMessage());
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
      if (!empty($changedFields)) {
        wire('log')->save(
          'markdown-sync',
          sprintf(
            'prepareEditForm applied fields: %s for %s',
            implode(',', $changedFields),
            (string) $page->path,
          ),
        );
      }
      MarkdownEditor::rememberHash($page);
    } catch (\Throwable $e) {
      wire('log')->save('markdown-sync', $e->getMessage());
      $event->wire('session')->error($e->getMessage());
    }
  }

  /** Append hidden hash field to edit form. */
  public static function appendHashField(HookEvent $event): void
  {
    wire('log')->save('markdown-sync', 'Hook: appendHashField triggered');
    $page = MarkdownEditor::pageFromProcess($event);
    if (!$page) {
      return;
    }

    $form = $event->return;
    if (!$form instanceof InputfieldForm) {
      return;
    }

    $htmlField = MarkdownConfig::getHtmlField($page);
    if ($htmlField) {
      $inputfield = $form->get($htmlField);
      if ($inputfield) {
        MarkdownHtmlConverter::applyEditorPlaceholdersToInputfield($inputfield);
      }
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

    $htmlField = MarkdownConfig::getHtmlField($page);
    if ($htmlField && $page->hasField($htmlField)) {
      $htmlValues = MarkdownInputCollector::collectSubmittedLanguageValues(
        $page,
        $htmlField,
        $input,
      );

      if ($htmlValues) {
        $sanitized = [];
        foreach ($htmlValues as $code => $value) {
          $sanitized[$code] = MarkdownHtmlConverter::editorPlaceholdersToComments(
            $value,
          );
        }

        $postedLanguageValues[$htmlField] = $sanitized;
      }
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

    $raw = wire('input')->post('md_markdown_lock_transient_value');
    if ($raw === null) {
      $raw = wire('input')->post('md_markdown_lock_transient');
    }

    $rawPriorityOverride = null;
    if ($raw !== null) {
      $normalized = strtolower(trim((string) $raw));
      $rawPriorityOverride = in_array($normalized, ['1', 'true', 'on'], true);
    }

    MarkdownSyncEngine::syncToMarkdown(
      $page,
      $expectedHash,
      $languageScope ?: null,
      $postedLanguageValues,
      $rawPriorityOverride,
    );
    
    MarkdownEditor::rememberHash($page);

    $hashField = MarkdownConfig::getHashField($page);
    // Skip hash persistence until the page has an ID (new pages can't save individual fields yet)
    if ($page->id && $hashField && $page->hasField($hashField)) {
      $page->of(false);
      $page->set($hashField, MarkdownHashTracker::buildHashPayload($page));
      $page->save($hashField);
    }
  }

  /** Refresh module auto-discovery when modules are loaded. */
  public static function handleModulesRefresh(HookEvent $event): void
  {
    wire('log')->save('markdown-sync', 'Hook: handleModulesRefresh triggered - starting sync');
    $user = $event->wire('user');
    $userName = $user ? ($user->name ?? '') : '?';
    $url = $event->wire('input') ? ($event->wire('input')->url() ?? '') : '';
    wire('log')->save(
      'markdown-sync',
      sprintf('Modules::refresh hook invoked: user=%s request=%s', $userName, $url),
    );

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
      wire('log')->save(
        'markdown-sync',
        sprintf('syncAllManagedPages completed: %d pages synced', $result ?? 0),
      );
    } catch (\Throwable $e) {
      wire('log')->save(
        'markdown-sync',
        'syncAllManagedPages error: ' . $e->getMessage(),
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
        foreach ($languages as $lang) {
          $path = MarkdownFileIO::getMarkdownFilePath($page, $lang->name);
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
      wire('log')->save(
        'markdown-sync',
        sprintf('Auto-created markdown file for page "%s" (%s)', $page->title, $defaultPath),
      );
    } catch (\Throwable $e) {
      // Non-fatal: log the error but don't block page save
      wire('log')->save(
        'markdown-sync',
        'ensureMarkdownFileExists error: ' . $e->getMessage(),
      );
    }
  }
}

<?php

namespace ProcessWire;

use ProcessWire\MarkdownEditor;

class MarkdownSyncHooks
{
  public static function enqueueAssets(HookEvent $event): void
  {
    $config = $event->wire('config');
    $url = $config->urls->MarkdownToFields ?? null;
    if (!$url) {
      return;
    }

    $config->scripts->add($url . 'assets/markdown-editor.js');
  }

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

  public static function prepareEditForm(HookEvent $event): void
  {
    wire('log')->save('markdown-sync', 'Hook: prepareEditForm triggered');
    $page = MarkdownEditor::pageFromProcess($event);
    if (!$page) {
      return;
    }

    // Skip markdown sync for pages in trash
    if ($page->isTrash()) {
      return;
    }

    if (!MarkdownSyncer::supportsPage($page)) {
      return;
    }

    // Ensure the page's selected editor field exists and is attached to the template
    try {
      $htmlFieldName = MarkdownSyncer::getHtmlField($page);
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

    $documentField = MarkdownSyncer::getMarkdownField($page);
    if (!$documentField) {
      return;
    }

    // Admin script registration moved to module init (assumes properly installed module).
    if ($event->wire('input')->requestMethod('POST')) {
      return;
    }

    $pendingBody = MarkdownSyncer::consumePendingBody($page, $documentField);
    $pendingFields = MarkdownSyncer::consumePendingFields($page);

    if ($pendingBody !== null || $pendingFields) {
      $page->of(false);
      $defaultCode = MarkdownSyncer::getDefaultLanguageCode($page);

      if ($pendingBody !== null) {
        $values = $pendingBody;
        if (!is_array($values)) {
          $values = [$defaultCode => (string) $pendingBody];
        }

        MarkdownSyncer::applyLanguageValues($page, $documentField, $values);
      }

      foreach ($pendingFields as $field => $value) {
        if ($field !== 'title' && !$page->hasField($field)) {
          continue;
        }

        $values = $value;
        if (!is_array($values)) {
          $values = [$defaultCode => (string) $value];
        }

        MarkdownSyncer::applyLanguageValues($page, $field, $values);
      }

      return;
    }

    try {
      $changedFields = MarkdownSyncer::syncFromMarkdown($page);
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

    $htmlField = MarkdownSyncer::getHtmlField($page);
    if ($htmlField) {
      $inputfield = $form->get($htmlField);
      if ($inputfield) {
        MarkdownSyncer::applyEditorPlaceholdersToInputfield($inputfield);
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
      MarkdownSyncer::recallFileHash($page) ??
        MarkdownSyncer::buildHashPayload($page),
    );

    $form->add($hidden);
  }

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

    if (!MarkdownSyncer::supportsPage($page)) {
      return;
    }

    $documentField = MarkdownSyncer::getMarkdownField($page);
    if (!$documentField) {
      return;
    }

    $input = $event->wire('input');
    $hashFieldName = MarkdownEditor::hashField($page);
    $expectedHash =
      $input->post($hashFieldName) ?? MarkdownSyncer::recallFileHash($page);
    $postedLanguageValues = [];

    $bodyPost = $input->post($documentField);
    $bodyValues = MarkdownSyncer::collectSubmittedLanguageValues(
      $page,
      $documentField,
      $input,
    );

    if ($bodyValues) {
      $postedLanguageValues[$documentField] = $bodyValues;
    }

    $htmlField = MarkdownSyncer::getHtmlField($page);
    if ($htmlField && $page->hasField($htmlField)) {
      $htmlValues = MarkdownSyncer::collectSubmittedLanguageValues(
        $page,
        $htmlField,
        $input,
      );

      if ($htmlValues) {
        $sanitized = [];
        foreach ($htmlValues as $code => $value) {
          $sanitized[$code] = MarkdownSyncer::editorPlaceholdersToComments(
            $value,
          );
        }

        $postedLanguageValues[$htmlField] = $sanitized;
      }
    }

    $titleValues = MarkdownSyncer::collectSubmittedLanguageValues(
      $page,
      'title',
      $input,
    );

    if ($titleValues) {
      $postedLanguageValues['title'] = $titleValues;
    }

    foreach (
      MarkdownSyncer::getFrontmatterMap($page)
      as $fieldName => $_frontKey
    ) {
      if ($fieldName === '' || $fieldName === 'title') {
        continue;
      }

      if (!$page->hasField($fieldName) && $fieldName !== 'name') {
        continue;
      }

      $values = MarkdownSyncer::collectSubmittedLanguageValues(
        $page,
        $fieldName,
        $input,
      );

      if (!$values) {
        continue;
      }

      $postedLanguageValues[$fieldName] = $values;
    }

    $languageScope = MarkdownSyncer::detectEditedLanguages(
      $page,
      $postedLanguageValues,
    );

    foreach ($postedLanguageValues as $fieldName => $languageValues) {
      MarkdownSyncer::applyLanguageValues($page, $fieldName, $languageValues);
    }

    if ($bodyPost) {
      MarkdownSyncer::syncFieldsFromMarkdown($page, $bodyPost);
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

    MarkdownSyncer::syncToMarkdown(
      $page,
      $expectedHash,
      $languageScope ?: null,
      $postedLanguageValues,
      $rawPriorityOverride,
    );
    
    MarkdownEditor::rememberHash($page);

    $hashField = MarkdownSyncer::getHashField($page);
    if ($hashField && $page->hasField($hashField)) {
      $page->of(false);
      $page->set($hashField, MarkdownSyncer::buildHashPayload($page));
      $page->save($hashField);
    }
  }

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

    // Delegates TTL, locking and logging logic to the MarkdownSyncer helper
    try {
      $result = MarkdownSyncer::syncAllManagedPages(
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
}

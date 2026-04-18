<?php

namespace ProcessWire;

use LetMeDown\ContentData;
use ProcessWire\MarkdownSyncHooks;

// Load dependencies and module classes early so they are available before init hooks run
$__moduleVendor = __DIR__ . '/vendor/autoload.php';
if (is_file($__moduleVendor)) {
  require_once $__moduleVendor;
}
if (!class_exists('LetMeDown\\ContentData', false)) {
  throw new WireException(
    'MarkdownToFields requires LetMeDown to be installed and autoloadable via Composer.',
  );
}
require_once __DIR__ . '/MarkdownContent.php';
require_once __DIR__ . '/MarkdownUtilities.php';
require_once __DIR__ . '/MarkdownDocumentParser.php';
require_once __DIR__ . '/MarkdownLanguageResolver.php';
require_once __DIR__ . '/MarkdownConfig.php';
require_once __DIR__ . '/MarkdownFileIO.php';
require_once __DIR__ . '/MarkdownBoundLinks.php';
require_once __DIR__ . '/MarkdownHtmlConverter.php';
require_once __DIR__ . '/MarkdownHashTracker.php';
require_once __DIR__ . '/MarkdownFieldSync.php';
require_once __DIR__ . '/MarkdownInputCollector.php';
require_once __DIR__ . '/MarkdownSessionManager.php';
require_once __DIR__ . '/MarkdownSyncEngine.php';
require_once __DIR__ . '/MarkdownBatchSync.php';
require_once __DIR__ . '/MarkdownEditor.php';
require_once __DIR__ . '/MarkdownSyncHooks.php';

/**
 * MarkdownToFields – Because you have a right to have Markdown files as your content source of truth
 *
 * Parse markdown into a ProcessWire-style content API and sync it bidirectionally
 * with ProcessWire fields.
 *
 * @property array  $templates     Configured templates with markdown sync enabled
 * @property string $markdownField Default field name for markdown content
 * @property string $contentPath   Base path for markdown files
 * @property bool   $debug         Enable debug logging to markdown-sync.txt
 */

class MarkdownToFields extends WireData implements Module, ConfigurableModule
{
  private static bool $uninstalling = false;
  
  private array $fieldDefs = [
    'md_markdown_tab' => ['FieldtypeFieldsetTabOpen', 'Markdown'],
    'md_markdown' => ['FieldtypeTextarea', 'Markdown editor'],
    'md_markdown_hash' => ['FieldtypeText', 'Markdown hash'],
    'md_link_index' => ['FieldtypeTextarea', 'Markdown links'],
    'md_markdown_tab_END' => ['FieldtypeFieldsetClose', 'Close Markdown tab'],
  ];

  /** Provide module metadata to ProcessWire. */
  public static function getModuleInfo()
  {
    return [
      'title' => 'Markdown to fields',
      'version' => '1.3.6',
      'summary' => 'Markdown files as your content source of truth',
      'description' =>
        'Use markdown as your content. Structure it with simple tags, and enjoy the markdown <-> ProcessWire fields sync',
      'author' => 'Lemachi Narbo',
      'icon' => 'file-text-o',
      'requires' => 'PHP>=8.0',
      'autoload' => true,
      'singular' => true,
      'href' => '',
    ];
  }

  /** Register hooks and sync template fields. */
  public function init()
  {
    $this->syncTemplateFields();

    $this->addHook('ProcessPageEdit::buildForm', MarkdownSyncHooks::class . '::prepareEditForm');
    $this->addHook('ProcessPageEdit::buildFormContent', MarkdownSyncHooks::class . '::appendHashField');
    $this->addHookBefore('Pages::saveReady', MarkdownSyncHooks::class . '::trackLinkedPageSaveReady');
    $this->addHook('Pages::saveReady', MarkdownSyncHooks::class . '::handleSaveReady');
    $this->addHookAfter('Pages::saved', MarkdownSyncHooks::class . '::handleLinkedPageSaved');
    $this->addHookAfter('ProcessPageEdit::buildForm', MarkdownSyncHooks::class . '::lockRawMarkdownField');
    $this->addHookAfter('Modules::refresh', MarkdownSyncHooks::class . '::handleModulesRefresh');
    $this->addHookAfter('Modules::saveConfig', MarkdownSyncHooks::class . '::handleSaveConfig');
  }

  /** Create required fields and set default configuration. */
  public function install()
  {
    $this->syncTemplateFields();
    $this->wire('modules')->saveConfig($this, [
      'linkSync' => false,
      'templates' => [],
      // Global frontmatter auto-sync defaults (opt-out only)
      'autoSyncFrontmatter' => true,
      'excludeFrontmatterFields' => ['name'],
      'includeFrontmatterFields' => [],
    ]);
  }

  /** Build module configuration form. */
  public function getModuleConfigInputfields(array $data): InputfieldWrapper
  {
    $effectiveTemplates = $this->getEffectiveEnabledTemplates();

    $defaults = [
      'templates' => $effectiveTemplates,
      'linkSync' => false,
    ];
    $data = array_merge($defaults, $data);

    $modules = $this->wire('modules');
    $templates = $this->wire('templates');
    $wrapper = new InputfieldWrapper();
    $mdConfig = $this->wire('config')->MarkdownToFields ?? [];
    $input = $this->wire('input');

    $options = $this->buildTemplateOptions($templates);

    if ($this->isTemplateConfigLocked($mdConfig)) {
      $this->renderReadOnlyTemplates($wrapper, $modules, $options, $effectiveTemplates);
    } else {
      $this->renderTemplateCheckboxes($wrapper, $modules, $options, $data['templates']);
    }

    $linkSyncField = $modules->get('InputfieldCheckbox');
    $linkSyncField->name = 'linkSync';
    $linkSyncField->label = 'Keep internal markdown links updated';
    $linkSyncField->description = 'When enabled, MarkdownToFields tracks internal page links and updates their URLs if the target page moves or changes URL.';
    $linkSyncField->attr('value', 1);
    $linkSyncField->checked((bool) ($data['linkSync'] ?? false));
    if (array_key_exists('linkSync', $mdConfig)) {
      $linkSyncField->collapsed = Inputfield::collapsedNo;
      $linkSyncField->attr('disabled', 'disabled');
      $linkSyncField->notes = 'Controlled by $config->MarkdownToFields["linkSync"].';
    }
    $wrapper->add($linkSyncField);

    // Image resync action (explicit, manual)
    $imageFieldset = $modules->get('InputfieldFieldset');
    $imageFieldset->name = 'image_resync';
    $imageFieldset->label = 'Image Refresh';
    $imageFieldset->description = 'Re-sync images copies the current versions of images from your source library into ProcessWire for all images used in this markdown.
    Use this if you replaced an image but kept the same filename.';

    $statusLines = [];
    $statusMessage = 'Click "Refresh Images" to refresh all images from your source folders.';
    if ($input->requestMethod('POST') && $input->post->resync_images) {
      $result = $this->resyncImagesForManagedPages(10000, $statusLines);
      $statusMessage = sprintf(
        '✓ Done! Checked %d page(s), updated %d page(s), refreshed %d image(s).',
        $result['processed'],
        $result['updatedPages'],
        $result['updatedImages'],
      );
    }

    $statusOutput = $modules->get('InputfieldMarkup');
    $statusOutput->label = 'Resync Status';
    $statusOutput->value =
      '<div class="Message"><p>' . htmlspecialchars($statusMessage) . '</p></div>' .
      '<pre style="background:#f5f5f5; border:1px solid #ddd; border-radius:3px; padding:10px; max-height:240px; overflow:auto; font-size:12px;">' .
      htmlspecialchars(implode("\n", $statusLines)) .
      '</pre>';
    $imageFieldset->add($statusOutput);

    $resyncButton = $modules->get('InputfieldSubmit');
    $resyncButton->name = 'resync_images';
    $resyncButton->value = 'Refresh Images';
    $resyncButton->description = 'One-time manual refresh. Copies current images from source folders to ProcessWire assets.';
    $imageFieldset->add($resyncButton);

    $wrapper->add($imageFieldset);

    // Configuration reference
    $refFieldset = $modules->get('InputfieldFieldset');
    $refFieldset->name = 'configuration_reference';
    $refFieldset->label = 'Configuration Reference';
    $refFieldset->description = 'All settings are managed in /site/config.php';

    $refMarkup = $modules->get('InputfieldMarkup');
    $settings = $this->getNormalizedSettings();
    $refMarkup->value = $this->renderConfigurationReference($settings);
    $refFieldset->add($refMarkup);
    $wrapper->add($refFieldset);

    return $wrapper;
  }

  private function resyncImagesForManagedPages(
    int $limit = 10000,
    ?array &$log = null,
  ): array
  {
    $pages = $this->wire('pages')->find("limit={$limit}");
    $processed = 0;
    $updatedPages = 0;
    $updatedImages = 0;

    if (is_array($log)) {
      $log[] = 'Starting image resync.';
      $log[] = 'Scanning pages for managed templates...';
    }

    foreach ($pages as $page) {
      if (!MarkdownConfig::supportsPage($page)) {
        continue;
      }

      $processed++;
      $count = MarkdownHtmlConverter::resyncImageHashesForPage($page);
      if ($count > 0) {
        $updatedPages++;
        $updatedImages += $count;
        if (is_array($log)) {
          $log[] = sprintf('Updated page %d (%s): %d image(s).', $page->id, $page->name, $count);
        }
      }
    }

    if (is_array($log)) {
      $log[] = sprintf(
        'Done. Pages checked: %d. Pages updated: %d. Images refreshed: %d.',
        $processed,
        $updatedPages,
        $updatedImages,
      );
    }

    return [
      'processed' => $processed,
      'updatedPages' => $updatedPages,
      'updatedImages' => $updatedImages,
    ];
  }

  /** Determine enabled templates from site config or module state. */
  private function getEffectiveEnabledTemplates(): array
  {
    $mdConfig = $this->wire('config')->MarkdownToFields ?? [];
    if (isset($mdConfig['enabledTemplates'])) {
      return (array) $mdConfig['enabledTemplates'];
    }
    
    // Using fallback: stored module config from previous UI configuration
    return (array) ($this->templates ?? []);
  }

  /** True when site config defines enabledTemplates (UI read-only). */
  private function isTemplateConfigLocked(array $mdConfig): bool
  {
    return array_key_exists('enabledTemplates', $mdConfig);
  }

  /** Render read-only template list controlled by config.php. */
  private function renderReadOnlyTemplates(InputfieldWrapper $wrapper, Modules $modules, array $options, array $templates): void
  {
    $markup = $modules->get('InputfieldMarkup');
    $markup->label = 'Enabled templates';
    $markup->description = 'Templates are controlled by $config->MarkdownToFields["enabledTemplates"].';
    if (empty($templates)) {
      $markup->value = '<em>None</em>';
    } else {
      $items = [];
      foreach ($templates as $tpl) {
        $label = $options[$tpl] ?? $tpl;
        $items[] = '<li><code>' . htmlspecialchars($label) . '</code></li>';
      }
      $markup->value = '<ul>' . implode('', $items) . '</ul>';
    }
    $wrapper->add($markup);
  }

  /** Render editable template checkboxes. */
  private function renderTemplateCheckboxes(InputfieldWrapper $wrapper, Modules $modules, array $options, array $value): void
  {
    $checkboxes = $modules->get('InputfieldCheckboxes');
    $checkboxes->attr('name', 'templates');
    $checkboxes->label = 'Enabled templates';
    $checkboxes->description = 'Enable Markdown sync for these templates. System and admin templates are ignored.';
    $checkboxes->addOptions($options);
    $checkboxes->value = $value;
    $wrapper->add($checkboxes);
  }

  /** Build selectable template labels (excludes system/admin). */
  private function buildTemplateOptions(Templates $templates): array
  {
    $options = [];
    foreach ($templates as $template) {
      if ($this->isTemplateExcluded($template)) {
        continue;
      }
      $label = $template->label
        ? "{$template->label} ({$template->name})"
        : $template->name;
      $options[$template->name] = $label;
    }
    ksort($options);
    return $options;
  }

  /** Normalize module settings from site config to typed values for display/use. */
  private function getNormalizedSettings(): array {
    $mdConfig = $this->wire('config')->MarkdownToFields ?? [];
    $moduleConfig = $this->wire('modules')->getConfig($this) ?? [];
    return [
      'enabledTemplates' => $mdConfig['enabledTemplates'] ?? [],
      'linkSync' => array_key_exists('linkSync', $mdConfig)
        ? (bool) $mdConfig['linkSync']
        : (bool) ($moduleConfig['linkSync'] ?? false),
      'markdownField' => $mdConfig['markdownField'] ?? 'md_markdown',
      'hashField' => $mdConfig['hashField'] ?? 'md_markdown_hash',
      'sourcePath' => $mdConfig['sourcePath'] ?? 'content/',
      'imageBaseUrl' => $mdConfig['imageBaseUrl'] ?? null,
      'imageSourcePaths' => $mdConfig['imageSourcePaths'] ?? [],
      'autoSyncFrontmatter' => $mdConfig['autoSyncFrontmatter'] ?? true,
      'excludeFrontmatterFields' => $mdConfig['excludeFrontmatterFields'] ?? ['name'],
      'includeFrontmatterFields' => $mdConfig['includeFrontmatterFields'] ?? [],
      'debug' => $mdConfig['debug'] ?? false,
    ];
  }

  /** Render-only HTML for the configuration reference and current typed values. */
  /** Render-only HTML for the configuration reference and current typed values. */
  private function renderConfigurationReference(array $settings): string {
    $html = $this->renderConfigExample();

    $html .= '<h4>Current values:</h4>';
    $html .= '<table style="width:100%; border-collapse:collapse; font-size:12px;">';
    $html .= '<tr style="background:#f5f5f5;">';
    $html .= '<th style="text-align:left; padding:8px; border:1px solid #ddd;">Setting</th>';
    $html .= '<th style="text-align:left; padding:8px; border:1px solid #ddd;">Value</th>';
    $html .= '<th style="text-align:center; padding:8px; border:1px solid #ddd; width:100px;">Status</th>';
    $html .= '</tr>';

    foreach ($settings as $key => $value) {
      $html .= $this->renderSettingRow($key, $value);
    }

    return $html . '</table>';
  }

  private function renderConfigExample(): string {
    $html = '<p>All settings are managed in <code>/site/config.php</code>. Add or adjust:</p>';
    $html .= '<pre style="background:#f0f0f0; padding:12px; border-radius:4px; overflow-x:auto; font-size:11px;">';
    $html .= "\$config->MarkdownToFields = [\n";
    $html .= "\n";
    $html .= "  // templates\n";
    $html .= "  'enabledTemplates' => ['home', 'about'],\n";
    $html .= "\n";
    $html .= "  // fields\n";
    $html .= "  'markdownField' => 'md_markdown',\n";
    $html .= "  'hashField' => 'md_markdown_hash',\n";
    $html .= "  'linkSync' => false,\n";
    $html .= "\n";
    $html .= "  // content\n";
    $html .= "  'sourcePath' => 'content/',\n";
    $html .= "  'imageBaseUrl' => \$config->urls->files . '{pageId}/',\n";
    $html .= "  // Must be relative to site/";
    $html .= "  'imageSourcePaths' => \$config->paths->site . 'images/',\n";
    $html .= "\n";
    $html .= "  // frontmatter\n";
    $html .= "  'autoSyncFrontmatter' => true,\n";
    $html .= "  'includeFrontmatterFields' => ['name', 'summary', 'bio'],\n";
    $html .= "  'excludeFrontmatterFields' => ['description'],\n";
    $html .= "\n";
    $html .= "  // debug\n";
    $html .= "  'debug' => true,\n";
    $html .= "];\n</pre>";
    return $html;
  }

  private function formatSettingValue($value): string {
    if (is_bool($value)) {
      return $value ? 'true' : 'false';
    }
    if (is_array($value)) {
      return '[' . implode(', ', array_map('strval', $value)) . ']';
    }
    return (string) $value;
  }

  private function isDefaultSetting(string $key, string &$display): bool {
    $isDefault = false;
    if ($key === 'linkSync' && $display === 'false') {
      $isDefault = true;
    } elseif ($key === 'markdownField' && $display === 'md_markdown') {
      $isDefault = true;
    } elseif ($key === 'hashField' && $display === 'md_markdown_hash') {
      $isDefault = true;
    } elseif ($key === 'sourcePath' && $display === 'content/') {
      $isDefault = true;
    } elseif ($key === 'autoSyncFrontmatter' && $display === 'true') {
      $isDefault = true;
    } elseif ($key === 'excludeFrontmatterFields' && $display === '[name]') {
      $isDefault = true;
    } elseif ($key === 'enabledTemplates' && $display === '[]') {
      $isDefault = true;
    } elseif ($key === 'includeFrontmatterFields' && $display === '[]') {
      $isDefault = true;
    } elseif ($key === 'imageBaseUrl' && $display === '') {
      $display = $this->wire('config')->urls->files . '{pageId}/';
      $isDefault = true;
    } elseif ($key === 'imageSourcePaths' && $display === '[]') {
      $display = $this->wire('config')->paths->site . 'images/';
      $isDefault = true;
    } elseif ($key === 'debug' && $display === 'false') {
      $isDefault = true;
    }
    return $isDefault;
  }

  private function renderSettingRow(string $key, $value): string {
    $display = $this->formatSettingValue($value);
    $isDefault = $this->isDefaultSetting($key, $display);

    $status = $isDefault ? 'Default' : 'Custom';
    $statusBg = $isDefault ? '#e8f5e9' : '#fff3e0';
    $statusColor = $isDefault ? '#2e7d32' : '#e65100';

    $html = '<tr>';
    $html .= '<td style="padding:8px; border:1px solid #ddd;"><code>' . htmlspecialchars($key) . '</code></td>';
    $html .= '<td style="padding:8px; border:1px solid #ddd;"><code>' . htmlspecialchars($display) . '</code></td>';
    $html .= '<td style="padding:8px; border:1px solid #ddd; text-align:center; background:' . $statusBg . '; color:' . $statusColor . '; font-weight:500;">' . $status . '</td>';
    $html .= '</tr>';
    return $html;
  }

  /** Remove module fields and clean up configuration. */
  public function uninstall()
  {
    self::$uninstalling = true;
    
    $fields = $this->wire('fields');
    $templates = $this->wire('templates');
    $log = $this->wire('log');
    
    // Get all fields created by this module
    $fieldNames = array_values(array_unique(array_merge(
      array_keys($this->fieldDefs),
      ['md_editor'],
    )));
    $fieldsInUse = false;
    
    // Check if any field is still assigned to a template
    foreach ($fieldNames as $fieldName) {
      $field = $fields->get($fieldName);
      if (!$field) {
        continue;
      }
      
      foreach ($templates as $template) {
        if ($template->fieldgroup->has($field)) {
          $fieldsInUse = true;
          break 2;
        }
      }
    }
    
    // If any fields are in use, abort uninstall
    if ($fieldsInUse) {
      throw new WireException(
        'Cannot uninstall MarkdownToFields: some fields are still in use. ' .
        'Go to the module settings and uncheck all templates in "Enabled templates" to remove these fields.'
      );
    }
    
    // Remove and delete fields (reverse order for fieldset pairs)
    $fieldsToDelete = array_reverse($fieldNames);
    $deletedCount = 0;
    
    foreach ($fieldsToDelete as $fieldName) {
      $field = $fields->get($fieldName);
      if (!$field) {
        continue;
      }
      
      // Remove from all templates/fieldgroups
      foreach ($templates as $template) {
        // SAFETY: Reload fieldgroup fresh from database
        $fieldgroup = $this->wire('fieldgroups')->get($template->fieldgroup->id);
        if (!$fieldgroup) continue;
        
        if ($fieldgroup->has($field)) {
          $fieldgroup->remove($field);
          $fieldgroup->save();
        }
      }
      
      // Reset flags before deletion
      $field->flags = 0;
      $fields->save($field);
      
      // Delete the field
      try {
        $deleted = $fields->delete($field);
        if ($deleted) {
          $deletedCount++;
        } else {
          throw new WireException("Field deletion returned false");
        }
      } catch (\Throwable $e) {
        throw new WireException("Cannot delete field '$fieldName': " . $e->getMessage());
      }
    }
    
    // Clear module config
    $this->wire('modules')->saveConfig($this, ['templates' => []]);
    
    if ($deletedCount > 0) {
      MarkdownUtilities::logChannel(
        null,
        "Uninstall complete: removed $deletedCount fields",
      );
    }
  }

  /** Sync page fields from markdown files. */
  public static function sync(Page $page): array
  {
    return MarkdownSyncEngine::syncFromMarkdown($page);
  }

  /** Load parsed markdown content for a page. */
  public static function load(Page $page, $language = null): ?ContentData
  {
    return MarkdownFileIO::loadMarkdown($page, $language);
  }

  /** Save page fields to markdown files. */
  public static function save(Page $page, $language = null): void
  {
    MarkdownSyncEngine::syncToMarkdown($page, null, null);
  }

  /** Sync template field associations for configured templates. */
  public function syncTemplateFields(): void
  {
    if (self::$uninstalling) {
      MarkdownUtilities::logChannel(
        null,
        'Template field sync skipped (uninstalling)',
      );
      return;
    }
    
    $this->createFields();
    $this->removeLegacyEditorField();
    $enabled = $this->getEffectiveEnabledTemplates();
    $templates = $this->wire('templates');

    foreach ($templates as $template) {
      if ($this->isTemplateExcluded($template)) continue;

      $shouldHaveFields = in_array($template->name, $enabled, true);
      $this->syncTemplateFieldgroup($template, $shouldHaveFields);
    }
  }

  /** Sync a single template's fieldgroup for Markdown fields. */
  private function syncTemplateFieldgroup(Template $template, bool $enabled): void
  {
    $fields = $this->wire('fields');
    $fieldgroup = $template->fieldgroup;
    $changed = false;
    
    // SAFETY: Force fresh reload from database to ensure complete state
    // This prevents issues where WireArray might have stale/partial field data
    $fieldgroupId = $fieldgroup->id;
    if ($fieldgroupId) {
      // Clear any cached state and reload
      $fieldgroup = $this->wire('fieldgroups')->get($fieldgroupId);
      if (!$fieldgroup) {
        MarkdownUtilities::logChannel(
          null,
          'Fieldgroup reload failed',
          ['template' => $template->name],
        );
        return;
      }
    }
    
    // SAFETY: Track existing non-markdown fields before modification
    $nonMDFieldsBefore = [];
    foreach ($fieldgroup as $f) {
      if (strpos($f->name, 'md_') !== 0) {
        $nonMDFieldsBefore[$f->name] = $f;
      }
    }

    foreach (array_keys($this->fieldDefs) as $name) {
      $field = $fields->get($name);
      if (!$field) continue;

      $has = $fieldgroup->has($field);
      if ($enabled && !$has) {
        $fieldgroup->add($field);
        $changed = true;
      } elseif (!$enabled && $has) {
        $fieldgroup->remove($field);
        $changed = true;
      }
    }

    if (!$changed) return;
    
    // SAFETY: Verify non-markdown fields are still present after modifications
    $nonMDFieldsAfter = [];
    foreach ($fieldgroup as $f) {
      if (strpos($f->name, 'md_') !== 0) {
        $nonMDFieldsAfter[$f->name] = $f;
      }
    }
    
    $lostFields = array_diff_key($nonMDFieldsBefore, $nonMDFieldsAfter);
    if (!empty($lostFields)) {
      // CRITICAL: Don't save if we're losing non-markdown fields!
      MarkdownUtilities::logChannel(
        null,
        'CRITICAL: Field loss detected',
        [
          'template' => $template->name,
          'lostFields' => implode(', ', array_keys($lostFields)),
        ],
      );
      return;  // Abort save to prevent data loss
    }

    $fieldgroup->save();
  }

  private function createFields(): void
  {
    $fields = $this->wire('fields');
    $modules = $this->wire('modules');

    $hasLanguages = $modules->isInstalled('LanguageSupport');

    $resolveType = function (string $name, string $defaultType) use ($hasLanguages, $modules) {
      // md_markdown should be language-aware when languages are installed
      if ($hasLanguages && $name === 'md_markdown') {
        if ($defaultType === 'FieldtypeTextarea') {
          return $modules->get('FieldtypeTextareaLanguage');
        }
      }

      // md_markdown_hash and md_link_index should remain single-language JSON/text
      if (in_array($name, ['md_markdown_hash', 'md_link_index'], true)) {
        return $modules->get($defaultType);
      }

      if ($hasLanguages && $defaultType === 'FieldtypeText') {
        return $modules->get('FieldtypeTextLanguage');
      }

      return $modules->get($defaultType);
    };

    foreach ($this->fieldDefs as $name => [$type, $label]) {
      if (!$fields->get($name)) {
        $f = new Field();
        $f->type = $resolveType($name, $type);
        $f->name = $name;
        $f->label = $label;
        $f->tags = 'markdown';
        
        // Configure markdown textarea with larger editor
        if ($name === 'md_markdown') {
          $f->rows = 40;
        }

        if ($name === 'md_link_index') {
          $f->rows = 8;
        }

        $fields->save($f);
      }
    }
  }

  private function removeLegacyEditorField(): void
  {
    $fields = $this->wire('fields');
    $templates = $this->wire('templates');
    $field = $fields->get('md_editor');

    if (!$field) {
      return;
    }

    foreach ($templates as $template) {
      $fieldgroup = $template->fieldgroup;
      if (!$fieldgroup || !$fieldgroup->has($field)) {
        continue;
      }

      $fieldgroup = $this->wire('fieldgroups')->get($fieldgroup->id);
      if (!$fieldgroup || !$fieldgroup->has($field)) {
        continue;
      }

      $fieldgroup->remove($field);
      $fieldgroup->save();
    }

    $field->flags = 0;
    $fields->save($field);
    $fields->delete($field);
  }

  private function isTemplateExcluded(Template $template): bool
  {
    return $template->name === 'admin' || (($template->flags ?? 0) & Template::flagSystem);
  }

}

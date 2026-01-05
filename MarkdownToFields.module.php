<?php

namespace ProcessWire;

use LetMeDown\ContentData;
use ProcessWire\MarkdownSyncHooks;

// Load dependencies and module classes early so they are available before init hooks run
$__moduleVendor = __DIR__ . '/vendor/autoload.php';
if (is_file($__moduleVendor)) {
  require_once $__moduleVendor;
}
require_once __DIR__ . '/MarkdownContent.php';
require_once __DIR__ . '/MarkdownUtilities.php';
require_once __DIR__ . '/MarkdownDocumentParser.php';
require_once __DIR__ . '/MarkdownLanguageResolver.php';
require_once __DIR__ . '/MarkdownConfig.php';
require_once __DIR__ . '/MarkdownFileIO.php';
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
 * @property string $htmlField     Markdown editor field (authoring surface)
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
    'md_markdown_tab_END' => ['FieldtypeFieldsetClose', 'Close Markdown tab'],
    'md_editor' => ['FieldtypeTextarea', 'Content editor'],
  ];

  /** Provide module metadata to ProcessWire. */
  public static function getModuleInfo()
  {
    return [
      'title' => 'Markdown to fields',
      'version' => '1.1.0',
      'summary' => 'Markdown files as your content source of truth',
      'description' =>
        'Parse markdown into structured content API, sync bidirectionally with fields.',
      'author' => 'Lemachi Narbo',
      'icon' => 'pencil-square-o',
      'requires' => 'PHP>=8.0',
      'autoload' => true,
      'singular' => true,
      'href' => '',
    ];
  }

  /** Single source of truth for required md_editor configuration. */
  protected function getRequiredMarkdownEditorConfig(): array
  {
    $contentStyle = '.md-comment-placeholder{display:inline-block;padding:2px 6px;border-radius:4px;font-family:monospace;opacity:.9;} .md-comment-placeholder--section{display:block;width:100%;text-align:center;background:#ececec;color:#555;font-size:12px;font-weight:600;} .md-comment-placeholder--sub{display:block;width:100%;background:#f2f2f2;color:#666;font-size:11px;font-weight:500;} .md-comment-placeholder--field{background:#f7f7f7;color:#666;font-size:10px;border-left:4px solid #d0d0d0;padding-left:6px;} .md-comment-placeholder--close{background:#e8e8e8;color:#888;font-size:10px;font-style:italic;} img{pointer-events:none;}';

    return [
      'tags' => 'markdown',
      'inputfieldClass' => 'InputfieldTinyMCE',
      'contentType' => 1,
      'height' => 1000,
      'rows' => 40,
      'features' => ['toolbar', 'stickybars', 'purifier', 'imgResize', 'pasteFilter'],
      'plugins' => ['anchor', 'code', 'link', 'lists', 'pwlink', 'table', 'noneditable'],
      'toolbar' => 'styles bold italic pwlink blockquote bullist numlist anchor code',
      'settingsJSON' => json_encode([
        'noneditable_class' => 'md-comment-placeholder',
        'content_style' => $contentStyle,
        'object_resizing' => false,
      ]),
    ];
  }

  /** Apply required config wholesale to a field. */
  protected function applyMarkdownEditorConfig(Field $field, array $cfg): void
  {
    foreach ($cfg as $key => $val) {
      if (property_exists($field, $key)) {
        $field->$key = $val;
      } else {
        $field->set($key, $val);
      }
    }
  }

  /** Register hooks and sync template fields. */
  public function init()
  {
    $this->syncTemplateFields();

    $this->addHook('ProcessPageEdit::buildForm', MarkdownSyncHooks::class . '::prepareEditForm');
    $this->addHook('ProcessPageEdit::buildFormContent', MarkdownSyncHooks::class . '::appendHashField');
    $this->addHook('Pages::saveReady', MarkdownSyncHooks::class . '::handleSaveReady');
    $this->addHookAfter('ProcessPageEdit::execute', MarkdownSyncHooks::class . '::enqueueAssets');
    $this->addHookAfter('ProcessPageEdit::buildForm', MarkdownSyncHooks::class . '::lockRawMarkdownField');
    $this->addHookAfter('Modules::refresh', MarkdownSyncHooks::class . '::handleModulesRefresh');
    $this->addHookAfter('Modules::saveConfig', MarkdownSyncHooks::class . '::handleSaveConfig');
  }

  /** Create required fields and set default configuration. */
  public function install()
  {
    $this->syncTemplateFields();
    $this->wire('modules')->saveConfig($this, [
      'htmlField' => 'md_editor',
      'templates' => [],
    ]);
  }

  /** Build module configuration form. */
  public function getModuleConfigInputfields(array $data): InputfieldWrapper
  {
    $defaults = [
      'templates' => (array) ($this->templates ?? []),
    ];
    $data = array_merge($defaults, $data);

    $modules = $this->wire('modules');
    $templates = $this->wire('templates');
    $wrapper = new InputfieldWrapper();
    $config = $this->wire('config');
    $mdConfig = $config->MarkdownToFields ?? [];

    // Template selection only
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

    $checkboxes = $modules->get('InputfieldCheckboxes');
    $checkboxes->attr('name', 'templates');
    $checkboxes->label = 'Enabled templates';
    $checkboxes->description = 'Enable Markdown sync for these templates. System and admin templates are ignored.';
    $checkboxes->addOptions($options);
    $checkboxes->attr('value', $data['templates']);
    $wrapper->add($checkboxes);

    // Configuration and editor field setup
    $configFieldset = $modules->get('InputfieldFieldset');
    $configFieldset->name = 'editor_field_setup';
    $configFieldset->label = 'Editor Field Setup';
    $configFieldset->description = 'If you choose a different TinyMCE field in config.php to be the content editor, use this option to set up the required settings. Or use it to reset to defaults if needed.';
    
    $currentField = $mdConfig['htmlField'] ?? 'md_editor';
    
    $setupCheckbox = $modules->get('InputfieldCheckbox');
    $setupCheckbox->name = 'configure_editor_field';
    $setupCheckbox->label = 'Apply default settings to ' . htmlspecialchars($currentField) . '?';
    $setupCheckbox->description = 'Applies required TinyMCE settings: noneditable plugin, disabled image interaction, and custom CSS.';
    $setupCheckbox->attr('value', 1);
    $configFieldset->add($setupCheckbox);
    
    $wrapper->add($configFieldset);

    // Configuration reference
    $refFieldset = $modules->get('InputfieldFieldset');
    $refFieldset->name = 'configuration_reference';
    $refFieldset->label = 'Configuration Reference';
    $refFieldset->description = 'All settings are managed in /site/config.php';
    
    $refMarkup = $modules->get('InputfieldMarkup');
    $html = '<p>To customize field names and paths, add to <code>/site/config.php:</code></p>';
    $html .= '<pre style="background:#f0f0f0; padding:12px; border-radius:4px; overflow-x:auto; font-size:11px;">$config->MarkdownToFields = [' . "\n";
    $html .= '  \'htmlField\' => \'md_editor\',           // Editor field' . "\n";
    $html .= '  \'markdownField\' => \'md_markdown\',     // Raw markdown' . "\n";
    $html .= '  \'hashField\' => \'md_markdown_hash\',    // Sync state' . "\n";
    $html .= '  \'sourcePath\' => \'content/\',           // Markdown location' . "\n";
    $html .= '];' . "\n</pre>";
    
    $html .= '<h4>Current values:</h4>';
    $html .= '<table style="width:100%; border-collapse:collapse; font-size:12px;">';
    $html .= '<tr style="background:#f5f5f5;">';
    $html .= '<th style="text-align:left; padding:8px; border:1px solid #ddd;">Setting</th>';
    $html .= '<th style="text-align:left; padding:8px; border:1px solid #ddd;">Value</th>';
    $html .= '</tr>';
    
    $settings = [
      'htmlField' => $mdConfig['htmlField'] ?? 'md_editor',
      'markdownField' => $mdConfig['markdownField'] ?? 'md_markdown',
      'hashField' => $mdConfig['hashField'] ?? 'md_markdown_hash',
      'sourcePath' => $mdConfig['sourcePath'] ?? 'content/',
    ];
    
    foreach ($settings as $key => $value) {
      $html .= '<tr><td style="padding:8px; border:1px solid #ddd;"><code>' . htmlspecialchars($key) . '</code></td>';
      $html .= '<td style="padding:8px; border:1px solid #ddd;"><code>' . htmlspecialchars($value) . '</code></td></tr>';
    }
    $html .= '</table>';
    
    $refMarkup->value = $html;
    $refFieldset->add($refMarkup);
    $wrapper->add($refFieldset);

    return $wrapper;
  }

  /** Remove module fields and clean up configuration. */
  public function uninstall()
  {
    self::$uninstalling = true;
    
    $fields = $this->wire('fields');
    $templates = $this->wire('templates');
    $log = $this->wire('log');
    
    // Get all fields created by this module
    $fieldNames = array_keys($this->fieldDefs);
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
        if ($template->fieldgroup->has($field)) {
          $template->fieldgroup->remove($field);
          $template->fieldgroup->save();
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
      $log->save('markdown-sync', "Uninstall complete: removed $deletedCount fields");
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

  /** Checks if the field uses a TinyMCE textarea editor. */
  public function isMarkdownEditorCompatible(Field $field): bool
  {
    if ($field->type->name !== 'FieldtypeTextarea') {
      return false;
    }
    if ($field->inputfieldClass !== 'InputfieldTinyMCE') {
      return false;
    }
    
    return true;
  }

  /** Sync template field associations for configured templates. */
  public function syncTemplateFields(): void
  {
    if (self::$uninstalling) {
      $this->wire('log')->save('markdown-sync', 'Template field sync skipped (uninstalling)');
      return;
    }
    
    $this->createFields();

    $enabled = (array) ($this->templates ?? []);
    $fields = $this->wire('fields');
    $templates = $this->wire('templates');
    $currentEditorField = $this->htmlField ?? 'md_editor';

    // Detect programmatic overrides: scan enabled template page classes for htmlField
    $pageClassOverride = null;
    foreach ($templates as $tmpl) {
      if ($this->isTemplateExcluded($tmpl)) continue;
      if (!in_array($tmpl->name, $enabled, true)) continue;

      $pageClass = $tmpl->pageClass;
      if (!$pageClass) {
        try {
          $pages = $this->wire('pages');
          $firstPage = $pages->findOne("template={$tmpl->id}");
          if ($firstPage) {
            $pageClass = get_class($firstPage);
          }
        } catch (\Throwable $e) {
          // skip
        }
      }

      if ($pageClass && class_exists($pageClass)) {
        try {
          $refl = new \ReflectionClass($pageClass);
          if ($refl->hasProperty('htmlField')) {
            $prop = $refl->getProperty('htmlField');
            if ($prop->hasDefaultValue()) {
              $val = $prop->getDefaultValue();
              $this->wire('log')->save('markdown-sync', "Reflection: found htmlField in {$pageClass} with default={$val}");
              if (is_string($val) && $val !== '') {
                $pageClassOverride = $val;
                $this->wire('log')->save('markdown-sync', "Programmatic override detected: htmlField={$val}");
                break;
              }
            }
          }
        } catch (\Throwable $e) {
          $this->wire('log')->save('markdown-sync', "Reflection error: " . $e->getMessage());
        }
      } else {
        $this->wire('log')->save('markdown-sync', "No page class found for template {$tmpl->name}");
      }
    }

    // If override found and valid, use it and update module config (preserving templates)
    if ($pageClassOverride) {
      $overrideField = $fields->get($pageClassOverride);
      if ($overrideField && $this->isMarkdownEditorCompatible($overrideField)) {
        $currentEditorField = $pageClassOverride;
        if ($this->htmlField !== $pageClassOverride) {
          $this->htmlField = $pageClassOverride;
          // Preserve existing config (especially templates) when updating htmlField
          $currentConfig = $this->wire('modules')->getConfig($this) ?? [];
          $currentConfig['htmlField'] = $pageClassOverride;
          $this->wire('modules')->saveConfig($this, $currentConfig);
          $this->wire('log')->save('markdown-sync', "Programmatic override detected: htmlField={$pageClassOverride}");
        }
      }
    }

    foreach ($templates as $template) {
      if ($this->isTemplateExcluded($template)) continue;

      $shouldHaveFields = in_array($template->name, $enabled, true);
      $fieldgroup = $template->fieldgroup;

      foreach (array_keys($this->fieldDefs) as $name) {
        if ($name === 'md_editor') continue;
        $field = $fields->get($name);
        if (!$field) continue;

        $has = $fieldgroup->has($field);
        if ($shouldHaveFields && !$has) {
          $fieldgroup->add($field);
        } elseif (!$shouldHaveFields && $has) {
          $fieldgroup->remove($field);
        }
      }

      $mdEditorField = $fields->get('md_editor');
      $selectedEditorField = $fields->get($currentEditorField);
      
      if ($shouldHaveFields) {
        if ($selectedEditorField && !$fieldgroup->has($selectedEditorField)) {
          $fieldgroup->add($selectedEditorField);
        }
        if ($mdEditorField && $currentEditorField !== 'md_editor' && $fieldgroup->has($mdEditorField)) {
          $fieldgroup->remove($mdEditorField);
        }
      } else {
        if ($mdEditorField && $fieldgroup->has($mdEditorField)) {
          $fieldgroup->remove($mdEditorField);
        }
        if ($selectedEditorField && $fieldgroup->has($selectedEditorField)) {
          $fieldgroup->remove($selectedEditorField);
        }
      }

      $fieldgroup->save();
    }
  }

  private function createFields(): void
  {
    $fields = $this->wire('fields');
    $modules = $this->wire('modules');

    $hasLanguages = $modules->isInstalled('LanguageSupport');

    $resolveType = function (string $name, string $defaultType) use ($hasLanguages, $modules) {
      // md_markdown and md_editor should be language-aware when languages are installed
      if ($hasLanguages && in_array($name, ['md_markdown', 'md_editor'], true)) {
        if ($defaultType === 'FieldtypeTextarea') {
          return $modules->get('FieldtypeTextareaLanguage');
        }
      }

      // md_markdown_hash should remain single-language (hash map stored as JSON)
      if ($name === 'md_markdown_hash') {
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


        
        // Configure md_editor field authoritatively
        if ($name === 'md_editor') {
          $this->applyMarkdownEditorConfig($f, $this->getRequiredMarkdownEditorConfig());
        }

        $fields->save($f);
      }
    }
  }

  private function isTemplateExcluded(Template $template): bool
  {
    return $template->name === 'admin' || (($template->flags ?? 0) & Template::flagSystem);
  }

  /** Applies the required TinyMCE configuration to the editor field. */
  public function repairMarkdownEditor(?string $fieldName = null): void
  {
    $fieldName = $fieldName ?: 'md_editor';
    $fields = $this->wire('fields');
    $modules = $this->wire('modules');
    $required = $this->getRequiredMarkdownEditorConfig();

    $field = $fields->get($fieldName);
    if (!$field) {
      $field = new Field();
      $field->type = $modules->get('FieldtypeTextarea');
      $field->name = $fieldName;
      $field->label = 'Content Editor';
      $this->applyMarkdownEditorConfig($field, $required);
      $fields->save($field);
      return;
    }

    $this->applyMarkdownEditorConfig($field, $required);
    $fields->save($field);
  }
}

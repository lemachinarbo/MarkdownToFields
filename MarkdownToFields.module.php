<?php

namespace ProcessWire;

use LetMeDown\ContentData;
use ProcessWire\ConfigurableModule;
use ProcessWire\Field;
use ProcessWire\InputfieldWrapper;
use ProcessWire\MarkdownSyncer;
use ProcessWire\MarkdownSyncHooks;
use ProcessWire\Template;

// Load dependencies and module classes early so they are available before init hooks run
$__moduleVendor = __DIR__ . '/vendor/autoload.php';
if (is_file($__moduleVendor)) {
  require_once $__moduleVendor;
}
require_once __DIR__ . '/MarkdownContent.php';
require_once __DIR__ . '/MarkdownSyncer.php';
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
  private array $fieldDefs = [
    'md_markdown_tab' => ['FieldtypeFieldsetTabOpen', 'Markdown'],
    'md_markdown_source' => ['FieldtypeText', 'Source file path'],
    'md_markdown' => ['FieldtypeTextarea', 'Markdown editor'],
    'md_markdown_hash' => ['FieldtypeText', 'Markdown hash'],
    'md_markdown_tab_END' => ['FieldtypeFieldsetClose', 'Close Markdown tab'],
    'md_editor' => ['FieldtypeTextarea', 'Content editor'],
  ];

  public static function getModuleInfo()
  {
    return [
      'title' => 'Markdown to fields',
      'version' => '1.0.3',
      'summary' => 'Markdown files as your content source of truth',
      'description' =>
        'Parse markdown into structured content API, sync bidirectionally with fields.',
      'author' => 'Lemachi Narbo',
      'icon' => 'pencil',
      'requires' => 'PHP>=8.0',
      'autoload' => true,
      'singular' => true,
      'href' => '',
    ];
  }

  /**
   * Single source of truth for required md_editor configuration.
   */
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

  /**
   * Apply required config wholesale to a field (authoritative setup).
   */
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

  public function init()
  {
    $this->syncTemplateFields();

    // Register hooks
    $this->addHook(
      'ProcessPageEdit::buildForm',
      MarkdownSyncHooks::class . '::prepareEditForm',
    );
    $this->addHook(
      'ProcessPageEdit::buildFormContent',
      MarkdownSyncHooks::class . '::appendHashField',
    );
    $this->addHook(
      'Pages::saveReady',
      MarkdownSyncHooks::class . '::handleSaveReady',
    );

    $this->addHookAfter('ProcessPageEdit::execute', function() {
      wire('config')->scripts->add(
          $this->config->urls->MarkdownToFields . 'assets/markdown-editor.js'
      );
    });

    // UI behavior: when building the edit form, ensure the raw markdown field is disabled when locked
    $this->addHookAfter('ProcessPageEdit::buildForm', function(HookEvent $event) {
      try {
        $page = $event->arguments(0);
        $form = $event->return ?: null;
        if (!$form || !$page) return;


        // Locate lock and markdown inputs in the form (they are added via field groups)
        if (method_exists($form, 'get')) {
          $mdInput = $form->get('md_markdown');
          $mdEditorInput = $form->get('md_editor');



          if ($mdInput) {
            // Start with the raw markdown textarea disabled by default (unchecked transient)
            $mdInput->attr('disabled', 'disabled');

            // UX: brief description instructing how to enable editing
            $mdInput->description = 'Double-click the field to edit the Markdown content.
            While editing Markdown, do not modify the same content in other fields (such as the title or content editor) to avoid losing changes.';

          }

            // Ensure raw textarea starts disabled by default (overlay will enable editing)
            if ($mdInput) {
              $mdInput->attr('disabled', 'disabled');
            }
        }
      } catch (\Throwable $e) {
        // be defensive; do not break form rendering
      }
    });


    $this->addHookAfter(
      'Modules::refresh',
      MarkdownSyncHooks::class . '::handleModulesRefresh',
    );

    // Auto-refresh modules and auto-configure editor field after config save
    $this->addHookAfter(
      'Modules::saveConfig',
      function(HookEvent $event) {
        $module = $event->arguments(0);
        $moduleName = $event->arguments(1) ?? '';
        
        // $module might be a string (module class name)
        $moduleStr = is_string($module) ? $module : (is_object($module) ? get_class($module) : '');
        
        if ($moduleStr === 'MarkdownToFields' || $moduleName === 'MarkdownToFields' || $module instanceof MarkdownToFields) {
          $this->syncTemplateFields();
          
          // Configure editor field only if checkbox is checked
          $configure = $this->wire('input')->post('configure_editor_field') ?? null;
          if ($configure) {
            $config = $this->wire('config');
            $mdConfig = $config->MarkdownToFields ?? [];
            $fieldName = $mdConfig['htmlField'] ?? 'md_editor';
            $this->repairMarkdownEditor($fieldName);
            
            // Show success message
            $this->message("Editor field '{$fieldName}' has been configured with required TinyMCE settings.");
            $this->wire('log')->save('markdown-sync', "Editor field '{$fieldName}' configured with required TinyMCE settings.");
          }
          
          $this->wire('log')->save('markdown-sync', 'Template field sync complete.');
        }
      }
    );

    // Pages::saved finalizer removed — writing is centralized in MarkdownSyncer::syncToMarkdown to avoid race conditions and duplicated logic.

  }

  public function install()
  {
    $this->syncTemplateFields();
    
    // Set default config
    $this->wire('modules')->saveConfig($this, [
      'htmlField' => 'md_editor',
      'templates' => [],
    ]);
  }

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
    $html .= '  \'sourcePageField\' => \'md_markdown_source\', // File ref' . "\n";
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
      'sourcePageField' => $mdConfig['sourcePageField'] ?? 'md_markdown_source',
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

  public function uninstall()
  {
    $this->wire('modules')->saveConfig($this, ['templates' => []]);
  }

  /**
   * Static shortcut to MarkdownSyncer methods
   */
  public static function sync(Page $page): array
  {
    return MarkdownSyncer::syncFromMarkdown($page);
  }

  public static function load(Page $page, $language = null): ?ContentData
  {
    return MarkdownSyncer::loadMarkdown($page, $language);
  }

  public static function save(Page $page, $language = null): void
  {
    MarkdownSyncer::syncToMarkdown($page, null, null);
  }

  /**
   * Check if a field can be used as a markdown editor surface.
   * Shallow check: just textarea + TinyMCE. Auto-repair on save adds required config.
   *
   * @param Field $field The field to check
   * @return bool True if field is textarea + TinyMCE
   */
  public function isMarkdownEditorCompatible(Field $field): bool
  {
    // Must be textarea type
    if ($field->type->name !== 'FieldtypeTextarea') {
      return false;
    }
    
    // Must use TinyMCE inputfield
    if ($field->inputfieldClass !== 'InputfieldTinyMCE') {
      return false;
    }
    
    return true;
  }

  private function syncTemplateFields(): void
  {
    // Create missing fields only; authoritative repair is explicit
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

      // Try to get page class: first from template->pageClass, then from first page using template
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

      // Manage all markdown-sync fields except the editor field (handled separately)
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

      // Editor field management: ensure only the selected editor is attached
      $mdEditorField = $fields->get('md_editor');
      $selectedEditorField = $fields->get($currentEditorField);
      
      if ($shouldHaveFields) {
        // Add selected editor if missing
        if ($selectedEditorField && !$fieldgroup->has($selectedEditorField)) {
          $fieldgroup->add($selectedEditorField);
        }
        // Remove md_editor if it's present but not the selected editor
        if ($mdEditorField && $currentEditorField !== 'md_editor' && $fieldgroup->has($mdEditorField)) {
          $fieldgroup->remove($mdEditorField);
        }
      } else {
        // If template disabled, remove both possible editor fields
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

    foreach ($this->fieldDefs as $name => [$type, $label]) {
      if (!$fields->get($name)) {
        $f = new Field();
        $f->type = $modules->get($type);
        $f->name = $name;
        $f->label = $label;
        $f->tags = 'markdown';
        
        // Configure source field with validation pattern
        if ($name === 'md_markdown_source') {
          $f->description = 'Relative path to markdown file (e.g., home.md, book/chapter.md). Must end with .md. Leave empty to use page name.';
          $f->pattern = '^(([a-zA-Z0-9_-]+\\/)*)?[a-zA-Z0-9_-]+\\.md$';
          $f->size = 255;
        }
        
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

  // ensureMdEditorConfigured removed: authoritative setup via Repair action only

  private function isTemplateExcluded(Template $template): bool
  {
    return $template->name === 'admin' || (($template->flags ?? 0) & Template::flagSystem);
  }

  /**
   * Repair action: replace config wholesale for selected editor field or create md_editor.
   */
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

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
    'md_markdown' => ['FieldtypeTextarea', 'Markdown'],
    'md_markdown_hash' => ['FieldtypeText', 'Markdown hash'],
    'md_markdown_tab_END' => ['FieldtypeFieldsetClose', 'Close Markdown tab'],
    'md_editor' => ['FieldtypeTextarea', 'Content Editor'],
  ];

  public static function getModuleInfo()
  {
    return [
      'title' => 'Markdown to fields',
      'version' => '1.0.2',
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
    $this->addHookAfter(
      'Modules::refresh',
      MarkdownSyncHooks::class . '::handleModulesRefresh',
    );

    // Auto-refresh modules after config save and configure selected editor field
    $this->addHookAfter(
      'Modules::saveConfig',
      function(HookEvent $event) {
        $module = $event->arguments(0);
        $moduleName = $event->arguments(1) ?? '';
        
        // $module might be a string (module class name)
        $moduleStr = is_string($module) ? $module : (is_object($module) ? get_class($module) : '');
        
        if ($moduleStr === 'MarkdownToFields' || $moduleName === 'MarkdownToFields' || $module instanceof MarkdownToFields) {
          $this->syncTemplateFields();
          // Auto-configure the selected editor field
          $this->repairMarkdownEditor($this->htmlField ?? 'md_editor');
          // Explicit restore to defaults action
          $restore = $this->wire('input')->post('restore_default_editor') ?? null;
          if ($restore) {
              // Restore the currently selected editor field (fallback to md_editor)
              $target = $this->wire('input')->post('htmlField') ?? ($this->htmlField ?? 'md_editor');
              $this->repairMarkdownEditor($target);
              $this->wire('log')->save('markdown-sync', "Editor field '{$target}' restored to required configuration.");
          }
          $this->wire('log')->save('markdown-sync', 'Template field sync and editor configuration complete.');
        }
      }
    );
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
      'htmlField' => $this->htmlField ?? 'md_editor',
      'debug' => (bool) ($this->debug ?? false),
    ];
    $data = array_merge($defaults, $data);

    $modules = $this->wire('modules');
    $templates = $this->wire('templates');
    $fields = $this->wire('fields');
    $wrapper = new InputfieldWrapper();

    // Template selection
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
    $checkboxes->label = 'Select templates';
    $checkboxes->description =
      'Select the templates where Markdown should be used as the content source:
      - When a template is enabled, the module adds a Markdown tab and its editor fields. 
      - Disable a template to remove those module fields. 
      
      System and admin templates are ignored.';

    $checkboxes->notes =
      'These checkboxes only manage fields created by the module. 
      Custom or user-selected fields are never added or removed.';

    $checkboxes->addOptions($options);
    $checkboxes->attr('value', $data['templates']);
    $wrapper->add($checkboxes);

    // HTML/Editor field selection (shallow compatibility: textarea + TinyMCE only)
    $f = $modules->get('InputfieldSelect');
    $f->name = 'htmlField';
    $f->label = 'Content Editor field';
    $f->description = 'By default, this module uses the md_editor TinyMCE field as the content editor. 
    You can replace it by selecting another TinyMCE field here.';
    $f->notes = "Only TinyMCE fields are shown.
    
    If you choose a different field, it will be automatically configured with the required TinyMCE settings (noneditable plugin, disabled image interaction, and custom CSS).";
    $f->columnWidth = 50;
    
    $compatibleFields = [];
    
    foreach ($fields as $field) {
      if ($field->type->name !== 'FieldtypeTextarea') continue;
      
      if ($this->isMarkdownEditorCompatible($field)) {
        $compatibleFields[] = $field;
        $label = $field->label ? "{$field->label} ({$field->name})" : $field->name;
        $f->addOption($field->name, $label);
      }
    }
    
    // If no compatible fields exist, guide user to create one
    if (count($compatibleFields) === 0) {
      $f->warning(
        'No compatible editor fields found. The "md_editor" field will be created on next modules refresh.'
      );
      $f->addOption('md_editor', 'md_editor (will be created)');
    }
    
    $f->attr('value', $data['htmlField']);

    
    // Two-column layout: left is the select, right is a fieldset
    $restoreFieldset = $modules->get('InputfieldFieldset');
    $restoreFieldset->label = 'Restore Selected Editor';
    $restoreFieldset->description = 'Reset the currently selected content editor field to the required TinyMCE configuration (noneditable plugin, disabled image interaction, custom CSS). If you select md_editor, it restores defaults for that field.';
    $restoreFieldset->columnWidth = 50;

    // Restore to defaults action
    $restoreBtn = $modules->get('InputfieldSubmit');
    $restoreBtn->name = 'restore_default_editor';
    $restoreBtn->value = 'Reset';
    $restoreBtn->columnWidth = 50;
    $restoreFieldset->add($restoreBtn);
    
    // Add select directly (no extra wrapper) and the restore fieldset side by side
    $wrapper->add($f);
    $wrapper->add($restoreFieldset);

    // Information section: field names and how to override programmatically
    $infoFieldset = $modules->get('InputfieldFieldset');
    $infoFieldset->label = 'Field Names & Programmatic Overrides';
    $infoFieldset->description = 'These are the field names used for markdown sync. You can override them per-page by setting protected properties in your page class.';
    
    $infoMarkup = $modules->get('InputfieldMarkup');
    $infoMarkup->value = '
<table style="width:100%; border-collapse:collapse; font-size:13px;">
  <tr style="background:#f5f5f5;">
    <th style="text-align:left; padding:8px; border:1px solid #ddd;"><strong>Internal name</strong></th>
    <th style="text-align:left; padding:8px; border:1px solid #ddd;"><strong>Field</strong></th>
    <th style="text-align:left; padding:8px; border:1px solid #ddd;"><strong>Purpose</strong></th>
  </tr>
  <tr>
    <td style="padding:8px; border:1px solid #ddd;"><code>htmlField</code></td>
    <td style="padding:8px; border:1px solid #ddd;"><code>' . htmlspecialchars($data['htmlField']) . '</code></td>
    <td style="padding:8px; border:1px solid #ddd;">Editor field (TinyMCE textarea)</td>
  </tr>
  <tr style="background:#fafafa;">
    <td style="padding:8px; border:1px solid #ddd;"><code>markdownField</code></td>
    <td style="padding:8px; border:1px solid #ddd;"><code>md_markdown</code></td>
    <td style="padding:8px; border:1px solid #ddd;">Raw markdown source</td>
  </tr>
  <tr>
    <td style="padding:8px; border:1px solid #ddd;"><code>hashField</code></td>
    <td style="padding:8px; border:1px solid #ddd;"><code>md_markdown_hash</code></td>
    <td style="padding:8px; border:1px solid #ddd;">Sync state tracker</td>
  </tr>
  <tr style="background:#fafafa;">
    <td style="padding:8px; border:1px solid #ddd;"><code>sourcePageField</code></td>
    <td style="padding:8px; border:1px solid #ddd;"><code>md_markdown_source</code></td>
    <td style="padding:8px; border:1px solid #ddd;">File reference field</td>
  </tr>
  <tr>
    <td style="padding:8px; border:1px solid #ddd;"><code>sourcePath</code></td>
    <td style="padding:8px; border:1px solid #ddd;"><code>site/content/</code></td>
    <td style="padding:8px; border:1px solid #ddd;">Base path for markdown files</td>
  </tr>
</table>

<h4 style="margin-top:16px;">Override per page:</h4>
<pre style="background:#f0f0f0; padding:12px; border-radius:4px; overflow-x:auto; font-size:12px;">class HomePage extends Page {
  use MarkdownContent;
  
  // Override defaults for this page only
  protected string $htmlField = \'custom_editor\';
  protected string $markdownField = \'content_md\';
  protected string $sourcePath = \'/var/markdown/\';
}</pre>

<p><strong>Priority:</strong> Page class override → Module config (htmlField only) → Trait defaults</p>
    ';
    $infoFieldset->add($infoMarkup);
    $wrapper->add($infoFieldset);

    // Debug mode checkbox (at the end)
    $debugCheck = $modules->get('InputfieldCheckbox');
    $debugCheck->name = 'debug';
    $debugCheck->label = 'Debug Mode';
    $debugCheck->description = 'Enable debug logging to markdown-sync.txt log file';
    $debugCheck->attr('value', 1);
    if ($data['debug']) {
      $debugCheck->attr('checked', 'checked');
    }
    $wrapper->add($debugCheck);

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

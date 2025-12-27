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

    // Auto-refresh modules after config save to pick up newly attached fields
    $this->addHookAfter(
      'Modules::saveConfig',
      function(HookEvent $event) {
        $module = $event->arguments(0);
        $moduleName = $event->arguments(1) ?? '';
        
        // $module might be a string (module class name)
        $moduleStr = is_string($module) ? $module : (is_object($module) ? get_class($module) : '');
        
        if ($moduleStr === 'MarkdownToFields' || $moduleName === 'MarkdownToFields' || $module instanceof MarkdownToFields) {
          $this->syncTemplateFields();
          $this->wire('log')->save('markdown-sync', 'Template field sync complete.');
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
    $checkboxes->label = 'Templates';
    $checkboxes->description =
      'Attach Markdown fields to selected templates. System/admin templates are ignored.';
    $checkboxes->addOptions($options);
    $checkboxes->attr('value', $data['templates']);
    $wrapper->add($checkboxes);

    // HTML/Editor field selection with compatibility filter
    $f = $modules->get('InputfieldSelect');
    $f->name = 'htmlField';
    $f->label = 'Markdown Editor Field (Authoring Surface)';
    $f->description = 'The field where users edit content. Must be a compatible TinyMCE editor.';
    $f->notes = 'Only compatible fields are shown. The field must support markdown placeholders.';
    
    $compatibleFields = [];
    $incompatibleFields = [];
    
    foreach ($fields as $field) {
      if ($field->type->name !== 'FieldtypeTextarea') continue;
      
      if ($this->isMarkdownEditorCompatible($field)) {
        $compatibleFields[] = $field;
        $label = $field->label ? "{$field->label} ({$field->name})" : $field->name;
        $f->addOption($field->name, $label);
      } else {
        $incompatibleFields[] = $field;
      }
    }
    
    // Warn if current selection is incompatible
    $currentField = $data['htmlField'] ? $fields->get($data['htmlField']) : null;
    if ($currentField && !$this->isMarkdownEditorCompatible($currentField)) {
      $f->error(
        "Currently selected field '{$currentField->name}' is not compatible with markdown editing. " .
        "It must be a TinyMCE editor with noneditable plugin configured for placeholders."
      );
    }
    
    // Show incompatible fields as info
    if (count($incompatibleFields) > 0) {
      $names = implode(', ', array_map(function($field) { return $field->name; }, $incompatibleFields));
      $f->notes .= "

**Incompatible textarea fields found:** {$names}
These fields lack proper TinyMCE configuration.";
    }
    
    // If no compatible fields exist, show warning
    if (count($compatibleFields) === 0) {
      $f->warning(
        'No compatible editor fields found. The "md_editor" field will be created/configured on next modules refresh.'
      );
      $f->addOption('md_editor', 'md_editor (will be created)');
    }
    
    $f->attr('value', $data['htmlField']);
    $wrapper->add($f);

    // Debug mode checkbox
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
   * Check if a field is compatible as a markdown editor surface.
   * This field is the authoring UI, not just storage—strict requirements apply.
   *
   * @param Field $field The field to check
   * @return bool True if field meets all markdown editor requirements
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
    
    // Must have contentType = 1 (Markup/HTML)
    if ($field->contentType != 1) {
      return false;
    }
    
    // Must have noneditable plugin configured for placeholders
    $settingsJSON = (string) ($field->settingsJSON ?? '');
    $plugins = $field->plugins ?? [];
    // Normalize plugins to array of strings
    if (is_string($plugins)) {
      $plugins = array_filter(array_map('trim', explode(',', $plugins)));
    } elseif (!is_array($plugins)) {
      $plugins = [];
    }

    $hasNonEditable = in_array('noneditable', $plugins, true) || strpos($settingsJSON, 'noneditable') !== false;
    if (!$hasNonEditable) {
      return false;
    }
    
    // Must have md-comment-placeholder class configured (via settingsJSON)
    $hasPlaceholderClass = strpos($settingsJSON, 'md-comment-placeholder') !== false || strpos($settingsJSON, 'noneditable_class') !== false;
    if (!$hasPlaceholderClass) {
      return false;
    }
    
    return true;
  }

  private function syncTemplateFields(): void
  {
    $this->createFields();
    $this->ensureMdEditorConfigured();

    $enabled = (array) ($this->templates ?? []);
    $fields = $this->wire('fields');
    $templates = $this->wire('templates');

    foreach ($templates as $template) {
      if ($this->isTemplateExcluded($template)) continue;

      $shouldHaveFields = in_array($template->name, $enabled, true);
      $fieldgroup = $template->fieldgroup;

      foreach (array_keys($this->fieldDefs) as $name) {
        $field = $fields->get($name);
        if (!$field) continue;

        $has = $fieldgroup->has($field);
        if ($shouldHaveFields && !$has) {
          $fieldgroup->add($field);
        } elseif (!$shouldHaveFields && $has) {
          $fieldgroup->remove($field);
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
        
        // Configure md_editor field with TinyMCE and markdown support
        if ($name === 'md_editor') {
          $f->inputfieldClass = 'InputfieldTinyMCE';
          $f->contentType = 1; // Markup/HTML
          $f->height = 1000;
          $f->rows = 40;
          $f->features = ['toolbar', 'stickybars', 'purifier', 'imgResize', 'pasteFilter'];
          // Plugins without ProcessWire image picker (pwimage) – images via markdown only
          $f->plugins = ['anchor', 'code', 'link', 'lists', 'pwlink', 'table', 'noneditable'];
          // Toolbar: remove pwimage button
          $f->toolbar = 'styles bold italic pwlink blockquote bullist numlist anchor code';
          
          // Configure TinyMCE placeholders and disable image interactions
          // Do NOT override plugins here; manage via field properties
          $tinymceSettings = [
            'noneditable_class' => 'md-comment-placeholder',
            // Prevent clicks on images inside the editor surface
            'content_style' => '.md-comment-placeholder{display:inline-block;padding:2px 6px;border-radius:4px;font-family:monospace;opacity:.9;} .md-comment-placeholder--section{display:block;width:100%;text-align:center;background:#ececec;color:#555;font-size:12px;font-weight:600;} .md-comment-placeholder--sub{display:block;width:100%;background:#f2f2f2;color:#666;font-size:11px;font-weight:500;} .md-comment-placeholder--field{background:#f7f7f7;color:#666;font-size:10px;border-left:4px solid #d0d0d0;padding-left:6px;} .md-comment-placeholder--close{background:#e8e8e8;color:#888;font-size:10px;font-style:italic;} img{pointer-events:none;}',
            // Disable object resizing (images, tables) to avoid accidental handles
            'object_resizing' => false,
          ];
          $f->settingsJSON = json_encode($tinymceSettings);
        }

        $fields->save($f);
      }
    }
  }

  private function ensureMdEditorConfigured(): void
  {
    $fields = $this->wire('fields');
    $existing = $fields->get('md_editor');
    if (!$existing) return;
    $modified = false;
    if ($existing->inputfieldClass !== 'InputfieldTinyMCE') { $existing->inputfieldClass = 'InputfieldTinyMCE'; $modified = true; }
    if ((int) $existing->contentType !== 1) { $existing->contentType = 1; $modified = true; }
    // Normalize plugins
    $plugins = $existing->plugins ?? [];
    if (is_string($plugins)) { $plugins = array_filter(array_map('trim', explode(',', $plugins))); }
    if (!is_array($plugins)) { $plugins = []; }
    // Required plugins (no pwimage)
    $required = ['anchor','code','link','lists','pwlink','table','noneditable'];
    $union = array_values(array_unique(array_merge($plugins, $required)));
    if ($union !== $plugins) { $existing->plugins = $union; $modified = true; }
    // Ensure toolbar contains pwimage
    $toolbar = (string) ($existing->toolbar ?? '');
    // Strip pwimage button if present
    if (strpos($toolbar, 'pwimage') !== false) { $existing->toolbar = trim(str_replace('pwimage', '', $toolbar)); $modified = true; }
    // Ensure placeholder class/style present and disable image interactions
    $settingsJSON = (string) ($existing->settingsJSON ?? '');
    $settings = [];
    if ($settingsJSON !== '') {
      $decoded = json_decode($settingsJSON, true);
      if (is_array($decoded)) { $settings = $decoded; }
    }
    // Remove any plugins override from settingsJSON (we manage plugins via field property)
    if (isset($settings['plugins'])) { unset($settings['plugins']); $modified = true; }
    if (!isset($settings['noneditable_class']) || strpos($settingsJSON, 'md-comment-placeholder') === false) {
      $settings['noneditable_class'] = 'md-comment-placeholder';
      $modified = true;
    }
    // Merge/append content_style with image pointer-events safeguard
    $contentStyle = isset($settings['content_style']) ? (string) $settings['content_style'] : '';
    $needsImgPE = (strpos($contentStyle, 'img{pointer-events:none') === false);
    if ($contentStyle === '') {
      $contentStyle = '.md-comment-placeholder{display:inline-block;padding:2px 6px;border-radius:4px;font-family:monospace;opacity:.9;} .md-comment-placeholder--section{display:block;width:100%;text-align:center;background:#ececec;color:#555;font-size:12px;font-weight:600;} .md-comment-placeholder--sub{display:block;width:100%;background:#f2f2f2;color:#666;font-size:11px;font-weight:500;} .md-comment-placeholder--field{background:#f7f7f7;color:#666;font-size:10px;border-left:4px solid #d0d0d0;padding-left:6px;} .md-comment-placeholder--close{background:#e8e8e8;color:#888;font-size:10px;font-style:italic;}';
    }
    if ($needsImgPE) {
      $contentStyle = rtrim($contentStyle) . ' img{pointer-events:none;}';
      $modified = true;
    }
    $settings['content_style'] = $contentStyle;
    // Enforce disabling object resizing
    if (!isset($settings['object_resizing']) || $settings['object_resizing'] !== false) {
      $settings['object_resizing'] = false;
      $modified = true;
    }
    if ($modified) { $existing->settingsJSON = json_encode($settings); }
    if ($modified) { $fields->save($existing); }
  }

  private function isTemplateExcluded(Template $template): bool
  {
    return $template->name === 'admin' || (($template->flags ?? 0) & Template::flagSystem);
  }
}

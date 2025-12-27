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
 * @property string $contentPath   Base path for markdown files
 */

class MarkdownToFields extends WireData implements Module, ConfigurableModule
{
  private array $fieldDefs = [
    'md_markdown_tab' => ['FieldtypeFieldsetTabOpen', 'Markdown'],
    'md_markdown_source' => ['FieldtypeText', 'Source file path'],
    'md_markdown' => ['FieldtypeTextarea', 'Markdown'],
    'md_markdown_hash' => ['FieldtypeText', 'Markdown hash'],
    'md_markdown_tab_END' => ['FieldtypeFieldsetClose', 'Close Markdown tab'],
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
  }

  public function getModuleConfigInputfields(array $data): InputfieldWrapper
  {

    $defaults = [
      'templates' => (array) ($this->templates ?? []),
    ];
    $data = array_merge($defaults, $data);

    $modules = $this->wire('modules');
    $templates = $this->wire('templates');

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

    $wrapper = new InputfieldWrapper();
    $wrapper->add($checkboxes);

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

  private function syncTemplateFields(): void
  {
    $this->createFields();

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
        
        $fields->save($f);
      }
    }
  }

  private function isTemplateExcluded(Template $template): bool
  {
    return $template->name === 'admin' || (($template->flags ?? 0) & Template::flagSystem);
  }
}

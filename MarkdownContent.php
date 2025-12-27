<?php

namespace ProcessWire;

use LetMeDown\ContentData;

/**
 * MarkdownContent Trait
 * 
 * Provides markdown loading functionality with sensible defaults.
 * Can be used by any Page class.
 * 
 * ## Folder Structure
 * 
 * **Single-language site:**
 * ```
 * site/content/
 *   home.md
 *   about.md
 * ```
 * 
 * **Multilingual site:**
 * ```
 * site/content/
 *   en/
 *     home.md
 *     about.md
 *   es/
 *     home.md
 *     about.md
 * ```
 * 
 * The syncer automatically detects your site's language setup and resolves paths.
 * 
 * ## Customization
 * 
 * All properties can be overridden in your page class:
 * 
 * ```php
 * class CustomPage extends Page {
 *   use MarkdownContent;
 *   protected string $sourcePath = '/var/markdown/';  // path to markdown files
 *   protected string $sourcePageField = 'md_ref';     // page reference field name
 *   protected string $markdownField = 'md_content';   // markdown content field
 *   protected string $htmlField = 'html';             // rendered HTML field
 *   protected string $hashField = 'md_hash';          // hash tracking field
 * }
 * ```
 * 
 * Or define per-page source:
 * 
 * ```php
 * class HomePage extends Page {
 *   use MarkdownContent;
 *   
 *   public function contentSource(): string {
 *     return 'home.md';  // Syncer handles language folders automatically
 *   }
 * }
 * 
 * ## Basic Usage
 * 
 * ```php
 * $content = $page->loadContent();
 * echo $content->hero->text;
 * ```
 */
trait MarkdownContent {
  
  // Defaults (override by setting the property in your class)
  protected string $sourcePath = '';  // empty = site/content/, or set to any path
  protected string $sourcePageField = 'md_markdown_source';
  protected string $markdownField = 'md_markdown';
  protected string $hashField = 'md_markdown_hash';
  protected string $htmlField = 'md_editor';
  
  /**
   * Ensure MarkdownSyncer class is loaded
   */

  // TODO: Consider moving MarkdownSyncer loading to module bootstrap.
  // This trait currently guards against load-order issues in PW.

  protected static function ensureMarkdownSyncer(): void {
    if (class_exists('\\ProcessWire\\MarkdownSyncer', false)) {
      return;
    }
    $config = wire('config');
    $path = $config->paths->siteModules . 'MarkdownToFields/MarkdownSyncer.php';
    if (is_file($path)) {
      require_once $path;
    }
  }

  /**
   * Get markdown sync configuration
   * Override to customize paths or settings
   */
  public function getMarkdownSyncMap(): array {
    $config = $this->wire('config');
    $path = $this->sourcePath ?: $config->paths->site . 'content/';
    
    return [
      'source' => [
        'path' => $path,
        'pageField' => $this->sourcePageField,
        'fallback' => $this->contentSource(),
      ],
      'markdownField' => $this->markdownField,
      'htmlField' => $this->htmlField,
      'hashField' => $this->hashField,
    ];
  }

  /**
   * Low-level markdown loader.
   * Do not call from templates—use content() instead.
   * 
   * @param string|null $source The markdown file source (relative to content path)
   * @param string|null $language Language code to load (defaults to current language)
   * @return ContentData The parsed markdown content
   * @internal For advanced use cases only
   */
  public function loadContent(?string $source = null, ?string $language = null): ContentData {
    self::ensureMarkdownSyncer();
    $source = $source ?? $this->contentSource();
    $syncerClass = '\\ProcessWire\\MarkdownSyncer';
    $lang = $language ?? $syncerClass::getLanguageCode($this);
    return $syncerClass::loadMarkdown($this, $source, $lang);
  }

  /**
   * Default markdown source: page name + .md
   * Override in your page class to customize
   */
  public function contentSource(): string {
    // Sensible default: use page name
    // Override in a Page class if needed.
    return $this->name . '.md';
  }

  /**
   * Canonical content access point.
   * Safe place to add caching, pre-processing, and other optimizations later.
   * Always call this from templates and page logic.
   * 
   * @return ContentData The parsed and ready-to-use markdown content
   */
  public function content(): ContentData {
    return $this->loadContent($this->contentSource());
  }

  /**
   * Template data boundary - the canonical view contract
   * Default: return loaded content
   * Override in your page class to shape/normalize data for templates
   * 
   * This is the only method templates should call.
   * Subclasses transform raw content into view-ready structure here.
   * 
   * @return ContentData The content prepared for template rendering
   */
  public function templateData(): ContentData {
    return $this->content();
  }
}

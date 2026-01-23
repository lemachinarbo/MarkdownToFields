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
  
  /** Returns the markdown sync configuration for this page. */
  public function getMarkdownSyncMap(): array {
    $config = $this->wire('config');
    $mdConfig = $config->MarkdownToFields ?? [];
    
    $sourcePath = $mdConfig['sourcePath'] ?? 'content/';
    $path = $sourcePath[0] === '/' ? $sourcePath : $config->paths->site . $sourcePath;

    // Global auto-sync controls (opt-out by design)
    $autoSync = $mdConfig['autoSyncFrontmatter'] ?? true;
    $exclude  = $mdConfig['excludeFrontmatterFields'] ?? [];
    $include  = $mdConfig['includeFrontmatterFields'] ?? [];
    $hasInclude = !empty($include);

    // Always support core fields explicitly
    $frontmatterMap = [
      'title' => 'title',
      // 'name'  => 'name',
    ];

    if ($autoSync && $this->template) {
      $excludeNames = [
        (string)($mdConfig['markdownField'] ?? 'md_markdown'),
        (string)($mdConfig['htmlField'] ?? 'md_editor'),
        (string)($mdConfig['hashField'] ?? 'md_markdown_hash'),
        'md_markdown_tab',
        'md_markdown_tab_END',
      ];
      foreach ($this->template->fieldgroup as $field) {
        $name = (string) $field->name;
        if ($name === '') continue;
        if (in_array($name, $excludeNames, true)) continue;
        if (in_array($name, $exclude, true)) continue;
        if ($hasInclude && !in_array($name, $include, true)) continue;
        $frontmatterMap[$name] = $name;
      }
    }

    $map = [
      'source' => [
        'path' => $path,
        'fallback' => $this->contentSource(),
      ],
      'markdownField' => $mdConfig['markdownField'] ?? 'md_markdown',
      'htmlField' => $mdConfig['htmlField'] ?? 'md_editor',
      'hashField' => $mdConfig['hashField'] ?? 'md_markdown_hash',
      'frontmatter' => $frontmatterMap,
    ];

    // Handle imageBaseUrl and imageSourcePaths from top-level config with {pageId} placeholder support
    if (isset($mdConfig['imageBaseUrl'])) {
      $map['imageBaseUrl'] = str_replace('{pageId}', (string) $this->id, $mdConfig['imageBaseUrl']);
    }
    if (!empty($mdConfig['imageSourcePaths'])) {
      $map['imageSourcePaths'] = (array) $mdConfig['imageSourcePaths'];
    }

    return $map;
  }

  /** Loads markdown content for the given source and language. */
  public function loadContent(?string $source = null, ?string $language = null): ContentData {
    $source = $source ?? $this->contentSource();
    $syncerClass = '\\ProcessWire\\MarkdownFileIO';
    $lang = $language ?? MarkdownLanguageResolver::getLanguageCode($this);
    return $syncerClass::loadMarkdown($this, $source, $lang);
  }

  /** Returns the default markdown filename for this page. */
  public function contentSource(): string {
    $name = trim((string) $this->name);
    
    // If name is empty (database state before frontmatter sync), derive from path
    if ($name === '') {
      // Root page → 'home', others → last path segment
      $name = $this->path === '/' ? 'home' : basename(rtrim($this->path, '/'));
    }
    
    return $name . '.md';
  }

  /** Loads parsed markdown content using the default source. */
  private $cachedContent = null;

  public function content(): ContentData {
    if ($this->cachedContent === null) {
      $this->cachedContent = $this->loadContent($this->contentSource());
    }
    return $this->cachedContent;
  }

  /** Provides view-ready content data for templates. */
  public function templateData(): ContentData {
    return $this->content();
  }

}


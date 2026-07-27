<?php

namespace ProcessWire;

// Same dependency bootstrap as MarkdownToFields: vendor classes must be
// available before init hooks run, regardless of module load order.
$__lensesVendor = __DIR__ . '/vendor/autoload.php';
if (is_file($__lensesVendor)) {
  require_once $__lensesVendor;
}
require_once __DIR__ . '/MarkdownLens.php';
require_once __DIR__ . '/MarkdownLensResponse.php';
require_once __DIR__ . '/MarkdownRawLens.php';
require_once __DIR__ . '/MarkdownReaderLens.php';
require_once __DIR__ . '/MarkdownFragmentLens.php';
require_once __DIR__ . '/MarkdownReaderView.php';
require_once __DIR__ . '/MarkdownSiteStructure.php';

/**
 * MarkdownToFieldsLenses – multiple lenses over one URL
 *
 * The request-plane companion to MarkdownToFields: any markdown-backed
 * page answers in alternate representations, no project code required.
 *
 * - /foo.md               raw markdown for agents, reader view for browsers
 * - /foo/?format=md       always raw markdown (explicit override)
 * - Accept: text/markdown raw markdown via content negotiation
 * - /foo/?section=hero,cta named content sections, as HTML or markdown
 *
 * The decision tree, cache rules, and exposure rules are specified in
 * notes/architecture.md. Projects extend behavior by registering their
 * own lenses (addLens) or hooking markdownFor/frontmatterFor/
 * MarkdownReaderView::slots.
 */
class MarkdownToFieldsLenses extends WireData implements Module, ConfigurableModule
{
  /** Project-registered lenses, checked before built-ins on Page::render. */
  private array $customLenses = [];

  private ?MarkdownRawLens $rawLens = null;
  private ?MarkdownReaderLens $readerLens = null;
  private ?MarkdownFragmentLens $fragmentLens = null;

  public static function getModuleInfo()
  {
    return [
      'title' => 'Markdown to fields: Lenses',
      'version' => '1.7.0', // x-release-please-version
      'summary' => 'Serve markdown-backed pages as raw markdown, a reader view, and content fragments. Multiple lenses over one URL',
      'author' => 'Lemachi Narbo',
      'icon' => 'eye',
      'requires' => ['MarkdownToFields', 'ProcessWire>=3.0.173', 'PHP>=8.1'],
      'autoload' => true,
      'singular' => true,
    ];
  }

  public function init()
  {
    $this->addHookBefore('Page::render', $this, 'negotiateRender');
  }

  public function ready()
  {
    // The .md route registers in ready(), not init(): ProcessPageView runs
    // path hooks once per boot phase, and on the init pass multi-language
    // support has not registered its Page hooks yet — language URLs
    // (httpUrl, localized paths) would silently resolve to the default
    // language. The ready pass has the full API.
    if ($this->lensEnabled('enableRaw') || $this->lensEnabled('enableReader')) {
      $this->addHook('!^(?<it>/.+)\.md$!', $this, 'handleMarkdownUrl');
    }
  }

  public function install()
  {
    $this->wire()->modules->saveConfig($this, [
      'enableRaw' => 1,
      'enableReader' => 1,
      'enableFragments' => 1,
      'structureFile' => 'site.md',
      'navSection' => 'nav',
      'homepageName' => 'home',
      'excludedTemplates' => [],
    ]);
  }

  /**
   * Register a project lens. Checked before built-in lenses on every
   * Page::render, so a project lens can also override built-in behavior.
   * Call from ready.php or a module: $modules->get('MarkdownToFieldsLenses')->addLens($lens)
   */
  public function addLens(MarkdownLens $lens): void
  {
    $this->customLenses[] = $lens;
  }

  /**
   * Content negotiation on the canonical URL: first lens that wants the
   * request replaces the normal page render. When none does, the page
   * renders normally and advertises its markdown alternate.
   */
  public function negotiateRender(HookEvent $event)
  {
    $page = $event->object;
    if (!$this->servable($page)) return;

    foreach ($this->lenses() as $lens) {
      if (!$lens->wants($page)) continue;
      $event->replace = true;
      $event->return = $this->sendResponse($lens->render($page));
      return;
    }

    if ($this->lensEnabled('enableRaw') && !headers_sent()) {
      $mdUrl = $page->httpUrl . '?format=md';
      header("Link: <$mdUrl>; rel=\"alternate\"; type=\"text/markdown\"", false);
    }
  }

  /**
   * The /foo.md sibling URL (ProcessWire URL hook — no page ever matches
   * a .md path, so this route owns it). Browsers get the reader view,
   * everything else gets raw markdown; 404s answer in the same format
   * the request would have gotten.
   */
  public function handleMarkdownUrl(HookEvent $event)
  {
    $pages = $this->wire()->pages;
    $path = (string) $event->arguments('it');

    if ($path === '/' . $this->homepageName()) {
      $path = '/';
    }

    $wantsHtml = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'text/html');
    $useReader = $wantsHtml && $this->lensEnabled('enableReader');
    if (!$this->lensEnabled('enableRaw')) $useReader = $this->lensEnabled('enableReader');

    $page = $this->resolvePath($path);

    if ($page === null || !$this->servable($page)) {
      $response = $useReader
        ? $this->readerLens()->renderNotFound($pages->get('/'))
        : $this->rawLens()->renderNotFound();
      $event->return = $this->sendResponse($response);
      return;
    }

    $lens = $useReader ? $this->readerLens() : $this->rawLens();
    $event->return = $this->sendResponse($lens->render($page));
  }

  /**
   * Single exit for every lens response, stamping the cache policy
   * (decided in notes/architecture.md): successful responses are publicly
   * cacheable for guests for 5 minutes — content changes only when the
   * markdown file changes, and the ETag makes revalidation cheap. A
   * logged-in user may be seeing pages guests cannot view, so their
   * responses must never land in a shared cache. Errors are not cached.
   */
  protected function sendResponse(MarkdownLensResponse $response): string
  {
    if ($response->status !== 200) {
      $response->headers[] = 'Cache-Control: no-cache';
    } elseif ($this->wire()->user->isGuest()) {
      $response->headers[] = 'Cache-Control: public, max-age=300';
    } else {
      $response->headers[] = 'Cache-Control: private, no-store';
    }

    return $response->send();
  }

  /**
   * Whether lenses may answer for this page at all: markdown-backed
   * (MarkdownToFields recognizes it and its source file exists), viewable
   * by the current user, and not on an opted-out template. Unpublished
   * and access-protected pages fail viewable() — a .md URL must never
   * leak a draft.
   */
  public function servable(Page $page): bool
  {
    if (!$page->id) return false;
    if (in_array($page->template->name, (array) $this->get('excludedTemplates'), true)) return false;
    if (!MarkdownConfig::supportsPage($page)) return false;
    if (!$page->viewable()) return false;

    try {
      $file = MarkdownFileIO::getMarkdownFilePath($page);
    } catch (\Throwable $e) {
      // No resolvable markdown source (invalid override, unnamed page).
      return false;
    }

    return is_file($file);
  }

  /**
   * The page's content as clean markdown for agents/tools: structural
   * comments stripped, links and images resolved to absolute URLs.
   * Hookable — replace it when a project needs its own image pipeline.
   */
  public function ___markdownFor(Page $page): string
  {
    $resolvers = $this->resolvers($page);
    return $page->content()->getCleanMarkdown($resolvers['image'], $resolvers['link']);
  }

  /**
   * The curated metadata served with markdown responses: title,
   * description, canonical URL. Absolute on purpose — markdown served to
   * an agent has no implicit base to resolve against. Hookable.
   */
  public function ___frontmatterFor(Page $page): array
  {
    $frontmatter = $page->content()->frontmatter ?? [];

    return [
      'title' => (string) ($frontmatter['seo_title'] ?? $frontmatter['title'] ?? $page->title),
      'description' => (string) ($frontmatter['description'] ?? $frontmatter['summary'] ?? ''),
      'canonical' => $page->httpUrl,
    ];
  }

  /**
   * Image/link resolvers for clean markdown: scheme URLs pass through,
   * root-relative paths become absolute, bare image filenames resolve to
   * the page's own assets (where the sync stores them — markdown served
   * to an external consumer must never reference an image it cannot
   * fetch), and same-page fragments are pinned to the page they were
   * authored on. Hookable — one hook covers full documents AND fragments;
   * override when a project serves processed variants instead.
   */
  public function ___resolvers(Page $page): array
  {
    $httpRoot = rtrim($this->wire()->urls->httpRoot, '/');
    $toAbsolute = fn(string $url): string => $httpRoot . '/' . ltrim($url, '/');

    return [
      'image' => function (string $ref, string $alt) use ($page, $toAbsolute): string {
        if (preg_match('#^https?://#i', $ref)) return $ref;
        if (str_starts_with($ref, '/')) return $toAbsolute($ref);
        $name = basename($ref);
        if (is_file($page->filesManager()->path() . $name)) {
          return $toAbsolute($page->filesManager()->url() . $name);
        }
        return $ref;
      },
      'link' => function (string $ref, string $text) use ($page, $toAbsolute): string {
        if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $ref)) return $ref;
        if (str_starts_with($ref, '#')) return $page->httpUrl . $ref;
        if (str_starts_with($ref, '/')) return $toAbsolute($ref);
        return $ref;
      },
    ];
  }

  public function structureFileName(): string
  {
    $name = trim((string) $this->get('structureFile'));
    return $name !== '' ? $name : 'site.md';
  }

  public function homepageName(): string
  {
    $name = trim((string) $this->get('homepageName'));
    return $name !== '' ? $name : 'home';
  }

  public function navSectionName(): string
  {
    $name = trim((string) $this->get('navSection'));
    return $name !== '' ? $name : 'nav';
  }

  /** Lenses in negotiation order: project-registered first, then built-ins. */
  protected function lenses(): array
  {
    $lenses = $this->customLenses;
    if ($this->lensEnabled('enableFragments')) $lenses[] = $this->fragmentLens();
    if ($this->lensEnabled('enableRaw')) $lenses[] = $this->rawLens();
    return $lenses;
  }

  /** Feature toggles default to on until the module config says otherwise. */
  protected function lensEnabled(string $key): bool
  {
    $value = $this->get($key);
    return $value === null || $value === '' ? true : (bool) $value;
  }

  protected function rawLens(): MarkdownRawLens
  {
    return $this->rawLens ??= new MarkdownRawLens($this);
  }

  protected function readerLens(): MarkdownReaderLens
  {
    return $this->readerLens ??= new MarkdownReaderLens($this);
  }

  protected function fragmentLens(): MarkdownFragmentLens
  {
    return $this->fragmentLens ??= new MarkdownFragmentLens($this);
  }

  /**
   * Resolves a .md URL's page exactly as ProcessWire would resolve the
   * page path itself, language included: /it/foo.md serves the language
   * /it/foo/ belongs to. Uses PagesPathFinder rather than page methods
   * like localPath() — URL hooks can fire before LanguageSupportPageNames
   * has registered its Page hooks, so those are not callable here.
   */
  protected function resolvePath(string $path): ?Page
  {
    $pages = $this->wire()->pages;
    $result = $pages->pathFinder()->get($path === '/' ? '/' : $path . '/');

    $pageId = (int) ($result['page']['id'] ?? 0);
    if (!$pageId || (int) $result['response'] >= 400) return null;
    // A path that only resolved via URL segments or pagination is not a
    // page of its own; its .md sibling does not exist.
    if (!empty($result['urlSegmentStr']) || (int) $result['pageNum'] > 1) return null;

    $languages = $this->wire()->languages;
    $languageName = (string) ($result['language']['name'] ?? '');
    if ($languages && $languageName !== '') {
      $language = $languages->get($languageName);
      if ($language && $language->id) $this->wire()->user->language = $language;
    }

    $page = $pages->get($pageId);
    return $page->id ? $page : null;
  }

  public function getModuleConfigInputfields(array $data): InputfieldWrapper
  {
    $modules = $this->wire()->modules;
    $wrapper = new InputfieldWrapper();

    $defaults = [
      'enableRaw' => 1,
      'enableReader' => 1,
      'enableFragments' => 1,
      'structureFile' => 'site.md',
      'navSection' => 'nav',
      'homepageName' => 'home',
      'excludedTemplates' => [],
    ];
    $data = array_merge($defaults, $data);

    foreach ([
      'enableRaw' => ['Raw markdown', 'Serve clean markdown at /page.md, ?format=md, and Accept: text/markdown.'],
      'enableReader' => ['Reader view', 'Serve a low-noise HTML reading view when a browser opens /page.md.'],
      'enableFragments' => ['Content fragments', 'Serve named markdown sections at ?section=name,name as HTML or markdown.'],
    ] as $name => [$label, $description]) {
      $field = $modules->get('InputfieldCheckbox');
      $field->name = $name;
      $field->label = $label;
      $field->description = $description;
      $field->checked = (bool) $data[$name];
      $wrapper->add($field);
    }

    $field = $modules->get('InputfieldText');
    $field->name = 'structureFile';
    $field->label = 'Structure file';
    $field->description = 'Markdown file in the content directory whose nav section defines the reader view navigation. When absent, navigation is derived from the page tree.';
    $field->value = $data['structureFile'];
    $wrapper->add($field);

    $field = $modules->get('InputfieldText');
    $field->name = 'navSection';
    $field->label = 'Nav section name';
    $field->description = 'Name of the section in the structure file that holds the navigation list (default: nav, as in <!-- section:nav -->).';
    $field->value = $data['navSection'];
    $wrapper->add($field);

    $field = $modules->get('InputfieldText');
    $field->name = 'homepageName';
    $field->label = 'Homepage markdown name';
    $field->description = 'The homepage has no URL segment to hang .md off, so it is served at this name (default: /home.md).';
    $field->value = $data['homepageName'];
    $wrapper->add($field);

    $field = $modules->get('InputfieldAsmSelect');
    $field->name = 'excludedTemplates';
    $field->label = 'Excluded templates';
    $field->description = 'Templates that must never answer through a lens, even when their pages are markdown-backed.';
    foreach ($this->wire()->templates as $template) {
      if ($template->flags & Template::flagSystem) continue;
      $field->addOption($template->name);
    }
    $field->value = $data['excludedTemplates'];
    $wrapper->add($field);

    return $wrapper;
  }
}

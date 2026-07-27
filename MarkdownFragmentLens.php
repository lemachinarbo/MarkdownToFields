<?php

namespace ProcessWire;

/**
 * Content fragments: ?section=hero,cta returns just those named sections
 * of the page's markdown, as semantic HTML by default or as clean
 * markdown when the request also negotiates markdown (?format=md or
 * Accept: text/markdown). Sections are addressed by the names authored in
 * the markdown itself (<!-- section:hero -->) — hence the param name —
 * never by template internals. Styled template fragments (Latte blocks,
 * Datastar chrome) are project territory served through a
 * project-registered lens, and conventionally use ?part=: both lexgeo and
 * df were already fetching template blocks with ?part= when this lens was
 * born, which is exactly why it must not claim that name.
 */
class MarkdownFragmentLens implements MarkdownLens
{
  public function __construct(private MarkdownToFieldsLenses $module) {}

  public function wants(Page $page): bool
  {
    return $page->wire()->input->get->text('section') !== '';
  }

  public function render(Page $page): MarkdownLensResponse
  {
    $input = $page->wire()->input;
    $wantsMarkdown = $input->get->text('format') === 'md'
      || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'text/markdown');

    $names = array_filter(array_map('trim', explode(',', $input->get->text('section'))));
    $content = $page->content();
    $sections = [];

    foreach ($names as $name) {
      $section = $content->sectionsByName[$name] ?? null;
      if ($section === null) {
        $body = $wantsMarkdown ? "# 404 Not Found\n\nUnknown section: $name\n"
          : '<h1>404 Not Found</h1><p>Unknown section: ' . htmlspecialchars($name) . '</p>';
        $type = $wantsMarkdown ? 'text/markdown; charset=utf-8' : 'text/html; charset=utf-8';
        return new MarkdownLensResponse($body, $type, 404);
      }
      $sections[$name] = $section;
    }

    if ($wantsMarkdown) {
      $resolvers = $this->module->resolvers($page);
      $parts = [];
      foreach ($sections as $section) {
        $parts[] = trim($section->getCleanMarkdown($resolvers['image'], $resolvers['link']));
      }
      $body = implode("\n\n", $parts) . "\n";
      $type = 'text/markdown; charset=utf-8';
    } else {
      $parts = [];
      foreach ($sections as $section) {
        $parts[] = $section->html;
      }
      $body = implode("\n", $parts);
      $type = 'text/html; charset=utf-8';
    }

    return new MarkdownLensResponse($body, $type, 200, ['X-Robots-Tag: noindex']);
  }
}

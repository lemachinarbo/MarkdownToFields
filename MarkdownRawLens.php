<?php

namespace ProcessWire;

/**
 * Raw markdown for agents and tools: the page's clean markdown (structural
 * comments stripped, links/images absolutized) behind a thin curated
 * frontmatter block. Triggered by an explicit ?format=md query param or an
 * Accept: text/markdown header; the /foo.md URL route also renders through
 * this lens for non-browser clients.
 *
 * ?format=md always means raw, regardless of Accept — it is the explicit
 * override, and the .md-URL browser/agent dual behavior never applies to it.
 */
class MarkdownRawLens implements MarkdownLens
{
  public function __construct(private MarkdownToFieldsLenses $module) {}

  public function wants(Page $page): bool
  {
    $format = $page->wire()->input->get->text('format');
    return $format === 'md' || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'text/markdown');
  }

  public function render(Page $page): MarkdownLensResponse
  {
    // Serves whatever frontmatterFor() returns, in order, skipping empty
    // values — so a project hook that adds keys (author, date, ...) sees
    // them appear here without touching this lens.
    $frontmatter = "---\n";
    foreach ($this->module->frontmatterFor($page) as $key => $value) {
      $value = trim(str_replace(["\r", "\n"], ' ', (string) $value));
      if ($value === '') continue;
      $frontmatter .= $key . ': ' . $value . "\n";
    }
    $frontmatter .= "---\n\n";

    $body = $frontmatter . trim($this->module->markdownFor($page)) . "\n";
    $tokenCount = str_word_count(strip_tags($body));

    return new MarkdownLensResponse($body, 'text/markdown; charset=utf-8', 200, [
      'X-Robots-Tag: noindex',
      'x-markdown-tokens: ' . $tokenCount,
      'Link: <' . $page->httpUrl . '>; rel="canonical"',
    ]);
  }

  /** Markdown-typed 404 for .md URLs that resolve to nothing servable. */
  public function renderNotFound(): MarkdownLensResponse
  {
    return new MarkdownLensResponse("# 404 Not Found\n", 'text/markdown; charset=utf-8', 404);
  }
}

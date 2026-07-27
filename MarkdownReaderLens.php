<?php

namespace ProcessWire;

/**
 * The human-facing counterpart to MarkdownRawLens: the same clean markdown
 * converted to plain semantic HTML (Parsedown, already a module dependency)
 * and wrapped in MarkdownReaderView's minimal self-contained layout.
 *
 * This lens is routed, not negotiated: only the /foo.md URL reaches it,
 * and only when the request's Accept header prefers text/html (an actual
 * browser). It is never registered in the Page::render negotiation, so
 * wants() is always false there — an explicit ?format=md must stay raw.
 */
class MarkdownReaderLens implements MarkdownLens
{
  public function __construct(private MarkdownToFieldsLenses $module) {}

  public function wants(Page $page): bool
  {
    return false;
  }

  public function render(Page $page): MarkdownLensResponse
  {
    $html = (new \Parsedown())->text($this->module->markdownFor($page));
    $html = MarkdownReaderView::rewriteLinks($page, $html);

    $view = $page->wire(new MarkdownReaderView());
    $body = $view->render($page, $html);

    return new MarkdownLensResponse($body, 'text/html; charset=utf-8', 200, [
      'X-Robots-Tag: noindex',
      'Link: <' . $page->httpUrl . '>; rel="canonical"',
    ]);
  }

  /**
   * Reader-typed 404 for .md URLs that resolve to nothing servable.
   * Rendered against the given context page (the homepage) so the reader
   * chrome — nav, brand — still works.
   */
  public function renderNotFound(Page $context): MarkdownLensResponse
  {
    $view = $context->wire(new MarkdownReaderView());
    $body = $view->render($context, '<h1>404 Not Found</h1>');

    return new MarkdownLensResponse($body, 'text/html; charset=utf-8', 404, [
      'X-Robots-Tag: noindex',
    ]);
  }
}

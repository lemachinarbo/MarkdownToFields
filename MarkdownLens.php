<?php

namespace ProcessWire;

/**
 * A lens is one alternate representation of a page's markdown-backed
 * content (raw markdown, reader HTML, content fragments, ...).
 *
 * Built-in lenses are negotiated by MarkdownToFieldsLenses in two places:
 * - Page::render (query param / Accept header on the canonical URL)
 * - the /foo.md URL route (which picks raw vs reader by Accept header)
 *
 * Projects can register their own lenses (a Datastar fragment handler,
 * a JSON representation, ...) via MarkdownToFieldsLenses::addLens();
 * registered lenses are checked before the built-in ones on every
 * Page::render, so a project lens can also override built-in behavior.
 */
interface MarkdownLens
{
  /** Whether this lens should handle the current request for the page. */
  public function wants(Page $page): bool;

  /** Build the response. Never echoes and never sends headers itself. */
  public function render(Page $page): MarkdownLensResponse;
}

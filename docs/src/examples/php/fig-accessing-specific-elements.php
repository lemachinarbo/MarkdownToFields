<?php
/**
 * @source: fig-content-with-lists-images-paragraphs-links.md
 */
namespace ProcessWire;
  $content = $page->content();

  $block = $content->section[0]->blocks[0];

  $weare = $block->paragraphs[2]->text;   // "We are based..."
  $chicago = $block->images[0]->src;      // chicago.jpg
  $ramason = $block->lists[0]->items[0];  // Ramason
  $visitus = $block->links[1]->text;      // "visit us"
?>

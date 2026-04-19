<?php
/**
 * @source: fig-content-with-blocks-and-children.md
 */
namespace ProcessWire;
  $content = $page->content();
  
  $values = $content->about->blocks[1];       // # What We Value (h1)
  $collaboration = $values->children[0];      // ## Collaboration (h2)
  $innovation = $collaboration->children[0]; // ### Innovation together (h3)
?>

<?php

use LetMeDown\LetMeDown;
use PHPUnit\Framework\TestCase;

/**
 * @testdox Subsection opener marker removed from main section markdown
 */
class SubsectionOpenerRemovedTest extends TestCase
{
    public function testOpenerMarkerNotInSectionMarkdown()
    {
        $md = "# Hello\n\n<!-- intro -->\nIntro text for hello section.\n\n<!-- sub:foo -->\n";

        $parser = new LetMeDown(__DIR__ . '/fixtures');
        $content = $parser->loadFromString($md);

        $sectionMarkdown = $content->sections[0]->getMarkdown();

        $this->assertStringNotContainsString('<!-- sub:foo -->', $sectionMarkdown);
        $this->assertNotNull($content->sections[0]->subsection('foo'));
    }
}

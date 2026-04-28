<?php

use LetMeDown\LetMeDown;
use PHPUnit\Framework\TestCase;

/**
 * @testdox Blocks with subsections projection
 */
class BlocksWithSubsectionsTest extends TestCase
{
    public function testBlocksWithSubsectionsAreProjected()
    {
        $md = "# Hello\n\n<!-- sub:left -->\n## Foo\nSome foo\n\n<!-- sub:right -->\n## Bar\nSome bar\n";

        $parser = new LetMeDown(__DIR__ . '/fixtures');
        $content = $parser->loadFromString($md);

        // Ensure subsection parsing happened
        $this->assertNotEmpty($content->sections[0]->subsection('left'));
        $this->assertNotEmpty($content->sections[0]->subsection('right'));

        $proj = $content->sections[0]->blocksWithSubsections();
        $this->assertNotEmpty($proj);

        $root = $proj[0];

        $this->assertNotEmpty($root->children, 'Root block should have children after projection');

        // First child should correspond to Foo, second to Bar
        $this->assertEquals('Foo', $root->children[0]->heading->text);
        $this->assertEquals('Bar', $root->children[1]->heading->text);
    }

    public function testNoSubsectionsReturnsOriginalBlocks()
    {
        $md = "# Solo\n\nSome content without subsections\n";

        $parser = new LetMeDown(__DIR__ . '/fixtures');
        $content = $parser->loadFromString($md);

        $section = $content->sections[0];
        $original = $section->getRealBlocks();
        $proj = $section->blocksWithSubsections();

        // When there are no subsections, projection should return the original blocks unchanged
        $this->assertSame($original, $proj);
    }
}

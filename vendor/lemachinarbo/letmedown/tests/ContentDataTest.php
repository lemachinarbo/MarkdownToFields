<?php

use LetMeDown\ContentData;
use PHPUnit\Framework\TestCase;

class ContentDataTest extends TestCase
{
    public function testGetRawDocumentWithFrontmatterAndBody()
    {
        $content = new ContentData([
            'markdown' => "Body content",
            'frontmatterRaw' => "title: Test"
        ]);

        $expected = "---\ntitle: Test\n---\n\nBody content";
        $this->assertSame($expected, $content->getRawDocument());
    }

    public function testGetRawDocumentWithNoFrontmatter()
    {
        $content = new ContentData([
            'markdown' => "Body content",
            'frontmatterRaw' => null
        ]);

        $this->assertSame("Body content", $content->getRawDocument());
    }

    public function testGetRawDocumentWithEmptyFrontmatter()
    {
        $content = new ContentData([
            'markdown' => "Body content",
            'frontmatterRaw' => ""
        ]);

        $expected = "---\n---\n\nBody content";
        $this->assertSame($expected, $content->getRawDocument());
    }

    public function testGetRawDocumentWithEmptyBody()
    {
        $content = new ContentData([
            'markdown' => "",
            'frontmatterRaw' => "title: Test"
        ]);

        $expected = "---\ntitle: Test\n---\n";
        $this->assertSame($expected, $content->getRawDocument());
    }

    public function testGetRawDocumentWhitespaceHandling()
    {
        $content = new ContentData([
            'markdown' => "\n\nBody content",
            'frontmatterRaw' => "title: Test\n\n"
        ]);

        $expected = "---\ntitle: Test\n---\n\nBody content";
        $this->assertSame($expected, $content->getRawDocument());
    }

    public function testGetRawDocumentViaMagicProperty()
    {
        $content = new ContentData([
            'markdown' => "Body content",
            'frontmatterRaw' => "title: Test"
        ]);

        $expected = "---\ntitle: Test\n---\n\nBody content";
        $this->assertSame($expected, $content->rawDocument);
    }
}

<?php

use LetMeDown\LetMeDown;
use PHPUnit\Framework\TestCase;

class ComplexParsingTest extends TestCase
{
    private string $fixture;

    protected function setUp(): void
    {
        $this->fixture = __DIR__ . '/fixtures/complex.md';
        $this->assertFileExists($this->fixture, 'Fixture file must exist for tests');
    }

    public function test_sections_and_frontmatter()
    {
        $parser = new LetMeDown();
        $content = $parser->load($this->fixture);

        // 4 sections: leading unnamed + hero + columns + body
        $this->assertCount(4, $content->sections);

        $this->assertSame('The Urban Farm Studio.', $content->frontmatter['title']);
    }

    public function test_leading_section()
    {
        $parser = new LetMeDown();
        $content = $parser->load($this->fixture);

        $this->assertSame('We work with soil..', trim($content->section(0)->text));
    }

    public function test_hero_fields_and_containers()
    {
        $parser = new LetMeDown();
        $content = $parser->load($this->fixture);

        $hero = $content->section('hero');
        $this->assertNotNull($hero);

        // Title field is a heading-type field
        $this->assertSame('The Urban Farm', $hero->field('title')->text);

        // Intro is a container (intro...)
        $this->assertInstanceOf(\LetMeDown\FieldContainer::class, $hero->field('intro'));

        // Features container contains the expected headings in HTML and at least one block
        $features = $hero->field('features');
        $this->assertInstanceOf(\LetMeDown\FieldContainer::class, $features);
        $this->assertNotEmpty($features->html);
        $this->assertStringContainsString('Soil-first design', $features->html);
        $this->assertStringContainsString('Small footprint systems', $features->html);
        $this->assertGreaterThanOrEqual(1, count($features->blocks));
    }

    public function test_columns_subsections_and_elements()
    {
        $parser = new LetMeDown();
        $content = $parser->load($this->fixture);

        $columns = $content->section('columns');
        $this->assertNotNull($columns);

        $left = $columns->subsection('left');
        $right = $columns->subsection('right');
        $this->assertNotNull($left);
        $this->assertNotNull($right);

        $this->assertSame('What we grow', trim($left->blocks[0]->heading->text));

        // Image field present in left subsection
        $imageField = $left->field('image');
        $this->assertNotNull($imageField);
        $this->assertSame('https://picsum.photos/id/309/400/200', $imageField->data['src']);

        // List field present and contains 4 items
        $listField = $left->field('list');
        $this->assertSame('list', $listField->type);
        $this->assertCount(4, $listField->items);
    }

    public function test_body_summary_and_plan_container()
    {
        $parser = new LetMeDown();
        $content = $parser->load($this->fixture);

        $body = $content->section('body');
        $this->assertNotNull($body);

        $this->assertSame('A short summary of our approach.', trim($body->field('summary')->text));

        $plan = $body->field('plan');
        $this->assertInstanceOf(\LetMeDown\FieldContainer::class, $plan);

        // Plan container contains a list block with four items
        $this->assertCount(1, $plan->lists); // top-level list in the container
        $this->assertCount(4, $plan->lists[0]->data['items']);
    }

    public function test_synthetic_start_preserves_h2_level()
    {
        $parser = new LetMeDown();
        $content = $parser->load($this->fixture);

        $body = $content->section('body');
        // The first real block should be an H2 and preserve level 2
        $firstBlock = $body->blocks[0];
        $this->assertSame(2, $firstBlock->level);
        $this->assertStringContainsString('Forget industrial farms', $firstBlock->heading->text);
    }
}

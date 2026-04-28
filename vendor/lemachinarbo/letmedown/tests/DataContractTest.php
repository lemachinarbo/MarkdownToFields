<?php

use LetMeDown\LetMeDown;
use PHPUnit\Framework\TestCase;

class DataContractTest extends TestCase
{
    private LetMeDown $parser;
    private $content;

    protected function setUp(): void
    {
        $this->parser = new LetMeDown(__DIR__ . '/fixtures');
        $this->content = $this->parser->load(__DIR__ . '/fixtures/complex.md');
    }

    public function test_content_data_returns_named_sections_only(): void
    {
        $data = $this->content->data();

        $this->assertIsArray($data);
        $this->assertArrayHasKey('hero', $data);
        $this->assertArrayHasKey('columns', $data);
        $this->assertArrayHasKey('body', $data);
        $this->assertArrayNotHasKey('sections', $data);
        $this->assertArrayNotHasKey('items', $data);
    }

    public function test_section_data_has_structural_keys_and_subsections_array(): void
    {
        $hero = $this->content->section('hero')->data();

        $this->assertSame('hero', $hero['key']);
        $this->assertArrayHasKey('subsections', $hero);
        $this->assertSame([], $hero['subsections']);
        $this->assertArrayNotHasKey('items', $hero);
    }

    public function test_scalar_field_data_is_associative_and_has_no_items(): void
    {
        $title = $this->content->section('hero')->field('title')->data();

        $this->assertSame('title', $title['key']);
        $this->assertSame('title', $title['type']);
        $this->assertArrayHasKey('html', $title);
        $this->assertArrayNotHasKey('items', $title);
    }

    public function test_iterable_field_data_exposes_items(): void
    {
        $list = $this->content->section('columns')->subsection('left')->field('list')->data();

        $this->assertSame('list', $list['key']);
        $this->assertSame('list', $list['type']);
        $this->assertArrayHasKey('items', $list);
        $this->assertCount(4, $list['items']);
    }

    public function test_field_container_data_exposes_named_children_and_items(): void
    {
        $container = $this->content->section('hero')->field('features')->data();

        $this->assertSame('features', $container['key']);
        $this->assertArrayHasKey('items', $container);
        $this->assertIsArray($container['items']);
        $this->assertGreaterThanOrEqual(1, count($container['items']));
    }
}

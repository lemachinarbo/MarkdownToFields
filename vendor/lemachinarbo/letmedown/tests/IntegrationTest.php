<?php

use LetMeDown\LetMeDown;
use PHPUnit\Framework\TestCase;

/**
 * @testdox Complex Parsing
 */
class IntegrationTest extends TestCase
{
    private LetMeDown $parser;
    private $content;

    protected function setUp(): void
    {
        $this->parser = new LetMeDown();
        $this->content = $this->parser->load(__DIR__ . '/fixtures/complex.md');
    }

    private function renderValue(mixed $v): string
    {
        if (is_null($v)) return 'null';
        if (is_bool($v)) return $v ? 'true' : 'false';
        if (is_scalar($v)) {
            $s = (string) $v;
            if (strlen($s) > 120) return substr($s, 0, 117) . '...';
            return $s;
        }
        if (is_array($v)) return json_encode($v, JSON_UNESCAPED_SLASHES);
        if (is_object($v)) {
            if (method_exists($v, 'getMarkdown')) {
                $s = trim(substr($v->getMarkdown(), 0, 200));
                return strlen($s) > 120 ? substr($s, 0, 117) . '...' : $s;
            }
            if (property_exists($v, 'text')) {
                $s = trim(substr($v->text, 0, 200));
                return strlen($s) > 120 ? substr($s, 0, 117) . '...' : $s;
            }
            if (property_exists($v, 'html')) {
                $s = trim(substr($v->html, 0, 200));
                return strlen($s) > 120 ? substr($s, 0, 117) . '...' : $s;
            }
            if (method_exists($v, '__toString')) return (string) $v;
            return get_class($v);
        }
        return (string) $v;
    }

    private function note(string $access, mixed $value): void
    {
        $out = $this->renderValue($value);
        $caller = (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'] ?? 'unknown');
        fwrite(STDERR, "    - test: " . $caller . PHP_EOL);
        fwrite(STDERR, "    - output: " . str_replace("\n", "\\n", $out) . PHP_EOL);
        fwrite(STDERR, "    - access: " . $access . PHP_EOL);
        fwrite(STDERR, PHP_EOL);
    }

    // Part 1: Semantic syntax (fields, containers, subsections, elements)
    /** @testdox Sections and frontmatter — <!-- section --> readable */
    public function test_section_marker_is_parsed_readable()
    {
        $value = $this->content->section('hero') !== null ? 'present' : 'missing';
        $this->note("\$content->section('hero')", $value);
        $this->assertNotNull($this->content->section('hero'));
    }

    /** @testdox Sections and frontmatter — frontmatter title parsed */
    public function test_frontmatter_title_parsed()
    {
        $value = $this->content->frontmatter['title'];
        $this->note("\$content->frontmatter['title']", $value);
        $this->assertSame('The Urban Farm Studio.', $value);
    }

    /** @testdox Leading section — leading content becomes first section */
    public function test_leading_content_becomes_first_section()
    {
        $value = trim($this->content->section(0)->text);
        $this->note("\$content->section(0)->text", $value);
        $this->assertSame('We work with soil..', $value);
    }

    /** @testdox Hero fields — <!-- title --> returns title text */
    public function test_hero_title_field_returns_title()
    {
        $hero = $this->content->section('hero');
        $value = $hero->field('title')->text;
        $this->note("\$content->section('hero')->field('title')->text", $value);
        $this->assertSame('The Urban Farm', $value);
    }

    /** @testdox Hero fields — <!-- subtitle --> returns subtitle text */
    public function test_hero_subtitle_field_returns_text()
    {
        $hero = $this->content->section('hero');
        $value = trim($hero->field('subtitle')->text);
        $this->note("\$content->section('hero')->field('subtitle')->text", $value);
        $this->assertSame('City-grown food and ideas.', $value);
    }

    /** @testdox Hero fields — <!-- intro... --> is a FieldContainer */
    public function test_intro_field_is_container()
    {
        $hero = $this->content->section('hero');
        $value = $hero->field('intro');
        $this->note("\$content->section('hero')->field('intro')", $value);
        $this->assertInstanceOf(\LetMeDown\FieldContainer::class, $value);
    }

    /** @testdox Hero fields — intro container contains expected markdown */
    public function test_intro_container_contains_markdown()
    {
        $hero = $this->content->section('hero');
        $value = $hero->field('intro')->markdown;
        $this->note("\$content->section('hero')->field('intro')->markdown", $value);
        $this->assertStringContainsString('We grow food and ideas in the city', $value);
    }

    /** @testdox Hero fields — features container includes both headings */
    public function test_features_contains_headings()
    {
        $hero = $this->content->section('hero');
        $value = $hero->field('features')->html;
        $this->note("\$content->section('hero')->field('features')->html", $value);
        $this->assertStringContainsString('Soil-first design', $value);
        $this->assertStringContainsString('Small footprint systems', $value);
    }

    /** @testdox Columns subsections — left and right subsections present */
    public function test_columns_have_left_and_right_subsections()
    {
        $columns = $this->content->section('columns');
        $left = $columns->subsection('left');
        $right = $columns->subsection('right');
        $this->assertNotNull($left);
        $this->assertNotNull($right);
    }

    /** @testdox Columns subsections — left heading is 'What we grow' */
    public function test_left_subsection_heading()
    {
        $left = $this->content->section('columns')->subsection('left');
        $value = trim($left->blocks[0]->heading->text);
        $this->note("\$content->section('columns')->subsection('left')->blocks[0]->heading->text", $value);
        $this->assertSame('What we grow', $value);
    }

    /** @testdox Columns subsections — right heading is 'How we work' */
    public function test_right_subsection_heading()
    {
        $right = $this->content->section('columns')->subsection('right');
        $value = trim($right->blocks[0]->heading->text);
        $this->note("\$content->section('columns')->subsection('right')->blocks[0]->heading->text", $value);
        $this->assertSame('How we work', $value);
    }

    /** @testdox Columns subsections — left image field src */
    public function test_left_image_field_src()
    {
        $left = $this->content->section('columns')->subsection('left');
        $img = $left->field('image');
        $value = $img->data['src'];
        $this->note("\$content->section('columns')->subsection('left')->field('image')->data['src']", $value);
        $this->assertSame('https://picsum.photos/id/309/400/200', $value);
    }

    /** @testdox Columns subsections — left list has 4 items */
    public function test_left_list_has_four_items()
    {
        $left = $this->content->section('columns')->subsection('left');
        $list = $left->field('list');
        $value = $list->items;
        $this->note("\$content->section('columns')->subsection('left')->field('list')->items", $value);
        $this->assertCount(4, $value);
    }

    /** @testdox Body — summary field parsed */
    public function test_body_summary_field()
    {
        $body = $this->content->section('body');
        $value = trim($body->field('summary')->text);
        $this->note("\$content->section('body')->field('summary')->text", $value);
        $this->assertSame('A short summary of our approach.', $value);
    }

    /** @testdox Body — plan container contains list items */
    public function test_body_plan_container_has_items()
    {
        $body = $this->content->section('body');
        $plan = $body->field('plan');
        $value = $plan->html;
        $this->note("\$content->section('body')->field('plan')->html", $value);
        $this->assertStringContainsString('Modular growing setups', $value);
    }

    /** @testdox Body — first block preserves H2 level (synthetic root) */
    public function test_body_first_block_is_h2()
    {
        $body = $this->content->section('body');
        $value = $body->blocks[0]->level;
        $this->note("\$content->section('body')->blocks[0]->level", $value);
        $this->assertSame(2, $value);
    }

    // Part 2: Positional syntax (access by index/hierarchy without using fields)
    /** @testdox Positional sections — hero is present at index 1 */
    public function test_positional_hero_at_index_1()
    {
        $hero = $this->content->sections[1];
        $value = $hero->blocks[0]->heading->text ?? ($hero->html ?? '');
        $this->note("\$content->sections[1]->blocks[0]->heading->text", $value);
        $this->assertStringContainsString('The Urban Farm', $value);
    }

    /** @testdox Positional sections — body first block heading contains expected text */
    public function test_positional_body_first_block_heading()
    {
        $body = $this->content->sections[3];
        $value = $body->blocks[0]->heading->text;
        $this->note("\$content->sections[3]->blocks[0]->heading->text", $value);
        $this->assertStringContainsString('Forget industrial farms', $value);
    }

    /** @testdox Positional elements — left subsection image accessible */
    public function test_positional_left_image_accessible()
    {
        $left = $this->content->sections[2]->subsection('left');
        $images = $left->images;
        $value = $images[0]->data['src'] ?? null;
        $this->note("\$content->sections[2]->subsection('left')->images[0]->data['src']", $value);
        $this->assertSame('https://picsum.photos/id/309/400/200', $value);
    }

    /** @testdox Positional elements — left subsection list has 4 items */
    public function test_positional_left_list_has_four_items()
    {
        $left = $this->content->sections[2]->subsection('left');
        $lists = $left->lists;
        $value = $lists[0]->data['items'] ?? [];
        $this->note("\$content->sections[2]->subsection('left')->lists[0]->data['items']", $value);
        $this->assertCount(4, $value);
    }

    /** @testdox Orphan pre-heading — leading block has no heading */
    public function test_orphan_block_has_no_heading()
    {
        $leading = $this->content->sections[0];
        $value = $leading->blocks[0]->heading ?? null;
        $this->note("\$content->sections[0]->blocks[0]->heading", $value);
        $this->assertNull($value);
    }

    /** @testdox Orphan pre-heading — leading block contains orphan text */
    public function test_orphan_block_contains_text()
    {
        $leading = $this->content->sections[0];
        $value = $leading->blocks[0]->text;
        $this->note("\$content->sections[0]->blocks[0]->text", $value);
        $this->assertStringContainsString('We work with soil', $value);
    }


    /** @testdox Content markdown — contains H3 'Im a children of a children' */
    public function test_examples_content_markdown_contains_h3()
    {
        $raw = file_get_contents(__DIR__ . '/fixtures/test-markdown.md');
        $this->assertStringContainsString('### 3.2.1 Im a children of a children', $raw);
    }

    /** @testdox Examples file — leading orphan text present */
    public function test_examples_leading_orphan_text()
    {
        $parser = new LetMeDown();
        $content = $parser->load(__DIR__ . '/fixtures/test-markdown.md');
        $this->assertStringContainsString('This section is an orphan section', $content->sections[0]->text);
    }
}

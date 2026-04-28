<?php

use LetMeDown\LetMeDown;
use PHPUnit\Framework\TestCase;

class LeadingSectionTest extends TestCase
{
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
        fwrite(STDOUT, PHP_EOL);
        fwrite(STDOUT, "    - output: " . str_replace("\n", "\\n", $out) . PHP_EOL);
        fwrite(STDOUT, "    - access: " . $access . PHP_EOL);
    }

    public function test_leading_content_becomes_first_section()
    {
        $md = <<<MD
---
title: The Urban Farm Studio.
---

Intro text for the section.

<!-- section:bye -->
# Tagged section here
Short text.
MD;

        $tmp = sys_get_temp_dir() . '/letmedown_test_' . uniqid() . '.md';
        file_put_contents($tmp, $md);

        $parser = new LetMeDown(sys_get_temp_dir());
        $content = $parser->load(basename($tmp));

        $value = trim($content->section(0)->text);
        $this->note("\$content->section(0)->text", $value);

        // The leading intro should be the first section
        $this->assertSame('Intro text for the section.', $value);

        unlink($tmp);
    }

    public function test_marker_at_start_has_no_leading_section()
    {
        $md = "<!-- section:hero -->\n# Heading\nSome text.";
        $tmp = sys_get_temp_dir() . '/letmedown_test_' . uniqid() . '.md';
        file_put_contents($tmp, $md);

        $parser = new LetMeDown(sys_get_temp_dir());
        $content = $parser->load(basename($tmp));

        // First section should be the tagged section
        $this->assertSame("Heading\n\nSome text.", trim($content->section(0)->text));

        // No unnamed leading section should be present
        $this->assertNull($content->section(''));

        unlink($tmp);
    }
}

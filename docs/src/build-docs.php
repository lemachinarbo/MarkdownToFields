<?php
/**
 * MarkdownToFields Documentation Builder
 * 
 * Automatically generates docs/guide.md from docs/guide.source.md
 * by injecting examples and reflecting on LetMeDown classes.
 */

namespace ProcessWire;

// require_once __DIR__ . '/../MarkdownToFields.module.php';
use LetMeDown\LetMeDown;
use ProcessWire\MarkdownContentView;
use ProcessWire\Page;

// Mock ProcessWire infrastructure for CLI documentation
if (!class_exists('ProcessWire\Wire')) {
    class Wire { 
        public function __get($n) { return null; }
        public function __call($n, $a) { return $this; }
    }
}
if (!class_exists('ProcessWire\WireData')) {
    class WireData extends Wire {
        public function project(?string $m = null): mixed { return $this; }
    }
}
if (!class_exists('ProcessWire\WireArray')) {
    class WireArray extends WireData implements \IteratorAggregate, \Countable {
        public function getIterator(): \Traversable { return new \ArrayIterator([]); }
        public function count(): int { return 0; }
    }
}
if (!class_exists('ProcessWire\Page')) {
    class Page extends WireData {
        public function __construct($id = 0) {}
    }
}

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../vendor/lemachinarbo/letmedown/src/LetMeDown.php';
require_once __DIR__ . '/../../MarkdownContentView.php';
require_once __DIR__ . '/../../MarkdownDataSet.php';
require_once __DIR__ . '/../../MarkdownNodeData.php';

class DocBuilder {
    private string $docsDir;
    private string $examplesDir;
    private string $markdownDir;
    private string $phpDir;
    private string $snapshotsDir;
    private string $sourceFile;
    private string $outputFile;
    private bool $updateMode = false;
    private int $figCounter = 1;

    public function __construct(array $argv = []) {
        $this->docsDir = dirname(__DIR__);
        $this->examplesDir = __DIR__ . '/examples';
        $this->markdownDir = $this->examplesDir . '/markdown';
        $this->phpDir = $this->examplesDir . '/php';
        $this->snapshotsDir = $this->examplesDir . '/snapshots';
        
        $this->sourceFile = __DIR__ . '/guide.source.md';
        $this->outputFile = $this->docsDir . '/guide.md';
        $this->updateMode = in_array('--update', $argv);

        // Warm up LetMeDown classes
        if (class_exists('LetMeDown\\LetMeDown')) { }
    }

    public function build(): void {
        if (!file_exists($this->sourceFile)) {
            die("Source file not found: {$this->sourceFile}\n");
        }

        echo "Verifying examples...\n";
        $this->verifyExamples();

        echo "Building documentation...\n";
        $content = file_get_contents($this->sourceFile);

        // 0. Auto-increment Figures (Matches **FIG:** or **FIG 12:**)
        $this->figCounter = 1;
        $content = preg_replace_callback('/\*\*FIG\s*\d*:\*\*/i', function($m) {
            return "**FIG " . $this->figCounter++ . ":**";
        }, $content);

        // 1. Process [[EXAMPLE:name]]
        $content = preg_replace_callback('/\[\[EXAMPLE:(.+)\]\]/', function($m) {
            return $this->renderExample($m[1]);
        }, $content);

        // 2. Process [[REFLECT:Class]]
        $content = preg_replace_callback('/\[\[REFLECT:(.+)\]\]/', function($m) {
            return $this->renderReflection($m[1]);
        }, $content);

        // 3. Process [[DUMP:example]]
        $content = preg_replace_callback('/\[\[DUMP:(.+)\]\]/', function($m) {
            return $this->renderDump($m[1]);
        }, $content);

        file_put_contents($this->outputFile, $content);
        echo "Done! Generated {$this->outputFile}\n";
    }

    private function verifyExamples(): void {
        $phpFiles = glob($this->phpDir . '/*.php');
        $mdFiles = glob($this->markdownDir . '/*.md');
        $verifiedMd = [];

        // 1. Verify PHP examples (with their linked Markdown)
        foreach ($phpFiles as $phpFile) {
            $name = basename($phpFile, '.php');
            $sourceAttr = $this->extractSourceAttr($phpFile);
            $mdFile = $sourceAttr ? $this->markdownDir . '/' . $sourceAttr : $this->markdownDir . '/' . $name . '.md';
            
            if (!file_exists($mdFile)) {
                echo "  [SKIP] {$name} (No source markdown found at {$mdFile})\n";
                continue;
            }
            $verifiedMd[] = realpath($mdFile);

            $this->verifyNode($name, $mdFile, $phpFile);
        }

        // 2. Verify pure MD examples (no PHP logic)
        foreach ($mdFiles as $mdFile) {
            if (in_array(realpath($mdFile), $verifiedMd)) continue;
            $name = basename($mdFile, '.md');
            $this->verifyNode($name, $mdFile, null);
        }
    }

    private function extractSourceAttr(string $file): ?string {
        $content = file_get_contents($file);
        if (preg_match('/@source:\s*([^\s\*]+)/', $content, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    private function verifyNode(string $name, string $mdFile, ?string $phpFile): void {
        $expectTxt = "{$this->snapshotsDir}/{$name}.txt";
        $expectHtml = "{$this->snapshotsDir}/{$name}.html";

        // Generate Object Dump
        $parser = new LetMeDown();
        $rawContent = $parser->loadFromString(file_get_contents($mdFile));
        
        // Wrap in View Layer (ProcessWire specific API)
        $mockPage = new Page();
        $content = new MarkdownContentView($mockPage, $rawContent);
        
        // Curation: If the example name suggests we only want a specific part, dive into it
        $dumpObj = $content;
        if (str_contains($name, 'section-object')) {
             $dumpObj = count($content->sections) ? $content->sections[0] : $content;
        } elseif (str_contains($name, 'block-object')) {
             // Look for the first block in the first section
             $section = count($content->sections) ? $content->sections[0] : null;
             $dumpObj = ($section && count($section->blocks)) ? $section->blocks[0] : ($section ?: $content);
        } elseif (str_contains($name, 'heading-output')) {
             $section = count($content->sections) ? $content->sections[0] : null;
             $block = ($section && count($section->blocks)) ? $section->blocks[0] : null;
             $dumpObj = $block ? $block->heading : $content;
        }

        $actualTxt = $this->prettyPrint($dumpObj);

        // Capture Render Output
        $actualHtml = $phpFile ? $this->captureRender($phpFile, $content) : '';

        if ($this->updateMode) {
            file_put_contents($expectTxt, $actualTxt);
            if ($actualHtml) file_put_contents($expectHtml, $actualHtml);
            echo "  [UPDATED] {$name}\n";
        } else {
            if (file_exists($expectTxt)) {
                $existingTxt = file_get_contents($expectTxt);
                if ($this->normalize($existingTxt) !== $this->normalize($actualTxt)) {
                    die("  [ERROR] Example '{$name}' object dump mismatch! Run with --update to approve changes.\n");
                }
            }
            if (file_exists($expectHtml) && $actualHtml) {
                $existingHtml = file_get_contents($expectHtml);
                if ($this->normalize($existingHtml) !== $this->normalize($actualHtml)) {
                    die("  [ERROR] Example '{$name}' HTML output mismatch! Run with --update to approve changes.\n");
                }
            }
            echo "  [OK] {$name} (" . get_class($dumpObj) . ")\n";
        }
    }

    private function captureRender(string $file, $contentData): string {
        // Mock $page->content() behavior
        $page = new class($contentData) {
            public function __construct(private $content) {}
            public function content() { return $this->content; }
        };
        $content = $contentData; 
        
        ob_start();
        try {
            namespace\includeFileInScope($file, ['page' => $page, 'content' => $content]);
        } catch (\Throwable $e) {
            ob_end_clean();
            return "Execution Error: " . $e->getMessage();
        }
        return trim(ob_get_clean());
    }

    private function prettyPrint($obj, int $level = 0): string {
        $indent = str_repeat('  ', $level);
        $out = "";

        if (is_object($obj)) {
            $className = get_class($obj);
            if ($level > 1) return "{$className} [...]\n";
            
            $out .= "{$className}\n";
            $ref = new \ReflectionClass($obj);
            $props = $ref->getProperties(\ReflectionProperty::IS_PUBLIC | \ReflectionProperty::IS_PROTECTED);
            
            foreach ($props as $prop) {
                $name = $prop->getName();
                
                // Hide Internal Machinery
                if (in_array($name, ['itemsCache', 'sectionsByName', 'section', 'sections', 'frontmatterRaw'])) continue;
                
                $val = $prop->getValue($obj);
                $out .= "{$indent}  {$name}: " . $this->prettyPrint($val, $level + 1);
            }
        } elseif (is_array($obj)) {
            if (empty($obj)) return "array (0)\n";
            $count = count($obj);
            
            if ($level > 1) {
                return "array ($count) [ ... ]\n";
            }

            $out .= "array ($count)\n";
            foreach ($obj as $k => $v) {
                $out .= "{$indent}  {$k} => " . $this->prettyPrint($v, $level + 1);
            }
        } elseif (is_string($obj)) {
            // Trim long strings for docs
            if (strlen($obj) > 150) {
                $obj = substr($obj, 0, 147) . "...";
            }

            if (str_contains($obj, "\n")) {
                $out .= "\n{$indent}    '" . str_replace("\n", "\n{$indent}     ", trim($obj)) . "'\n";
            } else {
                $out .= "'{$obj}'\n";
            }
        } elseif (is_bool($obj)) {
            $out .= ($obj ? 'true' : 'false') . "\n";
        } elseif ($obj === null) {
            $out .= "null\n";
        } else {
            $out .= print_r($obj, true);
        }

        return $out;
    }

    private function normalize(string $str): string {
        return preg_replace('/\s+/', ' ', trim($str));
    }

    private function renderExample(string $param): string {
        $parts = explode(':', $param);
        $name = $parts[0];
        $type = $parts[1] ?? null;

        $mdFile = "{$this->markdownDir}/{$name}.md";
        $phpFile = "{$this->phpDir}/{$name}.php";
        
        $out = "";
        if (($type === null || $type === 'md') && file_exists($mdFile)) {
            $md = file_get_contents($mdFile);
            $out .= "```markdown\n{$md}```\n\n";
        }
        
        if (($type === null || $type === 'php') && file_exists($phpFile)) {
            $php = file_get_contents($phpFile);
            $out .= "```php\n{$php}```\n";
        }
        
        return trim($out);
    }

    private function renderReflection(string $className): string {
        $manualDocs = [
            'ContentData' => [
                'sections' => 'Array of all sections in the document',
                'frontmatter' => 'Parsed frontmatter data',
                'data' => 'Returns a plain array of the content dataset',
                'dataSet' => "Returns a WireData wrapper for fluent data access. Accepts 'html' or 'text' to automatically collapse simple fields to their respective string values."
            ],
            'Section' => [
                'html' => 'Rendered HTML of the section content',
                'text' => 'Plain text version of the section content',
                'markdown' => 'Raw markdown of the section',
                'blocks' => 'Parsed block objects inside the section',
                'fields' => 'Named field blocks inside the section',
                'subsections' => 'Nested subsections (not supported inside subsections themselves)',
                'frontmatter' => 'Frontmatter data for this section (if available)',
                'data' => 'Returns a plain array of the section dataset',
                'dataSet' => "Returns a WireData wrapper for fluent data access. Accepts 'html' or 'text' to automatically collapse simple fields to their respective string values.",
                'subsection' => 'Access a nested subsection by name'
            ],
            'Block' => [
                'heading' => 'Main heading element for this block',
                'level' => 'Heading level (1-6)',
                'content' => 'HTML content excluding the heading',
                'html' => 'Full rendered HTML of the block',
                'text' => 'Plain text version of the block',
                'markdown' => 'Raw markdown source of the block',
                'paragraphs' => 'Collection of paragraph elements',
                'images' => 'Collection of image elements',
                'links' => 'Collection of link elements',
                'lists' => 'Collection of list elements',
                'children' => 'Hierarchical child blocks',
                'fields' => 'Tagged fields inside this block'
            ],
            'HeadingElement' => [
                'text' => 'Heading label (plain text)',
                'html' => 'Full rendered <h1>...</h1> tag',
                'innerHtml' => 'Content inside the heading tag',
                'level' => 'Heading level (1-6)'
            ],
            'FieldData' => [
               'data' => 'Returns a plain array of the field dataset',
               'dataSet' => "Returns a WireData wrapper for fluent data access. Accepts 'html' or 'text' to automatically collapse simple fields to their respective string values."
            ],
            'FieldContainer' => [
               'data' => 'Returns a plain array of the container dataset',
               'dataSet' => "Returns a WireData wrapper for fluent data access. Accepts 'html' or 'text' to automatically collapse simple fields to their respective string values."
            ]
        ];

        try {
            // Mapping Logic: Documentation keys vs Actual Implementation Classes
            $docKey = $className; // e.g. "Section"
            
            // Try to find the "View" version first (the ProcessWire reality)
            $potentialClasses = [
                "ProcessWire\\Markdown{$className}View",
                "ProcessWire\\Markdown{$className}", // ContentData -> MarkdownContentView
                "LetMeDown\\{$className}"
            ];
            
            if ($className === 'ContentData') {
                array_unshift($potentialClasses, "ProcessWire\\MarkdownContentView");
            }

            $ref = null;
            foreach ($potentialClasses as $c) {
                if (class_exists($c)) {
                    $className = $c;
                    $ref = new \ReflectionClass($c);
                    break;
                }
            }

            if (!$ref) {
              $ref = new \ReflectionClass("LetMeDown\\" . $className);
            }
            
            $table = "| Member | Type | Description |\n";
            $table .= "| :--- | :--- | :--- |\n";
            
            // 1. Properties
            $props = $ref->getProperties(\ReflectionProperty::IS_PUBLIC);
            foreach ($props as $prop) {
                if ($prop->isStatic() || in_array($prop->getName(), ['itemsCache', 'page', 'nodeArea'])) continue;
                
                $typeObj = $prop->getType();
                $type = 'mixed';
                if ($typeObj instanceof \ReflectionNamedType) {
                    $type = $typeObj->getName();
                } elseif ($typeObj instanceof \ReflectionUnionType) {
                    $type = implode('|', array_map(fn($t) => $t->getName(), $typeObj->getTypes()));
                }
                
                $name = $prop->getName();
                $description = $manualDocs[$docKey][$name] ?? $this->parseDoc($prop->getDocComment() ?: '');
                $table .= "| `\${$name}` | `{$type}` | {$description} |\n";
            }

            // 2. Methods
            $methods = $ref->getMethods(\ReflectionMethod::IS_PUBLIC);
            foreach ($methods as $method) {
                $name = $method->getName();
                
                // Filter out Magic, Static, and Boring Getters
                if (str_starts_with($name, '__') || $method->isStatic() || str_starts_with($name, 'get')) continue;
                
                // Formatted parameters
                $params = [];
                foreach ($method->getParameters() as $p) {
                    $params[] = '$' . $p->getName() . ($p->isOptional() ? '?' : '');
                }
                $methodName = $name . '(' . implode(', ', $params) . ')';

                $description = $manualDocs[$docKey][$name] ?? $this->parseDoc($method->getDocComment() ?: '');
                $table .= "| `{$methodName}` | `method` | {$description} |\n";
            }
            
            return $table;
        } catch (\Exception $e) {
            return "> [!ERROR] Could not reflect class {$className}: " . $e->getMessage();
        }
    }

    private function renderDump(string $exampleName): string {
        $dumpFile = "{$this->snapshotsDir}/{$exampleName}.txt";
        if (file_exists($dumpFile)) {
            return "```php\n" . file_get_contents($dumpFile) . "```";
        }
        return "<!-- Missing dump for {$exampleName} -->";
    }

    private function parseDoc(string $doc): string {
        $doc = preg_replace('/^\/\*\*|\*\/|\s\*\s?/', '', $doc);
        $lines = explode("\n", trim($doc));
        $description = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (str_starts_with($line, '@')) continue;
            if ($line === '') continue;
            $description[] = $line;
        }
        return implode(' ', $description);
    }
}

/**
 * Isolated scope for including files
 */
function includeFileInScope($file, $vars) {
    extract($vars);
    include $file;
}

$builder = new DocBuilder($argv);
$builder->build();

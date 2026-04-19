<?php
/**
 * MarkdownToFields Documentation Playground
 * 
 * Interactive debugger to verify documentation examples against 
 * live code reality.
 */

// Mock ProcessWire infrastructure for standalone operation
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

use LetMeDown\LetMeDown;
use ProcessWire\MarkdownContentView;
use ProcessWire\Page;

$examplesDir = __DIR__ . '/examples';
$markdownDir = $examplesDir . '/markdown';
$phpDir = $examplesDir . '/php';

// 1. Discovery
$examples = [];
foreach (glob("$markdownDir/*.md") as $file) {
    $name = basename($file, '.md');
    $examples[$name] = [
        'name' => $name,
        'md' => $file,
        'php' => file_exists("$phpDir/$name.php") ? "$phpDir/$name.php" : null
    ];
}
ksort($examples);

// 2. Selection
$selected = $_GET['ex'] ?? null;
$current = $selected ? ($examples[$selected] ?? null) : null;

// 3. Execution
$contentData = null;
$renderedHtml = '';
$error = null;

if ($current) {
    try {
        $parser = new LetMeDown();
        $rawContent = $parser->loadFromString(file_get_contents($current['md']));
        
        // Wrap in View Layer (ProcessWire specific API)
        $mockPage = new Page();
        $contentData = new MarkdownContentView($mockPage, $rawContent);
        
        if ($current['php']) {
            ob_start();
            $page = $mockPage;
            $content = $contentData;
            // Import file in isolated scope
            (function($__file, $page, $content) {
                include $__file;
            })($current['php'], $page, $content);
            $renderedHtml = ob_get_clean();
        }
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

/**
 * Interactive Object Explorer
 */
function explorer($obj, $name = 'root', $depth = 0) {
    $id = uniqid('ex');
    $type = gettype($obj);
    $label = is_object($obj) ? get_class($obj) : $type;
    $count = (is_array($obj) || is_object($obj)) ? count((array)$obj) : null;
    $displayLabel = "<strong>{$name}</strong>: <small>{$label}" . ($count !== null ? " ($count)" : "") . "</small>";

    if ($depth > 10) return "<li>Limit reached</li>";

    if (is_scalar($obj) || $obj === null) {
        $val = var_export($obj, true);
        return "<li>{$displayLabel} = <code class='val'>{$val}</code></li>";
    }

    $out = "<li>";
    $out .= "<details " . ($depth < 1 ? 'open' : '') . ">";
    $out .= "<summary>{$displayLabel}</summary>";
    $out .= "<ul>";
    
    foreach ((array)$obj as $k => $v) {
        // Clean up protected property names
        $k = str_replace("\0*\0", "", $k);
        $out .= explorer($v, $k, $depth + 1);
    }
    
    $out .= "</ul>";
    $out .= "</details>";
    $out .= "</li>";
    return $out;
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>MarkdownToFields Playground</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; margin: 0; display: grid; grid-template-columns: 300px 1fr; height: 100vh; background: #f5f7f9; color: #2c3e50; }
        
        /* Sidebar */
        aside { background: #fff; border-right: 1px solid #e1e4e8; overflow-y: auto; padding: 20px; }
        h1 { font-size: 16px; text-transform: uppercase; color: #a0aec0; margin-bottom: 20px; letter-spacing: 1px; }
        .ex-link { display: block; padding: 8px 12px; text-decoration: none; color: #4a5568; border-radius: 6px; font-size: 13px; margin-bottom: 2px; }
        .ex-link:hover { background: #edf2f7; }
        .ex-link.active { background: #3182ce; color: #fff; font-weight: 600; }

        /* Main */
        main { overflow-y: auto; padding: 40px; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; }
        
        section { background: #fff; border-radius: 12px; padding: 25px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); margin-bottom: 40px; border: 1px solid #e1e4e8; }
        h2 { margin-top: 0; font-size: 18px; border-bottom: 2px solid #f7fafc; padding-bottom: 10px; margin-bottom: 20px; }
        
        pre { background: #f8fafc; padding: 15px; border-radius: 8px; font-family: "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace; font-size: 13px; overflow-x: auto; border: 1px solid #edf2f7; }
        
        /* Explorer */
        ul { list-style: none; padding-left: 20px; margin: 0; }
        li { margin: 4px 0; }
        summary { cursor: pointer; padding: 4px 8px; border-radius: 4px; outline: none; transition: background 0.1s; }
        summary:hover { background: #f7fafc; }
        .val { color: #d53f8c; font-weight: 600; }
        small { color: #718096; margin-left: 8px; }
        
        .preview { border: 2px dashed #edf2f7; padding: 20px; border-radius: 8px; }
        .error { background: #fff5f5; border: 1px solid #feb2b2; color: #c53030; padding: 15px; border-radius: 8px; }
        
        .empty { display: flex; align-items: center; justify-content: center; height: 100%; color: #a0aec0; font-size: 20px; }
    </style>
</head>
<body>

<aside>
    <h1>Playground Examples</h1>
    <?php foreach ($examples as $name => $ex): ?>
        <a href="?ex=<?= $name ?>" class="ex-link <?= $selected === $name ? 'active' : '' ?>">
            <?= str_replace('fig-', 'FIG: ', $name) ?>
        </a>
    <?php endforeach; ?>
</aside>

<main>
    <?php if ($current): ?>
        <h2>Testing: <?= $current['name'] ?></h2>

        <?php if ($error): ?>
            <div class="error"><strong>Error:</strong> <?= $error ?></div>
        <?php endif; ?>

        <div class="grid">
            <section>
                <h2>Source Markdown</h2>
                <pre><?= htmlspecialchars(file_get_contents($current['md'])) ?></pre>
            </section>
            <section>
                <h2>PHP Template</h2>
                <?php if ($current['php']): ?>
                    <pre><?= htmlspecialchars(file_get_contents($current['php'])) ?></pre>
                <?php else: ?>
                    <p style="color: #a0aec0">No PHP snippet for this example.</p>
                <?php endif; ?>
            </section>
        </div>

        <section>
            <h2>Interactive Object Explorer (Final Truth)</h2>
            <div style="background: #fff; padding: 10px;">
                <ul id="explorer-root">
                    <?= explorer($contentData) ?>
                </ul>
            </div>
        </section>

        <section>
            <h2>Render Output</h2>
            <div class="preview">
                <?= $renderedHtml ?: '<em style="color:#a0aec0">No HTML output</em>' ?>
            </div>
            <pre style="margin-top: 20px; background: #2d3748; color: #fff;"><?= htmlspecialchars($renderedHtml) ?></pre>
        </section>

    <?php else: ?>
        <div class="empty">
            Select an example to start the x-ray audit.
        </div>
    <?php endif; ?>
</main>

</body>
</html>

<?php
namespace ProcessWire;

/**
 * MarkdownToFields Documentation Playground IDE
 */

// 1. Core library first
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../vendor/lemachinarbo/letmedown/src/LetMeDown.php';

use LetMeDown\LetMeDown;

// 2. Mocks - must be defined BEFORE requiring module files that extend them
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

// 3. Module files that depend on the mocks
require_once __DIR__ . '/../../MarkdownContentView.php';
require_once __DIR__ . '/../../MarkdownDataSet.php';
require_once __DIR__ . '/../../MarkdownNodeData.php';


$examplesDir = __DIR__ . '/examples';
$markdownDir = $examplesDir . '/markdown';
$phpDir = $examplesDir . '/php';

/**
 * Global dump target for the explorer
 */
$GLOBALS['__dumpTarget'] = null;
function dump($obj) {
    $GLOBALS['__dumpTarget'] = $obj;
}

/**
 * AJAX Runner Endpoint
 */
if (isset($_POST['markdown'])) {
    header('Content-Type: application/json');
    try {
        $md = $_POST['markdown'];
        $php = $_POST['php'] ?? '';
        
        $parser = new LetMeDown();
        $rawContent = $parser->loadFromString($md);
        $mockPage = new Page();
        $content = new MarkdownContentView($mockPage, $rawContent);
        
        // Execute PHP logic
        $renderedHtml = '';
        if ($php) {
            ob_start();
            $page = $mockPage;
            // Execute the string as PHP
            try {
                // Remove optional tags if present
                $cleanPhp = preg_replace('/^<\?php/', '', $php);
                $cleanPhp = preg_replace('/\?>$/', '', $cleanPhp);
                // Wrap in namespace so dump() and module classes are found
                eval("namespace ProcessWire; " . $cleanPhp . ';'); 
            } catch (\Throwable $e) {
                echo "<div style='color:red; font-family:monospace;'>PHP Error: " . htmlspecialchars($e->getMessage()) . "</div>";
            }
            $renderedHtml = ob_get_clean();
        }

        // Use targeted dump if provided, otherwise the whole content
        $inspectTarget = $GLOBALS['__dumpTarget'] ?? $content;

        echo json_encode([
            'html' => $renderedHtml,
            'explorer' => explorer($inspectTarget, $GLOBALS['__dumpTarget'] ? 'target' : 'content'),
            'rawHtml' => htmlspecialchars($renderedHtml)
        ]);
    } catch (\Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

/**
 * Discovery
 */
$examples = [];
foreach (glob("$markdownDir/*.md") as $file) {
    $name = basename($file, '.md');
    $examples[$name] = [
        'name' => $name,
        'md' => file_get_contents($file),
        'php' => file_exists("$phpDir/$name.php") ? file_get_contents("$phpDir/$name.php") : ''
    ];
}
ksort($examples);

function explorer($obj, $name = 'root', $depth = 0) {
    $type = gettype($obj);
    $label = is_object($obj) ? get_class($obj) : $type;
    $count = (is_array($obj) || is_object($obj)) ? count((array)$obj) : null;
    $displayLabel = "<strong>{$name}</strong>: <small>{$label}" . ($count !== null ? " ($count)" : "") . "</small>";

    if ($depth > 12) return "<li>Limit reached</li>";

    if (is_scalar($obj) || $obj === null) {
        $val = var_export($obj, true);
        $escapedVal = htmlspecialchars($val);
        
        // Collapsible long strings
        if (is_string($obj) && strlen($obj) > 40) {
            return "<li><details><summary>{$displayLabel} (Expand string)</summary><code class='val'>{$escapedVal}</code></details></li>";
        }
        
        return "<li>{$displayLabel} = <code class='val'>{$escapedVal}</code></li>";
    }

    $out = "<li>";
    $out .= "<details " . ($depth < 1 ? 'open' : '') . ">";
    $out .= "<summary>{$displayLabel}</summary>";
    $out .= "<ul>";
    
    foreach ((array)$obj as $k => $v) {
        $k = str_replace("\0*\0", "", $k); // Clean protected keys
        if (in_array($k, ['itemsCache', 'page', 'nodeArea'])) continue;
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
    <script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.32.7/ace.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/split.js/1.6.5/split.min.js"></script>
    <style>
        :root { --bg: #0f172a; --panel: #1e293b; --border: #334155; --text: #f8fafc; --accent: #38bdf8; }
        body { font-family: -apple-system, system-ui, sans-serif; margin: 0; background: var(--bg); color: var(--text); overflow: hidden; display: flex; flex-direction: column; height: 100vh; }
        
        header { background: var(--panel); border-bottom: 1px solid var(--border); padding: 10px 20px; display: flex; align-items: center; justify-content: space-between; z-index: 100; }
        .logo { font-weight: 800; font-size: 14px; color: var(--accent); text-transform: uppercase; letter-spacing: 1px; }
        select { background: #0f172a; color: white; border: 1px solid var(--border); padding: 5px 10px; border-radius: 4px; outline: none; }
        .btn-run { background: #22c55e; color: white; border: none; padding: 6px 20px; border-radius: 4px; font-weight: 700; cursor: pointer; transition: 0.2s; }
        .btn-run:hover { opacity: 0.8; }

        .workspace { flex-grow: 1; display: flex; flex-direction: column; overflow: hidden; }
        .split-v { display: flex; flex-direction: row; height: 100%; width: 100%; }
        .split-h { width: 100%; display: flex; flex-direction: column; }
        
        .panel { background: var(--panel); display: flex; flex-direction: column; overflow: hidden; position: relative; }
        .panel-header { background: #0f172a; padding: 6px 12px; font-size: 10px; font-weight: 800; text-transform: uppercase; color: #64748b; border-bottom: 1px solid var(--border); }
        .editor { flex-grow: 1; width: 100%; height: 100%; }
        
        .scrollable { overflow-y: auto; flex-grow: 1; padding: 15px; }

        /* Split.js Gutters */
        .gutter { background: #0f172a; background-repeat: no-repeat; background-position: center; }
        .gutter.gutter-horizontal { cursor: col-resize; background-image: url('data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAUAAAAeCAYAAADkftS9AAAAIklEQVQoU2M4c+bMfxAG8aRE4u/fv/8zclEioSjSTVDfv/9ZQsObCPabGgwAAAAASUVORK5CYII='); }
        .gutter.gutter-vertical { cursor: row-resize; background-image: url('data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAB4AAAAFAQMAAABjU97ZAAAABlBMVEVHcEzMzMzy7u4DAAAAAXRSTlMAQObYZgAAABRJREFUeF5jYGRgYPBggHGQYI8ABADZHTAFE9XPbQAAAABJRU5ErkJggg=='); }

        /* Explorer */
        ul { list-style: none; padding-left: 20px; }
        li { margin: 2px 0; font-family: monospace; font-size: 12px; line-height: 1.4; }
        summary { cursor: pointer; color: #94a3b8; outline: none; }
        summary:hover { color: var(--accent); }
        .val { color: #f472b6; white-space: pre-wrap; word-break: break-all; opacity: 0.9; }
        small { color: #475569; }
        
        .preview { background: white; color: black; border-radius: 4px; height: 100%; box-shadow: inset 0 2px 10px rgba(0,0,0,0.1); }
        .raw-html { font-size: 10px; opacity: 0.4; margin-top: 15px; border-top: 1px solid #ddd; padding-top: 10px; }
    </style>
</head>
<body>

<header>
    <div class="logo">MTF Playground IDE v2</div>
    <div>
        <select id="preset-select">
            <option value="">Load Example...</option>
            <?php foreach ($examples as $name => $ex): ?>
                <option value="<?= $name ?>"><?= str_replace('fig-', '', $name) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn-run" onclick="run()">RUN (⌘+S)</button>
    </div>
</header>

<div class="workspace">
    <div id="split-top" class="split-v">
        <div id="panel-md" class="panel">
            <div class="panel-header">Markdown</div>
            <div id="md-editor" class="editor"></div>
        </div>
        <div id="panel-php" class="panel">
            <div class="panel-header">PHP Logic</div>
            <div id="php-editor" class="editor"></div>
        </div>
    </div>
    <div id="split-bottom" class="split-v">
        <div id="panel-tree" class="panel">
            <div class="panel-header">Explorer</div>
            <div class="scrollable">
                <ul id="explorer-root"></ul>
            </div>
        </div>
        <div id="panel-preview" class="panel">
            <div class="panel-header">Preview</div>
            <div class="scrollable preview" id="preview-area"></div>
        </div>
    </div>
</div>

<script>
    const examples = <?= json_encode($examples) ?>;
    
    // Init Split.js
    Split(['#split-top', '#split-bottom'], {
        direction: 'vertical',
        sizes: [50, 50],
        gutterSize: 6
    });

    Split(['#panel-md', '#panel-php'], {
        sizes: [50, 50],
        gutterSize: 6
    });

    Split(['#panel-tree', '#panel-preview'], {
        sizes: [40, 60],
        gutterSize: 6
    });

    // Setup Editors
    const mdEditor = ace.edit("md-editor");
    mdEditor.setTheme("ace/theme/tomorrow_night_eighties");
    mdEditor.session.setMode("ace/mode/markdown");
    mdEditor.setShowPrintMargin(false);

    const phpEditor = ace.edit("php-editor");
    phpEditor.setTheme("ace/theme/tomorrow_night_eighties");
    phpEditor.session.setMode("ace/mode/php");
    phpEditor.setShowPrintMargin(false);

    // Hotkeys
    document.addEventListener('keydown', e => {
        if ((e.metaKey || e.ctrlKey) && e.key === 's') { e.preventDefault(); run(); }
    });

    // Preset loading
    document.getElementById('preset-select').onchange = e => {
        const ex = examples[e.target.value];
        if (ex) {
            mdEditor.setValue(ex.md, -1);
            phpEditor.setValue(ex.php, -1);
            run();
        }
    };

    async function run() {
        const formData = new FormData();
        formData.append('markdown', mdEditor.getValue());
        formData.append('php', phpEditor.getValue());

        try {
            const resp = await fetch(window.location.href, { method: 'POST', body: formData });
            const data = await resp.json();
            
            if (data.error) {
                document.getElementById('preview-area').innerHTML = `<div style="color:red; font-family:monospace;">${data.error}</div>`;
                return;
            }

            document.getElementById('preview-area').innerHTML = data.html + `<div class="raw-html"><h3>Raw HTML:</h3><pre>${data.rawHtml}</pre></div>`;
            document.getElementById('explorer-root').innerHTML = data.explorer;
        } catch (e) {
            console.error(e);
        }
    }

    // Default init
    mdEditor.setValue("# Playground\n\nEdit me!", -1);
    phpEditor.setValue("echo $content->text;", -1);
    run();
</script>

</body>
</html>

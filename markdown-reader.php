<?php

namespace ProcessWire;

/**
 * Default reader view template. Copy this file to site/templates/ to
 * replace the markup or theme; it receives the MarkdownReaderView slots
 * as variables:
 *
 * @var string $lang
 * @var string $title
 * @var string $canonicalUrl
 * @var string $rawMarkdownUrl
 * @var string $brandHtml
 * @var array  $nav            [['label' => ..., 'href' => ...], ...]
 * @var string $contentHtml
 * @var string $footerHtml
 */

$e = fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?><!DOCTYPE html>
<html lang="<?php echo $e($lang); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?php echo $e($title); ?></title>
<link rel="canonical" href="<?php echo $e($canonicalUrl); ?>">
<link rel="alternate" type="text/markdown" href="<?php echo $e($rawMarkdownUrl); ?>">
<style>
:root {
  --bg: #ffffff;
  --fg: #1a1a1a;
  --muted: #6b6b6b;
  --accent: #0b57d0;
  --rule: #e5e5e5;
}
@media (prefers-color-scheme: dark) {
  :root {
    --bg: #17181a;
    --fg: #e8e6e3;
    --muted: #9a9a9a;
    --accent: #8ab4f8;
    --rule: #333;
  }
}
* { box-sizing: border-box; }
body {
  margin: 0 auto;
  padding: 1.5rem 1rem 3rem;
  max-width: 42rem;
  background: var(--bg);
  color: var(--fg);
  font: 1.05rem/1.65 Georgia, 'Times New Roman', serif;
}
header, footer {
  font-family: system-ui, sans-serif;
  font-size: 0.85rem;
}
header {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem 1rem;
  align-items: baseline;
  border-bottom: 1px solid var(--rule);
  padding-bottom: 0.75rem;
  margin-bottom: 2rem;
}
header .brand { font-weight: 700; }
header nav ul { display: flex; flex-wrap: wrap; gap: 0.75rem; margin: 0; padding: 0; list-style: none; }
footer {
  border-top: 1px solid var(--rule);
  padding-top: 0.75rem;
  margin-top: 3rem;
  color: var(--muted);
}
a { color: var(--accent); }
h1, h2, h3, h4 { line-height: 1.25; font-family: system-ui, sans-serif; }
img { max-width: 100%; height: auto; }
pre { overflow-x: auto; padding: 0.75rem; border: 1px solid var(--rule); }
code { font-size: 0.9em; }
blockquote { margin: 1rem 0; padding-left: 1rem; border-left: 3px solid var(--rule); color: var(--muted); }
table { border-collapse: collapse; }
th, td { border: 1px solid var(--rule); padding: 0.3rem 0.6rem; }
</style>
</head>
<body>
<header>
  <span class="brand"><?php echo $brandHtml; ?></span>
  <?php if (count($nav)): ?>
  <nav>
    <ul>
      <?php foreach ($nav as $item): ?>
      <li><a href="<?php echo $e($item['href']); ?>"><?php echo $e($item['label']); ?></a></li>
      <?php endforeach; ?>
    </ul>
  </nav>
  <?php endif; ?>
</header>
<main>
<?php echo $contentHtml; ?>
</main>
<footer><?php echo $footerHtml; ?></footer>
</body>
</html>

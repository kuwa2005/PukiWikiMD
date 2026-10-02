<?php
// Convert legacy wiki/*.txt (hex names, PukiWiki syntax) into Markdown files.
// Usage: php pukiwiki/tools/pukiwiki-to-md.php [--dry-run]

if (PHP_SAPI !== 'cli') {
	fwrite(STDERR, "CLI only\n");
	exit(1);
}

$root = dirname(__DIR__);
require $root . '/lib/func.php';
require $root . '/lib/markdown.php';

$data = $root . '/wiki/';
$dry = in_array('--dry-run', $argv, TRUE);
$pages = array();
$files = array();

foreach (scandir($data) as $file) {
	if (! preg_match('/^([0-9A-F]+)\.txt$/', $file, $m)) continue;
	$page = decode($m[1]);
	$pages[] = $page;
	$files[$page] = $data . $file;
}
sort($pages);

$written = 0;
foreach ($pages as $page) {
	$src = file_get_contents($files[$page]);
	$md = pkwk_pukiwiki_to_markdown($src, $page, $pages);
	$rel = pkwk_md_relative_path($page);
	if ($rel === '') {
		fwrite(STDERR, "skip empty path: $page\n");
		continue;
	}
	$dest = $data . $rel;
	if ($dry) {
		echo $page . " -> " . $rel . "\n";
		continue;
	}
	$dir = dirname($dest);
	if (! is_dir($dir)) mkdir($dir, 0777, TRUE);
	file_put_contents($dest, $md);
	unlink($files[$page]);
	$written++;
}

echo $dry ? "dry-run " . count($pages) . " pages\n" : "converted $written pages\n";

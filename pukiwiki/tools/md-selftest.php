<?php
// CLI checks for path round-trip, Markdown render, and OGP parsing.

if (PHP_SAPI !== 'cli') exit(1);

if (! defined('CONTENT_CHARSET')) define('CONTENT_CHARSET', 'UTF-8');
$root = dirname(__DIR__);
require $root . '/lib/func.php';
require $root . '/lib/markdown.php';
require $root . '/lib/ogp.php';

$fail = 0;
function check($cond, $msg) {
	global $fail;
	if ($cond) {
		echo "ok  $msg\n";
	} else {
		echo "NG  $msg\n";
		$fail++;
	}
}

$samples = array('FrontPage', 'PukiWiki/1.4', ':config/plugin', 'ヘルプ', 'CON', '.hidden', 'a~b');
foreach ($samples as $page) {
	$rel = pkwk_md_relative_path($page);
	$back = pkwk_md_page_from_relative(substr($rel, 0, -3));
	check($back === $page, "roundtrip $page -> $rel");
}

if (! function_exists('get_page_uri')) {
	function get_page_uri($page, $flag = 0) { return '?' . rawurlencode($page); }
}

$html = pkwk_markdown_to_html(<<<'MD'
# 見出し

| 列 | 値 |
| --- | --- |
| A | **太** |

- [x] 完了
- [ ] 未了

~~取消~~ ==印==

脚注[^1]。

[^1]: 注です。

用語
:   説明

[toc]
MD
, FALSE);

check(strpos($html, '<h1') !== FALSE, 'heading');
check(strpos($html, '<table') !== FALSE, 'table');
check(strpos($html, 'type="checkbox"') !== FALSE, 'task list');
check(strpos($html, '<del>') !== FALSE, 'strikethrough');
check(strpos($html, '<mark>') !== FALSE, 'highlight');
check(strpos($html, 'footnote') !== FALSE || strpos($html, 'fn-') !== FALSE || strpos($html, 'fnref') !== FALSE, 'footnote');
check(strpos($html, '<dl>') !== FALSE || strpos($html, '<dt>') !== FALSE, 'description list');
check(pkwk_md_convert_bracket('表示>https://example.com/x') === '[表示](https://example.com/x)', 'external bracket');
check(pkwk_md_convert_bracket('Help|ヘルプ') === '[[Help|ヘルプ]]', 'wiki bracket');
check(strpos($html, 'class="toc"') !== FALSE, 'toc');
check(strpos($html, 'ogp-card') === FALSE, 'no ogp without flag');

$with = pkwk_markdown_to_html("https://ogp.me/\n", FALSE);
check(strpos($with, 'ogp.me') !== FALSE, 'bare url becomes link');
check(strpos($with, 'ogp-card') === FALSE, 'flag off skips card');

$meta = pkwk_ogp_parse(<<<'HTML'
<html><head>
<meta property="og:title" content="OGP Title">
<meta property="og:description" content="説明文">
<meta property="og:image" content="/cover.png">
<meta property="og:site_name" content="Example">
<title>fallback</title>
</head></html>
HTML
, 'https://example.com/page');
check($meta['title'] === 'OGP Title', 'og:title');
check($meta['description'] === '説明文', 'og:description');
check($meta['image'] === 'https://example.com/cover.png', 'relative og:image');
check($meta['site'] === 'Example', 'og:site_name');

$card_html = '<p><a href="https://example.com/a">https://example.com/a</a></p>';
$decorated = pkwk_ogp_decorate_html($card_html);
check(is_string($decorated), 'decorate returns string');

exit($fail === 0 ? 0 : 1);

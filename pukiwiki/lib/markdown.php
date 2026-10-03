<?php
// PukiWikiMD — Markdown page store and CommonMark/GFM renderer.
// License: GPL v2 or (at your option) any later version
//
// Pages are UTF-8 Markdown files under DATA_DIR. Rendering uses
// league/commonmark (CommonMark + GitHub Flavored Markdown, plus footnotes,
// description lists, attributes, highlight, front matter, and heading ids).

/**
 * Relative path of a page, without a leading slash, ending in .md.
 */
function pkwk_md_relative_path($page)
{
	$page = strip_bracket(strval($page));
	$page = str_replace('\\', '/', $page);
	$parts = explode('/', $page);
	$safe = array();
	foreach ($parts as $part) {
		if ($part === '') continue;
		$safe[] = pkwk_md_encode_segment($part);
	}
	if (count($safe) === 0) return '';
	return implode('/', $safe) . '.md';
}

function pkwk_md_encode_segment($part)
{
	$out = '';
	$len = strlen($part);
	for ($i = 0; $i < $len; $i++) {
		$c = $part[$i];
		$o = ord($c);
		if ($o < 0x20 || $o === 0x7F || strpos(":*?\"<>|\\~", $c) !== FALSE) {
			$out .= '~' . strtoupper(sprintf('%02X', $o));
		} else {
			$out .= $c;
		}
	}
	if (preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])$/i', $part)) {
		$out = '~' . strtoupper(sprintf('%02X', ord($part[0]))) . substr($part, 1);
	} else if ($out === '.' || $out === '..') {
		$out = '~2E' . substr($out, 1);
	} else if ($out !== '' && $out[0] === '.') {
		$out = '~2E' . substr($out, 1);
	}
	return $out;
}

function pkwk_md_decode_segment($segment)
{
	return preg_replace_callback('/~([0-9A-F]{2})/', function ($m) {
		return chr(hexdec($m[1]));
	}, $segment);
}

function pkwk_md_page_from_relative($relative)
{
	$relative = str_replace('\\', '/', $relative);
	if (substr($relative, -3) === '.md') {
		$relative = substr($relative, 0, -3);
	}
	$parts = explode('/', $relative);
	$decoded = array();
	foreach ($parts as $part) {
		if ($part === '' || $part === '.' || $part === '..') return '';
		$decoded[] = pkwk_md_decode_segment($part);
	}
	return implode('/', $decoded);
}

function pkwk_md_page_filepath($page, $for_write = FALSE)
{
	$rel = pkwk_md_relative_path($page);
	$md = DATA_DIR . $rel;
	if ($for_write || $rel === '') return $md;
	if (is_file($md)) return $md;
	$legacy = DATA_DIR . encode($page) . '.txt';
	if (is_file($legacy)) return $legacy;
	return $md;
}

function pkwk_md_list_pages($dir)
{
	$out = array();
	pkwk_md_scan_pages($dir, '', $out);
	$dp = @opendir($dir);
	if ($dp) {
		while (($file = readdir($dp)) !== FALSE) {
			if (! preg_match('/^([0-9A-F]+)\.txt$/', $file, $m)) continue;
			$page = decode($m[1]);
			if ($page === '' || in_array($page, $out, TRUE)) continue;
			$out[$file] = $page;
		}
		closedir($dp);
	}
	return $out;
}

function pkwk_md_scan_pages($base, $rel, &$out)
{
	$dir = ($rel === '') ? $base : $base . $rel;
	$dp = @opendir($dir);
	if (! $dp) return;
	$baseReal = realpath($base);
	while (($file = readdir($dp)) !== FALSE) {
		if ($file === '.' || $file === '..') continue;
		if ($file === 'index.html' || $file === '.htaccess') continue;
		$childRel = ($rel === '') ? $file : $rel . '/' . $file;
		$path = $base . $childRel;
		if (is_link($path)) continue;
		if (is_dir($path)) {
			$real = realpath($path);
			if ($baseReal && $real && strpos($real, $baseReal) !== 0) continue;
			pkwk_md_scan_pages($base, $childRel, $out);
			continue;
		}
		if (substr($file, -3) !== '.md') continue;
		$page = pkwk_md_page_from_relative($childRel);
		if ($page !== '') $out[$childRel] = $page;
	}
	closedir($dp);
}

function pkwk_md_prune_empty_dirs($dir)
{
	$base = rtrim(DATA_DIR, '/');
	$dir = rtrim($dir, '/');
	while ($dir !== $base && strpos($dir, $base . '/') === 0 && is_dir($dir)) {
		$items = @scandir($dir);
		if ($items === FALSE) return;
		$items = array_diff($items, array('.', '..', 'index.html', '.htaccess'));
		if (count($items) > 0) return;
		@rmdir($dir);
		$dir = dirname($dir);
	}
}

function pkwk_md_text_is_frozen($text)
{
	if (preg_match('/^#freeze\s*$/m', $text)) return TRUE;
	if (preg_match('/^frozen:\s*true\s*$/mi', $text)) return TRUE;
	return FALSE;
}

function pkwk_md_set_frozen($text, $frozen)
{
	$text = str_replace("\r\n", "\n", str_replace("\r", "\n", $text));
	$text = preg_replace('/^#freeze\s*\n/m', '', $text);
	$fm = '';
	$body = $text;
	if (preg_match('/\A---[ \t]*\n(.*?\n)---[ \t]*\n/s', $text, $m)) {
		$fm = preg_replace('/^frozen:\s*.*\n/mi', '', $m[1]);
		$body = substr($text, strlen($m[0]));
	}
	if ($frozen) {
		$fm = "frozen: true\n" . $fm;
	}
	$fm = trim($fm, "\n");
	$body = ltrim($body, "\n");
	if ($fm === '') return $body === '' ? '' : $body;
	return "---\n" . $fm . "\n---\n" . ($body === '' ? '' : $body);
}

function pkwk_markdown_to_html($source, $with_ogp = FALSE)
{
	$text = is_array($source) ? implode('', $source) : strval($source);
	$text = str_replace("\r\n", "\n", str_replace("\r", "\n", $text));
	$text = preg_replace('/^#author\([^\n]*\)\s*$/m', '', $text);
	$text = preg_replace('/^#freeze\s*$/m', '', $text);
	$text = preg_replace('/^#norelated\s*$/m', '', $text);
	$text = preg_replace('/^#nofollow\s*$/m', '', $text);

	$protected = array();
	$text = pkwk_md_protect_code($text, $protected);

	// #plugin / #plugin(args) — single "#" and no space after it (## / # title stay Markdown)
	$plugin_html = array();
	$text = pkwk_md_extract_block_plugins($text, $plugin_html);

	$text = preg_replace_callback('/\[\[((?:(?!\]\]).)+)\]\]/u', function ($m) {
		return pkwk_md_wikilink_to_md($m[1]);
	}, $text);
	$text = pkwk_md_restore_code($text, $protected);

	$autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
	if (! is_file($autoload)) {
		return '<p>Markdown の変換ライブラリがありません。リポジトリ直下で <code>composer install</code> を実行してください。</p>';
	}
	require_once $autoload;

	$config = array(
		'html_input' => 'allow',
		'allow_unsafe_links' => FALSE,
		'max_nesting_level' => 100,
		'heading_permalink' => array(
			'insert' => 'none',
			'apply_id_to_heading' => TRUE,
			'id_prefix' => '',
			'fragment_prefix' => '',
			'symbol' => '',
		),
		'external_link' => array(
			'internal_hosts' => array(),
			'open_in_new_window' => FALSE,
			'html_class' => 'external-link',
			'nofollow' => 'external',
			'noopener' => 'external',
			'noreferrer' => 'external',
		),
		'attributes' => array(
			'allow' => array('id', 'class'),
		),
	);

	try {
		$environment = new League\CommonMark\Environment\Environment($config);
		$environment->addExtension(new League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension());
		$environment->addExtension(new League\CommonMark\Extension\GithubFlavoredMarkdownExtension());
		$environment->addExtension(new League\CommonMark\Extension\Footnote\FootnoteExtension());
		$environment->addExtension(new League\CommonMark\Extension\DescriptionList\DescriptionListExtension());
		$environment->addExtension(new League\CommonMark\Extension\Attributes\AttributesExtension());
		$environment->addExtension(new League\CommonMark\Extension\Highlight\HighlightExtension());
		$environment->addExtension(new League\CommonMark\Extension\FrontMatter\FrontMatterExtension());
		$environment->addExtension(new League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension());
		$environment->addExtension(new League\CommonMark\Extension\ExternalLink\ExternalLinkExtension());
		$converter = new League\CommonMark\MarkdownConverter($environment);
		$html = $converter->convert($text)->getContent();
	} catch (Throwable $e) {
		return '<p>このページの Markdown を表示できませんでした。</p>';
	}

	$html = pkwk_md_restore_block_plugins($html, $plugin_html);
	$html = pkwk_md_insert_toc($html);
	if ($with_ogp && function_exists('pkwk_ogp_decorate_html')) {
		$html = pkwk_ogp_decorate_html($html);
	}
	return $html;
}

/**
 * Extract PukiWiki block plugins from Markdown source.
 *
 * Triggers only for a single leading "#" with no following space, e.g. #calendar2
 * or #calendar2(off). "## heading" / "# title" remain Markdown.
 *
 * @param string $text
 * @param array $plugin_html Filled with rendered HTML fragments
 * @return string
 */
function pkwk_md_extract_block_plugins($text, &$plugin_html)
{
	if (! function_exists('exist_plugin_convert') || ! function_exists('do_plugin_convert')) {
		return $text;
	}

	$lines = explode("\n", $text);
	$out = array();
	$n = count($lines);
	$i = 0;
	$multiline_ok = ! (defined('PKWKEXP_DISABLE_MULTILINE_PLUGIN_HACK')
		&& PKWKEXP_DISABLE_MULTILINE_PLUGIN_HACK);

	while ($i < $n) {
		$line = $lines[$i];

		if ($multiline_ok &&
			preg_match('/^#([A-Za-z][A-Za-z0-9_]*)(?:\((.*)\))?(\{\{+)\s*$/', $line, $m) &&
			exist_plugin_convert($m[1])) {
			$len = strlen($m[3]);
			$body_lines = array();
			$i++;
			$closed = FALSE;
			while ($i < $n) {
				$next = $lines[$i];
				$i++;
				if (preg_match('/^\}{' . $len . '}\s*$/', $next)) {
					$closed = TRUE;
					break;
				}
				$body_lines[] = $next;
			}
			if (! $closed) {
				// Incomplete fence: leave original lines as Markdown
				$out[] = $line;
				foreach ($body_lines as $bl) {
					$out[] = $bl;
				}
				continue;
			}
			$args = isset($m[2]) ? $m[2] : '';
			$args .= "\r" . implode("\r", $body_lines) . "\r";
			$html = do_plugin_convert($m[1], $args);
			$id = count($plugin_html);
			$plugin_html[$id] = is_string($html) ? $html : '';
			$out[] = '<!--PKWKPLUGIN:' . $id . '-->';
			continue;
		}

		// Single-line: #name or #name(args) — not ## and not "# title"
		if (preg_match('/^#([A-Za-z][A-Za-z0-9_]*)(?:\((.*)\))?\s*$/', $line, $m) &&
			exist_plugin_convert($m[1])) {
			$args = array_key_exists(2, $m) ? $m[2] : '';
			$html = do_plugin_convert($m[1], $args);
			$id = count($plugin_html);
			$plugin_html[$id] = is_string($html) ? $html : '';
			$out[] = '<!--PKWKPLUGIN:' . $id . '-->';
			$i++;
			continue;
		}

		$out[] = $line;
		$i++;
	}

	return implode("\n", $out);
}

/**
 * Restore plugin HTML that was reserved as <!--PKWKPLUGIN:n--> placeholders.
 *
 * @param string $html
 * @param array $plugin_html
 * @return string
 */
function pkwk_md_restore_block_plugins($html, $plugin_html)
{
	if (! is_array($plugin_html) || count($plugin_html) === 0) {
		return $html;
	}
	$html = preg_replace_callback(
		'/<p>\s*<!--PKWKPLUGIN:(\d+)-->\s*<\/p>/',
		function ($m) use ($plugin_html) {
			$id = (int)$m[1];
			return isset($plugin_html[$id]) ? $plugin_html[$id] : $m[0];
		},
		$html
	);
	$html = preg_replace_callback(
		'/<!--PKWKPLUGIN:(\d+)-->/',
		function ($m) use ($plugin_html) {
			$id = (int)$m[1];
			return isset($plugin_html[$id]) ? $plugin_html[$id] : $m[0];
		},
		$html
	);
	return $html;
}

function pkwk_md_protect_code($text, &$bucket)
{
	$text = preg_replace_callback('/```[^\n]*\n.*?```/s', function ($m) use (&$bucket) {
		$id = count($bucket);
		$bucket[$id] = $m[0];
		return 'PKWKFENCE' . $id . 'END';
	}, $text);
	$text = preg_replace_callback('/`[^`\n]+`/', function ($m) use (&$bucket) {
		$id = count($bucket);
		$bucket[$id] = $m[0];
		return 'PKWKCODE' . $id . 'END';
	}, $text);
	return $text;
}

function pkwk_md_restore_code($text, $bucket)
{
	return preg_replace_callback('/PKWK(?:FENCE|CODE)(\d+)END/', function ($m) use ($bucket) {
		$id = (int) $m[1];
		return isset($bucket[$id]) ? $bucket[$id] : $m[0];
	}, $text);
}

function pkwk_md_wikilink_to_md($inner)
{
	$inner = trim($inner);
	$label = $inner;
	$target = $inner;
	if (strpos($inner, '|') !== FALSE) {
		$parts = explode('|', $inner, 2);
		$target = trim($parts[0]);
		$label = trim($parts[1]);
	}
	$anchor = '';
	$hash = strpos($target, '#');
	if ($hash !== FALSE) {
		$anchor = substr($target, $hash);
		$target = substr($target, 0, $hash);
	}
	if (preg_match('#^(https?|ftp|mailto):#i', $target)) {
		$href = $target . $anchor;
	} else if ($target === '') {
		$href = ($anchor === '') ? '#' : $anchor;
	} else if (function_exists('get_page_uri')) {
		$href = get_page_uri($target) . $anchor;
	} else {
		$href = '?' . rawurlencode($target) . $anchor;
	}
	$label = str_replace(array('\\', '[', ']'), array('\\\\', '\\[', '\\]'), $label);
	$href = str_replace(array(' ', ')'), array('%20', '%29'), $href);
	return '[' . $label . '](' . $href . ')';
}

function pkwk_md_insert_toc($html)
{
	if (strpos($html, '[toc]') === FALSE) return $html;
	if (! preg_match_all('/<h([1-6])\b([^>]*)>(.*?)<\/h\1>/is', $html, $heads, PREG_SET_ORDER)) {
		return str_replace('<p>[toc]</p>', '', $html);
	}
	$items = '';
	foreach ($heads as $h) {
		$id = '';
		if (preg_match('/\bid="([^"]*)"/', $h[2], $idm)) $id = $idm[1];
		$label = trim(strip_tags($h[3]));
		if ($label === '') continue;
		$href = ($id === '') ? '' : ' href="#' . htmlsc($id) . '"';
		$items .= '<li class="toc-l' . (int) $h[1] . '"><a' . $href . '>' . htmlsc($label) . '</a></li>';
	}
	$toc = '<nav class="toc"><p class="toc-title">目次</p><ul>' . $items . '</ul></nav>';
	return preg_replace('/<p>\s*\[toc\]\s*<\/p>/', $toc, $html, 1);
}

function pkwk_md_link_objects($page)
{
	$source = get_source($page, TRUE, TRUE);
	if (! is_string($source) || $source === '') return array();
	$source = preg_replace('/```[^\n]*\n.*?```/s', '', $source);
	$names = array();
	if (preg_match_all('/\[\[((?:(?!\]\]).)+)\]\]/u', $source, $all)) {
		foreach ($all[1] as $inner) {
			$target = $inner;
			if (strpos($inner, '|') !== FALSE) {
				$target = trim(explode('|', $inner, 2)[0]);
			} else if (strpos($inner, '>') !== FALSE) {
				$target = trim(explode('>', $inner, 2)[1]);
			}
			$target = preg_replace('/#.*$/', '', trim($target));
			if ($target === '' || preg_match('#^(https?|ftp|mailto):#i', $target)) continue;
			if (function_exists('get_fullname')) $target = get_fullname($target, $page);
			if ($target !== '') $names[$target] = TRUE;
		}
	}
	$out = array();
	foreach (array_keys($names) as $name) {
		$out[] = new MdPageLink($name);
	}
	return $out;
}

class MdPageLink
{
	var $type = 'pagename';
	var $name;
	function __construct($name)
	{
		$this->name = $name;
	}
}

function pkwk_recent_list_html($limit = 12)
{
	global $whatsnew;
	$pages = array();
	foreach (get_existpages() as $page) {
		if ($page === $whatsnew || check_non_list($page)) continue;
		$pages[$page] = get_filetime($page);
	}
	arsort($pages, SORT_NUMERIC);
	$pages = array_slice($pages, 0, $limit, TRUE);
	$html = '<h2>最近の更新</h2><ul class="recent_list">';
	if (count($pages) === 0) {
		$html .= '<li><span class="small">まだページはありません</span></li>';
	}
	foreach ($pages as $page => $time) {
		$html .= '<li><a href="' . htmlsc(get_page_uri($page)) . '">' . htmlsc($page) . '</a>'
			. ' <small>' . htmlsc(format_date($time)) . '</small></li>';
	}
	$html .= '</ul>';
	return $html;
}

/**
 * Convert a legacy PukiWiki page into Markdown.
 * Plugin lines are dropped or rewritten. The result is what gets stored.
 */
function pkwk_pukiwiki_to_markdown($source, $page = '', $all_pages = array())
{
	$source = str_replace("\r\n", "\n", str_replace("\r", "\n", $source));
	$lines = explode("\n", $source);
	$out = array();
	$frozen = FALSE;
	$n = count($lines);
	$i = 0;
	while ($i < $n) {
		$line = $lines[$i];

		if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t")) {
			$buf = array();
			while ($i < $n && $lines[$i] !== '' && ($lines[$i][0] === ' ' || $lines[$i][0] === "\t")) {
				$raw = $lines[$i];
				$buf[] = ($raw[0] === "\t") ? substr($raw, 1) : substr($raw, 1);
				$i++;
			}
			$out[] = '```';
			foreach ($buf as $b) $out[] = $b;
			$out[] = '```';
			continue;
		}

		if ($line !== '' && $line[0] === '|') {
			$rows = array();
			while ($i < $n && $lines[$i] !== '' && $lines[$i][0] === '|') {
				$rows[] = pkwk_md_convert_table_row($lines[$i]);
				$i++;
			}
			foreach (pkwk_md_format_table($rows) as $row) $out[] = $row;
			continue;
		}

		$converted = pkwk_md_convert_line($line, $page, $all_pages, $frozen);
		if ($converted === NULL) {
			$i++;
			continue;
		}
		foreach (explode("\n", $converted) as $piece) $out[] = $piece;
		$i++;
	}

	$text = implode("\n", $out);
	$text = preg_replace("/\n{3,}/", "\n\n", $text);
	$text = ltrim($text, "\n");
	if ($frozen) {
		$text = pkwk_md_set_frozen($text, TRUE);
	}
	if ($text === '' || substr($text, -1) !== "\n") $text .= "\n";
	return $text;
}

function pkwk_md_convert_line($line, $page, $all_pages, &$frozen)
{
	if ($line === '') return '';
	if (strncmp($line, '//', 2) === 0) {
		$comment = trim(substr($line, 2));
		$comment = str_replace('--', '- -', $comment);
		return '<!-- ' . $comment . ' -->';
	}
	if (preg_match('/^(LEFT|CENTER|RIGHT):(.*)$/', $line, $m)) {
		$line = $m[2];
		if ($line === '') return '';
	}
	if (preg_match('/^#author\(/', $line)) return NULL;
	if (preg_match('/^#freeze\s*$/', $line)) {
		$frozen = TRUE;
		return NULL;
	}
	if (preg_match('/^#(nofollow|norelated|setlinebreak|comment|recent|menu)(?:\(|\s*$)/', $line)) {
		return NULL;
	}
	if (preg_match('/^#contents\s*$/', $line)) return '[toc]';
	if (preg_match('/^#hr\s*$/', $line)) return '---';
	if (preg_match('/^#br\s*$/', $line)) return '';
	if (preg_match('/^#ls2?\b/', $line)) {
		return pkwk_md_child_list($page, $all_pages, strpos($line, 'title') !== FALSE);
	}
	if (preg_match('/^#navi\b/', $line)) {
		return pkwk_md_navi_link($page);
	}
	if (preg_match('/^#[A-Za-z_]/', $line)) return NULL;

	if (preg_match('/^-{4,}\s*$/', $line)) return '---';
	if (preg_match('/^(\*{1,3})\s+(.*)$/', $line, $m)) {
		$level = strlen($m[1]);
		$text = $m[2];
		$id = '';
		if (preg_match('/^(.*)\s*\[#([A-Za-z][\w-]*)\]\s*$/', $text, $h)) {
			$text = rtrim($h[1]);
			$id = ' {#' . $h[2] . '}';
		}
		return str_repeat('#', $level) . ' ' . pkwk_md_convert_inline($text) . $id;
	}
	if (preg_match('/^(-{1,3})(?!-)\s?(.*)$/', $line, $m)) {
		$level = strlen($m[1]);
		return str_repeat('  ', $level - 1) . '- ' . pkwk_md_convert_inline($m[2]);
	}
	if (preg_match('/^(\+{1,3})\s?(.*)$/', $line, $m)) {
		$level = strlen($m[1]);
		return str_repeat('   ', $level - 1) . '1. ' . pkwk_md_convert_inline($m[2]);
	}
	if (preg_match('/^;(.+)$/', $line, $m)) {
		return pkwk_md_convert_inline($m[1]);
	}
	if (preg_match('/^:(.+)$/', $line, $m)) {
		$body = $m[1];
		if (strpos($body, '|') !== FALSE) {
			list($term, $desc) = explode('|', $body, 2);
			return pkwk_md_convert_inline(trim($term)) . "\n:   " . pkwk_md_convert_inline(trim($desc));
		}
		return ':   ' . pkwk_md_convert_inline($body);
	}
	if ($line[0] === '>' || $line[0] === '<') {
		return '> ' . pkwk_md_convert_inline(ltrim(substr($line, 1)));
	}

	$br = FALSE;
	if (substr($line, -1) === '~') {
		$line = substr($line, 0, -1);
		$br = TRUE;
	}
	if (isset($line[0]) && $line[0] === '~' && isset($line[1]) && strpos('*+-#|>:<', $line[1]) !== FALSE) {
		$line = substr($line, 1);
	}
	$line = pkwk_md_convert_inline($line);
	if ($br) $line .= '  ';
	return $line;
}

function pkwk_md_child_list($page, $all_pages, $with_title)
{
	$prefix = ($page === '') ? '' : $page . '/';
	$children = array();
	foreach ($all_pages as $name) {
		if ($prefix === '') {
			if (strpos($name, '/') === FALSE) $children[] = $name;
			continue;
		}
		if (strpos($name, $prefix) !== 0) continue;
		$rest = substr($name, strlen($prefix));
		if ($rest !== '' && strpos($rest, '/') === FALSE) $children[] = $name;
	}
	natcasesort($children);
	$lines = array();
	foreach ($children as $name) {
		$label = ($prefix === '') ? $name : substr($name, strlen($prefix));
		$lines[] = '- [[' . $name . '|' . $label . ']]';
	}
	if ($with_title && count($lines) === 0) return '';
	return implode("\n", $lines);
}

function pkwk_md_navi_link($page)
{
	$parent = dirname(str_replace('\\', '/', $page));
	if ($parent === '.' || $parent === '' || $parent === $page) return '';
	return '[[' . $parent . '|↑ ' . $parent . ']]';
}

function pkwk_md_convert_inline($text)
{
	$text = preg_replace('/%%%(.+?)%%%/u', '<u>$1</u>', $text);
	$text = preg_replace('/%%(.+?)%%/u', '~~$1~~', $text);
	$text = preg_replace("/'''(.+?)'''/u", '*$1*', $text);
	$text = preg_replace("/''(.+?)''/u", '**$1**', $text);
	$text = preg_replace_callback('/\[\[((?:(?!\]\]).)+)\]\]/u', function ($m) {
		return pkwk_md_convert_bracket($m[1]);
	}, $text);
	return $text;
}

function pkwk_md_convert_bracket($inner)
{
	if (preg_match('/^(.*?)>(.+)$/u', $inner, $p)) {
		$alias = $p[1];
		$target = $p[2];
		if (preg_match('#^(https?|ftp|mailto):#i', $target)) {
			if ($alias === '') $alias = $target;
			return '[' . $alias . '](' . $target . ')';
		}
		$alias = str_replace('|', '\\|', $alias);
		return '[[' . $target . '|' . $alias . ']]';
	}
	return '[[' . $inner . ']]';
}

function pkwk_md_convert_table_row($line)
{
	$line = rtrim($line);
	if (substr($line, -1) === '|') $line = substr($line, 0, -1);
	if ($line !== '' && $line[0] === '|') $line = substr($line, 1);
	$cells = explode('|', $line);
	$header = FALSE;
	$out = array();
	foreach ($cells as $cell) {
		$cell = trim($cell);
		if (strncmp($cell, '~', 1) === 0) {
			$header = TRUE;
			$cell = substr($cell, 1);
		}
		if ($cell !== '' && ($cell[0] === '>' || $cell[0] === ':')) {
			$cell = substr($cell, 1);
		}
		$out[] = str_replace('|', '\\|', pkwk_md_convert_inline(trim($cell)));
	}
	return array($header, $out);
}

function pkwk_md_format_table($rows)
{
	if (count($rows) === 0) return array();
	$width = 0;
	foreach ($rows as $row) $width = max($width, count($row[1]));
	$norm = array();
	$has_header = FALSE;
	foreach ($rows as $row) {
		$cells = $row[1];
		while (count($cells) < $width) $cells[] = '';
		$norm[] = $cells;
		if ($row[0]) $has_header = TRUE;
	}
	$lines = array();
	$lines[] = '| ' . implode(' | ', $norm[0]) . ' |';
	$lines[] = '| ' . implode(' | ', array_fill(0, $width, '---')) . ' |';
	$start = 1;
	if (! $has_header) {
		// GFM needs a header row; the first PukiWiki row already occupies it.
		$start = 1;
	}
	for ($i = $start; $i < count($norm); $i++) {
		$lines[] = '| ' . implode(' | ', $norm[$i]) . ' |';
	}
	return $lines;
}

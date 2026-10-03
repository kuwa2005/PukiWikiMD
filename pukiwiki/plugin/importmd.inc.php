<?php
// PukiWikiMD - Import Markdown file as a new wiki page (drag-and-drop API)
// License: GPL v2 or (at your option) any later version

// Max upload size for .md import (bytes)
if (! defined('PLUGIN_IMPORTMD_MAX_BYTES')) {
	define('PLUGIN_IMPORTMD_MAX_BYTES', 8 * 1024 * 1024);
}

function plugin_importmd_action()
{
	global $vars;

	if (isset($vars['pcmd']) && $vars['pcmd'] === 'api') {
		plugin_importmd_api_action();
	}
	die_message('Invalid action');
}

/**
 * JSON API: POST plugin=importmd&pcmd=api with md_file (+ optional refer).
 */
function plugin_importmd_api_action()
{
	global $vars;

	if (PKWK_READONLY) {
		plugin_importmd_json(array('ok' => FALSE, 'error' => '読み取り専用のため作成できません'));
	}

	$refer = isset($vars['refer']) ? $vars['refer'] : '';
	if ($refer === '' && isset($vars['page'])) {
		$refer = $vars['page'];
	}

	if (! isset($_FILES['md_file'])) {
		plugin_importmd_json(array('ok' => FALSE, 'error' => 'ファイルがありません'));
	}

	$file = $_FILES['md_file'];
	if (! isset($file['error']) || (int)$file['error'] !== UPLOAD_ERR_OK ||
		$file['tmp_name'] === '' || ! is_uploaded_file($file['tmp_name'])) {
		plugin_importmd_json(array('ok' => FALSE, 'error' => 'アップロードに失敗しました'));
	}
	if ((int)$file['size'] > PLUGIN_IMPORTMD_MAX_BYTES) {
		plugin_importmd_json(array('ok' => FALSE, 'error' => 'ファイルが大きすぎます'));
	}

	$orig = isset($file['name']) ? $file['name'] : '';
	$leaf = plugin_importmd_pagename_from_filename($orig);
	if ($leaf === '') {
		plugin_importmd_json(array('ok' => FALSE, 'error' => 'Markdown ファイル（.md）のみ作成できます'));
	}

	// ドロップ先ページの子として作成（例: 議事録 + aaaa.md → 議事録/aaaa）
	$page = plugin_importmd_resolve_child_pagename($leaf, $refer);
	if ($page === '') {
		plugin_importmd_json(array('ok' => FALSE, 'error' => 'ページ名として使えません: ' . $leaf));
	}

	$content = file_get_contents($file['tmp_name']);
	if ($content === FALSE) {
		plugin_importmd_json(array('ok' => FALSE, 'error' => 'ファイルの読み込みに失敗しました'));
	}

	$result = plugin_importmd_create_page($page, $content, $refer, $leaf);
	plugin_importmd_json($result);
}

/**
 * Create a new wiki page from Markdown text.
 *
 * @param string $page Full page name
 * @param string $content
 * @param string $refer Parent/context page
 * @param string $leaf_title Optional leaf name for empty-file heading
 * @return array JSON-serializable result
 */
function plugin_importmd_create_page($page, $content, $refer = '', $leaf_title = '')
{
	if (! is_pagename($page) || ! is_pagename_bytes_within_hard_limit($page)) {
		return array('ok' => FALSE, 'error' => 'ページ名として使えません: ' . $page);
	}
	if (function_exists('pkwk_is_safe_identifier') && ! pkwk_is_safe_identifier($page)) {
		return array('ok' => FALSE, 'error' => 'ページ名として使えません: ' . $page);
	}
	if (! check_editable($page, TRUE, FALSE)) {
		return array(
			'ok' => FALSE,
			'error' => '編集権限がありません。ログインしてからやり直してください。',
			'login' => TRUE,
		);
	}
	if (is_page($page)) {
		return array(
			'ok' => FALSE,
			'error' => 'すでに存在するページです: ' . $page,
			'page' => $page,
			'uri' => get_page_uri($page, PKWK_URI_ROOT),
		);
	}

	if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
		$content = substr($content, 3);
	}
	$content = str_replace("\r\n", "\n", $content);
	$content = str_replace("\r", "\n", $content);
	if (trim($content) === '') {
		$heading = ($leaf_title !== '') ? $leaf_title : $page;
		$content = '# ' . $heading . "\n";
	}
	if (substr($content, -1) !== "\n") {
		$content .= "\n";
	}

	page_write($page, $content);

	if (! is_page($page)) {
		return array('ok' => FALSE, 'error' => 'ページの保存に失敗しました');
	}

	$parent_link = plugin_importmd_append_parent_link($refer, $page);

	return array(
		'ok' => TRUE,
		'page' => $page,
		'uri' => get_page_uri($page, PKWK_URI_ROOT),
		'refer' => $refer,
		'parent_link' => $parent_link,
	);
}

/**
 * Append [[child]] to the drop-target (parent) page.
 *
 * @param string $refer Parent page name
 * @param string $child_page Full child page name
 * @return array{ok:bool,skipped?:bool,already?:bool,error?:string,link?:string}
 */
function plugin_importmd_append_parent_link($refer, $child_page)
{
	if ($refer === '' || ! is_pagename($refer) || ! is_page($refer)) {
		return array('ok' => FALSE, 'skipped' => TRUE);
	}
	if ($child_page === '' || ! is_pagename($child_page)) {
		return array('ok' => FALSE, 'skipped' => TRUE);
	}
	if (! check_editable($refer, TRUE, FALSE)) {
		return array('ok' => FALSE, 'error' => '親ページを編集できません');
	}

	$link = '[[' . $child_page . ']]';
	$src = join('', get_source($refer));
	if (strpos($src, $link) !== FALSE) {
		return array('ok' => TRUE, 'already' => TRUE, 'link' => $link);
	}

	$src = rtrim($src);
	if ($src !== '') {
		$src .= "\n";
	}
	$src .= $link . "\n";
	page_write($refer, $src);

	return array('ok' => TRUE, 'link' => $link);
}

/**
 * Build a child page name under $refer from a leaf name.
 *
 * @param string $leaf Filename without extension
 * @param string $refer Current page
 * @return string Empty if unusable
 */
function plugin_importmd_resolve_child_pagename($leaf, $refer)
{
	global $defaultpage;

	if ($leaf === '' || ! is_pagename($leaf)) {
		return '';
	}
	if ($refer !== '' && is_pagename($refer)) {
		return get_fullname('./' . $leaf, $refer);
	}
	if (isset($defaultpage) && $defaultpage !== '' && is_pagename($defaultpage)) {
		return get_fullname('./' . $leaf, $defaultpage);
	}
	return $leaf;
}

/**
 * Derive a leaf wiki page name from an uploaded .md filename.
 *
 * @param string $filename
 * @return string Empty if not a usable Markdown file name
 */
function plugin_importmd_pagename_from_filename($filename)
{
	$base = basename(str_replace('\\', '/', (string)$filename));
	if ($base === '' || $base === '.' || $base === '..') {
		return '';
	}
	if (! preg_match('/\.(md|markdown)$/i', $base)) {
		return '';
	}
	$name = preg_replace('/\.(md|markdown)$/i', '', $base);
	if ($name === '' || strpos($name, '/') !== FALSE || strpos($name, '\\') !== FALSE) {
		return '';
	}
	return $name;
}

function plugin_importmd_json($obj)
{
	pkwk_common_headers();
	header('Content-Type: application/json; charset=UTF-8');
	print(json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	exit;
}

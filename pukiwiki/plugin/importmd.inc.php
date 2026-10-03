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
	$page = plugin_importmd_pagename_from_filename($orig);
	if ($page === '') {
		plugin_importmd_json(array('ok' => FALSE, 'error' => 'Markdown ファイル（.md）のみ作成できます'));
	}

	$content = file_get_contents($file['tmp_name']);
	if ($content === FALSE) {
		plugin_importmd_json(array('ok' => FALSE, 'error' => 'ファイルの読み込みに失敗しました'));
	}

	$result = plugin_importmd_create_page($page, $content, $refer);
	plugin_importmd_json($result);
}

/**
 * Create a new wiki page from Markdown text.
 *
 * @param string $page
 * @param string $content
 * @param string $refer Current page (informational)
 * @return array JSON-serializable result
 */
function plugin_importmd_create_page($page, $content, $refer = '')
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
		$content = '# ' . $page . "\n";
	}
	if (substr($content, -1) !== "\n") {
		$content .= "\n";
	}

	page_write($page, $content);

	if (! is_page($page)) {
		return array('ok' => FALSE, 'error' => 'ページの保存に失敗しました');
	}

	return array(
		'ok' => TRUE,
		'page' => $page,
		'uri' => get_page_uri($page, PKWK_URI_ROOT),
		'refer' => $refer,
	);
}

/**
 * Derive a wiki page name from an uploaded .md filename.
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

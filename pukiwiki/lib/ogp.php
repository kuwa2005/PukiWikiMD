<?php
// PukiWikiMD — Open Graph cards for URLs on the read view.
// License: GPL v2 or (at your option) any later version
//
// A paragraph that is only an http(s) link becomes a link plus an OGP card
// when the page is displayed. Edit and preview do not fetch cards.

if (! defined('PKWK_OGP_MAX_BYTES')) define('PKWK_OGP_MAX_BYTES', 262144);
if (! defined('PKWK_OGP_TIMEOUT')) define('PKWK_OGP_TIMEOUT', 3);
if (! defined('PKWK_OGP_MAX_CARDS')) define('PKWK_OGP_MAX_CARDS', 5);

function pkwk_ogp_config()
{
	global $ogp_enabled, $ogp_cache_hours, $ogp_max_cards;

	return array(
		'enabled' => isset($ogp_enabled) ? (bool) $ogp_enabled : TRUE,
		'cache_hours' => isset($ogp_cache_hours) ? (float) $ogp_cache_hours : 24,
		'max_cards' => isset($ogp_max_cards) ? (int) $ogp_max_cards : PKWK_OGP_MAX_CARDS,
	);
}

function pkwk_ogp_decorate_html($html)
{
	$cfg = pkwk_ogp_config();
	if (! $cfg['enabled'] || $html === '') return $html;

	$used = 0;
	$max = max(0, $cfg['max_cards']);
	$pattern = '#<(p|li)>(\s*<a\b[^>]*href=(["\'])(https?://[^"\']+)\3[^>]*>.*?</a>\s*)</\1>#is';
	return preg_replace_callback($pattern, function ($m) use (&$used, $max) {
		if ($used >= $max) return $m[0];
		$href = html_entity_decode($m[4], ENT_QUOTES, 'UTF-8');
		// A paragraph of prose that happens to be one link is still a "pasted URL"
		// only when the visible text is the URL itself or the link is the whole paragraph.
		// The regex already requires the paragraph to contain only that anchor.
		$card = pkwk_ogp_card($href);
		if ($card === '') return $m[0];
		$used++;
		return $m[0] . "\n" . $card;
	}, $html);
}

function pkwk_ogp_card($url)
{
	$meta = pkwk_ogp_fetch($url);
	if (! is_array($meta) || empty($meta['title'])) return '';

	$title = htmlsc($meta['title']);
	$desc = isset($meta['description']) ? htmlsc($meta['description']) : '';
	$site = isset($meta['site']) ? htmlsc($meta['site']) : '';
	$href = htmlsc($url);
	$image = '';
	if (! empty($meta['image'])) {
		$image = '<img class="ogp-image" src="' . htmlsc($meta['image']) . '" alt="" loading="lazy" />';
	}
	$desc_html = ($desc === '') ? '' : '<span class="ogp-desc">' . $desc . '</span>';
	$site_html = ($site === '') ? '' : '<span class="ogp-site">' . $site . '</span>';

	return '<aside class="ogp-card"><a class="ogp-link" href="' . $href . '" rel="nofollow noopener noreferrer">'
		. $image
		. '<span class="ogp-body"><strong class="ogp-title">' . $title . '</strong>'
		. $desc_html
		. $site_html
		. '</span></a></aside>';
}

function pkwk_ogp_fetch($url)
{
	$url = pkwk_ogp_normalize($url);
	if ($url === FALSE) return FALSE;

	$cached = pkwk_ogp_cache_get($url);
	if ($cached !== FALSE) return $cached;

	if (! function_exists('pkwk_oembed_url_is_safe')) {
		require_once LIB_DIR . 'oembed.php';
	}
	if (! pkwk_oembed_url_is_safe($url)) {
		pkwk_ogp_cache_set($url, array('title' => ''));
		return FALSE;
	}

	$html = pkwk_ogp_http_get($url, PKWK_OGP_MAX_BYTES, PKWK_OGP_TIMEOUT, 2);
	if ($html === FALSE || $html === '') {
		pkwk_ogp_cache_set($url, array('title' => ''));
		return FALSE;
	}

	$meta = pkwk_ogp_parse($html, $url);
	if (! is_array($meta)) $meta = array('title' => '');
	pkwk_ogp_cache_set($url, $meta);
	if ($meta['title'] === '') return FALSE;
	return $meta;
}

function pkwk_ogp_normalize($url)
{
	$url = trim(html_entity_decode(strval($url), ENT_QUOTES, 'UTF-8'));
	if (! preg_match('#^https?://#i', $url)) return FALSE;
	if (preg_match('/[\s<>"\']/', $url)) return FALSE;
	return $url;
}

function pkwk_ogp_parse($html, $page_url)
{
	$html = substr($html, 0, PKWK_OGP_MAX_BYTES);
	$title = '';
	$description = '';
	$image = '';
	$site = '';

	if (preg_match_all('/<meta\b[^>]*>/i', $html, $tags)) {
		foreach ($tags[0] as $tag) {
			$key = '';
			if (preg_match('/\bproperty\s*=\s*(["\'])(.*?)\1/i', $tag, $m)) {
				$key = strtolower($m[2]);
			} else if (preg_match('/\bname\s*=\s*(["\'])(.*?)\1/i', $tag, $m)) {
				$key = strtolower($m[2]);
			}
			if ($key === '' || ! preg_match('/\bcontent\s*=\s*(["\'])(.*?)\1/is', $tag, $c)) continue;
			$value = trim(html_entity_decode($c[2], ENT_QUOTES, 'UTF-8'));
			if ($value === '') continue;
			switch ($key) {
			case 'og:title':
				if ($title === '') $title = $value;
				break;
			case 'twitter:title':
				if ($title === '') $title = $value;
				break;
			case 'og:description':
				if ($description === '') $description = $value;
				break;
			case 'twitter:description':
				if ($description === '') $description = $value;
				break;
			case 'description':
				if ($description === '') $description = $value;
				break;
			case 'og:image':
			case 'twitter:image':
				if ($image === '') $image = $value;
				break;
			case 'og:site_name':
				if ($site === '') $site = $value;
				break;
			}
		}
	}

	if ($title === '' && preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
		$title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8'));
	}

	$title = pkwk_ogp_clip($title, 140);
	$description = pkwk_ogp_clip($description, 220);
	$site = pkwk_ogp_clip($site, 80);
	if ($site === '') {
		$host = parse_url($page_url, PHP_URL_HOST);
		$site = $host ? $host : '';
	}

	$image = pkwk_ogp_resolve_url($image, $page_url);
	if ($image !== '' && ! pkwk_ogp_image_is_safe($image)) $image = '';

	return array(
		'title' => $title,
		'description' => $description,
		'image' => $image,
		'site' => $site,
	);
}

function pkwk_ogp_clip($text, $max)
{
	$text = trim(preg_replace('/\s+/u', ' ', $text));
	if (function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') > $max) {
		return mb_substr($text, 0, $max, 'UTF-8') . '…';
	}
	if (strlen($text) > $max) return substr($text, 0, $max) . '…';
	return $text;
}

function pkwk_ogp_resolve_url($url, $base)
{
	$url = trim($url);
	if ($url === '') return '';
	if (preg_match('#^https?://#i', $url)) return $url;
	if (strpos($url, '//') === 0) {
		$scheme = parse_url($base, PHP_URL_SCHEME);
		return ($scheme ? $scheme : 'https') . ':' . $url;
	}
	$parts = parse_url($base);
	if (! is_array($parts) || empty($parts['host'])) return '';
	$origin = $parts['scheme'] . '://' . $parts['host'];
	if (! empty($parts['port'])) $origin .= ':' . $parts['port'];
	if (isset($url[0]) && $url[0] === '/') return $origin . $url;
	$path = isset($parts['path']) ? $parts['path'] : '/';
	$dir = substr($path, 0, strrpos($path, '/') + 1);
	return $origin . $dir . $url;
}

function pkwk_ogp_image_is_safe($url)
{
	if (! preg_match('#^https?://#i', $url)) return FALSE;
	if (! function_exists('pkwk_oembed_url_is_safe')) return TRUE;
	return pkwk_oembed_url_is_safe($url) !== FALSE;
}

function pkwk_ogp_http_get($url, $max_bytes, $timeout, $redirects)
{
	if (! function_exists('pkwk_oembed_url_is_safe') || pkwk_oembed_url_is_safe($url) === FALSE) {
		return FALSE;
	}
	if (! ini_get('allow_url_fopen')) return FALSE;

	$ctx = stream_context_create(array(
		'http' => array(
			'method' => 'GET',
			'timeout' => $timeout,
			'follow_location' => 0,
			'ignore_errors' => TRUE,
			'header' => "User-Agent: PukiWikiMD\r\nAccept: text/html\r\n",
		),
		'ssl' => array(
			'verify_peer' => TRUE,
			'verify_peer_name' => TRUE,
		),
	));
	$fp = @fopen($url, 'rb', FALSE, $ctx);
	if ($fp === FALSE) return FALSE;
	$meta = stream_get_meta_data($fp);
	$data = '';
	while (! feof($fp) && strlen($data) < $max_bytes) {
		$chunk = fread($fp, 8192);
		if ($chunk === FALSE || $chunk === '') break;
		$data .= $chunk;
	}
	fclose($fp);

	$status = 0;
	$location = '';
	if (isset($meta['wrapper_data']) && is_array($meta['wrapper_data'])) {
		foreach ($meta['wrapper_data'] as $header) {
			if (preg_match('#^HTTP/\S+\s+(\d+)#', $header, $m)) $status = (int) $m[1];
			if (preg_match('/^Location:\s*(\S+)/i', $header, $m)) $location = trim($m[1]);
		}
	}
	if (in_array($status, array(301, 302, 303, 307, 308), TRUE) && $location !== '' && $redirects > 0) {
		if (! preg_match('#^https?://#i', $location)) {
			$location = pkwk_ogp_resolve_url($location, $url);
		}
		if ($location === '') return FALSE;
		return pkwk_ogp_http_get($location, $max_bytes, $timeout, $redirects - 1);
	}
	if ($status !== 200 && $status !== 0) return FALSE;
	return $data;
}

function pkwk_ogp_cache_dir()
{
	$dir = CACHE_DIR . 'ogp/';
	if (! is_dir($dir)) @mkdir($dir, 0777, TRUE);
	return $dir;
}

function pkwk_ogp_cache_path($url)
{
	return pkwk_ogp_cache_dir() . md5($url) . '.json';
}

function pkwk_ogp_cache_get($url)
{
	$cfg = pkwk_ogp_config();
	if ($cfg['cache_hours'] <= 0) return FALSE;
	$path = pkwk_ogp_cache_path($url);
	if (! is_readable($path)) return FALSE;
	$raw = @file_get_contents($path);
	$data = json_decode($raw, TRUE);
	if (! is_array($data) || ! isset($data['time'], $data['meta'])) return FALSE;
	if (UTIME - (int) $data['time'] > (int) ($cfg['cache_hours'] * 3600)) {
		@unlink($path);
		return FALSE;
	}
	return $data['meta'];
}

function pkwk_ogp_cache_set($url, $meta)
{
	$cfg = pkwk_ogp_config();
	if ($cfg['cache_hours'] <= 0) return;
	$payload = json_encode(array('time' => UTIME, 'meta' => $meta));
	if ($payload === FALSE) return;
	@file_put_contents(pkwk_ogp_cache_path($url), $payload, LOCK_EX);
}

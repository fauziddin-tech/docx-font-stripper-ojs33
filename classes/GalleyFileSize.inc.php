<?php

/**
 * @file classes/GalleyFileSize.inc.php
 *
 * Distributed under the GNU GPL v3.
 *
 * @class GalleyFileSize
 * @brief Adds the file size to galley links in a rendered page, whatever the
 *  theme, e.g. "PDF" becomes "PDF (1,2 MB)".
 *
 * Works on the final HTML, so it does not depend on how a theme writes its
 * galley_link.tpl: every <a href> that points to an article or issue galley
 * of the current journal (op view or download) gets a size. Links that
 * already show a size (a theme that prints its own) are left alone.
 *
 * Pure PHP (no OJS); the plugin supplies the URL prefixes and a callback
 * that returns the size in bytes for a parsed link.
 */
class GalleyFileSize {

	/** Most links annotated on one page; protects against huge archive pages. */
	const MAX_LINKS = 500;

	/** @var array<string, string[]> page => [URL prefix of op view, URL prefix of op download] */
	private $_prefixes;

	/** @var callable(string $page, string[] $args): ?int */
	private $_sizeResolver;

	/** @var string */
	private $_decimalSeparator;

	/** @var array<string, ?int> */
	private $_cache = [];

	/**
	 * @param array<string, string[]> $prefixes e.g. ['article' => ['https://x/index.php/j/article/view', 'https://x/index.php/j/article/download'], 'issue' => [...]]
	 * @param callable $sizeResolver function(string $page, string[] $args): ?int  Size in bytes, or null to leave the link alone
	 * @param string $decimalSeparator
	 */
	public function __construct(array $prefixes, callable $sizeResolver, $decimalSeparator = '.') {
		$this->_prefixes = $prefixes;
		$this->_sizeResolver = $sizeResolver;
		$this->_decimalSeparator = $decimalSeparator;
	}

	/**
	 * Human-readable size: "820 B", "17 KB", "1,2 MB", "14 MB", "1,1 GB" (1 KB = 1024 B).
	 * One decimal below 10 in MB and GB, none otherwise.
	 *
	 * @param int $bytes
	 * @param string $decimalSeparator
	 * @return string
	 */
	public static function formatSize($bytes, $decimalSeparator = '.') {
		$bytes = max(0, (int) $bytes);
		if ($bytes < 1024) return $bytes . ' B';
		if (round($bytes / 1024) < 1024) return max(1, (int) round($bytes / 1024)) . ' KB';
		$units = ['MB' => 1024 ** 2, 'GB' => 1024 ** 3];
		$unit = $bytes < 1024 ** 3 ? 'MB' : 'GB';
		$value = $bytes / $units[$unit];
		if ($unit === 'MB' && round($value) >= 1024) return self::formatSize(1024 ** 3, $decimalSeparator);
		$text = $value < 9.95 ? number_format($value, 1, $decimalSeparator, '') : (string) (int) round($value);
		return $text . ' ' . $unit;
	}

	/**
	 * Parse a link and tell whether it points to a galley of this journal.
	 *
	 * @param string $href As written in the HTML attribute (entities allowed)
	 * @return array|null [page, args] with page "article" or "issue"
	 */
	public function parseGalleyHref($href) {
		$href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
		$href = preg_replace('/#.*$/s', '', $href);
		foreach ($this->_prefixes as $page => $prefixes) {
			foreach ($prefixes as $prefix) {
				$args = $this->_argsAfterPrefix($href, $prefix);
				if ($args === null || !$this->_looksLikeGalley($page, $args)) continue;
				return [$page, $args];
			}
		}
		return null;
	}

	/**
	 * Add a size to every galley link in the page.
	 *
	 * @param string $html
	 * @return string
	 */
	public function annotate($html) {
		if (!is_string($html) || stripos($html, '<a') === false) return $html;

		$count = 0;
		$pattern = '/<a\b(?<attrs>[^>]*?\bhref\s*=\s*(?<q>["\'])(?<href>.*?)\k<q>[^>]*)>(?<inner>.*?)<\/a\s*>/is';
		$result = preg_replace_callback($pattern, function ($raw) use (&$count, $html) {
			$m = self::_flatten($raw);
			$link = $m[0];
			if ($count >= self::MAX_LINKS) return $link;
			if (stripos($m['attrs'], 'dfs_no_size') !== false) return $link;
			$parsed = $this->parseGalleyHref($m['href']);
			if ($parsed === null) return $link;
			if (self::_showsSize($m['inner'])) return $link;

			// A theme may print the size right after the link instead of inside it.
			$after = (string) substr($html, $raw[0][1] + strlen($link), 300);
			$after = preg_split('/<a\b/i', $after, 2)[0];
			if (self::_showsSize($after)) return $link;

			$size = $this->_size($parsed[0], $parsed[1]);
			if ($size === null) return $link;
			$count++;

			$label = htmlspecialchars(self::formatSize($size, $this->_decimalSeparator), ENT_QUOTES, 'UTF-8');
			$closePos = strrpos($link, '</');
			return substr($link, 0, $closePos)
				. ' <span class="dfs_file_size">(' . $label . ')</span>'
				. substr($link, $closePos);
		}, $html, -1, $replaced, PREG_OFFSET_CAPTURE);
		return $result ?? $html;
	}

	/**
	 * preg_replace_callback with PREG_OFFSET_CAPTURE gives [text, offset] pairs.
	 * Smooth that over so the callback reads $m['href'] as a string.
	 */
	private static function _flatten(array $m) {
		$out = [];
		foreach ($m as $key => $value) $out[$key] = is_array($value) ? $value[0] : $value;
		return $out;
	}

	/**
	 * @param string $text
	 * @return bool
	 */
	private static function _showsSize($text) {
		$text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		return (bool) preg_match('/\d\s*(?:[KMG]i?B|bytes?|kilobytes?|megabytes?)\b/iu', $text);
	}

	/**
	 * @param string $page
	 * @param string[] $args
	 * @return int|null
	 */
	private function _size($page, array $args) {
		$key = $page . '|' . implode('/', $args);
		if (!array_key_exists($key, $this->_cache)) {
			$size = call_user_func($this->_sizeResolver, $page, $args);
			$this->_cache[$key] = is_int($size) && $size > 0 ? $size : null;
		}
		return $this->_cache[$key];
	}

	/**
	 * Path arguments after a URL prefix, for both URL styles OJS can generate:
	 * path info (".../article/view/12/34") and query
	 * ("...?journal=j&page=article&op=view&path[]=12&path[]=34").
	 *
	 * @param string $href
	 * @param string $prefix
	 * @return string[]|null
	 */
	private function _argsAfterPrefix($href, $prefix) {
		$href = self::_withoutScheme($href);
		$prefix = self::_withoutScheme($prefix);
		if ($prefix === '') return null;

		$queryPos = strpos($prefix, '?');
		if ($queryPos === false) {
			if (strncmp($href, $prefix . '/', strlen($prefix) + 1) !== 0) return null;
			$rest = (string) preg_replace('/\?.*$/s', '', substr($href, strlen($prefix) + 1));
			$args = array_map('rawurldecode', explode('/', rtrim($rest, '/')));
			return $args === [''] ? null : $args;
		}

		// Query style: same script, every query parameter of the prefix present.
		$hrefQueryPos = strpos($href, '?');
		if ($hrefQueryPos === false || substr($href, 0, $hrefQueryPos) !== substr($prefix, 0, $queryPos)) return null;
		parse_str(substr($prefix, $queryPos + 1), $want);
		parse_str(substr($href, $hrefQueryPos + 1), $have);
		foreach ($want as $name => $value) {
			if (!isset($have[$name]) || $have[$name] !== $value) return null;
		}
		$path = $have['path'] ?? null;
		if (!is_array($path) || !$path) return null;
		return array_values(array_map('strval', array_filter($path, 'is_scalar')));
	}

	/**
	 * Treat "https://host/x", "http://host/x" and "//host/x" alike.
	 * @param string $url
	 * @return string
	 */
	private static function _withoutScheme($url) {
		return (string) preg_replace('#^(?:https?:)?//#i', '//', $url);
	}

	/**
	 * Article: {submission}/{galley}[/{fileId}] or {submission}/version/{publicationId}/{galley}[/{fileId}].
	 * Issue: {issue}/{galley}[/{fileId}].
	 *
	 * @param string $page
	 * @param string[] $args
	 * @return bool
	 */
	private function _looksLikeGalley($page, array $args) {
		foreach ($args as $arg) {
			if ($arg === '' || strlen($arg) > 255) return false;
		}
		if ($page === 'article' && ($args[1] ?? null) === 'version') {
			return count($args) >= 4 && count($args) <= 5 && ctype_digit($args[2]);
		}
		return count($args) >= 2 && count($args) <= 3;
	}
}

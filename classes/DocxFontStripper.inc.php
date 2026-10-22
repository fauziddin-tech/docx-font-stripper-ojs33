<?php

/**
 * @file classes/DocxFontStripper.inc.php
 *
 * Distributed under the GNU GPL v3.
 *
 * @class DocxFontStripper
 * @brief Removes embedded fonts from a .docx (WordprocessingML) package.
 *
 * Pure PHP (ext-zip + ext-dom), no OJS dependency, so it can be unit tested
 * on its own. Only package plumbing is touched:
 *  - font parts (word/fonts/* and every target of a "font" relationship),
 *  - the font relationships themselves (e.g. word/_rels/fontTable.xml.rels),
 *  - w:embedRegular/Bold/Italic/BoldItalic in the font table(s),
 *  - Override/Default entries for the removed parts in [Content_Types].xml,
 *  - w:embedTrueTypeFonts and w:saveSubsetFonts in word/settings.xml.
 * Every other part keeps its exact content; parts that were stored without
 * compression are deflated while the package is rewritten anyway. The output
 * is verified before it is accepted, otherwise the original stays as it is.
 */

class DocxFontStripResult {
	const STRIPPED = 'stripped';   // fonts removed, output written and verified
	const UNCHANGED = 'unchanged'; // valid .docx without embedded fonts
	const SKIPPED = 'skipped';     // not a .docx (or cannot be handled here)
	const FAILED = 'failed';       // something went wrong; original kept

	/** @var string One of the constants above */
	public $status;

	/** @var string Human readable reason (for skipped/failed/unchanged) */
	public $reason;

	/** @var int */
	public $sizeBefore = 0;

	/** @var int */
	public $sizeAfter = 0;

	/** @var string[] Parts removed from the package */
	public $removedParts = [];

	/** @var string[] XML parts rewritten */
	public $modifiedParts = [];

	/** @var string[] Stored (uncompressed) parts that were deflated, content unchanged */
	public $recompressedParts = [];

	/**
	 * @param string $status
	 * @param string $reason
	 */
	public function __construct($status, $reason = '') {
		$this->status = $status;
		$this->reason = $reason;
	}

	/**
	 * @return bool
	 */
	public function isStripped() {
		return $this->status === self::STRIPPED;
	}

	/**
	 * One line summary, suitable for error_log().
	 * @return string
	 */
	public function summary() {
		if ($this->status === self::STRIPPED) {
			$saved = $this->sizeBefore > 0 ? (1 - $this->sizeAfter / $this->sizeBefore) * 100 : 0;
			return sprintf(
				'stripped: %s -> %s bytes (-%.1f%%), %d part(s) removed, %d XML part(s) updated, %d part(s) recompressed',
				number_format($this->sizeBefore), number_format($this->sizeAfter), $saved,
				count($this->removedParts), count($this->modifiedParts), count($this->recompressedParts)
			);
		}
		return sprintf('%s: %s (%s bytes)', $this->status, $this->reason, number_format($this->sizeBefore));
	}
}

class DocxFontStripper {
	const DOCX_MAIN_CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml';
	const MAIN_PART = 'word/document.xml';
	const FONT_TABLE_PART = 'word/fontTable.xml';
	const SETTINGS_PART = 'word/settings.xml';
	const CONTENT_TYPES_PART = '[Content_Types].xml';
	const FONTS_DIR = 'word/fonts/';

	const NS_RELATIONSHIPS = 'http://schemas.openxmlformats.org/package/2006/relationships';
	const NS_CONTENT_TYPES = 'http://schemas.openxmlformats.org/package/2006/content-types';

	/** Upper bound for an XML part we load into DOM (zip bomb guard). */
	const MAX_XML_PART_BYTES = 16777216;

	/** @var string[] WordprocessingML namespaces (transitional, strict) */
	private static $wordNamespaces = [
		'http://schemas.openxmlformats.org/wordprocessingml/2006/main',
		'http://purl.oclc.org/ooxml/wordprocessingml/main',
	];

	/** @var string[] Relationship types pointing at an embedded font (transitional, strict) */
	private static $fontRelationshipTypes = [
		'http://schemas.openxmlformats.org/officeDocument/2006/relationships/font',
		'http://purl.oclc.org/ooxml/officeDocument/relationships/font',
	];

	/** @var string[] */
	private static $fontEmbedElements = ['embedRegular', 'embedBold', 'embedItalic', 'embedBoldItalic'];

	/** @var string[] */
	private static $settingsElements = ['embedTrueTypeFonts', 'saveSubsetFonts'];

	/** @var string[] Extensions not worth deflating when found stored */
	private static $precompressedExtensions = [
		'jpg', 'jpeg', 'png', 'gif', 'webp', 'jdp', 'wdp', 'mp3', 'mp4', 'm4a', 'mov', 'avi', 'wmv',
		'zip', 'gz', 'docx', 'xlsx', 'pptx', 'docm', 'xlsm', 'pptm',
	];

	/**
	 * Strip embedded fonts from $sourcePath and write the result to $destinationPath.
	 *
	 * $sourcePath is never modified. $destinationPath only exists afterwards
	 * when the result is STRIPPED.
	 *
	 * @param string $sourcePath
	 * @param string $destinationPath
	 * @return DocxFontStripResult
	 */
	public function strip($sourcePath, $destinationPath) {
		if (!class_exists('ZipArchive') || !class_exists('DOMDocument')) {
			return new DocxFontStripResult(DocxFontStripResult::SKIPPED, 'PHP zip/dom extension not available');
		}
		if (!is_file($sourcePath) || !is_readable($sourcePath)) {
			return new DocxFontStripResult(DocxFontStripResult::SKIPPED, 'source file not readable');
		}
		if (file_exists($destinationPath) && realpath($destinationPath) === realpath($sourcePath)) {
			throw new InvalidArgumentException('Destination must differ from source; use stripInPlace().');
		}

		clearstatcache(true, $sourcePath);
		$sizeBefore = (int) filesize($sourcePath);

		try {
			$plan = $this->_buildPlan($sourcePath);
		} catch (Throwable $e) {
			$result = new DocxFontStripResult(DocxFontStripResult::FAILED, $e->getMessage());
			$result->sizeBefore = $sizeBefore;
			return $result;
		}
		if ($plan instanceof DocxFontStripResult) {
			$plan->sizeBefore = $sizeBefore;
			return $plan;
		}

		try {
			$this->_write($sourcePath, $destinationPath, $plan);
			$this->_validate($sourcePath, $destinationPath, $plan);
		} catch (Throwable $e) {
			if (file_exists($destinationPath)) @unlink($destinationPath);
			$result = new DocxFontStripResult(DocxFontStripResult::FAILED, $e->getMessage());
			$result->sizeBefore = $sizeBefore;
			return $result;
		}

		clearstatcache(true, $destinationPath);
		$result = new DocxFontStripResult(DocxFontStripResult::STRIPPED);
		$result->sizeBefore = $sizeBefore;
		$result->sizeAfter = (int) filesize($destinationPath);
		$result->removedParts = array_values($plan['remove']);
		$result->modifiedParts = array_keys($plan['xml']);
		$result->recompressedParts = $plan['recompressed'];
		return $result;
	}

	/**
	 * Strip embedded fonts and atomically replace $path with the cleaned file.
	 * The temporary file lives next to $path so the final rename() is atomic;
	 * the original permissions are kept. On anything but STRIPPED, $path is
	 * left untouched.
	 *
	 * @param string $path
	 * @return DocxFontStripResult
	 */
	public function stripInPlace($path) {
		$dir = dirname($path);
		if (!is_writable($dir)) {
			$result = new DocxFontStripResult(DocxFontStripResult::FAILED, 'directory not writable: ' . $dir);
			$result->sizeBefore = is_file($path) ? (int) filesize($path) : 0;
			return $result;
		}
		$tmpPath = $path . '.dfs-' . bin2hex(random_bytes(6)) . '.tmp';
		try {
			$result = $this->strip($path, $tmpPath);
			if ($result->isStripped()) {
				$perms = @fileperms($path);
				if ($perms !== false) @chmod($tmpPath, $perms & 0777);
				if (!@rename($tmpPath, $path)) {
					$failed = new DocxFontStripResult(DocxFontStripResult::FAILED, 'could not replace original file');
					$failed->sizeBefore = $result->sizeBefore;
					return $failed;
				}
			}
			return $result;
		} finally {
			if (file_exists($tmpPath)) @unlink($tmpPath);
		}
	}

	/**
	 * Inspect the package and decide what to change.
	 *
	 * @param string $sourcePath
	 * @return array|DocxFontStripResult Plan, or a SKIPPED/UNCHANGED result
	 * @throws RuntimeException when the package has fonts but cannot be processed safely
	 */
	private function _buildPlan($sourcePath) {
		$handle = @fopen($sourcePath, 'rb');
		$signature = $handle ? fread($handle, 4) : '';
		if ($handle) fclose($handle);
		if ($signature !== "PK\x03\x04") {
			return new DocxFontStripResult(DocxFontStripResult::SKIPPED, 'not a ZIP/OOXML package');
		}

		$zip = new ZipArchive();
		if ($zip->open($sourcePath) !== true) {
			return new DocxFontStripResult(DocxFontStripResult::SKIPPED, 'cannot open as ZIP');
		}

		try {
			$entries = $this->_listEntries($zip);
			if (!isset($entries[strtolower(self::MAIN_PART)])) {
				return new DocxFontStripResult(DocxFontStripResult::SKIPPED, 'no ' . self::MAIN_PART);
			}
			if (!isset($entries[strtolower(self::CONTENT_TYPES_PART)])) {
				return new DocxFontStripResult(DocxFontStripResult::SKIPPED, 'no ' . self::CONTENT_TYPES_PART);
			}
			try {
				$contentTypes = $this->_loadXmlPart($zip, $entries[strtolower(self::CONTENT_TYPES_PART)]);
			} catch (RuntimeException $e) {
				return new DocxFontStripResult(DocxFontStripResult::SKIPPED, $e->getMessage());
			}
			$mainType = $this->_overrideContentType($contentTypes, self::MAIN_PART);
			if ($mainType !== self::DOCX_MAIN_CONTENT_TYPE) {
				return new DocxFontStripResult(DocxFontStripResult::SKIPPED, 'not a .docx (main part type: ' . ($mainType === null ? 'none' : $mainType) . ')');
			}

			$remove = []; // lower-case name => actual name
			$xml = [];    // actual name => DOMDocument (modified)

			// 1. Everything under word/fonts/
			foreach ($entries as $lower => $name) {
				if (strpos($lower, self::FONTS_DIR) === 0) $remove[$lower] = $name;
			}

			// 2. Font relationships in any .rels part, and their targets
			$embedHosts = [];
			if (isset($entries[strtolower(self::FONT_TABLE_PART)])) {
				$embedHosts[strtolower(self::FONT_TABLE_PART)] = $entries[strtolower(self::FONT_TABLE_PART)];
			}
			foreach ($entries as $lower => $name) {
				if (substr($lower, -5) !== '.rels') continue;
				$raw = $this->_readPart($zip, $name);
				if (stripos($raw, 'relationships/font"') === false) continue; // cheap pre-filter
				$doc = $this->_parseXml($raw, $name);
				$removedRels = 0;
				foreach ($this->_elements($doc, [self::NS_RELATIONSHIPS], ['Relationship']) as $rel) {
					if (!in_array($rel->getAttribute('Type'), self::$fontRelationshipTypes, true)) continue;
					if (strcasecmp($rel->getAttribute('TargetMode'), 'External') !== 0) {
						$target = strtolower($this->_resolveTarget($name, $rel->getAttribute('Target')));
						if (isset($entries[$target])) $remove[$target] = $entries[$target];
					}
					$rel->parentNode->removeChild($rel);
					$removedRels++;
				}
				if (!$removedRels) continue;

				if (!count($this->_elements($doc, [self::NS_RELATIONSHIPS], ['Relationship']))) {
					$remove[$lower] = $name; // nothing left: drop the empty .rels part
				} else {
					$xml[$name] = $doc;
				}
				$host = $this->_sourcePartOfRels($name);
				if ($host !== null && isset($entries[strtolower($host)])) {
					$embedHosts[strtolower($host)] = $entries[strtolower($host)];
				}
			}

			// 3. w:embed* references in the font table(s)
			foreach ($embedHosts as $lower => $name) {
				if (isset($remove[$lower])) continue;
				$doc = $this->_loadXmlPart($zip, $name);
				$embeds = $this->_elements($doc, self::$wordNamespaces, self::$fontEmbedElements);
				foreach ($embeds as $node) $node->parentNode->removeChild($node);
				if ($embeds) $xml[$name] = $doc;
			}

			if (!$remove && !$xml) {
				return new DocxFontStripResult(DocxFontStripResult::UNCHANGED, 'no embedded fonts');
			}

			// 4. Do not embed again on the next save
			$settingsLower = strtolower(self::SETTINGS_PART);
			if (isset($entries[$settingsLower])) {
				$doc = $this->_loadXmlPart($zip, $entries[$settingsLower]);
				$flags = $this->_elements($doc, self::$wordNamespaces, self::$settingsElements);
				foreach ($flags as $node) $node->parentNode->removeChild($node);
				if ($flags) $xml[$entries[$settingsLower]] = $doc;
			}

			// 5. [Content_Types].xml: overrides for removed parts, defaults no longer used
			if ($this->_cleanContentTypes($contentTypes, $entries, $remove)) {
				$xml[$entries[strtolower(self::CONTENT_TYPES_PART)]] = $contentTypes;
			}

			return ['entries' => $entries, 'remove' => $remove, 'xml' => $xml];
		} finally {
			$zip->close();
		}
	}

	/**
	 * @param DOMDocument $contentTypes
	 * @param array $entries lower => actual
	 * @param array $remove lower => actual
	 * @return bool Whether anything was removed
	 */
	private function _cleanContentTypes($contentTypes, $entries, $remove) {
		$changed = false;
		foreach ($this->_elements($contentTypes, [self::NS_CONTENT_TYPES], ['Override']) as $override) {
			$part = strtolower(ltrim(rawurldecode($override->getAttribute('PartName')), '/'));
			if (isset($remove[$part])) {
				$override->parentNode->removeChild($override);
				$changed = true;
			}
		}

		$removedExtensions = [];
		$remainingExtensions = [];
		foreach ($entries as $lower => $name) {
			if (substr($lower, -1) === '/') continue;
			$extension = strtolower(pathinfo($lower, PATHINFO_EXTENSION));
			if (isset($remove[$lower])) {
				$removedExtensions[$extension] = true;
			} else {
				$remainingExtensions[$extension] = true;
			}
		}
		foreach ($this->_elements($contentTypes, [self::NS_CONTENT_TYPES], ['Default']) as $default) {
			$extension = strtolower($default->getAttribute('Extension'));
			if (isset($removedExtensions[$extension]) && !isset($remainingExtensions[$extension])) {
				$default->parentNode->removeChild($default);
				$changed = true;
			}
		}
		return $changed;
	}

	/**
	 * Copy the source and apply the plan with ZipArchive. Untouched entries are
	 * copied by libzip as they are, except stored ones, which get deflated.
	 *
	 * @param string $sourcePath
	 * @param string $destinationPath
	 * @param array $plan Receives the list of recompressed parts
	 */
	private function _write($sourcePath, $destinationPath, &$plan) {
		if (!@copy($sourcePath, $destinationPath)) {
			throw new RuntimeException('cannot write temporary file');
		}
		$zip = new ZipArchive();
		if ($zip->open($destinationPath) !== true) {
			throw new RuntimeException('cannot reopen temporary file');
		}
		foreach ($plan['remove'] as $name) {
			if (!$zip->deleteName($name)) {
				$zip->close();
				throw new RuntimeException('cannot remove ' . $name);
			}
		}
		$plan['recompressed'] = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$stat = $zip->statIndex($i);
			if (!$stat || $stat['comp_method'] !== ZipArchive::CM_STORE || $stat['size'] === 0) continue;
			if (isset($plan['xml'][$stat['name']])) continue;
			if (in_array(strtolower(pathinfo($stat['name'], PATHINFO_EXTENSION)), self::$precompressedExtensions, true)) continue;
			if ($zip->setCompressionIndex($i, ZipArchive::CM_DEFLATE)) $plan['recompressed'][] = $stat['name'];
		}
		foreach ($plan['xml'] as $name => $doc) {
			$content = $doc->saveXML();
			if ($content === false || !$zip->addFromString($name, $content)) {
				$zip->close();
				throw new RuntimeException('cannot update ' . $name);
			}
			$zip->setCompressionName($name, ZipArchive::CM_DEFLATE);
		}
		if (!$zip->close()) {
			throw new RuntimeException('cannot save ZIP: ' . $zip->getStatusString());
		}
	}

	/**
	 * Re-open the output and make sure it is a sound .docx before accepting it.
	 *
	 * @param string $sourcePath
	 * @param string $destinationPath
	 * @param array $plan
	 */
	private function _validate($sourcePath, $destinationPath, $plan) {
		$out = new ZipArchive();
		if ($out->open($destinationPath, ZipArchive::CHECKCONS) !== true) {
			throw new RuntimeException('validation: output is not a consistent ZIP');
		}
		$source = new ZipArchive();
		if ($source->open($sourcePath) !== true) {
			$out->close();
			throw new RuntimeException('validation: cannot reopen source');
		}

		try {
			$outEntries = $this->_listEntries($out);
			foreach ([self::MAIN_PART, self::CONTENT_TYPES_PART] as $required) {
				if (!isset($outEntries[strtolower($required)])) {
					throw new RuntimeException('validation: ' . $required . ' missing');
				}
			}
			if (count($outEntries) !== count($plan['entries']) - count($plan['remove'])) {
				throw new RuntimeException('validation: unexpected number of entries');
			}

			$modified = array_change_key_case(array_flip(array_keys($plan['xml'])), CASE_LOWER);
			foreach ($plan['entries'] as $lower => $name) {
				$isRemoved = isset($plan['remove'][$lower]);
				if ($isRemoved === isset($outEntries[$lower])) {
					throw new RuntimeException('validation: ' . $name . ($isRemoved ? ' still present' : ' missing'));
				}
				if ($isRemoved || isset($modified[$lower])) continue;
				// Unmodified parts must be byte identical to the source
				$a = $source->statName($name);
				$b = $out->statName($outEntries[$lower]);
				if ($a === false || $b === false || $a['size'] !== $b['size'] || $a['crc'] !== $b['crc']) {
					throw new RuntimeException('validation: ' . $name . ' changed unexpectedly');
				}
			}

			// Data of every entry must decompress and match its CRC
			for ($i = 0; $i < $out->numFiles; $i++) {
				$stat = $out->statIndex($i);
				if (substr($stat['name'], -1) === '/') continue;
				if ($this->_crc32OfEntry($out, $stat['name']) !== ($stat['crc'] & 0xFFFFFFFF)) {
					throw new RuntimeException('validation: CRC mismatch in ' . $stat['name']);
				}
			}

			// Rewritten XML must parse, and must not reference fonts any more
			foreach (array_keys($plan['xml']) as $name) {
				$doc = $this->_loadXmlPart($out, $name);
				if ($this->_elements($doc, self::$wordNamespaces, array_merge(self::$fontEmbedElements, self::$settingsElements))) {
					throw new RuntimeException('validation: font references left in ' . $name);
				}
			}

			// No remaining relationship may point at a removed part
			foreach ($outEntries as $lower => $name) {
				if (substr($lower, -5) !== '.rels') continue;
				$raw = $this->_readPart($out, $name);
				$hit = false;
				foreach ($plan['remove'] as $removedName) {
					if (stripos($raw, basename($removedName)) !== false) { $hit = true; break; }
				}
				if (!$hit) continue;
				$doc = $this->_parseXml($raw, $name);
				foreach ($this->_elements($doc, [self::NS_RELATIONSHIPS], ['Relationship']) as $rel) {
					if (strcasecmp($rel->getAttribute('TargetMode'), 'External') === 0) continue;
					if (isset($plan['remove'][strtolower($this->_resolveTarget($name, $rel->getAttribute('Target')))])) {
						throw new RuntimeException('validation: ' . $name . ' still references ' . $rel->getAttribute('Target'));
					}
				}
			}
		} finally {
			$out->close();
			$source->close();
		}
	}

	/**
	 * @param ZipArchive $zip
	 * @param string $name
	 * @return int
	 */
	private function _crc32OfEntry($zip, $name) {
		$stream = $zip->getStream($name);
		if (!$stream) throw new RuntimeException('validation: cannot read ' . $name);
		$ctx = hash_init('crc32b');
		while (!feof($stream)) {
			$chunk = fread($stream, 1048576);
			if ($chunk === false) break;
			hash_update($ctx, $chunk);
		}
		fclose($stream);
		return hexdec(hash_final($ctx));
	}

	/**
	 * @param ZipArchive $zip
	 * @return array lower-case name => actual name
	 */
	private function _listEntries($zip) {
		$entries = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = $zip->getNameIndex($i);
			if ($name === false) throw new RuntimeException('unreadable ZIP entry #' . $i);
			$entries[strtolower($name)] = $name;
		}
		return $entries;
	}

	/**
	 * @param ZipArchive $zip
	 * @param string $name
	 * @return string
	 */
	private function _readPart($zip, $name) {
		$stat = $zip->statName($name);
		if ($stat === false) throw new RuntimeException('missing part ' . $name);
		if ($stat['size'] > self::MAX_XML_PART_BYTES) throw new RuntimeException('part too large: ' . $name);
		$content = $zip->getFromName($name);
		if ($content === false) throw new RuntimeException('cannot read ' . $name);
		return $content;
	}

	/**
	 * @param ZipArchive $zip
	 * @param string $name
	 * @return DOMDocument
	 */
	private function _loadXmlPart($zip, $name) {
		return $this->_parseXml($this->_readPart($zip, $name), $name);
	}

	/**
	 * @param string $content
	 * @param string $name For error messages
	 * @return DOMDocument
	 */
	private function _parseXml($content, $name) {
		if (trim($content) === '') throw new RuntimeException('empty XML in ' . $name);
		$doc = new DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$ok = $doc->loadXML($content, LIBXML_NONET);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		if (!$ok || !$doc->documentElement) throw new RuntimeException('invalid XML in ' . $name);
		if ($doc->doctype) throw new RuntimeException('DTD not allowed in ' . $name);
		return $doc;
	}

	/**
	 * Elements with one of the given local names in one of the given namespaces.
	 *
	 * @param DOMDocument $doc
	 * @param string[] $namespaces
	 * @param string[] $localNames
	 * @return DOMElement[]
	 */
	private function _elements($doc, $namespaces, $localNames) {
		$found = [];
		foreach ($namespaces as $ns) {
			foreach ($localNames as $localName) {
				foreach ($doc->getElementsByTagNameNS($ns, $localName) as $node) $found[] = $node;
			}
		}
		return $found;
	}

	/**
	 * @param DOMDocument $contentTypes
	 * @param string $partName without leading slash
	 * @return string|null
	 */
	private function _overrideContentType($contentTypes, $partName) {
		foreach ($this->_elements($contentTypes, [self::NS_CONTENT_TYPES], ['Override']) as $override) {
			if (strcasecmp(ltrim(rawurldecode($override->getAttribute('PartName')), '/'), $partName) === 0) {
				return $override->getAttribute('ContentType');
			}
		}
		return null;
	}

	/**
	 * "word/_rels/fontTable.xml.rels" => "word/fontTable.xml"; "_rels/.rels" => "" (package).
	 *
	 * @param string $relsName
	 * @return string|null
	 */
	private function _sourcePartOfRels($relsName) {
		if (!preg_match('#^(.*?)_rels/([^/]*)\.rels$#i', $relsName, $m)) return null;
		return $m[1] . $m[2];
	}

	/**
	 * Resolve a relationship target to a package part name (no leading slash).
	 *
	 * @param string $relsName The .rels part containing the relationship
	 * @param string $target
	 * @return string
	 */
	private function _resolveTarget($relsName, $target) {
		$target = rawurldecode(str_replace('\\', '/', $target));
		if (substr($target, 0, 1) === '/') {
			$path = substr($target, 1);
		} else {
			$base = preg_match('#^(.*?)_rels/[^/]*$#i', $relsName, $m) ? $m[1] : '';
			$path = $base . $target;
		}
		$segments = [];
		foreach (explode('/', $path) as $segment) {
			if ($segment === '' || $segment === '.') continue;
			if ($segment === '..') {
				array_pop($segments);
				continue;
			}
			$segments[] = $segment;
		}
		return implode('/', $segments);
	}
}

<?php

/**
 * @file classes/DocxFontStripper.inc.php
 *
 * Distributed under the GNU GPL v3.
 *
 * @class DocxFontStripper
 * @brief Removes embedded fonts from a .docx (WordprocessingML) package and,
 * optionally, downscales PNG images stored far above their displayed size.
 *
 * Pure PHP (ext-zip + ext-dom, ext-gd for images), no OJS dependency, so it
 * can be unit tested on its own. Only package plumbing is touched:
 *  - font parts (word/fonts/* and every target of a "font" relationship),
 *  - the font relationships themselves (e.g. word/_rels/fontTable.xml.rels),
 *  - w:embedRegular/Bold/Italic/BoldItalic in the font table(s),
 *  - Override/Default entries for the removed parts in [Content_Types].xml,
 *  - w:embedTrueTypeFonts and w:saveSubsetFonts in word/settings.xml.
 * With 'downscaleImages', a PNG shown only in plain pictures (wp:inline or
 * wp:anchor) is resampled to 'imageTargetDpi' at its largest displayed size,
 * keeping its part name and format. Every other part keeps its exact content; parts that were stored without
 * compression are deflated while the package is rewritten anyway. The output
 * is verified before it is accepted, otherwise the original stays as it is.
 */

class DocxFontStripResult {
	const STRIPPED = 'stripped';   // fonts removed and/or images downscaled, output verified
	const UNCHANGED = 'unchanged'; // valid .docx, nothing to clean
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

	/** @var string[] PNG parts downscaled, as "name WxH (bytes) -> wxh (bytes)" */
	public $resizedImages = [];

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
				'cleaned: %s -> %s bytes (-%.1f%%), %d part(s) removed, %d XML part(s) updated, %d part(s) recompressed, %d image(s) downscaled%s',
				number_format($this->sizeBefore), number_format($this->sizeAfter), $saved,
				count($this->removedParts), count($this->modifiedParts), count($this->recompressedParts),
				count($this->resizedImages), $this->resizedImages ? ' [' . implode('; ', $this->resizedImages) . ']' : ''
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

	/** PNGs smaller than this are not worth resampling. */
	const MIN_IMAGE_BYTES = 65536;

	/** Upper bound for a PNG we decode. */
	const MAX_IMAGE_BYTES = 52428800;

	/** Only downscale when the new size is at most this fraction of the old (per axis). */
	const MAX_IMAGE_SCALE = 0.7;

	/** EMU per inch (DrawingML units). */
	const EMU_PER_INCH = 914400;

	/** @var string[] Relationship id attribute namespaces (transitional, strict) */
	private static $relationshipIdNamespaces = [
		'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
		'http://purl.oclc.org/ooxml/officeDocument/relationships',
	];

	/** @var string[] Image relationship types (transitional, strict) */
	private static $imageRelationshipTypes = [
		'http://schemas.openxmlformats.org/officeDocument/2006/relationships/image',
		'http://purl.oclc.org/ooxml/officeDocument/relationships/image',
	];

	/** @var string[] */
	private static $drawingNamespaces = [
		'http://schemas.openxmlformats.org/drawingml/2006/main',
		'http://purl.oclc.org/ooxml/drawingml/main',
	];

	/** @var string[] */
	private static $pictureNamespaces = [
		'http://schemas.openxmlformats.org/drawingml/2006/picture',
		'http://purl.oclc.org/ooxml/drawingml/picture',
	];

	/** @var string[] */
	private static $wordDrawingNamespaces = [
		'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing',
		'http://purl.oclc.org/ooxml/drawingml/wordprocessingDrawing',
	];

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

	/** @var array */
	private $_options;

	/**
	 * @param array $options
	 *  - downscaleImages (bool, default false): resample oversized PNG images
	 *  - imageTargetDpi (int, default 500): resolution kept at the displayed size;
	 *    500 dpi is the usual publisher requirement for figures mixing text and graphics
	 */
	public function __construct(array $options = []) {
		$this->_options = array_merge(['downscaleImages' => false, 'imageTargetDpi' => 500], $options);
	}

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
		foreach ($plan['images'] as $name => $image) {
			$result->resizedImages[] = sprintf(
				'%s %dx%d (%s) -> %dx%d (%s)', $name,
				$image['from'][0], $image['from'][1], number_format($image['from'][2]),
				$image['to'][0], $image['to'][1], number_format($image['to'][2])
			);
		}
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

			$hasFonts = $remove || $xml;
			if ($hasFonts) {
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
			}

			// 6. Optional: oversized PNG images. A problem here only means no image is touched.
			$images = [];
			if ($this->_options['downscaleImages']) {
				try {
					$images = $this->_planImages($zip, $entries, $remove);
				} catch (Throwable $e) {
					$images = [];
				}
			}

			if (!$hasFonts && !$images) {
				return new DocxFontStripResult(
					DocxFontStripResult::UNCHANGED,
					$this->_options['downscaleImages'] ? 'no embedded fonts or oversized images' : 'no embedded fonts'
				);
			}

			return ['entries' => $entries, 'remove' => $remove, 'xml' => $xml, 'images' => $images];
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
			if (isset($plan['xml'][$stat['name']]) || isset($plan['images'][$stat['name']])) continue;
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
		foreach ($plan['images'] as $name => $image) {
			if (!$zip->addFromString($name, $image['data'])) {
				$zip->close();
				throw new RuntimeException('cannot update ' . $name);
			}
			$zip->setCompressionName($name, ZipArchive::CM_STORE);
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

			$modified = array_change_key_case(array_flip(array_merge(array_keys($plan['xml']), array_keys($plan['images']))), CASE_LOWER);
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

			// Resampled images must be PNGs of the planned size
			foreach ($plan['images'] as $name => $image) {
				$data = $out->getFromName($name);
				$info = $data === false ? false : @getimagesizefromstring($data);
				if (!$info || $info[2] !== IMAGETYPE_PNG || $info[0] !== $image['to'][0] || $info[1] !== $image['to'][1]) {
					throw new RuntimeException('validation: resized image ' . $name . ' is not valid');
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
	 * Find PNG parts that are only used as plain pictures and are stored at a
	 * much higher resolution than needed for their displayed size.
	 *
	 * @param ZipArchive $zip
	 * @param array $entries lower => actual
	 * @param array $remove lower => actual
	 * @return array actual name => ['data' => string, 'from' => [w, h, bytes], 'to' => [w, h, bytes]]
	 */
	private function _planImages($zip, $entries, $remove) {
		if (!function_exists('imagecreatefromstring') || !function_exists('imagepng')) return [];

		$pngs = [];
		foreach ($entries as $lower => $name) {
			if (substr($lower, -4) === '.png' && !isset($remove[$lower])) $pngs[$lower] = $name;
		}
		if (!$pngs) return [];

		// Who refers to each PNG? Anything but an image relationship from an XML part blocks it.
		$usages = [];
		$blocked = [];
		foreach ($entries as $lower => $relsName) {
			if (substr($lower, -5) !== '.rels' || isset($remove[$lower])) continue;
			$raw = $this->_readPart($zip, $relsName);
			if (stripos($raw, '.png') === false) continue;
			$doc = $this->_parseXml($raw, $relsName);
			$host = $this->_sourcePartOfRels($relsName);
			foreach ($this->_elements($doc, [self::NS_RELATIONSHIPS], ['Relationship']) as $rel) {
				if (strcasecmp($rel->getAttribute('TargetMode'), 'External') === 0) continue;
				$target = strtolower($this->_resolveTarget($relsName, $rel->getAttribute('Target')));
				if (!isset($pngs[$target])) continue;
				$hostLower = $host === null ? '' : strtolower($host);
				if (!in_array($rel->getAttribute('Type'), self::$imageRelationshipTypes, true)
					|| substr($hostLower, -4) !== '.xml' || !isset($entries[$hostLower])) {
					$blocked[$target] = true;
					continue;
				}
				$usages[$target][] = [$entries[$hostLower], $rel->getAttribute('Id')];
			}
		}

		$planned = [];
		$hostDocs = [];
		foreach ($usages as $pngLower => $uses) {
			if (isset($blocked[$pngLower])) continue;
			$needed = $this->_requiredPixels($zip, $uses, $hostDocs);
			if ($needed === null) continue;
			$image = $this->_downscalePng($zip, $pngs[$pngLower], $needed);
			if ($image !== null) $planned[$pngs[$pngLower]] = $image;
		}
		return $planned;
	}

	/**
	 * Pixels needed for the largest displayed size of an image, or null when
	 * any use of it is not a plain picture whose size we can read.
	 *
	 * @param ZipArchive $zip
	 * @param array $uses [[host part name, relationship id], ...]
	 * @param array $hostDocs Cache of parsed host parts
	 * @return float[]|null [width, height]
	 */
	private function _requiredPixels($zip, $uses, &$hostDocs) {
		$dpi = (float) $this->_options['imageTargetDpi'];
		$needed = [0.0, 0.0];
		foreach ($uses as $use) {
			list($host, $rId) = $use;
			if ($rId === '' || strpbrk($rId, '"\'') !== false) return null;
			if (!isset($hostDocs[$host])) $hostDocs[$host] = new DOMXPath($this->_loadXmlPart($zip, $host));
			$xpath = $hostDocs[$host];

			$found = 0;
			foreach (self::$relationshipIdNamespaces as $ns) {
				foreach ($xpath->query('//@*[namespace-uri()="' . $ns . '" and .="' . $rId . '"]') as $attr) {
					$size = $this->_pictureSize($attr);
					if ($size === null) return null;
					$needed[0] = max($needed[0], $size[0] * $dpi);
					$needed[1] = max($needed[1], $size[1] * $dpi);
					$found++;
				}
			}
			if (!$found) return null;
		}
		return $needed;
	}

	/**
	 * For an r:embed on a:blip inside wp:inline|wp:anchor/a:graphic/a:graphicData/pic:pic/pic:blipFill,
	 * return the displayed size in inches of the whole (uncropped) image.
	 *
	 * @param DOMAttr $attr
	 * @return float[]|null [width, height]
	 */
	private function _pictureSize($attr) {
		$blip = $attr->ownerElement;
		if ($attr->localName !== 'embed' || !$this->_is($blip, self::$drawingNamespaces, 'blip')) return null;
		$blipFill = $blip->parentNode;
		$pic = $blipFill ? $blipFill->parentNode : null;
		$graphicData = $pic ? $pic->parentNode : null;
		$graphic = $graphicData ? $graphicData->parentNode : null;
		$frame = $graphic ? $graphic->parentNode : null;
		if (!$this->_is($blipFill, self::$pictureNamespaces, 'blipFill')
			|| !$this->_is($pic, self::$pictureNamespaces, 'pic')
			|| !$this->_is($graphicData, self::$drawingNamespaces, 'graphicData')
			|| !$this->_is($graphic, self::$drawingNamespaces, 'graphic')
			|| !($this->_is($frame, self::$wordDrawingNamespaces, 'inline') || $this->_is($frame, self::$wordDrawingNamespaces, 'anchor'))) {
			return null;
		}

		$extent = null;
		$srcRect = null;
		foreach ($frame->childNodes as $child) {
			if ($this->_is($child, self::$wordDrawingNamespaces, 'extent')) $extent = $child;
		}
		foreach ($blipFill->childNodes as $child) {
			if ($this->_is($child, self::$drawingNamespaces, 'srcRect')) $srcRect = $child;
		}
		if (!$extent) return null;
		$cx = (int) $extent->getAttribute('cx');
		$cy = (int) $extent->getAttribute('cy');
		if ($cx <= 0 || $cy <= 0) return null;

		// A cropped picture shows only part of the image at the extent size
		$visibleX = 1.0;
		$visibleY = 1.0;
		if ($srcRect) {
			$visibleX = 1 - ((int) $srcRect->getAttribute('l') + (int) $srcRect->getAttribute('r')) / 100000;
			$visibleY = 1 - ((int) $srcRect->getAttribute('t') + (int) $srcRect->getAttribute('b')) / 100000;
		}
		if ($visibleX < 0.05 || $visibleY < 0.05) return null;

		return [$cx / self::EMU_PER_INCH / $visibleX, $cy / self::EMU_PER_INCH / $visibleY];
	}

	/**
	 * @param DOMNode|null $node
	 * @param string[] $namespaces
	 * @param string $localName
	 * @return bool
	 */
	private function _is($node, $namespaces, $localName) {
		return $node instanceof DOMElement && $node->localName === $localName && in_array($node->namespaceURI, $namespaces, true);
	}

	/**
	 * Resample a PNG down to the needed pixel size (aspect ratio kept).
	 *
	 * @param ZipArchive $zip
	 * @param string $name
	 * @param float[] $needed [width, height] in pixels
	 * @return array|null
	 */
	private function _downscalePng($zip, $name, $needed) {
		$stat = $zip->statName($name);
		if ($stat === false || $stat['size'] < self::MIN_IMAGE_BYTES || $stat['size'] > self::MAX_IMAGE_BYTES) return null;
		$data = $zip->getFromName($name);
		if ($data === false) return null;
		$info = @getimagesizefromstring($data);
		if (!$info || $info[2] !== IMAGETYPE_PNG || $info[0] < 1 || $info[1] < 1) return null;
		list($width, $height) = $info;

		$scale = max($needed[0] / $width, $needed[1] / $height);
		if ($scale > self::MAX_IMAGE_SCALE) return null;
		$newWidth = max(1, (int) ceil($width * $scale));
		$newHeight = max(1, (int) ceil($height * $scale));
		if (!$this->_hasMemoryFor(($width * $height + $newWidth * $newHeight) * 6 + strlen($data) * 2)) return null;

		$source = @imagecreatefromstring($data);
		if (!$source) return null;
		$target = imagecreatetruecolor($newWidth, $newHeight);
		imagealphablending($target, false);
		imagesavealpha($target, true);
		imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
		$ok = imagecopyresampled($target, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
		unset($source);
		if (!$ok) return null;
		// Fully opaque: drop the alpha channel, RGB is smaller than RGBA
		if ($this->_isOpaque($target, $newWidth, $newHeight)) imagesavealpha($target, false);

		ob_start();
		$ok = imagepng($target, null, 9, PNG_ALL_FILTERS);
		$png = ob_get_clean();
		unset($target);
		if (!$ok || !is_string($png) || $png === '' || strlen($png) > 0.8 * strlen($data)) return null;

		return ['data' => $png, 'from' => [$width, $height, strlen($data)], 'to' => [$newWidth, $newHeight, strlen($png)]];
	}

	/**
	 * @param resource|GdImage $image
	 * @param int $width
	 * @param int $height
	 * @return bool
	 */
	private function _isOpaque($image, $width, $height) {
		if ($width * $height > 4000000) return false;
		for ($y = 0; $y < $height; $y++) {
			for ($x = 0; $x < $width; $x++) {
				if ((imagecolorat($image, $x, $y) >> 24) & 0x7F) return false;
			}
		}
		return true;
	}

	/**
	 * @param int $bytes
	 * @return bool
	 */
	private function _hasMemoryFor($bytes) {
		$limit = trim((string) ini_get('memory_limit'));
		if ($limit === '' || $limit === '-1') return true;
		$value = (int) $limit;
		switch (strtolower(substr($limit, -1))) {
			case 'g': $value *= 1024;
			case 'm': $value *= 1024;
			case 'k': $value *= 1024;
		}
		return memory_get_usage() + $bytes < $value * 0.8;
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

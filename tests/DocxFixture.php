<?php

/**
 * Builds small synthetic .docx packages for the tests.
 */
class DocxFixture {
	const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
	const R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
	const REL_BASE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/';
	const DOCX_MAIN = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml';

	/**
	 * Parts of a document with tables, comments, tracked changes, an image
	 * and (optionally) two embedded fonts.
	 *
	 * @param bool $withFonts
	 * @return array name => content
	 */
	public static function parts($withFonts = true) {
		$xmlHead = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\r\n";

		$defaults = '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			. '<Default Extension="xml" ContentType="application/xml"/>'
			. '<Default Extension="png" ContentType="image/png"/>'
			. ($withFonts ? '<Default Extension="odttf" ContentType="application/vnd.openxmlformats-officedocument.obfuscatedFont"/>' : '');
		$overrides = self::override('/word/document.xml', self::DOCX_MAIN)
			. self::override('/word/settings.xml', 'application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml')
			. self::override('/word/fontTable.xml', 'application/vnd.openxmlformats-officedocument.wordprocessingml.fontTable+xml')
			. self::override('/word/comments.xml', 'application/vnd.openxmlformats-officedocument.wordprocessingml.comments+xml')
			. self::override('/word/header1.xml', 'application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml');

		$parts = [];
		$parts['[Content_Types].xml'] = $xmlHead
			. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' . $defaults . $overrides . '</Types>';
		$parts['_rels/.rels'] = $xmlHead . self::rels([
			['rId1', 'officeDocument', 'word/document.xml'],
		]);
		$parts['word/document.xml'] = $xmlHead
			. '<w:document xmlns:w="' . self::W . '" xmlns:r="' . self::R . '"><w:body>'
			. '<w:p><w:r><w:rPr><w:rFonts w:ascii="Calibri"/></w:rPr><w:t>Halo dunia — teks asli.</w:t></w:r></w:p>'
			. '<w:p><w:ins w:id="1" w:author="Penulis" w:date="2026-01-01T00:00:00Z"><w:r><w:t>sisipan</w:t></w:r></w:ins>'
			. '<w:del w:id="2" w:author="Penulis" w:date="2026-01-01T00:00:00Z"><w:r><w:delText>hapusan</w:delText></w:r></w:del></w:p>'
			. '<w:p><w:commentRangeStart w:id="0"/><w:r><w:t>dikomentari</w:t></w:r><w:commentRangeEnd w:id="0"/>'
			. '<w:r><w:commentReference w:id="0"/></w:r></w:p>'
			. '<w:tbl><w:tr><w:tc><w:p><w:r><w:t>A1</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>B1</w:t></w:r></w:p></w:tc></w:tr></w:tbl>'
			. '<w:sectPr><w:headerReference w:type="default" r:id="rId5"/></w:sectPr>'
			. '</w:body></w:document>';
		$parts['word/_rels/document.xml.rels'] = $xmlHead . self::rels([
			['rId1', 'settings', 'settings.xml'],
			['rId2', 'fontTable', 'fontTable.xml'],
			['rId3', 'comments', 'comments.xml'],
			['rId4', 'image', 'media/image1.png'],
			['rId5', 'header', 'header1.xml'],
		]);
		$parts['word/comments.xml'] = $xmlHead
			. '<w:comments xmlns:w="' . self::W . '"><w:comment w:id="0" w:author="Reviewer"><w:p><w:r><w:t>Komentar reviewer</w:t></w:r></w:p></w:comment></w:comments>';
		$parts['word/header1.xml'] = $xmlHead
			. '<w:hdr xmlns:w="' . self::W . '"><w:p><w:r><w:t>Header</w:t></w:r></w:p></w:hdr>';
		$parts['word/media/image1.png'] = "\x89PNG\r\n\x1a\n" . self::bytes(2000, 1);

		$embeds = $withFonts
			? '<w:embedRegular r:id="rId1" w:fontKey="{11111111-1111-1111-1111-111111111111}"/>'
				. '<w:embedBold r:id="rId2" w:fontKey="{22222222-2222-2222-2222-222222222222}"/>'
			: '';
		$parts['word/fontTable.xml'] = $xmlHead
			. '<w:fonts xmlns:w="' . self::W . '" xmlns:r="' . self::R . '">'
			. '<w:font w:name="Calibri"><w:charset w:val="00"/><w:family w:val="swiss"/>' . $embeds . '</w:font>'
			. '<w:font w:name="Times New Roman"><w:charset w:val="00"/></w:font>'
			. '</w:fonts>';
		$parts['word/settings.xml'] = $xmlHead
			. '<w:settings xmlns:w="' . self::W . '"><w:zoom w:percent="100"/>'
			. ($withFonts ? '<w:embedTrueTypeFonts/><w:saveSubsetFonts/>' : '')
			. '<w:defaultTabStop w:val="720"/></w:settings>';

		if ($withFonts) {
			$parts['word/_rels/fontTable.xml.rels'] = $xmlHead . self::rels([
				['rId1', 'font', 'fonts/font1.odttf'],
				['rId2', 'font', 'fonts/font2.odttf'],
			]);
			// Incompressible, like real (obfuscated) font data
			$parts['word/fonts/font1.odttf'] = self::bytes(300000, 2);
			$parts['word/fonts/font2.odttf'] = self::bytes(250000, 3);
		}
		return $parts;
	}

	/**
	 * A picture paragraph (w:drawing/wp:inline/.../pic:pic) showing relationship $rId.
	 *
	 * @param string $rId
	 * @param int $cx Displayed width in EMU (914400 per inch)
	 * @param int $cy Displayed height in EMU
	 * @param string $srcRect Attributes for a:srcRect (cropping), e.g. 'l="25000" r="25000"'
	 * @return string
	 */
	public static function picture($rId, $cx, $cy, $srcRect = '') {
		return '<w:p><w:r><w:drawing>'
			. '<wp:inline xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing">'
			. '<wp:extent cx="' . $cx . '" cy="' . $cy . '"/><wp:docPr id="1" name="Picture 1"/>'
			. '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
			. '<a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
			. '<pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
			. '<pic:nvPicPr><pic:cNvPr id="0" name="image1.png"/><pic:cNvPicPr/></pic:nvPicPr>'
			. '<pic:blipFill><a:blip r:embed="' . $rId . '"/>' . ($srcRect === '' ? '<a:srcRect/>' : '<a:srcRect ' . $srcRect . '/>')
			. '<a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
			. '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm><a:prstGeom prst="rect"/></pic:spPr>'
			. '</pic:pic></a:graphicData></a:graphic></wp:inline>'
			. '</w:drawing></w:r></w:p>';
	}

	/**
	 * Add $paragraphs at the start of the document body.
	 *
	 * @param array $parts
	 * @param string $paragraphs
	 * @return array
	 */
	public static function withBodyContent(array $parts, $paragraphs) {
		$parts['word/document.xml'] = str_replace('<w:body>', '<w:body>' . $paragraphs, $parts['word/document.xml']);
		return $parts;
	}

	/**
	 * Noisy (hard to compress) PNG.
	 *
	 * @param int $width
	 * @param int $height
	 * @param bool $alpha Use translucent pixels
	 * @return string
	 */
	public static function png($width, $height, $alpha = false) {
		$image = imagecreatetruecolor($width, $height);
		imagealphablending($image, false);
		imagesavealpha($image, $alpha);
		mt_srand(42);
		for ($y = 0; $y < $height; $y++) {
			for ($x = 0; $x < $width; $x++) {
				$color = mt_rand(0, 0xFFFFFF);
				if ($alpha) $color |= mt_rand(0, 100) << 24;
				imagesetpixel($image, $x, $y, $color);
			}
		}
		ob_start();
		imagepng($image);
		return ob_get_clean();
	}

	/**
	 * PNG color type from the IHDR chunk (2 = RGB, 6 = RGBA).
	 * @param string $png
	 * @return int
	 */
	public static function pngColorType($png) {
		return ord($png[25]);
	}

	/**
	 * @param string $path
	 * @param array $parts name => content
	 * @param int $method ZipArchive::CM_DEFLATE or CM_STORE
	 */
	public static function write($path, array $parts, $method = ZipArchive::CM_DEFLATE) {
		if (file_exists($path)) unlink($path);
		$zip = new ZipArchive();
		if ($zip->open($path, ZipArchive::CREATE) !== true) throw new RuntimeException('Cannot create ' . $path);
		foreach ($parts as $name => $content) {
			$zip->addFromString($name, $content);
			$zip->setCompressionName($name, $method);
		}
		$zip->close();
	}

	/**
	 * @param string $path
	 * @return array name => content
	 */
	public static function read($path) {
		$zip = new ZipArchive();
		if ($zip->open($path) !== true) throw new RuntimeException('Cannot open ' . $path);
		$parts = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$parts[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
		}
		$zip->close();
		return $parts;
	}

	/**
	 * @param array $rels [[id, type suffix, target], ...]
	 * @return string
	 */
	public static function rels(array $rels) {
		$xml = '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
		foreach ($rels as $rel) {
			$xml .= '<Relationship Id="' . $rel[0] . '" Type="' . self::REL_BASE . $rel[1] . '" Target="' . $rel[2] . '"/>';
		}
		return $xml . '</Relationships>';
	}

	/**
	 * @param string $partName
	 * @param string $contentType
	 * @return string
	 */
	public static function override($partName, $contentType) {
		return '<Override PartName="' . $partName . '" ContentType="' . $contentType . '"/>';
	}

	/**
	 * Deterministic pseudo-random bytes.
	 * @param int $length
	 * @param int $seed
	 * @return string
	 */
	public static function bytes($length, $seed) {
		$out = '';
		$block = (string) $seed;
		while (strlen($out) < $length) {
			$block = hash('sha256', $block, true);
			$out .= $block;
		}
		return substr($out, 0, $length);
	}
}

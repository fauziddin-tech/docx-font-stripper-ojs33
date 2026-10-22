<?php

use PHPUnit\Framework\TestCase;

class DocxFontStripperTest extends TestCase {
	/** @var string */
	private $dir;

	/** @var DocxFontStripper */
	private $stripper;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/dfs-test-' . bin2hex(random_bytes(4));
		mkdir($this->dir);
		$this->stripper = new DocxFontStripper();
	}

	protected function tearDown(): void {
		foreach (glob($this->dir . '/*') as $file) unlink($file);
		rmdir($this->dir);
	}

	public function testStripsEmbeddedFonts(): void {
		$in = $this->fixture('fonts.docx', DocxFixture::parts(true));
		$out = $this->dir . '/out.docx';
		$originalHash = sha1_file($in);

		$result = $this->stripper->strip($in, $out);

		$this->assertSame(DocxFontStripResult::STRIPPED, $result->status, $result->summary());
		$this->assertSame($originalHash, sha1_file($in), 'source must not be modified');
		$this->assertLessThan($result->sizeBefore / 5, $result->sizeAfter);
		$this->assertSame(filesize($out), $result->sizeAfter);

		$parts = DocxFixture::read($out);
		$this->assertArrayNotHasKey('word/fonts/font1.odttf', $parts);
		$this->assertArrayNotHasKey('word/fonts/font2.odttf', $parts);
		$this->assertArrayNotHasKey('word/_rels/fontTable.xml.rels', $parts, 'empty font relationships part is dropped');

		$fontTable = $this->dom($parts['word/fontTable.xml']);
		$this->assertSame(0, $fontTable->getElementsByTagNameNS(DocxFixture::W, 'embedRegular')->length);
		$this->assertSame(0, $fontTable->getElementsByTagNameNS(DocxFixture::W, 'embedBold')->length);
		$this->assertSame(2, $fontTable->getElementsByTagNameNS(DocxFixture::W, 'font')->length, 'font declarations stay');
		$this->assertSame(2, $fontTable->getElementsByTagNameNS(DocxFixture::W, 'charset')->length);

		$settings = $this->dom($parts['word/settings.xml']);
		$this->assertSame(0, $settings->getElementsByTagNameNS(DocxFixture::W, 'embedTrueTypeFonts')->length);
		$this->assertSame(0, $settings->getElementsByTagNameNS(DocxFixture::W, 'saveSubsetFonts')->length);
		$this->assertSame(1, $settings->getElementsByTagNameNS(DocxFixture::W, 'zoom')->length);
		$this->assertSame(1, $settings->getElementsByTagNameNS(DocxFixture::W, 'defaultTabStop')->length);

		$contentTypes = $parts['[Content_Types].xml'];
		$this->assertStringNotContainsString('odttf', $contentTypes);
		$this->assertStringContainsString('Extension="png"', $contentTypes);
		$this->assertStringContainsString('PartName="/word/fontTable.xml"', $contentTypes);
		$this->assertStringContainsString('standalone="yes"', $contentTypes);

		$this->assertEqualsCanonicalizing(
			['word/fonts/font1.odttf', 'word/fonts/font2.odttf', 'word/_rels/fontTable.xml.rels'],
			$result->removedParts
		);
	}

	public function testDocumentContentIsByteIdentical(): void {
		$parts = DocxFixture::parts(true);
		$in = $this->fixture('fonts.docx', $parts);
		$out = $this->dir . '/out.docx';

		$this->assertTrue($this->stripper->strip($in, $out)->isStripped());

		$cleaned = DocxFixture::read($out);
		foreach (['word/document.xml', 'word/comments.xml', 'word/header1.xml', 'word/media/image1.png', 'word/_rels/document.xml.rels', '_rels/.rels'] as $name) {
			$this->assertSame($parts[$name], $cleaned[$name], $name . ' must be unchanged');
		}
		$this->assertStringContainsString('<w:ins ', $cleaned['word/document.xml']);
		$this->assertStringContainsString('<w:delText>hapusan</w:delText>', $cleaned['word/document.xml']);
	}

	public function testStoredEntriesAreDeflatedWithoutChangingContent(): void {
		$parts = DocxFixture::parts(true);
		$parts['word/document.xml'] .= str_repeat('<!-- padding -->', 5000);
		$in = $this->fixture('stored.docx', $parts, ZipArchive::CM_STORE);
		$out = $this->dir . '/out.docx';

		$result = $this->stripper->strip($in, $out);

		$this->assertTrue($result->isStripped(), $result->summary());
		$this->assertContains('word/document.xml', $result->recompressedParts);
		$this->assertNotContains('word/media/image1.png', $result->recompressedParts);
		$zip = new ZipArchive();
		$zip->open($out);
		$stat = $zip->statName('word/document.xml');
		$this->assertSame(ZipArchive::CM_DEFLATE, $stat['comp_method']);
		$this->assertLessThan($stat['size'] / 10, $stat['comp_size']);
		$this->assertSame($parts['word/document.xml'], $zip->getFromName('word/document.xml'));
		$zip->close();
	}

	public function testDocxWithoutEmbeddedFontsIsUnchanged(): void {
		$parts = DocxFixture::parts(false);
		// The "embed fonts" flag alone does not count as embedded fonts
		$parts['word/settings.xml'] = str_replace('<w:zoom w:percent="100"/>', '<w:zoom w:percent="100"/><w:embedTrueTypeFonts/>', $parts['word/settings.xml']);
		$in = $this->fixture('nofonts.docx', $parts);
		$out = $this->dir . '/out.docx';
		$hash = sha1_file($in);

		$result = $this->stripper->strip($in, $out);

		$this->assertSame(DocxFontStripResult::UNCHANGED, $result->status);
		$this->assertFileDoesNotExist($out);

		$inPlace = $this->stripper->stripInPlace($in);
		$this->assertSame(DocxFontStripResult::UNCHANGED, $inPlace->status);
		$this->assertSame($hash, sha1_file($in));
		$this->assertSame([$in], glob($this->dir . '/*'), 'no temporary files left behind');
	}

	public function testSecondRunIsUnchanged(): void {
		$in = $this->fixture('fonts.docx', DocxFixture::parts(true));
		$this->assertTrue($this->stripper->stripInPlace($in)->isStripped());
		$hash = sha1_file($in);

		$this->assertSame(DocxFontStripResult::UNCHANGED, $this->stripper->stripInPlace($in)->status);
		$this->assertSame($hash, sha1_file($in));
	}

	/**
	 * @dataProvider nonDocxProvider
	 */
	public function testNonDocxFilesAreSkipped(string $content): void {
		$in = $this->dir . '/file.bin';
		file_put_contents($in, $content);
		$hash = sha1_file($in);

		$result = $this->stripper->stripInPlace($in);

		$this->assertSame(DocxFontStripResult::SKIPPED, $result->status);
		$this->assertSame($hash, sha1_file($in));
	}

	public function nonDocxProvider(): array {
		return [
			'legacy .doc (OLE)' => ["\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 600)],
			'pdf' => ["%PDF-1.7\n1 0 obj\n<<>>\nendobj\n%%EOF"],
			'png' => ["\x89PNG\r\n\x1a\n" . str_repeat("\0", 100)],
			'text' => ['just text'],
			'empty' => [''],
			'truncated zip' => ["PK\x03\x04garbage"],
		];
	}

	public function testDocmIsSkipped(): void {
		$parts = DocxFixture::parts(true);
		$parts['[Content_Types].xml'] = str_replace(
			DocxFixture::DOCX_MAIN,
			'application/vnd.ms-word.document.macroEnabled.main+xml',
			$parts['[Content_Types].xml']
		);
		$in = $this->fixture('macro.docm', $parts);
		$hash = sha1_file($in);

		$result = $this->stripper->stripInPlace($in);

		$this->assertSame(DocxFontStripResult::SKIPPED, $result->status);
		$this->assertStringContainsString('not a .docx', $result->reason);
		$this->assertSame($hash, sha1_file($in));
	}

	public function testZipWithoutDocumentXmlIsSkipped(): void {
		$parts = DocxFixture::parts(true);
		unset($parts['word/document.xml']);
		$in = $this->fixture('other.zip', $parts);

		$this->assertSame(DocxFontStripResult::SKIPPED, $this->stripper->stripInPlace($in)->status);
	}

	public function testMalformedFontTableFailsAndKeepsOriginal(): void {
		$parts = DocxFixture::parts(true);
		$parts['word/fontTable.xml'] = '<w:fonts xmlns:w="' . DocxFixture::W . '"><w:font>';
		$in = $this->fixture('broken.docx', $parts);
		$hash = sha1_file($in);

		$result = $this->stripper->stripInPlace($in);

		$this->assertSame(DocxFontStripResult::FAILED, $result->status);
		$this->assertStringContainsString('word/fontTable.xml', $result->reason);
		$this->assertSame($hash, sha1_file($in));
		$this->assertSame([$in], glob($this->dir . '/*'), 'no temporary files left behind');
	}

	public function testUnexpectedReferenceToFontPartFailsValidation(): void {
		$parts = DocxFixture::parts(true);
		// Some other part still points at a font file we would remove
		$parts['word/_rels/document.xml.rels'] = str_replace(
			'</Relationships>',
			'<Relationship Id="rId9" Type="' . DocxFixture::REL_BASE . 'image" Target="fonts/font1.odttf"/></Relationships>',
			$parts['word/_rels/document.xml.rels']
		);
		$in = $this->fixture('dangling.docx', $parts);
		$hash = sha1_file($in);

		$result = $this->stripper->stripInPlace($in);

		$this->assertSame(DocxFontStripResult::FAILED, $result->status);
		$this->assertStringContainsString('still references', $result->reason);
		$this->assertSame($hash, sha1_file($in));
	}

	public function testFontsOutsideWordFontsAreFoundThroughRelationships(): void {
		$parts = DocxFixture::parts(true);
		$parts['[Content_Types].xml'] = str_replace(
			'</Types>',
			'<Default Extension="ttf" ContentType="application/x-font-ttf"/>'
				. DocxFixture::override('/word/glossary/fontTable.xml', 'application/vnd.openxmlformats-officedocument.wordprocessingml.fontTable+xml')
				. '</Types>',
			$parts['[Content_Types].xml']
		);
		$parts['word/glossary/fontTable.xml'] = '<w:fonts xmlns:w="' . DocxFixture::W . '" xmlns:r="' . DocxFixture::R . '">'
			. '<w:font w:name="Lato"><w:embedItalic r:id="rId1" w:fontKey="{33333333-3333-3333-3333-333333333333}"/></w:font></w:fonts>';
		$parts['word/glossary/_rels/fontTable.xml.rels'] = DocxFixture::rels([['rId1', 'font', '../embedded/Lato-Italic.ttf']]);
		$parts['word/embedded/Lato-Italic.ttf'] = DocxFixture::bytes(50000, 4);
		$in = $this->fixture('glossary.docx', $parts);
		$out = $this->dir . '/out.docx';

		$result = $this->stripper->strip($in, $out);

		$this->assertTrue($result->isStripped(), $result->summary());
		$cleaned = DocxFixture::read($out);
		$this->assertArrayNotHasKey('word/embedded/Lato-Italic.ttf', $cleaned);
		$this->assertArrayNotHasKey('word/glossary/_rels/fontTable.xml.rels', $cleaned);
		$this->assertStringNotContainsString('embedItalic', $cleaned['word/glossary/fontTable.xml']);
		$this->assertStringNotContainsString('Extension="ttf"', $cleaned['[Content_Types].xml']);
	}

	public function testContentTypeDefaultKeptWhileStillUsed(): void {
		$parts = DocxFixture::parts(true);
		$parts['word/embeddings/keep.odttf'] = 'not a font, but still in the package';
		$in = $this->fixture('keep.docx', $parts);
		$out = $this->dir . '/out.docx';

		$this->assertTrue($this->stripper->strip($in, $out)->isStripped());
		$cleaned = DocxFixture::read($out);
		$this->assertArrayHasKey('word/embeddings/keep.odttf', $cleaned);
		$this->assertStringContainsString('Extension="odttf"', $cleaned['[Content_Types].xml']);
	}

	public function testStrictNamespaceIsHandled(): void {
		$strictW = 'http://purl.oclc.org/ooxml/wordprocessingml/main';
		$parts = DocxFixture::parts(true);
		$parts['word/fontTable.xml'] = str_replace(DocxFixture::W, $strictW, $parts['word/fontTable.xml']);
		$parts['word/settings.xml'] = str_replace(DocxFixture::W, $strictW, $parts['word/settings.xml']);
		$parts['word/_rels/fontTable.xml.rels'] = str_replace(
			DocxFixture::REL_BASE . 'font',
			'http://purl.oclc.org/ooxml/officeDocument/relationships/font',
			$parts['word/_rels/fontTable.xml.rels']
		);
		$in = $this->fixture('strict.docx', $parts);
		$out = $this->dir . '/out.docx';

		$this->assertTrue($this->stripper->strip($in, $out)->isStripped());
		$cleaned = DocxFixture::read($out);
		$this->assertStringNotContainsString('embedRegular', $cleaned['word/fontTable.xml']);
		$this->assertStringNotContainsString('embedTrueTypeFonts', $cleaned['word/settings.xml']);
	}

	public function testStripInPlaceKeepsPermissions(): void {
		if (DIRECTORY_SEPARATOR === '\\') $this->markTestSkipped('POSIX permissions only');
		$in = $this->fixture('fonts.docx', DocxFixture::parts(true));
		chmod($in, 0640);
		clearstatcache();
		$before = filesize($in);

		$result = $this->stripper->stripInPlace($in);

		clearstatcache();
		$this->assertTrue($result->isStripped());
		$this->assertLessThan($before, filesize($in));
		$this->assertSame(0640, fileperms($in) & 0777);
		$this->assertSame([$in], glob($this->dir . '/*'));
	}

	/**
	 * Optional: run against a real file, e.g.
	 *   DOCX_SAMPLE=/path/to/article.docx vendor/bin/phpunit
	 */
	public function testRealSample(): void {
		$sample = getenv('DOCX_SAMPLE');
		if (!$sample) $this->markTestSkipped('set DOCX_SAMPLE=/path/to/file.docx to run');
		$this->assertFileExists($sample);
		$out = $this->dir . '/sample.docx';

		$result = $this->stripper->strip($sample, $out);
		fwrite(STDERR, "\n" . basename($sample) . ': ' . $result->summary() . "\n");

		$this->assertContains($result->status, [DocxFontStripResult::STRIPPED, DocxFontStripResult::UNCHANGED], $result->reason);
		if ($result->isStripped()) {
			$this->assertLessThan($result->sizeBefore, $result->sizeAfter);
			$before = DocxFixture::read($sample);
			$after = DocxFixture::read($out);
			$this->assertSame($before['word/document.xml'], $after['word/document.xml']);
			foreach (array_keys($after) as $name) {
				$this->assertStringStartsNotWith('word/fonts/', $name);
			}
		}
	}

	/**
	 * @param string $name
	 * @param array $parts
	 * @param int $method
	 * @return string
	 */
	private function fixture($name, array $parts, $method = ZipArchive::CM_DEFLATE) {
		$path = $this->dir . '/' . $name;
		DocxFixture::write($path, $parts, $method);
		return $path;
	}

	/**
	 * @param string $xml
	 * @return DOMDocument
	 */
	private function dom($xml) {
		$doc = new DOMDocument();
		$this->assertTrue($doc->loadXML($xml), 'XML must be well-formed');
		return $doc;
	}
}

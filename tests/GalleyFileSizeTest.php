<?php

use PHPUnit\Framework\TestCase;

class GalleyFileSizeTest extends TestCase {

	private const BASE = 'https://jurnal.example.ac.id/index.php/jpi';

	/** @var array[] Resolver calls */
	private $calls = [];

	private function sizer(array $sizes = [], string $base = self::BASE, string $sep = ','): GalleyFileSize {
		$this->calls = [];
		$prefixes = [];
		foreach (['article', 'issue'] as $page) {
			foreach (['view', 'download'] as $op) {
				$prefixes[$page][] = strpos($base, '?') === false
					? "$base/$page/$op"
					: "$base&page=$page&op=$op";
			}
		}
		return new GalleyFileSize($prefixes, function ($page, $args) use ($sizes) {
			$this->calls[] = [$page, $args];
			return $sizes[$page . ':' . implode('/', $args)] ?? null;
		}, $sep);
	}

	/**
	 * @dataProvider sizes
	 */
	public function testFormatSize(int $bytes, string $expected): void {
		$this->assertSame($expected, GalleyFileSize::formatSize($bytes, ','));
	}

	public function sizes(): array {
		return [
			[0, '0 B'],
			[820, '820 B'],
			[1024, '1 KB'],
			[17 * 1024 + 300, '17 KB'],
			[1024 * 1024 - 1, '1,0 MB'],
			[1024 * 1023, '1023 KB'],
			[1024 * 1024, '1,0 MB'],
			[(int) (1.24 * 1024 * 1024), '1,2 MB'],
			[(int) (9.96 * 1024 * 1024), '10 MB'],
			[(int) (14.95 * 1024 * 1024), '15 MB'],
			[(int) (1.1 * 1024 ** 3), '1,1 GB'],
		];
	}

	public function testDecimalSeparatorFollowsLocale(): void {
		$this->assertSame('1.2 MB', GalleyFileSize::formatSize((int) (1.24 * 1024 * 1024), '.'));
	}

	public function testAddsSizeInsideGalleyLink(): void {
		$html = '<a class="obj_galley_link pdf" href="' . self::BASE . '/article/view/12/34">PDF</a>';
		$out = $this->sizer(['article:12/34' => 1300000])->annotate($html);
		$this->assertSame('<a class="obj_galley_link pdf" href="' . self::BASE . '/article/view/12/34">PDF <span class="dfs_file_size">(1,2 MB)</span></a>', $out);
	}

	public function testWorksWithAnyThemeMarkup(): void {
		$html = '<div class="galleys"><a href="' . self::BASE . '/article/download/artikel-a/pdf-id" target="_blank" class="btn btn-primary"><i class="fa fa-file-pdf"></i> Unduh PDF</a></div>'
			. '<a href=\'' . self::BASE . '/issue/view/5/7\'>Full issue</a>';
		$out = $this->sizer(['article:artikel-a/pdf-id' => 512000, 'issue:5/7' => 3 * 1024 * 1024])->annotate($html);
		$this->assertStringContainsString('Unduh PDF <span class="dfs_file_size">(500 KB)</span></a>', $out);
		$this->assertStringContainsString('Full issue <span class="dfs_file_size">(3,0 MB)</span></a>', $out);
	}

	public function testVersionedAndFileLinks(): void {
		$sizer = $this->sizer();
		$this->assertSame(['article', ['12', 'version', '40', '34']], $sizer->parseGalleyHref(self::BASE . '/article/view/12/version/40/34'));
		$this->assertSame(['article', ['12', '34', '99']], $sizer->parseGalleyHref(self::BASE . '/article/download/12/34/99'));
		$this->assertSame(['article', ['12', '34']], $sizer->parseGalleyHref('http://jurnal.example.ac.id/index.php/jpi/article/view/12/34?x=1#top'));
		$this->assertSame(['article', ['12', '34']], $sizer->parseGalleyHref('//jurnal.example.ac.id/index.php/jpi/article/view/12/34/'));
	}

	/**
	 * @dataProvider notGalleyLinks
	 */
	public function testIgnoresOtherLinks(string $href): void {
		$this->assertNull($this->sizer()->parseGalleyHref($href));
	}

	public function notGalleyLinks(): array {
		return [
			'article landing page' => [self::BASE . '/article/view/12'],
			'version landing page' => [self::BASE . '/article/view/12/version/40'],
			'issue table of contents' => [self::BASE . '/issue/view/5'],
			'issue archive' => [self::BASE . '/issue/archive'],
			'other journal' => ['https://jurnal.example.ac.id/index.php/lain/article/view/12/34'],
			'other site' => ['https://example.org/index.php/jpi/article/view/12/34'],
			'prefix of a longer path' => [self::BASE . '/article/viewer/12/34'],
			'too many segments' => [self::BASE . '/article/view/1/2/3/4'],
		];
	}

	public function testQueryStyleUrls(): void {
		$base = 'https://jurnal.example.ac.id/index.php?journal=jpi';
		$sizer = $this->sizer(['article:12/34' => 2048], $base);
		$html = '<a href="https://jurnal.example.ac.id/index.php?journal=jpi&amp;page=article&amp;op=view&amp;path%5B%5D=12&amp;path%5B%5D=34">PDF</a>'
			. '<a href="https://jurnal.example.ac.id/index.php?journal=lain&amp;page=article&amp;op=view&amp;path%5B%5D=12&amp;path%5B%5D=34">PDF</a>';
		$out = $sizer->annotate($html);
		$this->assertSame(1, substr_count($out, 'dfs_file_size'));
		$this->assertStringContainsString('PDF <span class="dfs_file_size">(2 KB)</span></a><a', $out);
	}

	public function testLeavesLinksThatAlreadyShowASize(): void {
		$html = '<a href="' . self::BASE . '/article/view/12/34">PDF (1.2 MB)</a>'
			. '<a href="' . self::BASE . '/article/view/12/35">HTML</a> <span class="size">820 KB</span>'
			. '<a href="' . self::BASE . '/article/view/12/36" class="dfs_no_size">XML</a>';
		$sizer = $this->sizer(['article:12/34' => 5000, 'article:12/35' => 5000, 'article:12/36' => 5000]);
		$this->assertSame($html, $sizer->annotate($html));
	}

	public function testUnknownGalleyIsLeftAlone(): void {
		$html = '<a href="' . self::BASE . '/article/view/12/34">PDF</a>';
		$this->assertSame($html, $this->sizer([])->annotate($html));
	}

	public function testResolvesEachGalleyOnce(): void {
		$link = '<a href="' . self::BASE . '/article/view/12/34">PDF</a>';
		$out = $this->sizer(['article:12/34' => 4096])->annotate($link . $link . $link);
		$this->assertSame(3, substr_count($out, '(4 KB)'));
		$this->assertCount(1, $this->calls);
	}

	public function testPageWithoutLinksIsUnchanged(): void {
		$html = "<html><body><p>Tidak ada tautan.</p></body></html>";
		$this->assertSame($html, $this->sizer()->annotate($html));
		$this->assertSame([], $this->calls);
	}
}

<?php

use PHPUnit\Framework\TestCase;

class UploadGuardTest extends TestCase {

	/**
	 * @dataProvider blockedNames
	 */
	public function testBlocksExecutableUploads(string $name, string $extension): void {
		$this->assertSame($extension, (new UploadGuard())->blockedExtension($name));
	}

	public function blockedNames(): array {
		return [
			'phtml (seen on a live journal)' => ['zip.phtml', 'phtml'],
			'php' => ['shell.php', 'php'],
			'upper case' => ['SHELL.PHTML', 'phtml'],
			'phar' => ['x.phar', 'phar'],
			'pht' => ['x.pht', 'pht'],
			'php7' => ['x.php7', 'php7'],
			'double extension, executable last' => ['artikel.docx.phtml', 'phtml'],
			'trailing dot' => ['x.phtml.', 'phtml'],
			'trailing dot and space' => ['x.php. ', 'php'],
			'shtml' => ['index.shtml', 'shtml'],
			'cgi' => ['run.cgi', 'cgi'],
			'aspx' => ['a.aspx', 'aspx'],
			'jsp' => ['a.jsp', 'jsp'],
			'htaccess' => ['.htaccess', 'htaccess'],
			'user.ini' => ['.user.ini', 'user.ini'],
			'exe' => ['setup.exe', 'exe'],
			'bat' => ['run.bat', 'bat'],
			'path with directories' => ['journals/1/articles/5/65f0c1.phtml', 'phtml'],
			'windows path' => ['C:\\Users\\a\\x.php', 'php'],
		];
	}

	/**
	 * @dataProvider allowedNames
	 */
	public function testAllowsManuscriptsAndResearchFiles(string $name): void {
		$this->assertNull((new UploadGuard())->blockedExtension($name));
	}

	public function allowedNames(): array {
		return [
			['naskah.docx'], ['naskah.DOCX'], ['naskah.pdf'], ['naskah.odt'], ['naskah.doc'], ['naskah.rtf'],
			['artikel.php.docx'], ['data.csv'], ['data.xlsx'], ['analisis.py'], ['model.R'], ['run.sh'],
			['figure.svg'], ['galley.html'], ['galley.htm'], ['lampiran.zip'], ['foto.jpg'],
			['README'], [''], ['.'], ['php'], ['laporan.phpinfo.pdf'], ['user.ini.txt'],
		];
	}

	public function testCustomList(): void {
		$guard = new UploadGuard(['.EXE', ' zip ', '']);
		$this->assertSame('zip', $guard->blockedExtension('a.zip'));
		$this->assertSame('exe', $guard->blockedExtension('a.exe'));
		$this->assertNull($guard->blockedExtension('a.phtml'));
	}
}

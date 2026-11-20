<?php

/**
 * @file classes/UploadGuard.inc.php
 *
 * Distributed under the GNU GPL v3.
 *
 * @class UploadGuard
 * @brief Decides whether an uploaded file name has an extension that a web
 *  server could execute (PHP, CGI, ASP, JSP, SSI, .htaccess) or that is a
 *  Windows program. No OJS dependency, so it can be unit tested on its own.
 *
 * OJS 3.3 stores an uploaded submission file as "<uniqid>.<extension>", where
 * the extension is the last dot-separated part of the original name
 * (FileManager::parseFileExtension). Only that last part reaches the disk, so
 * only that part is checked: "artikel.php.docx" is stored as .docx and is
 * harmless, "zip.phtml" is stored as .phtml.
 */

class UploadGuard {

	/**
	 * Blocked by default. Kept to types that have no place in a journal
	 * submission; research code (.py, .r, .sh), figures (.svg) and HTML galleys
	 * (.html, .htm) are deliberately not on the list.
	 */
	const DEFAULT_BLOCKED = [
		// PHP and other server-side scripts
		'php', 'phtml', 'pht', 'phar', 'phps', 'php3', 'php4', 'php5', 'php7', 'php8', 'inc',
		'cgi', 'fcgi', 'asp', 'aspx', 'ashx', 'asmx', 'jsp', 'jspx', 'shtml', 'shtm', 'stm',
		'htaccess', 'htpasswd', 'user.ini',
		// Windows programs and scripts
		'exe', 'dll', 'com', 'scr', 'msi', 'bat', 'cmd', 'vbs', 'vbe', 'wsf', 'ps1', 'hta', 'lnk',
	];

	/** @var string[] lower case, no leading dot */
	private $_blocked;

	/**
	 * @param string[]|null $blocked Extensions to block; null for DEFAULT_BLOCKED
	 */
	public function __construct($blocked = null) {
		$this->_blocked = [];
		foreach ($blocked === null ? self::DEFAULT_BLOCKED : $blocked as $ext) {
			$ext = strtolower(ltrim(trim((string) $ext), '.'));
			if ($ext !== '') $this->_blocked[$ext] = true;
		}
	}

	/**
	 * @param string $fileName Original or stored file name (a path is fine)
	 * @return string|null The blocked extension (lower case), or null if the name is allowed
	 */
	public function blockedExtension($fileName) {
		$name = strtolower(basename(str_replace('\\', '/', (string) $fileName)));
		// Windows and some servers ignore trailing dots and spaces: "x.phtml. " is "x.phtml".
		$name = rtrim($name, ". \t\r\n\0");
		if ($name === '') return null;

		// ".user.ini" style names: check the whole name first.
		foreach (['user.ini'] as $special) {
			if ($name === $special || substr($name, -strlen($special) - 1) === '.' . $special) {
				return isset($this->_blocked[$special]) ? $special : null;
			}
		}

		$pos = strrpos($name, '.');
		if ($pos === false) return null;
		$ext = substr($name, $pos + 1);
		return isset($this->_blocked[$ext]) ? $ext : null;
	}
}

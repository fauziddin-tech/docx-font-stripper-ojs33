<?php

/**
 * @file DocxFontStripperPlugin.inc.php
 *
 * Distributed under the GNU GPL v3.
 *
 * @class DocxFontStripperPlugin
 * @brief Removes embedded fonts from uploaded .docx submission files and
 *  downscales PNG images stored far above their displayed size.
 *
 * Hooks (pkp-lib 3_3_0-22):
 *  - SubmissionFile::add  classes/services/PKPSubmissionFileService.inc.php:448
 *    New files from the upload wizard (submission, review, review attachment,
 *    revision, copyediting, production, discussion) and the REST API.
 *  - SubmissionFile::edit classes/services/PKPSubmissionFileService.inc.php:461
 *    "Upload a revision" of an existing file (wizard and REST API).
 * Both fire after Services::get('file')->add() has stored the uploaded file,
 * so the stored file is cleaned in place.
 *
 * Upload guard (classes/UploadGuard.inc.php) rejects files a web server could
 * execute (.php, .phtml, .phar, .cgi, .asp, .jsp, .shtml, .htaccess, ...) and
 * Windows programs (.exe, .bat, ...) before they are kept:
 *  - submissionfilesuploadform::validate  lib/pkp/classes/form/Form.inc.php:285
 *    Upload wizard (FileUploadWizardHandler::uploadFile validates before the
 *    file is stored).
 *  - SubmissionFile::validate classes/services/PKPSubmissionFileService.inc.php:291
 *    REST API (PKPSubmissionFileHandler deletes the stored file on errors).
 *
 * Galley file size (classes/GalleyFileSize.inc.php) adds the size to every
 * article and issue galley link on reader pages, whatever the theme:
 *  - TemplateManager::display lib/pkp/classes/template/PKPTemplateManager.inc.php:1227
 *    registers a Smarty output filter, which Smarty runs on the whole page.
 */

import('lib.pkp.classes.plugins.GenericPlugin');
require_once(__DIR__ . '/classes/DocxFontStripper.inc.php');
require_once(__DIR__ . '/classes/UploadGuard.inc.php');
require_once(__DIR__ . '/classes/GalleyFileSize.inc.php');

class DocxFontStripperPlugin extends GenericPlugin {

	/** @var int[] File ids already handled in this request */
	private $_handledFileIds = [];

	/** @var bool Whether the galley size output filter is registered */
	private $_sizeFilterRegistered = false;

	/**
	 * @copydoc Plugin::register()
	 */
	public function register($category, $path, $mainContextId = null) {
		$success = parent::register($category, $path, $mainContextId);
		if (!Config::getVar('general', 'installed') || defined('RUNNING_UPGRADE')) return $success;
		if ($success && $this->getEnabled($mainContextId)) {
			HookRegistry::register('SubmissionFile::add', [$this, 'handleSubmissionFileAdd']);
			HookRegistry::register('SubmissionFile::edit', [$this, 'handleSubmissionFileEdit']);
			HookRegistry::register('submissionfilesuploadform::validate', [$this, 'guardWizardUpload']);
			HookRegistry::register('SubmissionFile::validate', [$this, 'guardApiUpload']);
			HookRegistry::register('TemplateManager::display', [$this, 'registerGalleySizeFilter']);
		}
		return $success;
	}

	/**
	 * @copydoc Plugin::getDisplayName()
	 */
	public function getDisplayName() {
		return __('plugins.generic.docxFontStripper.displayName');
	}

	/**
	 * @copydoc Plugin::getDescription()
	 */
	public function getDescription() {
		return __('plugins.generic.docxFontStripper.description');
	}

	/**
	 * SubmissionFile::add callback.
	 * @param string $hookName
	 * @param array $args [SubmissionFile &$submissionFile, Request $request]
	 * @return bool
	 */
	public function handleSubmissionFileAdd($hookName, $args) {
		$submissionFile = $args[0];
		$this->_processFile((int) $submissionFile->getData('fileId'), (int) $submissionFile->getId());
		return false;
	}

	/**
	 * SubmissionFile::edit callback. Only acts when a new file was uploaded.
	 * @param string $hookName
	 * @param array $args [SubmissionFile &$newSubmissionFile, SubmissionFile $submissionFile, array $params, Request $request]
	 * @return bool
	 */
	public function handleSubmissionFileEdit($hookName, $args) {
		$params = $args[2];
		if (!empty($params['fileId'])) {
			$this->_processFile((int) $params['fileId'], (int) $args[1]->getId());
		}
		return false;
	}

	/**
	 * submissionfilesuploadform::validate callback (upload wizard).
	 *
	 * The wizard template cannot show form errors, and its JavaScript expects an
	 * uploaded file whenever the response status is true. So a blocked file is
	 * answered like a failed upload (status false), which the browser shows as
	 * a message; nothing has been stored at this point.
	 *
	 * @param string $hookName
	 * @param array $args [Form $form, mixed &$value]
	 * @return bool
	 */
	public function guardWizardUpload($hookName, $args) {
		$name = $_FILES['uploadedFile']['name'] ?? null;
		if (!is_string($name)) return false;
		$extension = (new UploadGuard())->blockedExtension($name);
		if ($extension === null) return false;

		$this->_logBlocked('wizard', $name, $extension);
		$message = $this->_blockedMessage($extension);
		$args[0]->addError('uploadedFile', $message);
		$this->_sendJsonError($message);
		return false;
	}

	/**
	 * SubmissionFile::validate callback (REST API upload or revision).
	 * The handler deletes the stored file when validation returns errors.
	 *
	 * @param string $hookName
	 * @param array $args [array &$errors, string $action, array $props, array $allowedLocales, string $primaryLocale]
	 * @return bool
	 */
	public function guardApiUpload($hookName, $args) {
		$errors =& $args[0];
		$props = $args[2];
		if (empty($props['fileId'])) return false;

		$names = [];
		$upload = $_FILES['file']['name'] ?? null;
		if (is_string($upload)) $names[] = $upload;
		try {
			$file = Services::get('file')->get((int) $props['fileId']);
			if ($file) $names[] = $file->path;
		} catch (Throwable $e) {
			// Unknown file id: OJS reports that itself.
		}

		$guard = new UploadGuard();
		foreach ($names as $name) {
			$extension = $guard->blockedExtension($name);
			if ($extension === null) continue;
			$this->_logBlocked('api', $name, $extension);
			if (!is_array($errors)) $errors = [];
			$errors['fileId'] = [$this->_blockedMessage($extension)];
			break;
		}
		return false;
	}

	/**
	 * TemplateManager::display callback: add file sizes to galley links on
	 * reader pages. Independent of the theme, because the filter works on the
	 * page's final HTML instead of a theme's galley_link.tpl.
	 *
	 * @param string $hookName
	 * @param array $args [TemplateManager $templateMgr, string &$template, string &$output]
	 * @return bool
	 */
	public function registerGalleySizeFilter($hookName, $args) {
		if ($this->_sizeFilterRegistered) return false;
		$templateMgr = $args[0];
		$template = $args[1];
		if (!is_string($template) || !$this->_isReaderTemplate($template)) return false;

		$request = Application::get()->getRequest();
		$context = $request ? $request->getContext() : null;
		if (!$context) return false;

		$this->_sizeFilterRegistered = true;
		// Smarty keys output filters by name and names every closure "closure",
		// so an unnamed closure would replace, or be replaced by, another
		// plugin's (e.g. Publication Facts Label). Register under our own name.
		$templateMgr->registerFilter('output', function ($html) use ($request, $context) {
			try {
				return $this->_galleyFileSize($request, $context)->annotate($html);
			} catch (Throwable $e) {
				$this->_log('galley file size: ' . $e->getMessage());
				return $html;
			}
		}, 'docxFontStripperGalleySize');
		return false;
	}

	/**
	 * Reader-facing templates: OJS frontend pages and plugin templates shown to
	 * readers (theme pages, PDF.js galley viewer, static pages, ...). Backend
	 * pages are left alone.
	 *
	 * @param string $template
	 * @return bool
	 */
	private function _isReaderTemplate($template) {
		if (strpos($template, 'frontend/') === 0) return true;
		if (strpos($template, ':') === false) return false;
		$request = Application::get()->getRequest();
		$page = $request ? (string) $request->getRequestedPage() : '';
		$backendPages = ['dashboard', 'submissions', 'workflow', 'authorDashboard', 'reviewer', 'management', 'manageIssues', 'stats', 'admin', 'tools', 'settings', 'user'];
		return !in_array($page, $backendPages, true);
	}

	/**
	 * @param PKPRequest $request
	 * @param Context $context
	 * @return GalleyFileSize
	 */
	private function _galleyFileSize($request, $context) {
		$prefixes = [];
		foreach (['article', 'issue'] as $page) {
			foreach (['view', 'download'] as $op) {
				$prefixes[$page][] = $request->url($context->getPath(), $page, $op);
			}
		}
		return new GalleyFileSize($prefixes, function ($page, $args) use ($context) {
			try {
				return $page === 'article'
					? $this->_articleGalleySize($context, $args)
					: $this->_issueGalleySize($context, $args);
			} catch (Throwable $e) {
				return null;
			}
		}, $this->_decimalSeparator());
	}

	/**
	 * Size of an article galley's file, for a link with these path arguments.
	 * Only galleys of published publications in this journal.
	 *
	 * @param Context $context
	 * @param string[] $args {submission}/{galley}[/{fileId}] or {submission}/version/{publicationId}/{galley}[/{fileId}]
	 * @return int|null
	 */
	private function _articleGalleySize($context, array $args) {
		$submissionPath = array_shift($args);
		$submissionService = Services::get('submission');
		$submission = $submissionService->getByUrlPath($submissionPath, $context->getId());
		if (!$submission && ctype_digit($submissionPath)) $submission = $submissionService->get((int) $submissionPath);
		if (!$submission || (int) $submission->getData('contextId') !== (int) $context->getId()) return null;

		if (($args[0] ?? null) === 'version') {
			array_shift($args);
			$publicationId = (int) array_shift($args);
			$publication = null;
			foreach ((array) $submission->getData('publications') as $candidate) {
				if ((int) $candidate->getId() === $publicationId) $publication = $candidate;
			}
		} else {
			$publication = $submission->getCurrentPublication();
		}
		if (!$publication || (int) $publication->getData('status') !== STATUS_PUBLISHED) return null;

		$galleyPath = array_shift($args);
		$fileId = array_shift($args);
		foreach ((array) $publication->getData('galleys') as $galley) {
			if ((string) $galley->getBestGalleyId() !== $galleyPath) continue;
			if ($galley->getData('urlRemote')) return null;
			$submissionFileId = (int) $galley->getData('submissionFileId');
			if (!$submissionFileId || ($fileId !== null && (int) $fileId !== $submissionFileId)) return null;
			$submissionFile = Services::get('submissionFile')->get($submissionFileId);
			return $submissionFile ? $this->_storedFileSize((int) $submissionFile->getData('fileId')) : null;
		}
		return null;
	}

	/**
	 * Size of an issue galley's file. Only published issues of this journal.
	 *
	 * @param Context $context
	 * @param string[] $args {issue}/{galley}[/{fileId}]
	 * @return int|null
	 */
	private function _issueGalleySize($context, array $args) {
		$issue = DAORegistry::getDAO('IssueDAO')->getByBestId($args[0], $context->getId(), true);
		if (!$issue || !$issue->getPublished()) return null;
		$galley = DAORegistry::getDAO('IssueGalleyDAO')->getByBestId($args[1], $issue->getId());
		if (!$galley) return null;
		if (isset($args[2]) && (int) $args[2] !== (int) $galley->getFileId()) return null;
		$size = (int) $galley->getFileSize();
		return $size > 0 ? $size : null;
	}

	/**
	 * @param int $fileId Row in the `files` table
	 * @return int|null
	 */
	private function _storedFileSize($fileId) {
		if (!$fileId) return null;
		$fileService = Services::get('file');
		$file = $fileService->get($fileId);
		if (!$file || !$fileService->fs->has($file->path)) return null;
		$size = (int) $fileService->fs->getSize($file->path);
		return $size > 0 ? $size : null;
	}

	/**
	 * Decimal separator for the reader's locale ("1,2 MB" in Indonesian, "1.2 MB" in English).
	 * @return string
	 */
	private function _decimalSeparator() {
		$locale = AppLocale::getLocale();
		if (class_exists('NumberFormatter')) {
			$formatter = new NumberFormatter($locale, NumberFormatter::DECIMAL);
			$separator = $formatter->getSymbol(NumberFormatter::DECIMAL_SEPARATOR_SYMBOL);
			if (is_string($separator) && $separator !== '') return $separator;
		}
		return in_array(substr($locale, 0, 2), ['id', 'de', 'fr', 'es', 'it', 'pt', 'nl', 'ru', 'tr', 'pl', 'cs', 'uk', 'ro', 'hu', 'sv', 'da', 'nb', 'fi'], true) ? ',' : '.';
	}

	/**
	 * @param string $extension
	 * @return string
	 */
	private function _blockedMessage($extension) {
		return __('plugins.generic.docxFontStripper.uploadBlocked', ['extension' => $extension]);
	}

	/**
	 * Answer the wizard upload request with a failed-upload JSON message and stop.
	 * @param string $message
	 */
	protected function _sendJsonError($message) {
		import('lib.pkp.classes.core.JSONMessage');
		$json = new JSONMessage(false, $message);
		if (!headers_sent()) header('Content-Type: application/json');
		echo $json->getString();
		exit;
	}

	/**
	 * @param string $route wizard|api
	 * @param string $name
	 * @param string $extension
	 */
	private function _logBlocked($route, $name, $extension) {
		$request = Application::get()->getRequest();
		$user = $request ? $request->getUser() : null;
		$this->_log(sprintf(
			'blocked upload (%s): ext=%s name="%s" userId=%s ip=%s',
			$route,
			$extension,
			preg_replace('/[^\x20-\x7E]/', '?', mb_substr($name, 0, 200)),
			$user ? (int) $user->getId() : '-',
			$request ? $request->getRemoteAddr() : '-'
		));
	}

	/**
	 * Strip embedded fonts from a stored file, if it is a .docx uploaded in this request.
	 * Never throws: a problem here must not break the upload.
	 *
	 * @param int $fileId Row in the `files` table
	 * @param int $submissionFileId For the log line only
	 */
	private function _processFile($fileId, $submissionFileId) {
		if (!$fileId || isset($this->_handledFileIds[$fileId])) return;
		$this->_handledFileIds[$fileId] = true;

		try {
			$fileService = Services::get('file');
			$file = $fileService->get($fileId);
			if (!$file || strtolower(pathinfo($file->path, PATHINFO_EXTENSION)) !== 'docx') return;

			$fs = $fileService->fs;
			if (!$fs->has($file->path)) return;
			$size = (int) $fs->getSize($file->path);

			// Copying a file to another stage re-adds the same fileId; only
			// touch files that were really uploaded in this request.
			if (!$this->_isUploadedInThisRequest($size)) return;

			$stripper = new DocxFontStripper(['downscaleImages' => true, 'imageTargetDpi' => 500]);
			$adapter = $fs->getAdapter();
			if ($adapter instanceof \League\Flysystem\Adapter\Local) {
				$result = $stripper->stripInPlace($adapter->applyPathPrefix($file->path));
			} else {
				$result = $this->_stripRemote($stripper, $fs, $file->path);
			}

			$this->_log(sprintf('submissionFileId=%d fileId=%d %s: %s', $submissionFileId, $fileId, $file->path, $result->summary()));
		} catch (Throwable $e) {
			$this->_log(sprintf('submissionFileId=%d fileId=%d: error, original kept: %s', $submissionFileId, $fileId, $e->getMessage()));
		}
	}

	/**
	 * Non-local Flysystem adapter (e.g. a cloud storage plugin): work on a
	 * local copy and write it back only when stripping succeeded.
	 *
	 * @param DocxFontStripper $stripper
	 * @param \League\Flysystem\FilesystemInterface $fs
	 * @param string $path
	 * @return DocxFontStripResult
	 */
	private function _stripRemote($stripper, $fs, $path) {
		$in = tempnam(sys_get_temp_dir(), 'dfs');
		$out = $in . '.out';
		try {
			$source = $fs->readStream($path);
			$target = fopen($in, 'wb');
			stream_copy_to_stream($source, $target);
			fclose($target);
			if (is_resource($source)) fclose($source);

			$result = $stripper->strip($in, $out);
			if ($result->isStripped()) {
				$stream = fopen($out, 'rb');
				$written = $fs->updateStream($path, $stream);
				if (is_resource($stream)) fclose($stream);
				if (!$written) {
					$failed = new DocxFontStripResult(DocxFontStripResult::FAILED, 'could not write back to storage');
					$failed->sizeBefore = $result->sizeBefore;
					return $failed;
				}
			}
			return $result;
		} finally {
			@unlink($in);
			@unlink($out);
		}
	}

	/**
	 * Whether a file of this size arrived as an HTTP upload in the current request.
	 * @param int $size
	 * @return bool
	 */
	private function _isUploadedInThisRequest($size) {
		foreach ($_FILES as $upload) {
			if (!is_array($upload) || !isset($upload['tmp_name'], $upload['error'])) continue;
			$tmpNames = (array) $upload['tmp_name'];
			$errors = (array) $upload['error'];
			foreach ($tmpNames as $key => $tmpName) {
				if (($errors[$key] ?? null) !== UPLOAD_ERR_OK || !is_string($tmpName)) continue;
				if (is_uploaded_file($tmpName) && (int) filesize($tmpName) === $size) return true;
			}
		}
		return false;
	}

	/**
	 * @param string $message
	 */
	private function _log($message) {
		error_log('[DocxFontStripper] ' . $message);
	}
}

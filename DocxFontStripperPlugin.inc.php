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
 */

import('lib.pkp.classes.plugins.GenericPlugin');
require_once(__DIR__ . '/classes/DocxFontStripper.inc.php');

class DocxFontStripperPlugin extends GenericPlugin {

	/** @var int[] File ids already handled in this request */
	private $_handledFileIds = [];

	/**
	 * @copydoc Plugin::register()
	 */
	public function register($category, $path, $mainContextId = null) {
		$success = parent::register($category, $path, $mainContextId);
		if (!Config::getVar('general', 'installed') || defined('RUNNING_UPGRADE')) return $success;
		if ($success && $this->getEnabled($mainContextId)) {
			HookRegistry::register('SubmissionFile::add', [$this, 'handleSubmissionFileAdd']);
			HookRegistry::register('SubmissionFile::edit', [$this, 'handleSubmissionFileEdit']);
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

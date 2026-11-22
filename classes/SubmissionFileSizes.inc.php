<?php

/**
 * @file classes/SubmissionFileSizes.inc.php
 *
 * Distributed under the GNU GPL v3.
 *
 * @class SubmissionFileSizes
 * @brief Sizes of the files of one submission, for the back end file lists
 *  (workflow, author dashboard, reviewer). Moved here from the
 *  Manuscript-Daffa theme, so the sizes no longer depend on the theme.
 *
 * The sizes are read from the files directory with one query
 * (submission_files + files). Nothing is downloaded or logged as a download.
 *
 * Who gets what:
 *  - journal managers, site admins and editors/assistants assigned to the
 *    submission: every file of the submission;
 *  - authors assigned to the submission: the files authors see in their
 *    dashboard (submission, revisions, copyedited, proofs, review attachments
 *    released to the author) and their own uploads;
 *  - reviewers: only the files of their own review assignment (the files
 *    given to them and their own review attachments);
 *  - everybody else: nothing.
 */

class SubmissionFileSizes {

	/** Sizes from this many bytes up are marked as large (red) in the lists. */
	const LARGE_FILE_BYTES = 5 * 1024 * 1024;

	/** Values of SUBMISSION_FILE_* in pkp-lib 3.3 (lib/pkp/classes/submission/SubmissionFile.inc.php). */
	const STAGE_SUBMISSION = 2;
	const STAGE_REVIEW_ATTACHMENT = 5;
	const STAGE_COPYEDIT = 9;
	const STAGE_PROOF = 10;
	const STAGE_REVIEW_REVISION = 15;

	/** ASSOC_TYPE_REVIEW_ASSIGNMENT in pkp-lib 3.3 */
	const ASSOC_TYPE_REVIEW_ASSIGNMENT = 0x0000205;

	/**
	 * File stages an assigned author can see in the author dashboard.
	 * @return int[]
	 */
	public static function authorFileStages() {
		return [self::STAGE_SUBMISSION, self::STAGE_REVIEW_REVISION, self::STAGE_COPYEDIT, self::STAGE_PROOF];
	}

	/**
	 * Sizes the current user may see for one submission.
	 *
	 * @param int $submissionId
	 * @return array<int,int>|null submission file id => size in bytes, or null
	 *  when the user may not see the submission at all.
	 */
	public static function forSubmission($submissionId) {
		$submissionId = (int) $submissionId;
		$request = Application::get()->getRequest();
		$user = $request->getUser();
		$context = $request->getContext();
		if (!$user || !$context || $submissionId <= 0) return null;

		$submission = Services::get('submission')->get($submissionId);
		if (!$submission || (int) $submission->getData('contextId') !== (int) $context->getId()) return null;

		$db = \Illuminate\Database\Capsule\Manager::class;
		$userId = (int) $user->getId();
		$all = $user->hasRole([ROLE_ID_MANAGER, ROLE_ID_SITE_ADMIN], (int) $context->getId())
			|| $user->hasRole([ROLE_ID_SITE_ADMIN], CONTEXT_SITE);

		$assignedRoles = self::_ints($db::table('stage_assignments as sa')
			->join('user_groups as ug', 'ug.user_group_id', '=', 'sa.user_group_id')
			->where('sa.submission_id', $submissionId)
			->where('sa.user_id', $userId)
			->pluck('ug.role_id'));
		if (array_intersect($assignedRoles, [ROLE_ID_MANAGER, ROLE_ID_SUB_EDITOR, ROLE_ID_ASSISTANT])) $all = true;
		$isAuthor = in_array(ROLE_ID_AUTHOR, $assignedRoles, true);

		$reviewIds = self::_ints($db::table('review_assignments')
			->where('submission_id', $submissionId)
			->where('reviewer_id', $userId)
			->where('declined', 0)
			->where('cancelled', 0)
			->pluck('review_id'));

		if (!$all && !$isAuthor && !$reviewIds) return null;

		$reviewFileIds = [];
		if (!$all && $reviewIds) {
			$reviewFileIds = self::_ints($db::table('review_files')
				->whereIn('review_id', $reviewIds)
				->pluck('submission_file_id'));
		}

		$rows = $db::table('submission_files as sf')
			->join('files as f', 'f.file_id', '=', 'sf.file_id')
			->where('sf.submission_id', $submissionId)
			->select(['sf.submission_file_id', 'sf.file_stage', 'sf.assoc_type', 'sf.assoc_id', 'sf.uploader_user_id', 'sf.viewable', 'f.path'])
			->get();

		$fs = null;
		try {
			$fs = Services::get('file')->fs;
		} catch (Throwable $e) {
			$fs = null;
		}
		$filesDir = rtrim((string) Config::getVar('files', 'files_dir'), '/');

		$sizes = [];
		foreach ($rows as $row) {
			if (!$all && !self::allowed($row, $userId, $isAuthor, $reviewIds, $reviewFileIds)) continue;
			$size = self::_size($fs, $filesDir, (string) $row->path);
			if ($size !== null) $sizes[(int) $row->submission_file_id] = $size;
		}
		return $sizes;
	}

	/**
	 * May an author or reviewer (not a manager or assigned editor) see the size of this file?
	 *
	 * @param object $row submission_file_id, file_stage, assoc_type, assoc_id, uploader_user_id, viewable
	 * @param int $userId
	 * @param bool $isAuthor Assigned to the submission as author
	 * @param int[] $reviewIds The user's active review assignments of this submission
	 * @param int[] $reviewFileIds Submission files given to those review assignments
	 * @return bool
	 */
	public static function allowed($row, $userId, $isAuthor, array $reviewIds, array $reviewFileIds) {
		$id = (int) $row->submission_file_id;
		$stage = (int) $row->file_stage;

		if ($reviewIds) {
			if (in_array($id, $reviewFileIds, true)) return true;
			if ($stage === self::STAGE_REVIEW_ATTACHMENT
				&& (int) $row->assoc_type === self::ASSOC_TYPE_REVIEW_ASSIGNMENT
				&& in_array((int) $row->assoc_id, $reviewIds, true)) {
				return true;
			}
		}

		if ($isAuthor) {
			if ((int) $row->uploader_user_id === (int) $userId) return true;
			if (in_array($stage, self::authorFileStages(), true)) return true;
			if ($stage === self::STAGE_REVIEW_ATTACHMENT && (int) $row->viewable === 1) return true;
		}

		return false;
	}

	/**
	 * Size of a stored file: through Flysystem (any storage adapter), else
	 * directly in files_dir.
	 *
	 * @param \League\Flysystem\FilesystemInterface|null $fs
	 * @param string $filesDir
	 * @param string $path Relative to files_dir
	 * @return int|null
	 */
	private static function _size($fs, $filesDir, $path) {
		$size = null;
		try {
			if ($fs) $size = $fs->getSize($path);
		} catch (Throwable $e) {
			$size = null;
		}
		if (!is_int($size) && $filesDir !== '' && strpos($path, '..') === false) {
			$full = $filesDir . '/' . ltrim($path, '/');
			if (is_file($full)) $size = @filesize($full);
		}
		return is_int($size) && $size >= 0 ? $size : null;
	}

	/**
	 * @param iterable $values
	 * @return int[]
	 */
	private static function _ints($values) {
		$out = [];
		foreach ($values as $value) $out[] = (int) $value;
		return $out;
	}
}

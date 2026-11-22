<?php

/**
 * @file classes/FileSizeLookup.php
 *
 * Distributed under the GNU GPL v3.
 *
 * @class FileSizeLookup
 * @brief OJS 3.5: sizes of the files of one submission for the back end file
 *  lists. Moved here from the Manuscript-Daffa theme (classes/FileSizes.php).
 *  Who may see which file is decided by SubmissionFileSizes::allowed(),
 *  shared with the OJS 3.3 build.
 *
 * The sizes are read from storage with one query (submission_files + files).
 * Nothing is downloaded or logged as a download.
 */

namespace APP\plugins\generic\docxFontStripper\classes;

use APP\core\Application;
use APP\facades\Repo;
use Illuminate\Support\Facades\DB;
use PKP\config\Config;
use PKP\security\Role;
use SubmissionFileSizes;
use Throwable;

class FileSizeLookup
{
    /**
     * @return array<int,int>|null submission file id => size in bytes, or
     *  null when the user may not see the submission at all.
     */
    public static function forSubmission(int $submissionId): ?array
    {
        $request = Application::get()->getRequest();
        $user = $request->getUser();
        $context = $request->getContext();
        if (!$user || !$context || $submissionId <= 0) {
            return null;
        }

        $submission = Repo::submission()->get($submissionId);
        if (!$submission || (int) $submission->getData('contextId') !== (int) $context->getId()) {
            return null;
        }

        $userId = (int) $user->getId();
        $all = $user->hasRole([Role::ROLE_ID_MANAGER, Role::ROLE_ID_SITE_ADMIN], (int) $context->getId())
            || $user->hasRole([Role::ROLE_ID_SITE_ADMIN], Application::SITE_CONTEXT_ID);

        $assignedRoles = DB::table('stage_assignments as sa')
            ->join('user_groups as ug', 'ug.user_group_id', '=', 'sa.user_group_id')
            ->where('sa.submission_id', $submissionId)
            ->where('sa.user_id', $userId)
            ->pluck('ug.role_id')
            ->map(fn ($r) => (int) $r)
            ->all();
        if (array_intersect($assignedRoles, [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR, Role::ROLE_ID_ASSISTANT])) {
            $all = true;
        }
        $isAuthor = in_array(Role::ROLE_ID_AUTHOR, $assignedRoles, true);

        $reviewIds = DB::table('review_assignments')
            ->where('submission_id', $submissionId)
            ->where('reviewer_id', $userId)
            ->where('declined', 0)
            ->where('cancelled', 0)
            ->pluck('review_id')
            ->map(fn ($r) => (int) $r)
            ->all();

        if (!$all && !$isAuthor && !$reviewIds) {
            return null;
        }

        $reviewFileIds = [];
        if (!$all && $reviewIds) {
            $reviewFileIds = DB::table('review_files')
                ->whereIn('review_id', $reviewIds)
                ->pluck('submission_file_id')
                ->map(fn ($r) => (int) $r)
                ->all();
        }

        $rows = DB::table('submission_files as sf')
            ->join('files as f', 'f.file_id', '=', 'sf.file_id')
            ->where('sf.submission_id', $submissionId)
            ->select(['sf.submission_file_id', 'sf.file_stage', 'sf.assoc_type', 'sf.assoc_id', 'sf.uploader_user_id', 'sf.viewable', 'f.path'])
            ->get();

        $fs = null;
        try {
            $fs = app()->get('file')->fs;
        } catch (Throwable $e) {
            $fs = null;
        }
        $filesDir = rtrim((string) Config::getVar('files', 'files_dir'), '/');

        $sizes = [];
        foreach ($rows as $row) {
            if (!$all && !SubmissionFileSizes::allowed($row, $userId, $isAuthor, $reviewIds, $reviewFileIds)) {
                continue;
            }
            $size = self::size($fs, $filesDir, (string) $row->path);
            if ($size !== null) {
                $sizes[(int) $row->submission_file_id] = $size;
            }
        }
        return $sizes;
    }

    /**
     * Size through Flysystem (any storage), else directly in files_dir.
     *
     * @param \League\Flysystem\Filesystem|null $fs
     */
    private static function size($fs, string $filesDir, string $path): ?int
    {
        $size = null;
        try {
            if ($fs) {
                $size = $fs->fileSize($path);
            }
        } catch (Throwable $e) {
            $size = null;
        }
        if (!is_int($size) && $filesDir !== '' && !str_contains($path, '..')) {
            $full = $filesDir . '/' . ltrim($path, '/');
            if (is_file($full)) {
                $size = @filesize($full);
            }
        }
        return is_int($size) && $size >= 0 ? $size : null;
    }
}

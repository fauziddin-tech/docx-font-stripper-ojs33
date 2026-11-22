<?php

/**
 * @file DocxFontStripperPlugin.php
 *
 * Distributed under the GNU GPL v3.
 *
 * @class DocxFontStripperPlugin
 * @brief OJS 3.5 build of Docx Font Stripper. Same features as the OJS 3.3
 *  build (DocxFontStripperPlugin.inc.php); the cleaning, upload guard and
 *  galley size classes in classes/ are shared by both builds.
 *
 * Hooks (pkp-lib 3_5_0-6):
 *  - SubmissionFile::add  classes/submissionFile/Repository.php:275
 *    New files: upload wizard and REST API (POST /submissions/{id}/files).
 *  - SubmissionFile::edit classes/submissionFile/Repository.php:358
 *    A new file for an existing submission file (wizard revision, REST PUT).
 *  Both fire after app()->get('file')->add() has stored the uploaded file, so
 *  the stored file is cleaned in place.
 *
 * Upload guard (classes/UploadGuard.inc.php) rejects files a web server could
 * execute and Windows programs before they are kept:
 *  - submissionfilesuploadform::validate classes/form/Form.php:311
 *    Upload wizard (FileUploadWizardHandler::uploadFile validates before the
 *    file is stored).
 *  - SubmissionFile::validate classes/submissionFile/Repository.php:252
 *    REST API. POST stores the file first and deletes it on errors
 *    (PKPSubmissionFileController::add); PUT validates before storing it,
 *    so the uploaded name in $_FILES is checked as well.
 *
 * Galley file size (classes/GalleyFileSize.inc.php): TemplateManager::display
 * registers a named Smarty output filter that adds the size to galley links
 * on reader pages, whatever the theme.
 *
 * Back end file sizes (classes/FileSizeLookup.php, js/fileSizes.js):
 * TemplateManager::display adds js/fileSizes.js to back end pages, and
 * answers ?docxFontStripperFileSizes=1&submissionId=N on the journal's
 * "About this Publishing System" page with JSON instead of the page.
 */

namespace APP\plugins\generic\docxFontStripper;

require_once __DIR__ . '/classes/DocxFontStripper.inc.php';
require_once __DIR__ . '/classes/UploadGuard.inc.php';
require_once __DIR__ . '/classes/GalleyFileSize.inc.php';
require_once __DIR__ . '/classes/SubmissionFileSizes.inc.php';

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\docxFontStripper\classes\FileSizeLookup;
use APP\template\TemplateManager;
use DocxFontStripper;
use DocxFontStripResult;
use GalleyFileSize;
use NumberFormatter;
use PKP\config\Config;
use PKP\core\JSONMessage;
use PKP\core\PKPRequest;
use PKP\db\DAORegistry;
use PKP\facades\Locale;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\submission\PKPSubmission;
use SubmissionFileSizes;
use Throwable;
use UploadGuard;

class DocxFontStripperPlugin extends GenericPlugin
{
    /** Request parameter of the back end file size lookup */
    public const FILE_SIZE_PARAM = 'docxFontStripperFileSizes';

    /** @var array<int,bool> File ids already handled in this request */
    private array $handledFileIds = [];

    /** Whether the galley size output filter is registered */
    private bool $sizeFilterRegistered = false;

    /**
     * @copydoc Plugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if (!Config::getVar('general', 'installed') || defined('RUNNING_UPGRADE')) {
            return $success;
        }
        if ($success && $this->getEnabled($mainContextId)) {
            Hook::add('SubmissionFile::add', $this->handleSubmissionFileAdd(...));
            Hook::add('SubmissionFile::edit', $this->handleSubmissionFileEdit(...));
            Hook::add('submissionfilesuploadform::validate', $this->guardWizardUpload(...));
            Hook::add('SubmissionFile::validate', $this->guardApiUpload(...));
            // Must run before registerGalleySizeFilter: answering the size
            // lookup ends the hook chain.
            Hook::add('TemplateManager::display', $this->handleFileSizes(...));
            Hook::add('TemplateManager::display', $this->registerGalleySizeFilter(...));
        }
        return $success;
    }

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName()
    {
        return __('plugins.generic.docxFontStripper.displayName');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription()
    {
        return __('plugins.generic.docxFontStripper.description');
    }

    /*
     * Font stripping
     */

    /**
     * SubmissionFile::add callback.
     *
     * @param array $args [SubmissionFile $submissionFile]
     */
    public function handleSubmissionFileAdd(string $hookName, array $args): bool
    {
        $submissionFile = $args[0];
        $this->processFile((int) $submissionFile->getData('fileId'), (int) $submissionFile->getId());
        return Hook::CONTINUE;
    }

    /**
     * SubmissionFile::edit callback. Only acts when a new file was uploaded.
     *
     * @param array $args [SubmissionFile $newSubmissionFile, SubmissionFile $submissionFile, array $params]
     */
    public function handleSubmissionFileEdit(string $hookName, array $args): bool
    {
        $params = $args[2];
        if (!empty($params['fileId']) && (int) $params['fileId'] !== (int) $args[1]->getData('fileId')) {
            $this->processFile((int) $params['fileId'], (int) $args[1]->getId());
        }
        return Hook::CONTINUE;
    }

    /**
     * Strip embedded fonts from a stored file, if it is a .docx uploaded in
     * this request. Never throws: a problem here must not break the upload.
     *
     * @param int $fileId Row in the `files` table
     * @param int $submissionFileId For the log line only
     */
    private function processFile(int $fileId, int $submissionFileId): void
    {
        if (!$fileId || isset($this->handledFileIds[$fileId])) {
            return;
        }
        $this->handledFileIds[$fileId] = true;

        try {
            $fileService = app()->get('file');
            $file = $fileService->get($fileId);
            if (!$file || strtolower(pathinfo($file->path, PATHINFO_EXTENSION)) !== 'docx') {
                return;
            }

            $fs = $fileService->fs;
            if (!$fs->fileExists($file->path)) {
                return;
            }
            $size = (int) $fs->fileSize($file->path);

            // Copying a file to another stage re-adds the same fileId; only
            // touch files that were really uploaded in this request.
            if (!$this->isUploadedInThisRequest($size)) {
                return;
            }

            $stripper = new DocxFontStripper(['downscaleImages' => true, 'imageTargetDpi' => 500]);
            $local = $this->localPath($file->path);
            $result = $local !== null
                ? $stripper->stripInPlace($local)
                : $this->stripRemote($stripper, $fs, $file->path);

            $this->log(sprintf('submissionFileId=%d fileId=%d %s: %s', $submissionFileId, $fileId, $file->path, $result->summary()));
        } catch (Throwable $e) {
            $this->log(sprintf('submissionFileId=%d fileId=%d: error, original kept: %s', $submissionFileId, $fileId, $e->getMessage()));
        }
    }

    /**
     * Absolute path of a stored file when it lives in files_dir on this
     * server (the default local storage of PKPFileService), else null.
     */
    private function localPath(string $path): ?string
    {
        $filesDir = rtrim((string) Config::getVar('files', 'files_dir'), '/');
        if ($filesDir === '' || str_contains($path, '..')) {
            return null;
        }
        $full = $filesDir . '/' . ltrim($path, '/');
        return is_file($full) ? $full : null;
    }

    /**
     * Storage outside files_dir: work on a local copy and write it back only
     * when stripping succeeded.
     *
     * @param \League\Flysystem\Filesystem $fs
     */
    private function stripRemote(DocxFontStripper $stripper, $fs, string $path): DocxFontStripResult
    {
        $in = tempnam(sys_get_temp_dir(), 'dfs');
        $out = $in . '.out';
        try {
            $source = $fs->readStream($path);
            $target = fopen($in, 'wb');
            stream_copy_to_stream($source, $target);
            fclose($target);
            if (is_resource($source)) {
                fclose($source);
            }

            $result = $stripper->strip($in, $out);
            if ($result->isStripped()) {
                $stream = fopen($out, 'rb');
                try {
                    $fs->writeStream($path, $stream);
                } catch (Throwable $e) {
                    $failed = new DocxFontStripResult(DocxFontStripResult::FAILED, 'could not write back to storage: ' . $e->getMessage());
                    $failed->sizeBefore = $result->sizeBefore;
                    return $failed;
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
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
     */
    private function isUploadedInThisRequest(int $size): bool
    {
        foreach ($_FILES as $upload) {
            if (!is_array($upload) || !isset($upload['tmp_name'], $upload['error'])) {
                continue;
            }
            $tmpNames = (array) $upload['tmp_name'];
            $errors = (array) $upload['error'];
            foreach ($tmpNames as $key => $tmpName) {
                if (($errors[$key] ?? null) !== UPLOAD_ERR_OK || !is_string($tmpName)) {
                    continue;
                }
                if (is_uploaded_file($tmpName) && (int) filesize($tmpName) === $size) {
                    return true;
                }
            }
        }
        return false;
    }

    /*
     * Upload guard
     */

    /**
     * submissionfilesuploadform::validate callback (upload wizard).
     *
     * The wizard's JavaScript expects an uploaded file whenever the response
     * status is true, so a blocked file is answered like a failed upload
     * (status false), which the browser shows as a message. Nothing has been
     * stored at this point.
     *
     * @param array $args [Form $form, mixed &$value]
     */
    public function guardWizardUpload(string $hookName, array $args): bool
    {
        $name = $_FILES['uploadedFile']['name'] ?? null;
        if (!is_string($name)) {
            return Hook::CONTINUE;
        }
        $extension = (new UploadGuard())->blockedExtension($name);
        if ($extension === null) {
            return Hook::CONTINUE;
        }

        $this->logBlocked('wizard', $name, $extension);
        $message = $this->blockedMessage($extension);
        $args[0]->addError('uploadedFile', $message);
        $this->sendJsonError($message);
        return Hook::CONTINUE;
    }

    /**
     * SubmissionFile::validate callback (REST API upload or new file).
     *
     * @param array $args [array &$errors, ?SubmissionFile $object, array $props, array $allowedLocales, string $primaryLocale]
     */
    public function guardApiUpload(string $hookName, array $args): bool
    {
        $errors = &$args[0];
        $props = $args[2];

        $names = [];
        $upload = $_FILES['file']['name'] ?? null;
        if (is_string($upload)) {
            $names[] = $upload;
        }
        if (!empty($props['fileId'])) {
            try {
                $file = app()->get('file')->get((int) $props['fileId']);
                if ($file) {
                    $names[] = $file->path;
                }
            } catch (Throwable $e) {
                // Unknown file id: OJS reports that itself.
            }
        }

        $guard = new UploadGuard();
        foreach ($names as $name) {
            $extension = $guard->blockedExtension($name);
            if ($extension === null) {
                continue;
            }
            $this->logBlocked('api', $name, $extension);
            if (!is_array($errors)) {
                $errors = [];
            }
            $errors['fileId'] = [$this->blockedMessage($extension)];
            break;
        }
        return Hook::CONTINUE;
    }

    private function blockedMessage(string $extension): string
    {
        return __('plugins.generic.docxFontStripper.uploadBlocked', ['extension' => $extension]);
    }

    /**
     * Answer the wizard upload request with a failed-upload JSON message and stop.
     */
    protected function sendJsonError(string $message): void
    {
        $json = new JSONMessage(false, $message);
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo $json->getString();
        exit;
    }

    private function logBlocked(string $route, string $name, string $extension): void
    {
        $request = Application::get()->getRequest();
        $user = $request ? $request->getUser() : null;
        $this->log(sprintf(
            'blocked upload (%s): ext=%s name="%s" userId=%s ip=%s',
            $route,
            $extension,
            preg_replace('/[^\x20-\x7E]/', '?', mb_substr($name, 0, 200)),
            $user ? (int) $user->getId() : '-',
            $request ? $request->getRemoteAddr() : '-'
        ));
    }

    /*
     * Back end file sizes
     */

    /**
     * TemplateManager::display callback for the back end file sizes.
     *  - With ?docxFontStripperFileSizes=1: answer with the JSON sizes of
     *    ?submissionId instead of the page (stops the hook chain).
     *  - Otherwise, for logged-in users in a journal: add js/fileSizes.js to
     *    back end pages (the script is not loaded on reader pages).
     *
     * @param array $args [TemplateManager $templateMgr, string &$template, ?string &$output]
     */
    public function handleFileSizes(string $hookName, array $args): bool
    {
        $request = Application::get()->getRequest();
        if (!$request) {
            return Hook::CONTINUE;
        }

        if ($request->getUserVar(self::FILE_SIZE_PARAM) !== null) {
            $output = &$args[2];
            $output = $this->fileSizeResponse($request);
            return Hook::ABORT;
        }

        try {
            $this->addFileSizeScript($args[0], $request);
        } catch (Throwable $e) {
            $this->log('file size script: ' . $e->getMessage());
        }
        return Hook::CONTINUE;
    }

    private function addFileSizeScript(TemplateManager $templateMgr, PKPRequest $request): void
    {
        $context = $request->getContext();
        if (!$request->getUser() || !$context) {
            return;
        }

        $config = [
            'url' => $request->getDispatcher()->url($request, Application::ROUTE_PAGE, $context->getPath(), 'about', 'aboutThisPublishingSystem', null, [self::FILE_SIZE_PARAM => 1]),
            'largeBytes' => SubmissionFileSizes::LARGE_FILE_BYTES,
            'largeLabel' => __('plugins.generic.docxFontStripper.largeFile'),
            'decimalSeparator' => $this->decimalSeparator(),
        ];
        $templateMgr->addJavaScript(
            'docxFontStripperFileSizesConfig',
            'window.docxFontStripperFileSizes = ' . json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . ';',
            ['inline' => true, 'contexts' => 'backend']
        );
        $script = __DIR__ . '/js/fileSizes.js';
        $templateMgr->addJavaScript(
            'docxFontStripperFileSizes',
            $request->getBaseUrl() . '/' . $this->getPluginPath() . '/js/fileSizes.js?v=' . (int) @filemtime($script),
            ['contexts' => 'backend']
        );
    }

    /**
     * JSON answer of the back end file size lookup. 403 when the user may
     * not see the submission.
     */
    private function fileSizeResponse(PKPRequest $request): string
    {
        $sizes = null;
        try {
            $sizes = FileSizeLookup::forSubmission((int) $request->getUserVar('submissionId'));
        } catch (Throwable $e) {
            $this->log('file sizes: ' . $e->getMessage());
        }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, private');
            header('X-Robots-Tag: noindex');
            if ($sizes === null) {
                http_response_code(403);
            }
        }
        return json_encode(['sizes' => (object) ($sizes ?? [])]);
    }

    /*
     * Galley file size on reader pages
     */

    /**
     * TemplateManager::display callback: add file sizes to galley links on
     * reader pages. Independent of the theme, because the filter works on the
     * page's final HTML instead of a theme's galley_link.tpl.
     *
     * @param array $args [TemplateManager $templateMgr, string &$template, ?string &$output]
     */
    public function registerGalleySizeFilter(string $hookName, array $args): bool
    {
        if ($this->sizeFilterRegistered) {
            return Hook::CONTINUE;
        }
        $templateMgr = $args[0];
        $template = $args[1];
        if (!is_string($template) || !$this->isReaderTemplate($template)) {
            return Hook::CONTINUE;
        }

        $request = Application::get()->getRequest();
        $context = $request ? $request->getContext() : null;
        if (!$context) {
            return Hook::CONTINUE;
        }

        $this->sizeFilterRegistered = true;
        // Smarty keys output filters by name and names every closure "closure",
        // so an unnamed closure would replace, or be replaced by, another
        // plugin's. Register under our own name.
        $templateMgr->registerFilter('output', function ($html) use ($request, $context) {
            try {
                return $this->galleyFileSize($request, $context)->annotate($html);
            } catch (Throwable $e) {
                $this->log('galley file size: ' . $e->getMessage());
                return $html;
            }
        }, 'docxFontStripperGalleySize');
        return Hook::CONTINUE;
    }

    /**
     * Reader-facing templates: OJS frontend pages and plugin templates shown
     * to readers (theme pages, PDF.js galley viewer, static pages, ...).
     */
    private function isReaderTemplate(string $template): bool
    {
        if (str_starts_with($template, 'frontend/')) {
            return true;
        }
        if (!str_contains($template, ':')) {
            return false;
        }
        $request = Application::get()->getRequest();
        $page = $request ? (string) $request->getRequestedPage() : '';
        $backendPages = ['dashboard', 'submissions', 'workflow', 'authorDashboard', 'reviewer', 'management', 'manageIssues', 'stats', 'admin', 'tools', 'settings', 'user', 'decision', 'invitation'];
        return !in_array($page, $backendPages, true);
    }

    private function galleyFileSize(PKPRequest $request, $context): GalleyFileSize
    {
        $prefixes = [];
        foreach (['article', 'issue'] as $page) {
            foreach (['view', 'download'] as $op) {
                $prefixes[$page][] = $request->url($context->getPath(), $page, $op);
            }
        }
        return new GalleyFileSize($prefixes, function ($page, $args) use ($context) {
            try {
                return $page === 'article'
                    ? $this->articleGalleySize($context, $args)
                    : $this->issueGalleySize($context, $args);
            } catch (Throwable $e) {
                return null;
            }
        }, $this->decimalSeparator());
    }

    /**
     * Size of an article galley's file, for a link with these path arguments.
     * Only galleys of published publications in this journal.
     *
     * @param string[] $args {submission}/{galley}[/{fileId}] or {submission}/version/{publicationId}/{galley}[/{fileId}]
     */
    private function articleGalleySize($context, array $args): ?int
    {
        $submissionPath = (string) array_shift($args);
        $contextId = (int) $context->getId();
        $submission = Repo::submission()->getByUrlPath($submissionPath, $contextId);
        if (!$submission && ctype_digit($submissionPath)) {
            $submission = Repo::submission()->get((int) $submissionPath, $contextId);
        }
        if (!$submission || (int) $submission->getData('contextId') !== $contextId) {
            return null;
        }

        $publication = null;
        if (($args[0] ?? null) === 'version') {
            array_shift($args);
            $publicationId = (int) array_shift($args);
            foreach ($submission->getData('publications') ?? [] as $candidate) {
                if ((int) $candidate->getId() === $publicationId) {
                    $publication = $candidate;
                }
            }
        } else {
            $publication = $submission->getCurrentPublication();
        }
        if (!$publication || (int) $publication->getData('status') !== PKPSubmission::STATUS_PUBLISHED) {
            return null;
        }

        $galleyPath = (string) array_shift($args);
        $fileId = array_shift($args);
        foreach ($publication->getData('galleys') ?? [] as $galley) {
            if ((string) $galley->getBestGalleyId() !== $galleyPath) {
                continue;
            }
            if ($galley->getData('urlRemote')) {
                return null;
            }
            $submissionFileId = (int) $galley->getData('submissionFileId');
            if (!$submissionFileId || ($fileId !== null && (int) $fileId !== $submissionFileId)) {
                return null;
            }
            $submissionFile = Repo::submissionFile()->get($submissionFileId);
            return $submissionFile ? $this->storedFileSize((int) $submissionFile->getData('fileId')) : null;
        }
        return null;
    }

    /**
     * Size of an issue galley's file. Only published issues of this journal.
     *
     * @param string[] $args {issue}/{galley}[/{fileId}]
     */
    private function issueGalleySize($context, array $args): ?int
    {
        $issue = Repo::issue()->getByBestId((string) $args[0], (int) $context->getId());
        if (!$issue || !$issue->getPublished()) {
            return null;
        }
        /** @var \APP\issue\IssueGalleyDAO $galleyDao */
        $galleyDao = DAORegistry::getDAO('IssueGalleyDAO');
        $galley = $galleyDao->getByBestId((string) $args[1], (int) $issue->getId());
        if (!$galley) {
            return null;
        }
        if (isset($args[2]) && (int) $args[2] !== (int) $galley->getFileId()) {
            return null;
        }
        $size = (int) $galley->getFileSize();
        return $size > 0 ? $size : null;
    }

    private function storedFileSize(int $fileId): ?int
    {
        if (!$fileId) {
            return null;
        }
        $fileService = app()->get('file');
        $file = $fileService->get($fileId);
        if (!$file || !$fileService->fs->fileExists($file->path)) {
            return null;
        }
        $size = (int) $fileService->fs->fileSize($file->path);
        return $size > 0 ? $size : null;
    }

    /**
     * Decimal separator for the reader's locale ("1,2 MB" in Indonesian,
     * "1.2 MB" in English).
     */
    private function decimalSeparator(): string
    {
        $locale = Locale::getLocale();
        if (class_exists(NumberFormatter::class)) {
            $formatter = new NumberFormatter($locale, NumberFormatter::DECIMAL);
            $separator = $formatter->getSymbol(NumberFormatter::DECIMAL_SEPARATOR_SYMBOL);
            if (is_string($separator) && $separator !== '') {
                return $separator;
            }
        }
        return in_array(substr($locale, 0, 2), ['id', 'de', 'fr', 'es', 'it', 'pt', 'nl', 'ru', 'tr', 'pl', 'cs', 'uk', 'ro', 'hu', 'sv', 'da', 'nb', 'fi'], true) ? ',' : '.';
    }

    private function log(string $message): void
    {
        error_log('[DocxFontStripper] ' . $message);
    }
}

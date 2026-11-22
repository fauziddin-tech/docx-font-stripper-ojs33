<?php

use PHPUnit\Framework\TestCase;

class SubmissionFileSizesTest extends TestCase {

	private const USER = 7;

	private function row(int $id, int $stage, array $extra = []): object {
		return (object) array_merge([
			'submission_file_id' => $id,
			'file_stage' => $stage,
			'assoc_type' => null,
			'assoc_id' => null,
			'uploader_user_id' => 99,
			'viewable' => 0,
		], $extra);
	}

	public function testStageValuesMatchPkpLib33(): void {
		// lib/pkp/classes/submission/SubmissionFile.inc.php and PKPApplication.inc.php (3_3_0-22)
		$this->assertSame(2, SubmissionFileSizes::STAGE_SUBMISSION);
		$this->assertSame(5, SubmissionFileSizes::STAGE_REVIEW_ATTACHMENT);
		$this->assertSame(9, SubmissionFileSizes::STAGE_COPYEDIT);
		$this->assertSame(10, SubmissionFileSizes::STAGE_PROOF);
		$this->assertSame(15, SubmissionFileSizes::STAGE_REVIEW_REVISION);
		$this->assertSame(0x205, SubmissionFileSizes::ASSOC_TYPE_REVIEW_ASSIGNMENT);
	}

	/**
	 * @dataProvider authorCases
	 */
	public function testAuthor(object $row, bool $expected): void {
		$this->assertSame($expected, SubmissionFileSizes::allowed($row, self::USER, true, [], []));
	}

	public function authorCases(): array {
		return [
			'submission file' => [$this->row(1, 2), true],
			'revision' => [$this->row(2, 15), true],
			'copyedited' => [$this->row(3, 9), true],
			'proof' => [$this->row(4, 10), true],
			'review file given to reviewers' => [$this->row(5, 4), false],
			'production ready' => [$this->row(6, 11), false],
			'review attachment shared with the author' => [$this->row(7, 5, ['viewable' => 1]), true],
			'review attachment not shared' => [$this->row(8, 5, ['viewable' => 0]), false],
			'own upload in another stage' => [$this->row(9, 18, ['uploader_user_id' => self::USER]), true],
		];
	}

	public function testReviewerSeesOnlyOwnReviewFiles(): void {
		$reviewIds = [40];
		$reviewFileIds = [5];
		$check = function (object $row) use ($reviewIds, $reviewFileIds) {
			return SubmissionFileSizes::allowed($row, self::USER, false, $reviewIds, $reviewFileIds);
		};
		$this->assertTrue($check($this->row(5, 4)), 'file given to the review');
		$this->assertFalse($check($this->row(6, 4)), 'review file of another round or reviewer');
		$this->assertTrue($check($this->row(7, 5, ['assoc_type' => 0x205, 'assoc_id' => 40])), 'own review attachment');
		$this->assertFalse($check($this->row(8, 5, ['assoc_type' => 0x205, 'assoc_id' => 41])), 'other reviewer\'s attachment');
		$this->assertFalse($check($this->row(1, 2)), 'submission file');
		$this->assertFalse($check($this->row(3, 9)), 'copyedited file');
	}

	public function testNobodyElse(): void {
		$this->assertFalse(SubmissionFileSizes::allowed($this->row(1, 2), self::USER, false, [], []));
		$this->assertFalse(SubmissionFileSizes::allowed($this->row(1, 2, ['uploader_user_id' => self::USER]), self::USER, false, [], []));
	}

	public function testLargeFileThreshold(): void {
		$this->assertSame(5 * 1024 * 1024, SubmissionFileSizes::LARGE_FILE_BYTES);
	}
}

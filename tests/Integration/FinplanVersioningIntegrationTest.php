<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Spec §10 integration: versioned Finanzierungsplan transitions (ADR-02).
 * Covers: first activation, draft cloning, CAS stale-base refusal on the
 * second activation (the concurrency contract — the loser of two racing
 * activations must see STALE_BASE, never a merged plan), immutability of
 * superseded versions, and exactly-one-active invariant.
 */

namespace OCA\NachweisCheck\Tests\Integration;

use OCA\NachweisCheck\Exception\ConflictException;
use OCA\NachweisCheck\Exception\ValidationException;
use OCA\NachweisCheck\Service\FinplanService;
use Test\TestCase;

class FinplanVersioningIntegrationTest extends TestCase
{
	use NwFixture;

	protected function setUp(): void
	{
		parent::setUp();
		$this->nwInit();
	}

	protected function tearDown(): void
	{
		$this->nwPurge();
		parent::tearDown();
	}

	public function testFullVersionLifecycleWithCasAndImmutability(): void
	{
		$uid = '__nw_it_plan__';
		$finplan = \OCP\Server::get(FinplanService::class);
		$p = $this->nwProject($uid);

		// v1 draft → activate with base 0 (nothing active yet)
		$v1 = $this->nwActivatePlan($p, [
			['code' => 'PK', 'title' => 'Personal', 'kind' => 'personal', 'budget_minor' => 50000],
			['code' => 'SK', 'title' => 'Sach', 'kind' => 'sach', 'budget_minor' => 20000],
		], $uid);
		self::assertSame('active', $v1->getState());
		self::assertSame(1, $v1->getVersion());

		// Frozen: positions on the active version refuse edits
		try {
			$finplan->replacePositions($v1, [
				['code' => 'PK', 'title' => 'P', 'kind' => 'personal', 'budget_minor' => 1],
			], $uid);
			self::fail('active plan must be immutable');
		} catch (ConflictException $e) {
			self::assertSame('IMMUTABLE_PLAN', $e->getErrorCode());
		}

		// New draft clones the active positions
		$draft = $finplan->newDraft($p->getId(), $uid, 'Umschichtung');
		self::assertSame(2, $draft->getVersion());
		self::assertSame(1, $draft->getBaseVersion());
		self::assertCount(2, $finplan->positions($draft));

		// Idempotent: a second newDraft returns the same draft (the racing
		// caller must never fork a second draft version)
		$same = $finplan->newDraft($p->getId(), $uid, 'other note');
		self::assertSame($draft->getId(), $same->getId());

		$finplan->replacePositions($draft, [
			['code' => 'PK', 'title' => 'Personal', 'kind' => 'personal', 'budget_minor' => 60000],
			['code' => 'SK', 'title' => 'Sach', 'kind' => 'sach', 'budget_minor' => 20000],
		], $uid);

		// CAS loser: declaring base 0 while v1 is active → STALE_BASE
		try {
			$finplan->activate($draft, 0, $uid);
			self::fail('stale base must refuse');
		} catch (ConflictException $e) {
			self::assertSame('STALE_BASE', $e->getErrorCode());
		}
		self::assertSame('draft', $draft->getState(), 'refused activation must keep the draft');

		// CAS winner: base 1 supersedes v1 — exactly one active version
		$v2 = $finplan->activate($draft, 1, $uid);
		self::assertSame('active', $v2->getState());
		self::assertSame('superseded', $finplan->getVersionOrFail($p->getId(), $v1->getId())->getState());
		self::assertSame(2, $finplan->activeFor($p->getId())->getVersion());

		$activeCount = 0;
		foreach ($finplan->listVersions($p->getId()) as $v) {
			if ($v->getState() === 'active') {
				$activeCount++;
			}
		}
		self::assertSame(1, $activeCount, 'exactly one active plan version must exist');

		// Superseded stays frozen too
		try {
			$finplan->replacePositions($v1, [
				['code' => 'X', 'title' => 'X', 'kind' => 'sach', 'budget_minor' => 1],
			], $uid);
			self::fail('superseded plan must be immutable');
		} catch (ConflictException $e) {
			self::assertSame('IMMUTABLE_PLAN', $e->getErrorCode());
		}
	}

	public function testEmptyPlanCannotActivate(): void
	{
		$uid = '__nw_it_plan2__';
		$finplan = \OCP\Server::get(FinplanService::class);
		$p = $this->nwProject($uid);
		$draft = $finplan->draftFor($p->getId());
		self::assertNotNull($draft);
		// wipe seeded positions → activation must refuse, not mint an empty plan
		$pos = \OCP\Server::get(\OCA\NachweisCheck\Db\PositionMapper::class);
		foreach ($pos->findByPlanVer($draft->getId()) as $row) {
			$pos->delete($row);
		}
		try {
			$finplan->activate($draft, 0, $uid);
			self::fail('empty plan must refuse');
		} catch (ValidationException $e) {
			self::assertArrayHasKey('positions', $e->getFields());
		}
	}
}

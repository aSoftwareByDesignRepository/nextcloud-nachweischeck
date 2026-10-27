<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Fristen-Engine persistence (MH-10). Recompute is idempotent: dedupe_key
 * is a unique column, so re-running after edits updates the set without
 * duplicates; obsolete rows (dates moved) are removed when no longer
 * computed.
 */

namespace OCA\NachweisCheck\Service;

use OCA\NachweisCheck\Db\Deadline;
use OCA\NachweisCheck\Db\DeadlineMapper;
use OCA\NachweisCheck\Db\Project;
use OCA\NachweisCheck\Db\WorkPackageMapper;
use OCA\NachweisCheck\Support\DeadlineCalculator;

class DeadlineService
{
	public function __construct(
		private readonly DeadlineMapper $mapper,
		private readonly WorkPackageMapper $apMapper,
		private readonly TemplateService $templates,
		private readonly ConcurrencyGuard $guard,
	) {
	}

	public function recompute(Project $project): void
	{
		// dedupe_key is unique — without the project lock a racing recompute
		// (activate vs. job wake) hits a unique violation mid-set.
		$this->guard->run(ConcurrencyGuard::projectKey($project->getId()), function () use ($project): void {
			$this->recomputeLocked($project);
		});
	}

	private function recomputeLocked(Project $project): void
	{
		$flags = $this->templates->ruleFlags($project->getTemplateKey());
		if ($project->getFinancingMode() === 'festbetrag') {
			$flags['festbetrag'] = true;
			$flags['einzelansatz_20pct'] = false;
		}
		$apEnds = [];
		foreach ($this->apMapper->findByProject($project->getId()) as $ap) {
			$apEnds[] = [
				'id' => $ap->getId(),
				'end_date' => $ap->getEndDate()?->format('Y-m-d'),
			];
		}
		$wanted = DeadlineCalculator::forProject(
			$project->getId(),
			$project->getBewStart()?->format('Y-m-d'),
			$project->getBewEnd()?->format('Y-m-d'),
			$flags,
			$apEnds,
		);
		$existing = $this->mapper->findByProject($project->getId());
		$existingKeys = [];
		foreach ($existing as $row) {
			$existingKeys[$row->getDedupeKey()] = $row;
		}
		$now = new \DateTime();
		foreach ($wanted as $w) {
			if (isset($existingKeys[$w['dedupe_key']])) {
				unset($existingKeys[$w['dedupe_key']]);
				continue;
			}
			$d = new Deadline();
			$d->setProjectId($project->getId());
			$d->setKind($w['kind']);
			$d->setDueDate(new \DateTime($w['due_date']));
			$d->setDedupeKey($w['dedupe_key']);
			$d->setCreatedAt($now);
			$this->mapper->insert($d);
		}
		// Obsolete computed deadlines disappear — but keep ones already
		// notified so the audit trail of reminders stays consistent.
		foreach ($existingKeys as $leftover) {
			if ($leftover->getNotifiedAt() === null) {
				$this->mapper->delete($leftover);
			}
		}
	}

	public function forProject(int $projectId): array
	{
		return $this->mapper->findByProject($projectId);
	}

	/** Deadlines due today or earlier that were never notified. */
	public function dueUnnotified(): array
	{
		return $this->mapper->findDueUnnotified((new \DateTimeImmutable('today'))->format('Y-m-d'));
	}

	public function markNotified(Deadline $d): void
	{
		$d->setNotifiedAt(new \DateTime());
		$this->mapper->update($d);
	}
}

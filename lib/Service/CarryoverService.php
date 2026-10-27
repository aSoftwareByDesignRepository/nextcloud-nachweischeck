<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Mittelübertragung (MH-13): unused funds carried into the following year
 * with the funder's decision reference. Flows into Zwischen- and
 * Schlussnachweis as an Einnahmen-side line.
 */

namespace OCA\NachweisCheck\Service;

use OCA\NachweisCheck\Db\Carryover;
use OCA\NachweisCheck\Db\CarryoverMapper;
use OCA\NachweisCheck\Exception\NotFoundException;
use OCA\NachweisCheck\Exception\ValidationException;
use OCA\NachweisCheck\Support\DbErrors;

class CarryoverService
{
	public function __construct(
		private readonly CarryoverMapper $mapper,
		private readonly AuditService $audit,
	) {
	}

	public function create(int $projectId, int $fromYear, int $toYear, int $amountMinor, string $docRef, string $decidedBy, string $actor): Carryover
	{
		$fields = [];
		if ($toYear !== $fromYear + 1) {
			$fields['to_year'] = 'A Mittelübertrag moves funds into the directly following year.';
		}
		if ($amountMinor <= 0) {
			$fields['amount_minor'] = 'Amount must be positive.';
		}
		if ($fields !== []) {
			throw new ValidationException($fields);
		}
		foreach ($this->mapper->findByProject($projectId) as $existing) {
			if ($existing->getFromYear() === $fromYear) {
				throw new ValidationException(['from_year' => 'A carryover for this year already exists.']);
			}
		}
		$c = new Carryover();
		$c->setProjectId($projectId);
		$c->setFromYear($fromYear);
		$c->setToYear($toYear);
		$c->setAmountMinor($amountMinor);
		$c->setDocRef($docRef);
		$c->setDecidedBy($decidedBy);
		$c->setCreatedBy($actor);
		$c->setCreatedAt(new \DateTime());
		try {
			$c = $this->mapper->insert($c);
		} catch (\Throwable $e) {
			// (project_id, from_year) is unique — a concurrent create won.
			if (DbErrors::isUniqueViolation($e)) {
				throw new ValidationException(['from_year' => 'A carryover for this year already exists.']);
			}
			throw $e;
		}
		$this->audit->record($projectId, 'carryover', $c->getId(), 'create', $actor, null, [
			'from_year' => $fromYear, 'to_year' => $toYear, 'amount_minor' => $amountMinor,
		]);
		return $c;
	}

	public function forProject(int $projectId): array
	{
		return $this->mapper->findByProject($projectId);
	}
}

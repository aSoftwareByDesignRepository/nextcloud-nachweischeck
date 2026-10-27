<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Deviation entries (US-NW-04 AC-3, NN-06): the Begründung is mandatory at
 * the time of the deviation — an empty reason is refused with a field-level
 * error, and the entry lands in the audit trail.
 */

namespace OCA\NachweisCheck\Service;

use OCA\NachweisCheck\Db\Deviation;
use OCA\NachweisCheck\Db\DeviationMapper;
use OCA\NachweisCheck\Exception\ValidationException;

class DeviationService
{
	public const SCOPES = ['position', 'ap'];
	public const KINDS = ['time', 'cost', 'schedule'];

	public function __construct(
		private readonly DeviationMapper $mapper,
		private readonly AuditService $audit,
	) {
	}

	public function create(int $projectId, string $scope, int $refId, string $kind, string $reasonText, string $uid): Deviation
	{
		$fields = [];
		if (!in_array($scope, self::SCOPES, true)) {
			$fields['scope'] = 'Invalid scope.';
		}
		if (!in_array($kind, self::KINDS, true)) {
			$fields['kind'] = 'Invalid kind.';
		}
		if ($refId <= 0) {
			$fields['ref_id'] = 'Invalid reference.';
		}
		if (trim($reasonText) === '') {
			// mandatory Begründung — this refusal is the feature
			$fields['reason_text'] = 'A justification (Begründung) is required.';
		}
		if ($fields !== []) {
			throw new ValidationException($fields);
		}
		$d = new Deviation();
		$d->setProjectId($projectId);
		$d->setScope($scope);
		$d->setRefId($refId);
		$d->setKind($kind);
		$d->setReasonText(trim($reasonText));
		$d->setUid($uid);
		$d->setCreatedAt(new \DateTime());
		$d = $this->mapper->insert($d);
		$this->audit->record($projectId, 'deviation', $d->getId(), 'create', $uid, null, [
			'scope' => $scope, 'ref_id' => $refId, 'kind' => $kind,
		]);
		return $d;
	}

	public function forScope(string $scope, int $refId): array
	{
		return $this->mapper->findFor($scope, $refId);
	}
}

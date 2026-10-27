<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Auditor seats (US-NW-06, V1-10, I-06, Pro): account-bound grants scoped to
 * exactly one project, valid_from/valid_to mandatory, revoke effective
 * immediately. Every read passes through AuditorReadLogger.
 */

namespace OCA\NachweisCheck\Service;

use OCA\NachweisCheck\Db\AuditorGrant;
use OCA\NachweisCheck\Db\AuditorGrantMapper;
use OCA\NachweisCheck\Exception\ConflictException;
use OCA\NachweisCheck\Exception\NotFoundException;
use OCA\NachweisCheck\Exception\ValidationException;
use OCA\NachweisCheck\Support\PeriodUtil;

class AuditorService
{
	public function __construct(
		private readonly AuditorGrantMapper $mapper,
		private readonly AuditorReadLogger $readLogger,
		private readonly AuditService $audit,
		private readonly ConcurrencyGuard $guard,
	) {
	}

	public function grant(int $projectId, string $ncUid, string $name, string $firm, string $validFromYmd, string $validToYmd, ?array $scope, string $actor): AuditorGrant
	{
		$fields = [];
		if ($ncUid === '') {
			$fields['nc_uid'] = 'An account is required.';
		}
		if (!PeriodUtil::isValidYmd($validFromYmd)) {
			$fields['valid_from'] = 'Invalid date.';
		}
		if (!PeriodUtil::isValidYmd($validToYmd)) {
			$fields['valid_to'] = 'Invalid date.';
		}
		if ($fields === [] && $validToYmd < $validFromYmd) {
			$fields['valid_to'] = 'Expiry must not precede start.';
		}
		if ($fields !== []) {
			throw new ValidationException($fields);
		}
		return $this->guard->run(ConcurrencyGuard::projectKey($projectId), function () use ($projectId, $ncUid, $name, $firm, $validFromYmd, $validToYmd, $scope, $actor): AuditorGrant {
			// One seat per auditor per project — a second live grant would
			// fork the read log (activeFor() resolves one row) and
			// double-count seats. Inside the lock: check-then-insert race.
			if ($this->mapper->activeFor($projectId, $ncUid) !== null) {
				throw new ConflictException('GRANT_EXISTS', 'This auditor already holds a grant for the project — revoke it first.');
			}
			$g = new AuditorGrant();
			$g->setProjectId($projectId);
			$g->setNcUid($ncUid);
			$g->setName($name);
			$g->setFirm($firm);
			$g->setScopeJson($scope === null ? null : json_encode($scope));
			$g->setValidFrom(new \DateTime($validFromYmd));
			$g->setValidTo(new \DateTime($validToYmd));
			$g->setCreatedBy($actor);
			$g->setCreatedAt(new \DateTime());
			$g = $this->mapper->insert($g);
			$this->audit->record($projectId, 'auditor_grant', $g->getId(), 'grant', $actor, null, [
				'nc_uid' => $ncUid, 'valid_to' => $validToYmd,
			]);
			return $g;
		});
	}

	public function revoke(AuditorGrant $g, string $actor): AuditorGrant
	{
		if ($g->getRevokedAt() === null) {
			$g->setRevokedAt(new \DateTime());
			$g = $this->mapper->update($g);
			$this->audit->record($g->getProjectId(), 'auditor_grant', $g->getId(), 'revoke', $actor);
		}
		return $g;
	}

	public function getOrFail(int $projectId, int $grantId): AuditorGrant
	{
		$g = $this->mapper->findById($grantId);
		if ($g === null || $g->getProjectId() !== $projectId) {
			throw new NotFoundException();
		}
		return $g;
	}

	/**
	 * The grant an auditor currently holds for a project, or null. Expired
	 * and revoked both yield null — callers then raise the uniform 404.
	 */
	public function activeGrant(int $projectId, string $uid): ?AuditorGrant
	{
		return $this->mapper->activeFor($projectId, $uid);
	}

	/** All grants (any state) held by a uid — for the auditor's own list. */
	public function grantsForUid(string $uid): array
	{
		return $this->mapper->findByUid($uid);
	}
}

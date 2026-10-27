<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Zinsen-/Rueckforderungs-Tracking (NH-06, ANBest-P Nr. 8).
 * kind 'interest': server computes amount_minor from principal × rate ×
 *   act/360 days — the client sends rate_bp + period, never the result.
 * kind 'recovery': amount_minor is the demanded sum (client-supplied,
 *   validated positive); principal stays the referenced overpaid amount.
 * Claims are records of funder-side events; settling only flips state —
 * the audit trail keeps the full history.
 */

namespace OCA\NachweisCheck\Service;

use OCA\NachweisCheck\Db\Claim;
use OCA\NachweisCheck\Db\ClaimMapper;
use OCA\NachweisCheck\Exception\ConflictException;
use OCA\NachweisCheck\Exception\NotFoundException;
use OCA\NachweisCheck\Exception\ValidationException;
use OCA\NachweisCheck\Support\InterestCalculator;
use OCA\NachweisCheck\Support\PeriodUtil;

class ClaimService
{
	public function __construct(
		private readonly ClaimMapper $mapper,
		private readonly AuditService $audit,
		private readonly ConcurrencyGuard $guard,
	) {
	}

	/** @return list<Claim> */
	public function list(int $projectId): array
	{
		return $this->mapper->findByProject($projectId);
	}

	/**
	 * @param array<string,mixed> $in
	 */
	public function create(int $projectId, array $in, string $actor): Claim
	{
		$kind = (string)($in['kind'] ?? '');
		$title = trim((string)($in['title'] ?? ''));
		$occurred = (string)($in['occurred_date'] ?? '');
		$fields = [];
		if (!in_array($kind, [Claim::KIND_INTEREST, Claim::KIND_RECOVERY], true)) {
			$fields['kind'] = 'Invalid kind.';
		}
		if ($title === '') {
			$fields['title'] = 'Title is required.';
		}
		if (!PeriodUtil::isValidYmd($occurred)) {
			$fields['occurred_date'] = 'Invalid date.';
		}

		$principal = (int)($in['principal_minor'] ?? 0);
		$rateBp = (int)($in['rate_bp'] ?? 0);
		$from = (string)($in['period_from'] ?? '');
		$to = (string)($in['period_to'] ?? '');
		$amount = 0;

		if ($kind === Claim::KIND_INTEREST) {
			if ($principal <= 0) {
				$fields['principal_minor'] = 'Principal must be positive.';
			}
			if ($rateBp <= 0 || $rateBp > 100000) {
				$fields['rate_bp'] = 'Rate must be 1–100000 basis points.';
			}
			if (!PeriodUtil::isValidYmd($from) || !PeriodUtil::isValidYmd($to) || $to <= $from) {
				$fields['period'] = 'Interest period must be a valid from/to range.';
			}
			if ($fields === []) {
				$amount = InterestCalculator::interestMinor($principal, $rateBp, InterestCalculator::days360($from, $to));
			}
		} elseif ($kind === Claim::KIND_RECOVERY) {
			$amount = (int)($in['amount_minor'] ?? 0);
			if ($amount <= 0) {
				$fields['amount_minor'] = 'Amount must be positive.';
			}
			if ($from !== '' && !PeriodUtil::isValidYmd($from)) {
				$fields['period_from'] = 'Invalid date.';
			}
			if ($to !== '' && !PeriodUtil::isValidYmd($to)) {
				$fields['period_to'] = 'Invalid date.';
			}
		}
		if ($fields !== []) {
			throw new ValidationException($fields);
		}

		return $this->guard->run(ConcurrencyGuard::projectKey($projectId), function () use ($projectId, $kind, $title, $principal, $rateBp, $from, $to, $amount, $occurred, $in, $actor): Claim {
			$c = new Claim();
			$c->setProjectId($projectId);
			$c->setKind($kind);
			$c->setTitle($title);
			$c->setPrincipalMinor($principal);
			$c->setRateBp($rateBp);
			$c->setPeriodFrom(PeriodUtil::isValidYmd($from) ? new \DateTime($from) : null);
			$c->setPeriodTo(PeriodUtil::isValidYmd($to) ? new \DateTime($to) : null);
			$c->setAmountMinor($amount);
			$c->setState(Claim::STATE_OPEN);
			$c->setOccurredDate(new \DateTime($occurred));
			$note = trim((string)($in['note'] ?? ''));
			$c->setNote($note !== '' ? $note : null);
			$c->setCreatedBy($actor);
			$c->setCreatedAt(new \DateTime());
			$c = $this->mapper->insert($c);
			$this->audit->record($projectId, 'claim', $c->getId(), 'create', $actor, null, [
				'kind' => $kind, 'amount_minor' => $amount, 'title' => $title,
			]);
			return $c;
		});
	}

	public function settle(Claim $claim, string $actor): Claim
	{
		return $this->guard->run(ConcurrencyGuard::projectKey($claim->getProjectId()), function () use ($claim, $actor): Claim {
			$fresh = $this->getOrFail($claim->getProjectId(), $claim->getId());
			if ($fresh->getState() !== Claim::STATE_OPEN) {
				throw new ConflictException('INVALID_STATE', 'Only an open claim can be settled.');
			}
			$fresh->setState(Claim::STATE_SETTLED);
			$this->mapper->update($fresh);
			$this->audit->record($fresh->getProjectId(), 'claim', $fresh->getId(), 'settle', $actor,
				['state' => Claim::STATE_OPEN], ['state' => Claim::STATE_SETTLED]);
			return $fresh;
		});
	}

	public function getOrFail(int $projectId, int $id): Claim
	{
		$c = $this->mapper->findById($id);
		if ($c === null || $c->getProjectId() !== $projectId) {
			throw new NotFoundException();
		}
		return $c;
	}
}

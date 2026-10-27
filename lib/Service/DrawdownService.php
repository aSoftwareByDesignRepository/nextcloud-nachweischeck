<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Mittelabruf history + Liquiditaetsprognose (NH-01/NH-09).
 *
 * History only — the app deliberately does NOT run the funder's call-for-
 * funds workflow; it logs each Abruf with its outcome and derives a
 * liquidity forecast from what is already known:
 *
 *   cash       = received payments - used (Soll-Ist total)
 *   burn rate  = used / elapsed months of the Bewilligungszeitraum
 *   runway     = cash / burn rate (months of funding left)
 *   suggested  = projected remaining spend - cash - pending drawdowns
 *
 * All amounts integer minor units; month math is whole-calendar based so
 * the numbers are explainable to a funder without a calculator.
 */

namespace OCA\NachweisCheck\Service;

use OCA\NachweisCheck\Db\Drawdown;
use OCA\NachweisCheck\Db\DrawdownMapper;
use OCA\NachweisCheck\Db\IncomingPaymentMapper;
use OCA\NachweisCheck\Db\Project;
use OCA\NachweisCheck\Exception\ConflictException;
use OCA\NachweisCheck\Exception\NotFoundException;
use OCA\NachweisCheck\Exception\ValidationException;
use OCA\NachweisCheck\Support\DbErrors;
use OCA\NachweisCheck\Support\PeriodUtil;

class DrawdownService
{
	public const STATES = [Drawdown::STATE_REQUESTED, Drawdown::STATE_RECEIVED, Drawdown::STATE_REJECTED];

	public function __construct(
		private readonly DrawdownMapper $mapper,
		private readonly IncomingPaymentMapper $paymentMapper,
		private readonly SollIstService $sollIst,
		private readonly AuditService $audit,
		private readonly ConcurrencyGuard $guard,
	) {
	}

	/** @return list<Drawdown> */
	public function list(int $projectId): array
	{
		return $this->mapper->findByProject($projectId);
	}

	public function create(int $projectId, string $requestedDateYmd, int $amountMinor, ?string $callRef, ?string $note, ?string $externalRef, string $actor): Drawdown
	{
		$fields = [];
		if (!PeriodUtil::isValidYmd($requestedDateYmd)) {
			$fields['requested_date'] = 'Invalid date.';
		}
		if ($amountMinor <= 0) {
			$fields['amount_minor'] = 'Amount must be positive.';
		}
		if ($fields !== []) {
			throw new ValidationException($fields);
		}
		$externalRef = $externalRef !== null && trim($externalRef) !== '' ? trim($externalRef) : null;

		return $this->guard->run(ConcurrencyGuard::projectKey($projectId), function () use ($projectId, $requestedDateYmd, $amountMinor, $callRef, $note, $externalRef, $actor): Drawdown {
			if ($externalRef !== null && $this->mapper->findByExternalRef($projectId, $externalRef) !== null) {
				throw new ValidationException(['external_ref' => 'Duplicate external reference.']);
			}
			$d = new Drawdown();
			$d->setProjectId($projectId);
			$d->setRequestedDate(new \DateTime($requestedDateYmd));
			$d->setAmountMinor($amountMinor);
			$d->setCallRef($callRef !== null && trim($callRef) !== '' ? trim($callRef) : null);
			$d->setState(Drawdown::STATE_REQUESTED);
			$d->setNote($note !== null && trim($note) !== '' ? trim($note) : null);
			$d->setExternalRef($externalRef);
			$d->setCreatedBy($actor);
			$d->setCreatedAt(new \DateTime());
			try {
				$d = $this->mapper->insert($d);
			} catch (\Throwable $e) {
				if (!DbErrors::isUniqueViolation($e)) {
					throw $e;
				}
				throw new ValidationException(['external_ref' => 'Duplicate external reference.']);
			}
			$this->audit->record($projectId, 'drawdown', $d->getId(), 'create', $actor, null, [
				'requested_date' => $requestedDateYmd, 'amount_minor' => $amountMinor,
			]);
			return $d;
		});
	}

	/**
	 * Mark a drawdown received and bind it to the incoming payment that
	 * carries the money — the Abruf-Log and the Einnahmenseite stay
	 * consistent by construction. The payment must belong to the project.
	 */
	public function markReceived(Drawdown $d, int $paymentId, string $actor): Drawdown
	{
		return $this->guard->run(ConcurrencyGuard::projectKey($d->getProjectId()), function () use ($d, $paymentId, $actor): Drawdown {
			$fresh = $this->getOrFail($d->getProjectId(), $d->getId());
			if ($fresh->getState() !== Drawdown::STATE_REQUESTED) {
				throw new ConflictException('INVALID_STATE', 'Only a requested drawdown can be marked received.');
			}
			$payment = $this->paymentMapper->findById($paymentId);
			if ($payment === null || $payment->getProjectId() !== $d->getProjectId()) {
				throw new ValidationException(['payment_id' => 'Payment not found in this project.']);
			}
			$before = ['state' => $fresh->getState(), 'payment_id' => $fresh->getPaymentId()];
			$fresh->setState(Drawdown::STATE_RECEIVED);
			$fresh->setPaymentId($paymentId);
			$this->mapper->update($fresh);
			$this->audit->record($d->getProjectId(), 'drawdown', $fresh->getId(), 'receive', $actor, $before, [
				'state' => Drawdown::STATE_RECEIVED, 'payment_id' => $paymentId,
			]);
			return $fresh;
		});
	}

	public function reject(Drawdown $d, string $actor): Drawdown
	{
		return $this->guard->run(ConcurrencyGuard::projectKey($d->getProjectId()), function () use ($d, $actor): Drawdown {
			$fresh = $this->getOrFail($d->getProjectId(), $d->getId());
			if ($fresh->getState() !== Drawdown::STATE_REQUESTED) {
				throw new ConflictException('INVALID_STATE', 'Only a requested drawdown can be rejected.');
			}
			$fresh->setState(Drawdown::STATE_REJECTED);
			$this->mapper->update($fresh);
			$this->audit->record($d->getProjectId(), 'drawdown', $fresh->getId(), 'reject', $actor,
				['state' => Drawdown::STATE_REQUESTED], ['state' => Drawdown::STATE_REJECTED]);
			return $fresh;
		});
	}

	public function getOrFail(int $projectId, int $id): Drawdown
	{
		$d = $this->mapper->findById($id);
		if ($d === null || $d->getProjectId() !== $projectId) {
			throw new NotFoundException();
		}
		return $d;
	}

	/**
	 * Liquidity forecast for one project (NH-01). Returns null fields
	 * where a denominator does not exist yet (no elapsed months → no burn
	 * rate) instead of inventing numbers.
	 *
	 * @return array{
	 *   received_minor:int, used_minor:int, cash_minor:int,
	 *   pending_drawdowns_minor:int, budget_total_minor:int,
	 *   elapsed_months:int, remaining_months:int,
	 *   burn_rate_minor:?int, runway_months:?int,
	 *   projected_remaining_spend_minor:?int, suggested_drawdown_minor:?int
	 * }
	 */
	public function forecast(Project $project, string $todayYmd): array
	{
		$received = 0;
		foreach ($this->paymentMapper->findByProject($project->getId()) as $p) {
			$received += $p->getAmountMinor();
		}
		$totals = $this->sollIst->forProject($project)['totals'];
		$used = $totals['used'];
		$budget = $totals['budget'];
		$pending = 0;
		foreach ($this->mapper->findByProjectState($project->getId(), Drawdown::STATE_REQUESTED) as $d) {
			$pending += $d->getAmountMinor();
		}

		$start = $project->getBewStart()?->format('Y-m-d');
		$end = $project->getBewEnd()?->format('Y-m-d');
		$elapsed = 0;
		$remaining = 0;
		if ($start !== null && $end !== null) {
			$elapsed = max(0, $this->monthsBetween($start, min($todayYmd, $end)));
			$remaining = max(0, $this->monthsBetween(max($todayYmd, $start), $end));
		}

		$cash = $received - $used;
		$burnRate = $elapsed > 0 ? intdiv($used, $elapsed) : null;
		$runway = $burnRate !== null && $burnRate > 0 ? intdiv(max(0, $cash), $burnRate) : null;
		$projectedRemaining = $burnRate !== null ? $burnRate * $remaining : null;
		$suggested = $projectedRemaining !== null
			? max(0, $projectedRemaining - $cash - $pending)
			: null;

		return [
			'received_minor' => $received,
			'used_minor' => $used,
			'cash_minor' => $cash,
			'pending_drawdowns_minor' => $pending,
			'budget_total_minor' => $budget,
			'elapsed_months' => $elapsed,
			'remaining_months' => $remaining,
			'burn_rate_minor' => $burnRate,
			'runway_months' => $runway,
			'projected_remaining_spend_minor' => $projectedRemaining,
			'suggested_drawdown_minor' => $suggested,
		];
	}

	/** Whole calendar months between two Y-m-d dates (floor). */
	private function monthsBetween(string $fromYmd, string $toYmd): int
	{
		if ($toYmd <= $fromYmd) {
			return 0;
		}
		$from = new \DateTime($fromYmd);
		$to = new \DateTime($toYmd);
		$months = ((int)$to->format('Y') - (int)$from->format('Y')) * 12
			+ ((int)$to->format('n') - (int)$from->format('n'));
		// A started month only counts once the same day-of-month passed.
		if ((int)$to->format('j') < (int)$from->format('j')) {
			$months--;
		}
		return max(0, $months);
	}
}

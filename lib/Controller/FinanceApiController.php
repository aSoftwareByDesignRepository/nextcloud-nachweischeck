<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Finance-role surfaces: rate history, incoming payments, Umschichtungen,
 * carryovers. All gated by requireFinance — the only role set that may
 * read or write money-shaping data.
 */

namespace OCA\NachweisCheck\Controller;

use OCA\NachweisCheck\Db\CarryoverMapper;
use OCA\NachweisCheck\Db\Claim;
use OCA\NachweisCheck\Db\IncomingPaymentMapper;
use OCA\NachweisCheck\Db\ReallocationMapper;
use OCA\NachweisCheck\Service\AccessDoorService;
use OCA\NachweisCheck\Service\CarryoverService;
use OCA\NachweisCheck\Service\ClaimService;
use OCA\NachweisCheck\Service\CofunderService;
use OCA\NachweisCheck\Service\DrawdownService;
use OCA\NachweisCheck\Service\IncomingPaymentService;
use OCA\NachweisCheck\Service\PermissionService;
use OCA\NachweisCheck\Service\ProjectService;
use OCA\NachweisCheck\Service\RateService;
use OCA\NachweisCheck\Service\ReallocationService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

class FinanceApiController extends NwApiController
{
	public function __construct(
		string $appName, IRequest $request, IUserSession $userSession,
		AccessDoorService $door, PermissionService $perm,
		private readonly RateService $rates,
		private readonly IncomingPaymentService $payments,
		private readonly IncomingPaymentMapper $paymentMapper,
		private readonly ReallocationService $reallocs,
		private readonly ReallocationMapper $reallocMapper,
		private readonly CarryoverService $carryovers,
		private readonly CarryoverMapper $carryoverMapper,
		private readonly CofunderService $cofunders,
		private readonly DrawdownService $drawdowns,
		private readonly ClaimService $claims,
		private readonly ProjectService $projects,
	) {
		parent::__construct($appName, $request, $userSession, $door, $perm);
	}

	// ---- rates ----------------------------------------------------------

	#[NoAdminRequired]
	public function rates(int $projectId, string $uid): JSONResponse
	{
		$actor = $this->uid();
		$this->perm->requireFinance($projectId, $actor);
		$out = [];
		foreach ($this->rates->history($projectId, $uid) as $r) {
			$out[] = [
				'id' => $r->getId(), 'uid' => $r->getUid(), 'role_key' => $r->getRoleKey(),
				'rate_minor' => $r->getRateMinor(), 'valid_from' => $r->getValidFrom()?->format('Y-m-d'),
			];
		}
		return $this->ok($out);
	}

	#[NoAdminRequired]
	public function addRate(int $projectId): JSONResponse
	{
		$actor = $this->uid();
		$this->perm->requireFinance($projectId, $actor);
		$in = $this->params();
		$r = $this->rates->addRate($projectId, (string)($in['uid'] ?? ''), (string)($in['role_key'] ?? ''),
			(int)($in['rate_minor'] ?? -1), (string)($in['valid_from'] ?? ''), $actor);
		return $this->ok(['id' => $r->getId()], 201);
	}

	// ---- incoming payments ----------------------------------------------

	#[NoAdminRequired]
	public function payments(int $projectId): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireView($projectId, $uid);
		$out = [];
		foreach ($this->paymentMapper->findByProject($projectId) as $p) {
			$out[] = [
				'id' => $p->getId(), 'received_date' => $p->getReceivedDate()?->format('Y-m-d'),
				'amount_minor' => $p->getAmountMinor(), 'call_ref' => $p->getCallRef(),
				'year_received' => $p->getYearReceived(), 'note' => $p->getNote(),
			];
		}
		return $this->ok($out);
	}

	#[NoAdminRequired]
	public function addPayment(int $projectId): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireFinance($projectId, $uid);
		$in = $this->params();
		$p = $this->payments->create($projectId, (string)($in['received_date'] ?? ''),
			(int)($in['amount_minor'] ?? 0), (string)($in['call_ref'] ?? ''),
			(string)($in['note'] ?? ''), $in['external_ref'] ?? null, $uid);
		return $this->ok(['id' => $p->getId()], 201);
	}

	#[NoAdminRequired]
	public function deletePayment(int $projectId, int $id): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireFinance($projectId, $uid);
		$this->payments->delete($this->payments->getOrFail($projectId, $id), $uid);
		return $this->ok(['deleted' => true]);
	}

	// ---- reallocations ----------------------------------------------------

	#[NoAdminRequired]
	public function reallocations(int $projectId): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireView($projectId, $uid);
		$out = [];
		foreach ($this->reallocMapper->findByProject($projectId) as $r) {
			$out[] = $this->serializeRealloc($r);
		}
		return $this->ok($out);
	}

	#[NoAdminRequired]
	public function requestReallocation(int $projectId): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireFinance($projectId, $uid);
		$in = $this->params();
		$r = $this->reallocs->request($projectId, (int)($in['from_position_id'] ?? 0),
			(int)($in['to_position_id'] ?? 0), (int)($in['amount_minor'] ?? 0),
			(string)($in['reason_text'] ?? ''), $uid);
		return $this->ok($this->serializeRealloc($r), 201);
	}

	#[NoAdminRequired]
	public function decideReallocation(int $projectId, int $id): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireAdmin($projectId, $uid); // documented Geber-Freigabe = admin decision
		$in = $this->params();
		$r = $this->reallocs->getOrFail($projectId, $id);
		$r = ($in['decision'] ?? '') === 'approve'
			? $this->reallocs->approve($r, $uid)
			: $this->reallocs->reject($r, $uid);
		return $this->ok($this->serializeRealloc($r));
	}

	// ---- carryovers -------------------------------------------------------

	#[NoAdminRequired]
	public function carryovers(int $projectId): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireView($projectId, $uid);
		$out = [];
		foreach ($this->carryoverMapper->findByProject($projectId) as $c) {
			$out[] = [
				'id' => $c->getId(), 'from_year' => $c->getFromYear(), 'to_year' => $c->getToYear(),
				'amount_minor' => $c->getAmountMinor(), 'doc_ref' => $c->getDocRef(),
				'decided_by' => $c->getDecidedBy(),
			];
		}
		return $this->ok($out);
	}

	#[NoAdminRequired]
	public function addCarryover(int $projectId): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireFinance($projectId, $uid);
		$in = $this->params();
		$c = $this->carryovers->create($projectId, (int)($in['from_year'] ?? 0), (int)($in['to_year'] ?? 0),
			(int)($in['amount_minor'] ?? 0), (string)($in['doc_ref'] ?? ''), (string)($in['decided_by'] ?? ''), $uid);
		return $this->ok(['id' => $c->getId()], 201);
	}

	// ---- co-funders (multi-funder, BQ-03) --------------------------------

	#[NoAdminRequired]
	public function cofunders(int $projectId): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireView($projectId, $uid);
		$out = [];
		foreach ($this->cofunders->list($projectId) as $c) {
			$out[] = $this->serializeCofunder($c);
		}
		return $this->ok($out);
	}

	#[NoAdminRequired]
	public function addCofunder(int $projectId): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireFinance($projectId, $uid);
		$in = $this->params();
		$c = $this->cofunders->create($projectId, (string)($in['name'] ?? ''),
			$in['ref'] ?? null, $in['contact'] ?? null, $in['note'] ?? null, $uid);
		return $this->ok($this->serializeCofunder($c), 201);
	}

	#[NoAdminRequired]
	public function deleteCofunder(int $projectId, int $id): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireFinance($projectId, $uid);
		$this->cofunders->delete($projectId, $id, $uid);
		return $this->ok(['deleted' => true]);
	}

	// ---- drawdowns / Mittelabruf history + forecast (NH-01/NH-09) --------

	#[NoAdminRequired]
	public function drawdowns(int $projectId): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireView($projectId, $uid);
		$out = [];
		foreach ($this->drawdowns->list($projectId) as $d) {
			$out[] = $this->serializeDrawdown($d);
		}
		return $this->ok($out);
	}

	#[NoAdminRequired]
	public function addDrawdown(int $projectId): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireFinance($projectId, $uid);
		$in = $this->params();
		$d = $this->drawdowns->create($projectId, (string)($in['requested_date'] ?? ''),
			(int)($in['amount_minor'] ?? 0), $in['call_ref'] ?? null,
			$in['note'] ?? null, $in['external_ref'] ?? null, $uid);
		return $this->ok($this->serializeDrawdown($d), 201);
	}

	#[NoAdminRequired]
	public function decideDrawdown(int $projectId, int $id): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireFinance($projectId, $uid);
		$d = $this->drawdowns->getOrFail($projectId, $id);
		$in = $this->params();
		$decision = (string)($in['decision'] ?? '');
		$d = match ($decision) {
			'receive' => $this->drawdowns->markReceived($d, (int)($in['payment_id'] ?? 0), $uid),
			'reject' => $this->drawdowns->reject($d, $uid),
			default => throw new \OCA\NachweisCheck\Exception\ValidationException(['decision' => 'Must be receive or reject.']),
		};
		return $this->ok($this->serializeDrawdown($d));
	}

	#[NoAdminRequired]
	public function forecast(int $projectId): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireView($projectId, $uid);
		$p = $this->projects->getOrFail($projectId);
		return $this->ok($this->drawdowns->forecast($p, (new \DateTime())->format('Y-m-d')));
	}

	// ---- claims / Zinsen + Rueckforderung (NH-06) -------------------------

	#[NoAdminRequired]
	public function claims(int $projectId): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireView($projectId, $uid);
		$out = [];
		foreach ($this->claims->list($projectId) as $c) {
			$out[] = $this->serializeClaim($c);
		}
		return $this->ok($out);
	}

	#[NoAdminRequired]
	public function addClaim(int $projectId): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireFinance($projectId, $uid);
		$c = $this->claims->create($projectId, $this->params(), $uid);
		return $this->ok($this->serializeClaim($c), 201);
	}

	#[NoAdminRequired]
	public function settleClaim(int $projectId, int $id): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireFinance($projectId, $uid);
		$c = $this->claims->settle($this->claims->getOrFail($projectId, $id), $uid);
		return $this->ok($this->serializeClaim($c));
	}

	private function serializeCofunder($c): array
	{
		return [
			'id' => $c->getId(), 'name' => $c->getName(), 'ref' => $c->getRef(),
			'contact' => $c->getContact(), 'note' => $c->getNote(),
		];
	}

	private function serializeDrawdown($d): array
	{
		return [
			'id' => $d->getId(), 'requested_date' => $d->getRequestedDate()?->format('Y-m-d'),
			'amount_minor' => $d->getAmountMinor(), 'call_ref' => $d->getCallRef(),
			'state' => $d->getState(), 'payment_id' => $d->getPaymentId(), 'note' => $d->getNote(),
		];
	}

	private function serializeClaim($c): array
	{
		return [
			'id' => $c->getId(), 'kind' => $c->getKind(), 'title' => $c->getTitle(),
			'principal_minor' => $c->getPrincipalMinor(), 'rate_bp' => $c->getRateBp(),
			'period_from' => $c->getPeriodFrom()?->format('Y-m-d'),
			'period_to' => $c->getPeriodTo()?->format('Y-m-d'),
			'amount_minor' => $c->getAmountMinor(), 'state' => $c->getState(),
			'occurred_date' => $c->getOccurredDate()?->format('Y-m-d'), 'note' => $c->getNote(),
		];
	}

	private function serializeRealloc($r): array
	{
		return [
			'id' => $r->getId(), 'from_position_id' => $r->getFromPositionId(),
			'to_position_id' => $r->getToPositionId(), 'amount_minor' => $r->getAmountMinor(),
			'reason_text' => $r->getReasonText(), 'state' => $r->getState(),
			'approved_by' => $r->getApprovedBy(), 'decided_at' => $r->getDecidedAt()?->format(\DateTime::ATOM),
		];
	}
}

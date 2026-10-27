<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Einnahmenseite (MH-11): received grant instalments with Abruf-Nr.
 * year_received is stored denormalized for the Zwischennachweis rule
 * (Nr. 6.1/6.6: only the year's RECEIVED amounts are reported) and is
 * validated to match the received_date year.
 */

namespace OCA\NachweisCheck\Service;

use OCA\NachweisCheck\Db\IncomingPayment;
use OCA\NachweisCheck\Db\IncomingPaymentMapper;
use OCA\NachweisCheck\Exception\NotFoundException;
use OCA\NachweisCheck\Exception\ValidationException;
use OCA\NachweisCheck\Support\PeriodUtil;

class IncomingPaymentService
{
	public function __construct(
		private readonly IncomingPaymentMapper $mapper,
		private readonly AuditService $audit,
	) {
	}

	public function create(int $projectId, string $receivedDateYmd, int $amountMinor, string $callRef, string $note, ?string $externalRef, string $actor): IncomingPayment
	{
		$fields = [];
		if (!PeriodUtil::isValidYmd($receivedDateYmd)) {
			$fields['received_date'] = 'Invalid date.';
		}
		if ($amountMinor <= 0) {
			$fields['amount_minor'] = 'Amount must be positive.';
		}
		if ($fields !== []) {
			throw new ValidationException($fields);
		}
		if ($externalRef !== null && $externalRef !== ''
			&& $this->mapper->findByExternalRef($projectId, $externalRef) !== null) {
			throw new ValidationException(['external_ref' => 'Duplicate external reference.']);
		}
		$p = new IncomingPayment();
		$p->setProjectId($projectId);
		$p->setReceivedDate(new \DateTime($receivedDateYmd));
		$p->setAmountMinor($amountMinor);
		$p->setCallRef($callRef);
		$p->setYearReceived((int)substr($receivedDateYmd, 0, 4));
		$p->setNote($note);
		$p->setExternalRef($externalRef);
		$p->setCreatedBy($actor);
		$p->setCreatedAt(new \DateTime());
		$p = $this->mapper->insert($p);
		$this->audit->record($projectId, 'incoming_payment', $p->getId(), 'create', $actor, null, [
			'date' => $receivedDateYmd, 'amount_minor' => $amountMinor, 'call_ref' => $callRef,
		]);
		return $p;
	}

	public function delete(IncomingPayment $p, string $actor): void
	{
		$this->audit->record($p->getProjectId(), 'incoming_payment', $p->getId(), 'delete', $actor, [
			'date' => $p->getReceivedDate()?->format('Y-m-d'), 'amount_minor' => $p->getAmountMinor(),
		]);
		$this->mapper->delete($p);
	}

	public function getOrFail(int $projectId, int $id): IncomingPayment
	{
		$p = $this->mapper->findById($id);
		if ($p === null || $p->getProjectId() !== $projectId) {
			throw new NotFoundException();
		}
		return $p;
	}
}

<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Shared API plumbing: authenticated user resolution, app-door gate,
 * project-role gates via PermissionService, and privacy-aware serializers
 * (rate fields are stripped unless the caller may see salaries — GAC-6).
 * Every endpoint wraps its work in a project-scope check FIRST; entities
 * outside the caller's scope collapse into the uniform 404.
 */

namespace OCA\NachweisCheck\Controller;

use OCA\NachweisCheck\Db\Receipt;
use OCA\NachweisCheck\Db\TimeEntry;
use OCA\NachweisCheck\Exception\ForbiddenException;
use OCA\NachweisCheck\Exception\NwException;
use OCA\NachweisCheck\Service\AccessDoorService;
use OCA\NachweisCheck\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;

abstract class NwApiController extends Controller
{
	public function __construct(
		string $appName,
		IRequest $request,
		protected readonly IUserSession $userSession,
		protected readonly AccessDoorService $door,
		protected readonly PermissionService $perm,
	) {
		parent::__construct($appName, $request);
	}

	protected function user(): IUser
	{
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new NwException('UNAUTHENTICATED', 'Authentication required.', 401);
		}
		if (!$this->door->isAllowed($user) && !$this->door->isAppAdmin($user)) {
			throw new ForbiddenException('This app is restricted.');
		}
		return $user;
	}

	protected function uid(): string
	{
		return $this->user()->getUID();
	}

	protected function ok(mixed $data, int $status = Http::STATUS_OK): JSONResponse
	{
		return new JSONResponse(['success' => true, 'data' => $data], $status);
	}

	// ---- serializers ----------------------------------------------------

	protected function serializeTime(TimeEntry $e, int $projectId, string $uid): array
	{
		$canSeeRates = $this->perm->canSeeRates($projectId, $uid);
		return [
			'id' => $e->getId(),
			'ap_id' => $e->getApId(),
			'uid' => $e->getUid(),
			'entry_date' => $e->getEntryDate()?->format('Y-m-d'),
			'minutes' => $e->getMinutes(),
			'note' => $e->getNote(),
			'rate_minor' => $canSeeRates ? $e->getRateMinor() : null,
			'amount_minor' => $canSeeRates ? $e->getAmountMinor() : null,
			'priced' => $e->getRateMinor() !== null,
		];
	}

	protected function serializeReceipt(Receipt $r): array
	{
		return [
			'id' => $r->getId(),
			'vendor' => $r->getVendor(),
			'invoice_no' => $r->getInvoiceNo(),
			'invoice_date' => $r->getInvoiceDate()?->format('Y-m-d'),
			'payment_date' => $r->getPaymentDate()?->format('Y-m-d'),
			'currency' => $r->getCurrency(),
			'total_minor' => $r->getTotalMinor(),
			'tax_minor' => $r->getTaxMinor(),
			'purpose' => $r->getPurpose(),
			'file_id' => $r->getFileId(),
			'salary_period' => $r->getSalaryPeriod(),
			'kind' => $r->getKind(),
			'state' => $r->getState(),
			'dup_flag' => (bool)$r->getDupFlag(),
		];
	}

	/** @return array{entry_date:string, minutes:int, note:string} */
	protected function params(): array
	{
		return $this->request->getParams();
	}
}

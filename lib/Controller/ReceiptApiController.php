<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NachweisCheck\Controller;

use OCA\NachweisCheck\Db\ReceiptMapper;
use OCA\NachweisCheck\Exception\ForbiddenException;
use OCA\NachweisCheck\Exception\NotFoundException;
use OCA\NachweisCheck\Exception\ValidationException;
use OCA\NachweisCheck\Service\AccessDoorService;
use OCA\NachweisCheck\Service\LicenseService;
use OCA\NachweisCheck\Service\PermissionService;
use OCA\NachweisCheck\Service\ReceiptService;
use OCA\NachweisCheck\Support\ERechnungParser;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUserSession;

class ReceiptApiController extends NwApiController
{
	public function __construct(
		string $appName, IRequest $request, IUserSession $userSession,
		AccessDoorService $door, PermissionService $perm,
		private readonly ReceiptService $receipts,
		private readonly ReceiptMapper $receiptMapper,
		private readonly LicenseService $license,
		private readonly IRootFolder $rootFolder,
	) {
		parent::__construct($appName, $request, $userSession, $door, $perm);
	}

	#[NoAdminRequired]
	public function index(int $projectId): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireView($projectId, $uid);
		$role = $this->perm->roleIn($projectId, $uid);
		$out = [];
		foreach ($this->receiptMapper->findByProject($projectId) as $r) {
			// salary-kind Belege are finance/admin only (MH-14 + NN-05)
			if ($r->getKind() === 'salary' && !in_array($role, ['admin', 'finance'], true)) {
				continue;
			}
			$row = $this->serializeReceipt($r);
			if (in_array($role, ['admin', 'finance'], true)) {
				// Serialize splits explicitly — Entity does not JsonSerialize.
				$row['splits'] = array_map(static fn($s) => [
					'id' => $s->getId(), 'position_id' => $s->getPositionId(),
					'ap_id' => $s->getApId(), 'amount_minor' => $s->getAmountMinor(),
					'note' => $s->getNote(),
				], $this->receipts->splitsOf($r->getId()));
			}
			$out[] = $row;
		}
		return $this->ok($out);
	}

	#[NoAdminRequired]
	public function create(int $projectId): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireBook($projectId, $uid);
		$in = $this->params();
		$kind = (string)($in['kind'] ?? 'receipt');
		if ($kind === 'salary') {
			$this->perm->requireFinance($projectId, $uid); // Gehaltsnachweise = finance
		}
		$splits = $in['splits'] ?? null;
		if (!is_array($splits)) {
			throw new ValidationException(['splits' => 'Splits are required.']);
		}
		$r = $this->receipts->create(
			$projectId,
			(string)($in['vendor'] ?? ''),
			$in['invoice_no'] !== '' ? ($in['invoice_no'] ?? null) : null,
			(string)($in['invoice_date'] ?? ''),
			$in['payment_date'] !== '' ? ($in['payment_date'] ?? null) : null,
			strtoupper((string)($in['currency'] ?? 'EUR')),
			isset($in['fx_rate_milli']) && $in['fx_rate_milli'] !== '' ? (int)$in['fx_rate_milli'] : null,
			(int)($in['total_minor'] ?? 0),
			(int)($in['tax_minor'] ?? 0),
			(string)($in['purpose'] ?? ''),
			isset($in['file_id']) && $in['file_id'] !== '' ? (int)$in['file_id'] : null,
			$kind,
			$splits,
			$in['external_ref'] ?? null,
			$uid,
			($in['confirm_duplicate'] ?? '0') === '1',
			isset($in['salary_period']) && $in['salary_period'] !== '' ? (string)$in['salary_period'] : null,
		);
		return $this->ok($this->serializeReceipt($r), 201);
	}

	#[NoAdminRequired]
	public function update(int $projectId, int $id): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireFinance($projectId, $uid);
		$r = $this->receipts->getOrFail($projectId, $id);
		$r = $this->receipts->update($r, $this->params(), $uid);
		return $this->ok($this->serializeReceipt($r));
	}

	#[NoAdminRequired]
	public function replaceSplits(int $projectId, int $id): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireFinance($projectId, $uid);
		$r = $this->receipts->getOrFail($projectId, $id);
		$in = $this->params();
		$splits = $in['splits'] ?? null;
		if (!is_array($splits)) {
			throw new ValidationException(['splits' => 'Splits are required.']);
		}
		$r = $this->receipts->replaceSplits($r, $splits, $uid);
		return $this->ok($this->serializeReceipt($r));
	}

	#[NoAdminRequired]
	public function delete(int $projectId, int $id): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireFinance($projectId, $uid);
		$r = $this->receipts->getOrFail($projectId, $id);
		$this->receipts->delete($r, $uid);
		return $this->ok(['deleted' => true]);
	}

	/**
	 * E-Rechnung inbound parse (Pro): reads the Files node, returns a
	 * prefilled draft. Parse failure → empty draft, never a crash.
	 */
	#[NoAdminRequired]
	public function parseEinvoice(int $projectId): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireBook($projectId, $uid);
		$this->license->requireFeature(LicenseService::FEATURE_ERECHNUNG);
		$in = $this->params();
		$fileId = (int)($in['file_id'] ?? 0);
		if ($fileId <= 0) {
			throw new ValidationException(['file_id' => 'File id required.']);
		}
		$node = $this->rootFolder->getUserFolder($uid)->getById($fileId)[0] ?? null;
		if ($node === null || !method_exists($node, 'getContent')) {
			throw new NotFoundException();
		}
		$result = ERechnungParser::parse($node->getContent());
		return $this->ok($result);
	}
}

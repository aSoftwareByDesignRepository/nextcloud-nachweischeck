<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NachweisCheck\Controller;

use OCA\NachweisCheck\Exception\ValidationException;
use OCA\NachweisCheck\Service\AccessDoorService;
use OCA\NachweisCheck\Service\CsvImportService;
use OCA\NachweisCheck\Service\PermissionService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

class ImportApiController extends NwApiController
{
	public function __construct(
		string $appName, IRequest $request, IUserSession $userSession,
		AccessDoorService $door, PermissionService $perm,
		private readonly CsvImportService $importer,
	) {
		parent::__construct($appName, $request, $userSession, $door, $perm);
	}

	#[NoAdminRequired]
	public function times(int $projectId): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireFinance($projectId, $uid);
		$in = $this->params();
		$csv = (string)($in['csv'] ?? '');
		if (trim($csv) === '') {
			throw new ValidationException(['csv' => 'CSV body required.']);
		}
		$out = $this->importer->importTimes($projectId, (string)($in['preset'] ?? 'generic'), $csv,
			(string)($in['filename'] ?? 'inline.csv'), $uid);
		return $this->ok($out);
	}

	#[NoAdminRequired]
	public function receipts(int $projectId): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireFinance($projectId, $uid);
		$in = $this->params();
		$csv = (string)($in['csv'] ?? '');
		if (trim($csv) === '') {
			throw new ValidationException(['csv' => 'CSV body required.']);
		}
		$out = $this->importer->importReceipts($projectId, (string)($in['preset'] ?? 'generic'), $csv,
			(string)($in['filename'] ?? 'inline.csv'), $uid);
		return $this->ok($out);
	}
}

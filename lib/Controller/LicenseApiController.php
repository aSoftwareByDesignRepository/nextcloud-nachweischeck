<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * License administration surface (I-07): status, apply, remove.
 * App-admin only — the access door decides, never client-hidden UI.
 */

namespace OCA\NachweisCheck\Controller;

use OCA\NachweisCheck\Exception\ForbiddenException;
use OCA\NachweisCheck\Exception\ValidationException;
use OCA\NachweisCheck\Service\AccessDoorService;
use OCA\NachweisCheck\Service\LicenseService;
use OCA\NachweisCheck\Service\PermissionService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

class LicenseApiController extends NwApiController
{
	public function __construct(
		string $appName,
		IRequest $request,
		IUserSession $userSession,
		AccessDoorService $door,
		PermissionService $perm,
		private readonly LicenseService $license,
	) {
		parent::__construct($appName, $request, $userSession, $door, $perm);
	}

	#[NoAdminRequired]
	public function show(): JSONResponse
	{
		$this->requireAppAdmin();
		return $this->ok($this->license->status());
	}

	#[NoAdminRequired]
	public function apply(): JSONResponse
	{
		$uid = $this->requireAppAdmin();
		$in = $this->params();
		$key = $in['key'] ?? null;
		if (!is_string($key) || trim($key) === '') {
			throw new ValidationException(['key' => 'A license key is required.']);
		}
		return $this->ok($this->license->apply($uid, $key));
	}

	#[NoAdminRequired]
	public function remove(): JSONResponse
	{
		$uid = $this->requireAppAdmin();
		return $this->ok($this->license->remove($uid));
	}

	private function requireAppAdmin(): string
	{
		$user = $this->user();
		if (!$this->door->isAppAdmin($user)) {
			throw new ForbiddenException('App admin required.');
		}
		return $user->getUID();
	}
}

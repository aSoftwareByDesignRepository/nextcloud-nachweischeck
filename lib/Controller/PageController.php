<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NachweisCheck\Controller;

use OCA\NachweisCheck\Db\ProjectMapper;
use OCA\NachweisCheck\Service\AccessDoorService;
use OCA\NachweisCheck\Service\LicenseService;
use OCA\NachweisCheck\Service\PermissionService;
use OCA\NachweisCheck\Support\AssetRegistrar;
use OCA\NachweisCheck\Support\Chrome;
use OCA\NachweisCheck\Support\SettingsCatalog;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

class PageController extends Controller
{
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly AccessDoorService $door,
		private readonly LicenseService $license,
		private readonly PermissionService $perm,
		private readonly ProjectMapper $projectMapper,
		private readonly IInitialState $initialState,
		private readonly IURLGenerator $urlGenerator,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse
	{
		$user = $this->userSession->getUser();
		$allowed = $user !== null && ($this->door->isAllowed($user) || $this->door->isAppAdmin($user));
		if (!$allowed) {
			$resp = new TemplateResponse('nachweischeck', 'main', [
				'appAllowed' => false,
			]);
			$resp->setStatus(Http::STATUS_FORBIDDEN);
			return $resp;
		}
		$uid = $user->getUID();

		$projects = [];
		$isAppAdmin = $this->door->isAppAdmin($user);
		foreach ($this->projectMapper->findForUid($uid) as $p) {
			$projects[] = ['id' => $p->getId(), 'name' => $p->getName(), 'state' => $p->getState()];
		}
		$currentId = (int)($this->request->getParam('project', 0));
		$current = null;
		foreach ($projects as $p) {
			if ($p['id'] === $currentId) {
				$current = $p + ['caps' => $this->perm->capabilities($currentId, $uid)];
			}
		}
		$view = preg_replace('/[^a-z-]/', '', (string)$this->request->getParam('view', 'dashboard')) ?: 'dashboard';

		$this->initialState->provideInitialState('boot', [
			'apiBase' => '/api/v1',
			'uid' => $uid,
			'role' => ['isAppAdmin' => $isAppAdmin, 'isPro' => $this->license->isPro()],
			'projects' => $projects,
			'project' => $current,
			'view' => $view,
			'newProject' => $this->request->getParam('new') === '1',
		]);
		AssetRegistrar::register('workspace');

		$params = [
			'appAllowed' => true,
			'pageTitle' => $current !== null ? $current['name'] : 'NachweisCheck',
			'pageIcon' => 'clipboard-check',
			'pageHelp' => $current === null
				? 'Select a project on the left, or create your first funded project.'
				: '',
			'roleLabel' => $isAppAdmin ? 'App admin' : 'Member',
		];
		Chrome::apply($params, $current !== null ? 'view-' . $view : 'home', $projects, $current);
		return new TemplateResponse('nachweischeck', 'main', $params);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function settings(): RedirectResponse
	{
		return new RedirectResponse($this->urlGenerator->linkToRoute(
			'nachweischeck.page.settingsSection', ['section' => SettingsCatalog::SECTIONS[0]]));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function settingsSection(string $section): TemplateResponse|NotFoundResponse
	{
		$section = strtolower(trim($section));
		if (!SettingsCatalog::isSection($section)) {
			return new NotFoundResponse();
		}
		$user = $this->userSession->getUser();
		if ($user === null || !$this->door->isAppAdmin($user)) {
			$resp = new TemplateResponse('nachweischeck', 'main', ['appAllowed' => false]);
			$resp->setStatus(Http::STATUS_FORBIDDEN);
			return $resp;
		}
		$projects = [];
		foreach ($this->projectMapper->findForUid($user->getUID()) as $p) {
			$projects[] = ['id' => $p->getId(), 'name' => $p->getName(), 'state' => $p->getState()];
		}
		$this->initialState->provideInitialState('boot', [
			'apiBase' => '/api/v1',
			'uid' => $user->getUID(),
			'role' => ['isAppAdmin' => true, 'isPro' => $this->license->isPro()],
			'view' => 'settings',
			'section' => $section,
		]);
		AssetRegistrar::register('settings');
		$params = [
			'appAllowed' => true,
			'pageTitle' => 'NachweisCheck settings',
			'pageIcon' => 'settings',
			'settingsSection' => $section,
			'roleLabel' => 'App admin',
		];
		Chrome::apply($params, 'settings-' . $section, $projects, null);
		return new TemplateResponse('nachweischeck', 'settings', $params);
	}
}

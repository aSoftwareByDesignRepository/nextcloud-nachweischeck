<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NachweisCheck\Controller;

use OCA\NachweisCheck\ConfigTransfer\TransferReceiptStore;
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
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Util;

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
		private readonly IL10N $l10n,
		private readonly TransferReceiptStore $transferReceipts,
		private readonly IGroupManager $groupManager,
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
			AssetRegistrar::registerStyles();
			$resp = new TemplateResponse('nachweischeck', 'main', [
				'appAllowed' => false,
				'homeUrl' => $this->urlGenerator->linkToDefaultPageUrl(),
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
			'pageTitle' => $current !== null ? $current['name'] : $this->l10n->t('NachweisCheck'),
			'pageIcon' => 'clipboard-check',
			'pageHelp' => $current === null
				? $this->l10n->t('Select a project on the left, or create your first funded project.')
				: '',
			'roleLabel' => $isAppAdmin ? $this->l10n->t('App admin') : $this->l10n->t('Member'),
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
			AssetRegistrar::registerStyles();
			$resp = new TemplateResponse('nachweischeck', 'main', [
				'appAllowed' => false,
				'homeUrl' => $this->urlGenerator->linkToDefaultPageUrl(),
			]);
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
		AssetRegistrar::register($section === 'transfer' ? '' : 'settings');
		if ($section === 'transfer') {
			// Synced transfer page (test → production): server-rendered
			// partial, its own stylesheet + bridge script — the settings
			// SPA renderer is not involved.
			Util::addStyle('nachweischeck', 'common/transfer');
			Util::addScript('nachweischeck', 'transfer-bridge');
			Util::addScript('nachweischeck', 'transfer');
		}
		$params = [
			'appAllowed' => true,
			'pageTitle' => Chrome::sectionLabel($this->l10n, $section),
			'pageIcon' => 'settings',
			'settingsSection' => $section,
			'roleLabel' => $this->l10n->t('App admin'),
		];
		if ($section === 'transfer') {
			$params['isSystemAdmin'] = $this->groupManager->isAdmin($user->getUID());
			$params['transferLastReceipt'] = $this->transferReceipts->lastReceipt();
			$params['pageHelp'] = $this->l10n->t('Export all settings and data as one encrypted file, then import it on your production instance. Review the preview before confirming.');
		}
		Chrome::apply($params, 'settings-' . $section, $projects, null);
		return new TemplateResponse('nachweischeck', 'settings', $params);
	}
}

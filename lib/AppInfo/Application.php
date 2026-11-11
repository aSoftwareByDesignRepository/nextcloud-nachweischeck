<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NachweisCheck\AppInfo;

use OCP\Lock\ILockingProvider;
use OCP\App\IAppManager;

// horstoeko/zugferd lives in the app-local vendor directory. Nextcloud only
// autoloads OCA\* classes, so the app must load its own composer autoloader —
// otherwise E-Rechnung parsing fatals at runtime (tests mask the omission).
// CRITICAL: composer's autoload registers itself *prepended*; a vendor tree
// installed with dev stubs (nextcloud/ocp) would shadow the server's real OCP
// interfaces. Re-register appended so the server autoloader always wins for
// OCP\*/OC\* while the library still resolves here. (InvoiceCheck pattern.)
$nwAutoload = __DIR__ . '/../../vendor/autoload.php';
if (!class_exists(\horstoeko\zugferd\ZugferdDocumentReader::class, false) && is_file($nwAutoload)) {
	$nwLoader = require $nwAutoload;
	if ($nwLoader instanceof \Composer\Autoload\ClassLoader) {
		$nwLoader->unregister();
		$nwLoader->register(false);
	}
	unset($nwLoader);
}
unset($nwAutoload);
use OCA\NachweisCheck\Service\AccessDoorService;
use OCA\NachweisCheck\Service\UpgradeBackupService;
use OCA\NachweisCheck\Support\NavGate;
use OCA\NachweisCheck\Repair\BackupBeforeUpdate;
use OCA\NachweisCheck\Middleware\ApiExceptionMiddleware;
use OCA\NachweisCheck\Repair\EnsureNachweisCheckSchema;
use OCA\NachweisCheck\Repair\UninstallDropTables;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\IAppContainer;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IUserManager;

class Application extends App implements IBootstrap
{
	public const APP_ID = 'nachweischeck';

	public function __construct()
	{
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void
	{
		// Registered FIRST: dispatcher consults afterException middlewares in
		// reverse order, so this is the last-chance JSON envelope.
		$context->registerMiddleware(ApiExceptionMiddleware::class);

		// I-10: render deadline notifications pushed by DeadlineReminderJob.
		$context->registerNotifierService(\OCA\NachweisCheck\Notification\Notifier::class);

		// Repair steps MUST be registered with their full constructor args —
		// autowiring guesswork here stalls occ upgrade (repair-step-DI rule).
		$context->registerService(EnsureNachweisCheckSchema::class, function ($c): EnsureNachweisCheckSchema {
			return new EnsureNachweisCheckSchema(
				$c->get(IDBConnection::class),
				$c->get(IAppConfig::class),
			);
		});
		$context->registerService(UninstallDropTables::class, function ($c): UninstallDropTables {
			return new UninstallDropTables(
				$c->get(IDBConnection::class),
				$c->get(\OCP\IConfig::class),
				$c->get(IRootFolder::class),
			);
		});
		$context->registerService(UpgradeBackupService::class, function ($c): UpgradeBackupService {
			return new UpgradeBackupService(
				$c->get(\OCP\IDBConnection::class),
				$c->get(\OCP\IConfig::class),
				$c->get(IRootFolder::class),
				$c->get(IAppManager::class),
				$c->get(ILockingProvider::class),
				$c->get(\Psr\Log\LoggerInterface::class),
			);
		});

		$context->registerService(BackupBeforeUpdate::class, function ($c): BackupBeforeUpdate {
			return new BackupBeforeUpdate(
				$c->get(UpgradeBackupService::class),
			);
		});

		// ── Config & data transfer (test → production) ──────────────────
		$context->registerService(\OCA\NachweisCheck\ConfigTransfer\TransferManifest::class, fn () => new \OCA\NachweisCheck\ConfigTransfer\TransferManifest());
		$context->registerService(\OCA\NachweisCheck\ConfigTransfer\TransferHooks::class, function ($c): \OCA\NachweisCheck\ConfigTransfer\TransferHooks {
			return new \OCA\NachweisCheck\ConfigTransfer\TransferHooks(
				$c->get(AccessDoorService::class),
				$c->get(UpgradeBackupService::class),
				$c->get(\OCA\NachweisCheck\Service\AuditService::class),
				$c->get(\OCP\IUserManager::class),
				$c->get(\OCP\IGroupManager::class),
				$c->get(\Psr\Log\LoggerInterface::class),
			);
		});
		$context->registerService(\OCA\NachweisCheck\ConfigTransfer\TransferPendingStore::class, fn ($c) => new \OCA\NachweisCheck\ConfigTransfer\TransferPendingStore($c->get(IRootFolder::class), $c->get(\OCP\IConfig::class), self::APP_ID));
		$context->registerService(\OCA\NachweisCheck\ConfigTransfer\TransferReceiptStore::class, fn ($c) => new \OCA\NachweisCheck\ConfigTransfer\TransferReceiptStore($c->get(\OCP\IConfig::class), self::APP_ID));
		$context->registerService(\OCA\NachweisCheck\ConfigTransfer\TransferService::class, function ($c): \OCA\NachweisCheck\ConfigTransfer\TransferService {
			return new \OCA\NachweisCheck\ConfigTransfer\TransferService(
				$c->get(IDBConnection::class),
				$c->get(\OCP\IConfig::class),
				$c->get(\OCP\IUserManager::class),
				$c->get(\OCP\IGroupManager::class),
				$c->get(IRootFolder::class),
				$c->get(IAppManager::class),
				$c->get(ILockingProvider::class),
				$c->get(\OCP\IURLGenerator::class),
				$c->get(\Psr\Log\LoggerInterface::class),
				$c->get(\OCA\NachweisCheck\ConfigTransfer\TransferManifest::class),
				$c->get(\OCA\NachweisCheck\ConfigTransfer\TransferHooks::class),
				$c->get(\OCA\NachweisCheck\ConfigTransfer\TransferPendingStore::class),
				$c->get(\OCA\NachweisCheck\ConfigTransfer\TransferReceiptStore::class),
				self::APP_ID,
				$c->get(IAppConfig::class),
			);
		});
		$context->registerService(\OCA\NachweisCheck\BackgroundJob\TransferApplyJob::class, function ($c): \OCA\NachweisCheck\BackgroundJob\TransferApplyJob {
			return new \OCA\NachweisCheck\BackgroundJob\TransferApplyJob(
				$c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
				$c->get(\OCA\NachweisCheck\ConfigTransfer\TransferService::class),
				$c->get(ILockingProvider::class),
				$c->get(\OCP\BackgroundJob\IJobList::class),
				$c->get(\Psr\Log\LoggerInterface::class),
			);
		});
		$context->registerService(\OCA\NachweisCheck\Controller\TransferApiController::class, function ($c): \OCA\NachweisCheck\Controller\TransferApiController {
			return new \OCA\NachweisCheck\Controller\TransferApiController(
				self::APP_ID,
				$c->get(\OCP\IRequest::class),
				$c->get(\OCA\NachweisCheck\ConfigTransfer\TransferHooks::class),
				$c->get(\OCA\NachweisCheck\ConfigTransfer\TransferService::class),
				$c->get(\OCP\BackgroundJob\IJobList::class),
				$c->get(\OCP\Security\Bruteforce\IThrottler::class),
				$c->get(\OCP\IGroupManager::class),
				$c->get(\OCP\IUserSession::class),
			);
		});

		// I-10 Activity provider — registered explicitly so the Activity app
		// resolves it through this app's container (dutycheck pattern).
		$context->registerService(\OCA\NachweisCheck\Activity\Provider::class, function ($c): \OCA\NachweisCheck\Activity\Provider {
			return new \OCA\NachweisCheck\Activity\Provider(
				$c->get(\OCP\L10N\IFactory::class),
				$c->get(\OCP\IURLGenerator::class),
			);
		});

	}

	public function boot(IBootContext $context): void
	{
		$container = $context->getAppContainer();
		// Access-door-gated menu entry (nav-gate contract): a static
		// info.xml <navigations> block would advertise the app to users the
		// door refuses — dead end + existence oracle. Mirrors the
		// PageController/NwApiController predicate (allowed OR app-admin).
		NavGate::register(
			$container,
			'nachweischeck.page.index',
			'app.svg',
			'NachweisCheck',
			60,
			static function (string $uid, IAppContainer $container): bool {
				$user = $container->get(IUserManager::class)->get($uid);
				if ($user === null) {
					return false;
				}
				$door = $container->get(AccessDoorService::class);
				return $door->isAllowed($user) || $door->isAppAdmin($user);
			},
		);
	}
}

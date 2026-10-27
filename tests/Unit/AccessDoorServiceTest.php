<?php

declare(strict_types=1);

namespace OCA\NachweisCheck\Tests\Unit;

use OCA\NachweisCheck\Service\AccessDoorService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use Test\TestCase;

class AccessDoorServiceTest extends TestCase
{
	/**
	 * @param array<string, string> $cfg appconfig rows keyed by config key
	 */
	private function svc(array $cfg = [], array $restriction = [], array $adminUids = [], array $inGroup = []): array
	{
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')
			->willReturnCallback(function (string $app, string $key, string $default = '') use ($cfg): string {
				return $cfg[$key] ?? $default;
			});
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(
			fn(string $uid): bool => in_array($uid, $adminUids, true)
		);
		$groups->method('isInGroup')->willReturnCallback(
			fn(string $uid, string $gid): bool => in_array($uid . '@' . $gid, $inGroup, true)
		);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppRestriction')->willReturn($restriction);
		return ['svc' => new AccessDoorService($appConfig, $groups, $appManager),
			'cfg' => $appConfig, 'groups' => $groups, 'apps' => $appManager];
	}

	private function user(string $uid): IUser
	{
		$u = $this->createMock(IUser::class);
		$u->method('getUID')->willReturn($uid);
		return $u;
	}

	public function testAnonymousDenied(): void
	{
		['svc' => $svc] = $this->svc();
		$this->assertFalse($svc->isAllowed(null));
		$this->assertFalse($svc->isAppAdmin(null));
	}

	public function testNcAdminAlwaysAllowed(): void
	{
		['svc' => $svc] = $this->svc(['nw_access_mode' => 'restricted'], ['staff'], ['ncadmin']);
		$this->assertTrue($svc->isAllowed($this->user('ncadmin')));
		$this->assertTrue($svc->isAppAdmin($this->user('ncadmin')));
	}

	public function testOpenModeWithoutRestrictionAllowsEveryone(): void
	{
		['svc' => $svc] = $this->svc(['nw_access_mode' => 'open'], []);
		$this->assertTrue($svc->isAllowed($this->user('anyone')));
	}

	public function testPlatformRestrictionWinsOverOpenFlag(): void
	{
		// out-of-band "Limit to groups" on the Apps page must reach the door
		// even while the app flag still says Open (fail closed).
		['svc' => $svc] = $this->svc(['nw_access_mode' => 'open'], ['staff'], [], ['insider@staff']);
		$this->assertFalse($svc->isAllowed($this->user('outsider')));
		$this->assertTrue($svc->isAllowed($this->user('insider'))); // via platform group
	}

	public function testRestrictedModeMemberAndAllowlistChecks(): void
	{
		['svc' => $svc] = $this->svc([
			'nw_access_mode' => 'restricted',
			'nw_access_users' => '["alice"," bob "]',
			'nw_admin_uids' => '["appadmin"]',
		], ['staff'], [], ['carol@staff']);
		$this->assertTrue($svc->isAllowed($this->user('alice')));   // user allowlist
		$this->assertTrue($svc->isAllowed($this->user('appadmin'))); // app admin enters too
		$this->assertTrue($svc->isAllowed($this->user('carol')));   // group member
		$this->assertFalse($svc->isAllowed($this->user('mallory')));
	}

	public function testRestrictedWithNoAllowlistDeniesEveryone(): void
	{
		['svc' => $svc] = $this->svc(['nw_access_mode' => 'restricted'], []);
		$this->assertFalse($svc->isAllowed($this->user('alice')));
	}

	public function testAppAdminRoleFromUidListOnly(): void
	{
		['svc' => $svc] = $this->svc(['nw_admin_uids' => '["appadmin"]']);
		$this->assertTrue($svc->isAppAdmin($this->user('appadmin')));
		$this->assertFalse($svc->isAppAdmin($this->user('alice')));
	}

	public function testAllowedGroupsReadsPlatformOnly(): void
	{
		['svc' => $svc] = $this->svc([], ['staff', ' staff ', '', 'board']);
		$this->assertSame(['staff', 'board'], $svc->allowedGroups());
	}

	public function testModeNormalizesUnknownValuesToOpen(): void
	{
		['svc' => $svc] = $this->svc(['nw_access_mode' => 'weird']);
		$this->assertSame('open', $svc->mode());
		['svc' => $svc2] = $this->svc(['nw_access_mode' => 'restricted']);
		$this->assertSame('restricted', $svc2->mode());
	}

	public function testConfigShape(): void
	{
		['svc' => $svc] = $this->svc([
			'nw_access_mode' => 'restricted',
			'nw_access_users' => '["alice"]',
			'nw_admin_uids' => '["appadmin"]',
		], ['staff']);
		$this->assertSame([
			'mode' => 'restricted',
			'groups' => ['staff'],
			'users' => ['alice'],
			'admins' => ['appadmin'],
		], $svc->config());
	}

	public function testSaveConfigRestrictedMirrorsGroupsToPlatform(): void
	{
		$cfg = $this->createMock(IAppConfig::class);
		$writes = [];
		$cfg->method('setValueString')
			->willReturnCallback(function (string $app, string $key, string $value) use (&$writes): bool {
				$writes[$key] = $value;
				return true;
			});
		$groupObj = $this->createMock(IGroup::class);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturnCallback(
			fn(string $gid): ?IGroup => $gid === 'staff' ? $groupObj : null
		);
		$apps = $this->createMock(IAppManager::class);
		$apps->expects($this->once())->method('enableAppForGroups')
			->with('nachweischeck', [$groupObj]);
		$apps->expects($this->never())->method('enableApp');
		$svc = new AccessDoorService($cfg, $groups, $apps);
		// 'ghost' resolves to null → dropped from the platform mirror
		$svc->saveConfig('restricted', ['staff', 'ghost', 'staff', ''], ['alice'], ['appadmin']);
		$this->assertSame('restricted', $writes['nw_access_mode']);
		$this->assertSame('["alice"]', $writes['nw_access_users']);
		$this->assertSame('["appadmin"]', $writes['nw_admin_uids']);
	}

	public function testSaveConfigOpenClearsPlatformRestriction(): void
	{
		$cfg = $this->createMock(IAppConfig::class);
		$cfg->method('setValueString')->willReturn(true);
		$groups = $this->createMock(IGroupManager::class);
		$apps = $this->createMock(IAppManager::class);
		$apps->expects($this->once())->method('enableApp')->with('nachweischeck');
		$apps->expects($this->never())->method('enableAppForGroups');
		$svc = new AccessDoorService($cfg, $groups, $apps);
		$svc->saveConfig('open', [], [], []);
	}

	public function testSaveConfigRestrictedWithoutGroupsClearsPlatform(): void
	{
		$cfg = $this->createMock(IAppConfig::class);
		$cfg->method('setValueString')->willReturn(true);
		$groups = $this->createMock(IGroupManager::class);
		$apps = $this->createMock(IAppManager::class);
		$apps->expects($this->once())->method('enableApp')->with('nachweischeck');
		$svc = new AccessDoorService($cfg, $groups, $apps);
		$svc->saveConfig('restricted', [], ['alice'], []);
	}
}

<?php

declare(strict_types=1);

namespace OCA\NachweisCheck\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * Family rule (inactive_entity_assignable_via_api): directory pickers never
 * offer deactivated Nextcloud accounts AND every uid-accepting write path
 * behind them rejects nonexistent/deactivated uids — collapsed into the
 * same existence code, never a distinguishable "deactivated" oracle.
 */
final class DisabledAccountGuardContractTest extends TestCase
{
	private function src(string $rel): string
	{
		return (string)file_get_contents(dirname(__DIR__, 3) . '/' . $rel);
	}

	public function testDirectoryUsersSkipsDeactivatedAccounts(): void
	{
		$src = $this->src('lib/Controller/AdminApiController.php');
		$this->assertMatchesRegularExpression(
			'/function directoryUsers[\s\S]{0,1500}if \(!\$u->isEnabled\(\)\)/',
			$src,
			'AdminApiController::directoryUsers must skip deactivated accounts'
		);
	}

	public function testAddMemberRejectsDeactivatedAccounts(): void
	{
		$src = $this->src('lib/Controller/AdminApiController.php');
		$this->assertMatchesRegularExpression(
			'/function addMember[\s\S]{0,2000}!\$target->isEnabled\(\)[\s\S]{0,200}Unknown user\./',
			$src,
			'AdminApiController::addMember must fold !isEnabled() into the collapsed "Unknown user." arm'
		);
	}

	public function testCreateGrantRejectsDeactivatedAccounts(): void
	{
		$src = $this->src('lib/Controller/AdminApiController.php');
		$this->assertMatchesRegularExpression(
			'/function createGrant[\s\S]{0,2000}!\$ncUser->isEnabled\(\)[\s\S]{0,200}Unknown user\./',
			$src,
			'AdminApiController::createGrant must fold !isEnabled() into the collapsed "Unknown user." arm'
		);
	}

	public function testMobileSeatAssignRejectsMissingOrDeactivated(): void
	{
		$src = $this->src('lib/Service/MobileSeatService.php');
		$this->assertMatchesRegularExpression(
			'/function assign[\s\S]{0,1500}userManager->get\(\$uid\)[\s\S]{0,200}!\$target->isEnabled\(\)[\s\S]{0,200}Unknown user\./',
			$src,
			'MobileSeatService::assign must reject nonexistent/deactivated uids with the collapsed "Unknown user." code'
		);
	}

	public function testAddRateEnforcesLiveMembership(): void
	{
		$src = $this->src('lib/Service/RateService.php');
		$this->assertMatchesRegularExpression(
			'/function addRate[\s\S]{0,2000}userManager->get\(\$uid\)[\s\S]{0,400}!\$target->isEnabled\(\)[\s\S]{0,300}findByProjectAndUid[\s\S]{0,300}Unknown user\./',
			$src,
			'RateService::addRate must require uid existence + enabled + project membership, collapsed into "Unknown user."'
		);
	}

	public function testSaveAccessConfigWriteValidatesUids(): void
	{
		$src = $this->src('lib/Service/AccessDoorService.php');
		$this->assertMatchesRegularExpression(
			'/function saveConfig[\s\S]{0,800}assertLiveUids\(\$users[\s\S]{0,300}assertLiveUids\(\$admins/',
			$src,
			'AccessDoorService::saveConfig must write-validate users+admins BEFORE persisting'
		);
		$this->assertMatchesRegularExpression(
			'/function assertLiveUids[\s\S]{0,800}!\$u->isEnabled\(\)[\s\S]{0,200}Unknown user\./',
			$src,
			'AccessDoorService::assertLiveUids must collapse nonexistent and deactivated into "Unknown user."'
		);
	}
}

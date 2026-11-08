<?php

declare(strict_types=1);

namespace OCA\NachweisCheck\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * Family rule: directory pickers never offer deactivated Nextcloud accounts.
 */
final class DisabledAccountGuardContractTest extends TestCase
{
	public function testDirectoryUsersSkipsDeactivatedAccounts(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/AdminApiController.php');
		$this->assertMatchesRegularExpression(
			'/function directoryUsers[\s\S]{0,1500}if \(!\$u->isEnabled\(\)\)/',
			$src,
			'AdminApiController::directoryUsers must skip deactivated accounts'
		);
	}
}

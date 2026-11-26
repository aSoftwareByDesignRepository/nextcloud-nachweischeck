<?php
/** Synced from _shared/agent-auth — do not edit app-side. */

declare(strict_types=1);

namespace OCA\NachweisCheck\Tests\Unit\Agent;

use OCA\NachweisCheck\Agent\AgentException;
use OCA\NachweisCheck\Agent\AgentScopeChecker;
use PHPUnit\Framework\TestCase;

/**
 * Scope vocabulary is exact-match only — the checks an attacker would
 * probe first (wildcards, prefix tricks, case games) must all fail closed.
 */
class AgentScopeCheckerTest extends TestCase
{
	public function testValidFormatsAccepted(): void
	{
		$this->assertTrue(AgentScopeChecker::isValidFormat('nachweischeck:time_entries:read'));
		$this->assertTrue(AgentScopeChecker::isValidFormat('nachweischeck:invoices:write'));
		$this->assertTrue(AgentScopeChecker::isValidFormat('a1_b2:c3_d4:write'));
	}

	public function testInvalidFormatsRejected(): void
	{
		$cases = [
			'',
			'nachweischeck:time_entries',                 // missing action
			'nachweischeck:time_entries:read:extra',      // too many segments
			'nachweischeck:Time_Entries:READ',            // case games
			'nachweischeck:*:read',                       // wildcard resource
			'nachweischeck:time_entries:*',               // wildcard action
			'*:*:*',
			'nachweischeck:*',                            // app-wide wildcard
			'nachweischeck :time_entries:read',           // embedded space
			"nachweischeck:time_entries:read\n",          // control chars
			'nachweischeck:time-entries:read',            // dash not allowed
			'nachweischeck::read',                        // empty resource
			':time_entries:read',                      // empty app
			'nachweischeck:time_entries:delete',          // v1 action set is read|write only
		];
		foreach ($cases as $scope) {
			$this->assertFalse(
				AgentScopeChecker::isValidFormat($scope),
				"scope should be invalid: " . var_export($scope, true)
			);
		}
	}

	public function testPrefixGrantIsNotSatisfaction(): void
	{
		// granted scope that is a string-prefix of the requirement must NOT
		// satisfy — exact match is the whole security model.
		$this->assertFalse(
			AgentScopeChecker::satisfied(
				['nachweischeck:time_ent'],
				'nachweischeck:time_entries:write',
			)
		);
		$this->assertFalse(
			AgentScopeChecker::satisfied(
				['nachweischeck:time_entries:write_extra'],
				'nachweischeck:time_entries:write',
			)
		);
	}

	public function testReadDoesNotSatisfyWrite(): void
	{
		$granted = ['nachweischeck:time_entries:read'];
		$this->assertFalse(
			AgentScopeChecker::satisfied($granted, 'nachweischeck:time_entries:write'),
			'read scope must never satisfy a write requirement'
		);
		$this->assertTrue(AgentScopeChecker::satisfied($granted, 'nachweischeck:time_entries:read'));
	}

	public function testPrefixMatchIsNotSatisfaction(): void
	{
		$granted = ['nachweischeck:time_entr:write', 'nachweischeck:time_entries_extended:write'];
		$this->assertFalse(
			AgentScopeChecker::satisfied($granted, 'nachweischeck:time_entries:write'),
			'prefix/partial matches must not satisfy'
		);
	}

	public function testValidateGrantRequestDeduplicatesAndValidates(): void
	{
		$declared = ['nachweischeck:a:read', 'nachweischeck:b:write'];
		$grants = AgentScopeChecker::validateGrantRequest(
			['nachweischeck:a:read', ' nachweischeck:a:read ', 'nachweischeck:b:write'],
			$declared,
		);
		$this->assertSame(['nachweischeck:a:read', 'nachweischeck:b:write'], $grants);
	}

	public function testValidateGrantRequestRejectsUndeclared(): void
	{
		$this->expectException(AgentException::class);
		AgentScopeChecker::validateGrantRequest(
			['nachweischeck:secret:read'],
			['nachweischeck:a:read'],
		);
	}

	public function testValidateGrantRequestRejectsWildcard(): void
	{
		try {
			AgentScopeChecker::validateGrantRequest(['nachweischeck:*:read'], ['nachweischeck:a:read']);
			$this->fail('wildcard grant must throw');
		} catch (AgentException $e) {
			$this->assertSame('validation', $e->getErrorCode());
		}
	}

	public function testValidateGrantRequestRejectsEmpty(): void
	{
		try {
			AgentScopeChecker::validateGrantRequest(['  '], ['nachweischeck:a:read']);
			$this->fail('empty grant list must throw');
		} catch (AgentException $e) {
			$this->assertSame('validation', $e->getErrorCode());
		}
	}

	public function testValidateGrantRequestRejectsNonString(): void
	{
		try {
			AgentScopeChecker::validateGrantRequest([['nested']], ['nachweischeck:a:read']);
			$this->fail('non-string scope must throw');
		} catch (AgentException $e) {
			$this->assertSame('validation', $e->getErrorCode());
		}
	}
}

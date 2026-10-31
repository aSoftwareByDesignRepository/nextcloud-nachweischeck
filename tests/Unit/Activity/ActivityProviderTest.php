<?php

declare(strict_types=1);

namespace OCA\NachweisCheck\Tests\Unit\Activity;

use OCA\NachweisCheck\Activity\Provider;
use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use OCP\IL10N;
use OCP\L10N\IFactory;
use Test\TestCase;

class ActivityProviderTest extends TestCase
{
	private function event(string $app, string $subject, array $params = []): IEvent
	{
		$e = $this->createMock(IEvent::class);
		$e->method('getApp')->willReturn($app);
		$e->method('getSubject')->willReturn($subject);
		$e->method('getSubjectParameters')->willReturn($params);
		$e->method('setParsedSubject')->willReturnSelf();
		return $e;
	}

	private function provider(): Provider
	{
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(fn($s, $p = []) => vsprintf(str_replace(['%1$s','%2$s','%3$s'], ['%s','%s','%s'], $s), $p ?: []));
		$f = $this->createMock(IFactory::class);
		$f->method('get')->willReturn($l);
		return new Provider($f);
	}

	public function testForeignAppRejected(): void
	{
		$this->expectException(UnknownActivityException::class);
		$this->provider()->parse('en', $this->event('otherapp', 'project_activated'));
	}

	public function testUnknownSubjectRejected(): void
	{
		$this->expectException(UnknownActivityException::class);
		$this->provider()->parse('en', $this->event('nachweischeck', 'nonsense_subject'));
	}

	public function testKnownSubjectsParse(): void
	{
		$provider = $this->provider();
		foreach ([
			'project_activated' => ['P'],
			'project_state' => ['P', 'closed'],
			'finplan_activated' => ['P', '3'],
			'nachweis_generated' => ['P', 'final', '2'],
			'deviation_created' => ['P', 'position', 'cost'],
			'auditor_granted' => ['P', 'stb'],
			'auditor_revoked' => ['P', 'stb'],
			'reallocation_requested' => ['P'],
			'reallocation_decided' => ['P', 'approve'],
			'carryover_created' => ['P'],
		] as $subject => $params) {
			$event = $this->event('nachweischeck', $subject, $params);
			$parsed = null;
			$event->method('setParsedSubject')->willReturnCallback(function ($s) use (&$parsed, $event) {
				$parsed = $s;
				return $event;
			});
			$provider->parse('en', $event);
			$this->assertNotSame('', (string)$parsed, $subject);
			$this->assertStringContainsString('P', (string)$parsed, $subject);
		}
	}
}

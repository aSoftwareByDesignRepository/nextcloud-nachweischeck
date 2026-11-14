<?php

declare(strict_types=1);

namespace OCA\NachweisCheck\Tests\Unit\Activity;

use OCA\NachweisCheck\Activity\Provider;
use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use OCP\IL10N;
use OCP\IURLGenerator;
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
		$url = $this->createMock(IURLGenerator::class);
		$url->method('imagePath')->willReturnCallback(fn($app, $img) => "/apps/$app/img/$img");
		$url->method('getAbsoluteURL')->willReturnCallback(fn($u) => 'https://nc.test' . $u);
		return new Provider($f, $url);
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

	/**
	 * Regression pin (intake 20261031T203300Z): parse() must setIcon() on
	 * every event — the Activity app's ActivityModel throws "The icon
	 * argument is not a valid string" on an empty icon and the whole feed
	 * fails to render for every user with a nw_fiscal row.
	 */
	public function testParsedEventCarriesIcon(): void
	{
		$event = $this->event('nachweischeck', 'project_activated', ['P']);
		$icon = null;
		$event->method('setIcon')->willReturnCallback(function ($i) use (&$icon, $event) {
			$icon = $i;
			return $event;
		});
		$this->provider()->parse('en', $event);
		$this->assertIsString($icon);
		$this->assertNotSame('', $icon);
		$this->assertStringContainsString('app-dark.svg', $icon);
	}
}

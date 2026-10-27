<?php

declare(strict_types=1);

namespace OCA\NachweisCheck\Tests\Unit;

use OCA\NachweisCheck\Notification\Notifier;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\UnknownNotificationException;
use Test\TestCase;

class NotifierTest extends TestCase
{
	private function svc(): array
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			fn(string $text, $params = []): string => $params === [] ? $text : vsprintf($text, $params)
		);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);
		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRouteAbsolute')
			->with('nachweischeck.page.index')
			->willReturn('https://cloud.example/apps/nachweischeck/');
		return [new Notifier($factory, $url), $l10n, $url];
	}

	/** Notification mock with stateful parsed subject/message/link. */
	private function notification(string $app, string $subject, array $params): INotification
	{
		$n = $this->createMock(INotification::class);
		$n->method('getApp')->willReturn($app);
		$n->method('getSubject')->willReturn($subject);
		$n->method('getSubjectParameters')->willReturn($params);
		$state = ['subject' => '', 'message' => '', 'link' => ''];
		$n->method('setParsedSubject')->willReturnCallback(function ($s) use (&$state, $n) {
			$state['subject'] = $s;
			return $n;
		});
		$n->method('setParsedMessage')->willReturnCallback(function ($s) use (&$state, $n) {
			$state['message'] = $s;
			return $n;
		});
		$n->method('setLink')->willReturnCallback(function ($s) use (&$state, $n) {
			$state['link'] = $s;
			return $n;
		});
		$n->method('getParsedSubject')->willReturnCallback(function () use (&$state) {
			return $state['subject'];
		});
		$n->method('getParsedMessage')->willReturnCallback(function () use (&$state) {
			return $state['message'];
		});
		$n->method('getLink')->willReturnCallback(function () use (&$state) {
			return $state['link'];
		});
		return $n;
	}

	public function testPrepareRejectsForeignApp(): void
	{
		[$svc] = $this->svc();
		$this->expectException(UnknownNotificationException::class);
		$svc->prepare($this->notification('otherapp', 'nw_deadline', []), 'de');
	}

	public function testPrepareRejectsUnknownSubject(): void
	{
		[$svc] = $this->svc();
		$this->expectException(UnknownNotificationException::class);
		$svc->prepare($this->notification('nachweischeck', 'nonsense', []), 'de');
	}

	public function testDeadlineSubjectRenderedWithProjectAndDue(): void
	{
		[$svc] = $this->svc();
		$n = $this->notification('nachweischeck', 'nw_deadline', [
			'project' => 'Projekt X', 'due' => '2026-08-31', 'kind' => 'zwischen',
		]);
		$out = $svc->prepare($n, 'de');
		$this->assertSame(
			'Interim report (Zwischennachweis) due — Projekt X (2026-08-31)',
			$out->getParsedSubject()
		);
		$this->assertSame(
			'Open NachweisCheck for the deadline list and the Nachweis export.',
			$out->getParsedMessage()
		);
		$this->assertSame('https://cloud.example/apps/nachweischeck/', $out->getLink());
	}

	public function testDeadlineKindLabels(): void
	{
		[$svc] = $this->svc();
		$expected = [
			'nachweis_final' => 'Final funder report due',
			'zwischen' => 'Interim report (Zwischennachweis) due',
			'bew_end' => 'Funding period ends soon',
			'ap_end' => 'Work package ends',
			'unknown_kind' => 'Deadline',
		];
		foreach ($expected as $kind => $label) {
			$n = $this->notification('nachweischeck', 'nw_deadline', [
				'project' => 'P', 'due' => '2026-01-31', 'kind' => $kind,
			]);
			$out = $svc->prepare($n, 'en');
			$this->assertSame("{$label} — P (2026-01-31)", $out->getParsedSubject(), "kind {$kind}");
		}
	}

	public function testDeadlineWithoutProjectOmitsName(): void
	{
		[$svc] = $this->svc();
		$n = $this->notification('nachweischeck', 'nw_deadline', [
			'project' => '', 'due' => '2026-08-31', 'kind' => 'bew_end',
		]);
		$out = $svc->prepare($n, 'de');
		$this->assertSame('Funding period ends soon due 2026-08-31', $out->getParsedSubject());
	}

	public function testGetIdAndName(): void
	{
		[$svc] = $this->svc();
		$this->assertSame('nachweischeck', $svc->getID());
		$this->assertSame('NachweisCheck', $svc->getName());
	}
}

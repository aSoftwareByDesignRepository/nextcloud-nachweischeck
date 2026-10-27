<?php

declare(strict_types=1);

namespace OCA\NachweisCheck\Tests\Unit;

use OCA\NachweisCheck\Db\Drawdown;
use OCA\NachweisCheck\Db\DrawdownMapper;
use OCA\NachweisCheck\Db\IncomingPayment;
use OCA\NachweisCheck\Db\IncomingPaymentMapper;
use OCA\NachweisCheck\Db\Project;
use OCA\NachweisCheck\Exception\ConflictException;
use OCA\NachweisCheck\Exception\NotFoundException;
use OCA\NachweisCheck\Exception\ValidationException;
use OCA\NachweisCheck\Service\AuditService;
use OCA\NachweisCheck\Service\ConcurrencyGuard;
use OCA\NachweisCheck\Service\DrawdownService;
use OCA\NachweisCheck\Service\SollIstService;
use OCP\Lock\ILockingProvider;
use Test\TestCase;

class DrawdownServiceTest extends TestCase
{
	private function svc(?DrawdownMapper $m = null, ?IncomingPaymentMapper $pm = null, ?SollIstService $si = null, ?AuditService $audit = null): array
	{
		$m = $m ?? $this->createMock(DrawdownMapper::class);
		$pm = $pm ?? $this->createMock(IncomingPaymentMapper::class);
		$si = $si ?? $this->createMock(SollIstService::class);
		$audit = $audit ?? $this->createMock(AuditService::class);
		$guard = new ConcurrencyGuard($this->createMock(ILockingProvider::class));
		return [new DrawdownService($m, $pm, $si, $audit, $guard), $m, $pm, $si, $audit];
	}

	private function drawdown(int $id = 7, int $projectId = 1, string $state = Drawdown::STATE_REQUESTED): Drawdown
	{
		$d = new Drawdown();
		$d->setId($id);
		$d->setProjectId($projectId);
		$d->setState($state);
		$d->setAmountMinor(50000);
		$d->setRequestedDate(new \DateTime('2026-03-01'));
		return $d;
	}

	public function testInvalidDateRefused(): void
	{
		[$svc] = $this->svc();
		$this->expectException(ValidationException::class);
		$svc->create(1, 'not-a-date', 1000, null, null, null, 'u1');
	}

	public function testNonPositiveAmountRefused(): void
	{
		[$svc] = $this->svc();
		$this->expectException(ValidationException::class);
		try {
			$svc->create(1, '2026-03-01', 0, null, null, null, 'u1');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('amount_minor', $e->getFields());
			throw $e;
		}
	}

	public function testDuplicateExternalRefRefused(): void
	{
		$m = $this->createMock(DrawdownMapper::class);
		$m->method('findByExternalRef')->willReturn(new Drawdown());
		$m->expects($this->never())->method('insert');
		[$svc] = $this->svc($m);
		$this->expectException(ValidationException::class);
		try {
			$svc->create(1, '2026-03-01', 1000, null, null, 'ext-1', 'u1');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('external_ref', $e->getFields());
			throw $e;
		}
	}

	public function testCreatePersistsRequestedState(): void
	{
		$m = $this->createMock(DrawdownMapper::class);
		$m->method('insert')->willReturnCallback(function ($d) {
			$d->setId(3);
			return $d;
		});
		[$svc] = $this->svc($m);
		$d = $svc->create(1, '2026-03-01', 25000, 'A-7', 'note', null, 'u1');
		$this->assertSame(Drawdown::STATE_REQUESTED, $d->getState());
		$this->assertSame(25000, $d->getAmountMinor());
		$this->assertNull($d->getPaymentId());
	}

	public function testMarkReceivedBindsPayment(): void
	{
		$dd = $this->drawdown();
		$m = $this->createMock(DrawdownMapper::class);
		$m->method('findById')->willReturn($dd);
		$m->expects($this->once())->method('update');
		$pay = new IncomingPayment();
		$pay->setId(11);
		$pay->setProjectId(1);
		$pm = $this->createMock(IncomingPaymentMapper::class);
		$pm->method('findById')->willReturn($pay);
		[$svc] = $this->svc($m, $pm);
		$out = $svc->markReceived($dd, 11, 'u1');
		$this->assertSame(Drawdown::STATE_RECEIVED, $out->getState());
		$this->assertSame(11, $out->getPaymentId());
	}

	public function testMarkReceivedRefusesForeignPayment(): void
	{
		$dd = $this->drawdown();
		$m = $this->createMock(DrawdownMapper::class);
		$m->method('findById')->willReturn($dd);
		$pay = new IncomingPayment();
		$pay->setId(11);
		$pay->setProjectId(99); // different project — cross-project binding hole
		$pm = $this->createMock(IncomingPaymentMapper::class);
		$pm->method('findById')->willReturn($pay);
		[$svc] = $this->svc($m, $pm);
		$this->expectException(ValidationException::class);
		$svc->markReceived($dd, 11, 'u1');
	}

	public function testMarkReceivedOnDecidedRefuses(): void
	{
		$dd = $this->drawdown(7, 1, Drawdown::STATE_RECEIVED);
		$m = $this->createMock(DrawdownMapper::class);
		$m->method('findById')->willReturn($dd);
		[$svc] = $this->svc($m);
		$this->expectException(ConflictException::class);
		$svc->markReceived($dd, 11, 'u1');
	}

	public function testRejectTransitionsOnce(): void
	{
		$dd = $this->drawdown();
		$m = $this->createMock(DrawdownMapper::class);
		$m->method('findById')->willReturn($dd);
		$m->expects($this->once())->method('update');
		[$svc] = $this->svc($m);
		$this->assertSame(Drawdown::STATE_REJECTED, $svc->reject($dd, 'u1')->getState());
		$this->expectException(ConflictException::class);
		$svc->reject($dd, 'u1');
	}

	public function testGetOrFailScopeGuard(): void
	{
		$m = $this->createMock(DrawdownMapper::class);
		$m->method('findById')->willReturn($this->drawdown(7, 99));
		[$svc] = $this->svc($m);
		$this->expectException(NotFoundException::class);
		$svc->getOrFail(1, 7);
	}

	public function testForecastComputesBurnAndSuggestion(): void
	{
		$pay = new IncomingPayment();
		$pay->setAmountMinor(100000);
		$pm = $this->createMock(IncomingPaymentMapper::class);
		$pm->method('findByProject')->willReturn([$pay]);

		$pending = new Drawdown();
		$pending->setAmountMinor(20000);
		$m = $this->createMock(DrawdownMapper::class);
		$m->method('findByProjectState')->willReturn([$pending]);

		$si = $this->createMock(SollIstService::class);
		$si->method('forProject')->willReturn(['totals' => ['budget' => 240000, 'used' => 60000]]);

		[$svc] = $this->svc($m, $pm, $si);
		$p = new Project();
		$p->setId(1);
		$p->setBewStart(new \DateTime('2026-01-01'));
		$p->setBewEnd(new \DateTime('2026-12-31'));

		$f = $svc->forecast($p, '2026-07-01');
		// elapsed: Jan→Jul = 6 months; burn = 60000/6 = 10000/mo
		$this->assertSame(6, $f['elapsed_months']);
		$this->assertSame(5, $f['remaining_months']); // Jul1→Dec31 = 5 whole months
		$this->assertSame(10000, $f['burn_rate_minor']);
		// cash = 100000 - 60000 = 40000; runway = 4 months
		$this->assertSame(40000, $f['cash_minor']);
		$this->assertSame(4, $f['runway_months']);
		// projected remaining = 10000×5 = 50000; suggested = 50000-40000-20000 <0 → 0
		$this->assertSame(50000, $f['projected_remaining_spend_minor']);
		$this->assertSame(0, $f['suggested_drawdown_minor']);
	}

	public function testForecastSuggestedCoversShortfall(): void
	{
		$pm = $this->createMock(IncomingPaymentMapper::class);
		$pm->method('findByProject')->willReturn([]); // nothing received
		$m = $this->createMock(DrawdownMapper::class);
		$m->method('findByProjectState')->willReturn([]);
		$si = $this->createMock(SollIstService::class);
		$si->method('forProject')->willReturn(['totals' => ['budget' => 240000, 'used' => 60000]]);
		[$svc] = $this->svc($m, $pm, $si);
		$p = new Project();
		$p->setId(1);
		$p->setBewStart(new \DateTime('2026-01-01'));
		$p->setBewEnd(new \DateTime('2026-12-31'));
		$f = $svc->forecast($p, '2026-07-01');
		// burn 10000 × remaining 5 = 50000; cash -60000 → suggested 110000
		$this->assertSame(-60000, $f['cash_minor']);
		$this->assertSame(110000, $f['suggested_drawdown_minor']);
	}

	public function testForecastNoElapsedMonthsYieldsNulls(): void
	{
		$pm = $this->createMock(IncomingPaymentMapper::class);
		$pm->method('findByProject')->willReturn([]);
		$m = $this->createMock(DrawdownMapper::class);
		$m->method('findByProjectState')->willReturn([]);
		$si = $this->createMock(SollIstService::class);
		$si->method('forProject')->willReturn(['totals' => ['budget' => 0, 'used' => 0]]);
		[$svc] = $this->svc($m, $pm, $si);
		$p = new Project();
		$p->setId(1);
		$p->setBewStart(new \DateTime('2026-06-01'));
		$p->setBewEnd(new \DateTime('2026-12-31'));
		$f = $svc->forecast($p, '2026-06-01');
		$this->assertNull($f['burn_rate_minor']);
		$this->assertNull($f['runway_months']);
		$this->assertNull($f['suggested_drawdown_minor']);
	}
}

<?php

declare(strict_types=1);

namespace OCA\NachweisCheck\Tests\Unit;

use OCA\NachweisCheck\Db\IncomingPayment;
use OCA\NachweisCheck\Db\IncomingPaymentMapper;
use OCA\NachweisCheck\Exception\NotFoundException;
use OCA\NachweisCheck\Exception\ValidationException;
use OCA\NachweisCheck\Service\AuditService;
use OCA\NachweisCheck\Service\IncomingPaymentService;
use Test\TestCase;

class IncomingPaymentServiceTest extends TestCase
{
	private function svc(?IncomingPaymentMapper $m = null, ?AuditService $audit = null): array
	{
		$m = $m ?? $this->createMock(IncomingPaymentMapper::class);
		$audit = $audit ?? $this->createMock(AuditService::class);
		return [new IncomingPaymentService($m, $audit), $m, $audit];
	}

	public function testInvalidDateRefused(): void
	{
		[$svc] = $this->svc();
		$this->expectException(ValidationException::class);
		try {
			$svc->create(1, '31.12.2026', 10000, 'Abruf 1', '', null, 'u1');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('received_date', $e->getFields());
			throw $e;
		}
	}

	public function testNonPositiveAmountRefused(): void
	{
		[$svc] = $this->svc();
		$this->expectException(ValidationException::class);
		try {
			$svc->create(1, '2026-03-01', -5, 'Abruf 1', '', null, 'u1');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('amount_minor', $e->getFields());
			throw $e;
		}
	}

	public function testYearReceivedDerivedFromDate(): void
	{
		$m = $this->createMock(IncomingPaymentMapper::class);
		$m->method('insert')->willReturnCallback(function ($p) {
			$p->setId(1);
			return $p;
		});
		[$svc] = $this->svc($m);
		$p = $svc->create(1, '2026-12-31', 10000, 'Abruf 3', 'note', null, 'u1');
		$this->assertSame(2026, $p->getYearReceived());
		$this->assertSame('2026-12-31', $p->getReceivedDate()->format('Y-m-d'));
	}

	public function testExternalRefDuplicateRefused(): void
	{
		$m = $this->createMock(IncomingPaymentMapper::class);
		$m->method('findByExternalRef')->willReturn(new IncomingPayment());
		$m->expects($this->never())->method('insert');
		[$svc] = $this->svc($m);
		$this->expectException(ValidationException::class);
		try {
			$svc->create(1, '2026-03-01', 10000, 'Abruf 1', '', 'bank:tx-42', 'u1');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('external_ref', $e->getFields());
			throw $e;
		}
	}

	public function testGetOrFailScopeGuard(): void
	{
		$m = $this->createMock(IncomingPaymentMapper::class);
		$p = new IncomingPayment();
		$p->setProjectId(9);
		$m->method('findById')->willReturn($p);
		[$svc] = $this->svc($m);
		$this->expectException(NotFoundException::class);
		$svc->getOrFail(1, 5);
	}

	public function testDeleteAuditsBeforeDelete(): void
	{
		$m = $this->createMock(IncomingPaymentMapper::class);
		$m->expects($this->once())->method('delete');
		$audit = $this->createMock(AuditService::class);
		$audit->expects($this->once())->method('record')
			->with(1, 'incoming_payment', 5, 'delete', 'u1');
		[$svc] = $this->svc($m, $audit);
		$p = new IncomingPayment();
		$p->setId(5);
		$p->setProjectId(1);
		$p->setReceivedDate(new \DateTime('2026-03-01'));
		$p->setAmountMinor(10000);
		$svc->delete($p, 'u1');
	}
}

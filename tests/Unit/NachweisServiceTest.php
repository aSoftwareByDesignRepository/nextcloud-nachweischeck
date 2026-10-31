<?php

declare(strict_types=1);

namespace OCA\NachweisCheck\Tests\Unit;

use OCA\NachweisCheck\Db\ApPositionMapper;
use OCA\NachweisCheck\Db\CarryoverMapper;
use OCA\NachweisCheck\Db\ClaimMapper;
use OCA\NachweisCheck\Db\CofunderMapper;
use OCA\NachweisCheck\Db\DrawdownMapper;
use OCA\NachweisCheck\Db\FinplanVersion;
use OCA\NachweisCheck\Db\IncomingPayment;
use OCA\NachweisCheck\Db\IncomingPaymentMapper;
use OCA\NachweisCheck\Db\Nachweis;
use OCA\NachweisCheck\Db\NachweisMapper;
use OCA\NachweisCheck\Db\Position;
use OCA\NachweisCheck\Db\Project;
use OCA\NachweisCheck\Db\Receipt;
use OCA\NachweisCheck\Db\ReceiptMapper;
use OCA\NachweisCheck\Db\ReceiptSplitMapper;
use OCA\NachweisCheck\Db\TimeEntryMapper;
use OCA\NachweisCheck\Db\WorkPackageMapper;
use OCA\NachweisCheck\Db\WorkReportMapper;
use OCA\NachweisCheck\Exception\ConflictException;
use OCA\NachweisCheck\Exception\ValidationException;
use OCA\NachweisCheck\Service\AuditService;
use OCA\NachweisCheck\Service\ConcurrencyGuard;
use OCA\NachweisCheck\Service\FinplanService;
use OCA\NachweisCheck\Service\NachweisService;
use OCA\NachweisCheck\Support\ManifestHasher;
use OCP\Lock\ILockingProvider;
use Test\TestCase;

class NachweisServiceTest extends TestCase
{
	private function build(array $over = []): array
	{
		$m = [
			'nachweis' => $this->createMock(NachweisMapper::class),
			'receipt' => $this->createMock(ReceiptMapper::class),
			'split' => $this->createMock(ReceiptSplitMapper::class),
			'entry' => $this->createMock(TimeEntryMapper::class),
			'payment' => $this->createMock(IncomingPaymentMapper::class),
			'carry' => $this->createMock(CarryoverMapper::class),
			'claim' => $this->createMock(ClaimMapper::class),
			'cof' => $this->createMock(CofunderMapper::class),
			'ddown' => $this->createMock(DrawdownMapper::class),
			'ap' => $this->createMock(WorkPackageMapper::class),
			'report' => $this->createMock(WorkReportMapper::class),
			'appos' => $this->createMock(ApPositionMapper::class),
			'finplan' => $this->createMock(FinplanService::class),
			'audit' => $this->createMock(AuditService::class),
			'guard' => new ConcurrencyGuard($this->createMock(ILockingProvider::class)),
		];
		foreach ($over as $k => $v) {
			$m[$k] = $v;
		}
		$svc = new NachweisService($m['nachweis'], $m['receipt'], $m['split'], $m['entry'],
			$m['payment'], $m['carry'], $m['claim'], $m['cof'], $m['ddown'], $m['ap'], $m['report'], $m['appos'], $m['finplan'], $m['audit'], $m['guard']);
		return ['svc' => $svc] + $m;
	}

	private function project(): Project
	{
		$p = new Project();
		$p->setId(1);
		$p->setName('Test');
		$p->setTemplateKey('anbest-p');
		$p->setFinancingMode('ausgaben');
		return $p;
	}

	public function testZwischenFiltersToReceivedYear(): void
	{
		$payment = $this->createMock(IncomingPaymentMapper::class);
		$p2026 = new IncomingPayment();
		$p2026->setReceivedDate(new \DateTime('2026-03-01'));
		$p2026->setAmountMinor(10000);
		$p2026->setYearReceived(2026);
		$p2026->setCallRef('R1');
		$p2027 = new IncomingPayment();
		$p2027->setReceivedDate(new \DateTime('2027-01-05'));
		$p2027->setAmountMinor(20000);
		$p2027->setYearReceived(2027);
		$p2027->setCallRef('R2');
		$payment->method('findByProject')->willReturn([$p2026, $p2027]);
		$m = $this->build(['payment' => $payment]);
		$svc = $m['svc'];
		$carry = $m['carry'];
		$ap = $m['ap'];
		$carry->method('findByProject')->willReturn([]);
		$ap->method('findByProject')->willReturn([]);
		$payload = $svc->buildPayload($this->project(), 'zwischen', '2026-01-01', '2026-12-31');
		$this->assertCount(1, $payload['einnahmen']);
		$this->assertSame(10000, $payload['einnahmen'][0]['amount_minor']);
	}

	public function testFinalIncludesAllPeriodPaymentsAndCarryover(): void
	{
		$payment = $this->createMock(IncomingPaymentMapper::class);
		$p = new IncomingPayment();
		$p->setReceivedDate(new \DateTime('2026-03-01'));
		$p->setAmountMinor(10000);
		$p->setYearReceived(2026);
		$p->setCallRef('R1');
		$payment->method('findByProject')->willReturn([$p]);
		$carry = $this->createMock(CarryoverMapper::class);
		$c = new \OCA\NachweisCheck\Db\Carryover();
		$c->setFromYear(2025);
		$c->setToYear(2026);
		$c->setAmountMinor(5000);
		$c->setDocRef('B-44');
		$carry->method('findByProject')->willReturn([$c]);
		$ap = $this->createMock(WorkPackageMapper::class);
		$ap->method('findByProject')->willReturn([]);
		$m = $this->build(['payment' => $payment, 'carry' => $carry, 'ap' => $ap]); $svc = $m['svc'];
		$payload = $svc->buildPayload($this->project(), 'final', '2026-01-01', '2026-12-31');
		$kinds = array_column($payload['einnahmen'], 'kind');
		$this->assertContains('zuwendung', $kinds);
		$this->assertContains('mitteluebertrag', $kinds);
	}

	public function testGapsEnumerateEveryIncompleteRecord(): void
	{
		$receipt = $this->createMock(ReceiptMapper::class);
		$r = new Receipt();
		$r->setId(9);
		$r->setVendor('X');
		$r->setInvoiceDate(new \DateTime('2026-02-01'));
		$r->setFileId(null);
		$r->setInvoiceNo(null);
		$r->setPaymentDate(null);
		$r->setTotalMinor(1000);
		$r->setKind('receipt');
		$receipt->method('findByProjectPeriod')->willReturn([$r]);
		$entry = $this->createMock(TimeEntryMapper::class);
		$t = new \OCA\NachweisCheck\Db\TimeEntry();
		$t->setId(4);
		$t->setUid('u1');
		$t->setApId(0);
		$t->setEntryDate(new \DateTime('2026-02-02'));
		$t->setRateMinor(null);
		$t->setAmountMinor(null);
		$entry->method('findByProjectPeriod')->willReturn([$t]);
		$ap = $this->createMock(WorkPackageMapper::class);
		$ap->method('findByProject')->willReturn([]);
		$m = $this->build(['receipt' => $receipt, 'entry' => $entry, 'ap' => $ap]); $svc = $m['svc']; $pay = $m['payment']; $carry = $m['carry'];
		$pay->method('findByProject')->willReturn([]);
		$carry->method('findByProject')->willReturn([]);
		$payload = $svc->buildPayload($this->project(), 'final', '2026-01-01', '2026-12-31');
		$types = array_column($payload['fehlende_angaben'], 'type');
		$this->assertContains('missing_attachment', $types);
		$this->assertContains('missing_invoice_no', $types);
		$this->assertContains('missing_payment_date', $types);
		$this->assertContains('unpriced_time', $types);
	}

	public function testGeneratePersistsHashAndVersion(): void
	{
		$nm = $this->createMock(NachweisMapper::class);
		$nm->method('maxVersion')->willReturn(0);
		$nm->method('insert')->willReturnCallback(function ($n) {
			$n->setId(1);
			return $n;
		});
		$ap = $this->createMock(WorkPackageMapper::class);
		$ap->method('findByProject')->willReturn([]);
		$pay = $this->createMock(IncomingPaymentMapper::class);
		$pay->method('findByProject')->willReturn([]);
		$carry = $this->createMock(CarryoverMapper::class);
		$carry->method('findByProject')->willReturn([]);
		$rcpt = $this->createMock(ReceiptMapper::class);
		$rcpt->method('findByProjectPeriod')->willReturn([]);
		$entry = $this->createMock(TimeEntryMapper::class);
		$entry->method('findByProjectPeriod')->willReturn([]);
		$m = $this->build(['nachweis' => $nm, 'ap' => $ap, 'payment' => $pay, 'carry' => $carry, 'receipt' => $rcpt, 'entry' => $entry]); $svc = $m['svc'];
		$n = $svc->generate($this->project(), 'final', '2026-01-01', '2026-12-31', 'admin');
		$this->assertSame(1, $n->getVersion());
		$this->assertSame(64, strlen($n->getManifestHash()));
		$this->assertSame('final', $n->getState());
	}

	public function testSupersedeRequiresReasonAndFinalState(): void
	{
		$nm = $this->createMock(NachweisMapper::class);
		$old = new Nachweis();
		$old->setId(3);
		$old->setProjectId(1);
		$old->setState('superseded'); // already superseded -> refuse
		$nm->method('findById')->willReturn($old);
		$nm->method('maxVersion')->willReturn(2);
		$m = $this->build(['nachweis' => $nm]); $svc = $m['svc'];
		$this->expectException(ConflictException::class);
		$svc->generate($this->project(), 'final', '2026-01-01', '2026-12-31', 'admin', 3, 'fix');
	}

	public function testVerifyDetectsTamper(): void
	{
		$nm = $this->createMock(NachweisMapper::class);
		$nm->method('insert')->willReturnCallback(function ($n) {
			$n->setId(1);
			return $n;
		});
		$nm->method('maxVersion')->willReturn(0);
		$ap = $this->createMock(WorkPackageMapper::class);
		$ap->method('findByProject')->willReturn([]);
		$pay = $this->createMock(IncomingPaymentMapper::class);
		$pay->method('findByProject')->willReturn([]);
		$carry = $this->createMock(CarryoverMapper::class);
		$carry->method('findByProject')->willReturn([]);
		$rcpt = $this->createMock(ReceiptMapper::class);
		$rcpt->method('findByProjectPeriod')->willReturn([]);
		$entry = $this->createMock(TimeEntryMapper::class);
		$entry->method('findByProjectPeriod')->willReturn([]);
		$m = $this->build(['nachweis' => $nm, 'ap' => $ap, 'payment' => $pay, 'carry' => $carry, 'receipt' => $rcpt, 'entry' => $entry]); $svc = $m['svc'];
		$n = $svc->generate($this->project(), 'final', '2026-01-01', '2026-12-31', 'admin');
		$this->assertTrue($svc->verify($n));
		// tamper: rewrite payload after generation
		$payload = json_decode($n->getPayloadJson(), true);
		$payload['ausgaben'][] = ['code' => 'XX', 'title' => 'fraud', 'kind' => 'sach', 'budget_minor' => 1, 'used_minor' => 1];
		$n->setPayloadJson(json_encode($payload));
		$this->assertFalse($svc->verify($n));
	}

	public function testGenerateMarksPeriodReceiptsFiled(): void
	{
		$receipt = $this->createMock(ReceiptMapper::class);
		$r = new Receipt();
		$r->setId(5);
		$r->setState('open');
		$receipt->method('findByProjectPeriod')->willReturn([$r]);
		$receipt->expects($this->once())->method('update')->with($r);
		$nachweis = $this->createMock(NachweisMapper::class);
		$nachweis->method('insert')->willReturnCallback(function ($n) { $n->setId(1); return $n; });
		$nachweis->method('maxVersion')->willReturn(0);
		$m = $this->build([
			'receipt' => $receipt, 'nachweis' => $nachweis,
		]);
		$n = $m['svc']->generate($this->project(), 'final', '2026-01-01', '2026-12-31', 'fin');
		$this->assertSame('filed', $r->getState());
		$this->assertSame('final', $n->getState());
	}
}

<?php

declare(strict_types=1);

namespace OCA\NachweisCheck\Tests\Unit;

use OCA\NachweisCheck\Db\FinplanVersion;
use OCA\NachweisCheck\Db\Position;
use OCA\NachweisCheck\Db\PositionMapper;
use OCA\NachweisCheck\Db\Receipt;
use OCA\NachweisCheck\Db\ReceiptMapper;
use OCA\NachweisCheck\Db\ReceiptSplit;
use OCA\NachweisCheck\Db\ReceiptSplitMapper;
use OCA\NachweisCheck\Exception\NotFoundException;
use OCA\NachweisCheck\Exception\ValidationException;
use OCA\NachweisCheck\Service\AuditService;
use OCA\NachweisCheck\Service\FinplanService;
use OCA\NachweisCheck\Service\ReceiptService;
use OCP\IDBConnection;
use Test\TestCase;

class ReceiptServiceTest extends TestCase
{
	private function svc(array $over = []): array
	{
		$m = [
			'mapper' => $this->createMock(ReceiptMapper::class),
			'splits' => $this->createMock(ReceiptSplitMapper::class),
			'pos' => $this->createMock(PositionMapper::class),
			'finplan' => $this->createMock(FinplanService::class),
			'audit' => $this->createMock(AuditService::class),
			'db' => $this->createMock(IDBConnection::class),
		];
		foreach ($over as $k => $v) {
			$m[$k] = $v;
		}
		return ['svc' => new ReceiptService($m['mapper'], $m['splits'], $m['pos'], $m['finplan'], $m['audit'], $m['db'])] + $m;
	}

	/** Service wired for a project with active plan ver 7 and positions 10/11. */
	private function svcWithPlan(array $over = []): array
	{
		$active = new FinplanVersion();
		$active->setId(7);
		$finplan = $this->createMock(FinplanService::class);
		$finplan->method('activeFor')->willReturn($active);
		$pos = $this->createMock(PositionMapper::class);
		$pos->method('findByPlanVer')->willReturn([
			$this->pos(10), $this->pos(11),
		]);
		return $this->svc($over + ['finplan' => $finplan, 'pos' => $pos]);
	}

	private function pos(int $id): Position
	{
		$p = new Position();
		$p->setId($id);
		$p->setPlanVerId(7);
		$p->setCode('P' . $id);
		$p->setTitle('t');
		$p->setKind('sach');
		$p->setBudgetMinor(1);
		return $p;
	}

	private function receipt(): Receipt
	{
		$r = new Receipt();
		$r->setId(3);
		$r->setProjectId(1);
		$r->setVendor('Vendor');
		$r->setInvoiceNo('R-1');
		$r->setInvoiceDate(new \DateTime('2026-02-01'));
		$r->setPaymentDate(null);
		$r->setCurrency('EUR');
		$r->setFxRateMilli(null);
		$r->setTotalMinor(10000);
		$r->setTaxMinor(0);
		$r->setPurpose('p');
		$r->setFileId(null);
		$r->setKind('receipt');
		$r->setState('open');
		$r->setDupFlag(true);
		$r->setExternalRef(null);
		$r->setSalaryPeriod(null);
		return $r;
	}

	private function createArgs(array $over = []): array
	{
		return $over + [
			'projectId' => 1, 'vendor' => 'Vendor', 'invoiceNo' => 'R-1',
			'invoiceDate' => '2026-02-01', 'paymentDate' => null,
			'currency' => 'EUR', 'fx' => null, 'total' => 10000, 'tax' => 0,
			'purpose' => 'p', 'fileId' => null, 'kind' => 'receipt',
			'splits' => [['position_id' => 10, 'amount_minor' => 10000]],
			'ref' => null, 'actor' => 'u1', 'confirm' => false, 'salaryPeriod' => null,
		];
	}

	private function create(array $a): Receipt
	{
		return $this->svcWithPlan()['svc']->create(
			$a['projectId'], $a['vendor'], $a['invoiceNo'], $a['invoiceDate'],
			$a['paymentDate'], $a['currency'], $a['fx'], $a['total'], $a['tax'],
			$a['purpose'], $a['fileId'], $a['kind'], $a['splits'], $a['ref'],
			$a['actor'], $a['confirm'], $a['salaryPeriod'],
		);
	}

	// ---- field validation ----------------------------------------------

	public function testForeignCurrencyWithoutFxRefused(): void
	{
		$this->expectException(ValidationException::class);
		try {
			$this->create($this->createArgs(['currency' => 'USD']));
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('fx_rate_milli', $e->getFields());
			throw $e;
		}
	}

	public function testForeignCurrencyWithZeroFxRefused(): void
	{
		$this->expectException(ValidationException::class);
		try {
			$this->create($this->createArgs(['currency' => 'USD', 'fx' => 0]));
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('fx_rate_milli', $e->getFields());
			throw $e;
		}
	}

	public function testForeignCurrencyWithFxAccepted(): void
	{
		$mapper = $this->createMock(ReceiptMapper::class);
		$mapper->method('insert')->willReturnCallback(function ($r) {
			$r->setId(1);
			return $r;
		});
		$m = $this->svcWithPlan(['mapper' => $mapper]);
		$a = $this->createArgs(['currency' => 'USD', 'fx' => 1100000]);
		$r = $m['svc']->create($a['projectId'], $a['vendor'], $a['invoiceNo'], $a['invoiceDate'],
			$a['paymentDate'], $a['currency'], $a['fx'], $a['total'], $a['tax'], $a['purpose'],
			$a['fileId'], $a['kind'], $a['splits'], $a['ref'], $a['actor'], $a['confirm'], $a['salaryPeriod']);
		$this->assertSame('USD', $r->getCurrency());
		$this->assertSame(1100000, $r->getFxRateMilli());
	}

	public function testSalaryKindRequiresSalaryPeriod(): void
	{
		$this->expectException(ValidationException::class);
		try {
			$this->create($this->createArgs(['kind' => 'salary']));
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('salary_period', $e->getFields());
			throw $e;
		}
	}

	public function testSalaryPeriodFormatEnforced(): void
	{
		foreach (['2026-13', '26-03', '2026-3-1', '202603'] as $bad) {
			try {
				$this->create($this->createArgs(['kind' => 'salary', 'salaryPeriod' => $bad]));
				$this->fail("expected ValidationException for {$bad}");
			} catch (ValidationException $e) {
				$this->assertArrayHasKey('salary_period', $e->getFields());
			}
		}
	}

	public function testValidSalaryReceiptStored(): void
	{
		$mapper = $this->createMock(ReceiptMapper::class);
		$mapper->method('insert')->willReturnCallback(function ($r) {
			$r->setId(1);
			return $r;
		});
		$m = $this->svcWithPlan(['mapper' => $mapper]);
		$a = $this->createArgs(['kind' => 'salary', 'salaryPeriod' => '2026-03']);
		$r = $m['svc']->create($a['projectId'], $a['vendor'], $a['invoiceNo'], $a['invoiceDate'],
			$a['paymentDate'], $a['currency'], $a['fx'], $a['total'], $a['tax'], $a['purpose'],
			$a['fileId'], $a['kind'], $a['splits'], $a['ref'], $a['actor'], $a['confirm'], $a['salaryPeriod']);
		$this->assertSame('salary', $r->getKind());
		$this->assertSame('2026-03', $r->getSalaryPeriod());
	}

	public function testSplitSumMismatchRefused(): void
	{
		$this->expectException(ValidationException::class);
		try {
			$this->create($this->createArgs([
				'splits' => [['position_id' => 10, 'amount_minor' => 9999]],
			]));
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('splits', $e->getFields());
			throw $e;
		}
	}

	public function testSplitPositionOutsideActivePlanRefused(): void
	{
		$this->expectException(ValidationException::class);
		try {
			$this->create($this->createArgs([
				'splits' => [['position_id' => 999, 'amount_minor' => 10000]],
			]));
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('splits', $e->getFields());
			throw $e;
		}
	}

	public function testNoActivePlanRefused(): void
	{
		$finplan = $this->createMock(FinplanService::class);
		$finplan->method('activeFor')->willReturn(null);
		$m = $this->svc(['finplan' => $finplan]);
		$this->expectException(ValidationException::class);
		try {
			$a = $this->createArgs();
			$m['svc']->create($a['projectId'], $a['vendor'], $a['invoiceNo'], $a['invoiceDate'],
				$a['paymentDate'], $a['currency'], $a['fx'], $a['total'], $a['tax'], $a['purpose'],
				$a['fileId'], $a['kind'], $a['splits'], $a['ref'], $a['actor']);
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('splits', $e->getFields());
			throw $e;
		}
	}

	public function testExternalRefDuplicateRefused(): void
	{
		$mapper = $this->createMock(ReceiptMapper::class);
		$mapper->method('findByExternalRef')->willReturn(new Receipt());
		$mapper->expects($this->never())->method('insert');
		$m = $this->svcWithPlan(['mapper' => $mapper]);
		$this->expectException(ValidationException::class);
		try {
			$a = $this->createArgs(['ref' => 'import:1']);
			$m['svc']->create($a['projectId'], $a['vendor'], $a['invoiceNo'], $a['invoiceDate'],
				$a['paymentDate'], $a['currency'], $a['fx'], $a['total'], $a['tax'], $a['purpose'],
				$a['fileId'], $a['kind'], $a['splits'], $a['ref'], $a['actor']);
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('external_ref', $e->getFields());
			throw $e;
		}
	}

	public function testCreateWrapsReceiptAndSplitsInTransaction(): void
	{
		$mapper = $this->createMock(ReceiptMapper::class);
		$mapper->method('insert')->willReturnCallback(function ($r) {
			$r->setId(5);
			return $r;
		});
		$splitMapper = $this->createMock(ReceiptSplitMapper::class);
		$written = [];
		$splitMapper->expects($this->exactly(2))->method('insert')
			->willReturnCallback(function ($s) use (&$written) {
				$written[] = $s;
				return $s;
			});
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->once())->method('beginTransaction');
		$db->expects($this->once())->method('commit');
		$audit = $this->createMock(AuditService::class);
		$audit->expects($this->once())->method('record')
			->with(1, 'receipt', 5, 'create', 'u1');
		$m = $this->svcWithPlan(['mapper' => $mapper, 'splits' => $splitMapper, 'db' => $db, 'audit' => $audit]);
		$a = $this->createArgs([
			'splits' => [
				['position_id' => 10, 'ap_id' => 4, 'amount_minor' => 6000, 'note' => 'a'],
				['position_id' => 11, 'amount_minor' => 4000],
			],
		]);
		$r = $m['svc']->create($a['projectId'], $a['vendor'], $a['invoiceNo'], $a['invoiceDate'],
			$a['paymentDate'], $a['currency'], $a['fx'], $a['total'], $a['tax'], $a['purpose'],
			$a['fileId'], $a['kind'], $a['splits'], $a['ref'], $a['actor']);
		$this->assertSame('open', $r->getState());
		$this->assertFalse($r->getDupFlag());
		$this->assertSame(5, $written[0]->getReceiptId());
		$this->assertSame(4, $written[0]->getApId());
		$this->assertNull($written[1]->getApId());
		$this->assertSame(6000, $written[0]->getAmountMinor());
	}

	public function testSoftDuplicateSetsFlagUnlessConfirmed(): void
	{
		$dup = new Receipt();
		$dup->setId(88);
		$mapper = $this->createMock(ReceiptMapper::class);
		$mapper->method('findDuplicate')->willReturn($dup);
		$mapper->method('insert')->willReturnCallback(function ($r) {
			$r->setId(1);
			return $r;
		});
		$m = $this->svcWithPlan(['mapper' => $mapper]);
		$a = $this->createArgs();
		$r = $m['svc']->create($a['projectId'], $a['vendor'], $a['invoiceNo'], $a['invoiceDate'],
			$a['paymentDate'], $a['currency'], $a['fx'], $a['total'], $a['tax'], $a['purpose'],
			$a['fileId'], $a['kind'], $a['splits'], $a['ref'], $a['actor'], false);
		$this->assertTrue($r->getDupFlag());
		// caller confirmed the duplicate → flag stays off
		$r = $m['svc']->create($a['projectId'], $a['vendor'], $a['invoiceNo'], $a['invoiceDate'],
			$a['paymentDate'], $a['currency'], $a['fx'], $a['total'], $a['tax'], $a['purpose'],
			$a['fileId'], $a['kind'], $a['splits'], $a['ref'], $a['actor'], true);
		$this->assertFalse($r->getDupFlag());
	}

	// ---- update ---------------------------------------------------------

	public function testUpdateRefusesTotalChangeThatBreaksSplits(): void
	{
		$splitMapper = $this->createMock(ReceiptSplitMapper::class);
		$s = new ReceiptSplit();
		$s->setAmountMinor(10000); // still the old total
		$splitMapper->method('findByReceipt')->willReturn([$s]);
		$m = $this->svcWithPlan(['splits' => $splitMapper]);
		$this->expectException(ValidationException::class);
		try {
			$m['svc']->update($this->receipt(), ['total_minor' => 5000], 'u1');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('total_minor', $e->getFields());
			throw $e;
		}
	}

	public function testUpdateAllowsTotalChangeWhenSplitsStillMatch(): void
	{
		$splitMapper = $this->createMock(ReceiptSplitMapper::class);
		$s = new ReceiptSplit();
		$s->setAmountMinor(5000);
		$splitMapper->method('findByReceipt')->willReturn([$s]);
		$mapper = $this->createMock(ReceiptMapper::class);
		$mapper->method('update')->willReturnCallback(fn($r) => $r);
		$mapper->method('findDuplicate')->willReturn(null);
		$m = $this->svcWithPlan(['splits' => $splitMapper, 'mapper' => $mapper]);
		$r = $m['svc']->update($this->receipt(), ['total_minor' => 5000], 'u1');
		$this->assertSame(5000, $r->getTotalMinor());
	}

	public function testDupAckClearsFlagWithoutRequery(): void
	{
		$mapper = $this->createMock(ReceiptMapper::class);
		$mapper->expects($this->never())->method('findDuplicate');
		$mapper->method('update')->willReturnCallback(fn($r) => $r);
		$m = $this->svcWithPlan(['mapper' => $mapper]);
		$r = $m['svc']->update($this->receipt(), ['dup_ack' => '1'], 'u1');
		$this->assertFalse($r->getDupFlag());
	}

	public function testUpdateRecomputesDupFlagExcludingSelf(): void
	{
		$mapper = $this->createMock(ReceiptMapper::class);
		$mapper->method('update')->willReturnCallback(fn($r) => $r);
		$mapper->expects($this->once())->method('findDuplicate')
			->with(1, 'Vendor', '2026-02-01', 10000, 'R-1', 3)
			->willReturn(null);
		$m = $this->svcWithPlan(['mapper' => $mapper]);
		$r = $m['svc']->update($this->receipt(), [], 'u1');
		$this->assertFalse($r->getDupFlag());
	}

	public function testUpdateSalaryPeriodValidationUsesStoredKind(): void
	{
		$r = $this->receipt();
		$r->setKind('salary');
		$r->setSalaryPeriod('2026-01');
		$m = $this->svcWithPlan();
		$this->expectException(ValidationException::class);
		try {
			// clearing the period on a salary receipt must be refused
			$m['svc']->update($r, ['salary_period' => ''], 'u1');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('salary_period', $e->getFields());
			throw $e;
		}
	}

	// ---- replaceSplits / getOrFail --------------------------------------

	public function testReplaceSplitsAtomicRewrite(): void
	{
		$splitMapper = $this->createMock(ReceiptSplitMapper::class);
		$splitMapper->expects($this->once())->method('deleteByReceipt')->with(3);
		$splitMapper->expects($this->exactly(2))->method('insert')
			->willReturnCallback(fn($s) => $s);
		$mapper = $this->createMock(ReceiptMapper::class);
		$mapper->method('update')->willReturnCallback(fn($r) => $r);
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->once())->method('commit');
		$m = $this->svcWithPlan(['splits' => $splitMapper, 'mapper' => $mapper, 'db' => $db]);
		$m['svc']->replaceSplits($this->receipt(), [
			['position_id' => 10, 'amount_minor' => 4000],
			['position_id' => 11, 'amount_minor' => 6000],
		], 'u1');
	}

	public function testGetOrFailScopeGuard(): void
	{
		$mapper = $this->createMock(ReceiptMapper::class);
		$r = new Receipt();
		$r->setProjectId(9);
		$mapper->method('findById')->willReturn($r);
		$m = $this->svcWithPlan(['mapper' => $mapper]);
		$this->expectException(NotFoundException::class);
		$m['svc']->getOrFail(1, 3);
	}
}

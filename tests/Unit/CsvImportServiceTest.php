<?php

declare(strict_types=1);

namespace OCA\NachweisCheck\Tests\Unit;

use OCA\NachweisCheck\Db\FinplanVersion;
use OCA\NachweisCheck\Db\ImportBatch;
use OCA\NachweisCheck\Db\ImportBatchMapper;
use OCA\NachweisCheck\Db\Position;
use OCA\NachweisCheck\Db\ReceiptMapper;
use OCA\NachweisCheck\Db\TimeEntry;
use OCA\NachweisCheck\Db\TimeEntryMapper;
use OCA\NachweisCheck\Db\WorkPackage;
use OCA\NachweisCheck\Exception\ValidationException;
use OCA\NachweisCheck\Service\CsvImportService;
use OCA\NachweisCheck\Service\FinplanService;
use OCA\NachweisCheck\Service\ReceiptService;
use OCA\NachweisCheck\Service\TimeEntryService;
use OCA\NachweisCheck\Service\WorkPackageService;
use Test\TestCase;

class CsvImportServiceTest extends TestCase
{
	private function svc(array $over = []): array
	{
		$m = [
			'batches' => $this->createMock(ImportBatchMapper::class),
			'entries' => $this->createMock(TimeEntryMapper::class),
			'receipts' => $this->createMock(ReceiptMapper::class),
			'timeService' => $this->createMock(TimeEntryService::class),
			'receiptService' => $this->createMock(ReceiptService::class),
			'apService' => $this->createMock(WorkPackageService::class),
			'finplan' => $this->createMock(FinplanService::class),
		];
		foreach ($over as $k => $v) {
			$m[$k] = $v;
		}
		return ['svc' => new CsvImportService($m['batches'], $m['entries'], $m['receipts'],
			$m['timeService'], $m['receiptService'], $m['apService'], $m['finplan'])] + $m;
	}

	private function batchMapper(): ImportBatchMapper
	{
		$batches = $this->createMock(ImportBatchMapper::class);
		$batches->method('insert')->willReturnCallback(function (ImportBatch $b) {
			$b->setId(7);
			return $b;
		});
		$batches->method('update')->willReturnCallback(fn($b) => $b);
		return $batches;
	}

	private function ap(int $id, string $code): WorkPackage
	{
		$ap = new WorkPackage();
		$ap->setId($id);
		$ap->setCode($code);
		return $ap;
	}

	// ---- times ------------------------------------------------------------

	public function testGenericPresetRoundtrip(): void
	{
		$csv = "date,uid,ap_code,minutes,note,external_ref\n"
			. "2026-03-10,u1,AP1,90,arbeit,t-1\n"
			. "2026-03-11,u2,AP1,60,mehr,t-2\n";
		$apService = $this->createMock(WorkPackageService::class);
		$apService->method('findByCode')->willReturn($this->ap(5, 'AP1'));
		$timeService = $this->createMock(TimeEntryService::class);
		$created = [];
		$timeService->expects($this->exactly(2))->method('create')
			->willReturnCallback(function (...$args) use (&$created) {
				$created[] = $args;
				return new TimeEntry();
			});
		$m = $this->svc(['batches' => $this->batchMapper(), 'apService' => $apService,
			'timeService' => $timeService]);
		$res = $m['svc']->importTimes(1, 'generic', $csv, 'times.csv', 'admin');
		$this->assertSame(7, $res['batch_id']);
		$this->assertSame(2, $res['rows_ok']);
		$this->assertSame(0, $res['rows_failed']);
		$this->assertSame([], $res['errors']);
		$this->assertSame([1, 5, 'u1', '2026-03-10', 90, 'arbeit', 't-1', 'admin'], $created[0]);
		$this->assertSame([1, 5, 'u2', '2026-03-11', 60, 'mehr', 't-2', 'admin'], $created[1]);
	}

	public function testExternalRefIdempotencySkipsSecondImport(): void
	{
		$csv = "date,uid,ap_code,minutes,external_ref\n"
			. "2026-03-10,u1,AP1,90,t-1\n";
		$entries = $this->createMock(TimeEntryMapper::class);
		$entries->method('findByExternalRef')->willReturn(new TimeEntry()); // already there
		$timeService = $this->createMock(TimeEntryService::class);
		$timeService->expects($this->never())->method('create');
		$apService = $this->createMock(WorkPackageService::class);
		$apService->expects($this->never())->method('findByCode');
		$m = $this->svc(['batches' => $this->batchMapper(), 'entries' => $entries,
			'timeService' => $timeService, 'apService' => $apService]);
		$res = $m['svc']->importTimes(1, 'generic', $csv, 'times.csv', 'admin');
		$this->assertSame(1, $res['rows_ok']); // skipped counts as ok, not error
		$this->assertSame(0, $res['rows_failed']);
	}

	public function testErrorRowsCollectedNotFatal(): void
	{
		$csv = "date,uid,ap_code,minutes,note\n"
			. "2026-03-10,u1,AP_MISSING,90,no ap here\n"
			. "2026-03-11,u1,AP1,60,ok row\n";
		$apService = $this->createMock(WorkPackageService::class);
		$apService->method('findByCode')->willReturnCallback(
			fn(int $pid, string $code) => $code === 'AP1' ? $this->ap(5, 'AP1') : null
		);
		$timeService = $this->createMock(TimeEntryService::class);
		$timeService->expects($this->once())->method('create')->willReturn(new TimeEntry());
		$m = $this->svc(['batches' => $this->batchMapper(), 'apService' => $apService,
			'timeService' => $timeService]);
		$res = $m['svc']->importTimes(1, 'generic', $csv, 'times.csv', 'admin');
		$this->assertSame(1, $res['rows_ok']);
		$this->assertSame(1, $res['rows_failed']);
		$this->assertCount(1, $res['errors']);
		$this->assertSame(2, $res['errors'][0]['row']); // header is row 1
		$this->assertNotSame('', $res['errors'][0]['error']);
	}

	public function testArbeitszeitcheckPresetMapsHoursToMinutes(): void
	{
		$csv = "date;person;task;hours;beschreibung;id\n"
			. "2026-03-10;u1;AP1;1,5;haltungsarbeit;azc-9\n";
		$apService = $this->createMock(WorkPackageService::class);
		$apService->method('findByCode')->willReturn($this->ap(5, 'AP1'));
		$timeService = $this->createMock(TimeEntryService::class);
		$timeService->expects($this->once())->method('create')
			->with(1, 5, 'u1', '2026-03-10', 90, 'haltungsarbeit', 'azc-9', 'admin')
			->willReturn(new TimeEntry());
		$m = $this->svc(['batches' => $this->batchMapper(), 'apService' => $apService,
			'timeService' => $timeService]);
		$res = $m['svc']->importTimes(1, 'arbeitszeitcheck', $csv, 'azc.csv', 'admin');
		$this->assertSame(1, $res['rows_ok']);
	}

	public function testAutoRefDerivedFromRowHashWhenMissing(): void
	{
		$csv = "date,uid,ap_code,minutes\n"
			. "2026-03-10,u1,AP1,90\n";
		$apService = $this->createMock(WorkPackageService::class);
		$apService->method('findByCode')->willReturn($this->ap(5, 'AP1'));
		$entries = $this->createMock(TimeEntryMapper::class);
		$entries->expects($this->once())->method('findByExternalRef')
			->with(1, $this->callback(fn(string $ref): bool => str_starts_with($ref, 'generic:')))
			->willReturn(null);
		$timeService = $this->createMock(TimeEntryService::class);
		$timeService->expects($this->once())->method('create')
			->with(1, 5, 'u1', '2026-03-10', 90, $this->anything(),
				$this->callback(fn(string $ref): bool => str_starts_with($ref, 'generic:')),
				'admin')
			->willReturn(new TimeEntry());
		$m = $this->svc(['batches' => $this->batchMapper(), 'apService' => $apService,
			'entries' => $entries, 'timeService' => $timeService]);
		$res = $m['svc']->importTimes(1, 'generic', $csv, 'times.csv', 'admin');
		$this->assertSame(1, $res['rows_ok']);
	}

	public function testEmptyCsvRefused(): void
	{
		$m = $this->svc(['batches' => $this->batchMapper()]);
		$this->expectException(ValidationException::class);
		$m['svc']->importTimes(1, 'generic', '', 'empty.csv', 'admin');
	}

	// ---- receipts ----------------------------------------------------------

	public function testReceiptImportResolvesPositionCode(): void
	{
		$csv = "invoice_date,vendor,invoice_no,total_cents,tax_cents,payment_date,purpose,position_code,external_ref\n"
			. "2026-02-01,Vendor X,R-9,12345,0,2026-02-03,Zweck,SK,imp-1\n";
		$active = new FinplanVersion();
		$active->setId(7);
		$finplan = $this->createMock(FinplanService::class);
		$finplan->method('activeFor')->willReturn($active);
		$pos = new Position();
		$pos->setId(11);
		$pos->setPlanVerId(7);
		$pos->setCode('SK');
		$finplan->method('positions')->willReturn([$pos]);
		$receiptService = $this->createMock(ReceiptService::class);
		$receiptService->expects($this->once())->method('create')
			->with(1, 'Vendor X', 'R-9', '2026-02-01', '2026-02-03', 'EUR', null,
				12345, 0, 'Zweck', null, 'receipt',
				[['position_id' => 11, 'amount_minor' => 12345, 'note' => '']],
				'imp-1', 'admin', true)
			->willReturn(new \OCA\NachweisCheck\Db\Receipt());
		$m = $this->svc(['batches' => $this->batchMapper(), 'finplan' => $finplan,
			'receiptService' => $receiptService]);
		$res = $m['svc']->importReceipts(1, 'generic', $csv, 'belege.csv', 'admin');
		$this->assertSame(1, $res['rows_ok']);
		$this->assertSame(0, $res['rows_failed']);
	}

	public function testReceiptImportSkipsAlreadyImportedRef(): void
	{
		$csv = "invoice_date,vendor,total_cents,position_code,external_ref\n"
			. "2026-02-01,Vendor X,12345,SK,imp-1\n";
		$receipts = $this->createMock(ReceiptMapper::class);
		$receipts->method('findByExternalRef')->willReturn(new \OCA\NachweisCheck\Db\Receipt());
		$receiptService = $this->createMock(ReceiptService::class);
		$receiptService->expects($this->never())->method('create');
		$m = $this->svc(['batches' => $this->batchMapper(), 'receipts' => $receipts,
			'receiptService' => $receiptService]);
		$res = $m['svc']->importReceipts(1, 'generic', $csv, 'belege.csv', 'admin');
		$this->assertSame(1, $res['rows_ok']);
		$this->assertSame(0, $res['rows_failed']);
	}

	public function testReceiptImportRowFailureCollected(): void
	{
		$csv = "invoice_date,vendor,total_cents,position_code\n"
			. "2026-02-01,Vendor X,12345,UNKNOWN\n";
		$active = new FinplanVersion();
		$active->setId(7);
		$finplan = $this->createMock(FinplanService::class);
		$finplan->method('activeFor')->willReturn($active);
		$finplan->method('positions')->willReturn([]); // code never resolves
		$m = $this->svc(['batches' => $this->batchMapper(), 'finplan' => $finplan]);
		$res = $m['svc']->importReceipts(1, 'generic', $csv, 'belege.csv', 'admin');
		$this->assertSame(0, $res['rows_ok']);
		$this->assertSame(1, $res['rows_failed']);
		$this->assertSame(2, $res['errors'][0]['row']);
	}

	public function testEuroDecimalColumnParsesToMinor(): void
	{
		$csv = "invoice_date,vendor,total_eur,position_code\n"
			. "2026-02-01,Vendor X,\"1.234,56\",SK\n";
		$active = new FinplanVersion();
		$active->setId(7);
		$finplan = $this->createMock(FinplanService::class);
		$finplan->method('activeFor')->willReturn($active);
		$pos = new Position();
		$pos->setId(11);
		$pos->setCode('SK');
		$finplan->method('positions')->willReturn([$pos]);
		$receiptService = $this->createMock(ReceiptService::class);
		$receiptService->expects($this->once())->method('create')
			->with(1, 'Vendor X', $this->anything(), '2026-02-01', $this->anything(), 'EUR', null,
				123456, $this->anything(), $this->anything(), null, 'receipt',
				$this->anything(), $this->anything(), 'admin', true)
			->willReturn(new \OCA\NachweisCheck\Db\Receipt());
		$m = $this->svc(['batches' => $this->batchMapper(), 'finplan' => $finplan,
			'receiptService' => $receiptService]);
		$res = $m['svc']->importReceipts(1, 'generic', $csv, 'belege.csv', 'admin');
		$this->assertSame(1, $res['rows_ok']);
	}
}

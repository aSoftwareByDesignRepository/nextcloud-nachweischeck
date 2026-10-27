<?php

declare(strict_types=1);

namespace OCA\NachweisCheck\Tests\Unit;

use OCA\NachweisCheck\Db\Position;
use OCA\NachweisCheck\Db\PositionMapper;
use OCA\NachweisCheck\Db\Receipt;
use OCA\NachweisCheck\Db\ReceiptMapper;
use OCA\NachweisCheck\Db\ReceiptSplit;
use OCA\NachweisCheck\Db\ReceiptSplitMapper;
use OCA\NachweisCheck\Service\DatevExportService;
use Test\TestCase;

class DatevExportServiceTest extends TestCase
{
	private function receipt(int $id, string $vendor, string $purpose = ''): Receipt
	{
		$r = new Receipt();
		$r->setId($id);
		$r->setProjectId(1);
		$r->setVendor($vendor);
		$r->setPurpose($purpose);
		$r->setInvoiceNo('RE-7');
		$r->setInvoiceDate(new \DateTime('2026-03-15'));
		$r->setTotalMinor(11900);
		return $r;
	}

	private function split(int $receiptId, int $posId, int $amount): ReceiptSplit
	{
		$s = new ReceiptSplit();
		$s->setReceiptId($receiptId);
		$s->setPositionId($posId);
		$s->setAmountMinor($amount);
		return $s;
	}

	public function testOneRowPerSplitWithPositionCodeAsKonto(): void
	{
		$rm = $this->createMock(ReceiptMapper::class);
		$rm->method('findByProjectPeriod')->willReturn([$this->receipt(1, 'Bürobedarf AG', 'Toner')]);
		$sm = $this->createMock(ReceiptSplitMapper::class);
		$sm->method('findByReceipt')->willReturnMap([
			[1, [$this->split(1, 10, 10000), $this->split(1, 11, 1900)]],
		]);
		$pm = $this->createMock(PositionMapper::class);
		$pos10 = new Position();
		$pos10->setId(10);
		$pos10->setCode('6815');
		$pm->method('findById')->willReturnMap([[10, $pos10], [11, null]]);
		$svc = new DatevExportService($rm, $sm, $pm);

		$csv = $svc->buildCsv(1, '2026-01-01', '2026-12-31');
		$lines = explode("\r\n", trim($csv));
		$this->assertCount(3, $lines); // header + 2 splits
		$this->assertSame(DatevExportService::COLUMNS, explode(';', $lines[0]));
		$first = explode(';', $lines[1]);
		$this->assertSame('100,00', $first[0]);   // 10000 ct → de-DE
		$this->assertSame('S', $first[1]);
		$this->assertSame('EUR', $first[2]);
		$this->assertSame('6815', $first[3]);     // position code → Konto
		$this->assertSame('1200', $first[4]);
		$this->assertSame('150326', $first[5]);   // Belegdatum ddmmyy
		$this->assertSame('RE-7', $first[6]);
		$second = explode(';', $lines[2]);
		$this->assertSame('19,00', $second[0]);
		$this->assertSame('11', $second[3]);      // deleted position → id fallback
	}

	public function testFieldInjectionStripped(): void
	{
		// vendor carrying the ';' field separator + a newline — the
		// delimited-export injection class (api-security-patterns §7):
		// one hostile string must never forge an extra column or row.
		$r = $this->receipt(1, "Evil;Corp\nforged;row", "Text;with\ndelims");
		$rm = $this->createMock(ReceiptMapper::class);
		$rm->method('findByProjectPeriod')->willReturn([$r]);
		$sm = $this->createMock(ReceiptSplitMapper::class);
		$sm->method('findByReceipt')->willReturn([$this->split(1, 10, 100)]);
		$pm = $this->createMock(PositionMapper::class);
		$pm->method('findById')->willReturn(null);
		$svc = new DatevExportService($rm, $sm, $pm);

		$csv = $svc->buildCsv(1, '2026-01-01', '2026-12-31');
		$lines = explode("\r\n", trim($csv));
		$this->assertCount(2, $lines, 'no forged rows via newline injection');
		$fields = explode(';', $lines[1]);
		$this->assertCount(8, $fields, 'no forged columns via separator injection');
		$this->assertStringNotContainsString("\n", $fields[7]);
	}

	public function testEmptyProjectYieldsHeaderOnly(): void
	{
		$rm = $this->createMock(ReceiptMapper::class);
		$rm->method('findByProjectPeriod')->willReturn([]);
		$svc = new DatevExportService($rm,
			$this->createMock(ReceiptSplitMapper::class),
			$this->createMock(PositionMapper::class));
		$csv = $svc->buildCsv(1, '2026-01-01', '2026-12-31');
		$this->assertSame(1, substr_count(trim($csv), "\r\n") === 0 ? 1 : 0 + substr_count(trim($csv), "\r\n"));
		$this->assertStringStartsWith('Umsatz (ohne Soll/Haben-Kz)', $csv);
	}

	public function testCustomGegenkonto(): void
	{
		$rm = $this->createMock(ReceiptMapper::class);
		$rm->method('findByProjectPeriod')->willReturn([$this->receipt(1, 'X')]);
		$sm = $this->createMock(ReceiptSplitMapper::class);
		$sm->method('findByReceipt')->willReturn([$this->split(1, 10, 100)]);
		$pm = $this->createMock(PositionMapper::class);
		$pm->method('findById')->willReturn(null);
		$svc = new DatevExportService($rm, $sm, $pm);
		$csv = $svc->buildCsv(1, '2026-01-01', '2026-12-31', '1600');
		$this->assertStringContainsString(';1600;', $csv);
	}
}

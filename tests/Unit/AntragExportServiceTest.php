<?php

declare(strict_types=1);

namespace OCA\NachweisCheck\Tests\Unit;

use OCA\NachweisCheck\Db\Cofunder;
use OCA\NachweisCheck\Db\CofunderMapper;
use OCA\NachweisCheck\Db\FinplanVersion;
use OCA\NachweisCheck\Db\Position;
use OCA\NachweisCheck\Db\Project;
use OCA\NachweisCheck\Db\WorkPackage;
use OCA\NachweisCheck\Db\WorkPackageMapper;
use OCA\NachweisCheck\Exception\NotFoundException;
use OCA\NachweisCheck\Service\AntragExportService;
use OCA\NachweisCheck\Service\FinplanService;
use Test\TestCase;

class AntragExportServiceTest extends TestCase
{
	private function position(string $code, int $budget, ?int $cofId = null): Position
	{
		$p = new Position();
		$p->setCode($code);
		$p->setTitle('T-' . $code);
		$p->setKind('sach');
		$p->setBudgetMinor($budget);
		$p->setCofunderId($cofId);
		return $p;
	}

	private function build(array $pos, array $aps = [], array $cofs = [], bool $draftFirst = true): array
	{
		$fp = $this->createMock(FinplanService::class);
		$ver = new FinplanVersion();
		$ver->setId(2);
		if ($draftFirst) {
			$fp->method('draftFor')->willReturn($ver);
		} else {
			$fp->method('draftFor')->willReturn(null);
			$fp->method('activeFor')->willReturn($ver);
		}
		$fp->method('positions')->willReturn($pos);
		$apm = $this->createMock(WorkPackageMapper::class);
		$apm->method('findByProject')->willReturn($aps);
		$cm = $this->createMock(CofunderMapper::class);
		$cm->method('findByProject')->willReturn($cofs);
		return [new AntragExportService($fp, $apm, $cm), $fp];
	}

	private function project(): Project
	{
		$p = new Project();
		$p->setId(1);
		$p->setName('Pilotprojekt');
		$p->setUid('admin');
		$p->setFunderName('BMBF');
		$p->setFunderRef('FKZ-42');
		$p->setBewStart(new \DateTime('2026-01-01'));
		$p->setBewEnd(new \DateTime('2027-06-30'));
		return $p;
	}

	public function testCsvContainsPositionsApsAndTotal(): void
	{
		$ap = new WorkPackage();
		$ap->setCode('AP1');
		$ap->setTitle('Konzept');
		$ap->setStartDate(new \DateTime('2026-01-01'));
		$ap->setEndDate(new \DateTime('2026-06-30'));
		$cof = new Cofunder();
		$cof->setId(3);
		$cof->setName('Land');
		[$svc] = $this->build(
			[$this->position('P1', 100000), $this->position('P2', 50000, 3)],
			[$ap], [$cof],
		);
		$csv = $svc->buildCsv($this->project());
		$this->assertStringContainsString('Pilotprojekt', $csv);
		$this->assertStringContainsString('P1;T-P1;sach;1.000,00;', $csv);
		$this->assertStringContainsString('P2;T-P2;sach;500,00;Land', $csv);
		$this->assertStringContainsString('Summe;;;1.500,00', $csv);
		$this->assertStringContainsString('AP1;Konzept;2026-01-01;2026-06-30', $csv);
	}

	public function testNoPlanVersionThrowsNotFound(): void
	{
		$fp = $this->createMock(FinplanService::class);
		$fp->method('draftFor')->willReturn(null);
		$fp->method('activeFor')->willReturn(null);
		$svc = new AntragExportService($fp,
			$this->createMock(WorkPackageMapper::class),
			$this->createMock(CofunderMapper::class));
		$this->expectException(NotFoundException::class);
		$svc->buildCsv($this->project());
	}

	public function testFallsBackToActivePlan(): void
	{
		[$svc] = $this->build([$this->position('P1', 100)], [], [], false);
		$csv = $svc->buildCsv($this->project());
		$this->assertStringContainsString('P1', $csv);
	}

	public function testOdsAndPdfNonEmpty(): void
	{
		[$svc] = $this->build([$this->position('P1', 100000)]);
		$ods = $svc->buildOds($this->project());
		$this->assertStringStartsWith('PK', $ods); // ODS = zip container
		$pdf = $svc->buildPdf($this->project());
		$this->assertStringStartsWith('%PDF', $pdf);
		$this->assertStringContainsString('Finanzierungsplan', $pdf);
	}
}

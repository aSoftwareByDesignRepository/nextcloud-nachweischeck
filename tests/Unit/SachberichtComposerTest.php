<?php

declare(strict_types=1);

namespace OCA\NachweisCheck\Tests\Unit;

use OCA\NachweisCheck\Db\Project;
use OCA\NachweisCheck\Db\WorkPackage;
use OCA\NachweisCheck\Db\WorkPackageMapper;
use OCA\NachweisCheck\Db\WorkReport;
use OCA\NachweisCheck\Db\WorkReportMapper;
use OCA\NachweisCheck\Service\SachberichtComposer;
use Test\TestCase;

class SachberichtComposerTest extends TestCase
{
	private function project(): Project
	{
		$p = new Project();
		$p->setId(1);
		return $p;
	}

	private function ap(int $id, string $code = 'AP1'): WorkPackage
	{
		$a = new WorkPackage();
		$a->setId($id);
		$a->setCode($code);
		$a->setTitle('Paket ' . $code);
		return $a;
	}

	private function report(int $id, string $period, string $state, string $summary = 's', string $outcome = 'o'): WorkReport
	{
		$w = new WorkReport();
		$w->setId($id);
		$w->setPeriod($period);
		$w->setState($state);
		$w->setSummary($summary);
		$w->setOutcome($outcome);
		return $w;
	}

	public function testBlocksFromAcceptedReportsInPeriod(): void
	{
		$apm = $this->createMock(WorkPackageMapper::class);
		$apm->method('findByProject')->willReturn([$this->ap(1)]);
		$rm = $this->createMock(WorkReportMapper::class);
		$rm->method('findByAp')->willReturn([
			$this->report(1, '2026-03', 'accepted', 'Im März konzipiert', 'Konzept fertig'),
			$this->report(2, '2026-04', 'submitted', 'Im April umgesetzt', ''),
			$this->report(3, '2025-12', 'accepted', 'alt', ''),       // outside period
			$this->report(4, '2026-05', 'draft', 'Entwurf', ''),       // drafts excluded
		]);
		$svc = new SachberichtComposer($apm, $rm);
		$out = $svc->compose($this->project(), '2026-01-01', '2026-06-30');
		$this->assertCount(1, $out);
		$this->assertSame('AP1', $out[0]['ap_code']);
		$this->assertCount(2, $out[0]['blocks']);
		$this->assertSame('2026-03', $out[0]['blocks'][0]['period']);
		$this->assertStringContainsString('Im März konzipiert', $out[0]['blocks'][0]['text']);
		$this->assertStringContainsString('Ergebnis: Konzept fertig', $out[0]['blocks'][0]['text']);
		$this->assertSame('Im April umgesetzt', $out[0]['blocks'][1]['text']);
	}

	public function testNewestReportWinsWithinPeriod(): void
	{
		$apm = $this->createMock(WorkPackageMapper::class);
		$apm->method('findByProject')->willReturn([$this->ap(1)]);
		$rm = $this->createMock(WorkReportMapper::class);
		$rm->method('findByAp')->willReturn([
			$this->report(1, '2026-03', 'accepted', 'erste Fassung'),
			$this->report(5, '2026-03', 'submitted', 'zweite Fassung'),
		]);
		$svc = new SachberichtComposer($apm, $rm);
		$out = $svc->compose($this->project(), '2026-01-01', '2026-12-31');
		$this->assertCount(1, $out[0]['blocks']);
		$this->assertStringContainsString('zweite Fassung', $out[0]['blocks'][0]['text']);
	}

	public function testEmptyReportsYieldEmptyBlocksNotMissingAp(): void
	{
		$apm = $this->createMock(WorkPackageMapper::class);
		$apm->method('findByProject')->willReturn([$this->ap(1), $this->ap(2, 'AP2')]);
		$rm = $this->createMock(WorkReportMapper::class);
		$rm->method('findByAp')->willReturn([]);
		$svc = new SachberichtComposer($apm, $rm);
		$out = $svc->compose($this->project(), '2026-01-01', '2026-12-31');
		$this->assertCount(2, $out);
		$this->assertSame([], $out[0]['blocks']);
		$this->assertSame([], $out[1]['blocks']);
	}
}

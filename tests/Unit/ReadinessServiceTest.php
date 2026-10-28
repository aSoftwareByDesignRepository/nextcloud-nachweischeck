<?php

declare(strict_types=1);

namespace OCA\NachweisCheck\Tests\Unit;

use OCA\NachweisCheck\Db\ApPositionMapper;
use OCA\NachweisCheck\Db\Claim;
use OCA\NachweisCheck\Db\ClaimMapper;
use OCA\NachweisCheck\Db\Drawdown;
use OCA\NachweisCheck\Db\DrawdownMapper;
use OCA\NachweisCheck\Db\FinplanVersion;
use OCA\NachweisCheck\Db\Project;
use OCA\NachweisCheck\Db\Receipt;
use OCA\NachweisCheck\Db\ReceiptMapper;
use OCA\NachweisCheck\Db\TimeEntry;
use OCA\NachweisCheck\Db\TimeEntryMapper;
use OCA\NachweisCheck\Db\Timesheet;
use OCA\NachweisCheck\Db\TimesheetMapper;
use OCA\NachweisCheck\Db\WorkPackage;
use OCA\NachweisCheck\Db\WorkPackageMapper;
use OCA\NachweisCheck\Service\FinplanService;
use OCA\NachweisCheck\Service\ReadinessService;
use OCP\IL10N;
use Test\TestCase;

class ReadinessServiceTest extends TestCase
{
	private function l10n(): IL10N
	{
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(
			static fn(string $text, array $params = []): string =>
				$params === [] ? $text : vsprintf($text, $params)
		);
		return $l;
	}

	private function build(array $over = []): array
	{
		$m = [
			'finplan' => $this->createMock(FinplanService::class),
			'ap' => $this->createMock(WorkPackageMapper::class),
			'appos' => $this->createMock(ApPositionMapper::class),
			'receipt' => $this->createMock(ReceiptMapper::class),
			'entry' => $this->createMock(TimeEntryMapper::class),
			'ts' => $this->createMock(TimesheetMapper::class),
			'dd' => $this->createMock(DrawdownMapper::class),
			'claim' => $this->createMock(ClaimMapper::class),
		];
		foreach ($over as $k => $v) {
			$m[$k] = $v;
		}
		foreach (['ap', 'receipt', 'ts', 'claim'] as $k) {
			if ($m[$k] instanceof \PHPUnit\Framework\MockObject\MockObject) {
				$m[$k]->method('findByProject')->willReturn([]);
			}
		}
		$m['entry']->method('findByProjectPeriod')->willReturn([]);
		$m['dd']->method('findByProjectState')->willReturn([]);
		$svc = new ReadinessService($m['finplan'], $m['ap'], $m['appos'], $m['receipt'], $m['entry'], $m['ts'], $m['dd'], $m['claim'], $this->l10n());
		return ['svc' => $svc] + $m;
	}

	private function project(): Project
	{
		$p = new Project();
		$p->setId(1);
		return $p;
	}

	public function testReadyWhenNoFindings(): void
	{
		['svc' => $svc, 'finplan' => $fp, 'appos' => $ap] = $this->build();
		$ver = new FinplanVersion();
		$fp->method('activeFor')->willReturn($ver);
		$ap->method('findByAp')->willReturn([new \OCA\NachweisCheck\Db\ApPosition()]);
		$report = $svc->checklist($this->project());
		$this->assertTrue($report['ready']);
		$this->assertSame([], $report['checks']);
	}

	public function testMissingPlanIsBlocker(): void
	{
		['svc' => $svc] = $this->build();
		$report = $svc->checklist($this->project());
		$this->assertFalse($report['ready']);
		$this->assertSame('no_active_plan', $report['checks'][0]['id']);
		$this->assertSame('blocker', $report['checks'][0]['severity']);
	}

	public function testUnmappedApIsBlocker(): void
	{
		$ap = new WorkPackage();
		$ap->setId(3);
		$ap->setCode('AP1');
		$ap->setTitle('Konzept');
		$ap->setState('active');
		$apm = $this->createMock(WorkPackageMapper::class);
		$apm->method('findByProject')->willReturn([$ap]);
		$appos = $this->createMock(ApPositionMapper::class);
		$appos->method('findByAp')->willReturn([]);
		$fp = $this->createMock(FinplanService::class);
		$fp->method('activeFor')->willReturn(new FinplanVersion());
		['svc' => $svc] = $this->build(['ap' => $apm, 'appos' => $appos, 'finplan' => $fp]);
		$report = $svc->checklist($this->project());
		$this->assertFalse($report['ready']);
		$this->assertSame('unmapped_aps', $report['checks'][0]['id']);
		$this->assertSame(1, $report['checks'][0]['count']);
	}

	public function testClosedApNotReported(): void
	{
		$ap = new WorkPackage();
		$ap->setId(3);
		$ap->setCode('AP1');
		$ap->setTitle('Konzept');
		$ap->setState('closed');
		$apm = $this->createMock(WorkPackageMapper::class);
		$apm->method('findByProject')->willReturn([$ap]);
		$appos = $this->createMock(ApPositionMapper::class);
		$appos->method('findByAp')->willReturn([]);
		$fp = $this->createMock(FinplanService::class);
		$fp->method('activeFor')->willReturn(new FinplanVersion());
		['svc' => $svc] = $this->build(['ap' => $apm, 'appos' => $appos, 'finplan' => $fp]);
		$report = $svc->checklist($this->project());
		$this->assertTrue($report['ready']);
	}

	public function testMissingReceiptDataIsWarning(): void
	{
		$r = new Receipt();
		$r->setId(5);
		$r->setVendor('X GmbH');
		$r->setFileId(null);
		$r->setInvoiceNo(null);
		$rm = $this->createMock(ReceiptMapper::class);
		$rm->method('findByProject')->willReturn([$r]);
		$fp = $this->createMock(FinplanService::class);
		$fp->method('activeFor')->willReturn(new FinplanVersion());
		['svc' => $svc] = $this->build(['receipt' => $rm, 'finplan' => $fp]);
		$report = $svc->checklist($this->project());
		$ids = array_column($report['checks'], 'id');
		$this->assertContains('missing_data', $ids);
		// missing attachment + missing invoice_no + missing payment_date = 3
		$this->assertSame(3, $report['checks'][0]['count']);
		$this->assertSame('warning', $report['checks'][0]['severity']);
		// UI details go through IL10N — never the German export literals.
		$this->assertSame(
			[
				'Receipt without attachment: X GmbH',
				'Receipt without invoice number: X GmbH',
				'Receipt without payment date: X GmbH',
			],
			$report['checks'][0]['items'],
		);
	}

	public function testUnpricedEntryIsWarning(): void
	{
		$t = new TimeEntry();
		$t->setId(8);
		$t->setUid('u1');
		$t->setRateMinor(null);
		$t->setAmountMinor(null);
		$em = $this->createMock(TimeEntryMapper::class);
		$em->method('findByProjectPeriod')->willReturn([$t]);
		$fp = $this->createMock(FinplanService::class);
		$fp->method('activeFor')->willReturn(new FinplanVersion());
		['svc' => $svc] = $this->build(['entry' => $em, 'finplan' => $fp]);
		$report = $svc->checklist($this->project());
		$this->assertSame('missing_data', $report['checks'][0]['id']);
		$this->assertSame(1, $report['checks'][0]['count']);
		$this->assertSame(['Time entry without hourly rate:  / u1'], $report['checks'][0]['items']);
	}

	public function testUnsignedTimesheetsAndPendingDrawdowns(): void
	{
		$ts = new Timesheet();
		$ts->setUid('u1');
		$ts->setPeriod('2026-03');
		$ts->setState('draft');
		$tm = $this->createMock(TimesheetMapper::class);
		$tm->method('findByProject')->willReturn([$ts]);
		$dd = new Drawdown();
		$dd->setRequestedDate(new \DateTime('2026-02-01'));
		$dd->setAmountMinor(1000);
		$dm = $this->createMock(DrawdownMapper::class);
		$dm->method('findByProjectState')->willReturn([$dd]);
		$claim = new Claim();
		$claim->setTitle('Zinsen');
		$claim->setKind('interest');
		$claim->setState('open');
		$cm = $this->createMock(ClaimMapper::class);
		$cm->method('findByProject')->willReturn([$claim]);
		$fp = $this->createMock(FinplanService::class);
		$fp->method('activeFor')->willReturn(new FinplanVersion());
		['svc' => $svc] = $this->build(['ts' => $tm, 'dd' => $dm, 'claim' => $cm, 'finplan' => $fp]);
		$report = $svc->checklist($this->project());
		$ids = array_column($report['checks'], 'id');
		$this->assertContains('unsigned_timesheets', $ids);
		$this->assertContains('pending_drawdowns', $ids);
		$this->assertContains('open_claims', $ids);
		// warnings sort before info
		$sevs = array_column($report['checks'], 'severity');
		$this->assertSame(['warning', 'info', 'info'], $sevs);
		$this->assertTrue($report['ready']); // info/warning don't block
	}
}

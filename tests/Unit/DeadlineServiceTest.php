<?php

declare(strict_types=1);

namespace OCA\NachweisCheck\Tests\Unit;

use OCA\NachweisCheck\Db\Deadline;
use OCA\NachweisCheck\Db\DeadlineMapper;
use OCA\NachweisCheck\Db\Project;
use OCA\NachweisCheck\Db\WorkPackage;
use OCA\NachweisCheck\Db\WorkPackageMapper;
use OCA\NachweisCheck\Service\DeadlineService;
use OCA\NachweisCheck\Service\ConcurrencyGuard;
use OCA\NachweisCheck\Service\TemplateService;
use OCP\Lock\ILockingProvider;
use Test\TestCase;

class DeadlineServiceTest extends TestCase
{
	private function svc(array $over = []): array
	{
		$m = [
			'mapper' => $this->createMock(DeadlineMapper::class),
			'apMapper' => $this->createMock(WorkPackageMapper::class),
			'templates' => $this->createMock(TemplateService::class),
	
			'guard' => new ConcurrencyGuard($this->createMock(ILockingProvider::class)),
		];
		foreach ($over as $k => $v) {
			$m[$k] = $v;
		}
		return ['svc' => new DeadlineService($m['mapper'], $m['apMapper'], $m['templates'], $m['guard'])] + $m;
	}

	private function project(string $financingMode = 'ausgaben'): Project
	{
		$p = new Project();
		$p->setId(1);
		$p->setTemplateKey('anbest-p');
		$p->setFinancingMode($financingMode);
		$p->setBewStart(new \DateTime('2026-01-01'));
		$p->setBewEnd(new \DateTime('2026-12-31'));
		return $p;
	}

	private function deadline(string $key, ?\DateTime $notifiedAt = null): Deadline
	{
		$d = new Deadline();
		$d->setDedupeKey($key);
		$d->setKind('x');
		$d->setNotifiedAt($notifiedAt);
		return $d;
	}

	public function testRecomputeInsertsMissingDedupeKeys(): void
	{
		// Calculator output for 2026-01-01..2026-12-31 (final 6m, warn 30d):
		//   nachweis_frist   → 2027-06-30  (first day of +6m → last day of month)
		//   bew_end_warning  → 2026-12-01
		// no zwischennachweis (single calendar year), one ap_end for AP 9.
		$ap = new WorkPackage();
		$ap->setId(9);
		$ap->setEndDate(new \DateTime('2026-06-30'));
		$apMapper = $this->createMock(WorkPackageMapper::class);
		$apMapper->method('findByProject')->willReturn([$ap]);
		$templates = $this->createMock(TemplateService::class);
		$templates->method('ruleFlags')->with('anbest-p')->willReturn(
			TemplateService::BUILTIN[TemplateService::KEY_ANBEST_P]['rule_flags']
		);
		$mapper = $this->createMock(DeadlineMapper::class);
		// one already-known row → NOT re-inserted; obsolete unnotified → deleted;
		// obsolete notified → kept for the audit trail.
		$notified = $this->deadline('1:old:notified', new \DateTime('2026-01-05'));
		$obsolete = $this->deadline('1:old:stale');
		$existing = [
			$this->deadline('1:bew_end_warning:2026-12-01'),
			$notified,
			$obsolete,
		];
		$mapper->method('findByProject')->willReturn($existing);
		$inserted = [];
		$mapper->expects($this->exactly(2))->method('insert')
			->willReturnCallback(function (Deadline $d) use (&$inserted) {
				$inserted[$d->getDedupeKey()] = $d;
				return $d;
			});
		$mapper->expects($this->once())->method('delete')->with($obsolete);
		$m = $this->svc(['mapper' => $mapper, 'apMapper' => $apMapper, 'templates' => $templates]);
		$m['svc']->recompute($this->project());
		$this->assertArrayHasKey('1:nachweis_frist:2027-06-30', $inserted);
		$this->assertArrayHasKey('1:ap_end:9:2026-06-30', $inserted);
		$this->assertSame('nachweis_frist', $inserted['1:nachweis_frist:2027-06-30']->getKind());
		$this->assertSame('2027-06-30', $inserted['1:nachweis_frist:2027-06-30']->getDueDate()->format('Y-m-d'));
	}

	public function testRecomputeIsIdempotentWhenNothingChanged(): void
	{
		$apMapper = $this->createMock(WorkPackageMapper::class);
		$apMapper->method('findByProject')->willReturn([]);
		$templates = $this->createMock(TemplateService::class);
		$templates->method('ruleFlags')->willReturn(
			TemplateService::BUILTIN[TemplateService::KEY_ANBEST_P]['rule_flags']
		);
		$mapper = $this->createMock(DeadlineMapper::class);
		$mapper->method('findByProject')->willReturn([
			$this->deadline('1:nachweis_frist:2027-06-30'),
			$this->deadline('1:bew_end_warning:2026-12-01'),
		]);
		$mapper->expects($this->never())->method('insert');
		$mapper->expects($this->never())->method('delete');
		$m = $this->svc(['mapper' => $mapper, 'apMapper' => $apMapper, 'templates' => $templates]);
		$m['svc']->recompute($this->project());
	}

	public function testZwischenSuppressedWhenTemplateDisablesIt(): void
	{
		// template flags drive the calculator: zwischen_enabled=false must
		// suppress interim deadlines even across a year boundary.
		$apMapper = $this->createMock(WorkPackageMapper::class);
		$apMapper->method('findByProject')->willReturn([]);
		$templates = $this->createMock(TemplateService::class);
		$templates->method('ruleFlags')->willReturn(
			array_merge(TemplateService::BUILTIN[TemplateService::KEY_ANBEST_P]['rule_flags'],
				['zwischen_enabled' => false])
		);
		$mapper = $this->createMock(DeadlineMapper::class);
		$mapper->method('findByProject')->willReturn([]);
		$inserted = [];
		$mapper->method('insert')->willReturnCallback(function (Deadline $d) use (&$inserted) {
			$inserted[] = $d->getKind();
			return $d;
		});
		$m = $this->svc(['mapper' => $mapper, 'apMapper' => $apMapper, 'templates' => $templates]);
		$p = $this->project();
		$p->setBewEnd(new \DateTime('2027-12-31')); // spans a year boundary
		$m['svc']->recompute($p);
		$this->assertNotContains('zwischennachweis', $inserted);
		$this->assertContains('nachweis_frist', $inserted);
	}

	public function testZwischenDeadlinesAcrossYearBoundary(): void
	{
		$apMapper = $this->createMock(WorkPackageMapper::class);
		$apMapper->method('findByProject')->willReturn([]);
		$templates = $this->createMock(TemplateService::class);
		$templates->method('ruleFlags')->willReturn(
			TemplateService::BUILTIN[TemplateService::KEY_ANBEST_P]['rule_flags']
		);
		$mapper = $this->createMock(DeadlineMapper::class);
		$mapper->method('findByProject')->willReturn([]);
		$inserted = [];
		$mapper->method('insert')->willReturnCallback(function (Deadline $d) use (&$inserted) {
			$inserted[$d->getKind()][] = $d->getDedupeKey();
			return $d;
		});
		$m = $this->svc(['mapper' => $mapper, 'apMapper' => $apMapper, 'templates' => $templates]);
		$p = $this->project();
		$p->setBewEnd(new \DateTime('2027-06-30'));
		$m['svc']->recompute($p);
		// year boundary 2026-12-31 inside the period → one zwischen (due +2m → 2027-02-28)
		$this->assertSame(['1:zwischennachweis:2026'], $inserted['zwischennachweis'] ?? []);
	}

	public function testDueUnnotifiedAndMarkNotified(): void
	{
		$mapper = $this->createMock(DeadlineMapper::class);
		$mapper->method('findDueUnnotified')->with((new \DateTimeImmutable('today'))->format('Y-m-d'))
			->willReturn(['row']);
		$mapper->expects($this->once())->method('update')->willReturnCallback(fn($d) => $d);
		$m = $this->svc(['mapper' => $mapper]);
		$this->assertSame(['row'], $m['svc']->dueUnnotified());
		$d = new Deadline();
		$m['svc']->markNotified($d);
		$this->assertNotNull($d->getNotifiedAt());
	}
}

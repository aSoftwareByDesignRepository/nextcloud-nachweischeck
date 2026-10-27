<?php

declare(strict_types=1);

namespace OCA\NachweisCheck\Tests\Unit;

use OCA\NachweisCheck\Db\DeviationMapper;
use OCA\NachweisCheck\Exception\ValidationException;
use OCA\NachweisCheck\Service\AuditService;
use OCA\NachweisCheck\Service\DeviationService;
use Test\TestCase;

class DeviationServiceTest extends TestCase
{
	private function svc(?DeviationMapper $m = null): array
	{
		$m = $m ?? $this->createMock(DeviationMapper::class);
		$audit = $this->createMock(AuditService::class);
		return [new DeviationService($m, $audit), $m, $audit];
	}

	public function testBlankReasonRefused(): void
	{
		[$svc, $m] = $this->svc();
		$m->expects($this->never())->method('insert');
		$this->expectException(ValidationException::class);
		try {
			$svc->create(1, 'position', 3, 'cost', '   ', 'u1');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('reason_text', $e->getFields());
			throw $e;
		}
	}

	public function testInvalidScopeRefused(): void
	{
		[$svc] = $this->svc();
		$this->expectException(ValidationException::class);
		$svc->create(1, 'nonsense', 3, 'cost', 'why', 'u1');
	}

	public function testValidDeviationRecordedAndAudited(): void
	{
		[$svc, $m, $audit] = $this->svc();
		$m->method('insert')->willReturnCallback(function ($d) { $d->setId(1); return $d; });
		$audit->expects($this->once())->method('record')
			->with(1, 'deviation', $this->anything(), 'create', 'u1');
		$d = $svc->create(1, 'position', 3, 'cost', 'Lieferengpass Material', 'u1');
		$this->assertSame('Lieferengpass Material', $d->getReasonText());
	}
}

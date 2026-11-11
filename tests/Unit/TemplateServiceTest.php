<?php

declare(strict_types=1);

namespace OCA\NachweisCheck\Tests\Unit;

use OCA\NachweisCheck\Db\Template;
use OCA\NachweisCheck\Db\TemplateMapper;
use OCA\NachweisCheck\Exception\NotFoundException;
use OCA\NachweisCheck\Exception\ValidationException;
use OCA\NachweisCheck\Service\TemplateService;
use Test\TestCase;

class TemplateServiceTest extends TestCase
{
	private function svc(?TemplateMapper $m = null): array
	{
		$m = $m ?? $this->createMock(TemplateMapper::class);
		return [new TemplateService($m), $m];
	}

	// ---- definition -------------------------------------------------------

	public function testBuiltinAnbestPDefinition(): void
	{
		[$svc] = $this->svc();
		$def = $svc->definition(TemplateService::TPL_ANBEST_P);
		$this->assertSame('builtin', $def['source']);
		$this->assertTrue($def['rule_flags']['einzelansatz_20pct']);
		$this->assertFalse($def['rule_flags']['festbetrag']);
		$this->assertSame(6, $def['rule_flags']['final_months']);
		$this->assertTrue($def['rule_flags']['zwischen_enabled']);
		$this->assertSame(2, $def['rule_flags']['zwischen_months']);
		$this->assertCount(6, $def['skeleton']);
	}

	public function testPackDefinitionAvailableButNotInstalled(): void
	{
		[$svc] = $this->svc();
		$def = $svc->definition('land-nebenbest');
		$this->assertSame('pack', $def['source']);
		$this->assertSame(3, $def['rule_flags']['zwischen_months']);
		$this->assertCount(5, $def['skeleton']);
	}

	public function testUnknownKeyCollapsesTo404(): void
	{
		$m = $this->createMock(TemplateMapper::class);
		$m->method('findByKey')->willReturn(null);
		[$svc] = $this->svc($m);
		$this->expectException(NotFoundException::class);
		$svc->definition('nonexistent-pack');
	}

	public function testInstalledDefinitionMergesBuiltinDefaults(): void
	{
		$row = new Template();
		$row->setTplKey('custom-land');
		$row->setName('Custom');
		$row->setRuleFlags(json_encode(['final_months' => 9])); // partial
		$row->setSkeleton(json_encode([['code' => 'PK', 'title' => 'P', 'kind' => 'personal']]));
		$row->setSource('installed');
		$m = $this->createMock(TemplateMapper::class);
		$m->method('findByKey')->willReturn($row);
		[$svc] = $this->svc($m);
		$def = $svc->definition('custom-land');
		$this->assertSame('installed', $def['source']);
		$this->assertSame(9, $def['rule_flags']['final_months']);      // pack value wins
		$this->assertTrue($def['rule_flags']['einzelansatz_20pct']);   // builtin default fills gap
	}

	// ---- listTemplates ------------------------------------------------------

	public function testFreeTierListsOnlyBuiltinAndInstalled(): void
	{
		$row = new Template();
		$row->setTplKey('custom');
		$row->setName('Custom');
		$row->setSource('installed');
		$m = $this->createMock(TemplateMapper::class);
		$m->method('findAll')->willReturn([$row]);
		[$svc] = $this->svc($m);
		$keys = array_column($svc->listTemplates(false), 'key');
		$this->assertSame(['anbest-p', 'custom'], $keys);
	}

	public function testProTierListsPacksAndFestbetrag(): void
	{
		$m = $this->createMock(TemplateMapper::class);
		$m->method('findAll')->willReturn([]);
		[$svc] = $this->svc($m);
		$list = $svc->listTemplates(true);
		$keys = array_column($list, 'key');
		$this->assertContains('anbest-p', $keys);
		$this->assertContains('festbetrag', $keys);
		foreach (array_keys(TemplateService::PACKS) as $pack) {
			$this->assertContains($pack, $keys);
		}
	}

	// ---- installPack --------------------------------------------------------

	public function testInstallPackRejectsInvalidKey(): void
	{
		[$svc] = $this->svc();
		$this->expectException(ValidationException::class);
		try {
			$svc->installPack('Bad Key!', 'n', [], [], 'admin');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('tpl_key', $e->getFields());
			throw $e;
		}
	}

	public function testInstallPackRejectsBuiltinKeys(): void
	{
		foreach (array_keys(TemplateService::BUILTIN) as $key) {
			[$svc] = $this->svc();
			try {
				$svc->installPack($key, 'n', [], [], 'admin');
				$this->fail("builtin key {$key} must be refused");
			} catch (ValidationException $e) {
				$this->assertArrayHasKey('tpl_key', $e->getFields());
			}
		}
	}

	public function testInstallPackFiltersUnknownFlagsAndSanitizesSkeleton(): void
	{
		$m = $this->createMock(TemplateMapper::class);
		$m->method('findByKey')->willReturn(null);
		$m->expects($this->once())->method('insert')->willReturnCallback(function (Template $t) {
			$t->setId(1);
			return $t;
		});
		[$svc] = $this->svc($m);
		$t = $svc->installPack('land-x', 'Land X', [
			'final_months' => 4,
			'evil_flag' => true,          // filtered out
			'zwischen_enabled' => false,
		], [
			['code' => 'PK', 'title' => 'Personal', 'kind' => 'personal'],
			['code' => 'XX', 'title' => 'X', 'kind' => 'nonsense'],   // kind → 'sach'
			['code' => 'INCOMPLETE'],                                  // dropped
		], 'admin');
		$flags = json_decode((string)$t->getRuleFlags(), true);
		$this->assertSame(4, $flags['final_months']);
		$this->assertFalse($flags['zwischen_enabled']);
		$this->assertArrayNotHasKey('evil_flag', $flags);
		$skeleton = json_decode((string)$t->getSkeleton(), true);
		$this->assertCount(2, $skeleton);
		$this->assertSame('sach', $skeleton[1]['kind']);
		$this->assertSame('installed', $t->getSource());
	}

	public function testInstallPackUpdatesExistingRow(): void
	{
		$existing = new Template();
		$existing->setId(5);
		$existing->setTplKey('land-x');
		$m = $this->createMock(TemplateMapper::class);
		$m->method('findByKey')->willReturn($existing);
		$m->expects($this->once())->method('update')->willReturnCallback(fn($t) => $t);
		$m->expects($this->never())->method('insert');
		[$svc] = $this->svc($m);
		$svc->installPack('land-x', 'Renamed', ['final_months' => 3], [], 'admin');
	}
}

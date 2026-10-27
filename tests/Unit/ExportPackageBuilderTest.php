<?php

declare(strict_types=1);

namespace OCA\NachweisCheck\Tests\Unit;

use OCA\NachweisCheck\Db\Nachweis;
use OCA\NachweisCheck\Service\ExportPackageBuilder;
use Test\TestCase;

class ExportPackageBuilderTest extends TestCase
{
	private function nachweis(array $payload): Nachweis
	{
		$n = new Nachweis();
		$n->setId(3);
		$n->setProjectId(1);
		$n->setKind('final');
		$n->setVersion(2);
		$n->setState('final');
		$n->setManifestHash(str_repeat('ab', 32));
		$n->setPayloadJson(json_encode($payload));
		$n->setPeriodFrom(new \DateTime('2026-01-01'));
		$n->setPeriodTo(new \DateTime('2026-12-31'));
		$n->setGeneratedBy('admin');
		$n->setGeneratedAt(new \DateTime('2026-12-20 10:00:00'));
		return $n;
	}

	private function payload(): array
	{
		return [
			'meta' => [
				'kind' => 'final', 'project_name' => 'Projekt X',
				'funder_name' => 'Bund', 'funder_ref' => 'AZ-9',
				'period_from' => '2026-01-01', 'period_to' => '2026-12-31',
				'plan_version' => 2,
			],
			'ausgaben' => [
				['code' => 'PK', 'title' => 'Personalkosten', 'kind' => 'personal', 'budget_minor' => 100000, 'used_minor' => 90000],
			],
			'einnahmen' => [
				['received_date' => '2026-03-01', 'amount_minor' => 50000, 'call_ref' => 'Abruf 1', 'kind' => 'zuwendung'],
			],
			'belegliste' => [
				['invoice_date' => '2026-02-01', 'payment_date' => '2026-02-03', 'vendor' => 'Vendor X',
					'invoice_no' => 'R-1', 'purpose' => 'Material', 'amount_minor' => 1234, 'has_attachment' => true],
			],
			'fehlende_angaben' => [
				['type' => 'missing_attachment', 'entity' => 'receipt', 'entity_id' => 9, 'detail' => 'no file'],
			],
			'sachbericht' => [
				['ap_code' => 'AP1', 'ap_title' => 'Arbeitspaket 1', 'reports' => [
					['period' => '2026-H1', 'state' => 'submitted', 'summary' => 's', 'outcome' => 'o'],
				]],
			],
		];
	}

	public function testSectionsProduceAllExpectedFiles(): void
	{
		$files = (new ExportPackageBuilder())->sections($this->nachweis($this->payload()));
		foreach ([
			'01_ausgaben.csv', '02_einnahmen.csv', '03_belegliste.csv',
			'04_fehlende_angaben.csv', '05_sachbericht.txt',
			'10_nachweis.ods', '11_nachweis.pdf', 'manifest.json',
		] as $name) {
			$this->assertArrayHasKey($name, $files, "missing section {$name}");
			$this->assertNotSame('', $files[$name], "empty section {$name}");
		}
	}

	public function testManifestCarriesHashAndMetadata(): void
	{
		$n = $this->nachweis($this->payload());
		$files = (new ExportPackageBuilder())->sections($n);
		$manifest = json_decode($files['manifest.json'], true);
		$this->assertSame('nachweischeck', $manifest['app']);
		$this->assertSame($n->getManifestHash(), $manifest['manifest_hash']);
		$this->assertSame('final', $manifest['kind']);
		$this->assertSame(2, $manifest['version']);
		$this->assertSame('2026-01-01', $manifest['period_from']);
		$this->assertSame('2026-12-31', $manifest['period_to']);
		$this->assertSame('admin', $manifest['generated_by']);
		$this->assertIsArray($manifest['sections']);
		// manifest.json must not list itself
		$this->assertArrayNotHasKey('manifest.json', $manifest['sections']);
		$this->assertArrayHasKey('01_ausgaben.csv', $manifest['sections']);
	}

	public function testOdsSectionIsValidZip(): void
	{
		$files = (new ExportPackageBuilder())->sections($this->nachweis($this->payload()));
		$this->assertStringStartsWith("PK\x03\x04", $files['10_nachweis.ods']);
		// ODF requires the mimetype entry first — stored uncompressed
		$tmp = tempnam(sys_get_temp_dir(), 'nwt-ods-');
		file_put_contents($tmp, $files['10_nachweis.ods']);
		try {
			$zip = new \ZipArchive();
			$this->assertTrue($zip->open($tmp));
			$this->assertSame('application/vnd.oasis.opendocument.spreadsheet',
				$zip->getFromName('mimetype'));
			$this->assertNotFalse($zip->getFromName('content.xml'));
			$zip->close();
		} finally {
			@unlink($tmp);
		}
	}

	public function testPdfSectionHasPdfMagicAndContent(): void
	{
		$files = (new ExportPackageBuilder())->sections($this->nachweis($this->payload()));
		$this->assertStringStartsWith('%PDF', $files['11_nachweis.pdf']);
		$this->assertStringContainsString('%%EOF', $files['11_nachweis.pdf']);
	}

	public function testCsvSectionsRenderDeDeAmounts(): void
	{
		$files = (new ExportPackageBuilder())->sections($this->nachweis($this->payload()));
		$this->assertStringContainsString('Personalkosten', $files['01_ausgaben.csv']);
		$this->assertStringContainsString('1.000,00', $files['01_ausgaben.csv']); // 100000 minor
		$this->assertStringContainsString('Abruf 1', $files['02_einnahmen.csv']);
		$this->assertStringContainsString('ja', $files['03_belegliste.csv']); // attachment marker
		$this->assertStringContainsString('missing_attachment', $files['04_fehlende_angaben.csv']);
		$this->assertStringContainsString('AP1', $files['05_sachbericht.txt']);
	}

	public function testInvalidPayloadJsonYieldsEmptySections(): void
	{
		$n = $this->nachweis([]);
		$n->setPayloadJson('{broken');
		$this->assertSame([], (new ExportPackageBuilder())->sections($n));
	}

	public function testZipBundlesAllSections(): void
	{
		if (!class_exists(\ZipArchive::class)) {
			$this->markTestSkipped('zip extension unavailable');
		}
		$path = (new ExportPackageBuilder())->zip($this->nachweis($this->payload()));
		$this->assertNotNull($path);
		try {
			$zip = new \ZipArchive();
			$this->assertTrue($zip->open($path));
			foreach (['manifest.json', '01_ausgaben.csv', '10_nachweis.ods', '11_nachweis.pdf'] as $name) {
				$this->assertNotFalse($zip->getFromName($name), "missing {$name} in bundle");
			}
			$zip->close();
		} finally {
			@unlink($path);
		}
	}
}

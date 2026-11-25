<?php

declare(strict_types=1);

namespace OCA\NachweisCheck\Tests\Unit\Support;

use OCA\NachweisCheck\Support\SimplePdfBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Brand-purity contract for generated statement PDFs: every branded cell
 * (header, continuation header, per-page footer) must carry the resolved
 * doc brand, and no sibling Check-family brand may ever appear in output
 * (ported-footer regression: footer() printed a literal sibling brand).
 */
final class SimplePdfBuilderTest extends TestCase {
	/** Sibling brand display names that must never render in our exports. */
	private const SIBLING_BRANDS = [
		'ArbeitszeitCheck', 'AudioCheck', 'BookingCheck', 'BudgetCheck',
		'CareCheck', 'ContractCheck', 'CustomerCheck', 'DeskCheck',
		'DutyCheck', 'EinkaufCheck', 'ExpenseCheck', 'HomeCheck',
		'InventoryCheck', 'InvoiceCheck', 'KanzleiCheck', 'LogCheck',
		'MaintenanceCheck', 'MobilityCheck', 'PrivacyCheck', 'ProjectCheck',
		'SnackCheck', 'TicketCheck', 'TourCheck', 'WhistleCheck',
	];

	/** @return array<string,mixed> */
	private function doc(array $overrides = []): array {
		return array_merge([
			'brand' => 'NachweisCheck',
			'title' => 'Verwendungsnachweis',
			'columns' => ['Item', 'Amount'],
			'colWidths' => [0.7, 0.3],
			'rows' => [['Material', '10.00 EUR']],
			'totals' => [],
			'note' => '',
		], $overrides);
	}

	private static function assertNoSiblingBrand(string $pdf): void {
		foreach (self::SIBLING_BRANDS as $brand) {
			self::assertStringNotContainsString($brand, $pdf, "sibling brand leaked into PDF: {$brand}");
		}
	}

	public function testExplicitBrandRendersInHeaderAndFooter(): void {
		$pdf = SimplePdfBuilder::buildStatement($this->doc());
		self::assertStringStartsWith('%PDF-1.4', $pdf);
		// Header (18pt) + footer (8pt) both emit "(NachweisCheck) Tj"; the
		// footer regression left only the header occurrence behind.
		self::assertGreaterThanOrEqual(2, substr_count($pdf, '(NachweisCheck) Tj'));
		self::assertNoSiblingBrand($pdf);
	}

	public function testMissingBrandFallsBackToAppBrand(): void {
		$doc = $this->doc();
		unset($doc['brand']);
		$pdf = SimplePdfBuilder::buildStatement($doc);
		self::assertGreaterThanOrEqual(2, substr_count($pdf, '(NachweisCheck) Tj'));
		self::assertNoSiblingBrand($pdf);
	}

	public function testEmptyBrandFallsBackToAppBrand(): void {
		$pdf = SimplePdfBuilder::buildStatement($this->doc(['brand' => '   ']));
		self::assertStringContainsString('(NachweisCheck) Tj', $pdf);
		self::assertNoSiblingBrand($pdf);
	}

	public function testFooterBrandRepeatsOnEveryPage(): void {
		// Enough rows to force a second page: header p1 + footer p1 +
		// footer p2 all carry "(NachweisCheck) Tj" verbatim.
		$rows = [];
		for ($i = 0; $i < 60; $i++) {
			$rows[] = ['Position ' . $i, '1.00 EUR'];
		}
		$pdf = SimplePdfBuilder::buildStatement($this->doc(['rows' => $rows]));
		self::assertStringContainsString('(Page 2 of ', $pdf);
		self::assertGreaterThanOrEqual(3, substr_count($pdf, '(NachweisCheck) Tj'));
		self::assertNoSiblingBrand($pdf);
	}

	public function testFromLinesCarriesAppBrand(): void {
		$pdf = SimplePdfBuilder::fromLines('My statement', ['line one', 'line two']);
		self::assertStringContainsString('(NachweisCheck) Tj', $pdf);
		self::assertNoSiblingBrand($pdf);
	}
}

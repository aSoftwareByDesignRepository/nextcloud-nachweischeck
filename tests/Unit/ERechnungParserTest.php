<?php

declare(strict_types=1);

namespace OCA\NachweisCheck\Tests\Unit;

use OCA\NachweisCheck\Support\ERechnungParser;
use Test\TestCase;

class ERechnungParserTest extends TestCase
{
	public function testXrechnungUblParsed(): void
	{
		$xml = '<?xml version="1.0"?><Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2">'
			. '<ID>RE-2026-001</ID><IssueDate>2026-01-15</IssueDate>'
			. '<AccountingSupplierParty><Party><PartyName><Name>Beispiel GmbH</Name></PartyName></Party></AccountingSupplierParty>'
			. '<LegalMonetaryTotal><TaxInclusiveTotalAmount currencyID="EUR">119.00</TaxInclusiveTotalAmount>'
			. '<TaxExclusiveAmount>100.00</TaxExclusiveAmount></LegalMonetaryTotal>'
			. '<TaxTotal><TaxAmount currencyID="EUR">19.00</TaxAmount></TaxTotal></Invoice>';
		$r = ERechnungParser::parse($xml);
		$this->assertTrue($r['ok']);
		$this->assertSame('RE-2026-001', $r['draft']['invoice_no']);
		$this->assertSame('2026-01-15', $r['draft']['invoice_date']);
		$this->assertSame(11900, $r['draft']['total_minor']);
	}

	public function testMalformedInputNeverCrashes(): void
	{
		$this->assertFalse(ERechnungParser::parse('')['ok']);
		$this->assertFalse(ERechnungParser::parse('   ')['ok']);
		$this->assertFalse(ERechnungParser::parse('not xml at all')['ok']);
		$this->assertFalse(ERechnungParser::parse('<unclosed>')['ok']);
	}

	public function testXxeDoesNotExpandOrFetch(): void
	{
		$xml = '<?xml version="1.0"?><!DOCTYPE Invoice [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
			. '<Invoice><ID>&xxe;</ID></Invoice>';
		$r = ERechnungParser::parse($xml);
		// either fails or parses without entity content — never leaks file bytes
		if ($r['ok']) {
			$this->assertStringNotContainsString('root:', $r['draft']['invoice_no']);
		} else {
			$this->assertFalse($r['ok']);
		}
	}

	public function testPdfContainerHandledGracefully(): void
	{
		$r = ERechnungParser::parse('%PDF-1.4 fake pdf bytes no embedded xml');
		$this->assertFalse($r['ok']);
		$this->assertSame('unsupported_container', $r['reason']);
	}
}

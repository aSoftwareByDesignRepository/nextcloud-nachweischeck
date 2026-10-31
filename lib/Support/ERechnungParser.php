<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * E-Rechnung inbound parse (I-05, V1-11). XXE is disabled by construction:
 * XMLReader with LIBXML_NONET and without LIBXML_NOENT means no network
 * fetches and no entity expansion — a malformed or hostile document yields
 * an empty draft, never a crash and never an external call.
 *
 * Supported: XRechnung as raw XML (UBL Invoice/CreditNote, CII
 * CrossIndustryInvoice). ZUGFeRD PDF containers are delegated to
 * horstoeko/zugferd when that library is vendored; otherwise the draft is
 * empty with reason 'unsupported_container' — the review UI still lets the
 * user file the Beleg manually (parse = convenience, never a gate).
 */

namespace OCA\NachweisCheck\Support;

final class ERechnungParser
{
	private function __construct()
	{
	}

	/**
	 * @return array{ok:bool, reason?:string, draft?:array{vendor:string, invoice_no:string, invoice_date:string, payment_date:?string, net_minor:int, tax_minor:int, total_minor:int, currency:string}}
	 */
	public static function parse(string $bytes): array
	{
		$bytes = trim($bytes);
		if ($bytes === '') {
			return ['ok' => false, 'reason' => 'empty'];
		}
		if (str_starts_with($bytes, '%PDF')) {
			$xml = self::extractZugferdXml($bytes);
			if ($xml === null) {
				return ['ok' => false, 'reason' => 'unsupported_container'];
			}
			$bytes = $xml;
		}
		if ($bytes[0] !== '<') {
			return ['ok' => false, 'reason' => 'not_xml'];
		}
		try {
			return self::parseXml($bytes);
		} catch (\Throwable) {
			return ['ok' => false, 'reason' => 'parse_error'];
		}
	}

	private static function extractZugferdXml(string $pdf): ?string
	{
		// Preferred path: the vendored horstoeko/zugferd reader extracts the
		// embedded XML attachment properly. Parse errors yield null — the
		// caller degrades to 'unsupported_container', never a crash.
		if (class_exists(\horstoeko\zugferd\ZugferdDocumentPdfReader::class)) {
			try {
				$xml = \horstoeko\zugferd\ZugferdDocumentPdfReader::getXmlFromContent($pdf);
				if (is_string($xml) && trim($xml) !== '') {
					return $xml;
				}
			} catch (\Throwable) {
				// fall through to the conservative sniff below
			}
		}
		// ZUGFeRD embeds the XML as a factur-x.xml / zugferd-invoice.xml
		// attachment. Without the vendored library we do a conservative
		// embedded-XML sniff: locate a complete XML document inside the
		// stream. Malformed input → null, never an exception.
		if (!preg_match('/(<\?xml[^?]*\?>.*<\/(?:rsm:)?CrossIndustryInvoice>)/s', $pdf, $m)
			&& !preg_match('/(<\?xml[^?]*\?>.*<\/(?:ubl:)?Invoice>)/s', $pdf, $m)) {
			return null;
		}
		return $m[1];
	}

	/** @return array{ok:bool, reason?:string, draft?:array} */
	private static function parseXml(string $xml): array
	{
		$reader = new \XMLReader();
		if (!$reader->XML($xml, null, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
			return ['ok' => false, 'reason' => 'not_xml'];
		}
		$draft = [
			'vendor' => '', 'invoice_no' => '', 'invoice_date' => '',
			'payment_date' => null, 'net_minor' => 0, 'tax_minor' => 0,
			'total_minor' => 0, 'currency' => 'EUR',
		];
		$seenAny = false;
		while ($reader->read()) {
			if ($reader->nodeType !== \XMLReader::ELEMENT) {
				continue;
			}
			$local = $reader->localName;
			switch ($local) {
				case 'ID': // CII invoice number / UBL doc id — first one is the invoice id
					if ($draft['invoice_no'] === '') {
						$draft['invoice_no'] = trim((string)$reader->readString());
						$seenAny = true;
					}
					break;
				case 'IssueDate': // UBL
					$draft['invoice_date'] = self::ymd((string)$reader->readString());
					$seenAny = true;
					break;
				case 'IssueDateTime': // CII
					$draft['invoice_date'] = self::ciiDate((string)$reader->readString());
					$seenAny = true;
					break;
				case 'DueDate': // UBL
					$draft['payment_date'] = self::ymd((string)$reader->readString());
					break;
				case 'Name': // seller party name (first Name inside SellerTradeParty / AccountingSupplierParty)
					if ($draft['vendor'] === '') {
						$draft['vendor'] = trim((string)$reader->readString());
					}
					break;
				case 'TaxBasisTotalAmount':
				case 'BasisAmount':
					$draft['net_minor'] = self::toMinor((string)$reader->readString());
					break;
				case 'TaxTotalAmount':
				case 'CalculatedAmount':
					$draft['tax_minor'] = self::toMinor((string)$reader->readString());
					break;
				case 'TaxInclusiveTotalAmount':
				case 'GrandTotalAmount':
					$draft['total_minor'] = self::toMinor((string)$reader->readString());
					break;
				case 'InvoiceCurrencyCode':
				case 'InvoiceCurrency':
					$cur = trim((string)$reader->readString());
					if (preg_match('/^[A-Z]{3}$/', $cur)) {
						$draft['currency'] = $cur;
					}
					break;
			}
		}
		$reader->close();
		if (!$seenAny || ($draft['invoice_no'] === '' && $draft['total_minor'] === 0)) {
			return ['ok' => false, 'reason' => 'no_invoice_fields'];
		}
		return ['ok' => true, 'draft' => $draft];
	}

	private static function toMinor(string $decimal): int
	{
		$decimal = trim($decimal);
		if ($decimal === '' || !preg_match('/^[+-]?\d+(\.\d+)?$/', $decimal)) {
			return 0;
		}
		$neg = str_starts_with($decimal, '-');
		$decimal = ltrim($decimal, '+-');
		[$e, $c] = array_pad(explode('.', $decimal, 2), 2, '');
		$c .= '00';
		$minor = (int)$e * 100 + (int)substr($c, 0, 2);
		// Round half-up on the third decimal — invoice lines may carry more
		// precision than cents; silent truncation skews the draft total.
		if ((int)substr($c, 2, 1) >= 5) {
			$minor++;
		}
		return $neg ? -$minor : $minor;
	}

	private static function ymd(string $s): string
	{
		$s = trim($s);
		return preg_match('/^\d{4}-\d{2}-\d{2}/', $s) ? substr($s, 0, 10) : '';
	}

	/** CII datetime: "20260115" or "2026-01-15" or with time component. */
	private static function ciiDate(string $s): string
	{
		$s = trim($s);
		if (preg_match('/^(\d{4})(\d{2})(\d{2})/', $s, $m)) {
			return $m[1] . '-' . $m[2] . '-' . $m[3];
		}
		return self::ymd($s);
	}
}

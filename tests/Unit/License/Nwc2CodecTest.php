<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NachweisCheck\Tests\Unit\License;

use OCA\NachweisCheck\Config\VendorPublicKey;
use OCA\NachweisCheck\License\Nwc2Codec;
use PHPUnit\Framework\TestCase;

final class Nwc2CodecTest extends TestCase
{
	private string $secretKey;

	protected function setUp(): void
	{
		parent::setUp();
		// Shared Check-family test seed → VendorPublicKey::TEST_PUBLIC_KEY_B64
		$seed = hash('sha256', 'projectcheck-pc2-test-signing-v1', true);
		$kp = sodium_crypto_sign_seed_keypair($seed);
		$this->secretKey = sodium_crypto_sign_secretkey($kp);
		$pub = sodium_crypto_sign_publickey($kp);
		putenv('NW_VENDOR_PUBLIC_KEY_B64=' . VendorPublicKey::base64urlEncode($pub));
		putenv('NW_ALLOW_VENDOR_KEY_OVERRIDE=1');
		if (!defined('PHPUNIT_RUNNING')) {
			define('PHPUNIT_RUNNING', true);
		}
	}

	protected function tearDown(): void
	{
		putenv('NW_VENDOR_PUBLIC_KEY_B64');
		putenv('NW_ALLOW_VENDOR_KEY_OVERRIDE');
		parent::tearDown();
	}

	public function testValidKeyRoundTrip(): void
	{
		$wire = $this->mint(40, '2099-12-31');
		self::assertSame('', Nwc2Codec::classifyError($wire));
		$parsed = Nwc2Codec::parseAndVerify($wire);
		self::assertNotNull($parsed);
		self::assertSame('nachweischeck', $parsed['payload']['product']);
		self::assertSame(40, $parsed['payload']['mobileSeats']);
	}

	public function testTamperedPayloadFailsSignature(): void
	{
		$wire = $this->mint(10, '2099-01-01');
		$parts = explode('.', $wire);
		$payload = json_decode(VendorPublicKey::base64urlDecode($parts[1]), true);
		$payload['mobileSeats'] = 9999;
		$parts[1] = VendorPublicKey::base64urlEncode(Nwc2Codec::canonicalJson($payload));
		$tampered = implode('.', $parts);
		self::assertSame(Nwc2Codec::ERROR_INVALID_SIGNATURE, Nwc2Codec::classifyError($tampered));
	}

	public function testReorderedEquivalentJsonRejected(): void
	{
		// Byte-equality: a signature over a non-canonical byte order must not pass.
		$payloadJson = '{"mobileSeats":5,"validUntil":"2099-01-01","issuedAt":"2026-01-01","customerId":"acme-corp","product":"nachweischeck","v":2}';
		$sig = sodium_crypto_sign_detached($payloadJson, $this->secretKey);
		$wire = 'NWC2.' . VendorPublicKey::base64urlEncode($payloadJson) . '.' . VendorPublicKey::base64urlEncode($sig);
		self::assertSame(Nwc2Codec::ERROR_INVALID_PAYLOAD, Nwc2Codec::classifyError($wire));
	}

	public function testWrongProductRejected(): void
	{
		$payload = [
			'v' => 2, 'product' => 'ticketcheck', 'customerId' => 'acme-corp',
			'issuedAt' => '2026-01-01', 'validUntil' => '2099-01-01', 'mobileSeats' => 5,
		];
		$bytes = Nwc2Codec::canonicalJson($payload);
		$sig = sodium_crypto_sign_detached($bytes, $this->secretKey);
		$wire = 'NWC2.' . VendorPublicKey::base64urlEncode($bytes) . '.' . VendorPublicKey::base64urlEncode($sig);
		self::assertSame(Nwc2Codec::ERROR_INVALID_PAYLOAD, Nwc2Codec::classifyError($wire));
	}

	public function testForeignPrefixRejected(): void
	{
		$wire = $this->mint(5, '2099-01-01');
		$wire = 'TKC2' . substr($wire, 4);
		self::assertSame(Nwc2Codec::ERROR_INVALID_FORMAT, Nwc2Codec::classifyError($wire));
	}

	public function testWhitespaceStrippedBeforeParsing(): void
	{
		$wire = $this->mint(5, '2099-01-01');
		$wrapped = substr($wire, 0, 20) . " \n" . substr($wire, 20, 40) . "\r\n\t" . substr($wire, 60);
		self::assertSame('', Nwc2Codec::classifyError($wrapped));
	}

	public function testZeroSeatsRejected(): void
	{
		$payload = [
			'v' => 2, 'product' => 'nachweischeck', 'customerId' => 'acme-corp',
			'issuedAt' => '2026-01-01', 'validUntil' => '2099-01-01', 'mobileSeats' => 0,
		];
		self::assertFalse(Nwc2Codec::validatePayloadFields($payload));
	}

	public function testSeatBoundaryMaxAndOverflow(): void
	{
		self::assertSame('', Nwc2Codec::classifyError($this->mint(10000, '2099-01-01')));
		$payload = [
			'v' => 2, 'product' => 'nachweischeck', 'customerId' => 'acme-corp',
			'issuedAt' => '2026-01-01', 'validUntil' => '2099-01-01', 'mobileSeats' => 10001,
		];
		self::assertFalse(Nwc2Codec::validatePayloadFields($payload));
	}

	public function testValidUntilBeforeIssuedRejected(): void
	{
		$payload = [
			'v' => 2, 'product' => 'nachweischeck', 'customerId' => 'acme-corp',
			'issuedAt' => '2027-01-01', 'validUntil' => '2026-01-01', 'mobileSeats' => 5,
		];
		self::assertFalse(Nwc2Codec::validatePayloadFields($payload));
	}

	public function testGoldenFixtureVerifies(): void
	{
		// Shared ops fixture — the exact wire sbdlicenseops mints for this seed.
		$fixture = json_decode((string)file_get_contents(
			__DIR__ . '/../../../../sbdlicenseops/tests/fixtures/license_nwc2_golden.json'
		), true);
		putenv('NW_VENDOR_PUBLIC_KEY_B64=' . $fixture['publicKeyB64']);
		self::assertSame('', Nwc2Codec::classifyError($fixture['wireKey']));
		$parsed = Nwc2Codec::parseAndVerify($fixture['wireKey']);
		self::assertSame(5, $parsed['payload']['mobileSeats']);
	}

	public function testIsValidOnInclusive(): void
	{
		self::assertTrue(Nwc2Codec::isValidOn('2026-07-26', '2026-07-26'));
		self::assertFalse(Nwc2Codec::isValidOn('2026-07-25', '2026-07-26'));
	}

	private function mint(int $seats, string $until): string
	{
		$payload = [
			'v' => 2, 'product' => 'nachweischeck', 'customerId' => 'acme-corp',
			'issuedAt' => '2026-01-01', 'validUntil' => $until, 'mobileSeats' => $seats,
		];
		$bytes = Nwc2Codec::canonicalJson($payload);
		$sig = sodium_crypto_sign_detached($bytes, $this->secretKey);
		return 'NWC2.' . VendorPublicKey::base64urlEncode($bytes) . '.' . VendorPublicKey::base64urlEncode($sig);
	}
}

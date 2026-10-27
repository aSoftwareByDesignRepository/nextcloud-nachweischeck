<?php

declare(strict_types=1);

namespace OCA\NachweisCheck\Tests\Unit;

use OCA\NachweisCheck\Support\ManifestHasher;
use Test\TestCase;

class ManifestHasherTest extends TestCase
{
	public function testDeterministicAcrossKeyOrder(): void
	{
		$a = ['b' => 2, 'a' => ['y' => 1, 'x' => [2, 1]]];
		$b = ['a' => ['x' => [2, 1], 'y' => 1], 'b' => 2];
		$this->assertSame(ManifestHasher::hash($a), ManifestHasher::hash($b));
	}

	public function testListOrderIsMeaningful(): void
	{
		$a = ['x' => [1, 2]];
		$b = ['x' => [2, 1]];
		$this->assertNotSame(ManifestHasher::hash($a), ManifestHasher::hash($b));
	}

	public function testTamperChangesHash(): void
	{
		$payload = ['belegliste' => [['amount_minor' => 1000]]];
		$hash = ManifestHasher::hash($payload);
		$payload['belegliste'][0]['amount_minor'] = 1001;
		$this->assertNotSame($hash, ManifestHasher::hash($payload));
	}
}

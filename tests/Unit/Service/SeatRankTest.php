<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NachweisCheck\Tests\Unit\Service;

use OCA\NachweisCheck\Service\SeatRank;
use PHPUnit\Framework\TestCase;

final class SeatRankTest extends TestCase
{
	public function testRanksOrderByAssignedAtThenId(): void
	{
		$seats = [
			['id' => 3, 'assignedAt' => 200],
			['id' => 1, 'assignedAt' => 100],
			['id' => 2, 'assignedAt' => 100],
		];
		$ranks = SeatRank::ranks($seats);
		self::assertSame(1, $ranks[1]);
		self::assertSame(2, $ranks[2]);
		self::assertSame(3, $ranks[3]);
	}

	public function testWithinLimit(): void
	{
		$seats = [
			['id' => 1, 'assignedAt' => 100],
			['id' => 2, 'assignedAt' => 200],
			['id' => 3, 'assignedAt' => 300],
		];
		self::assertTrue(SeatRank::isWithinLimit($seats, 1, 2));
		self::assertTrue(SeatRank::isWithinLimit($seats, 2, 2));
		self::assertFalse(SeatRank::isWithinLimit($seats, 3, 2));
		self::assertFalse(SeatRank::isWithinLimit($seats, 1, 0));
		self::assertFalse(SeatRank::isWithinLimit($seats, 99, 10));
	}
}

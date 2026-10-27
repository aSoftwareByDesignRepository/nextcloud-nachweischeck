<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * DB error taxonomy helpers. NC wraps driver exceptions in OCP\DB\Exception
 * with a reason code; the unique-violation signal can sit on the exception
 * itself or any level of its previous chain (TicketCheck pattern).
 */

namespace OCA\NachweisCheck\Support;

use OCP\DB\Exception as DbException;

final class DbErrors
{
	private function __construct()
	{
	}

	public static function isUniqueViolation(\Throwable $e): bool
	{
		for ($cur = $e; $cur !== null; $cur = $cur->getPrevious()) {
			if ($cur instanceof DbException
				&& $cur->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				return true;
			}
		}
		return false;
	}
}

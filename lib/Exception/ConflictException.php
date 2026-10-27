<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Optimistic-lock and state conflicts. 409 tells the client to reload and
 * retry — never to merge (fail-closed CAS).
 */

namespace OCA\NachweisCheck\Exception;

class ConflictException extends NwException
{
	public function __construct(string $code = 'CONFLICT', string $message = 'Conflict — reload and retry.')
	{
		parent::__construct($code, $message, 409);
	}
}

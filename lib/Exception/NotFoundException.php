<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Uniform "not found" — thrown for BOTH missing entities and entities outside
 * the caller's scope. The wire payload is always identical
 * ({error: NOT_FOUND}), so a caller cannot enumerate foreign project/entity
 * ids by diffing response codes or messages (existence oracle).
 */

namespace OCA\NachweisCheck\Exception;

class NotFoundException extends NwException
{
	public function __construct()
	{
		parent::__construct('NOT_FOUND', 'Not found.', 404);
	}
}

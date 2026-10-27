<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NachweisCheck\Exception;

class ValidationException extends NwException
{
	/** @param array<string, string> $fields field => message */
	public function __construct(array $fields, string $message = 'Validation failed.')
	{
		parent::__construct('VALIDATION_FAILED', $message, 400, $fields);
	}
}

<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Thrown when a mutation path touches a finalized Nachweis or another
 * append-only fiscal record. Corrections are new versions only (NN-01).
 */

namespace OCA\NachweisCheck\Exception;

class ImmutableEntityException extends NwException
{
	public function __construct(string $message = 'This record is finalized; create a new version.')
	{
		parent::__construct('IMMUTABLE', $message, 409);
	}
}

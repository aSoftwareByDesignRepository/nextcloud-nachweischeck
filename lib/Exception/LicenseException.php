<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NachweisCheck\Exception;

class LicenseException extends NwException
{
	public function __construct(string $feature)
	{
		parent::__construct('LICENSE_REQUIRED', 'This feature requires NachweisCheck Pro.', 403, ['feature' => $feature]);
	}
}

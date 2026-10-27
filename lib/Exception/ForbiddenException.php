<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Authenticated but not permitted — only used when the caller legitimately
 * knows the resource exists (e.g. own project but wrong role). For scoped
 * entities outside the caller's visibility, use NotFoundException instead.
 */

namespace OCA\NachweisCheck\Exception;

class ForbiddenException extends NwException
{
	public function __construct(string $message = 'Access denied.')
	{
		parent::__construct('FORBIDDEN', $message, 403);
	}
}

<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Base for every NachweisCheck domain error. Carries the wire contract:
 * a stable machine code, an HTTP status, and an optional per-field map for
 * form-level rendering. Controllers never build error payloads themselves;
 * the middleware serializes these uniformly.
 */

namespace OCA\NachweisCheck\Exception;

use Exception;

class NwException extends Exception
{
	public function __construct(
		private readonly string $errorCode,
		string $message = '',
		private readonly int $httpStatus = 400,
		private readonly array $fields = [],
	) {
		parent::__construct($message !== '' ? $message : $errorCode, $httpStatus);
	}

	public function getErrorCode(): string
	{
		return $this->errorCode;
	}

	public function getHttpStatus(): int
	{
		return $this->httpStatus;
	}

	/** @return array<string, string> field => message-key */
	public function getFields(): array
	{
		return $this->fields;
	}
}

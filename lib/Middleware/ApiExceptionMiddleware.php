<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Uniform JSON error envelope for all NachweisCheck API surfaces.
 * Registered FIRST in Application::register() so the dispatcher's
 * reverse-order afterException consults it LAST (typed middleware win first).
 *
 * Wire contract: {"success": false, "error": "<CODE>", "message": "...",
 * "fields": {...}} — NOT_FOUND collapses missing and out-of-scope entities
 * into one identical payload (no existence oracle).
 */

namespace OCA\NachweisCheck\Middleware;

use OCA\NachweisCheck\Exception\NwException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class ApiExceptionMiddleware extends Middleware
{
	public function __construct(
		private readonly IRequest $request,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * @param Controller $controller
	 */
	public function afterException($controller, $methodName, \Exception $exception): JSONResponse
	{
		if (!$this->isJsonSurface($this->normalizedPath())) {
			throw $exception;
		}

		if ($exception instanceof NwException) {
			$payload = [
				'success' => false,
				'error' => $exception->getErrorCode(),
				'message' => $exception->getMessage(),
				'fields' => (object)$exception->getFields(),
			];
			return new JSONResponse($payload, $exception->getHttpStatus());
		}

		$this->logger->error('NachweisCheck API request failed', [
			'app' => 'nachweischeck',
			'exception' => $exception,
		]);
		return new JSONResponse([
			'success' => false,
			'error' => 'INTERNAL',
			'message' => 'An internal error occurred.',
			'fields' => (object)[],
		], Http::STATUS_INTERNAL_SERVER_ERROR);
	}

	private function isJsonSurface(string $path): bool
	{
		if (str_contains($path, '/api/')) {
			return true;
		}
		$accept = strtolower((string)$this->request->getHeader('Accept'));
		return str_contains($accept, 'application/json') || str_contains($accept, '+json');
	}

	private function normalizedPath(): string
	{
		$path = (string)$this->request->getPathInfo();
		$path = rawurldecode($path);
		foreach (['/index.php/apps/nachweischeck', '/apps/nachweischeck'] as $prefix) { // urlgate:ok — path matching, not URL emission
			if ($path === $prefix) {
				return '/';
			}
			if (str_starts_with($path, $prefix . '/')) {
				return substr($path, strlen($prefix)) ?: '/';
			}
		}
		return $path === '' ? '/' : $path;
	}
}

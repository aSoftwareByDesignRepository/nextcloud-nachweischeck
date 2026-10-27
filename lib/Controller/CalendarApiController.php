<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * ICS feed (I-08): per-project deadline calendar. Token-authed — calendar
 * clients subscribe outside a session. The token is a project secret,
 * generated lazily and rotatable by project admins; a wrong token is a
 * uniform 404 (no existence oracle). Feed content is deadlines only —
 * no fiscal data, no names, no amounts.
 */

namespace OCA\NachweisCheck\Controller;

use OCA\NachweisCheck\Exception\NotFoundException;
use OCA\NachweisCheck\Service\DeadlineService;
use OCA\NachweisCheck\Service\ProjectService;
use OCA\NachweisCheck\Db\ProjectMapper;
use OCA\NachweisCheck\Service\AccessDoorService;
use OCA\NachweisCheck\Service\AuditService;
use OCA\NachweisCheck\Service\PermissionService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TextPlainResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;

class CalendarApiController extends NwApiController
{
	public function __construct(
		string $appName, IRequest $request, IUserSession $userSession,
		AccessDoorService $door, PermissionService $perm,
		private readonly ProjectMapper $projectMapper,
		private readonly ProjectService $projects,
		private readonly DeadlineService $deadlines,
		private readonly ISecureRandom $random,
		private readonly AuditService $audit,
	) {
		parent::__construct($appName, $request, $userSession, $door, $perm);
	}

	/**
	 * Public ICS feed: GET /projects/{id}/calendar.ics?token=…
	 * Read-only, token-bound, deadline data only.
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function ics(int $projectId): Response
	{
		$p = $this->projectMapper->findById($projectId);
		$token = (string)$this->request->getParam('token', '');
		if ($p === null || $p->getIcsToken() === null || $token === ''
			|| !hash_equals($p->getIcsToken(), $token)) {
			// Same uniform 404 as every other scope miss — no oracle.
			$r = new TextPlainResponse('Not found.');
			$r->setStatus(Http::STATUS_NOT_FOUND);
			return $r;
		}
		$lines = [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Software by Design//NachweisCheck//DE',
			'CALSCALE:GREGORIAN',
			'X-WR-CALNAME:' . self::escape('NachweisCheck — ' . ($p->getName() ?: ('#' . $projectId))),
		];
		foreach ($this->deadlines->forProject($projectId) as $d) {
			$due = $d->getDueDate();
			if ($due === null) {
				continue;
			}
			$lines[] = 'BEGIN:VEVENT';
			$lines[] = 'UID:nw-deadline-' . $d->getId() . '@nachweischeck';
			$lines[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
			$lines[] = 'DTSTART;VALUE=DATE:' . $due->format('Ymd');
			$lines[] = 'SUMMARY:' . self::escape('[NachweisCheck] ' . $d->getKind() . ' — ' . $p->getName());
			$lines[] = 'END:VEVENT';
		}
		$lines[] = 'END:VCALENDAR';
		$body = implode("\r\n", $lines) . "\r\n";
		$r = new TextPlainResponse($body);
		$r->addHeader('Content-Type', 'text/calendar; charset=utf-8');
		return $r;
	}

	/** Return the feed URL, minting the token lazily on first read. */
	#[NoAdminRequired]
	public function feed(int $projectId): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireAdmin($projectId, $uid);
		$p = $this->projects->getOrFail($projectId);
		if ($p->getIcsToken() === null) {
			$p->setIcsToken(bin2hex($this->random->generate(24)));
			$this->projectMapper->update($p);
		}
		return $this->ok(['url' => $this->feedUrl($projectId, $p->getIcsToken())]);
	}

	/** Rotate the feed token — old subscribers lose access instantly. */
	#[NoAdminRequired]
	public function rotateFeed(int $projectId): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireAdmin($projectId, $uid);
		$p = $this->projects->getOrFail($projectId);
		$p->setIcsToken(bin2hex($this->random->generate(24)));
		$this->projectMapper->update($p);
		$this->audit->record($projectId, 'project', $projectId, 'ics_rotate', $uid);
		return $this->ok(['url' => $this->feedUrl($projectId, $p->getIcsToken())]);
	}

	private function feedUrl(int $projectId, string $token): string
	{
		return \OCP\Server::get(\OCP\IURLGenerator::class)->linkToRouteAbsolute(
			'nachweischeck.calendarApi.ics', ['projectId' => $projectId]) . '?token=' . urlencode($token);
	}

	private static function escape(string $s): string
	{
		return str_replace(['\\', ';', ',', "\r", "\n"], ['\\\\', '\\;', '\\,', ' ', ' '], $s);
	}
}

<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Parses NachweisCheck activity subjects into localized feed entries.
 * Unknown subjects throw UnknownActivityException so the Activity app can
 * skip rows written by a newer/other version gracefully.
 */

namespace OCA\NachweisCheck\Activity;

use OCA\NachweisCheck\AppInfo\Application;
use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use OCP\Activity\IProvider;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;

class Provider implements IProvider
{
	public function __construct(
		private readonly IFactory $l10nFactory,
		private readonly IURLGenerator $url,
	) {
	}

	/**
	 * @throws UnknownActivityException
	 */
	public function parse($language, IEvent $event, ?IEvent $previousEvent = null): IEvent
	{
		if ($event->getApp() !== Application::APP_ID) {
			throw new UnknownActivityException();
		}
		// Every parsed event must carry an icon — an empty icon string makes
		// the Activity app's ActivityModel throw and fails the whole stream.
		$event->setIcon($this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app.svg')));
		$l = $this->l10nFactory->get(Application::APP_ID, $language);
		$p = $event->getSubjectParameters();
		$project = (string)($p[0] ?? '');

		switch ($event->getSubject()) {
			case 'project_activated':
				$event->setParsedSubject($l->t('Project activated: %s', [$project]));
				break;
			case 'project_state':
				$event->setParsedSubject($l->t('Project %1$s moved to state %2$s', [$project, (string)($p[1] ?? '')]));
				break;
			case 'finplan_activated':
				$event->setParsedSubject($l->t('Financing plan v%2$s activated for %1$s', [$project, (string)($p[1] ?? '')]));
				break;
			case 'nachweis_generated':
				$event->setParsedSubject($l->t('Nachweis (%2$s, v%3$s) generated for %1$s', [$project, (string)($p[1] ?? ''), (string)($p[2] ?? '')]));
				break;
			case 'deviation_created':
				$event->setParsedSubject($l->t('Deviation logged on %1$s (%2$s/%3$s)', [$project, (string)($p[1] ?? ''), (string)($p[2] ?? '')]));
				break;
			case 'auditor_granted':
				$event->setParsedSubject($l->t('Auditor seat granted on %1$s (%2$s)', [$project, (string)($p[1] ?? '')]));
				break;
			case 'auditor_revoked':
				$event->setParsedSubject($l->t('Auditor seat revoked on %1$s (%2$s)', [$project, (string)($p[1] ?? '')]));
				break;
			case 'reallocation_requested':
				$event->setParsedSubject($l->t('Budget reallocation requested on %s', [$project]));
				break;
			case 'reallocation_decided':
				$event->setParsedSubject($l->t('Budget reallocation %2$s on %1$s', [$project, (string)($p[1] ?? '')]));
				break;
			case 'carryover_created':
				$event->setParsedSubject($l->t('Carryover recorded on %s', [$project]));
				break;
			default:
				throw new UnknownActivityException();
		}
		return $event;
	}
}

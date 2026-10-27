<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Renders NachweisCheck notifications (I-10): deadline reminders pushed by
 * DeadlineReminderJob get a human subject/message/link here; unknown
 * subjects surface as UnknownNotificationException so stale installs never
 * leak raw parameter arrays into the notification list.
 */

namespace OCA\NachweisCheck\Notification;

use OCA\NachweisCheck\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

class Notifier implements INotifier
{
	public function __construct(
		private readonly IFactory $l10nFactory,
		private readonly IURLGenerator $urlGenerator,
	) {
	}

	public function getID(): string
	{
		return Application::APP_ID;
	}

	public function getName(): string
	{
		return $this->l10nFactory->get(Application::APP_ID)->t('NachweisCheck');
	}

	public function prepare(INotification $notification, string $languageCode): INotification
	{
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException();
		}
		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$p = $notification->getSubjectParameters();
		$project = (string)($p['project'] ?? '');
		$due = (string)($p['due'] ?? '');
		$kind = (string)($p['kind'] ?? '');

		switch ($notification->getSubject()) {
			case 'nw_deadline':
				$kindLabel = match ($kind) {
					'nachweis_final' => $l->t('Final funder report due'),
					'zwischen' => $l->t('Interim report (Zwischennachweis) due'),
					'bew_end' => $l->t('Funding period ends soon'),
					'ap_end' => $l->t('Work package ends'),
					default => $l->t('Deadline'),
				};
				$subject = $project !== ''
					? $l->t('%s — %s (%s)', [$kindLabel, $project, $due])
					: $l->t('%s due %s', [$kindLabel, $due]);
				$notification->setParsedSubject($subject);
				$notification->setParsedMessage($l->t('Open NachweisCheck for the deadline list and the Nachweis export.'));
				$notification->setLink($this->urlGenerator->linkToRouteAbsolute('nachweischeck.page.index'));
				return $notification;
			case 'nw_gaps':
				$count = (int)($p['count'] ?? 0);
				$notification->setParsedSubject(
					$project !== ''
						? $l->t('%s — %d open audit items', [$project, $count])
						: $l->t('%d open audit items', [$count])
				);
				$notification->setParsedMessage($l->t('Missing receipts, unpriced time entries or unsigned timesheets — check the readiness list before the next export.'));
				$notification->setLink($this->urlGenerator->linkToRouteAbsolute('nachweischeck.page.index'));
				return $notification;
			default:
				throw new UnknownNotificationException();
		}
	}
}

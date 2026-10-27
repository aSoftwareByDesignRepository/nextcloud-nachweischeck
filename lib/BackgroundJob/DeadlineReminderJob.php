<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Daily deadline notifier (MH-10, I-10): sends NC notifications to project
 * admin+finance for due/overdue Fristen, deduplicated via notified_at.
 */

namespace OCA\NachweisCheck\BackgroundJob;

use OCA\NachweisCheck\Db\ProjectMapper;
use OCA\NachweisCheck\Db\ProjectMemberMapper;
use OCA\NachweisCheck\Service\DeadlineService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;
use OCP\Notification\IManager;

class DeadlineReminderJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private readonly DeadlineService $deadlines,
		private readonly ProjectMapper $projectMapper,
		private readonly ProjectMemberMapper $memberMapper,
		private readonly IManager $notifications,
		private readonly IConfig $userConfig,
	) {
		parent::__construct($time);
		$this->setInterval(24 * 3600);
	}

	protected function run($argument): void
	{
		foreach ($this->deadlines->dueUnnotified() as $d) {
			$project = $this->projectMapper->findById($d->getProjectId());
			if ($project === null || $project->getState() === 'closed') {
				$this->deadlines->markNotified($d);
				continue;
			}
			foreach ($this->memberMapper->findByProject($d->getProjectId()) as $m) {
				if (!in_array($m->getRole(), ['admin', 'finance'], true)) {
					continue;
				}
				// Per-user opt-out (I-10): users silence deadline pings without
				// losing member rights — stored in user config, never in logs.
				if ($this->userConfig->getUserValue($m->getUid(), 'nachweischeck', 'notify_deadlines', '1') === '0') {
					continue;
				}
				$notif = $this->notifications->createNotification();
				$notif->setApp('nachweischeck')
					->setUser($m->getUid())
					->setDateTime(new \DateTime())
					->setObject('deadline', (string)$d->getId())
					->setSubject('nw_deadline', [
						'kind' => $d->getKind(),
						'due' => $d->getDueDate()?->format('Y-m-d'),
						'project' => $project->getName(),
					]);
				$this->notifications->notify($notif);
			}
			$this->deadlines->markNotified($d);
		}
	}
}

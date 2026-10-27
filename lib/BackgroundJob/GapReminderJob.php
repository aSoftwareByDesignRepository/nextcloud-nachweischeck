<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Monthly gap ping (NH-05): for every ACTIVE project, run the
 * Pruefungsbereit checklist and — when it has entries — send ONE
 * notification per admin/finance member per project per calendar month.
 * The dedupe marker lives in appconfig (nw_gap_ping_<pid> = YYYY-MM), so
 * restarts inside the same month never double-notify and a stale month
 * pings again. Members opt out via the same notify_deadlines user flag.
 */

namespace OCA\NachweisCheck\BackgroundJob;

use OCA\NachweisCheck\Db\ProjectMapper;
use OCA\NachweisCheck\Db\ProjectMemberMapper;
use OCA\NachweisCheck\Service\ReadinessService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\Notification\IManager;

class GapReminderJob extends TimedJob
{
	private const APP_ID = 'nachweischeck';

	public function __construct(
		ITimeFactory $time,
		private readonly ReadinessService $readiness,
		private readonly ProjectMapper $projectMapper,
		private readonly ProjectMemberMapper $memberMapper,
		private readonly IManager $notifications,
		private readonly IConfig $userConfig,
		private readonly IAppConfig $appConfig,
	) {
		parent::__construct($time);
		$this->setInterval(30 * 24 * 3600);
	}

	protected function run($argument): void
	{
		$month = $this->time->getDateTime()->format('Y-m');
		foreach ($this->projectMapper->findAll() as $project) {
			if ($project->getState() !== 'active') {
				continue;
			}
			$key = 'nw_gap_ping_' . $project->getId();
			if ($this->appConfig->getValueString(self::APP_ID, $key, '') === $month) {
				continue;
			}
			$report = $this->readiness->checklist($project);
			$count = array_sum(array_map(static fn($c) => $c['count'], $report['checks']));
			if ($count === 0) {
				$this->appConfig->setValueString(self::APP_ID, $key, $month);
				continue;
			}
			foreach ($this->memberMapper->findByProject($project->getId()) as $m) {
				if (!in_array($m->getRole(), ['admin', 'finance'], true)) {
					continue;
				}
				if ($this->userConfig->getUserValue($m->getUid(), self::APP_ID, 'notify_deadlines', '1') === '0') {
					continue;
				}
				$notif = $this->notifications->createNotification();
				$notif->setApp(self::APP_ID)
					->setUser($m->getUid())
					->setDateTime(new \DateTime())
					->setObject('project', (string)$project->getId())
					->setSubject('nw_gaps', [
						'project' => $project->getName(),
						'count' => $count,
					]);
				$this->notifications->notify($notif);
			}
			$this->appConfig->setValueString(self::APP_ID, $key, $month);
		}
	}
}

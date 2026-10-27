<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * ADR-06: pre-build the Nachweis export package in the background. The
 * generated ZIP lands in appdata so downloads stream a file instead of
 * re-assembling 2k+ rows inside a web request. Failures are logged; the
 * download path still falls back to synchronous rendering.
 */

namespace OCA\NachweisCheck\BackgroundJob;

use OCA\NachweisCheck\Db\NachweisMapper;
use OCA\NachweisCheck\Service\ExportPackageBuilder;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\Files\AppData\IAppDataFactory;
use Psr\Log\LoggerInterface;

class NachweisExportJob extends QueuedJob
{
	public function __construct(
		ITimeFactory $time,
		private readonly NachweisMapper $nachweisMapper,
		private readonly ExportPackageBuilder $exporter,
		private readonly IAppDataFactory $appDataFactory,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	protected function run($argument): void
	{
		$id = (int)($argument['nachweisId'] ?? 0);
		if ($id <= 0) {
			return;
		}
		$n = $this->nachweisMapper->findById($id);
		if ($n === null) {
			return;
		}
		$zipPath = $this->exporter->zip($n);
		if ($zipPath === null) {
			$this->logger->warning('NachweisCheck export job: ZIP unavailable', ['app' => 'nachweischeck', 'nachweis' => $id]);
			return;
		}
		try {
			$appData = $this->appDataFactory->get('nachweischeck');
			$folder = $appData->getFolder('exports');
		} catch (\Throwable) {
			$appData = $this->appDataFactory->get('nachweischeck');
			$folder = $appData->newFolder('exports');
		}
		$name = 'nachweis-' . $id . '-v' . $n->getVersion() . '.zip';
		if ($folder->fileExists($name)) {
			$folder->getFile($name)->delete();
		}
		$folder->newFile($name, file_get_contents($zipPath));
		@unlink($zipPath);
	}
}

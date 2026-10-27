<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Antragstellung document (NH-02): renders the DRAFT Finanzierungsplan
 * plus Arbeitspakete into a funder-shaped application skeleton
 * (CSV / ODS / PDF). Planning surface only — the app never submits
 * anything; the document is the Träger's starting point for their own
 * application. Draft plan preferred, active plan as fallback so the
 * document works both before and after Bewilligung.
 */

namespace OCA\NachweisCheck\Service;

use OCA\NachweisCheck\Db\CofunderMapper;
use OCA\NachweisCheck\Db\Project;
use OCA\NachweisCheck\Db\WorkPackageMapper;
use OCA\NachweisCheck\Exception\NotFoundException;
use OCA\NachweisCheck\Support\CsvCell;
use OCA\NachweisCheck\Support\Money;
use OCA\NachweisCheck\Support\OdsBuilder;
use OCA\NachweisCheck\Support\SimplePdfBuilder;

class AntragExportService
{
	public const FORMATS = ['csv', 'ods', 'pdf'];

	public function __construct(
		private readonly FinplanService $finplan,
		private readonly WorkPackageMapper $apMapper,
		private readonly CofunderMapper $cofunderMapper,
	) {
	}

	/**
	 * @return array{positions:list<array>, aps:list<array>, cofunders:list<array>, total_minor:int, project:Project}
	 */
	private function collect(Project $project): array
	{
		$ver = $this->finplan->draftFor($project->getId()) ?? $this->finplan->activeFor($project->getId());
		if ($ver === null) {
			throw new NotFoundException();
		}
		$positions = [];
		$total = 0;
		$cofNames = [];
		foreach ($this->cofunderMapper->findByProject($project->getId()) as $c) {
			$cofNames[$c->getId()] = $c->getName();
		}
		foreach ($this->finplan->positions($ver) as $pos) {
			$total += $pos->getBudgetMinor();
			$positions[] = [
				'code' => $pos->getCode(),
				'title' => $pos->getTitle(),
				'kind' => $pos->getKind(),
				'budget_minor' => $pos->getBudgetMinor(),
				'funder' => $cofNames[$pos->getCofunderId() ?? 0] ?? '',
			];
		}
		$aps = [];
		foreach ($this->apMapper->findByProject($project->getId()) as $ap) {
			$aps[] = [
				'code' => $ap->getCode(),
				'title' => $ap->getTitle(),
				'start' => $ap->getStartDate()?->format('Y-m-d'),
				'end' => $ap->getEndDate()?->format('Y-m-d'),
			];
		}
		$cofs = [];
		foreach ($this->cofunderMapper->findByProject($project->getId()) as $c) {
			$cofs[] = ['name' => $c->getName(), 'ref' => $c->getRef()];
		}
		return [
			'positions' => $positions,
			'aps' => $aps,
			'cofunders' => $cofs,
			'total_minor' => $total,
			'project' => $project,
		];
	}

	public function buildCsv(Project $project): string
	{
		$d = $this->collect($project);
		$rows = [
			['Finanzierungsplan (Entwurf) — ' . CsvCell::delimitedSafe($project->getName())],
			[],
			['Position', 'Titel', 'Art', 'Betrag (EUR)', 'Geber'],
		];
		foreach ($d['positions'] as $p) {
			$rows[] = [
				CsvCell::delimitedSafe($p['code']), CsvCell::delimitedSafe($p['title']),
				$p['kind'], Money::formatMinor($p['budget_minor']),
				CsvCell::delimitedSafe($p['funder']),
			];
		}
		$rows[] = ['Summe', '', '', Money::formatMinor($d['total_minor']), ''];
		$rows[] = [];
		$rows[] = ['Arbeitspaket', 'Titel', 'Start', 'Ende'];
		foreach ($d['aps'] as $ap) {
			$rows[] = [CsvCell::delimitedSafe($ap['code']), CsvCell::delimitedSafe($ap['title']), $ap['start'], $ap['end']];
		}
		return CsvCell::buildCsv($rows);
	}

	public function buildOds(Project $project): string
	{
		$d = $this->collect($project);
		$rows = [];
		foreach ($d['positions'] as $p) {
			$rows[] = [$p['code'], $p['title'], $p['kind'], Money::formatMinor($p['budget_minor']), $p['funder']];
		}
		$rows[] = ['Summe', '', '', Money::formatMinor($d['total_minor']), ''];
		$apRows = [];
		foreach ($d['aps'] as $ap) {
			$apRows[] = [$ap['code'], $ap['title'], $ap['start'], $ap['end']];
		}
		$cofRows = [];
		foreach ($d['cofunders'] as $c) {
			$cofRows[] = [$c['name'], (string)$c['ref']];
		}
		return (new OdsBuilder())->build([
			['name' => 'Finanzierungsplan', 'columns' => ['Position', 'Titel', 'Art', 'Betrag (EUR)', 'Geber'], 'rows' => $rows],
			['name' => 'Arbeitspakete', 'columns' => ['AP', 'Titel', 'Start', 'Ende'], 'rows' => $apRows],
			['name' => 'Mittelgeber', 'columns' => ['Name', 'Aktenzeichen'], 'rows' => $cofRows],
		], 'NachweisCheck Antrag');
	}

	public function buildPdf(Project $project): string
	{
		$d = $this->collect($project);
		$rows = [];
		foreach ($d['positions'] as $p) {
			$rows[] = [$p['code'] . ' ' . $p['title'], Money::formatMinor($p['budget_minor']) . ' €', $p['funder']];
		}
		$rows[] = ['Summe', Money::formatMinor($d['total_minor']) . ' €', ''];
		return SimplePdfBuilder::buildStatement([
			'brand' => 'NachweisCheck',
			'title' => 'Finanzierungsplan (Antrag)',
			'meta' => [
				['Projekt', (string)$project->getName()],
				['Antragsteller', (string)$project->getUid()],
				['Zuwendungsgeber', (string)($project->getFunderName() ?? '')],
				['Aktenzeichen', (string)($project->getFunderRef() ?? '')],
				['Bewilligungszeitraum', ($project->getBewStart()?->format('Y-m-d') ?? '') . ' bis ' . ($project->getBewEnd()?->format('Y-m-d') ?? '')],
			],
			'tableTitle' => 'Einzelansätze',
			'columns' => ['Position', 'Betrag', 'Geber'],
			'colWidths' => [0.55, 0.2, 0.25],
			'rows' => $rows,
			'note' => count($d['aps']) . ' Arbeitspakete — siehe ODS/CSV fuer die AP-Uebersicht.',
		]);
	}
}

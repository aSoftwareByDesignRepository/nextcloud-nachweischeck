<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Multi-funder co-financing (BQ-03): the lead funder lives on nw_project;
 * additional funders are Cofunder rows referenced by nw_position.cofunder_id.
 * A cofunder still referenced by ANY position (any plan version — frozen
 * versions must stay resolvable) cannot be deleted.
 */

namespace OCA\NachweisCheck\Service;

use OCA\NachweisCheck\Db\Cofunder;
use OCA\NachweisCheck\Db\CofunderMapper;
use OCA\NachweisCheck\Db\PositionMapper;
use OCA\NachweisCheck\Db\FinplanVersionMapper;
use OCA\NachweisCheck\Exception\ConflictException;
use OCA\NachweisCheck\Exception\NotFoundException;
use OCA\NachweisCheck\Exception\ValidationException;

class CofunderService
{
	public function __construct(
		private readonly CofunderMapper $mapper,
		private readonly PositionMapper $posMapper,
		private readonly FinplanVersionMapper $verMapper,
		private readonly AuditService $audit,
		private readonly ConcurrencyGuard $guard,
	) {
	}

	/** @return list<Cofunder> */
	public function list(int $projectId): array
	{
		return $this->mapper->findByProject($projectId);
	}

	public function create(int $projectId, string $name, ?string $ref, ?string $contact, ?string $note, string $actor): Cofunder
	{
		$name = trim($name);
		if ($name === '') {
			throw new ValidationException(['name' => 'Name is required.']);
		}
		return $this->guard->run(ConcurrencyGuard::projectKey($projectId), function () use ($projectId, $name, $ref, $contact, $note, $actor): Cofunder {
			foreach ($this->mapper->findByProject($projectId) as $c) {
				if (mb_strtolower($c->getName()) === mb_strtolower($name)) {
					throw new ConflictException('COFUNDER_EXISTS', 'A co-funder with that name already exists.');
				}
			}
			$c = new Cofunder();
			$c->setProjectId($projectId);
			$c->setName($name);
			$c->setRef($ref !== null && trim($ref) !== '' ? trim($ref) : null);
			$c->setContact($contact !== null && trim($contact) !== '' ? trim($contact) : null);
			$c->setNote($note !== null && trim($note) !== '' ? trim($note) : null);
			$c->setCreatedAt(new \DateTime());
			$c = $this->mapper->insert($c);
			$this->audit->record($projectId, 'cofunder', $c->getId(), 'create', $actor, null, [
				'name' => $name, 'ref' => $c->getRef(),
			]);
			return $c;
		});
	}

	public function delete(int $projectId, int $id, string $actor): void
	{
		$c = $this->getOrFail($projectId, $id);
		$this->guard->run(ConcurrencyGuard::projectKey($projectId), function () use ($projectId, $c, $actor): void {
			// Referenced anywhere → refuse; frozen plan versions must keep
			// resolving their cofunder for every future Nachweis reprint.
			foreach ($this->verMapper->findByProject($projectId) as $ver) {
				foreach ($this->posMapper->findByPlanVer($ver->getId()) as $pos) {
					if ($pos->getCofunderId() === $c->getId()) {
						throw new ConflictException('COFUNDER_IN_USE', 'Co-funder is still referenced by plan positions.');
					}
				}
			}
			$this->audit->record($projectId, 'cofunder', $c->getId(), 'delete', $actor, [
				'name' => $c->getName(),
			]);
			$this->mapper->delete($c);
		});
	}

	public function getOrFail(int $projectId, int $id): Cofunder
	{
		$c = $this->mapper->findById($id);
		if ($c === null || $c->getProjectId() !== $projectId) {
			throw new NotFoundException();
		}
		return $c;
	}

	/**
	 * Map cofunder id → display name for one project; index 0 = lead funder.
	 * @return array<int, string>
	 */
	public function labelMap(int $projectId, string $leadName): array
	{
		$map = [0 => $leadName];
		foreach ($this->mapper->findByProject($projectId) as $c) {
			$map[$c->getId()] = $c->getName();
		}
		return $map;
	}
}

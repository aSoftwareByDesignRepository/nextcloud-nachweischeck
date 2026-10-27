<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Funder template engine (MH-08, V1-13). ANBest-P is the built-in default;
 * Landes/Stiftung/LEADER variants are installable packs stored in nw_template.
 * Rule flags drive the threshold engine, deadline calculator and export
 * layout — a template is data, not code.
 */

namespace OCA\NachweisCheck\Service;

use OCA\NachweisCheck\Db\Template;
use OCA\NachweisCheck\Db\TemplateMapper;
use OCA\NachweisCheck\Exception\NotFoundException;

class TemplateService
{
	public const KEY_ANBEST_P = 'anbest-p';

	/** Installable pack definitions shipped with the app (Pro, V1-13). */
	public const PACKS = [
		'land-nebenbest' => [
			'name' => 'Landesförderung (Nebenbestimmungen Land)',
			'rule_flags' => ['einzelansatz_20pct' => true, 'zwischen_enabled' => true, 'zwischen_months' => 3],
			'skeleton' => [
				['code' => 'PK', 'title' => 'Personalkosten', 'kind' => 'personal'],
				['code' => 'SK', 'title' => 'Sachkosten', 'kind' => 'sach'],
				['code' => 'RK', 'title' => 'Reisekosten', 'kind' => 'reise'],
				['code' => 'IK', 'title' => 'Investitionen', 'kind' => 'extern'],
				['code' => 'EA', 'title' => 'Eigenanteil', 'kind' => 'eigen'],
			],
		],
		'stiftung' => [
			'name' => 'Stiftungsförderung',
			'rule_flags' => ['einzelansatz_20pct' => true, 'final_months' => 3, 'zwischen_enabled' => false],
			'skeleton' => [
				['code' => 'PK', 'title' => 'Personalkosten', 'kind' => 'personal'],
				['code' => 'SK', 'title' => 'Sachkosten', 'kind' => 'sach'],
				['code' => 'HK', 'title' => 'Honorare', 'kind' => 'extern'],
				['code' => 'EA', 'title' => 'Eigenmittel', 'kind' => 'eigen'],
			],
		],
		'leader-esf' => [
			'name' => 'LEADER / ESF (EU-Kofinanzierung)',
			'rule_flags' => ['einzelansatz_20pct' => true, 'final_months' => 6, 'zwischen_enabled' => true, 'zwischen_months' => 2],
			'skeleton' => [
				['code' => 'PK', 'title' => 'Personnel costs', 'kind' => 'personal'],
				['code' => 'TR', 'title' => 'Travel and accommodation', 'kind' => 'reise'],
				['code' => 'EQ', 'title' => 'Equipment', 'kind' => 'extern'],
				['code' => 'EX', 'title' => 'External expertise', 'kind' => 'extern'],
				['code' => 'OF', 'title' => 'Office flatrate', 'kind' => 'sach'],
				['code' => 'EA', 'title' => 'Eigenanteil', 'kind' => 'eigen'],
			],
		],
	];

	/** Built-in template definitions — always available, source 'builtin'. */
	public const BUILTIN = [
		self::KEY_ANBEST_P => [
			'name' => 'ANBest-P (Bund, Ausgabenfinanzierung)',
			'rule_flags' => [
				'einzelansatz_20pct' => true,
				'festbetrag' => false,
				'final_months' => 6,
				'zwischen_enabled' => true,
				'zwischen_months' => 2,
				'bew_end_warn_days' => 30,
				'belegliste_layout' => 'anbest_p_6_4',
			],
			'skeleton' => [
				['code' => 'PK', 'title' => 'Personalkosten', 'kind' => 'personal'],
				['code' => 'SK', 'title' => 'Sachkosten', 'kind' => 'sach'],
				['code' => 'RK', 'title' => 'Reisekosten', 'kind' => 'reise'],
				['code' => 'EK', 'title' => 'Externe Leistungen', 'kind' => 'extern'],
				['code' => 'EA', 'title' => 'Eigenanteil', 'kind' => 'eigen'],
				['code' => 'FM', 'title' => 'Fremdmittel', 'kind' => 'fremd'],
			],
		],
		'festbetrag' => [
			'name' => 'Festbetragsfinanzierung',
			'rule_flags' => [
				'einzelansatz_20pct' => false,
				'festbetrag' => true,
				'final_months' => 6,
				'zwischen_enabled' => false,
				'zwischen_months' => 2,
				'bew_end_warn_days' => 30,
				'belegliste_layout' => 'anbest_p_6_4',
			],
			'skeleton' => [
				['code' => 'FB', 'title' => 'Festbetrag', 'kind' => 'sach'],
			],
		],
	];

	public function __construct(
		private readonly TemplateMapper $mapper,
	) {
	}

	/**
	 * @return array<string, mixed> effective rule flags for a template key
	 */
	public function ruleFlags(string $tplKey): array
	{
		$def = $this->definition($tplKey);
		return $def['rule_flags'];
	}

	/**
	 * @return array{name:string, rule_flags:array, skeleton:list<array{code:string,title:string,kind:string}>}
	 */
	public function definition(string $tplKey): array
	{
		if (isset(self::BUILTIN[$tplKey])) {
			return self::BUILTIN[$tplKey] + ['key' => $tplKey, 'source' => 'builtin'];
		}
		if (isset(self::PACKS[$tplKey])) {
			return self::PACKS[$tplKey] + ['key' => $tplKey, 'source' => 'pack'];
		}
		$row = $this->mapper->findByKey($tplKey);
		if ($row === null) {
			throw new NotFoundException();
		}
		$flags = json_decode((string)($row->getRuleFlags() ?? ''), true) ?: [];
		$skeleton = json_decode((string)($row->getSkeleton() ?? ''), true) ?: [];
		return [
			'key' => $tplKey,
			'name' => $row->getName(),
			'rule_flags' => $flags + self::BUILTIN[self::KEY_ANBEST_P]['rule_flags'],
			'skeleton' => $skeleton,
			'source' => $row->getSource(),
		];
	}

	/**
	 * @return list<array{key:string, name:string, source:string}>
	 */
	public function listTemplates(bool $pro): array
	{
		$out = [[
			'key' => self::KEY_ANBEST_P,
			'name' => self::BUILTIN[self::KEY_ANBEST_P]['name'],
			'source' => 'builtin',
		]];
		foreach ($this->mapper->findAll() as $row) {
			$out[] = ['key' => $row->getTplKey(), 'name' => $row->getName(), 'source' => $row->getSource()];
		}
		if ($pro) {
			$out[] = ['key' => 'festbetrag', 'name' => self::BUILTIN['festbetrag']['name'], 'source' => 'builtin'];
			foreach (self::PACKS as $key => $def) {
				$out[] = ['key' => $key, 'name' => $def['name'], 'source' => 'pack'];
			}
		}
		return $out;
	}

	/**
	 * Install an extra template pack (Pro). Definition is validated to a
	 * conservative shape — a pack can never inject unexpected flags.
	 */
	public function installPack(string $key, string $name, array $ruleFlags, array $skeleton, string $actor): Template
	{
		if (!preg_match('/^[a-z0-9][a-z0-9-]{1,62}$/', $key)) {
			throw new \OCA\NachweisCheck\Exception\ValidationException(['tpl_key' => 'Invalid template key.']);
		}
		if (isset(self::BUILTIN[$key])) {
			throw new \OCA\NachweisCheck\Exception\ValidationException(['tpl_key' => 'Built-in keys cannot be replaced.']);
		}
		$allowed = array_fill_keys(array_keys(self::BUILTIN[self::KEY_ANBEST_P]['rule_flags']), true);
		$flags = array_intersect_key($ruleFlags, $allowed);
		$cleanSkeleton = [];
		foreach ($skeleton as $p) {
			if (!isset($p['code'], $p['title'], $p['kind'])) {
				continue;
			}
			$cleanSkeleton[] = [
				'code' => substr((string)$p['code'], 0, 40),
				'title' => substr((string)$p['title'], 0, 200),
				'kind' => in_array($p['kind'], ['personal', 'sach', 'reise', 'extern', 'eigen', 'fremd'], true) ? $p['kind'] : 'sach',
			];
		}
		$row = $this->mapper->findByKey($key) ?? new Template();
		$row->setTplKey($key);
		$row->setName(substr($name, 0, 200));
		$row->setRuleFlags(json_encode($flags));
		$row->setSkeleton(json_encode($cleanSkeleton));
		$row->setSource('installed');
		if ($row->getId() === null) {
			$row->setCreatedAt(new \DateTime());
			return $this->mapper->insert($row);
		}
		return $this->mapper->update($row);
	}
}

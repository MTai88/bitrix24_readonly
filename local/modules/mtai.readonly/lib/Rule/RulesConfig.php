<?php

declare(strict_types=1);

namespace MTai\ReadOnly\Rule;

use Bitrix\Main\Web\Json;

/**
 * Разбор декларативных правил из настройки модуля (JSON).
 *
 * Формат — массив объектов:
 * [
 *   {
 *     "kind": "fieldEquals",           // тип правила
 *     "entityTypeIds": [2, 3, 4, 1038],// к каким сущностям применять (не задано — ко всем)
 *     "field": "UF_CRM_READONLY_DEMO", // имя поля (штатное или UF_*)
 *     "value": "Y"                     // ожидаемое значение
 *   },
 *   {
 *     "kind": "userGroup",
 *     "entityTypeIds": [2],
 *     "groupId": 9                     // ID группы пользователей
 *   }
 * ]
 */
final class RulesConfig
{
	/**
	 * @return list<array{rule: Rule, entityTypeIds: array<int, int>|null, title: string}>
	 */
	public static function parseFromOption(string $json): array
	{
		$json = trim($json);
		if ($json === '')
		{
			return [];
		}

		try
		{
			$list = Json::decode($json);
		}
		catch (\Throwable)
		{
			return [];
		}

		if (!is_array($list))
		{
			return [];
		}

		return self::parseFromArray($list);
	}

	/**
	 * @param array<int, mixed> $list
	 *
	 * @return list<array{rule: Rule, entityTypeIds: array<int, int>|null, title: string}>
	 */
	public static function parseFromArray(array $list): array
	{
		$result = [];
		foreach ($list as $row)
		{
			if (!is_array($row))
			{
				continue;
			}

			$entityTypeIds = null;
			if (isset($row['entityTypeIds']) && is_array($row['entityTypeIds']))
			{
				$entityTypeIds = array_values(array_map('intval', array_filter($row['entityTypeIds'], 'is_numeric')));
				if ($entityTypeIds === [])
				{
					$entityTypeIds = null;
				}
			}

			$rule = self::buildRule($row);
			if ($rule === null)
			{
				continue;
			}

			$result[] = [
				'rule' => $rule,
				'entityTypeIds' => $entityTypeIds,
				'title' => (string)($row['title'] ?? self::defaultTitle($row)),
			];
		}

		return $result;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private static function buildRule(array $row): ?Rule
	{
		$kind = (string)($row['kind'] ?? '');

		switch ($kind)
		{
			case 'fieldEquals':
				$field = (string)($row['field'] ?? '');
				if ($field === '')
				{
					return null;
				}

				return new FieldEqualsRule($field, $row['value'] ?? null);

			case 'userGroup':
				$groupId = (int)($row['groupId'] ?? 0);
				if ($groupId <= 0)
				{
					return null;
				}

				return new UserGroupRule($groupId);

			default:
				return null;
		}
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private static function defaultTitle(array $row): string
	{
		$kind = (string)($row['kind'] ?? '');

		if ($kind === 'fieldEquals')
		{
			return $kind . ': ' . (string)($row['field'] ?? '') . ' = ' . (string)($row['value'] ?? '');
		}

		if ($kind === 'userGroup')
		{
			return $kind . ': group ' . (string)($row['groupId'] ?? '');
		}

		return $kind;
	}
}

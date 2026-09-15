<?php

declare(strict_types=1);

namespace MTai\ReadOnly\Internals;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\Type\DateTime;

/**
 * Персистентные блокировки «только чтение».
 * USER_ID = NULL — блокировка действует для всех пользователей,
 * иначе — только для указанного.
 */
final class LockTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'mtai_readonly_lock';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary(true)
				->configureAutocomplete(true),

			(new IntegerField('ENTITY_TYPE_ID'))
				->configureRequired(true),

			(new IntegerField('ENTITY_ID'))
				->configureRequired(true),

			(new IntegerField('USER_ID'))
				->configureNullable(true),

			(new DatetimeField('CREATED_AT'))
				->configureRequired(true)
				->configureDefaultValue(static fn () => new DateTime()),
		];
	}
}

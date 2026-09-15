<?php

declare(strict_types=1);

namespace MTai\ReadOnly\Guard;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\EntityError;
use Bitrix\Main\ORM\Event as OrmEvent;
use Bitrix\Main\ORM\EventResult as OrmEventResult;
use Bitrix\Main\DI\ServiceLocator;
use MTai\ReadOnly\Manager;
use Throwable;

Loc::loadMessages(__FILE__);

/**
 * Жёсткий запрет сохранения и удаления на бэкенде для смарт-процессов.
 *
 * У динамических типов нет классических событий OnBeforeCrm*Update,
 * поэтому перехватываем ORM-события их таблиц b_crm_dynamic_items_<ID>
 * (runtime-сущности с именами crm_items_<ID типа>, см.
 * Bitrix\Main\UserField\Internal\TypeDataManager::compileEntity).
 *
 * Обработчики регистрируются в b_module_to_module с пустым FROM_MODULE_ID
 * (см. Manager::rebindDynamicTypeHandlers()).
 */
final class DynamicGuard
{
	/** @var array<int, int>|null кэш на запрос: ID типа (строка) => ENTITY_TYPE_ID */
	private static ?array $typeIdMap = null;

	/**
	 * Имена ORM-событий для типа смарт-процесса (ID строки в b_crm_dynamic_types).
	 *
	 * Внимание: ORM\Event без неймспейса формирует имя как getName() . $type —
	 * без разделителя '::' (см. Bitrix\Main\ORM\Event::__construct),
	 * поэтому события называются именно «crm_items_<ID>OnBeforeUpdate».
	 *
	 * @return array<string, string> eventName => метод-обработчик
	 */
	public static function eventNamesForType(int $typeRowId): array
	{
		$entityName = self::resolveEntityName($typeRowId) ?? ('crm_items_' . $typeRowId);

		return [
			$entityName . 'OnBeforeUpdate' => 'onBeforeUpdate',
			$entityName . 'OnBeforeDelete' => 'onBeforeDelete',
		];
	}

	/**
	 * Имя runtime-сущности берём у самого ядра — так регистрация и
	 * диспетчеризация гарантированно используют одну и ту же строку.
	 */
	private static function resolveEntityName(int $typeRowId): ?string
	{
		try
		{
			$typeFactory = ServiceLocator::getInstance()->get('crm.type.factory');
			if (is_object($typeFactory) && method_exists($typeFactory, 'getItemEntity'))
			{
				$entity = $typeFactory->getItemEntity($typeRowId);

				return $entity?->getName();
			}
		}
		catch (Throwable)
		{
		}

		return null;
	}

	public static function onBeforeUpdate(OrmEvent $event): OrmEventResult
	{
		return self::guard($event);
	}

	public static function onBeforeDelete(OrmEvent $event): OrmEventResult
	{
		return self::guard($event);
	}

	private static function guard(OrmEvent $event): OrmEventResult
	{
		$result = new OrmEventResult();

		$entityTypeId = self::resolveEntityTypeId($event);
		$entityId = (int)($event->getParameter('id') ?? 0);

		if (
			$entityTypeId !== null
			&& $entityId > 0
			&& Manager::getInstance()->isReadOnly($entityTypeId, $entityId)
		)
		{
			$result->addError(
				new EntityError(
					Loc::getMessage(
						'MTAI_READONLY_BLOCKED',
						['#ENTITY#' => \CCrmOwnerType::GetDescription($entityTypeId)],
					) ?? ''
				)
			);
		}

		return $result;
	}

	private static function resolveEntityTypeId(OrmEvent $event): ?int
	{
		$entityName = $event->getEntity()?->getName() ?? '';
		if (!preg_match('/(\d+)\s*$/', $entityName, $m))
		{
			return null;
		}

		$typeRowId = (int)$m[1];
		if ($typeRowId <= 0)
		{
			return null;
		}

		if (self::$typeIdMap === null)
		{
			self::$typeIdMap = [];
			$rows = \Bitrix\Crm\Model\Dynamic\TypeTable::getList([
				'select' => ['ID', 'ENTITY_TYPE_ID'],
			]);
			foreach ($rows as $row)
			{
				self::$typeIdMap[(int)$row['ID']] = (int)$row['ENTITY_TYPE_ID'];
			}
		}

		return self::$typeIdMap[$typeRowId] ?? null;
	}
}

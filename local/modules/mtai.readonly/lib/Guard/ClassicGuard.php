<?php

declare(strict_types=1);

namespace MTai\ReadOnly\Guard;

use Bitrix\Main\Localization\Loc;
use MTai\ReadOnly\Manager;

Loc::loadMessages(__FILE__);

/**
 * Жёсткий запрет сохранения и удаления на бэкенде для классических
 * сущностей CRM (сделки, контакты, компании).
 *
 * События OnBeforeCrm{Deal,Contact,Company}{Update,Delete} вызываются ядром
 * как из Factory-операций (современный путь: карточки, REST crm.*,
 * роботы, списки), так и из legacy-API (CCrmDeal::Update и т.п.).
 * Возврат false отменяет операцию, сообщение передаётся через
 * $fields['RESULT_MESSAGE'] / $APPLICATION->ThrowException().
 */
final class ClassicGuard
{
	public static function onBeforeDealUpdate(array &$fields)
	{
		return self::guardUpdate(\CCrmOwnerType::Deal, $fields);
	}

	public static function onBeforeContactUpdate(array &$fields)
	{
		return self::guardUpdate(\CCrmOwnerType::Contact, $fields);
	}

	public static function onBeforeCompanyUpdate(array &$fields)
	{
		return self::guardUpdate(\CCrmOwnerType::Company, $fields);
	}

	public static function onBeforeDealDelete($id)
	{
		return self::guardDelete(\CCrmOwnerType::Deal, (int)$id);
	}

	public static function onBeforeContactDelete($id)
	{
		return self::guardDelete(\CCrmOwnerType::Contact, (int)$id);
	}

	public static function onBeforeCompanyDelete($id)
	{
		return self::guardDelete(\CCrmOwnerType::Company, (int)$id);
	}

	private static function guardUpdate(int $entityTypeId, array &$fields)
	{
		$id = (int)($fields['ID'] ?? 0);
		if ($id > 0 && Manager::getInstance()->isReadOnly($entityTypeId, $id))
		{
			$fields['RESULT_MESSAGE'] = self::message($entityTypeId);

			return false;
		}

		return true;
	}

	private static function guardDelete(int $entityTypeId, int $id)
	{
		if ($id > 0 && Manager::getInstance()->isReadOnly($entityTypeId, $id))
		{
			global $APPLICATION;
			$APPLICATION->ThrowException(new \CApplicationException(self::message($entityTypeId)));

			return false;
		}

		return true;
	}

	private static function message(int $entityTypeId): string
	{
		return Loc::getMessage(
			'MTAI_READONLY_BLOCKED',
			['#ENTITY#' => \CCrmOwnerType::GetDescription($entityTypeId)],
		) ?? '';
	}
}

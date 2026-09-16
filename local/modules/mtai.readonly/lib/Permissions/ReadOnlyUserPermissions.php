<?php

declare(strict_types=1);

namespace MTai\ReadOnly\Permissions;

use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\Service\UserPermissions\EntityPermissions\Item;

/**
 * UserPermissions, у которого item() возвращает декоратор, дополнительно
 * запрещающий изменение заблокированных элементов.
 *
 * Регистрируется в ServiceLocator под тем же идентификатором, который
 * использует CRM Container::getUserPermissions() (см. Bootstrapper) —
 * тогда карточки, списки и операции CRM получают «суженные» права
 * нативно: карточка смарт-процесса открывается в режиме просмотра
 * (readOnly у редактора) ещё на сервере.
 */
final class ReadOnlyUserPermissions extends UserPermissions
{
	private ?ReadOnlyItemPermissions $itemPermissions = null;

	public function __construct(int $userId)
	{
		parent::__construct($userId);
	}

	public function item(): Item
	{
		if ($this->itemPermissions === null)
		{
			$this->itemPermissions = ReadOnlyItemPermissions::wrap(
				parent::item(),
				$this->getUserId(),
			);
		}

		return $this->itemPermissions;
	}
}

<?php

declare(strict_types=1);

namespace MTai\ReadOnly\Permissions;

use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\UserPermissions\EntityPermissions\Item;
use MTai\ReadOnly\Manager;
use ReflectionClass;

/**
 * Декоратор проверки прав на элементы CRM: дополнительно запрещает
 * изменение/удаление элементов, находящихся в режиме «только чтение».
 *
 * Контракт тот же, что у всего модуля: права можно только сузить —
 * каждый метод возвращает parent-результат И отсутствие блокировки,
 * т.е. false декоратор может вернуть чаще ядра, true — никогда.
 *
 * Создаётся без конструктора (обёртка над штатным экземпляром),
 * поэтому ВСЕ публичные методы Item явно перекрыты делегированием —
 * наследованные методы на нашем экземпляре работать не смогут.
 */
final class ReadOnlyItemPermissions extends Item
{
	private ?Item $inner = null;
	private ?int $userId = null;

	public static function wrap(Item $inner, ?int $userId = null): self
	{
		/** @var self $instance */
		$instance = (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
		$instance->inner = $inner;
		$instance->userId = $userId;

		return $instance;
	}

	/* ===================== изменение/удаление: сужение ==================== */

	public function canUpdate(int $entityTypeId, int $entityId): bool
	{
		return $this->inner->canUpdate($entityTypeId, $entityId)
			&& !$this->isReadOnly($entityTypeId, $entityId);
	}

	public function canDelete(int $entityTypeId, int $entityId): bool
	{
		return $this->inner->canDelete($entityTypeId, $entityId)
			&& !$this->isReadOnly($entityTypeId, $entityId);
	}

	public function canUpdateItemIdentifier(ItemIdentifier $itemIdentifier): bool
	{
		return $this->inner->canUpdateItemIdentifier($itemIdentifier)
			&& !$this->isReadOnly($itemIdentifier->getEntityTypeId(), $itemIdentifier->getEntityId());
	}

	public function canDeleteItemIdentifier(ItemIdentifier $itemIdentifier): bool
	{
		return $this->inner->canDeleteItemIdentifier($itemIdentifier)
			&& !$this->isReadOnly($itemIdentifier->getEntityTypeId(), $itemIdentifier->getEntityId());
	}

	public function canUpdateItem(\Bitrix\Crm\Item $item): bool
	{
		return $this->inner->canUpdateItem($item)
			&& !$this->isReadOnly($item->getEntityTypeId(), $item->getId());
	}

	public function canDeleteItem(\Bitrix\Crm\Item $item): bool
	{
		return $this->inner->canDeleteItem($item)
			&& !$this->isReadOnly($item->getEntityTypeId(), $item->getId());
	}

	public function canImportItem(\Bitrix\Crm\Item $item): bool
	{
		return $this->inner->canImportItem($item)
			&& $item->getId() > 0
			&& !$this->isReadOnly($item->getEntityTypeId(), $item->getId());
	}

	public function canChangeStage(ItemIdentifier $itemIdentifier, string $fromStageId, string $toStageId): bool
	{
		return $this->inner->canChangeStage($itemIdentifier, $fromStageId, $toStageId)
			&& !$this->isReadOnly($itemIdentifier->getEntityTypeId(), $itemIdentifier->getEntityId());
	}

	/* ================= остальное: прозрачно как в ядре ==================== */

	public function canRead(int $entityTypeId, int $entityId): bool
	{
		return $this->inner->canRead($entityTypeId, $entityId);
	}

	public function canReadItemIdentifier(ItemIdentifier $itemIdentifier): bool
	{
		return $this->inner->canReadItemIdentifier($itemIdentifier);
	}

	public function canReadItem(\Bitrix\Crm\Item $item): bool
	{
		return $this->inner->canReadItem($item);
	}

	public function canAddItem(\Bitrix\Crm\Item $item): bool
	{
		return $this->inner->canAddItem($item);
	}

	public function canAddOnlySelfAssignedItems(\Bitrix\Crm\Item $item): bool
	{
		return $this->inner->canAddOnlySelfAssignedItems($item);
	}

	public function canChangeStageToAny(int $entityTypeId): bool
	{
		return $this->inner->canChangeStageToAny($entityTypeId);
	}

	public function prepareItemPermissionAttributes(\Bitrix\Crm\Item $item): array
	{
		return $this->inner->prepareItemPermissionAttributes($item);
	}

	public function preloadPermissionAttributes(int $entityTypeId, array $ids): void
	{
		$this->inner->preloadPermissionAttributes($entityTypeId, $ids);
	}

	/* ====================================================================== */

	/**
	 * Проверка режима чтения для пользователя, чьи права мы декорируем
	 * (по умолчанию — текущий пользователь запроса).
	 */
	private function isReadOnly(int $entityTypeId, int $entityId): bool
	{
		if ($entityTypeId <= 0 || $entityId <= 0)
		{
			return false;
		}

		return Manager::getInstance()->isReadOnly($entityTypeId, $entityId, $this->userId);
	}
}

<?php

declare(strict_types=1);

namespace MTai\ReadOnly\Rule;

use Bitrix\Crm\Item;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Loader;
use Bitrix\Main\ObjectException;
use Throwable;

/**
 * Контекст проверки правила: какой элемент какой сущности проверяется
 * и для какого пользователя.
 */
final class Context
{
	private ?Item $item = null;
	private bool $itemLoaded = false;

	/** @var array<int, int>|null */
	private ?array $userGroups = null;

	public function __construct(
		public readonly int $entityTypeId,
		public readonly int $entityId,
		public readonly int $userId,
		public readonly bool $isAdmin,
	)
	{
	}

	/**
	 * Элемент CRM через Factory API (сделки, контакты, компании, смарт-процессы).
	 * Данные загружаются лениво и кэшируются на время запроса.
	 */
	public function getItem(): ?Item
	{
		if ($this->itemLoaded)
		{
			return $this->item;
		}

		$this->itemLoaded = true;

		if ($this->entityId <= 0 || !Loader::includeModule('crm'))
		{
			return null;
		}

		$factory = Container::getInstance()->getFactory($this->entityTypeId);
		if ($factory === null)
		{
			return null;
		}

		try
		{
			$this->item = $factory->getItem($this->entityId);
		}
		catch (Throwable)
		{
			return null;
		}

		return $this->item;
	}

	/**
	 * Значение поля элемента: штатное или пользовательское (UF_*).
	 */
	public function getFieldValue(string $fieldName): mixed
	{
		$item = $this->getItem();
		if ($item === null)
		{
			return null;
		}

		try
		{
			return $item->get($fieldName);
		}
		catch (ObjectException|Throwable)
		{
			return $item->getData()[$fieldName] ?? null;
		}
	}

	/**
	 * Группы пользователя (для правил типа «участникам группы X — только чтение»).
	 *
	 * @return array<int, int>
	 */
	public function getUserGroups(): array
	{
		if ($this->userGroups === null)
		{
			$this->userGroups = $this->userId > 0 ? \CUser::GetUserGroup($this->userId) : [];
		}

		return $this->userGroups;
	}
}

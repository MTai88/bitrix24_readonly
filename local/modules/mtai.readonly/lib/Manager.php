<?php

declare(strict_types=1);

namespace MTai\ReadOnly;

use Bitrix\Crm\Model\Dynamic\TypeTable;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\EventManager;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\Data\AddResult;
use Bitrix\Main\ORM\Entity;
use Bitrix\Main\Result;
use Bitrix\Main\Error;
use MTai\ReadOnly\Guard\DynamicGuard;
use MTai\ReadOnly\Internals\LockTable;
use MTai\ReadOnly\Rule\Context;
use MTai\ReadOnly\Rule\Rule;
use MTai\ReadOnly\Rule\RulesConfig;
use Throwable;

Loc::loadMessages(__FILE__);

/**
 * Сервис программного «режима только чтение» для элементов CRM
 * (сделки, контакты, компании, смарт-процессы).
 *
 * Механизм только СУЖАЕТ права: если штатные права запрещают редактирование —
 * они и так не пустят сохранение; если разрешают — правило/блокировка может
 * перевести элемент в режим чтения. Расширить права через этот сервис нельзя.
 *
 * Три источника режима чтения (объединяются по ИЛИ):
 *  1) персистентные блокировки lock()/unlock() — хранятся в БД;
 *  2) декларативные правила из настроек модуля (JSON) — fieldEquals / userGroup;
 *  3) runtime-правила registerRule() — любые callable, регистрируются кодом.
 */
final class Manager
{
	public const MODULE_ID = 'mtai.readonly';

	private const ADMIN_GROUP_ID = 1;

	private static ?self $instance = null;

	/** @var array<string, bool> кэш решений на запрос: "typeId:entityId:userId" => bool */
	private array $decisionCache = [];

	/** @var list<array{rule: Rule, entityTypeIds: array<int, int>|null}> */
	private array $runtimeRules = [];

	/** @var list<array{rule: Rule, entityTypeIds: array<int, int>|null, title: string}>|null */
	private ?array $configRules = null;

	/** @var array<string, list<int|null>> кэш блокировок на запрос: "typeId:entityId" => USER_ID (null = все) */
	private array $locksIndex = [];

	/** @var array<int, bool> кэш признака «администратор» на запрос */
	private array $adminCache = [];

	public static function getInstance(): self
	{
		return self::$instance ??= new self();
	}

	private function __construct()
	{
	}

	/* =====================================================================
	 *  Публичный API: программные блокировки (персистентные)
	 * ==================================================================== */

	/**
	 * Перевести элемент в режим только чтение.
	 * $userId = null — для всех пользователей, иначе — только для указанного.
	 */
	public function lock(int $entityTypeId, int $entityId, ?int $userId = null): AddResult
	{
		$result = new AddResult();

		if ($entityTypeId <= 0 || $entityId <= 0)
		{
			return $result->addError(new Error('Invalid entity: ' . $entityTypeId . ':' . $entityId));
		}

		if (\CCrmOwnerType::isUseDynamicTypeBasedApproach($entityTypeId))
		{
			// у нового смарт-типа мог ещё не появиться наш ORM-обработчик
			$this->ensureDynamicTypeHandlers();
		}

		$existing = LockTable::getList([
			'filter' => [
				'=ENTITY_TYPE_ID' => $entityTypeId,
				'=ENTITY_ID' => $entityId,
				'=USER_ID' => $userId,
			],
			'limit' => 1,
		])->fetch();

		if (!$existing)
		{
			$add = LockTable::add([
				'ENTITY_TYPE_ID' => $entityTypeId,
				'ENTITY_ID' => $entityId,
				'USER_ID' => $userId,
			]);
			if (!$add->isSuccess())
			{
				return $result->addErrors($add->getErrors());
			}

			$result->setId($add->getId());
		}
		else
		{
			$result->setId((int)$existing['ID']);
		}

		$this->locksIndex = [];
		$this->decisionCache = [];

		return $result;
	}

	/**
	 * Снять конкретную блокировку (точное совпадение по пользователю:
	 * unlock(..., null) снимает общую блокировку, unlock(..., 5) — персональную).
	 */
	public function unlock(int $entityTypeId, int $entityId, ?int $userId = null): Result
	{
		$this->deleteLocks($entityTypeId, $entityId, $userId);

		$this->locksIndex = [];
		$this->decisionCache = [];

		return (new Result());
	}

	/**
	 * Снять все блокировки с элемента (и общую, и персональные).
	 */
	public function unlockAll(int $entityTypeId, int $entityId): Result
	{
		$this->deleteLocks($entityTypeId, $entityId, null, true);

		$this->locksIndex = [];
		$this->decisionCache = [];

		return (new Result());
	}

	/**
	 * Есть ли персистентная блокировка (без учёта правил) для пользователя.
	 */
	public function isLocked(int $entityTypeId, int $entityId, ?int $userId = null): bool
	{
		$userId ??= self::currentUserId();
		foreach ($this->loadLocks($entityTypeId, $entityId) as $lockedUserId)
		{
			if ($lockedUserId === null || (int)$lockedUserId === $userId)
			{
				return true;
			}
		}

		return false;
	}

	/* =====================================================================
	 *  Публичный API: runtime-правила (любая логика в коде)
	 * ==================================================================== */

	/**
	 * Зарегистрировать правило на время запроса (обычно — из init.php,
	 * сервиса или своего обработчика событий). $entityTypeIds — фильтр по
	 * типам сущностей, null = применять ко всем.
	 *
	 * @param array<int, int>|null $entityTypeIds
	 */
	public function registerRule(Rule $rule, ?array $entityTypeIds = null): void
	{
		$this->runtimeRules[] = [
			'rule' => $rule,
			'entityTypeIds' => $entityTypeIds !== null ? array_values($entityTypeIds) : null,
		];
		$this->decisionCache = [];
	}

	/**
	 * @return list<array{rule: Rule, entityTypeIds: array<int, int>|null}>
	 */
	public function getRuntimeRules(): array
	{
		return $this->runtimeRules;
	}

	public function clearRuntimeRules(): void
	{
		$this->runtimeRules = [];
		$this->decisionCache = [];
	}

	/* =====================================================================
	 *  Главный вопрос: находится ли элемент в режиме только чтение
	 * ==================================================================== */

	/**
	 * Элемент в режиме только чтение для пользователя $userId
	 * (по умолчанию — для текущего)?
	 */
	public function isReadOnly(int $entityTypeId, int $entityId, ?int $userId = null): bool
	{
		$userId ??= self::currentUserId();

		$cacheKey = $entityTypeId . ':' . $entityId . ':' . $userId;
		if (array_key_exists($cacheKey, $this->decisionCache))
		{
			return $this->decisionCache[$cacheKey];
		}

		$decision = false;

		if ($this->isEnabled() && $this->appliesToUser($userId))
		{
			$context = new Context(
				$entityTypeId,
				$entityId,
				$userId,
				$this->isAdmin($userId),
			);

			$decision = $this->isLocked($entityTypeId, $entityId, $userId)
				|| $this->matchesRules($context);
		}

		return $this->decisionCache[$cacheKey] = $decision;
	}

	public function isReadOnlyForCurrentUser(int $entityTypeId, int $entityId): bool
	{
		return $this->isReadOnly($entityTypeId, $entityId, self::currentUserId());
	}

	/* =====================================================================
	 *  Обработчики смарт-процессов (ORM-события вида crm_items_<ID типа>)
	 * ==================================================================== */

	/**
	 * (Пере)регистрирует ORM-обработчики сохранения/удаления для всех
	 * существующих смарт-процессов. Вызывается при установке модуля,
	 * при lock() смарт-элемента и при открытии карточки смарт-процесса.
	 */
	public function rebindDynamicTypeHandlers(): int
	{
		if (!Loader::includeModule('crm'))
		{
			return 0;
		}

		$em = EventManager::getInstance();
		$count = 0;

		$rows = TypeTable::getList(['select' => ['ID']]);

		while ($row = $rows->fetch())
		{
			foreach (DynamicGuard::eventNamesForType((int)$row['ID']) as $eventName => $method)
			{
				$em->registerEventHandler('', $eventName, self::MODULE_ID, DynamicGuard::class, $method, 100);
			}
			$count++;
		}

		return $count;
	}

	/**
	 * Ленивая сверка: для всех ли смарт-типов зарегистрированы обработчики.
	 */
	public function ensureDynamicTypeHandlers(): void
	{
		if (!Loader::includeModule('crm'))
		{
			return;
		}

		$typesCount = (int)TypeTable::getCount();
		if ($typesCount <= 0)
		{
			return;
		}

		$registered = (int)Application::getConnection()->query(
			"SELECT COUNT(DISTINCT MESSAGE_ID) AS CNT"
			. " FROM b_module_to_module"
			. " WHERE TO_MODULE_ID = '" . self::MODULE_ID . "' AND FROM_MODULE_ID = ''"
			. " AND MESSAGE_ID LIKE 'crm\\_items%'"
		)->fetch()['CNT'];

		// на каждый тип — по два обработчика (update и delete)
		if ($registered < $typesCount * 2)
		{
			$this->rebindDynamicTypeHandlers();
		}
	}

	/* =====================================================================
	 *  Внутреннее
	 * ==================================================================== */

	private function matchesRules(Context $context): bool
	{
		$ruleSets = array_merge($this->getConfigRules(), $this->runtimeRules);

		foreach ($ruleSets as $ruleSet)
		{
			$entityTypeIds = $ruleSet['entityTypeIds'] ?? null;
			if (
				$entityTypeIds !== null
				&& !in_array($context->entityTypeId, $entityTypeIds, true)
			)
			{
				continue;
			}

			if ($ruleSet['rule']->matches($context))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @return list<array{rule: Rule, entityTypeIds: array<int, int>|null, title: string}>
	 */
	private function getConfigRules(): array
	{
		if ($this->configRules === null)
		{
			$json = (string)Option::get(self::MODULE_ID, 'rules', '');
			$this->configRules = RulesConfig::parseFromOption($json);
		}

		return $this->configRules;
	}

	private function isEnabled(): bool
	{
		return Option::get(self::MODULE_ID, 'enabled', 'Y') !== 'N';
	}

	private function appliesToUser(int $userId): bool
	{
		if (Option::get(self::MODULE_ID, 'apply_to_admins', 'N') === 'Y')
		{
			return true;
		}

		// по умолчанию администраторов не блокируем — чтобы портал
		// всегда можно было «разблокировать изнутри»
		return !$this->isAdmin($userId);
	}

	private function isAdmin(int $userId): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		if (!array_key_exists($userId, $this->adminCache))
		{
			// GetUserGroup возвращает ID из БД строками — приводим к int
			$groupIds = array_map('intval', \CUser::GetUserGroup($userId));
			$this->adminCache[$userId] = in_array(self::ADMIN_GROUP_ID, $groupIds, true);
		}

		return $this->adminCache[$userId];
	}

	public static function currentUserId(): int
	{
		global $USER;

		if (is_object($USER) && $USER->IsAuthorized())
		{
			return (int)$USER->GetID();
		}

		return 0;
	}

	/**
	 * @return list<int|null>
	 */
	private function loadLocks(int $entityTypeId, int $entityId): array
	{
		$key = $this->lockKey($entityTypeId, $entityId);
		if (!array_key_exists($key, $this->locksIndex))
		{
			$userIds = [];
			$rows = LockTable::getList([
				'filter' => [
					'=ENTITY_TYPE_ID' => $entityTypeId,
					'=ENTITY_ID' => $entityId,
				],
			]);
			foreach ($rows as $row)
			{
				$userIds[] = $row['USER_ID'] !== null ? (int)$row['USER_ID'] : null;
			}

			$this->locksIndex[$key] = $userIds;
		}

		return $this->locksIndex[$key];
	}

	private function lockKey(int $entityTypeId, int $entityId): string
	{
		return $entityTypeId . ':' . $entityId;
	}

	/**
	 * Удаление строк блокировок (=all: все строки элемента, иначе точное совпадение по пользователю).
	 */
	private function deleteLocks(int $entityTypeId, int $entityId, ?int $userId = null, bool $all = false): void
	{
		$rows = LockTable::getList([
			'filter' => [
				'=ENTITY_TYPE_ID' => $entityTypeId,
				'=ENTITY_ID' => $entityId,
			],
			'select' => ['ID', 'USER_ID'],
		]);
		foreach ($rows as $row)
		{
			$rowUserId = $row['USER_ID'] !== null ? (int)$row['USER_ID'] : null;
			if ($all || $rowUserId === $userId)
			{
				LockTable::delete($row['ID']);
			}
		}
	}
}

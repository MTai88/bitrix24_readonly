<?php

declare(strict_types=1);

namespace MTai\ReadOnly;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use MTai\ReadOnly\Permissions\ReadOnlyUserPermissions;
use Throwable;

/**
 * Серверная интеграция с системой прав CRM.
 *
 * OnProlog выполняется после авторизации пользователя, но до запуска
 * компонентов — идеальный момент, чтобы зарегистрировать в ServiceLocator
 * свой UserPermissions для текущего пользователя. CRM (Container::
 * getUserPermissions) проверяет ServiceLocator до создания штатного
 * объекта, поэтому все карточки/списки/операции получают декоратор,
 * который «сужает» права на заблокированные элементы. Для смарт-процессов
 * это даёт нативный read-only карточки ещё на сервере (редактор
 * создаётся с readOnly: true, кнопки редактирования не отрисовываются).
 *
 * Если пользователь не авторизован или CRM ещё не понадобится —
 * ничего не делаем (остаются гварды событий как страховка).
 */
final class Bootstrapper
{
	/** URL, на которых имеет смысл подключать декоратор прав (страницы и AJAX CRM) */
	private const URI_GATE_REGEX = '~/crm|ajax\.php~';

	public static function onProlog(): void
	{
		try
		{
			$userId = Manager::currentUserId();
			if ($userId <= 0)
			{
				return;
			}

			$uri = (string)($_SERVER['REQUEST_URI'] ?? '');
			if ($uri !== '' && !preg_match(self::URI_GATE_REGEX, $uri))
			{
				return;
			}

			if (!Loader::includeModule('crm'))
			{
				return;
			}

			self::registerPermissions($userId);
		}
		catch (Throwable)
		{
			// сбой интеграции прав не должен ломать страницу — страховкой остаются гварды
		}
	}

	/**
	 * Регистрирует декоратор прав пользователя в ServiceLocator.
	 * Повторные вызовы безопасны: существующая регистрация не трогается.
	 */
	public static function registerPermissions(int $userId): void
	{
		$identifier = Container::getIdentifierByClassName(UserPermissions::class, [$userId]);

		$locator = ServiceLocator::getInstance();
		if ($locator->has($identifier))
		{
			return;
		}

		$locator->addInstance($identifier, new ReadOnlyUserPermissions($userId));
	}

	/**
	 * Активен ли декоратор прав для пользователя (для решений уровня UI:
	 * если активен — сервер уже отдаёт карточки в read-only, JS не нужен).
	 */
	public static function isPermissionsDecoratorActive(?int $userId = null): bool
	{
		try
		{
			$userId ??= Manager::currentUserId();
			if ($userId <= 0 || !Loader::includeModule('crm'))
			{
				return false;
			}

			$identifier = Container::getIdentifierByClassName(UserPermissions::class, [$userId]);

			$locator = ServiceLocator::getInstance();

			return $locator->has($identifier)
				&& $locator->get($identifier) instanceof ReadOnlyUserPermissions;
		}
		catch (Throwable)
		{
			return false;
		}
	}
}

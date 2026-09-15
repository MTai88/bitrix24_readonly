<?php

declare(strict_types=1);

namespace MTai\ReadOnly\Rule;

/**
 * Условие — произвольный callback: fn(Context $context): bool.
 * Внутри доступно всё: параметры сессии, дата/время, внешние сервисы.
 *
 * Пример:
 *   MTai\ReadOnly\Manager::getInstance()->registerRule(
 *       new CallbackRule(fn(Context $c) => $c->entityTypeId === \CCrmOwnerType::Deal
 *           && isset($_SESSION['DEMO_RO']) && $_SESSION['DEMO_RO'] === 'Y')
 *   );
 */
final class CallbackRule implements Rule
{
	/** @var callable(Context): bool */
	private $callback;

	public function __construct(callable $callback)
	{
		$this->callback = $callback;
	}

	public function matches(Context $context): bool
	{
		try
		{
			return (bool)call_user_func($this->callback, $context);
		}
		catch (\Throwable)
		{
			// ошибка внутри клиентского кода не должна ломать сохранение
			return false;
		}
	}
}

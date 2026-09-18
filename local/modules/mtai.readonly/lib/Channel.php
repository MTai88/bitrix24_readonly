<?php

declare(strict_types=1);

namespace MTai\ReadOnly;

use Bitrix\Main\Application;
use Throwable;

/**
 * Канал, из которого выполняется действие над сущностью.
 *
 * UI    — интерактивный веб-интерфейс CRM: страницы /crm* (карточки,
 *         канбан, списки, слайдеры) и AJAX-контроллеры с действиями
 *         crm.* (ими сохраняет интерфейс);
 * CODE  — всё остальное: CLI-скрипты, агенты, крон, REST/вебхуки,
 *         собственные страницы и AJAX-обработчики.
 *
 * По умолчанию модуль блокирует только канал UI; код продолжает обновлять
 * сущности (опция block_api = Y возвращает строгий режим «везде»).
 */
final class Channel
{
	public const TYPE_UI = 'ui';
	public const TYPE_CODE = 'code';

	private static ?string $forced = null;

	/**
	 * Текущий канал запроса (для тестов и спец-сценариев можно принудительно
	 * задать через force()).
	 */
	public static function current(): string
	{
		if (self::$forced !== null)
		{
			return self::$forced;
		}

		if (PHP_SAPI === 'cli')
		{
			return self::TYPE_CODE;
		}

		try
		{
			$request = Application::getInstance()->getContext()->getRequest();
			$action = (string)($request->getPost('action') ?? $request->getQuery('action') ?? '');

			return self::resolveForUri(
				(string)(parse_url((string)$request->getRequestUri(), PHP_URL_PATH) ?: ''),
				$action,
			);
		}
		catch (Throwable)
		{
			return self::TYPE_UI;
		}
	}

	/**
	 * Чистое определение канала по URI и action (удобно для тестов).
	 *
	 * UI: страницы CRM (/crm…) и AJAX-запросы действий crm.* (карточки,
	 * канбан, списки сохраняются именно через них).
	 * CODE: REST, собственные страницы и AJAX-обработчики без crm-действия.
	 */
	public static function resolveForUri(string $uri, string $action = ''): string
	{
		$uri = strtolower(trim($uri));

		if ($uri === '')
		{
			return self::TYPE_UI; // безопасное значение по умолчанию
		}

		if (str_starts_with($uri, '/rest/') || str_starts_with($uri, '/bitrix/services/rest'))
		{
			return self::TYPE_CODE;
		}

		if (str_contains($uri, 'ajax.php'))
		{
			// AJAX-контроллер: блокируем только действия CRM (ими сохраняет UI),
			// прочие действия считаем программным доступом
			return $action === '' || str_starts_with(strtolower($action), 'crm')
				? self::TYPE_UI
				: self::TYPE_CODE;
		}

		return str_contains($uri, '/crm')
			? self::TYPE_UI
			: self::TYPE_CODE;
	}

	public static function isCode(): bool
	{
		return self::current() === self::TYPE_CODE;
	}

	public static function isUi(): bool
	{
		return self::current() === self::TYPE_UI;
	}

	/**
	 * Принудительно считать канал указанным (null — вернуть автоопределение).
	 * Используется тестами и кодом, которому нужно эмулировать канал.
	 */
	public static function force(?string $type): void
	{
		self::$forced = ($type === self::TYPE_CODE || $type === self::TYPE_UI) ? $type : null;
	}
}

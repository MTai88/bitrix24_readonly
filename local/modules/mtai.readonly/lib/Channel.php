<?php

declare(strict_types=1);

namespace MTai\ReadOnly;

use Bitrix\Main\Application;
use Throwable;

/**
 * Канал, из которого выполняется действие над сущностью.
 *
 * UI    — браузерный веб-интерфейс (страницы CRM и AJAX-контроллеры,
 *         через которые сохраняет карточка/канбан/списки);
 * CODE  — программный доступ: CLI-скрипты, агенты, крон, REST и вебхуки.
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
			$uri = (string)(parse_url(
				(string)Application::getInstance()->getContext()->getRequest()->getRequestUri(),
				PHP_URL_PATH,
			) ?: '');

			if (
				$uri !== ''
				&& (str_starts_with($uri, '/rest/') || str_starts_with($uri, '/bitrix/services/rest'))
			)
			{
				return self::TYPE_CODE;
			}
		}
		catch (Throwable)
		{
		}

		return self::TYPE_UI;
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

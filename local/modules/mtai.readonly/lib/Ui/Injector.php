<?php

declare(strict_types=1);

namespace MTai\ReadOnly\Ui;

use Bitrix\Crm\Service\Container;
use Bitrix\Main\Application;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Loader;
use MTai\ReadOnly\Manager;
use Throwable;

Loc::loadMessages(__FILE__);

/**
 * UI-часть: карточка деталей открывается в режиме просмотра.
 *
 * Сделки/контакты/компании: ядро поддерживает запрос-параметр
 * FORCE_READONLY=Y (initializeReadOnly() в компонентах карточек) —
 * инжектим скрипт, который один раз перезагружает страницу с этим
 * параметром, и карточка нативно рендерится «только для чтения».
 *
 * Смарт-процессы: параметра нет, поэтому best-effort переводим редактор
 * карточки в режим просмотра клиентски. Жёсткая гарантия в любом случае
 * остаётся на бэкенде (Guard\*).
 */
final class Injector
{
	private const CLASSIC_URL_REGEX = '~^/crm/(deal|contact|company)/details/(\d+)/?$~';
	private const SMART_URL_REGEX = '~^/crm/type/(\d+)/details/(\d+)/?$~';

	private const CLASSIC_TYPE_MAP = [
		'deal' => \CCrmOwnerType::Deal,
		'contact' => \CCrmOwnerType::Contact,
		'company' => \CCrmOwnerType::Company,
	];

	public static function onEndBufferContent(&$content): void
	{
		try
		{
			self::inject($content);
		}
		catch (Throwable)
		{
			// UI-надстройка не должна ломать страницу
		}
	}

	private static function inject(&$content): void
	{
		if (!is_string($content) || $content === '' || !str_contains($content, '</body>'))
		{
			return;
		}

		if (!Loader::includeModule('crm'))
		{
			return;
		}

		$request = Application::getInstance()->getContext()->getRequest();
		if ($request->isPost() || $request->isAjaxRequest())
		{
			return;
		}

		if ($request->get('FORCE_READONLY') !== null)
		{
			return;
		}

		$path = (string)(parse_url((string)$request->getRequestUri(), PHP_URL_PATH) ?: '');

		$forceParamSupported = false;
		$entityTypeId = 0;
		$entityId = 0;

		if (preg_match(self::CLASSIC_URL_REGEX, $path, $m))
		{
			$entityTypeId = self::CLASSIC_TYPE_MAP[strtolower($m[1])] ?? 0;
			$entityId = (int)$m[2];
			$forceParamSupported = true;
		}
		elseif (preg_match(self::SMART_URL_REGEX, $path, $m))
		{
			$entityTypeId = (int)$m[1];
			$entityId = (int)$m[2];
			// попутно сверяем обработчики: смарт-тип могли создать после установки
			Manager::getInstance()->ensureDynamicTypeHandlers();
		}

		if ($entityTypeId <= 0 || $entityId <= 0)
		{
			return;
		}

		if (!Container::getInstance()->getFactory($entityTypeId))
		{
			return;
		}

		$manager = Manager::getInstance();
		if (!$manager->isReadOnlyForCurrentUser($entityTypeId, $entityId))
		{
			return;
		}

		// права уже сужены на сервере (Bootstrapper) — карточка нативно
		// рендерится в режиме просмотра, JS-надстройка не нужна
		if (\MTai\ReadOnly\Bootstrapper::isPermissionsDecoratorActive())
		{
			return;
		}

		$script = $forceParamSupported ? self::reloadWithForceScript() : self::viewModeScript();

		$content = preg_replace(
			'~</body>~i',
			$script . '</body>',
			$content,
			1,
		) ?? $content . $script;
	}

	/**
	 * Классические карточки: одна перезагрузка с FORCE_READONLY=Y —
	 * дальше ядро само рендерит карточку в режиме просмотра.
	 */
	private static function reloadWithForceScript(): string
	{
		return '<script data-mtai-readonly="1">(function(){'
			. 'if(window.MTAI_READONLY_APPLIED)return;window.MTAI_READONLY_APPLIED=true;'
			. 'try{var u=new URL(window.location.href);'
			. 'if(u.searchParams.get("FORCE_READONLY")!=="Y"){'
			. 'u.searchParams.set("FORCE_READONLY","Y");window.location.replace(u.href);}}catch(e){}'
			. '})();</script>';
	}

	/**
	 * Смарт-процессы: переводим редактор карточки в режим просмотра
	 * и возвращаем в него при попытке входа в редактирование.
	 */
	private static function viewModeScript(): string
	{
		return '<script data-mtai-readonly="1">(function(){'
			. 'function apply(e){try{if(e&&e.switchToViewMode){setTimeout(function(){try{e.switchToViewMode();}catch(x){}},0);}}catch(x){}}'
			. 'function onInit(e){apply(e);}'
			. 'function onSwitchToEdit(e){apply(e);}'
			. 'function init(){try{if(window.BX&&BX.addCustomEvent){'
			. 'BX.addCustomEvent("BX.UI.EntityEditor:onInit",onInit);'
			. 'BX.addCustomEvent("BX.Crm.EntityEditor:onInit",onInit);'
			. 'BX.addCustomEvent("BX.UI.EntityEditor:onSwitchToEditMode",onSwitchToEdit);'
			. 'BX.addCustomEvent("BX.Crm.EntityEditor:onSwitchToEditMode",onSwitchToEdit);'
			. '}}catch(x){}}'
			. 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",init);}else{init();}'
			. '})();</script>';
	}
}

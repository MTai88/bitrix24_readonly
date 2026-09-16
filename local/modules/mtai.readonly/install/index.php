<?php

use Bitrix\Main\Application;
use Bitrix\Main\EventManager;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\ORM\Entity;
use MTai\ReadOnly\Bootstrapper;
use MTai\ReadOnly\Guard\ClassicGuard;
use MTai\ReadOnly\Guard\DynamicGuard;
use MTai\ReadOnly\Internals\LockTable;
use MTai\ReadOnly\Manager;
use MTai\ReadOnly\Ui\Injector;

Loc::loadMessages(__FILE__);

class MTai_ReadOnly extends CModule
{
	public $MODULE_ID = 'mtai.readonly';
	public $MODULE_VERSION;
	public $MODULE_VERSION_DATE;
	public $MODULE_NAME;
	public $MODULE_DESCRIPTION;
	public $PARTNER_NAME = 'MTai';
	public $PARTNER_URI = 'https://github.com/MTai88/bitrix24_readonly';
	public $MODULE_GROUP_RIGHTS = 'N';

	/** @var array<int, array{0: string, 1: string}> событие => метод ClassicGuard */
	private const CLASSIC_EVENTS = [
		['OnBeforeCrmDealUpdate', 'onBeforeDealUpdate'],
		['OnBeforeCrmDealDelete', 'onBeforeDealDelete'],
		['OnBeforeCrmContactUpdate', 'onBeforeContactUpdate'],
		['OnBeforeCrmContactDelete', 'onBeforeContactDelete'],
		['OnBeforeCrmCompanyUpdate', 'onBeforeCompanyUpdate'],
		['OnBeforeCrmCompanyDelete', 'onBeforeCompanyDelete'],
	];

	public function __construct()
	{
		$arModuleVersion = [];
		include __DIR__ . '/version.php';

		$this->MODULE_VERSION = $arModuleVersion['VERSION'];
		$this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'];
		$this->MODULE_NAME = Loc::getMessage('MTAI_RO_MODULE_NAME');
		$this->MODULE_DESCRIPTION = Loc::getMessage('MTAI_RO_MODULE_DESCRIPTION');
	}

	public function DoInstall()
	{
		global $APPLICATION;

		if (!ModuleManager::isModuleInstalled('crm') || !\Bitrix\Main\Loader::includeModule('crm'))
		{
			$APPLICATION->ThrowException(Loc::getMessage('MTAI_RO_INSTALL_CRM_REQUIRED'));

			return false;
		}

		ModuleManager::registerModule($this->MODULE_ID);
		$this->InstallDB();
		$this->InstallEvents();

		return true;
	}

	public function DoUninstall()
	{
		$this->UnInstallEvents();
		$this->UnInstallDB();
		\Bitrix\Main\Config\Option::delete($this->MODULE_ID);
		ModuleManager::unRegisterModule($this->MODULE_ID);

		return true;
	}

	public function InstallDB()
	{
		// подключаем модуль, чтобы заработал автозагрузчик lib/
		\Bitrix\Main\Loader::includeModule($this->MODULE_ID);

		$connection = Application::getConnection();
		if (!$connection->isTableExists(LockTable::getTableName()))
		{
			Entity::getInstance(LockTable::class)->createDbTable();
		}

		return true;
	}

	public function UnInstallDB()
	{
		// модуль ещё зарегистрирован — подключаем, чтобы работал автозагрузчик lib/
		\Bitrix\Main\Loader::includeModule($this->MODULE_ID);

		$connection = Application::getConnection();
		if ($connection->isTableExists(LockTable::getTableName()))
		{
			$connection->dropTable(LockTable::getTableName());
		}

		return true;
	}

	public function InstallEvents()
	{
		$em = EventManager::getInstance();

		foreach (self::CLASSIC_EVENTS as [$event, $method])
		{
			$em->registerEventHandlerCompatible('crm', $event, $this->MODULE_ID, ClassicGuard::class, $method);
		}

		$em->registerEventHandlerCompatible(
			'main',
			'OnProlog',
			$this->MODULE_ID,
			Bootstrapper::class,
			'onProlog',
		);

		$em->registerEventHandlerCompatible(
			'main',
			'OnEndBufferContent',
			$this->MODULE_ID,
			Injector::class,
			'onEndBufferContent',
		);

		// ORM-события смарт-процессов (FROM_MODULE_ID = '')
		Manager::getInstance()->rebindDynamicTypeHandlers();

		return true;
	}

	public function UnInstallEvents()
	{
		$em = EventManager::getInstance();

		foreach (self::CLASSIC_EVENTS as [$event, $method])
		{
			$em->unRegisterEventHandler('crm', $event, $this->MODULE_ID, ClassicGuard::class, $method);
		}

		$em->unRegisterEventHandler(
			'main',
			'OnProlog',
			$this->MODULE_ID,
			Bootstrapper::class,
			'onProlog',
		);

		$em->unRegisterEventHandler(
			'main',
			'OnEndBufferContent',
			$this->MODULE_ID,
			Injector::class,
			'onEndBufferContent',
		);

		// все обработчики смарт-процессов зарегистрированы с пустым FROM_MODULE_ID
		Application::getConnection()->queryExecute(
			"DELETE FROM b_module_to_module"
			. " WHERE TO_MODULE_ID = '" . $this->MODULE_ID . "' AND FROM_MODULE_ID = ''"
		);

		return true;
	}
}

<?php

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Localization\Loc;
use MTai\ReadOnly\Manager;

/**
 * @global CMain $APPLICATION
 * @global CUser $USER
 */

$module_id = 'mtai.readonly';
Loc::loadMessages(__FILE__);

if (!$USER->IsAdmin())
{
	return;
}

\Bitrix\Main\Loader::includeModule($module_id);

$aTabs = [
	[
		'DIV' => 'edit1',
		'TAB' => Loc::getMessage('MTAI_RO_OPTIONS_TAB'),
		'TITLE' => Loc::getMessage('MTAI_RO_OPTIONS_TAB_TITLE'),
	],
];
$tabControl = new CAdminTabControl('tabControl', $aTabs);

if (
	$_SERVER['REQUEST_METHOD'] === 'POST'
	&& check_bitrix_sessid()
	&& (isset($_POST['Update']) || isset($_POST['rebind']))
) {
	if (isset($_POST['Update']))
	{
		Option::set($module_id, 'enabled', isset($_POST['enabled']) ? 'Y' : 'N');
		Option::set($module_id, 'apply_to_admins', isset($_POST['apply_to_admins']) ? 'Y' : 'N');
		Option::set($module_id, 'rules', (string)($_POST['rules'] ?? ''));
	}

	if (isset($_POST['rebind']))
	{
		Manager::getInstance()->rebindDynamicTypeHandlers();
	}

	LocalRedirect(
		$APPLICATION->GetCurPage()
		. '?mid=' . urlencode($module_id)
		. '&lang=' . LANGUAGE_ID
		. '&' . $tabControl->ActiveTabParam()
	);
}

$rulesValue = (string)Option::get($module_id, 'rules', '');
$tabControl->Begin();
?>
<form method="post" action="<?= $APPLICATION->GetCurPage() ?>?mid=<?= urlencode($module_id) ?>&lang=<?= LANGUAGE_ID ?>">
	<?= bitrix_sessid_post() ?>
	<?php $tabControl->BeginNextTab(); ?>
	<tr>
		<td width="40%"><?= Loc::getMessage('MTAI_RO_OPTIONS_ENABLED') ?></td>
		<td width="60%">
			<input type="checkbox" name="enabled" value="Y" <?= Option::get($module_id, 'enabled', 'Y') !== 'N' ? 'checked' : '' ?>>
		</td>
	</tr>
	<tr>
		<td width="40%"><?= Loc::getMessage('MTAI_RO_OPTIONS_ADMINS') ?></td>
		<td width="60%">
			<input type="checkbox" name="apply_to_admins" value="Y" <?= Option::get($module_id, 'apply_to_admins', 'N') === 'Y' ? 'checked' : '' ?>>
			<div style="margin-top:6px;color:#666;"><?= Loc::getMessage('MTAI_RO_OPTIONS_ADMINS_HINT') ?></div>
		</td>
	</tr>
	<tr>
		<td width="40%" style="vertical-align:top;padding-top:10px;">
			<?= Loc::getMessage('MTAI_RO_OPTIONS_RULES') ?>
		</td>
		<td width="60%">
			<textarea name="rules" rows="12" cols="70" style="width:100%;font-family:monospace;"><?= htmlspecialcharsbx($rulesValue) ?></textarea>
			<div style="margin-top:6px;color:#666;"><?= Loc::getMessage('MTAI_RO_OPTIONS_RULES_HINT') ?></div>
		</td>
	</tr>
	<?php $tabControl->Buttons(); ?>
	<input type="submit" name="Update" value="<?= Loc::getMessage('MTAI_RO_OPTIONS_SAVE') ?>" class="adm-btn-save">
	<input type="submit" name="rebind" value="<?= Loc::getMessage('MTAI_RO_OPTIONS_REBIND') ?>"
		onclick="return confirm('<?= \CUtil::JSEscape(Loc::getMessage('MTAI_RO_OPTIONS_REBIND_CONFIRM')) ?>');">
	<?php $tabControl->End(); ?>
</form>

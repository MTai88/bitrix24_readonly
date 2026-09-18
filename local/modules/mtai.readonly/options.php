<?php

use Bitrix\Crm\Model\Dynamic\TypeTable;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Loader;
use Bitrix\Main\Web\Json;
use MTai\ReadOnly\Manager;
use MTai\ReadOnly\Rule\RulesConfig;

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

Loader::includeModule($module_id);
Loader::includeModule('crm');

$aTabs = [
	[
		'DIV' => 'edit1',
		'TAB' => Loc::getMessage('MTAI_RO_OPTIONS_TAB'),
		'TITLE' => Loc::getMessage('MTAI_RO_OPTIONS_TAB_TITLE'),
	],
];
$tabControl = new CAdminTabControl('tabControl', $aTabs);

// ==== справочники для редактора правил =====================================

// классические сущности + смарт-процессы
$entityTypes = [
	['id' => CCrmOwnerType::Deal, 'title' => Loc::getMessage('MTAI_RO_ENTITY_DEAL')],
	['id' => CCrmOwnerType::Contact, 'title' => Loc::getMessage('MTAI_RO_ENTITY_CONTACT')],
	['id' => CCrmOwnerType::Company, 'title' => Loc::getMessage('MTAI_RO_ENTITY_COMPANY')],
];

$typeFactory = ServiceLocator::getInstance()->get('crm.type.factory');
$typeRows = TypeTable::getList([
	'select' => ['ID', 'ENTITY_TYPE_ID', 'TITLE'],
	'order' => ['ID' => 'ASC'],
]);
while ($typeRow = $typeRows->fetch())
{
	$entityTypes[] = [
		'id' => (int)$typeRow['ENTITY_TYPE_ID'],
		'title' => sprintf(
			'%s [%s]',
			(string)$typeRow['TITLE'] !== '' ? (string)$typeRow['TITLE'] : ('#' . $typeRow['ID']),
			$typeRow['ENTITY_TYPE_ID'],
		),
	];
}

// UF-сущности типов (ID строк смарт-типов нужны для getUserFieldEntityId)
$typeRowIdByEntityTypeId = [];
foreach ($entityTypes as $entityType)
{
	if (\CCrmOwnerType::isUseDynamicTypeBasedApproach((int)$entityType['id']))
	{
		$typeRow = TypeTable::getRow([
			'filter' => ['=ENTITY_TYPE_ID' => (int)$entityType['id']],
			'select' => ['ID'],
		]);
		$typeRowIdByEntityTypeId[(int)$entityType['id']] = (int)($typeRow['ID'] ?? 0);
	}
}

// группы пользователей
$userGroups = [];
$groupsDb = CGroup::GetList($by = 'id', $order = 'asc', ['ANONYMOUS' => 'N']);
while ($group = $groupsDb->Fetch())
{
	$userGroups[(int)$group['ID']] = sprintf('[%s] %s', $group['ID'], $group['NAME']);
}

// UF-поля по типам сущностей — подсказки для поля «Поле»
$ufFieldsByType = [];
foreach ($entityTypes as $entityType)
{
	$typeId = (int)$entityType['id'];
	$ufEntityId = isset($typeRowIdByEntityTypeId[$typeId])
		? $typeFactory->getUserFieldEntityId($typeRowIdByEntityTypeId[$typeId])
		: 'CRM_' . strtoupper(CCrmOwnerType::ResolveName($typeId));

	$fields = [];
	$ufDb = CUserTypeEntity::GetList([], ['ENTITY_ID' => $ufEntityId]);
	while ($uf = $ufDb->Fetch())
	{
		$label = $uf['EDIT_FORM_LABEL'][LANGUAGE_ID] ?? ($uf['EDIT_FORM_LABEL']['ru'] ?? '');
		$fields[$uf['FIELD_NAME']] = sprintf('%s — %s', $uf['FIELD_NAME'], $label !== '' ? $label : $uf['FIELD_NAME']);
	}
	$ufFieldsByType[$typeId] = $fields;
}

// ==== сохранение ============================================================

if (
	$_SERVER['REQUEST_METHOD'] === 'POST'
	&& check_bitrix_sessid()
	&& (isset($_POST['Update']) || isset($_POST['rebind']))
) {
	if (isset($_POST['Update']))
	{
		Option::set($module_id, 'enabled', isset($_POST['enabled']) ? 'Y' : 'N');
		Option::set($module_id, 'apply_to_admins', isset($_POST['apply_to_admins']) ? 'Y' : 'N');

		$rules = RulesConfig::normalizeFromPost(is_array($_POST['rule'] ?? null) ? $_POST['rule'] : []);
		Option::set($module_id, 'rules', $rules !== [] ? json_encode($rules, JSON_UNESCAPED_UNICODE) : '');
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

$rulesRaw = (string)Option::get($module_id, 'rules', '');
$rulesRows = [];
if (trim($rulesRaw) !== '')
{
	$decoded = Json::decode($rulesRaw);
	if (is_array($decoded))
	{
		$rulesRows = array_values(array_filter($decoded, 'is_array'));
	}
}

/**
 * HTML одной строки правила. $row — массив из настройки rules.
 * Плейсхолдеры __KIND_*__ заменяются после (вызовы Loc::getMessage в heredoc недоступны).
 */
function mtaiRoRenderRuleRow(int $index, array $row, array $entityTypes, array $userGroups): string
{
	$kind = (string)($row['kind'] ?? 'fieldEquals');
	$title = htmlspecialcharsbx((string)($row['title'] ?? ''));
	$field = htmlspecialcharsbx((string)($row['field'] ?? ''));
	$value = htmlspecialcharsbx((string)($row['value'] ?? ''));
	$groupId = (int)($row['groupId'] ?? 0);
	$selectedTypes = array_map('intval', is_array($row['entityTypeIds'] ?? null) ? $row['entityTypeIds'] : []);

	$typeOptions = '';
	foreach ($entityTypes as $entityType)
	{
		$typeOptions .= sprintf(
			'<option value="%s"%s>%s</option>',
			(int)$entityType['id'],
			in_array((int)$entityType['id'], $selectedTypes, true) ? ' selected' : '',
			htmlspecialcharsbx($entityType['title']),
		);
	}

	$groupOptions = '<option value="0">__GROUP_PLACEHOLDER__</option>';
	foreach ($userGroups as $gid => $gname)
	{
		$groupOptions .= sprintf(
			'<option value="%s"%s>%s</option>',
			$gid,
			$gid === $groupId ? ' selected' : '',
			htmlspecialcharsbx($gname),
		);
	}

	$isFieldKind = $kind !== 'userGroup';
	$fieldDisplay = $isFieldKind ? '' : 'none';
	$groupDisplay = $isFieldKind ? 'none' : '';

	return <<<HTML
	<tr class="mtai-ro-rule">
		<td style="vertical-align:top;">
			<input type="text" name="rule[{$index}][title]" value="{$title}" size="16" style="width:130px;">
		</td>
		<td style="vertical-align:top;">
			<select name="rule[{$index}][kind]" class="mtai-ro-kind" style="width:165px;">
				<option value="fieldEquals"__SEL_FIELD__>__KIND_FIELD__</option>
				<option value="userGroup"__SEL_GROUP__>__KIND_GROUP__</option>
			</select>
		</td>
		<td style="vertical-align:top;">
			<select name="rule[{$index}][entityTypeIds][]" multiple size="4" class="mtai-ro-types" style="min-width:150px;">{$typeOptions}</select>
		</td>
		<td style="vertical-align:top;display:{$fieldDisplay};" class="mtai-ro-field-cell">
			<input type="text" name="rule[{$index}][field]" value="{$field}" list="mtai-ro-fields-{$index}"
				placeholder="UF_..." class="mtai-ro-field" style="width:170px;">
			<datalist id="mtai-ro-fields-{$index}" class="mtai-ro-datalist"></datalist>
		</td>
		<td style="vertical-align:top;display:{$fieldDisplay};" class="mtai-ro-value-cell">
			<input type="text" name="rule[{$index}][value]" value="{$value}" class="mtai-ro-value" style="width:80px;">
		</td>
		<td style="vertical-align:top;display:{$groupDisplay};" class="mtai-ro-group-cell">
			<select name="rule[{$index}][groupId]" class="mtai-ro-group" style="width:210px;">{$groupOptions}</select>
		</td>
		<td style="vertical-align:top;">
			<input type="button" value="✕" class="mtai-ro-remove" style="width:30px;" title="__REMOVE_TITLE__">
		</td>
	</tr>
HTML;
}

/** Замена текстовых плейсхолдеров строки правила на локализованные значения. */
function mtaiRoLocalizeRow(string $html, string $kind): string
{
	return str_replace(
		[
			'__SEL_FIELD__',
			'__SEL_GROUP__',
			'__KIND_FIELD__',
			'__KIND_GROUP__',
			'__GROUP_PLACEHOLDER__',
			'__REMOVE_TITLE__',
		],
		[
			$kind !== 'userGroup' ? ' selected' : '',
			$kind === 'userGroup' ? ' selected' : '',
			Loc::getMessage('MTAI_RO_KIND_FIELD'),
			Loc::getMessage('MTAI_RO_KIND_GROUP'),
			Loc::getMessage('MTAI_RO_RULE_GROUP_PLACEHOLDER'),
			Loc::getMessage('MTAI_RO_RULE_REMOVE'),
		],
		$html,
	);
}

$tabControl->Begin();
?>
<form method="post" action="<?= $APPLICATION->GetCurPage() ?>?mid=<?= urlencode($module_id) ?>&lang=<?= LANGUAGE_ID ?>"
	name="mtai_ro_form">
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
		<td colspan="2">
			<div style="font-weight:bold;margin:8px 0 4px;"><?= Loc::getMessage('MTAI_RO_OPTIONS_RULES') ?></div>
			<div style="color:#666;margin-bottom:8px;"><?= Loc::getMessage('MTAI_RO_RULES_HINT') ?></div>

			<table class="internal mtai-ro-table" style="width:100%;">
				<thead>
				<tr class="heading">
					<td><?= Loc::getMessage('MTAI_RO_RULE_TITLE') ?></td>
					<td><?= Loc::getMessage('MTAI_RO_RULE_KIND') ?></td>
					<td><?= Loc::getMessage('MTAI_RO_RULE_ENTITIES') ?></td>
					<td><?= Loc::getMessage('MTAI_RO_RULE_FIELD') ?></td>
					<td><?= Loc::getMessage('MTAI_RO_RULE_VALUE') ?></td>
					<td><?= Loc::getMessage('MTAI_RO_RULE_GROUP') ?></td>
					<td></td>
				</tr>
				</thead>
				<tbody id="mtai-ro-rules">
				<?php
				foreach ($rulesRows as $i => $row) {
					echo mtaiRoLocalizeRow(
						mtaiRoRenderRuleRow($i, $row, $entityTypes, $userGroups),
						(string)($row['kind'] ?? ''),
					);
				}
				?>
				</tbody>
			</table>

			<input type="button" id="mtai-ro-add" value="<?= Loc::getMessage('MTAI_RO_RULE_ADD') ?>" style="margin-top:6px;">
		</td>
	</tr>
	<?php $tabControl->Buttons(); ?>
	<input type="submit" name="Update" value="<?= Loc::getMessage('MTAI_RO_OPTIONS_SAVE') ?>" class="adm-btn-save">
	<input type="submit" name="rebind" value="<?= Loc::getMessage('MTAI_RO_OPTIONS_REBIND') ?>"
		onclick="return confirm('<?= \CUtil::JSEscape(Loc::getMessage('MTAI_RO_OPTIONS_REBIND_CONFIRM')) ?>');">
	<?php $tabControl->End(); ?>
</form>

<script>
(function () {
	var ufByType = <?= \CUtil::PhpToJSObject($ufFieldsByType) ?>;
	var rulesBody = BX('mtai-ro-rules');
	var addBtn = BX('mtai-ro-add');
	var nextIndex = <?= count($rulesRows) ?>;

	// шаблон новой строки (index 0) — индексы подставляются при добавлении
	var template = <?= \CUtil::PhpToJSObject(mtaiRoLocalizeRow(
		mtaiRoRenderRuleRow(0, [], $entityTypes, $userGroups),
		'fieldEquals',
	)) ?>;

	function rowHtml(index) {
		return template
			.replace(/rule\[0\]/g, 'rule[' + index + ']')
			.replace('mtai-ro-fields-0', 'mtai-ro-fields-' + index);
	}

	function updateDatalist(row) {
		var select = row.querySelector('.mtai-ro-types');
		var datalist = row.querySelector('.mtai-ro-datalist');
		if (!select || !datalist) {
			return;
		}
		var names = {};
		[].forEach.call(select.selectedOptions || [], function (opt) {
			var fields = ufByType[parseInt(opt.value, 10)] || {};
			Object.keys(fields).forEach(function (name) { names[name] = fields[name]; });
		});
		datalist.innerHTML = Object.keys(names).sort().map(function (name) {
			return '<option value="' + name + '">' + names[name] + '</option>';
		}).join('');
	}

	function bindRow(row) {
		if (!row) {
			return;
		}
		var kindSelect = row.querySelector('.mtai-ro-kind');
		var removeBtn = row.querySelector('.mtai-ro-remove');
		var typesSelect = row.querySelector('.mtai-ro-types');

		function syncKind() {
			var isField = kindSelect.value === 'fieldEquals';
			row.querySelector('.mtai-ro-field-cell').style.display = isField ? '' : 'none';
			row.querySelector('.mtai-ro-value-cell').style.display = isField ? '' : 'none';
			row.querySelector('.mtai-ro-group-cell').style.display = isField ? 'none' : '';
		}

		BX.bind(kindSelect, 'change', syncKind);
		BX.bind(removeBtn, 'click', function () { BX.remove(row); });
		BX.bind(typesSelect, 'change', function () { updateDatalist(row); });

		syncKind();
		updateDatalist(row);
	}

	BX.bind(addBtn, 'click', function () {
		var tmp = document.createElement('tbody');
		tmp.innerHTML = '<table>' + rowHtml(nextIndex) + '</table>';
		var row = tmp.querySelector('tr.mtai-ro-rule');
		rulesBody.appendChild(row);
		bindRow(row);
		nextIndex++;
	});

	[].forEach.call(rulesBody.querySelectorAll('tr.mtai-ro-rule'), bindRow);
})();
</script>

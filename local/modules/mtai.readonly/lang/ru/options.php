<?php

$MESS['MTAI_RO_OPTIONS_TAB'] = 'Режим только чтение';
$MESS['MTAI_RO_OPTIONS_TAB_TITLE'] = 'Настройки режима «только чтение» для элементов CRM';
$MESS['MTAI_RO_OPTIONS_ENABLED'] = 'Модуль включён';
$MESS['MTAI_RO_OPTIONS_ADMINS'] = 'Применять к администраторам';
$MESS['MTAI_RO_OPTIONS_ADMINS_HINT'] = 'По умолчанию выключено: администратор всегда может отредактировать элемент и снять блокировку. Включайте, только если понимаете риск остаться без доступа к управлению.';
$MESS['MTAI_RO_OPTIONS_RULES'] = 'Правила (JSON)';
$MESS['MTAI_RO_OPTIONS_RULES_HINT'] = 'Массив правил: {"kind":"fieldEquals","entityTypeIds":[2,1038],"field":"UF_CRM_READONLY_DEMO","value":"Y"} или {"kind":"userGroup","entityTypeIds":[2],"groupId":9}. entityTypeIds: 2 = сделки, 3 = контакты, 4 = компании, ID смарт-процесса — как в URL /crm/type/&lt;ID&gt;/. Без entityTypeIds правило применяется ко всем сущностям.';
$MESS['MTAI_RO_OPTIONS_SAVE'] = 'Сохранить';
$MESS['MTAI_RO_OPTIONS_REBIND'] = 'Перерегистрировать обработчики смарт-процессов';
$MESS['MTAI_RO_OPTIONS_REBIND_CONFIRM'] = 'Перерегистрировать обработчики для всех смарт-процессов?';

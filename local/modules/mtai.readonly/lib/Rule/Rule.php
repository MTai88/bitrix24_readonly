<?php

declare(strict_types=1);

namespace MTai\ReadOnly\Rule;

/**
 * Правило «только чтение»: если matches() вернёт true для контекста,
 * элемент переводится в режим чтения для указанного пользователя.
 *
 * Правило может только ЗАПРЕТИТЬ редактирование (сузить штатные права),
 * разрешить то, что запрещено штатными ролями CRM, оно не может.
 */
interface Rule
{
	public function matches(Context $context): bool;
}

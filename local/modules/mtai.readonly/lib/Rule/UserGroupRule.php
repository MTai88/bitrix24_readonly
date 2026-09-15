<?php

declare(strict_types=1);

namespace MTai\ReadOnly\Rule;

/**
 * Условие «пользователь состоит в группе»: всей группе — только чтение.
 */
final class UserGroupRule implements Rule
{
	public function __construct(
		private readonly int $groupId,
	)
	{
	}

	public static function of(int $groupId): self
	{
		return new self($groupId);
	}

	public function matches(Context $context): bool
	{
		return in_array($this->groupId, $context->getUserGroups(), true);
	}
}

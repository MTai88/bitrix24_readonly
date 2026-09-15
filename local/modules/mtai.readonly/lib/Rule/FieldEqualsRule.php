<?php

declare(strict_types=1);

namespace MTai\ReadOnly\Rule;

/**
 * Условие «поле элемента равно значению».
 * Классический пример из задачи: UF_BLOCKED = Y → элемент только для чтения.
 */
final class FieldEqualsRule implements Rule
{
	private const BOOL_LIKE = ['Y', 'YES', 'TRUE', 'N', 'NO', 'FALSE'];

	public function __construct(
		private readonly string $fieldName,
		private readonly mixed $value,
	)
	{
	}

	public static function of(string $fieldName, mixed $value): self
	{
		return new self($fieldName, $value);
	}

	public function matches(Context $context): bool
	{
		$actual = $context->getFieldValue($this->fieldName);
		if ($actual === null)
		{
			return false;
		}

		return self::looselyEquals($actual, $this->value);
	}

	/**
	 * Сравнение с приведением bool-подобных значений: UF-поля boolean
	 * могут прийти как true/1/'1', а в правиле указано 'Y' — считаем их равными.
	 */
	public static function looselyEquals(mixed $actual, mixed $expected): bool
	{
		if (self::isBoolLike($actual) && self::isBoolLike($expected))
		{
			return self::toBool($actual) === self::toBool($expected);
		}

		// нестрогое сравнение: '11' == 11 и т.п.
		return $actual == $expected; // phpcs:ignore
	}

	private static function isBoolLike(mixed $value): bool
	{
		if (is_bool($value))
		{
			return true;
		}

		if (is_int($value))
		{
			return $value === 0 || $value === 1;
		}

		if (is_string($value))
		{
			$upper = strtoupper(trim($value));

			return in_array($upper, self::BOOL_LIKE, true) || $value === '0' || $value === '1';
		}

		return false;
	}

	private static function toBool(mixed $value): bool
	{
		if (is_bool($value))
		{
			return $value;
		}

		if (is_int($value))
		{
			return $value === 1;
		}

		if (is_string($value))
		{
			return in_array(strtoupper(trim($value)), ['Y', 'YES', 'TRUE', '1'], true);
		}

		return (bool)$value;
	}
}

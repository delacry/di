<?php declare(strict_types=1);

/**
 * This file is part of the Nette Framework (https://nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

namespace Nette\DI\Definitions;


/**
 * Reference to service. Either by name or by type or reference to the 'self' service.
 * Type references may additionally carry an identity tag that narrows resolution to
 * services with that exact tag.
 */
final class Reference
{
	public const Self = 'self';

	#[\Deprecated('use Reference::Self')]
	public const SELF = self::Self;


	/**
	 * Creates a type-based reference (resolved by class name rather than service name).
	 * Optional $tag narrows to services with that identity tag.
	 */
	public static function fromType(string $value, ?string $tag = null): static
	{
		if (!str_contains($value, '\\')) {
			$value = '\\' . $value;
		}

		return new static($value, $tag);
	}


	public function __construct(
		private readonly string $value,
		private readonly ?string $tag = null,
	) {
	}


	public function getValue(): string
	{
		return $this->value;
	}


	public function getTag(): ?string
	{
		return $this->tag;
	}


	public function isName(): bool
	{
		return !str_contains($this->value, '\\') && $this->value !== self::Self;
	}


	public function isType(): bool
	{
		return str_contains($this->value, '\\');
	}


	public function isSelf(): bool
	{
		return $this->value === self::Self;
	}
}

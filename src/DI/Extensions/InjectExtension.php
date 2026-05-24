<?php declare(strict_types=1);

/**
 * This file is part of the Nette Framework (https://nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

namespace Nette\DI\Extensions;

use Deprecated;
use Nette\DI\Attributes\Inject;
use Nette\DI\Definitions;


/**
 * Calls inject methods and fills #[Inject] properties.
 */
final class InjectExtension extends AbstractInjectExtension
{
	public const TagInject = 'nette.inject';

	#[Deprecated('use InjectExtension::TagInject')]
	public const TAG_INJECT = self::TagInject;


	protected static function injectAttribute(): string
	{
		return Inject::class;
	}


	protected function shouldInjectMembers(Definitions\Definition $def, string $class): bool
	{
		return (bool) $def->getTag(self::TagInject);
	}
}

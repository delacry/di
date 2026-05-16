<?php declare(strict_types=1);

/**
 * This file is part of the Nette Framework (https://nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

namespace Nette\DI\Attributes;

use Attribute;


/**
 * Marks a property, constructor parameter, or `inject*` method parameter for tag-aware
 * dependency injection. On constructor and method parameters the attribute is only
 * meaningful when $tag is set — untagged parameters are autowired via their native type
 * already. On properties the attribute is also the marker that enables setter-style
 * injection on that property.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Inject
{
	public function __construct(
		public readonly ?string $tag = null,
	) {
	}
}

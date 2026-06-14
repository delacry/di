<?php declare(strict_types=1);

/**
 * Test: AbstractInjectExtension registers inject-bearing class files as container
 * dependencies, so editing an inject attribute (which the DependencyChecker hash does
 * not capture) invalidates the cached container.
 */

use Nette\DI;
use Nette\DI\Definitions\Definition;
use Nette\DI\Extensions\AbstractInjectExtension;
use Tester\Assert;


require __DIR__ . '/../bootstrap.php';


#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class ProbeInject
{
	public function __construct(public ?string $tag = null)
	{
	}
}

class ProbeExtension extends AbstractInjectExtension
{
	protected static function injectAttribute(): string
	{
		return ProbeInject::class;
	}


	protected static function allowsNonPublic(): bool
	{
		return true;
	}


	protected function shouldInjectMembers(Definition $def, string $class): bool
	{
		return true;
	}
}

class Dep
{
}

class Injected
{
	#[ProbeInject]
	private Dep $dep;
}

class Plain
{
}


// a class with an inject member → its file is tracked as a dependency
$compiler = new DI\Compiler;
$compiler->addExtension('probe', new ProbeExtension);
createContainer($compiler, "
services:
	dep: Dep
	injected: Injected
");
Assert::contains(
	new ReflectionClass(Injected::class)->getFileName(),
	$compiler->getContainerBuilder()->getDependencies(),
);


// a class with no inject members → its file is not added as a (string) dependency
$compiler = new DI\Compiler;
$compiler->addExtension('probe', new ProbeExtension);
createContainer($compiler, "
services:
	plain: Plain
");
Assert::notContains(
	new ReflectionClass(Plain::class)->getFileName(),
	$compiler->getContainerBuilder()->getDependencies(),
);

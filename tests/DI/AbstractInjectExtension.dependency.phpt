<?php declare(strict_types=1);

/**
 * Test: AbstractInjectExtension registers inject-bearing class files as container
 * dependencies, so editing an inject attribute (which the DependencyChecker hash does
 * not capture) invalidates the cached container. The inject-bearing class lives in a
 * separate file so its dependency can't be confused with the always-tracked extension file.
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

require __DIR__ . '/files/injectDependency.php';

$fixtureFile = new ReflectionClass(InjectDependencyConsumer::class)->getFileName();

// exportDependencies() returns [version, files, phpFiles, classes, functions, hash];
// [1] is the mtime-tracked file list.

// the consumer has an inject member → its file is tracked
$compiler = new DI\Compiler;
$compiler->addExtension('probe', new ProbeExtension);
createContainer($compiler, "
services:
	dep: InjectDependencyDep
	consumer: InjectDependencyConsumer
");
Assert::true(array_key_exists($fixtureFile, $compiler->exportDependencies()[1]));

// only a plain service from the same file is registered → the file is not tracked
$compiler = new DI\Compiler;
$compiler->addExtension('probe', new ProbeExtension);
createContainer($compiler, "
services:
	dep: InjectDependencyDep
");
Assert::false(array_key_exists($fixtureFile, $compiler->exportDependencies()[1]));

<?php declare(strict_types=1);

/**
 * Test: Container::getOrNull() — non-throwing variant of get().
 */

use Nette\DI;
use Tester\Assert;


require __DIR__ . '/../bootstrap.php';


interface IGON
{
}

class GonRed implements IGON
{
}

class GonBlue implements IGON
{
}


$compiler = new DI\Compiler;
$container = createContainer($compiler, '
services:
	red:
		factory: GonRed
		tag: warm

	blue:
		factory: GonBlue
		tag: cold
');

// Hit: returns the service
Assert::type(GonRed::class, $container->getOrNull(IGON::class, 'warm'));
Assert::type(GonBlue::class, $container->getOrNull(IGON::class, 'cold'));
Assert::type(GonRed::class, $container->getOrNull(GonRed::class));

// Miss: returns null (instead of throwing like get() does)
Assert::null($container->getOrNull(IGON::class, 'nonexistent'));
Assert::null($container->getOrNull(DateTime::class));
Assert::null($container->getOrNull('CompletelyUnknownClass'));

// Ambiguity still throws — that's a programming error
$compiler = new DI\Compiler;
$container = createContainer($compiler, '
services:
	red:
		factory: GonRed
		tag: shared
	blue:
		factory: GonBlue
		tag: shared
');

Assert::exception(
	fn() => $container->getOrNull(IGON::class, 'shared'),
	DI\MissingServiceException::class,
	"Multiple services of type IGON with tag 'shared' found: blue, red. To replace one, decorate the existing service; to keep both, give one an identity tag.",
);

// Untagged service (implicit "default" tag) lookups work the same way
$compiler = new DI\Compiler;
$container = createContainer($compiler, '
services:
	red: GonRed   # untagged → "default"
');

Assert::type(GonRed::class, $container->getOrNull(IGON::class));
Assert::type(GonRed::class, $container->getOrNull(IGON::class, 'default'));
Assert::null($container->getOrNull(IGON::class, 'somethingElse'));

<?php declare(strict_types=1);

/**
 * Test: array<string, T> PHPDoc-typed parameters get autowired as a tag-keyed map of services.
 */

use Nette\DI;
use Tester\Assert;


require __DIR__ . '/../bootstrap.php';


interface BagItem
{
}

class BagAlpha implements BagItem
{
}

class BagBeta implements BagItem
{
}

class BagGamma implements BagItem
{
}

class BagConsumer
{
	/**
	 * @param array<string, BagItem> $items
	 */
	public function __construct(
		public readonly array $items,
	) {
	}
}


$compiler = new DI\Compiler;
$container = createContainer($compiler, '
services:
	alpha:
		factory: BagAlpha
		tag: alpha

	beta:
		factory: BagBeta
		tag: beta

	gamma: BagGamma
	# untagged → "default"

	consumer: BagConsumer
');

$consumer = $container->getService('consumer');

Assert::count(3, $consumer->items);
Assert::type(BagAlpha::class, $consumer->items['alpha']);
Assert::type(BagBeta::class, $consumer->items['beta']);
Assert::type(BagGamma::class, $consumer->items['default']);

// Verify the same instance comes from get($type, $tag) — they share the underlying service entries
Assert::same($container->get(BagItem::class, 'alpha'), $consumer->items['alpha']);
Assert::same($container->get(BagItem::class, 'beta'), $consumer->items['beta']);


// Tag-collision test: two services share a tag → compile-time error
Assert::exception(
	function () {
		$compiler = new DI\Compiler;
		createContainer($compiler, '
services:
	a:
		factory: BagAlpha
		tag: sharedTag
	b:
		factory: BagBeta
		tag: sharedTag
	consumer: BagConsumer
');
	},
	DI\ServiceCreationException::class,
	"%A%Cannot autowire array<string, BagItem>: services 'a' and 'b' share the identity tag 'sharedTag'.",
);


// Non-tagged list autowire still works (T[] PHPDoc → numerically-keyed list)
class ListConsumer
{
	/**
	 * @param BagItem[] $items
	 */
	public function __construct(
		public readonly array $items,
	) {
	}
}

$compiler = new DI\Compiler;
$container = createContainer($compiler, '
services:
	alpha:
		factory: BagAlpha
		tag: alpha
	beta:
		factory: BagBeta
		tag: beta
	consumer: ListConsumer
');

$consumer = $container->getService('consumer');
Assert::count(2, $consumer->items);
Assert::same([0, 1], array_keys($consumer->items));   // numeric keys, not tags

<?php declare(strict_types=1);

/**
 * Test: DefinitionOrdering also orders the tag-keyed map autowire (array<string, T>)
 */

use Nette\DI;
use Tester\Assert;


require __DIR__ . '/../bootstrap.php';


interface Handler
{
}

class Aaa implements Handler
{
}

class Bbb implements Handler
{
}

class Ccc implements Handler
{
}

class TagCollector
{
	/** @var list<string> */
	public array $tagsInOrder;

	/** @param array<string, Handler> $items */
	public function __construct(array $items)
	{
		$this->tagsInOrder = array_keys($items);
	}
}


$builder = new DI\ContainerBuilder;
$builder->addDefinition('a')->setType(Aaa::class)->setTag('alpha');               // Normal (0)
$builder->addDefinition('b')->setType(Bbb::class)->setTag('beta')->setPriority(100);   // Highest here
$builder->addDefinition('c')->setType(Ccc::class)->setTag('gamma')->setPriority(-100); // Lowest here

$container = createContainer($builder);

// keyed by identity tag, ordered by priority: beta(100), alpha(0), gamma(-100)
$collector = $container->createInstance(TagCollector::class);
Assert::same(['beta', 'alpha', 'gamma'], $collector->tagsInOrder);

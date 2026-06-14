<?php declare(strict_types=1);

/**
 * Test: DefinitionOrdering applies to collections autowired at runtime
 * (Container::findAutowired / createInstance), not just compile-time-baked ones.
 */

use Nette\DI;
use Tester\Assert;


require __DIR__ . '/../bootstrap.php';


interface Orderable
{
}

class Alpha implements Orderable
{
}

class Bravo implements Orderable
{
}

class Charlie implements Orderable
{
}

class Collector
{
	/** @var list<class-string> */
	public array $order;

	/** @param list<Orderable> $items */
	public function __construct(array $items)
	{
		$this->order = array_map(fn(Orderable $i): string => $i::class, $items);
	}
}


$builder = new DI\ContainerBuilder;
// Registered in an order that contradicts both priority and FQCN, to prove neither
// registration nor filesystem order leaks through.
$builder->addDefinition('bravo')->setType(Bravo::class);
$builder->addDefinition('alpha')->setType(Alpha::class);
$builder->addDefinition('high')->setType(Charlie::class)->setPriority(100);

$container = createContainer($builder);

// runtime findAutowired returns names in DefinitionOrdering order (priority, then FQCN)
Assert::same(['high', 'alpha', 'bravo'], $container->findAutowired(Orderable::class));

// and a collection autowired into an on-demand instance is ordered the same way
$collector = $container->createInstance(Collector::class);
Assert::same([Charlie::class, Alpha::class, Bravo::class], $collector->order);

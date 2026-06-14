<?php declare(strict_types=1);

/**
 * Test: Nette\DI\Definitions\Definition ordering metadata (priority / before / after)
 */

use Nette\DI\ContainerBuilder;
use Tester\Assert;


require __DIR__ . '/../bootstrap.php';


$def = (new ContainerBuilder)->addDefinition('x')->setType(stdClass::class);

// defaults
Assert::null($def->getPriority());
Assert::same([], $def->getBefore());
Assert::same([], $def->getAfter());

// fluent setters return $this
Assert::same($def, $def->setPriority(100));
Assert::same(100, $def->getPriority());

Assert::same($def, $def->setPriority(null));
Assert::null($def->getPriority());

Assert::same($def, $def->setBefore([stdClass::class]));
Assert::same([stdClass::class], $def->getBefore());

Assert::same($def, $def->setAfter([stdClass::class, Throwable::class]));
Assert::same([stdClass::class, Throwable::class], $def->getAfter());

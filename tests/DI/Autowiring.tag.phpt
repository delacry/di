<?php declare(strict_types=1);

/**
 * Test: Nette\DI\Autowiring with identity tag.
 */

use Nette\DI;
use Tester\Assert;


require __DIR__ . '/../bootstrap.php';


interface IBus
{
}

class CommandBus implements IBus
{
}

class QueryBus implements IBus
{
}

class UntaggedBus implements IBus
{
}


$compiler = new DI\Compiler;
$container = createContainer($compiler, '
services:
	cmdBus:
		factory: CommandBus
		tag: command

	qryBus:
		factory: QueryBus
		tag: query

	plainBus: UntaggedBus
');

$builder = $compiler->getContainerBuilder();

// ContainerBuilder::getByType respects tag
Assert::same('cmdBus', $builder->getByType(IBus::class, throw: true, tag: 'command'));
Assert::same('qryBus', $builder->getByType(IBus::class, throw: true, tag: 'query'));

// no-tag with multiple candidates: prefers the implicit "default"-tagged one
Assert::same('plainBus', $builder->getByType(IBus::class, throw: true));

// missing-tag: throws with helpful message
Assert::exception(
	fn() => $builder->getByType(IBus::class, throw: true, tag: 'audit'),
	DI\MissingServiceException::class,
	"Service of type IBus with tag 'audit' not found.",
);

// runtime side: Container::get with tag
Assert::type(CommandBus::class, $container->get(IBus::class, 'command'));
Assert::type(QueryBus::class, $container->get(IBus::class, 'query'));
Assert::type(UntaggedBus::class, $container->get(IBus::class));

// Container::getByType still works with bool $throw and optional tag
Assert::type(CommandBus::class, $container->getByType(IBus::class, throw: true, tag: 'command'));
Assert::null($container->getByType(IBus::class, throw: false, tag: 'nonexistent'));

// Definition::getTag returns "default" for untagged
$plainDef = $builder->getDefinition('plainBus');
Assert::same(DI\Definitions\Definition::DefaultTag, $plainDef->getTag());
Assert::same('default', $plainDef->getTag());

// Definition::getTag returns the explicit tag when set
$cmdDef = $builder->getDefinition('cmdBus');
Assert::same('command', $cmdDef->getTag());

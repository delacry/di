<?php declare(strict_types=1);

/**
 * Test: NEON @Type#tag reference syntax — tag-aware service references in config.
 */

use Nette\DI;
use Tester\Assert;


require __DIR__ . '/../bootstrap.php';


interface IBusRef
{
}

class CmdBusRef implements IBusRef
{
}

class QryBusRef implements IBusRef
{
}

class Consumer
{
	public function __construct(
		public readonly IBusRef $bus,
	) {
	}
}


$compiler = new DI\Compiler;
$container = createContainer($compiler, '
services:
	cmd:
		factory: CmdBusRef
		tag: command

	qry:
		factory: QryBusRef
		tag: query

	consumer:
		factory: Consumer
		arguments:
			bus: @\IBusRef#query
');

$consumer = $container->getService('consumer');
Assert::type(QryBusRef::class, $consumer->bus);

// Verify the same via Container::get
Assert::same($consumer->bus, $container->get(IBusRef::class, 'query'));

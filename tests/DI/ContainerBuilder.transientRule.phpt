<?php declare(strict_types=1);

/**
 * Test: ContainerBuilder::addTransientRule — type and closure rules mark matching
 * definitions transient during completion, no matter who registered them.
 */

use Nette\DI;
use Tester\Assert;


require __DIR__ . '/../bootstrap.php';


abstract class Renderable
{
}

class HomePage extends Renderable
{
}

class AdminPage extends Renderable
{
}

class ReportJob
{
}

class Registry
{
}


// a string rule matches by is-a on the resolved type, a closure decides per definition
$compiler = new DI\Compiler;
$compiler->getContainerBuilder()->addTransientRule(Renderable::class);
$compiler->getContainerBuilder()->addTransientRule(
	fn(DI\Definitions\ServiceDefinition $def): bool => $def->getType() === ReportJob::class,
);

$container = createContainer($compiler, '
services:
	home: HomePage
	admin: AdminPage
	job: ReportJob
	registry: Registry
');

Assert::notSame($container->create(HomePage::class), $container->create(HomePage::class));
Assert::type(AdminPage::class, $container->create(AdminPage::class));
Assert::type(ReportJob::class, $container->create(ReportJob::class));
Assert::same($container->get(Registry::class), $container->get(Registry::class));

Assert::exception(
	fn() => $container->get(HomePage::class),
	DI\TransientServiceException::class,
);

// a transient dependency of a shared service fails the compile with guidance
class Dashboard
{
	public function __construct(
		public HomePage $page,
	) {
	}
}

$compiler = new DI\Compiler;
$compiler->getContainerBuilder()->addTransientRule(Renderable::class);

Assert::exception(
	fn() => createContainer($compiler, '
services:
	home: HomePage
	dashboard: Dashboard
'),
	DI\ServiceCreationException::class,
	'%A%is transient and cannot be autowired into a shared service — inject a factory or use Container::create().%A%',
);

// two transients of one type: create() reports the ambiguity
$compiler = new DI\Compiler;
$compiler->getContainerBuilder()->addTransientRule(Renderable::class);

$container = createContainer($compiler, '
services:
	home: HomePage
	home2: HomePage
');

Assert::exception(
	fn() => $container->create(HomePage::class),
	DI\MissingServiceException::class,
	'Multiple transient services of type HomePage found: %a%.',
);

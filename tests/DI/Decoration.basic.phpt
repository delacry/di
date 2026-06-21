<?php declare(strict_types=1);

/**
 * Test: Nette\DI\ContainerBuilder: service decoration (onion chains).
 */

use Nette\DI;
use Nette\DI\Definitions\Reference;
use Nette\DI\ServiceCreationException;
use Tester\Assert;


require __DIR__ . '/../bootstrap.php';


interface Greeter
{
	public function greet(): string;
}

class BaseGreeter implements Greeter
{
	public function greet(): string
	{
		return 'base';
	}
}

class QuietGreeter implements Greeter
{
	public function greet(): string
	{
		return 'quiet';
	}
}

class ExclaimGreeter implements Greeter
{
	public function __construct(public Greeter $inner)
	{
	}


	public function greet(): string
	{
		return $this->inner->greet() . '!';
	}
}

class ShoutGreeter implements Greeter
{
	public function __construct(public Greeter $inner)
	{
	}


	public function greet(): string
	{
		return strtoupper($this->inner->greet());
	}
}

interface Reader
{
	public function read(): string;
}

interface Writer
{
	public function write(): string;
}

class BaseIo implements Reader, Writer
{
	public function read(): string
	{
		return 'r';
	}


	public function write(): string
	{
		return 'w';
	}
}

class TracingIo implements Reader, Writer
{
	public function __construct(public Reader&Writer $inner)
	{
	}


	public function read(): string
	{
		return $this->inner->read() . '*';
	}


	public function write(): string
	{
		return $this->inner->write() . '*';
	}
}

class AuditWriter implements Writer
{
	public function __construct(public Writer $inner)
	{
	}


	public function write(): string
	{
		return $this->inner->write() . '#';
	}
}

class ForkGreeter implements Greeter
{
	public function __construct(public Greeter $sibling, public Greeter $inner)
	{
	}


	public function greet(): string
	{
		return $this->inner->greet() . '/' . $this->sibling->greet();
	}
}


// a single decorator wraps the base
test('single decorator', function () {
	$builder = new DI\ContainerBuilder;
	$builder->addDefinition('base')->setType(BaseGreeter::class);
	$builder->addDefinition('exclaim')->setType(ExclaimGreeter::class)->decorate(Greeter::class);

	$container = createContainer($builder);
	$greeter = $container->getByType(Greeter::class);

	Assert::type(ExclaimGreeter::class, $greeter);
	Assert::same('base!', $greeter->greet());
});


// decorators stack outermost-first by decoration priority
test('priority onion', function () {
	$builder = new DI\ContainerBuilder;
	$builder->addDefinition('base')->setType(BaseGreeter::class);
	$builder->addDefinition('exclaim')->setType(ExclaimGreeter::class)->decorate(Greeter::class, priority: 0);
	$builder->addDefinition('shout')->setType(ShoutGreeter::class)->decorate(Greeter::class, priority: 10);

	$container = createContainer($builder);
	$greeter = $container->getByType(Greeter::class);

	Assert::type(ShoutGreeter::class, $greeter); // highest priority is outermost
	Assert::same('BASE!', $greeter->greet()); // strtoupper('base!')
});


// one decorator wraps several interfaces sharing one inner instance
test('multi-interface', function () {
	$builder = new DI\ContainerBuilder;
	$builder->addDefinition('io')->setType(BaseIo::class);
	$builder->addDefinition('tracing')->setType(TracingIo::class)
		->decorate(Reader::class)
		->decorate(Writer::class);

	$container = createContainer($builder);
	$reader = $container->getByType(Reader::class);

	Assert::type(TracingIo::class, $reader);
	Assert::same($reader, $container->getByType(Writer::class));
	Assert::same('r*', $reader->read());
	Assert::same('w*', $reader->write());
});


// decorating a tagged slot leaves the default slot untouched
test('tagged slot', function () {
	$builder = new DI\ContainerBuilder;
	$builder->addDefinition('fast')->setType(BaseGreeter::class)->setTag('fast');
	$builder->addDefinition('plain')->setType(QuietGreeter::class);
	$builder->addDefinition('exclaim')->setType(ExclaimGreeter::class)->decorate(Greeter::class, 'fast');

	$container = createContainer($builder);

	Assert::same('base!', $container->get(Greeter::class, 'fast')->greet()); // wrapped
	Assert::same('quiet', $container->get(Greeter::class)->greet()); // default slot intact
});


// a decorator outermost in one slot but wrapped in another keeps the slots separate
test('buried in one slot, outermost in another', function () {
	$builder = new DI\ContainerBuilder;
	$builder->addDefinition('io')->setType(BaseIo::class);
	$builder->addDefinition('tracing')->setType(TracingIo::class)
		->decorate(Reader::class)
		->decorate(Writer::class);
	$builder->addDefinition('audit')->setType(AuditWriter::class)->decorate(Writer::class, priority: 10);

	$container = createContainer($builder);

	// Reader: only TracingIo decorates it, so it is outermost there.
	Assert::type(TracingIo::class, $container->getByType(Reader::class));
	Assert::same('r*', $container->getByType(Reader::class)->read());

	// Writer: AuditWriter (higher priority) wraps TracingIo which wraps the base.
	Assert::type(AuditWriter::class, $container->getByType(Writer::class));
	Assert::same('w*#', $container->getByType(Writer::class)->write());
});


// a narrower decorator (implements only one of the base's interfaces) leaves the base
// reachable by the types the decorator does NOT implement — and by its concrete class
test('surgical burial keeps the base autowired for undecorated types', function () {
	$builder = new DI\ContainerBuilder;
	$builder->addDefinition('io')->setType(BaseIo::class); // implements Reader + Writer
	$builder->addDefinition('audit')->setType(AuditWriter::class)->decorate(Writer::class); // implements Writer only

	$container = createContainer($builder);

	// Writer slot is decorated: the wrapper answers it.
	Assert::type(AuditWriter::class, $container->getByType(Writer::class));
	Assert::same('w#', $container->getByType(Writer::class)->write());

	// Reader is NOT implemented by the decorator, so the base still answers it
	// (under total burial this would throw "not autowired").
	Assert::type(BaseIo::class, $container->getByType(Reader::class));

	// And the base is still reachable by its concrete class.
	Assert::type(BaseIo::class, $container->getByType(BaseIo::class));
});


// a non-autowired service of the same type + identity tag is not a decoration base —
// it coexists with the decorated (autowired) one rather than causing "multiple base"
test('non-autowired same-type service is not a base', function () {
	$builder = new DI\ContainerBuilder;
	$builder->addDefinition('app')->setType(BaseGreeter::class);
	$builder->addDefinition('internal')->setType(BaseGreeter::class)->setAutowired(false); // private, by-name only
	$builder->addDefinition('exclaim')->setType(ExclaimGreeter::class)->decorate(Greeter::class);

	$container = createContainer($builder);

	// The autowired 'app' base is wrapped; 'internal' is untouched and still reachable by name.
	Assert::type(ExclaimGreeter::class, $container->getByType(Greeter::class));
	Assert::same('base!', $container->getByType(Greeter::class)->greet());
	Assert::type(BaseGreeter::class, $container->getService('internal'));
});


// the inner is the param resolved to the slot's service (as an extension or NEON sets it);
// a param bound to a different service is left untouched
test('redirects the param bound to the slot', function () {
	$builder = new DI\ContainerBuilder;
	$builder->addDefinition('fast')->setType(BaseGreeter::class)->setTag('fast');
	$builder->addDefinition('other')->setType(QuietGreeter::class)->setTag('other');
	$builder->addDefinition('deco')->setType(ForkGreeter::class)
		->decorate(Greeter::class, 'fast')
		->setArgument('inner', Reference::fromType(Greeter::class, 'fast'))
		->setArgument('sibling', Reference::fromType(Greeter::class, 'other'));

	$container = createContainer($builder);
	$greeter = $container->get(Greeter::class, 'fast');

	Assert::type(ForkGreeter::class, $greeter);
	Assert::same('base/quiet', $greeter->greet()); // inner = fast base, sibling = the 'other' service
});


// nothing to decorate aborts compilation
test('no base service', function () {
	$builder = new DI\ContainerBuilder;
	$builder->addDefinition('exclaim')->setType(ExclaimGreeter::class)->decorate(Greeter::class);

	Assert::exception(
		fn() => createContainer($builder),
		ServiceCreationException::class,
		'No base service of type %a% found to decorate.',
	);
});


// targets of one definition must agree on the identity tag
test('mixed tags rejected', function () {
	$builder = new DI\ContainerBuilder;
	$def = $builder->addDefinition('exclaim')->setType(ExclaimGreeter::class)->decorate(Greeter::class, 'a');

	Assert::exception(
		fn() => $def->decorate(Reader::class, 'b'),
		Nette\InvalidStateException::class,
		'%A%same identity tag%A%',
	);
});

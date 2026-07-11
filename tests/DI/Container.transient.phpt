<?php declare(strict_types=1);

/**
 * Test: transient services — never cached, refused by get(), built fresh by create().
 */

use Nette\DI;
use Tester\Assert;


require __DIR__ . '/../bootstrap.php';


class Mailer
{
}

class Newsletter
{
	public function __construct(
		public Mailer $mailer,
	) {
	}
}


$compiler = new DI\Compiler;
$container = createContainer($compiler, '
services:
	mailer: Mailer

	newsletter:
		factory: Newsletter
		transient: true
');

// create() builds a fresh, fully wired instance per call
$a = $container->create(Newsletter::class);
$b = $container->create(Newsletter::class);
Assert::type(Newsletter::class, $a);
Assert::type(Mailer::class, $a->mailer);
Assert::notSame($a, $b);
Assert::same($a->mailer, $b->mailer); // the shared dependency stays shared

Assert::true($container->hasTransient(Newsletter::class));
Assert::false($container->hasTransient(Mailer::class));

// every shared-access path refuses a transient
Assert::exception(
	fn() => $container->getService('newsletter'),
	DI\TransientServiceException::class,
	"Service 'newsletter' of type Newsletter is transient — the container never shares its instance. Use Container::create() to build a fresh one.",
);

Assert::exception(
	fn() => $container->get(Newsletter::class),
	DI\TransientServiceException::class,
	'Service of type Newsletter is transient — use Container::create() for a fresh instance.',
);

Assert::exception(
	fn() => $container->getByType(Newsletter::class),
	DI\TransientServiceException::class,
);

// nullable lookups miss silently — a transient is simply not autowirable
Assert::null($container->getByType(Newsletter::class, throw: false));
Assert::null($container->getOrNull(Newsletter::class));

// create() refuses a shared service and an unknown type
Assert::exception(
	fn() => $container->create(Mailer::class),
	DI\TransientServiceException::class,
	'Service of type Mailer is shared, not transient — use Container::get() for the container-managed instance.',
);

Assert::exception(
	fn() => $container->create(stdClass::class),
	DI\MissingServiceException::class,
	'Transient service of type stdClass not found. Did you add it to configuration file?',
);

// the definition was withdrawn from autowiring during completion
$def = $compiler->getContainerBuilder()->getDefinition('newsletter');
Assert::type(DI\Definitions\ServiceDefinition::class, $def);
Assert::true($def->isTransient());
Assert::false($def->getAutowired());


// tag-aware create(): (type, tag) selects among same-type transients
class Report
{
	public function __construct(
		public string $format,
	) {
	}
}

$container = createContainer(new DI\Compiler, "
services:
\treport.plain:
\t\tfactory: Report('plain')
\t\ttransient: true

\treport.pdf:
\t\tfactory: Report('pdf')
\t\ttransient: true
\t\ttag: pdf

\treport.csv:
\t\tfactory: Report('csv')
\t\ttransient: true
\t\ttag: csv
");

// the tag picks the matching transient, still fresh per call
Assert::same('pdf', $container->create(Report::class, 'pdf')->format);
Assert::same('csv', $container->create(Report::class, 'csv')->format);
Assert::notSame($container->create(Report::class, 'pdf'), $container->create(Report::class, 'pdf'));

// no tag → the lone "default"-tagged transient breaks the ambiguity
Assert::same('plain', $container->create(Report::class)->format);

// hasTransient() is tag-aware too
Assert::true($container->hasTransient(Report::class));         // any tag
Assert::true($container->hasTransient(Report::class, 'pdf'));
Assert::false($container->hasTransient(Report::class, 'xml'));

// a tag that matches no transient of the type
Assert::exception(
	fn() => $container->create(Report::class, 'xml'),
	DI\MissingServiceException::class,
	"Transient service of type Report with tag 'xml' not found.",
);

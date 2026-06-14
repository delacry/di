<?php declare(strict_types=1);

/**
 * Test: Nette\DI\DefinitionOrdering::sort()
 */

use Nette\DI\ContainerBuilder;
use Nette\DI\DefinitionOrdering;
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

class Zeta implements Orderable
{
}


/**
 * Builds a name => Definition map. Spec: name => [type, priority, before, after].
 * @param array<string, array{class-string, ?int, list<class-string>, list<class-string>}> $spec
 * @return array<string, Nette\DI\Definitions\Definition>
 */
function defs(array $spec): array
{
	$builder = new ContainerBuilder;
	$out = [];
	foreach ($spec as $name => [$type, $priority, $before, $after]) {
		$out[$name] = $builder->addDefinition($name)
			->setType($type)
			->setPriority($priority)
			->setBefore($before)
			->setAfter($after);
	}

	return $out;
}


// a set with no ordering metadata is returned untouched (gate)
Assert::same(['bravo', 'alpha'], array_keys(DefinitionOrdering::sort(defs([
	'bravo' => [Bravo::class, null, [], []],
	'alpha' => [Alpha::class, null, [], []],
]))));

// priority descending, then FQCN ascending (alpha before bravo at equal priority)
Assert::same(['high', 'alpha', 'bravo', 'low'], array_keys(DefinitionOrdering::sort(defs([
	'bravo' => [Bravo::class, null, [], []],
	'alpha' => [Alpha::class, null, [], []],
	'high' => [Charlie::class, 100, [], []],
	'low' => [Zeta::class, -100, [], []],
]))));

// a before edge overrides the FQCN tie-break (Zeta would sort last)
Assert::same(['zeta', 'alpha'], array_keys(DefinitionOrdering::sort(defs([
	'alpha' => [Alpha::class, null, [], []],
	'zeta' => [Zeta::class, null, [Alpha::class], []],
]))));

// an after edge overrides the FQCN tie-break (Alpha would sort first)
Assert::same(['charlie', 'alpha'], array_keys(DefinitionOrdering::sort(defs([
	'alpha' => [Alpha::class, null, [], [Charlie::class]],
	'charlie' => [Charlie::class, null, [], []],
]))));

// before and after together insert a service between two others
Assert::same(['alpha', 'zeta', 'charlie'], array_keys(DefinitionOrdering::sort(defs([
	'alpha' => [Alpha::class, null, [], []],
	'charlie' => [Charlie::class, null, [], []],
	'zeta' => [Zeta::class, null, [Charlie::class], [Alpha::class]],
]))));

// a list of before targets adds one edge each
Assert::same(['zeta', 'alpha', 'bravo'], array_keys(DefinitionOrdering::sort(defs([
	'alpha' => [Alpha::class, null, [], []],
	'bravo' => [Bravo::class, null, [], []],
	'zeta' => [Zeta::class, null, [Alpha::class, Bravo::class], []],
]))));

// an interface reference fans out to every implementation (self-edge skipped)
Assert::same(['zeta', 'alpha', 'bravo'], array_keys(DefinitionOrdering::sort(defs([
	'alpha' => [Alpha::class, null, [], []],
	'bravo' => [Bravo::class, null, [], []],
	'zeta' => [Zeta::class, null, [Orderable::class], []],
]))));

// a reference matching nothing in the set is ignored (soft); falls back to FQCN
Assert::same(['alpha', 'zeta'], array_keys(DefinitionOrdering::sort(defs([
	'alpha' => [Alpha::class, null, [], []],
	'zeta' => [Zeta::class, null, [Charlie::class], []],
]))));

// a cycle throws, naming the tangled services
Assert::exception(
	fn() => DefinitionOrdering::sort(defs([
		'a' => [Alpha::class, null, [Bravo::class], []],
		'b' => [Bravo::class, null, [Alpha::class], []],
	])),
	Nette\DI\ServiceCreationException::class,
	'%a%circular before/after%a%',
);

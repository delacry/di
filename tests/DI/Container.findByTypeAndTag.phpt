<?php declare(strict_types=1);

/**
 * Test: Container::findByTypeAndTag() — precomputed (type, tag) → names index lookup.
 *
 * Powers Container::get($type, $tag) for O(1) resolution; also intended to drive
 * a future bag-of-services autowire (array<string, T> ctor parameters keyed by tag).
 */

use Nette\DI;
use Tester\Assert;


require __DIR__ . '/../bootstrap.php';


interface IFb
{
}

class FbA implements IFb
{
}

class FbB implements IFb
{
}

class FbC implements IFb
{
}


$compiler = new DI\Compiler;
$container = createContainer($compiler, '
services:
	a:
		factory: FbA
		tag: alpha
	b:
		factory: FbB
		tag: beta
	c: FbC
');

// With $tag specified: returns list<string> for that (type, tag)
Assert::same(['a'], $container->findByTypeAndTag(IFb::class, 'alpha'));
Assert::same(['b'], $container->findByTypeAndTag(IFb::class, 'beta'));
Assert::same(['c'], $container->findByTypeAndTag(IFb::class, 'default'));

// Missing tag: empty list
Assert::same([], $container->findByTypeAndTag(IFb::class, 'gamma'));

// Missing type: empty list
Assert::same([], $container->findByTypeAndTag('UnknownType', 'alpha'));

// With $tag null: returns the full tag → names map
$all = $container->findByTypeAndTag(IFb::class);
ksort($all);
Assert::same(
	[
		'alpha' => ['a'],
		'beta' => ['b'],
		'default' => ['c'],
	],
	$all,
);

// Concrete type lookup works too
Assert::same(['a'], $container->findByTypeAndTag(FbA::class, 'alpha'));
Assert::same(['c'], $container->findByTypeAndTag(FbC::class, 'default'));

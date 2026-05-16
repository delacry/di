<?php declare(strict_types=1);

/**
 * Test: Nette\DI\Compiler: #[Inject(tag:)] — tag-aware property, ctor, and inject-method injection.
 *
 * Covers the (type, tag) identity model:
 *   - implicit "default" tag for untagged services
 *   - #[Inject(tag: X)] on properties, ctor params, inject-method params
 *   - polymorphic resolution (concrete vs interface ref both resolve to the same instance)
 *   - ambiguity / missing-tag error messages
 */

use Nette\DI;
use Nette\DI\Attributes\Inject;
use Tester\Assert;


require __DIR__ . '/../bootstrap.php';


interface ICacheT
{
}

class CacheRedisT implements ICacheT
{
}

class CacheFsT implements ICacheT
{
}

class ConsumerPropI
{
	#[Inject(tag: 'doctrine')]
	public ICacheT $cache;
}

class ConsumerPropC
{
	#[Inject(tag: 'doctrine')]
	public CacheRedisT $cache;
}

class ConsumerCtorI
{
	public function __construct(
		#[Inject(tag: 'doctrine')]
		public readonly ICacheT $cache,
	) {
	}
}

class ConsumerCtorC
{
	public function __construct(
		#[Inject(tag: 'doctrine')]
		public readonly CacheRedisT $cache,
	) {
	}
}

class ConsumerInjectMethod
{
	public ICacheT $cache;


	public function injectCache(#[Inject(tag: 'doctrine')] ICacheT $cache): void
	{
		$this->cache = $cache;
	}
}


$compiler = new DI\Compiler;
$compiler->addExtension('inject', new DI\Extensions\InjectExtension);
$container = createContainer($compiler, '
services:
	redis:
		factory: CacheRedisT
		tag: doctrine

	fsCache:
		factory: CacheFsT
		# untagged → implicit tag "default"

	propI:
		factory: ConsumerPropI
		inject: true

	propC:
		factory: ConsumerPropC
		inject: true

	ctorI: ConsumerCtorI

	ctorC: ConsumerCtorC

	injectMethod:
		factory: ConsumerInjectMethod
		inject: true
');

$redis = $container->getService('redis');
$fs = $container->getService('fsCache');

// container Container::get(class, ?tag) resolves to redis by tag
Assert::same($redis, $container->get(CacheRedisT::class, 'doctrine'));
Assert::same($redis, $container->get(ICacheT::class, 'doctrine'));

// no-tag lookup falls back to the untagged "default" service
Assert::same($fs, $container->get(ICacheT::class));
Assert::same($fs, $container->get(CacheFsT::class));

// polymorphic resolution: property ref via interface vs concrete both yield redis
Assert::same($redis, $container->getService('propI')->cache);
Assert::same($redis, $container->getService('propC')->cache);

// polymorphic resolution: ctor param ref via interface vs concrete both yield redis
Assert::same($redis, $container->getService('ctorI')->cache);
Assert::same($redis, $container->getService('ctorC')->cache);

// inject-method param with #[Inject(tag:)]
Assert::same($redis, $container->getService('injectMethod')->cache);

// missing-tag error message
Assert::exception(
	fn() => $container->get(ICacheT::class, 'nonexistent'),
	DI\MissingServiceException::class,
	"Service of type ICacheT with tag 'nonexistent' not found.",
);

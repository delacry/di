# nette/di - delacry fork

A fork of [nette/di](https://github.com/nette/di) that adds **tag-based dependency injection** for services of the same type, so you can register multiple implementations of an interface and pick the right one at the injection site by tag.

## Why this fork exists

[nette/di#321](https://github.com/nette/di/pull/321) - *InjectExtension: added support for injecting services by tags* - has been open against upstream since May 2025 with no movement. This fork picks the feature up, ships it, and extends the model further: a first-class identity tag on every service definition, a new canonical `Container::get()` lookup, NEON `@Type#tag` reference syntax, and an O(1) precomputed `(type, tag)` index on the compiled container.

For everything else about Nette DI - service definitions, factories, decorators, NEON syntax, autowiring rules, extension authoring - see [nette/di's documentation](https://doc.nette.org/dependency-injection). All of it works the same here. This README only covers what's different.

## What's new

### `#[Inject(tag: 'X')]` on properties, constructor parameters, and inject methods

```php
use Nette\DI\Attributes\Inject;

class OrderService
{
    public function __construct(
        #[Inject(tag: 'fast')]
        public readonly CacheInterface $cache,
    ) {}
}
```

Same attribute works on properties:

```php
class OrderService
{
    #[Inject(tag: 'fast')]
    public CacheInterface $cache;
}
```

…and on `inject*()` method parameters:

```php
class OrderService
{
    public function injectCache(#[Inject(tag: 'fast')] CacheInterface $cache): void
    {
        $this->cache = $cache;
    }
}
```

`#[Inject]` on a constructor or inject-method parameter **requires** a tag - untagged parameters are autowired by native type already, so a bare `#[Inject]` there is redundant and throws at compile time.

### Single-string identity tag per service

```neon
services:
    cache.fast:
        factory: App\Cache\RedisCache
        tag: fast

    cache.slow:
        factory: App\Cache\FileSystemCache
        tag: slow

    fallback: App\Cache\NullCache
    # untagged services are implicitly tagged "default"
```

Or via the fluent API:

```php
$builder->addDefinition('cache.fast')
    ->setType(App\Cache\RedisCache::class)
    ->setTag('fast');
```

The single-string tag is intentionally distinct from upstream's existing multi-key `tags: { … }` metadata bag (which is unchanged and still works). Tags here are an *identity discriminator* used together with the service type; `tags:` is a free-form metadata bag used by extensions like `LocatorDefinition`'s `tagged:` selector.

### `Container::get($type, ?$tag)` canonical lookup

```php
$cache = $container->get(CacheInterface::class, 'fast'); // RedisCache
$cache = $container->get(CacheInterface::class);          // NullCache (untagged → default)
```

Backed by a precomputed `array<class-string, array<tag, list<name>>>` index baked into the generated container at compile time. The hot path is one hash lookup + one `count()` + one `getService()` - **~108 ns/op** on a 10-implementation interface with tag filtering, ~9.2M ops/s on a single core (measured on PHP 8.4, no opcache JIT). For comparison, plain `getService($name)` by direct name lookup measures ~40 ns/op.

`get()` throws `MissingServiceException` on miss or ambiguity. For a nullable miss, use `getOrNull($type, ?$tag)` - same fast path, returns `null` instead of throwing when nothing matches. Ambiguity (multiple services match) still throws on `getOrNull()` because it's a programming error, not a "does this exist?" question.

```php
$cache = $container->getOrNull(CacheInterface::class, 'optional');  // null if not registered
```

### NEON `@Type#tag` reference syntax

```neon
services:
    orderService:
        factory: OrderService
        arguments:
            cache: @App\Cache\CacheInterface#fast
```

Any reference value containing a backslash is treated as a type reference (this is upstream Nette's rule, not new in the fork), so namespaced FQNs like `@App\Cache\CacheInterface` work as-is. A leading `\` is only needed for global-namespace types (`@\CacheInterface#fast`) to disambiguate them from a service-name reference. NEON's own tokenizer accepts the `#tag` suffix unquoted, so no escaping required.

### Polymorphic resolution

All three of these return the same instance when `cache.fast` is the only `'fast'`-tagged service implementing `CacheInterface`:

```php
$container->get(CacheInterface::class, 'fast');
$container->get(RedisCache::class, 'fast');
$container->get(RedisCache::class); // RedisCache is the only one
```

The autowiring index registers each service under all its parent classes and interfaces; the tag filter narrows the candidates to the matching identity.

### Tag-keyed bag-of-services autowire: `array<string, T>`

A constructor parameter PHPDoc-typed as `array<string, T>` is autowired as a tag-keyed map of every autowired service implementing `T`:

```php
class PoolRegistry
{
    /**
     * @param array<string, CacheInterface> $pools
     */
    public function __construct(
        public readonly array $pools,
    ) {}
}
```

Given the services above, `$pools` is filled with `['fast' => $redisCache, 'slow' => $fsCache, 'default' => $fallback]`. The generated container emits the array literal at compile time - no runtime aggregation.

The pre-existing `T[]`, `list<T>` and `array<int, T>` patterns continue to autowire as numerically-keyed lists, unchanged from upstream. If two services of the same type share the same identity tag, the `array<string, T>` autowire throws at compile time (the tag → service mapping must be unambiguous).

### `Container::findByTypeAndTag($type, ?$tag)`

Returns service names matching `(type, tag)` from the precomputed index. With `$tag` null, returns the full `tag → names` map for the type. Useful for collecting all implementations of an interface broken down by tag.

```php
$container->findByTypeAndTag(CacheInterface::class, 'fast');
// → ['cache.fast']

$container->findByTypeAndTag(CacheInterface::class);
// → ['fast' => ['cache.fast'], 'slow' => ['cache.slow'], 'default' => ['fallback']]
```

## What's removed

- Legacy `@inject` docblock annotation fallback in `InjectExtension` (use the `#[Inject]` attribute)
- Legacy `@var` type-hint fallback for inject properties (use native type hints)
- `Helpers::parseAnnotation()` (no remaining callers after the @inject strip)
- Pre-3.0 class aliases: `Nette\DI\ServiceDefinition`, `Nette\DI\Statement`, `Nette\DI\Config\IAdapter`
- `Definition::generateMethod()` (callers updated to use `Definition::generateCode()`)
- `Definition::isAutowired()` (use `getAutowired()`)

`Definition::setClass()` / `getClass()` are kept as deprecated wrappers because tracy/tracy's DI bridge still calls them.

## Deprecated (still functional)

- `Container::getService($name)` - `@deprecated` docblock points at `Container::get($type, $tag)`. Docblock-only deprecation; no runtime `E_USER_DEPRECATED` is emitted, since `get()` itself calls `getService()` internally.

## Backward compatibility

This fork keeps the engine permissive:

- `addDefinition($name, …)` with a non-null name still works
- `services: { foo: Bar }` NEON keys still register `foo` as the service name
- All existing tests pass (162 in total)

Tag-aware features are strictly additive. Calling code that doesn't use tags behaves exactly like upstream nette/di v3.3.

## Status

- Based on upstream `nette/di` v3.3 (commit `d16957a`).
- Not tracking upstream - upstream branches force-push, so changes from upstream are cherry-picked when needed.
- Tests: 164 pass (was 157 on the v3.3 baseline; +7 new for the tag features and the `array<string, T>` bag autowire).
- **PHP requirement: 8.4 – 8.5** (bumped from upstream's 8.2 – 8.5; the fork uses asymmetric property visibility for `Definition::$tag` and other 8.4-only conveniences). If you need 8.2 or 8.3 compatibility, stay on upstream `nette/di`.

## Documentation

For installation, service definitions, factories, decorators, NEON syntax, autowiring rules, extension authoring - read [nette/di's documentation](https://doc.nette.org/dependency-injection). Only the additions above are fork-specific.

## License

BSD-3-Clause / GPL-2.0 / GPL-3.0 (same as upstream nette/di).

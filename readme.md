# nette/di - delacry fork

A fork of [nette/di](https://github.com/nette/di) that adds **tag-based dependency injection** for services of the same type, so you can register multiple implementations of an interface and pick the right one at the injection site by tag.

## Why this fork exists

[nette/di#321](https://github.com/nette/di/pull/321) - *InjectExtension: added support for injecting services by tags* - has been open against upstream since May 2025 with no movement. This fork picks the feature up, ships it, and extends the model further: a first-class identity tag on every service definition, a new canonical `Container::get()` lookup, NEON `@Type#tag` reference syntax, an O(1) precomputed `(type, tag)` index on the compiled container, deterministic ordering of autowired collections via per-definition `priority`/`before`/`after`, and priority-ordered service decoration (onion chains).

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

### Deterministic collection ordering: `priority` / `before` / `after`

When a parameter autowires a *collection* of a type (`T[]`, `list<T>`, `array<int, T>`, or the tag-keyed `array<string, T>` above), the collected services come back in registration order - which, for attribute- or filesystem-discovered services, is not reproducible across machines. Each `Definition` carries optional ordering metadata that makes the order deterministic:

```php
$builder->addDefinition('appRouter')
    ->setType(App\AppRouter::class)
    ->setPriority(100);                    // higher is collected first

$builder->addDefinition('adminRouter')
    ->setType(Admin\AdminRouter::class)
    ->setBefore([App\AppRouter::class])    // relative: collected before AppRouter
    ->setAfter([Core\CoreRouter::class]);  // …and after CoreRouter
```

The order is resolved by `Nette\DI\DefinitionOrdering` and applied wherever a collection of a type is assembled - autowired collection parameters as well as direct `ContainerBuilder::findByType()` / `findAutowired()` calls all return services in this order:

- **`priority`** (`?int`, default `null` → treated as `0`) - an absolute tier; higher is collected first.
- **`before` / `after`** (`list<class-string>`) - relative, hard constraints against other collected services. They become edges in a topological sort; `priority`, then the service's type FQCN, then its name break ties among otherwise-unordered services.

Rules:

- A collection in which **nothing** carries ordering metadata is returned in registration order, untouched - existing code and other Nette DI users see no behavioural change.
- `before`/`after` match by `is_a`, so referencing an **interface** orders this service against every collected implementation of it.
- A reference that matches **no** collected service is silently ignored - that's how a package can declare "I run before X" when X may not be installed.
- A **cycle** (A before B, B before A) throws `ServiceCreationException` at compile time, naming the tangled services.

These are storage-only primitives: the engine reads them, but never sets them itself. A higher layer (e.g. an attribute-driven compiler pass) decides what they mean and calls the setters - the same division of labour as the multi-key `tags:` bag.

### Service decoration: priority-ordered onion chains

A *decorator* implements the same type as the service it wraps, receives the inner instance as a constructor argument, and takes over that service's slot for autowiring. `Definition::decorate()` declares the relationship:

```php
$builder->addDefinition()
    ->setType(App\Cache\RedisCache::class);

$builder->addDefinition()
    ->setType(App\Cache\LoggingCache::class)   // also implements CacheInterface
    ->decorate(CacheInterface::class);      // wrap whatever serves CacheInterface
```

Now `get(CacheInterface::class)` returns the `LoggingCache`, constructed with the `RedisCache` as its `CacheInterface` argument. The wrapped service stays registered but no longer wins autowiring.

Several decorators of one slot stack into an onion ordered by decoration **priority - highest is outermost** (the layer consumers receive):

```php
$builder->addDefinition()->setType(TimingCache::class)
    ->decorate(CacheInterface::class, priority: 100);   // outermost
$builder->addDefinition()->setType(LoggingCache::class)
    ->decorate(CacheInterface::class, priority: 0);     // inner

// get(CacheInterface::class) === TimingCache → LoggingCache → RedisCache
```

This decoration priority is its **own axis**, separate from the collection `priority` above - it orders the onion, not collection membership, so a deeply nested decorator never shifts where the service sorts in an `array<string, T>` / `list<T>` collection.

#### Decorating a tagged slot

`decorate($type, $tag)` wraps one `(type, tag)` identity, leaving the other tagged services of that type alone:

```php
$builder->addDefinition()->setType(RedisCache::class)->setTag('fast');
$builder->addDefinition()->setType(FileSystemCache::class)->setTag('slow');

$builder->addDefinition()->setType(AuditedCache::class)
    ->decorate(CacheInterface::class, 'fast');

$container->get(CacheInterface::class, 'fast'); // AuditedCache wrapping RedisCache
$container->get(CacheInterface::class, 'slow'); // FileSystemCache, untouched
```

The outermost wrapper inherits the slot's identity tag, so `get($type, $tag)` transparently returns the chain. Because a definition carries a single identity tag, all of one definition's `decorate()` targets must share one tag.

#### Decorating several types at once

A decorator implementing more than one interface wraps each by calling `decorate()` per type - handy when one underlying service fills several roles:

```php
$builder->addDefinition()->setType(FileIo::class);       // implements Reader, Writer
$builder->addDefinition()->setType(TracingIo::class)     // implements Reader, Writer
    ->decorate(Reader::class)
    ->decorate(Writer::class);
// get(Reader::class) and get(Writer::class) both return the one TracingIo, which
// receives the single FileIo through a `Reader&Writer` constructor parameter.
```

#### Which constructor parameter receives the inner

The weaver injects the inner into the parameter **already bound to the slot's service** - by a NEON `@Type#tag` argument, or by an extension that resolved a tagged parameter (e.g. `#[Inject(tag:)]`) into a `Reference`:

```php
final class AuditedCache implements CacheInterface
{
    public function __construct(
        #[Inject(tag: 'metrics')] private CacheInterface $metrics, // a collaborator, left alone
        #[Inject(tag: 'fast')]    private CacheInterface $inner,   // the decorated 'fast' slot
    ) {}
}
```

If no parameter is pre-bound to the slot, the first unbound parameter whose type the inner satisfies is used (an exact match on the decorated type ahead of a supertype or intersection). The weaver reads the **resolved argument**, not the attribute - so `#[Inject]`, any injection attribute a downstream extension resolves, or a plain NEON reference all produce the same `Reference` it reads, and the engine never has to know about the attribute.

#### Ignoring the inner = full replacement

A decorator that has **no parameter accepting the inner** (or no constructor at all) *replaces* the slot instead of wrapping it: the base is still buried and the decorator answers the (type, tag) slot, but nothing is wired into it. This mirrors Symfony, where ignoring the decorated service is a complete replacement - the clean way to swap a framework service for your own without registering a second service of the same type (which would make the slot ambiguous):

```php
// takes over CacheInterface entirely; never touches the previous implementation
final class MyCache implements CacheInterface
{
    public function __construct(private Clock $clock) {}   // no CacheInterface param
}
```

#### Mechanics

- Chains are woven by `Nette\DI\Decoration` during `ContainerBuilder::complete()`; `get()` / `getByType()` / autowiring all see only the outermost wrapper.
- The wrapped base and any buried decorators are dropped from autowiring (still registered, referenced internally by the chain); a head that is itself wrapped in another slot is autowiring-restricted to the types it heads, so it can't win a slot it sits inside.
- Decorating a type with no base service - or with more than one base in the slot - throws `ServiceCreationException` at compile time.
- Like `priority`/`before`/`after`, `decorate()` is a storage-only primitive: the engine reads it, a higher layer (e.g. an `#[AsDecorator]` compiler pass) sets it.

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
- Collection ordering is opt-in: a collection with no `priority`/`before`/`after` is returned in registration order, exactly as before
- All existing tests pass

Tag-aware features and the ordering primitives are strictly additive. Calling code that doesn't use them behaves exactly like upstream nette/di v3.3.

## Status

- Based on upstream `nette/di` v3.3 (commit `d16957a`).
- Not tracking upstream - upstream branches force-push, so changes from upstream are cherry-picked when needed.
- Tests: 182 pass (was 157 on the v3.3 baseline; the tag features, the `array<string, T>` bag autowire, deterministic collection ordering, and service decoration are the additions).
- **PHP requirement: 8.4 – 8.5** (bumped from upstream's 8.2 – 8.5; the fork uses asymmetric property visibility for `Definition::$tag` and other 8.4-only conveniences). If you need 8.2 or 8.3 compatibility, stay on upstream `nette/di`.

## Documentation

For installation, service definitions, factories, decorators, NEON syntax, autowiring rules, extension authoring - read [nette/di's documentation](https://doc.nette.org/dependency-injection). Only the additions above are fork-specific.

## License

BSD-3-Clause / GPL-2.0 / GPL-3.0 (same as upstream nette/di).

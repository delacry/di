# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**delacry fork of Nette DI** — a compiled Dependency Injection Container for PHP, based on [nette/di](https://github.com/nette/di) v3.3 (commit `d16957a`). The fork ships [PR #321](https://github.com/nette/di/pull/321) (tag-based injection — open against upstream since May 2024 with no movement) plus a broader (type, tag) identity model layered on top of upstream's existing autowiring.

**Key characteristics:**
- Compiled container generates optimized PHP code for maximum performance
- Full autowiring support with type-based dependency resolution
- **Tag-based identity model** on top of upstream's autowiring: every service has a single-string identity tag (`"default"` if unset), and `(type, tag)` together uniquely select a service
- O(1) precomputed `(type, tag) → names` index on the generated container — ~108 ns/op tag-filtered lookups
- NEON configuration format with the fork's `@Type#tag` reference syntax
- `#[Inject(tag:)]` attribute on properties, constructor parameters, and inject-method parameters
- Supports PHP 8.2 – 8.5
- ~5,900 lines of production code

**Not tracking upstream.** Upstream branches force-push (the original PR #321's commits got rewritten when dg moved them between branches). Pulling fixes from upstream is done by cherry-picking specific commits, not by merge or rebase.

## Fork's commits (on top of upstream/v3.3 tip `d16957a`)

```
ContainerBuilder: addDefinition() name parameter defaults to null
tag-based service identity with #[Inject(tag:)] support
removed obsolete BC compatibility layer
```

Use `git log d16957a..` to see the actual hashes; the list above is order-of-application.

## Tag identity model — what's added on top of upstream

**Tag identity:**
- `Definition::$tag` (`?string`, null = implicit `"default"`)
- `Definition::setTag(?string $tag): static` — fluent setter
- `Definition::getTag(): string` — always returns a string (`"default"` when null)
- `Definition::DefaultTag = 'default'` constant
- NEON config key: `tag: <string>` on services, accessors, factories, locators, imported services
- This is **distinct from** the existing multi-key `Definition::$tags` metadata array (which is unchanged). The new identity tag and the legacy `tags` bag are separate concepts; `getTagValue(string $name): mixed` is the renamed accessor for the legacy bag (was `getTag(string $name)` — renamed to free the no-arg `getTag(): string` for identity).

**Runtime container API:**
- `Container::get(string $type, ?string $tag = null): object` — canonical lookup. Always throws on miss/ambiguity. O(1) via the precomputed index.
- `Container::getByType($type, $throw, ?$tag)` — same shape with optional `$throw=false` for nullable returns and explicit `$tag`.
- `Container::findByTypeAndTag($type, ?$tag)` — index introspection.
- `array<string, T>` PHPDoc-typed constructor parameters get autowired as a tag→service map (via `Resolver::isArrayOf` extension). Compile-time error if two services share an identity tag for the same type. The pre-existing `T[]`/`list<T>`/`array<int, T>` patterns are unchanged — they still autowire as numerically-keyed lists.
- `Container::$byTypeAndTag` — `array<class-string, array<tag, list<name>>>`, populated from the high-priority autowiring slot at compile time.
- `Container::$serviceTags` — `array<service_name, string>`, only populated for services whose identity tag is non-default (saves bytes in the generated container).
- `Container::getService($name)` — kept for back-compat with name-based registration, but `@deprecated` (docblock-only — no runtime `E_USER_DEPRECATED`, since `get()` calls `getService()` internally).

**Inject attribute:**
- `Nette\DI\Attributes\Inject` accepts `?string $tag` and targets `TARGET_PROPERTY | TARGET_PARAMETER`.
- `#[Inject(tag: 'X')]` on a **property** = tag-aware setter injection (requires service-level `inject: true` to apply).
- `#[Inject(tag: 'X')]` on a **constructor parameter** = tag-aware autowiring override for that param (applies regardless of `inject: true` — it's a per-param directive, not a service-level opt-in).
- `#[Inject(tag: 'X')]` on an **inject-method parameter** = tag-aware autowiring for the method call (requires `inject: true`).
- `#[Inject]` without a tag on a constructor or inject-method param throws at compile time — bare `#[Inject]` there is redundant since untagged params are autowired by native type already.

**NEON `@Type#tag` reference syntax:**
- `Helpers::filterArguments` regex extended to `^@([\w\\]+)(?:#(\w+))?$`.
- `@\Foo\Bar#doctrine` parses as `Reference(value='Foo\\Bar', tag='doctrine')`.
- The leading `\` is the standard Nette convention separating type-refs from name-refs.
- No nette/neon fork needed — `#` is only a NEON comment marker at column 0 or after whitespace, so the `#tag` suffix is accepted unquoted.

**`Reference` carries the tag through compile:**
- `Reference::__construct(string $value, ?string $tag = null)`
- `Reference::fromType($value, ?$tag)` — type-reference factory accepts tag.
- `Reference::getTag(): ?string` — getter (null for name-refs and untagged type-refs).
- `Resolver::getByType($type, ?$tag)` and `Resolver::normalizeReference()` propagate the tag through resolution.

**Compile-time autowiring:**
- `Autowiring::getByType($type, $throw, ?$tag)` filters candidates by `Definition::getTag()`. Untagged services match the implicit `"default"` tag. When `$tag` is null and multiple candidates exist, prefers the `"default"`-tagged subset to break ambiguity (matches upstream's null-tag behavior).
- `ContainerBuilder::getByType($type, $throw, ?$tag)` passes through to autowiring.

## What was removed

- `src/compatibility.php` — pre-3.0 class aliases (`Nette\DI\ServiceDefinition`, `Nette\DI\Statement`, `Nette\DI\Config\IAdapter`).
- `class_exists()` warmup calls in `Definitions\Statement` and `Definitions\ServiceDefinition` that triggered the deleted compat layer.
- `Definition::generateMethod()` — wrapper for `generateCode()`. Callers (PhpGenerator, FactoryDefinition) updated to call `generateCode()` directly.
- `Definition::isAutowired()` — wrapper for `getAutowired()`. No callers anywhere.
- `Helpers::parseAnnotation()` — used only by the deleted `@inject` docblock fallback. Test deleted with it.
- `InjectExtension`'s `@inject` docblock annotation fallback and `@var`-as-type fallback. Native attributes and native types only from here on.

**Kept on purpose** (still load-bearing in our context):
- `Definition::setClass()` / `getClass()` — tracy/tracy's DI bridge still calls them.
- `Config\Helpers` — `DefinitionSchema` still uses `takeParent()`.
- `ContainerBuilder::formatPhp()` — `DIExtension`'s Tracy integration uses it.
- `Compiler::getConfig()`, `Container::$parameters`, `CompilerExtension::validateConfig()` — still useful test/introspection points.

## Deferred (not in this fork — intended for consumer code)

The original tag-identity vision included two more constraints that didn't land in the engine fork:

- **`addDefinition($name)` throwing on non-null name.** 47 test files + every Nette ecosystem extension passes names to `addDefinition()`. Keeping the engine permissive lets that code keep working; consumers who want the strict rule enforce it at config-parse time in their own bundle / config-validation layer before reaching `addDefinition()`.
- **NEON `services: { foo: Bar }` keys becoming aliases rather than identity.** Same reason — would cascade through `ServicesExtension`, `removeDefinition`, alteration logic, and every test asserting specific service names. Consumers wanting this enforce it at their own config-validation layer.

Both constraints are *configurable on top of* the engine — the deprecation markers and `findByTypeAndTag` helper signal the direction without breaking BC.

## Essential Commands

### Running Tests

Tests use Nette Tester (not PHPUnit) with `.phpt` file format. **Note:** the test suite needs `php.ini` (top of repo) loaded explicitly via `-c` because Tester spawns subprocesses with `-n` (no system php.ini), and the suite requires the `tokenizer` extension which the project-local `php.ini` enables.

```bash
# Run all DI tests
vendor/bin/tester -p php -c php.ini tests/DI

# Run a single test
vendor/bin/tester -p php -c php.ini tests/DI/Container.findByTypeAndTag.phpt

# With info / parallelism / output
vendor/bin/tester -p php -c php.ini -s tests/DI
vendor/bin/tester -p php -c php.ini -j 4 tests/DI    # 4 parallel processes (default 8)
```

The `tests/types/TypesTest.phpt` failure on baseline is pre-existing (missing `Nette\PHPStan\Tester\TypeAssert` class — not in vendor). Ignore it.

### Static Analysis

```bash
composer phpstan
```

## Test Infrastructure

### Test File Structure

All tests use `.phpt` format. Bootstrap registers the `createContainer()` helper:

```php
<?php declare(strict_types=1);

use Nette\DI;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';

$compiler = new DI\Compiler;
$compiler->addExtension('inject', new DI\Extensions\InjectExtension);  // tag-aware inject needs this
$container = createContainer($compiler, '
services:
    cache.fast:
        factory: RedisCache
        tag: fast
');

Assert::type(RedisCache::class, $container->get(CacheInterface::class, 'fast'));
```

**Important: `InjectExtension` is not auto-registered by the bare `Compiler`.** The default `Compiler` only registers `ServicesExtension` + `ParametersExtension`. If a test exercises `#[Inject]` or `#[Inject(tag:)]`, the test must explicitly add `InjectExtension`.

### Test Helpers (from bootstrap.php)

- `createContainer($source, $config = null, array $params = [])` — compiles and instantiates. `$source` is `Compiler` or `ContainerBuilder`; `$config` is a NEON string or file path. Generated code lands at `tests/tmp/{pid}/code.php` for inspection.
- `getTempDir()` — per-process scratch dir at `tests/tmp/{pid}/`, garbage collected on next run.
- `Notes::add()` / `Notes::fetch()` — debug-message channel.

### Common Test Patterns

Tag-aware tests live in:
- `tests/DI/Autowiring.tag.phpt` — `ContainerBuilder::getByType($type, $throw, $tag)` resolution rules.
- `tests/DI/InjectExtension.tags.phpt` — property + ctor + inject-method polymorphic resolution.
- `tests/DI/InjectExtension.tags.errors.phpt` — bare `#[Inject]` on ctor/inject param throws; ambiguity errors.
- `tests/DI/NeonAdapter.tagRef.phpt` — `@\Type#tag` NEON syntax end-to-end.
- `tests/DI/Container.findByTypeAndTag.phpt` — precomputed index introspection.

## Architecture Overview

### Compilation Flow

Standard upstream flow with one new step:

1. **Load** — Configuration files loaded and merged (`Config\Loader`).
2. **Extensions** — `loadConfiguration()` on each extension; `ServicesExtension` registers services from NEON.
3. **Resolve** — `ContainerBuilder::complete()` resolves types via `Resolver`.
4. **Generate** — `PhpGenerator::generate()` emits the container class; `ContainerBuilder::exportMeta()` builds the `$wiring`, `$tags`, `$serviceTags`, and **`$byTypeAndTag`** properties (the last one new in this fork — populated in a second pass over the high-priority autowiring slot).
5. **Runtime** — Compiled container instantiated; `Container::get($type, $tag)` uses the precomputed index for O(1) resolution.

### Core Components

- **`Container.php`** — Runtime. `get($type, ?$tag)`, `getByType()`, `getService()` (`@deprecated`), `findByTypeAndTag()`, `findByTag()`. Holds `$wiring`, `$tags`, `$serviceTags`, `$byTypeAndTag`.
- **`Compiler.php`** — Orchestrates compilation. Default extensions: `ServicesExtension` + `ParametersExtension` only. `InjectExtension` is opt-in.
- **`ContainerBuilder.php`** — `addDefinition($name, ?Definition)`, `getByType($type, $throw, ?$tag)`, `exportMeta()` (emits all the runtime metadata, including the new `byTypeAndTag` index).
- **`Resolver.php`** — `getByType($type, ?$tag)` returns a `Reference` carrying the tag; `normalizeReference()` propagates the tag through type-to-name resolution.
- **`Autowiring.php`** — `getByType($type, $throw, ?$tag)` filter by `Definition::getTag()`. Default-tag fallback for ambiguity.
- **`PhpGenerator.php`** — Generates `createService<Name>()` methods. `getMethodName()` maps `.` → `__` in names.

### Service Definitions (`src/DI/Definitions/`)

All definition types inherit `Definition::$tag` (the identity tag).

- **`ServiceDefinition`** — Standard service.
- **`FactoryDefinition`** — Factory from interface. `applyInjectAttributesToConstructor` is called on its `resultDefinition`.
- **`AccessorDefinition`** — Lazy getter.
- **`LocatorDefinition`** — Multi-service locator. Uses upstream's `tagged:` selector against the legacy `$tags` metadata bag, **not** the new identity tag.
- **`ImportedDefinition`** — External service reference.
- **`Reference`** — `__construct(string $value, ?string $tag = null)`. `fromType($value, ?$tag)`. `getTag(): ?string`.
- **`Statement`** — Callable/function call.

### Configuration System (`src/DI/Config/`)

- **`NeonAdapter.php`** — Processes NEON. Tag-aware reference parsing happens downstream in `Helpers::filterArguments` (regex updated to `^@([\w\\]+)(?:#(\w+))?$`), not in NeonAdapter itself.
- **`Loader.php`** — File loading and merging.
- **`Helpers.php` (Config)** — `takeParent()` and `PREVENT_MERGING` constant — kept because `DefinitionSchema` still uses them.

### Extension System (`src/DI/Extensions/`)

- **`ServicesExtension`** — Reads NEON `services:`, applies the new `tag:` key via `Definition::setTag()`.
- **`InjectExtension`** — Tag-aware `#[Inject]` handling. Three application sites:
  - **Constructor parameter:** `applyInjectAttributesToConstructor()` — runs for **all** ServiceDefinitions regardless of `inject: true`. Rewrites the creator's argument map to use tagged References.
  - **Property:** processed inside `updateDefinition()` — gated by `inject: true` (legacy Nette convention because it adds setup statements).
  - **Inject method parameter:** `buildInjectMethodStatement()` — also gated by `inject: true`.
- **`DefinitionSchema`** — Schema for NEON `services:` block. Includes the new `'tag' => Expect::type('string|null')` field on every service kind (service, accessor, factory, locator, imported).
- **`ParametersExtension`** / **`SearchExtension`** / **`DecoratorExtension`** / **`DIExtension`** / **`ExtensionsExtension`** — unchanged from upstream.

### Tracy Integration (`src/Bridges/DITracy/`)

`ContainerPanel` displays services, tags, wiring info. Unchanged from upstream; the new `$serviceTags` and `$byTypeAndTag` index aren't exposed yet but the data is there if someone wants to wire it into the panel.

## NEON Configuration Syntax

Everything from upstream nette/di works — see [nette/di's docs](https://doc.nette.org/dependency-injection) for the full reference. Fork additions below.

### Tag identity

```neon
services:
    cache.fast:
        factory: App\Cache\RedisCache
        tag: fast

    cache.slow:
        factory: App\Cache\FileSystemCache
        tag: slow

    fallback: App\Cache\NullCache
    # untagged → implicit tag "default"
```

### `@Type#tag` reference syntax

```neon
services:
    orderService:
        factory: OrderService
        arguments:
            cache: @\App\Cache\CacheInterface#fast
```

The leading `\` is the standard convention separating type-refs from name-refs. Without it, `@CacheInterface#fast` parses as the *name* `CacheInterface` (with tag `fast`). NEON natively accepts the `#tag` suffix unquoted.

## Autowiring Behavior (delta from upstream)

Standard upstream rules apply (exactly-one-of-type required for autowiring, `autowired: false` to exclude, narrowing via `autowired: SomeType`, etc.). The fork adds:

- Each service has an **identity tag** alongside its type. The default tag is `"default"` if unset.
- `Container::get($type, $tag)` and `getByType($type, $throw, $tag)` filter candidates by `(type, tag)`. With `$tag === null`, multi-candidate ambiguity is broken by preferring `"default"`-tagged services.
- Polymorphic resolution works because the index registers each service under all its parent classes and interfaces.

Example:

```neon
services:
    redis:
        factory: RedisCache
        tag: doctrine

    fs:
        factory: FileSystemCache
        # untagged → "default"
```

```php
$container->get(CacheInterface::class);              // → FileSystemCache (untagged default)
$container->get(CacheInterface::class, 'doctrine');  // → RedisCache
$container->get(RedisCache::class, 'doctrine');      // → same RedisCache instance (polymorphic)
$container->get(CacheInterface::class, 'missing');   // throws MissingServiceException
```

## Extension Development

Same lifecycle as upstream (`getConfigSchema()`, `loadConfiguration()`, `beforeCompile()`, `afterCompile()`, `$initialization`). The key extension-author thing to know about this fork:

- If your extension cares about identity tags, read them via `$def->getTag()` (returns `string`, `"default"` if unset).
- To filter the container builder's services by identity tag, walk `$builder->getDefinitions()` and check `$def->getTag()`. There is no `findByIdentityTag()` helper at the builder level — only at the runtime `Container::findByTypeAndTag()`.
- The legacy `tags:` metadata bag (multi-key, with values) is unchanged. `Definition::getTagValue($name)` (renamed from `getTag()`) gives single-key access; `getTags()` returns the whole bag.

## Important Files

- `readme.md` — fork-focused overview, what's added on top of nette/di.
- `src/DI/Container.php` — runtime; the `get()` / `getByType()` / `findByTypeAndTag()` API.
- `src/DI/Autowiring.php` — `getByType($type, $throw, $tag)` resolution rules.
- `src/DI/Extensions/InjectExtension.php` — `#[Inject(tag:)]` handling at all three sites (ctor, property, inject method).
- `src/DI/ContainerBuilder.php::exportMeta()` — where the runtime metadata (including `$byTypeAndTag`) gets baked.
- `tests/DI/Autowiring.tag.phpt`, `InjectExtension.tags.phpt`, `Container.findByTypeAndTag.phpt`, `NeonAdapter.tagRef.phpt` — the new tag-feature tests.

## Code Style

Follows Nette Coding Standard — strict types in every file, PascalCase classes, camelCase methods/properties, two empty lines between methods, exceptions in `exceptions.php`, natural-language exception messages.

## Common Gotchas

- **`InjectExtension` is not auto-registered.** Tests using `#[Inject]` must `$compiler->addExtension('inject', new InjectExtension)` explicitly.
- **NEON `@Type#tag` requires leading `\`** for type-refs — `@CacheInterface#fast` is a *name* reference; `@\CacheInterface#fast` is a *type* reference.
- **`Definition::getTag()` has two overlapping meanings.** The no-arg version `getTag(): string` returns the **identity tag** (new). The one-arg version `getTagValue(string $name): mixed` (renamed from `getTag(string $name)` in this fork) returns a **metadata value** from the legacy `tags` bag. If you see old code calling `$def->getTag('something')`, it needs migration to `getTagValue('something')`.
- **Bare `#[Inject]` on a ctor/inject-method param throws.** Untagged params are autowired by native type already; bare `#[Inject]` there has no meaning and is rejected at compile time. Use `#[Inject(tag: '…')]` or remove the attribute.
- **`Compiler::getConfig()` carries an `@deprecated` upstream tag** but is still used by 9 tests for config introspection. Don't aggressively remove or you'll break a lot of tests for no gain.
- **`#[\Deprecated]` attribute is not used** for the deprecation markers in this fork — it would trigger `E_USER_DEPRECATED` at runtime on PHP 8.4, including from our own internal callers. Only `@deprecated` docblock comments are used.

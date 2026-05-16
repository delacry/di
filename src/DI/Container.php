<?php declare(strict_types=1);

/**
 * This file is part of the Nette Framework (https://nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

namespace Nette\DI;

use Nette;
use Nette\DI\Definitions\Definition;
use function array_filter, array_flip, array_key_exists, array_keys, array_map, array_merge, array_search, array_values, class_exists, count, get_class_methods, implode, interface_exists, is_a, is_object, natsort, sprintf, str_replace, ucfirst;


/**
 * The dependency injection container default implementation.
 */
class Container
{
	/**
	 * @var mixed[]
	 * @deprecated use Container::getParameter() or getParameters()
	 */
	public $parameters = [];

	/** @var string[]  alias => service name */
	protected array $aliases = [];

	/** @var array<string, array<string, mixed>>  tag name => (service name => tag value) */
	protected array $tags = [];

	/**
	 * Identity tag of each non-default-tagged service. Untagged services
	 * are omitted; their tag is implicitly Definition::DefaultTag.
	 * @var array<string, string>  service name => identity tag
	 */
	protected array $serviceTags = [];

	/**
	 * Precomputed O(1) lookup index for get($type, $tag). For each registered
	 * type (incl. parent classes and interfaces), maps each identity tag to
	 * the list of service names matching (type, tag). Most lists have one
	 * entry; multi-entry lists indicate multiple concrete implementations
	 * share an interface and tag — that's only an error at lookup time.
	 * @var array<class-string, array<string, list<string>>>
	 */
	protected array $byTypeAndTag = [];

	/** @var array<class-string, array<int, list<string>>>  type => (high/low/no => service names) */
	protected array $wiring = [];

	/** @var object[]  service name => instance */
	private array $instances = [];

	/** @var array<string, true>  circular reference detector */
	private array $creating;

	/** @var array<string, int> */
	private array $methods;

	/** @var array<string, \Closure(): object>  service name => factory */
	private array $factories = [];


	/** @param  mixed[]  $params */
	public function __construct(array $params = [])
	{
		$this->parameters = $params + $this->getStaticParameters();
		$this->methods = array_flip(get_class_methods($this));
	}


	/** @return mixed[] */
	public function getParameters(): array
	{
		return $this->parameters;
	}


	/**
	 * Returns a parameter value, loading it dynamically if not yet initialized.
	 */
	public function getParameter(string|int $key): mixed
	{
		if (!array_key_exists($key, $this->parameters)) {
			$this->parameters[$key] = $this->preventDeadLock("%$key%", fn() => $this->getDynamicParameter($key));
		}
		return $this->parameters[$key];
	}


	/** @return mixed[] */
	protected function getStaticParameters(): array
	{
		return [];
	}


	protected function getDynamicParameter(string|int $key): mixed
	{
		throw new Nette\InvalidStateException(sprintf("Parameter '%s' not found. Check if 'di › export › parameters' is enabled.", $key));
	}


	/**
	 * Adds the service or its factory to the container.
	 * @param  object  $service  service or its factory
	 */
	public function addService(string $name, object $service): static
	{
		$name = $this->aliases[$name] ?? $name;
		if (isset($this->instances[$name])) {
			throw new Nette\InvalidStateException(sprintf("Service '%s' already exists.", $name));
		}

		if ($service instanceof \Closure) {
			$rt = Nette\Utils\Type::fromReflection(new \ReflectionFunction($service));
			$type = $rt ? Helpers::ensureClassType($rt, 'return type of closure') : '';
		} else {
			$type = $service::class;
		}

		if (isset($this->methods[self::getMethodName($name)])
			&& ($expectedType = $this->getServiceType($name))
			&& !is_a($type, $expectedType, allow_string: true)
		) {
			throw new Nette\InvalidArgumentException(sprintf(
				"Service '%s' must be instance of %s, %s.",
				$name,
				$expectedType,
				$type ? "$type given" : 'add typehint to closure',
			));
		}

		if ($service instanceof \Closure) {
			$this->factories[$name] = $service;
		} else {
			$this->instances[$name] = $service;
		}

		return $this;
	}


	/**
	 * Removes a service instance from the container.
	 */
	public function removeService(string $name): void
	{
		$name = $this->aliases[$name] ?? $name;
		unset($this->instances[$name]);
	}


	/**
	 * Returns the service instance. If it has not been created yet, it creates it.
	 *
	 * Name-based lookup. Prefer Container::get($type, $tag) for tag-aware resolution —
	 * it goes through an O(1) precomputed (type, tag) → name index. This method stays
	 * available for back-compat with code that registered services by explicit name
	 * (e.g. NEON `services: { foo: ... }`).
	 *
	 * @deprecated use Container::get($type, $tag) — name-based lookup is being phased out
	 * @throws MissingServiceException
	 */
	public function getService(string $name): object
	{
		if (!isset($this->instances[$name])) {
			if (isset($this->aliases[$name])) {
				return $this->getService($this->aliases[$name]);
			}

			$this->instances[$name] = $this->createService($name);
		}

		return $this->instances[$name];
	}


	/**
	 * Returns the service instance. If it has not been created yet, it creates it.
	 * Alias for getService().
	 * @throws MissingServiceException
	 */
	public function getByName(string $name): object
	{
		return $this->getService($name);
	}


	/**
	 * Returns type of the service.
	 * @return class-string
	 * @throws MissingServiceException
	 */
	public function getServiceType(string $name): string
	{
		$method = self::getMethodName($name);
		if (isset($this->aliases[$name])) {
			return $this->getServiceType($this->aliases[$name]);

		} elseif (isset($this->methods[$method])) {
			return (string) (new \ReflectionMethod($this, $method))->getReturnType();

		} elseif ($cb = $this->factories[$name] ?? null) {
			return (string) (new \ReflectionFunction($cb))->getReturnType();

		} else {
			throw new MissingServiceException(sprintf("Type of service '%s' not known.", $name));
		}
	}


	/**
	 * Checks whether the service exists in the container.
	 */
	public function hasService(string $name): bool
	{
		$name = $this->aliases[$name] ?? $name;
		return isset($this->methods[self::getMethodName($name)]) || isset($this->instances[$name]) || isset($this->factories[$name]);
	}


	/**
	 * Has a service instance been created?
	 */
	public function isCreated(string $name): bool
	{
		if (!$this->hasService($name)) {
			throw new MissingServiceException(sprintf("Service '%s' not found.", $name));
		}

		$name = $this->aliases[$name] ?? $name;
		return isset($this->instances[$name]);
	}


	/**
	 * Creates new instance of the service.
	 * @throws MissingServiceException
	 */
	public function createService(string $name): object
	{
		$name = $this->aliases[$name] ?? $name;
		$method = self::getMethodName($name);
		if ($callback = ($this->factories[$name] ?? null)) {
			$service = $this->preventDeadLock($name, fn() => $callback());
		} elseif (isset($this->methods[$method])) {
			$service = $this->preventDeadLock($name, fn() => $this->$method());
		} else {
			throw new MissingServiceException(sprintf("Service '%s' not found.", $name));
		}

		if (!is_object($service)) {
			throw new Nette\UnexpectedValueException(sprintf(
				"Unable to create service '$name', value returned by %s is not object.",
				$callback instanceof \Closure ? 'closure' : "method $method()",
			));
		}

		return $service;
	}


	/**
	 * Resolves an autowired service by (type, identity tag). The canonical tag-aware lookup.
	 * Untagged services are considered tagged with Definitions\Definition::DefaultTag.
	 * Always throws on miss or ambiguity — use getOrNull() if you want a nullable miss.
	 *
	 * O(1) via the precompiled $byTypeAndTag index, then one O(1) hash lookup in $methods
	 * inside getService().
	 *
	 * @template T of object
	 * @param  class-string<T>  $type
	 * @return T
	 * @throws MissingServiceException
	 */
	public function get(string $type, ?string $tag = null): object
	{
		$lookupTag = $tag ?? Definition::DefaultTag;

		// Fast path: try the type as-given. Most callers pass canonical class names
		// via ::class so we avoid the ReflectionClass allocation in normalizeClass()
		// for the common case.
		if (isset($this->byTypeAndTag[$type][$lookupTag])) {
			$names = $this->byTypeAndTag[$type][$lookupTag];
			if (count($names) === 1) {
				return $this->getService($names[0]);
			}
		}

		// Slow path: normalize (handles case-folding / leading backslash) and full lookup.
		return $this->getByType($type, throw: true, tag: $tag);
	}


	/**
	 * Resolves an autowired service by (type, identity tag), returning null on miss
	 * instead of throwing. Ambiguity (multiple services matching) still throws —
	 * that's a programming error, not a "does this exist?" question.
	 *
	 * Shares the same O(1) fast path as get().
	 *
	 * @template T of object
	 * @param  class-string<T>  $type
	 * @return T|null
	 * @throws MissingServiceException on ambiguity
	 */
	public function getOrNull(string $type, ?string $tag = null): ?object
	{
		$lookupTag = $tag ?? Definition::DefaultTag;

		if (isset($this->byTypeAndTag[$type][$lookupTag])) {
			$names = $this->byTypeAndTag[$type][$lookupTag];
			if (count($names) === 1) {
				return $this->getService($names[0]);
			}
		}

		return $this->getByType($type, throw: false, tag: $tag);
	}


	/**
	 * Returns an instance of the autowired service of the given type and optional identity tag.
	 * If it has not been created yet, it creates it.
	 * @template T of object
	 * @param  class-string<T>  $type
	 * @return ($throw is true ? T : ?T)
	 * @throws MissingServiceException
	 */
	public function getByType(string $type, bool $throw = true, ?string $tag = null): ?object
	{
		$type = Helpers::normalizeClass($type);

		// Fast path: precomputed index. Handles the overwhelming majority of resolutions.
		$lookupTag = $tag ?? Definition::DefaultTag;
		$names = $this->byTypeAndTag[$type][$lookupTag] ?? null;
		if ($names !== null && count($names) === 1) {
			return $this->getService($names[0]);
		}

		// Slow path: ambiguous match, missing, or implicit-default fallback.
		if (!empty($this->wiring[$type][0])) {
			$names = $this->wiring[$type][0];

			if ($tag !== null) {
				$names = array_values(array_filter($names, fn(string $name): bool => ($this->serviceTags[$name] ?? Definition::DefaultTag) === $tag));
				if ($names === []) {
					if ($throw) {
						throw new MissingServiceException(sprintf("Service of type %s with tag '%s' not found.", $type, $tag));
					}

					return null;
				}
			} elseif (count($names) > 1) {
				// Multiple type-matches with no explicit tag: prefer the "default"-tagged subset.
				$defaults = array_values(array_filter($names, fn(string $name): bool => ($this->serviceTags[$name] ?? Definition::DefaultTag) === Definition::DefaultTag));
				if (count($defaults) === 1) {
					return $this->getService($defaults[0]);
				}
			}

			if (count($names) === 1) {
				return $this->getService($names[0]);
			}

			natsort($names);
			throw new MissingServiceException(sprintf(
				'Multiple services of type %s%s found: %s.',
				$type,
				$tag !== null ? sprintf(" with tag '%s'", $tag) : '',
				implode(', ', $names),
			));

		} elseif ($throw) {
			if (!class_exists($type) && !interface_exists($type)) {
				throw new MissingServiceException(sprintf("Service of type '%s' not found. Check the class name because it cannot be found.", $type));
			} elseif ($tag !== null) {
				throw new MissingServiceException(sprintf("Service of type %s with tag '%s' not found.", $type, $tag));
			} elseif ($this->findByType($type)) {
				throw new MissingServiceException(sprintf("Service of type %s is not autowired or is missing in di\u{a0}›\u{a0}export\u{a0}›\u{a0}types.", $type));
			} else {
				throw new MissingServiceException(sprintf('Service of type %s not found. Did you add it to configuration file?', $type));
			}
		}

		return null;
	}


	/**
	 * Returns the names of autowired services of the given type.
	 * @param  class-string  $type
	 * @return list<string>
	 */
	public function findAutowired(string $type): array
	{
		$type = Helpers::normalizeClass($type);
		return array_merge($this->wiring[$type][0] ?? [], $this->wiring[$type][1] ?? []);
	}


	/**
	 * Returns the names of all services of the given type.
	 * @param  class-string  $type
	 * @return list<string>
	 */
	public function findByType(string $type): array
	{
		$type = Helpers::normalizeClass($type);
		return empty($this->wiring[$type])
			? []
			: array_merge(...array_values($this->wiring[$type]));
	}


	/**
	 * Returns the names of services with the given tag.
	 * @return array<string, mixed>  service name => tag value
	 */
	public function findByTag(string $tag): array
	{
		return $this->tags[$tag] ?? [];
	}


	/**
	 * Returns service names matching (type, tag) from the precomputed index. With $tag null,
	 * returns the full tag → names map for the type. Used internally by Container::get() and
	 * the planned bag-of-services autowiring (array<string, T> ctor params keyed by tag).
	 *
	 * @param  class-string  $type
	 * @return ($tag is null ? array<string, list<string>> : list<string>)
	 */
	public function findByTypeAndTag(string $type, ?string $tag = null): array
	{
		$type = Helpers::normalizeClass($type);
		if ($tag === null) {
			return $this->byTypeAndTag[$type] ?? [];
		}

		return $this->byTypeAndTag[$type][$tag] ?? [];
	}


	/**
	 * Returns all registered services as a map of service name to type.
	 * Aliases are not included — use getAliases() separately.
	 * @return array<string, string>
	 */
	public function getServiceTypes(): array
	{
		$types = [];
		foreach (array_keys($this->methods) as $method) {
			if (strlen($method) > 13 && str_starts_with($method, 'createService')) {
				$name = lcfirst(str_replace('__', '.', substr($method, 13)));
				$types[$name] = (string) (new \ReflectionMethod($this, $method))->getReturnType();
			}
		}
		foreach ($this->factories as $name => $cb) {
			$types[$name] = (string) (new \ReflectionFunction($cb))->getReturnType();
		}
		return $types;
	}


	/**
	 * Returns the alias map: alias name => canonical service name.
	 * @return array<string, string>
	 */
	public function getAliases(): array
	{
		return $this->aliases;
	}


	/**
	 * Returns services that have already been instantiated, indexed by service name.
	 * @return array<string, object>
	 */
	public function getInstantiatedServices(): array
	{
		return $this->instances;
	}


	/**
	 * Returns the tags attached to the given service.
	 * @return array<string, mixed>  tag name => tag value
	 */
	public function getServiceTags(string $name): array
	{
		$name = $this->aliases[$name] ?? $name;
		$result = [];
		foreach ($this->tags as $tag => $services) {
			if (array_key_exists($name, $services)) {
				$result[$tag] = $services[$name];
			}
		}
		return $result;
	}


	/**
	 * Detects circular references and invokes the callback.
	 * @param  \Closure(): mixed  $callback
	 */
	private function preventDeadLock(string $key, \Closure $callback): mixed
	{
		if (isset($this->creating[$key])) {
			throw new Nette\InvalidStateException(sprintf('Circular reference detected for: %s.', implode(', ', array_keys($this->creating))));
		}
		try {
			$this->creating[$key] = true;
			return $callback();
		} finally {
			unset($this->creating[$key]);
		}
	}


	/********************* autowiring ****************d*g**/


	/**
	 * Creates an instance of the class and passes dependencies to the constructor using autowiring.
	 * @template T of object
	 * @param  class-string<T>  $class
	 * @param  array<mixed>  $args
	 * @return T
	 */
	public function createInstance(string $class, array $args = []): object
	{
		$rc = new \ReflectionClass($class);
		if (!$rc->isInstantiable()) {
			throw new ServiceCreationException(sprintf('Class %s is not instantiable.', $class));

		} elseif ($constructor = $rc->getConstructor()) {
			return $rc->newInstanceArgs($this->autowireArguments($constructor, $args));

		} elseif ($args) {
			throw new ServiceCreationException(sprintf('Unable to pass arguments, class %s has no constructor.', $class));
		}

		return new $class;
	}


	/**
	 * Calls all methods starting with 'inject' and passes dependencies to them via autowiring.
	 */
	public function callInjects(object $service): void
	{
		Extensions\InjectExtension::callInjects($this, $service);
	}


	/**
	 * Calls the method and passes dependencies to it via autowiring.
	 * @param  array<mixed>  $args
	 */
	public function callMethod(callable $function, array $args = []): mixed
	{
		return $function(...$this->autowireArguments(Nette\Utils\Callback::toReflection($function), $args));
	}


	/**
	 * @param  array<mixed>  $args
	 * @return array<mixed>
	 */
	private function autowireArguments(\ReflectionFunctionAbstract $function, array $args = []): array
	{
		return Resolver::autowireArguments($function, $args, function (string $type, bool $single, bool $tagged = false) {
			if ($single) {
				return $this->getByType($type);
			}

			$names = $this->findAutowired($type);
			if (!$tagged) {
				return array_map($this->getService(...), $names);
			}

			$result = [];
			foreach ($names as $name) {
				$itemTag = $this->serviceTags[$name] ?? Definition::DefaultTag;
				if (isset($result[$itemTag])) {
					throw new MissingServiceException(sprintf(
						"Cannot autowire array<string, %s>: services '%s' and '%s' share the identity tag '%s'.",
						$type,
						array_search($result[$itemTag], $this->instances, true) ?: '?',
						$name,
						$itemTag,
					));
				}

				$result[$itemTag] = $this->getService($name);
			}

			return $result;
		});
	}


	/**
	 * Returns the method name for creating a service.
	 */
	final public static function getMethodName(string $name): string
	{
		if ($name === '') {
			throw new Nette\InvalidArgumentException('Service name must be a non-empty string.');
		}

		return 'createService' . str_replace('.', '__', ucfirst($name));
	}


	public function initialize(): void
	{
	}
}

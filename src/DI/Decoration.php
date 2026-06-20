<?php declare(strict_types=1);

/**
 * This file is part of the Nette Framework (https://nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

namespace Nette\DI;

use Nette\DI\Definitions\Definition;
use Nette\DI\Definitions\Reference;
use Nette\DI\Definitions\ServiceDefinition;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionType;
use function array_keys, count, implode, is_a, sprintf, strcmp, usort;


/**
 * Weaves decorator chains from {@see Definition::decorate()} metadata. Per decorated
 * (type, tag) slot it finds the base, stacks decorators into an onion by decoration priority
 * (highest outermost), injects each inner into the next wrapper's constructor, and rewires
 * autowiring so only the outermost answers the slot. No metadata means no-op.
 *
 * Decoration priority is its own axis, independent of {@see Definition::getPriority()} (which
 * orders collections), so a deep decorator never disturbs collection rank.
 */
final class Decoration
{
	public static function apply(ContainerBuilder $builder): void
	{
		$definitions = $builder->getDefinitions();

		/** @var array<string, array{type: class-string, tag: ?string, decorators: list<array{name: string, priority: int}>}> $slots */
		$slots = [];
		foreach ($definitions as $name => $def) {
			foreach ($def->getDecorated() as $target) {
				$key = $target['type'] . "\0" . ($target['tag'] ?? '');
				$slots[$key]['type'] = $target['type'];
				$slots[$key]['tag'] = $target['tag'];
				$slots[$key]['decorators'][] = ['name' => $name, 'priority' => $target['priority']];
			}
		}

		if ($slots === []) {
			return;
		}

		/** @var array<string, list<class-string>> $heads  decorator name => types it is outermost for */
		$heads = [];

		/** @var array<string, ?string> $headTag  decorator name => identity tag of its slot */
		$headTag = [];

		/** @var array<string, true> $buried  decorator names wrapped by another in some slot */
		$buried = [];

		/** @var array<string, true> $bases  base service names to drop from autowiring */
		$bases = [];

		/** @var list<array{outer: ServiceDefinition, param: string, inner: string}> $wiring */
		$wiring = [];

		/** @var array<string, array<string, string>> $assigned  decorator name => param => inner already wired there */
		$assigned = [];

		foreach ($slots as $slot) {
			$type = $slot['type'];
			$tag = $slot['tag'];

			$decorators = $slot['decorators'];
			usort(
				$decorators,
				static fn(array $a, array $b): int => ($b['priority'] <=> $a['priority']) ?: strcmp($a['name'], $b['name']),
			);

			$base = self::findBase($definitions, $type, $tag);

			$chain = [];
			foreach ($decorators as $decorator) {
				$chain[] = $decorator['name'];
			}

			$chain[] = $base;

			for ($i = 0, $n = count($chain) - 1; $i < $n; $i++) {
				$outerName = $chain[$i];
				$innerName = $chain[$i + 1];

				$outer = $definitions[$outerName];
				if (!$outer instanceof ServiceDefinition) {
					throw new ServiceCreationException(sprintf(
						"Decorator '%s' of type %s must be a standard service.",
						$outerName,
						$type,
					));
				}

				$param = self::findInnerParam($outer, $type, $definitions[$innerName]->getType(), $tag, $innerName, $assigned[$outerName] ?? []);
				if (($assigned[$outerName][$param] ?? null) !== $innerName) {
					$assigned[$outerName][$param] = $innerName;
					$wiring[] = ['outer' => $outer, 'param' => $param, 'inner' => $innerName];
				}
			}

			$head = $chain[0];
			$heads[$head][] = $type;
			$headTag[$head] = $tag;
			$bases[$base] = true;

			for ($j = 1, $last = count($chain) - 1; $j < $last; $j++) {
				$buried[$chain[$j]] = true;
			}
		}

		$names = [];
		foreach ($slots as $slot) {
			foreach ($slot['decorators'] as $decorator) {
				$names[$decorator['name']] = true;
			}
		}

		foreach (array_keys($names) as $name) {
			if (!isset($heads[$name])) {
				$definitions[$name]->setAutowired(false); // buried in every slot it joins
			} elseif (isset($buried[$name])) {
				$definitions[$name]->setAutowired($heads[$name]); // outermost here, wrapped there — restrict to what it heads
			} else {
				$definitions[$name]->setAutowired(true); // outermost everywhere — a full drop-in for the base
			}
		}

		foreach (array_keys($bases) as $name) {
			$definitions[$name]->setAutowired(false);
		}

		foreach ($wiring as $wire) {
			$wire['outer']->setArgument($wire['param'], new Reference($wire['inner']));
		}

		foreach ($headTag as $name => $tag) {
			if ($tag !== null) {
				$definitions[$name]->setTag($tag);
			}
		}
	}


	/**
	 * The single non-decorator service carrying the slot's identity tag — the innermost
	 * layer the onion wraps.
	 *
	 * @param  array<string, Definition>  $definitions
	 * @param  class-string  $type
	 */
	private static function findBase(array $definitions, string $type, ?string $tag): string
	{
		$identityTag = $tag ?? Definition::DefaultTag;
		$found = [];
		foreach ($definitions as $name => $def) {
			$candidate = $def->getType();
			if (
				$candidate !== null
				&& is_a($candidate, $type, allow_string: true)
				&& $def->getTag() === $identityTag
				&& !self::decoratesType($def, $type)
			) {
				$found[] = $name;
			}
		}

		if ($found === []) {
			throw new ServiceCreationException(sprintf(
				'No base service of type %s%s found to decorate.',
				$type,
				$tag === null ? '' : sprintf(" with tag '%s'", $tag),
			));
		}

		if (count($found) > 1) {
			throw new ServiceCreationException(sprintf(
				'Cannot decorate type %s%s: multiple base services found: %s.',
				$type,
				$tag === null ? '' : sprintf(" with tag '%s'", $tag),
				implode(', ', $found),
			));
		}

		return $found[0];
	}


	/** @param  class-string  $type */
	private static function decoratesType(Definition $def, string $type): bool
	{
		foreach ($def->getDecorated() as $target) {
			if ($target['type'] === $type) {
				return true;
			}
		}

		return false;
	}


	/**
	 * The constructor parameter that receives the inner service. A param already bound (by NEON
	 * or an extension that turns a tagged param into a tagged Reference) to a service of this
	 * very (type, tag) slot wins and is redirected to the inner; a param bound to a different
	 * service is left alone. Otherwise the first unbound parameter the inner type satisfies is
	 * used — an exact match on $type ahead of a supertype or intersection.
	 *
	 * @param  class-string  $type
	 * @param  class-string|null  $innerType
	 * @param  string  $innerName  the inner being wired; a param already bound to it may be reused
	 * @param  array<string, string>  $assigned  parameter name => inner already wired there
	 */
	private static function findInnerParam(ServiceDefinition $outer, string $type, ?string $innerType, ?string $slotTag, string $innerName, array $assigned): string
	{
		$class = $outer->getType();
		$constructor = $class === null ? null : new ReflectionClass($class)->getConstructor();
		if ($constructor === null) {
			throw new ServiceCreationException(sprintf(
				"Decorator '%s' must declare a constructor parameter for the decorated %s service.",
				$outer->getName(),
				$type,
			));
		}

		$identityTag = $slotTag ?? Definition::DefaultTag;
		$arguments = $outer->getCreator()->arguments;
		$exact = $fallback = null;
		foreach ($constructor->getParameters() as $position => $param) {
			$name = $param->getName();
			if (isset($assigned[$name]) && $assigned[$name] !== $innerName) {
				continue; // bound to a different inner already
			}

			$argument = $arguments[$name] ?? $arguments[$position] ?? null;
			if ($argument instanceof Reference) {
				if (($argument->getTag() ?? Definition::DefaultTag) === $identityTag && self::accepts($param->getType(), $type, $innerType)) {
					return $name; // explicitly bound to this slot
				}

				continue; // bound to another service
			} elseif ($argument !== null) {
				continue; // a literal value, not the inner
			}

			$paramType = $param->getType();
			if (!self::accepts($paramType, $type, $innerType)) {
				continue;
			}

			if ($paramType instanceof ReflectionNamedType && Helpers::normalizeClass($paramType->getName()) === $type) {
				$exact ??= $name;
			} else {
				$fallback ??= $name;
			}
		}

		if (($best = $exact ?? $fallback) !== null) {
			return $best;
		}

		throw new ServiceCreationException(sprintf(
			"Decorator '%s' has no constructor parameter that accepts the decorated %s service.",
			$outer->getName(),
			$type,
		));
	}


	/**
	 * Whether a parameter's type accepts the inner: an exact/supertype of $type, or an
	 * intersection the inner implements in full.
	 *
	 * @param  class-string  $type
	 * @param  class-string|null  $innerType
	 */
	private static function accepts(?ReflectionType $paramType, string $type, ?string $innerType): bool
	{
		if ($paramType instanceof ReflectionNamedType && !$paramType->isBuiltin()) {
			$name = Helpers::normalizeClass($paramType->getName());
			return $name === $type
				|| ($innerType !== null && is_a($innerType, $name, allow_string: true) && is_a($type, $name, allow_string: true));
		}

		return $paramType instanceof ReflectionIntersectionType && self::intersectionAccepts($paramType, $innerType);
	}


	/** @param  class-string|null  $innerType */
	private static function intersectionAccepts(ReflectionIntersectionType $paramType, ?string $innerType): bool
	{
		if ($innerType === null) {
			return false;
		}

		foreach ($paramType->getTypes() as $part) {
			if (!$part instanceof ReflectionNamedType || !is_a($innerType, Helpers::normalizeClass($part->getName()), allow_string: true)) {
				return false;
			}
		}

		return true;
	}
}

<?php declare(strict_types=1);

/**
 * This file is part of the Nette Framework (https://nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

namespace Nette\DI;

use Nette\DI\Definitions\Definition;
use function array_filter, array_keys, array_map, array_values, count, implode, is_a, sprintf, strcmp;


/**
 * Deterministically orders service definitions for collection autowiring from the
 * ordering metadata each carries: before/after as a topological sort, priority (desc)
 * then type FQCN then name breaking ties. A set with no metadata is returned unchanged;
 * before/after references matching nothing in the set are ignored; a cycle throws.
 */
final class DefinitionOrdering
{
	/**
	 * @param  array<string, Definition>  $definitions  name => definition
	 * @return array<string, Definition>  name => definition, in collection order
	 */
	public static function sort(array $definitions): array
	{
		$configured = array_filter(
			$definitions,
			static fn(Definition $def): bool => $def->getPriority() !== null || $def->getBefore() || $def->getAfter(),
		);
		if (!$configured) {
			return $definitions;
		}

		$result = [];
		foreach (self::resolve($definitions) as $name) {
			$result[$name] = $definitions[$name];
		}

		return $result;
	}


	/**
	 * @param  array<string, Definition>  $definitions
	 * @return list<string>  ordered names
	 */
	private static function resolve(array $definitions): array
	{
		$names = array_keys($definitions);

		/** @var array<string, list<string>> $successors */
		$successors = [];
		/** @var array<string, int> $indegree */
		$indegree = [];
		foreach ($names as $name) {
			$successors[$name] = [];
			$indegree[$name] = 0;
		}

		/** @var array<string, array{string, string}> $edges keyed "u\0v" to dedupe */
		$edges = [];
		foreach ($definitions as $name => $def) {
			foreach ($def->getBefore() as $ref) {
				foreach (self::matching($definitions, $ref) as $target) {
					if ($name !== $target) {
						$edges[$name . "\0" . $target] = [$name, $target];
					}
				}
			}

			foreach ($def->getAfter() as $ref) {
				foreach (self::matching($definitions, $ref) as $source) {
					if ($source !== $name) {
						$edges[$source . "\0" . $name] = [$source, $name];
					}
				}
			}
		}

		foreach ($edges as [$from, $to]) {
			$successors[$from][] = $to;
			$indegree[$to]++;
		}

		$ready = array_values(array_filter($names, static fn(string $n): bool => $indegree[$n] === 0));
		$ordered = [];
		while ($ready) {
			$next = self::pickNext($ready, $definitions);
			$ready = array_values(array_filter($ready, static fn(string $n): bool => $n !== $next));
			$ordered[] = $next;
			foreach ($successors[$next] as $successor) {
				if (--$indegree[$successor] === 0) {
					$ready[] = $successor;
				}
			}
		}

		if (count($ordered) !== count($names)) {
			$cycle = array_values(array_filter($names, static fn(string $n): bool => $indegree[$n] > 0));
			throw new ServiceCreationException(sprintf(
				'Cannot resolve a consistent service order: circular before/after constraints among %s.',
				implode(', ', array_map(static fn(string $n): string => "'$n'", $cycle)),
			));
		}

		return $ordered;
	}


	/**
	 * Names of definitions whose type is-a $ref (an interface reference fans out to
	 * every implementation present in the set).
	 *
	 * @param  array<string, Definition>  $definitions
	 * @param  class-string  $ref
	 * @return list<string>
	 */
	private static function matching(array $definitions, string $ref): array
	{
		$out = [];
		foreach ($definitions as $name => $def) {
			$type = $def->getType();
			if ($type !== null && is_a($type, $ref, allow_string: true)) {
				$out[] = $name;
			}
		}

		return $out;
	}


	/**
	 * @param  list<string>  $ready
	 * @param  array<string, Definition>  $definitions
	 */
	private static function pickNext(array $ready, array $definitions): string
	{
		$best = $ready[0];
		foreach ($ready as $name) {
			if (self::compare($name, $best, $definitions) < 0) {
				$best = $name;
			}
		}

		return $best;
	}


	/**
	 * Higher priority first, then type FQCN ascending, then service name ascending.
	 * Negative means $a is collected before $b.
	 *
	 * @param  array<string, Definition>  $definitions
	 */
	private static function compare(string $a, string $b, array $definitions): int
	{
		$pa = $definitions[$a]->getPriority() ?? 0;
		$pb = $definitions[$b]->getPriority() ?? 0;
		if ($pa !== $pb) {
			return $pb <=> $pa;
		}

		return strcmp($definitions[$a]->getType() ?? '', $definitions[$b]->getType() ?? '') ?: strcmp($a, $b);
	}
}

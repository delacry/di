<?php declare(strict_types=1);

/**
 * This file is part of the Nette Framework (https://nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

namespace Nette\DI;

use Nette\DI\Definitions\Definition;
use function array_filter, array_merge, array_values, class_exists, class_implements, class_parents, count, implode, interface_exists, is_a, is_array, natsort, sprintf, str_contains;


/**
 * Resolves service names by type for autowiring.
 */
class Autowiring
{
	/** @var array<class-string, list<string>>  type => service names */
	private array $highPriority = [];

	/** @var array<class-string, list<string>>  type => service names */
	private array $lowPriority = [];

	/** @var array<class-string, class-string> */
	private array $excludedClasses = [];


	public function __construct(
		private readonly ContainerBuilder $builder,
	) {
	}


	/**
	 * Resolves a service name by (type, tag). When $tag is null, untagged services
	 * (i.e. those whose identity tag is the implicit "default") are preferred to break
	 * ambiguity. When $tag is non-null, only services with a matching identity tag are
	 * considered.
	 *
	 * @param class-string  $type
	 * @return ($throw is true ? string : ?string)
	 * @throws MissingServiceException when not found
	 * @throws ServiceCreationException when multiple match
	 */
	public function getByType(string $type, bool $throw = false, ?string $tag = null): ?string
	{
		$type = Helpers::normalizeClass($type);
		$candidates = $this->highPriority[$type] ?? [];

		if ($candidates === []) {
			if ($throw) {
				if (!class_exists($type) && !interface_exists($type)) {
					throw new MissingServiceException(sprintf("Service of type '%s' not found. Check the class name because it cannot be found.", $type));
				}

				throw new MissingServiceException(sprintf('Service of type %s not found. Did you add it to configuration file?', $type));
			}

			return null;
		}

		$definitions = $this->builder->getDefinitions();

		if ($tag !== null) {
			$candidates = array_values(array_filter($candidates, static fn(string $name): bool => $definitions[$name]->getTag() === $tag));
			if ($candidates === []) {
				if ($throw) {
					throw new MissingServiceException(sprintf("Service of type %s with tag '%s' not found.", $type, $tag));
				}

				return null;
			}
		}

		if (count($candidates) === 1) {
			return $candidates[0];
		}

		// $tag === null and multiple candidates — try to disambiguate by preferring "default"-tagged services
		if ($tag === null) {
			$defaults = array_values(array_filter($candidates, static fn(string $name): bool => $definitions[$name]->getTag() === Definition::DefaultTag));
			if (count($defaults) === 1) {
				return $defaults[0];
			}
		}

		natsort($candidates);
		$list = array_values($candidates);
		$hint = count($list) === 2 && ($tmp = str_contains($list[0], '.') xor str_contains($list[1], '.'))
			? '. If you want to overwrite service ' . $list[$tmp ? 0 : 1] . ', give it proper name.'
			: '';
		throw new ServiceCreationException(sprintf(
			'Multiple services of type %s%s found: %s%s',
			$type,
			$tag !== null ? sprintf(" with tag '%s'", $tag) : '',
			implode(', ', $list),
			$hint,
		));
	}


	/**
	 * Gets the service names and definitions of the specified type.
	 * @param class-string  $type
	 * @return array<string, Definitions\Definition>  service name => definition
	 */
	public function findByType(string $type): array
	{
		$type = Helpers::normalizeClass($type);
		$definitions = $this->builder->getDefinitions();
		$names = array_merge($this->highPriority[$type] ?? [], $this->lowPriority[$type] ?? []);
		$res = [];
		foreach ($names as $name) {
			$res[$name] = $definitions[$name];
		}

		return $res;
	}


	/**
	 * Excludes classes and their ancestors from autowiring lookup.
	 * @param array<class-string>  $types
	 */
	public function addExcludedClasses(array $types): void
	{
		foreach ($types as $type) {
			if (class_exists($type) || interface_exists($type)) {
				$type = Helpers::normalizeClass($type);
				$this->excludedClasses += class_parents($type) + class_implements($type) + [$type => $type];
			}
		}
	}


	/**
	 * Returns low-priority and high-priority type-to-service-names maps.
	 * @return array{array<class-string, list<string>>, array<class-string, list<string>>}
	 */
	public function getClassList(): array
	{
		return [$this->lowPriority, $this->highPriority];
	}


	/**
	 * Rebuilds the type-to-service-names index from current definitions.
	 */
	public function rebuild(): void
	{
		$this->lowPriority = $this->highPriority = $preferred = [];

		foreach ($this->builder->getDefinitions() as $name => $def) {
			if (!($type = $def->getType())) {
				continue;
			}

			$autowired = $def->getAutowired();
			if (is_array($autowired)) {
				foreach ($autowired as $k => $autowiredType) {
					if ($autowiredType === ContainerBuilder::ThisService) {
						$autowired[$k] = $type;
					} elseif (!is_a($type, $autowiredType, allow_string: true)) {
						throw new ServiceCreationException(sprintf(
							"Incompatible class %s in autowiring definition of service '%s'.",
							$autowiredType,
							$name,
						));
					}
				}
			}

			foreach (class_parents($type) + class_implements($type) + [$type] as $parent) {
				if (!$autowired || isset($this->excludedClasses[$parent])) {
					continue;
				} elseif (is_array($autowired)) {
					$priority = false;
					foreach ($autowired as $autowiredType) {
						if (is_a($parent, $autowiredType, allow_string: true)) {
							if (empty($preferred[$parent]) && isset($this->highPriority[$parent])) {
								$this->lowPriority[$parent] = array_merge($this->lowPriority[$parent] ?? [], $this->highPriority[$parent]);
								$this->highPriority[$parent] = [];
							}

							$preferred[$parent] = $priority = true;
							break;
						}
					}

					if (!$priority) {
						continue; // restrict: not autowired for a type outside its declared set — excluded, not a low-priority fallback (so it never leaks into a collection or tagged map for that type)
					}
				} else {
					$priority = empty($preferred[$parent]);
				}

				$list = $priority ? 'highPriority' : 'lowPriority';
				$this->$list[$parent][] = $name;
			}
		}
	}
}

<?php declare(strict_types=1);

/**
 * This file is part of the Nette Framework (https://nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

namespace Nette\DI\Extensions;

use Nette;
use Nette\DI;
use Nette\DI\Definitions;
use Nette\Utils\Reflection;
use function array_keys, array_reverse, array_search, array_unshift, array_values, class_parents, get_class_methods, is_a, is_subclass_of, ksort, sprintf, str_starts_with, uksort;


/**
 * Shared mechanism for attribute-driven injection: tag-aware constructor and
 * inject-method parameter wiring, inject* method calls, and setter-style property
 * injection. A concrete extension picks the attribute via injectAttribute() and
 * whether non-public properties are injectable via allowsNonPublic().
 */
abstract class AbstractInjectExtension extends DI\CompilerExtension
{
	/** @return class-string */
	abstract protected static function injectAttribute(): string;


	protected static function allowsNonPublic(): bool
	{
		return false;
	}


	abstract protected function shouldInjectMembers(Definitions\Definition $def, string $class): bool;


	public function getConfigSchema(): Nette\Schema\Schema
	{
		return Nette\Schema\Expect::structure([]);
	}


	public function beforeCompile(): void
	{
		foreach ($this->getContainerBuilder()->getDefinitions() as $def) {
			$target = $def instanceof Definitions\FactoryDefinition
				? $def->getResultDefinition()
				: $def;
			if (!$target instanceof Definitions\ServiceDefinition) {
				continue;
			}

			$class = $this->resolveTargetClass($target);
			if ($class === null) {
				continue;
			}

			self::applyInjectAttributesToConstructor($target, $class);

			if ($this->shouldInjectMembers($def, $class)) {
				$this->updateDefinition($target, $class);
			}

			/** @var class-string $class */
			$this->trackInjectDependency($class);
		}
	}


	/**
	 * Registers the class file (and its parents', for inherited non-public members) as a
	 * dependency, so edits to inject attributes invalidate the cached container — the
	 * DependencyChecker's structural hash doesn't track them.
	 * @param  class-string  $class
	 */
	private function trackInjectDependency(string $class): void
	{
		if (!$this->injectsAnything($class)) {
			return;
		}

		$files = [];
		foreach ([$class, ...array_values(class_parents($class) ?: [])] as $name) {
			if (($file = new \ReflectionClass($name)->getFileName()) !== false) {
				$files[] = $file;
			}
		}

		$this->compiler->addDependencies($files);
	}


	/** @param  class-string  $class */
	private function injectsAnything(string $class): bool
	{
		if (static::resolveInjectProperties($class) !== [] || static::getInjectMethods($class) !== []) {
			return true;
		}

		$ctor = new \ReflectionClass($class)->getConstructor();
		foreach ($ctor?->getParameters() ?? [] as $param) {
			if ($param->getAttributes(static::injectAttribute()) !== []) {
				return true;
			}
		}

		return false;
	}


	private function resolveTargetClass(Definitions\ServiceDefinition $def): ?string
	{
		$resolvedType = new DI\Resolver($this->getContainerBuilder())->resolveEntityType($def->getCreator());
		return $resolvedType && $def->getType() && is_subclass_of($resolvedType, $def->getType())
			? $resolvedType
			: $def->getType();
	}


	private function updateDefinition(Definitions\ServiceDefinition $def, string $class): void
	{
		$setups = $def->getSetup();

		foreach (static::resolveInjectProperties($class) as $property => $info) {
			$reference = Definitions\Reference::fromType($info['type'], $info['tag']);

			if ($info['public']) {
				$builder = $this->getContainerBuilder();
				$inject = new Definitions\Statement(['@self', '$' . $property], [$reference]);
				foreach ($setups as $key => $setup) {
					if ($setup->getEntity() == $inject->getEntity()) { // intentionally ==
						$inject = $setup;
						$builder = null;
						unset($setups[$key]);
					}
				}

				if ($builder) {
					self::checkType($class, $property, $info['type'], $builder, $info['tag']);
				}
				array_unshift($setups, $inject);
			} else {
				self::checkType($class, $property, $info['type'], $this->getContainerBuilder(), $info['tag']);
				array_unshift($setups, new Definitions\Statement(
					[static::class, 'injectProperty'],
					['@self', $property, $reference, $info['declaringClass']],
				));
			}
		}

		foreach (array_reverse(static::getInjectMethods($class)) as $method) {
			$inject = self::buildInjectMethodStatement($class, $method);
			foreach ($setups as $key => $setup) {
				if ($setup->getEntity() == $inject->getEntity()) { // intentionally ==
					$inject = $setup;
					unset($setups[$key]);
				}
			}

			array_unshift($setups, $inject);
		}

		$def->setSetup($setups);
	}


	private static function applyInjectAttributesToConstructor(Definitions\ServiceDefinition $def, string $class): void
	{
		$ctor = new \ReflectionClass($class)->getConstructor();
		if ($ctor === null) {
			return;
		}

		$attribute = static::injectAttribute();
		$creator = $def->getCreator();
		$arguments = $creator->arguments;
		$changed = false;

		foreach ($ctor->getParameters() as $param) {
			$attrs = $param->getAttributes($attribute);
			if ($attrs === []) {
				continue;
			}

			$tag = $attrs[0]->newInstance()->tag;
			if ($tag === null) {
				throw new Nette\InvalidStateException(sprintf(
					'#[%s] on parameter $%s in %s requires a tag — untagged parameters are autowired by type automatically.',
					new \ReflectionClass($attribute)->getShortName(),
					$param->getName(),
					Reflection::toString($ctor),
				));
			}

			$type = Nette\Utils\Type::fromReflection($param);
			$typeName = DI\Helpers::ensureClassType($type, sprintf('type of parameter $%s in %s', $param->getName(), Reflection::toString($ctor)));
			$arguments[$param->getName()] = Definitions\Reference::fromType($typeName, $tag);
			$changed = true;
		}

		if ($changed) {
			$def->setCreator($creator->getEntity(), $arguments);
		}
	}


	private static function buildInjectMethodStatement(string $class, string $method): Definitions\Statement
	{
		$attribute = static::injectAttribute();
		$arguments = [];
		foreach (new \ReflectionMethod($class, $method)->getParameters() as $param) {
			$attrs = $param->getAttributes($attribute);
			if ($attrs === []) {
				continue;
			}

			$tag = $attrs[0]->newInstance()->tag;
			if ($tag === null) {
				throw new Nette\InvalidStateException(sprintf(
					'#[%s] on parameter $%s in %s::%s() requires a tag — untagged parameters are autowired by type automatically.',
					new \ReflectionClass($attribute)->getShortName(),
					$param->getName(),
					$class,
					$method,
				));
			}

			$type = Nette\Utils\Type::fromReflection($param);
			$typeName = DI\Helpers::ensureClassType($type, sprintf('type of parameter $%s in %s::%s()', $param->getName(), $class, $method));
			$arguments[$param->getName()] = Definitions\Reference::fromType($typeName, $tag);
		}

		return new Definitions\Statement(['@self', $method], $arguments);
	}


	/**
	 * @return string[]
	 * @internal
	 */
	public static function getInjectMethods(string $class): array
	{
		$classes = [];
		foreach (get_class_methods($class) as $name) {
			if (str_starts_with($name, 'inject')) {
				$classes[$name] = new \ReflectionMethod($class, $name)->getDeclaringClass()->name;
			}
		}

		$methods = array_keys($classes);
		uksort($classes, fn(string $a, string $b): int => $classes[$a] === $classes[$b]
				? array_search($a, $methods, strict: true) <=> array_search($b, $methods, strict: true)
				: (is_a($classes[$a], $classes[$b], allow_string: true) ? 1 : -1));
		return array_keys($classes);
	}


	/**
	 * @return array<string, array{type: class-string, tag: ?string}>
	 * @internal
	 */
	public static function getInjectProperties(string $class): array
	{
		$res = [];
		foreach (static::resolveInjectProperties($class) as $name => $info) {
			$res[$name] = ['type' => $info['type'], 'tag' => $info['tag']];
		}

		return $res;
	}


	/**
	 * @return array<string, array{type: class-string, tag: ?string, declaringClass: class-string, public: bool}>
	 */
	protected static function resolveInjectProperties(string $class): array
	{
		$attribute = static::injectAttribute();
		$allowNonPublic = static::allowsNonPublic();
		$res = [];
		$rc = new \ReflectionClass($class);

		do {
			foreach ($rc->getProperties() as $rp) {
				if ($allowNonPublic && $rp->getDeclaringClass()->getName() !== $rc->getName()) {
					continue;
				}

				$attrs = $rp->getAttributes($attribute);
				if ($attrs === []) {
					continue;
				}
				if ($rp->isPromoted()) {
					continue;
				}

				if ($allowNonPublic) {
					if ($rp->isStatic() || $rp->isReadOnly()) {
						throw new Nette\InvalidStateException(sprintf('Property %s for injection must not be static or readonly.', Reflection::toString($rp)));
					}
				} elseif (!$rp->isPublic() || $rp->isStatic() || $rp->isReadOnly()) {
					throw new Nette\InvalidStateException(sprintf('Property %s for injection must not be static, readonly and must be public.', Reflection::toString($rp)));
				}

				$type = Nette\Utils\Type::fromReflection($rp);
				$res[$rp->getName()] = [
					'type' => DI\Helpers::ensureClassType($type, 'type of property ' . Reflection::toString($rp)),
					'tag' => $attrs[0]->newInstance()->tag,
					'declaringClass' => $rp->getDeclaringClass()->getName(),
					'public' => $rp->isPublic(),
				];
			}

			if (!$allowNonPublic) {
				break;
			}
			$rc = $rc->getParentClass();
		} while ($rc !== false);

		ksort($res);
		return $res;
	}


	public static function callInjects(DI\Container $container, object $service): void
	{
		foreach (static::getInjectMethods($service::class) as $method) {
			$container->callMethod([$service, $method]);
		}

		foreach (static::resolveInjectProperties($service::class) as $property => $info) {
			self::checkType($service, $property, $info['type'], $container, $info['tag']);
			$value = $container->getByType($info['type'], throw: true, tag: $info['tag']);
			if ($info['public']) {
				$service->$property = $value;
			} else {
				self::injectProperty($service, $property, $value, $info['declaringClass']);
			}
		}
	}


	public static function injectProperty(object $service, string $property, mixed $value, string $declaringClass): void
	{
		new \ReflectionProperty($declaringClass, $property)->setValue($service, $value);
	}


	private static function checkType(
		object|string $class,
		string $name,
		?string $type,
		DI\Container|DI\ContainerBuilder $container,
		?string $tag = null,
	): void
	{
		if (!$container->getByType($type, throw: false, tag: $tag)) {
			throw new Nette\DI\MissingServiceException(sprintf(
				'Service of type %s%s required by %s not found. Did you add it to configuration file?',
				$type,
				$tag !== null ? sprintf(" with tag '%s'", $tag) : '',
				Reflection::toString(new \ReflectionProperty($class, $name)),
			));
		}
	}
}

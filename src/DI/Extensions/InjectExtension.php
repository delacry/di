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
use function array_keys, array_reverse, array_search, array_unshift, get_class_methods, is_a, is_subclass_of, ksort, sprintf, str_starts_with, uksort;


/**
 * Calls inject methods and fills #[Inject] properties.
 */
final class InjectExtension extends DI\CompilerExtension
{
	public const TagInject = 'nette.inject';

	#[\Deprecated('use InjectExtension::TagInject')]
	public const TAG_INJECT = self::TagInject;


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
			if ($class !== null) {
				// Constructor-level #[Inject(tag:)] is per-parameter autowiring control;
				// it applies regardless of whether the service opted into property/method injection.
				self::applyInjectAttributesToConstructor($target, $class);
			}

			// Property and inject-method handling is gated behind inject:true (the legacy
			// Nette convention) because it adds setup statements that change construction order.
			if ($def->getTag(self::TagInject) && $class !== null) {
				$this->updateDefinition($target, $class);
			}
		}
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

		foreach (self::getInjectProperties($class) as $property => $info) {
			$builder = $this->getContainerBuilder();
			$inject = new Definitions\Statement(
				['@self', '$' . $property],
				[Definitions\Reference::fromType($info['type'], $info['tag'])],
			);
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
		}

		foreach (array_reverse(self::getInjectMethods($class)) as $method) {
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

		$creator = $def->getCreator();
		$arguments = $creator->arguments;
		$changed = false;

		foreach ($ctor->getParameters() as $param) {
			$attrs = $param->getAttributes(DI\Attributes\Inject::class);
			if ($attrs === []) {
				continue;
			}

			$tag = $attrs[0]->newInstance()->tag;
			if ($tag === null) {
				throw new Nette\InvalidStateException(sprintf(
					'#[Inject] on parameter $%s in %s requires a tag — untagged parameters are autowired by type automatically.',
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
		$arguments = [];
		foreach (new \ReflectionMethod($class, $method)->getParameters() as $param) {
			$attrs = $param->getAttributes(DI\Attributes\Inject::class);
			if ($attrs === []) {
				continue;
			}

			$tag = $attrs[0]->newInstance()->tag;
			if ($tag === null) {
				throw new Nette\InvalidStateException(sprintf(
					'#[Inject] on parameter $%s in %s::%s() requires a tag — untagged parameters are autowired by type automatically.',
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
	 * Returns list of inject method names, ordered from parent to child class.
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
	 * Returns list of injectable properties with their types and optional identity tags.
	 * @return array<string, array{type: class-string, tag: ?string}>
	 * @internal
	 */
	public static function getInjectProperties(string $class): array
	{
		$res = [];
		foreach (new \ReflectionClass($class)->getProperties() as $rp) {
			$attrs = $rp->getAttributes(DI\Attributes\Inject::class);
			if (!$attrs) {
				continue;
			}

			if ($rp->isPromoted()) {
				continue; // Constructor-promoted properties are wired via the constructor itself.
			}

			if (!$rp->isPublic() || $rp->isStatic() || $rp->isReadOnly()) {
				throw new Nette\InvalidStateException(sprintf('Property %s for injection must not be static, readonly and must be public.', Reflection::toString($rp)));
			}

			$type = Nette\Utils\Type::fromReflection($rp);
			$res[$rp->getName()] = [
				'type' => DI\Helpers::ensureClassType($type, 'type of property ' . Reflection::toString($rp)),
				'tag' => $attrs[0]->newInstance()->tag,
			];
		}

		ksort($res);
		return $res;
	}


	/**
	 * Calls inject methods and fills inject properties on the given service.
	 */
	public static function callInjects(DI\Container $container, object $service): void
	{
		foreach (self::getInjectMethods($service::class) as $method) {
			$container->callMethod([$service, $method]);
		}

		foreach (self::getInjectProperties($service::class) as $property => $info) {
			self::checkType($service, $property, $info['type'], $container, $info['tag']);
			$service->$property = $container->getByType($info['type'], throw: true, tag: $info['tag']);
		}
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

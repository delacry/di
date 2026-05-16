<?php declare(strict_types=1);

/**
 * This file is part of the Nette Framework (https://nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

namespace Nette\DI\Definitions;

use Nette;
use function class_exists, interface_exists, is_array, is_string, sprintf;


/**
 * Abstract base for all service definition types used by ContainerBuilder.
 */
abstract class Definition
{
	private ?string $name = null;

	/** @var class-string|null */
	private ?string $type = null;

	/** @var array<string, mixed> */
	private array $tags = [];

	/**
	 * Identity tag — a single string discriminator used together with the service type
	 * to identify this service for autowiring and Container::get($type, $tag) lookups.
	 * Null means the implicit Definition::DefaultTag.
	 *
	 * Readable publicly, writable only via setTag() so subclasses and external callers
	 * go through the validated path. For the "always a string" view that falls back to
	 * DefaultTag when null, use getTag() instead.
	 */
	public private(set) ?string $tag = null;

	/** @var bool|class-string[] */
	private bool|array $autowired = true;

	/** @var ?(\Closure(): void) */
	private ?\Closure $notifier = null;


	/**
	 * @internal  This is managed by ContainerBuilder and should not be called by user
	 */
	final public function setName(string $name): static
	{
		if ($this->name) {
			throw new Nette\InvalidStateException('Name already has been set.');
		}

		$this->name = $name;
		return $this;
	}


	final public function getName(): ?string
	{
		return $this->name;
	}


	/** @param  class-string|null  $type */
	protected function setType(?string $type): static
	{
		if ($this->autowired && $this->notifier && $this->type !== $type) {
			($this->notifier)();
		}

		if ($type === null) {
			$this->type = null;
		} elseif (!class_exists($type) && !interface_exists($type)) {
			throw new Nette\InvalidArgumentException(sprintf(
				"Service '%s': Class or interface '%s' not found.",
				$this->name,
				$type,
			));
		} else {
			$this->type = Nette\DI\Helpers::normalizeClass($type);
		}

		return $this;
	}


	/** @return class-string|null */
	final public function getType(): ?string
	{
		return $this->type;
	}


	/** @param  array<string, mixed>  $tags */
	final public function setTags(array $tags): static
	{
		$this->tags = $tags;
		return $this;
	}


	/** @return array<string, mixed> */
	final public function getTags(): array
	{
		return $this->tags;
	}


	final public function addTag(string $tag, mixed $attr = true): static
	{
		$this->tags[$tag] = $attr;
		return $this;
	}


	/**
	 * With no argument: returns the identity tag (a single string; defaults to
	 * Definition::DefaultTag when not explicitly set), used by tag-aware autowiring
	 * and Container::get($type, $tag) lookups.
	 *
	 * With a tag name: returns the per-tag metadata value from the legacy multi-tag
	 * bag (or null if not set).
	 *
	 * @return ($name is null ? string : mixed)
	 */
	final public function getTag(?string $name = null): mixed
	{
		return $name === null
			? ($this->tag ?? self::DefaultTag)
			: ($this->tags[$name] ?? null);
	}


	/**
	 * Sets the identity tag used by tag-aware autowiring (Container::get($type, $tag)).
	 * Pass null to fall back to the implicit "default" tag.
	 */
	final public function setTag(?string $tag): static
	{
		$this->tag = $tag;
		return $this;
	}


	public const DefaultTag = 'default';


	/**
	 * Sets the autowiring mode. Pass false to disable, true to enable for all types, or one or more class names to restrict autowiring to specific types.
	 * @param  bool|class-string|class-string[]  $state
	 */
	final public function setAutowired(bool|string|array $state = true): static
	{
		if ($this->notifier && $this->autowired !== $state) {
			($this->notifier)();
		}

		$this->autowired = is_string($state) || is_array($state)
			? (array) $state
			: $state;
		return $this;
	}


	/** @return bool|class-string[] */
	final public function getAutowired(): bool|array
	{
		return $this->autowired;
	}


	public function setExported(bool $state = true): static
	{
		return $this->addTag('nette.exported', $state);
	}


	public function isExported(): bool
	{
		return (bool) $this->getTag('nette.exported');
	}


	public function __clone()
	{
		$this->notifier = $this->name = null;
	}


	/********************* life cycle ****************d*g**/


	abstract public function resolveType(Nette\DI\Resolver $resolver): void;


	abstract public function complete(Nette\DI\Resolver $resolver): void;


	abstract public function generateCode(Nette\DI\PhpGenerator $generator): string;


	/** @param (\Closure(): void)|null $notifier */
	final public function setNotifier(?\Closure $notifier): void
	{
		$this->notifier = $notifier;
	}


	/**
	 * @deprecated Use setType() — kept for vendor compatibility (e.g. tracy/tracy bridge).
	 * @param class-string|null $type
	 * @return static
	 */
	public function setClass(?string $type)
	{
		return $this->setType($type);
	}


	/**
	 * @deprecated Use getType() — kept for vendor compatibility.
	 * @return class-string|null
	 */
	public function getClass(): ?string
	{
		return $this->getType();
	}
}

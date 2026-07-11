<?php declare(strict_types=1);

/**
 * This file is part of the Nette Framework (https://nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

namespace Nette\DI;

use Nette;


/**
 * The requested service was not found in the container.
 */
class MissingServiceException extends Nette\InvalidStateException
{
}


/**
 * Failed to create the service instance.
 */
class ServiceCreationException extends Nette\InvalidStateException
{
	public function setMessage(string $message): static
	{
		$this->message = $message;
		return $this;
	}
}


/**
 * A shared-access API was used on a transient service (get() on a per-call service)
 * or a transient-access API on a shared one (create() on a singleton).
 */
class TransientServiceException extends Nette\InvalidStateException
{
}


/**
 * Operation is not allowed while container is resolving dependencies.
 */
class NotAllowedDuringResolvingException extends Nette\InvalidStateException
{
}


/**
 * The DI container configuration is invalid.
 */
class InvalidConfigurationException extends Nette\InvalidStateException
{
}

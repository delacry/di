<?php declare(strict_types=1);

/**
 * Test: AbstractInjectExtension with allowsNonPublic() — public, protected, private
 * and inherited-private property injection through the compiled container.
 */

use Nette\DI;
use Nette\DI\Definitions\Definition;
use Nette\DI\Extensions\AbstractInjectExtension;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class ProbeInject
{
	public function __construct(public ?string $tag = null)
	{
	}
}

class ProbeExtension extends AbstractInjectExtension
{
	protected static function injectAttribute(): string
	{
		return ProbeInject::class;
	}


	protected static function allowsNonPublic(): bool
	{
		return true;
	}


	protected function shouldInjectMembers(Definition $def, string $class): bool
	{
		return true;
	}
}

class Dep
{
}

class ParentService
{
	#[ProbeInject]
	private Dep $inheritedPrivate;


	public function getInheritedPrivate(): Dep
	{
		return $this->inheritedPrivate;
	}
}

class ChildService extends ParentService
{
	#[ProbeInject]
	public Dep $pub;

	#[ProbeInject]
	protected Dep $prot;

	#[ProbeInject]
	private Dep $priv;


	public function getProt(): Dep
	{
		return $this->prot;
	}


	public function getPriv(): Dep
	{
		return $this->priv;
	}
}


$compiler = new DI\Compiler;
$compiler->addExtension('probe', new ProbeExtension);
$container = createContainer($compiler, '
services:
	dep: Dep
	svc: ChildService
');

$dep = $container->getByType(Dep::class);
$svc = $container->getByType(ChildService::class);

Assert::same($dep, $svc->pub);
Assert::same($dep, $svc->getProt());
Assert::same($dep, $svc->getPriv());
Assert::same($dep, $svc->getInheritedPrivate());


class BadReadonly
{
	#[ProbeInject]
	public readonly Dep $ro;
}

Assert::exception(
	function () {
		$compiler = new DI\Compiler;
		$compiler->addExtension('probe', new ProbeExtension);
		createContainer($compiler, '
services:
	dep: Dep
	bad: BadReadonly
');
	},
	Nette\InvalidStateException::class,
	'%a% for injection must not be static or readonly.',
);

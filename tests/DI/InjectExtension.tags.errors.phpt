<?php declare(strict_types=1);

/**
 * Test: Nette\DI\Compiler: #[Inject(tag:)] error cases — untagged on ctor/inject param + ambiguity.
 */

use Nette\DI;
use Nette\DI\Attributes\Inject;
use Tester\Assert;


require __DIR__ . '/../bootstrap.php';


interface IServiceE
{
}

class ImplA implements IServiceE
{
}

class ImplB implements IServiceE
{
}


// #[Inject] without tag on ctor parameter must error — untagged params are autowired by type already.
class CtorNoTag
{
	public function __construct(
		#[Inject]
		public readonly IServiceE $svc,
	) {
	}
}

class InjectMethodNoTag
{
	public IServiceE $svc;


	public function injectIt(#[Inject] IServiceE $svc): void
	{
		$this->svc = $svc;
	}
}


// Two services share the same (type, tag) — should explode at single-resolution time.
class AmbiguousConsumer
{
	#[Inject(tag: 'sharedTag')]
	public IServiceE $svc;
}


Assert::exception(
	function () {
		$compiler = new DI\Compiler;
		$compiler->addExtension('inject', new DI\Extensions\InjectExtension);
		createContainer($compiler, '
services:
	a: ImplA
	bad: CtorNoTag
');
	},
	Nette\InvalidStateException::class,
	'#[Inject] on parameter $svc in %a% requires a tag — untagged parameters are autowired by type automatically.',
);


Assert::exception(
	function () {
		$compiler = new DI\Compiler;
		$compiler->addExtension('inject', new DI\Extensions\InjectExtension);
		createContainer($compiler, '
services:
	a: ImplA
	bad:
		factory: InjectMethodNoTag
		inject: true
');
	},
	Nette\InvalidStateException::class,
	'#[Inject] on parameter $svc in InjectMethodNoTag::injectIt() requires a tag — untagged parameters are autowired by type automatically.',
);


Assert::exception(
	function () {
		$compiler = new DI\Compiler;
		$compiler->addExtension('inject', new DI\Extensions\InjectExtension);
		createContainer($compiler, '
services:
	a:
		factory: ImplA
		tag: sharedTag
	b:
		factory: ImplB
		tag: sharedTag
	consumer:
		factory: AmbiguousConsumer
		inject: true
');
	},
	DI\ServiceCreationException::class,
	"Multiple services of type IServiceE with tag 'sharedTag' found: a, b",
);

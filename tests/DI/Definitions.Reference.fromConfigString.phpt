<?php declare(strict_types=1);

/**
 * Test: Nette\DI\Definitions\Reference::fromConfigString()
 */

use Nette\DI\Definitions\Reference;
use Tester\Assert;


require __DIR__ . '/../bootstrap.php';


test('type reference with identity tag', function () {
	$ref = Reference::fromConfigString('@Foo\Bar#doctrine');
	Assert::type(Reference::class, $ref);
	Assert::same('Foo\Bar', $ref->getValue());
	Assert::same('doctrine', $ref->getTag());
	Assert::true($ref->isType());
});

test('type reference without tag', function () {
	$ref = Reference::fromConfigString('@Foo\Bar');
	Assert::same('Foo\Bar', $ref->getValue());
	Assert::null($ref->getTag());
	Assert::true($ref->isType());
});

test('bare word is a (legacy) name reference', function () {
	$ref = Reference::fromConfigString('@service');
	Assert::same('service', $ref->getValue());
	Assert::null($ref->getTag());
	Assert::true($ref->isName());
});

test('non-references and malformed strings return null', function () {
	Assert::null(Reference::fromConfigString('service'));   // no leading @
	Assert::null(Reference::fromConfigString('@'));          // empty target
	Assert::null(Reference::fromConfigString('@#doctrine')); // tag without a type
	Assert::null(Reference::fromConfigString('@Foo\Bar#'));  // trailing # without a tag
});

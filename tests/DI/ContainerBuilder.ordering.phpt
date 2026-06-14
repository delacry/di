<?php declare(strict_types=1);

/**
 * Test: Nette\DI\ContainerBuilder::findByType() applies DefinitionOrdering
 */

use Nette\DI\ContainerBuilder;
use Tester\Assert;


require __DIR__ . '/../bootstrap.php';


interface Orderable
{
}

class Alpha implements Orderable
{
}

class Bravo implements Orderable
{
}

class Charlie implements Orderable
{
}


// no ordering metadata: insertion order preserved (gate keeps existing behaviour)
$plain = new ContainerBuilder;
$plain->addDefinition('bravo')->setType(Bravo::class);
$plain->addDefinition('alpha')->setType(Alpha::class);
Assert::same(['bravo', 'alpha'], array_keys($plain->findByType(Orderable::class)));

// once a member carries priority, the whole collection comes back ordered
$ordered = new ContainerBuilder;
$ordered->addDefinition('bravo')->setType(Bravo::class);
$ordered->addDefinition('alpha')->setType(Alpha::class);
$ordered->addDefinition('high')->setType(Charlie::class)->setPriority(100);
Assert::same(['high', 'alpha', 'bravo'], array_keys($ordered->findByType(Orderable::class)));

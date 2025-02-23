<?php

/**
 * Test: Nette\DI\ContainerBuilder and rich syntax.
 */

declare(strict_types=1);

use Nette\DI;
use Nette\DI\Definitions\Statement;
use Tester\Assert;


require __DIR__ . '/../bootstrap.php';


class Factory
{
	public function create(): Obj
	{
		return new Obj;
	}


	public function mark(Obj $obj)
	{
		$obj->mark = true;
	}
}

class Obj
{
	public function foo(...$args): self
	{
		$this->args[] = $args;
		return $this;
	}
}


$builder = new DI\ContainerBuilder;
$one = $builder->addDefinition('one')
	->setCreator([new Statement(Factory::class), 'create'])
	->addSetup([new Statement(Factory::class), 'mark'], ['@self']);

$two = $builder->addDefinition('two')
	->setCreator([new Statement([$one, 'foo'], [1]), 'foo'], [2]);


$container = createContainer($builder);

Assert::same(Obj::class, $one->getType());
Assert::type(Obj::class, $container->getService('one'));
Assert::true($container->getService('one')->mark);

Assert::same(Obj::class, $two->getType());
Assert::type(Obj::class, $container->getService('two'));
Assert::true($container->getService('two')->mark);
Assert::same([[1], [2]], $container->getService('two')->args);

<?php

/**
 * This file is part of the Nette Framework (https://nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Nette\DI;

use Nette;
use Nette\DI\Definitions\Definition;
use Nette\DI\Definitions\Reference;
use Nette\DI\Definitions\Statement;
use Nette\PhpGenerator\Helpers as PhpHelpers;
use Nette\Utils\Callback;
use Nette\Utils\Reflection;
use Nette\Utils\Strings;
use Nette\Utils\Validators;
use ReflectionClass;


/**
 * Services resolver
 * @internal
 */
class Resolver
{
	use Nette\SmartObject;

	private ContainerBuilder $builder;
	private ?Definition $currentService = null;
	private ?string $currentServiceType = null;
	private bool $currentServiceAllowed = false;

	/** circular reference detector */
	private \SplObjectStorage $recursive;


	public function __construct(ContainerBuilder $builder)
	{
		$this->builder = $builder;
		$this->recursive = new \SplObjectStorage;
	}


	public function getContainerBuilder(): ContainerBuilder
	{
		return $this->builder;
	}


	public function resolveDefinition(Definition $def): void
	{
		if ($this->recursive->contains($def)) {
			$names = array_map(fn($item) => $item->getName(), iterator_to_array($this->recursive));
			throw new ServiceCreationException(sprintf('Circular reference detected for services: %s.', implode(', ', $names)));
		}

		try {
			$this->recursive->attach($def);

			$def->resolveType($this);

			if (!$def->getType()) {
				throw new ServiceCreationException('Type of service is unknown.');
			}
		} catch (\Throwable $e) {
			throw $this->completeException($e, $def);

		} finally {
			$this->recursive->detach($def);
		}
	}


	public function resolveReferenceType(Reference $ref): ?string
	{
		if ($ref->isSelf()) {
			return $this->currentServiceType;
		} elseif ($ref->isType()) {
			return ltrim($ref->getValue(), '\\');
		}

		$def = $this->resolveReference($ref);
		if (!$def->getType()) {
			$this->resolveDefinition($def);
		}

		return $def->getType();
	}


	public function resolveEntityType(Statement $statement): ?string
	{
		$entity = $this->normalizeEntity($statement);

		if (is_array($entity)) {
			if ($entity[0] instanceof Reference || $entity[0] instanceof Statement) {
				$entity[0] = $this->resolveEntityType($entity[0] instanceof Statement ? $entity[0] : new Statement($entity[0]));
				if (!$entity[0]) {
					return null;
				}
			}

			try {
				/** @var \ReflectionMethod|\ReflectionFunction $reflection */
				$reflection = Callback::toReflection($entity[0] === '' ? $entity[1] : $entity);
				$refClass = $reflection instanceof \ReflectionMethod
					? $reflection->getDeclaringClass()
					: null;
			} catch (\ReflectionException $e) {
				$refClass = $reflection = null;
			}

			if (isset($e) || ($refClass && (!$reflection->isPublic()
				|| ($refClass->isTrait() && !$reflection->isStatic())
			))) {
				throw new ServiceCreationException(sprintf('Method %s() is not callable.', Callback::toString($entity)), 0, $e ?? null);
			}

			$this->addDependency($reflection);

			$type = Nette\Utils\Type::fromReflection($reflection);
			return $type && !in_array((string) $type, ['object', 'mixed'], true)
				? Helpers::ensureClassType($type, sprintf('return type of %s()', Callback::toString($entity)))
				: null;

		} elseif ($entity instanceof Reference) { // alias or factory
			return $this->resolveReferenceType($entity);

		} elseif (is_string($entity)) { // class
			if (!class_exists($entity)) {
				throw new ServiceCreationException(sprintf(
					interface_exists($entity)
						? "Interface %s can not be used as 'create' or 'factory', did you mean 'implement'?"
						: "Class '%s' not found.",
					$entity,
				));
			}

			return $entity;
		}

		return null;
	}


	public function completeDefinition(Definition $def): void
	{
		$this->currentService = in_array($def, $this->builder->getDefinitions(), true)
			? $def
			: null;
		$this->currentServiceType = $def->getType();
		$this->currentServiceAllowed = false;

		try {
			$def->complete($this);

			$this->addDependency(new \ReflectionClass($def->getType()));

		} catch (\Throwable $e) {
			throw $this->completeException($e, $def);

		} finally {
			$this->currentService = $this->currentServiceType = null;
		}
	}


	public function completeStatement(Statement $statement, bool $currentServiceAllowed = false): Statement
	{
		$this->currentServiceAllowed = $currentServiceAllowed;
		$entity = $this->normalizeEntity($statement);
		$arguments = $this->convertReferences($statement->arguments);
		$getter = fn(string $type, bool $single) => $single
				? $this->getByType($type)
				: array_values(array_filter($this->builder->findAutowired($type), fn($obj) => $obj !== $this->currentService));

		switch (true) {
			case is_string($entity) && Strings::contains($entity, '?'): // PHP literal
				break;

			case $entity === 'not':
			case $entity === 'bool':
			case $entity === 'int':
			case $entity === 'float':
			case $entity === 'string':
				if (count($arguments) !== 1) {
					throw new ServiceCreationException(sprintf('Function %s() expects 1 parameter, %s given.', $entity, count($arguments)));
				}
				break;

			case is_string($entity): // create class
				if (!class_exists($entity)) {
					throw new ServiceCreationException(sprintf("Class '%s' not found.", $entity));
				} elseif ((new ReflectionClass($entity))->isAbstract()) {
					throw new ServiceCreationException(sprintf('Class %s is abstract.', $entity));
				} elseif (($rm = (new ReflectionClass($entity))->getConstructor()) !== null && !$rm->isPublic()) {
					throw new ServiceCreationException(sprintf('Class %s has %s constructor.', $entity, $rm->isProtected() ? 'protected' : 'private'));
				} elseif ($constructor = (new ReflectionClass($entity))->getConstructor()) {
					$arguments = self::autowireArguments($constructor, $arguments, $getter);
					$this->addDependency($constructor);
				} elseif ($arguments) {
					throw new ServiceCreationException(sprintf(
						'Unable to pass arguments, class %s has no constructor.',
						$entity,
					));
				}

				break;

			case $entity instanceof Reference:
				$entity = [new Reference(ContainerBuilder::THIS_CONTAINER), Container::getMethodName($entity->getValue())];
				break;

			case is_array($entity):
				if (!preg_match('#^\$?(\\\\?' . PhpHelpers::PHP_IDENT . ')+(\[\])?$#D', $entity[1])) {
					throw new ServiceCreationException(sprintf(
						"Expected function, method or property name, '%s' given.",
						$entity[1],
					));
				}

				switch (true) {
					case $entity[0] === '': // function call
						if (!Nette\Utils\Arrays::isList($arguments)) {
							throw new ServiceCreationException(sprintf(
								'Unable to pass specified arguments to %s.',
								$entity[0],
							));
						} elseif (!function_exists($entity[1])) {
							throw new ServiceCreationException(sprintf("Function %s doesn't exist.", $entity[1]));
						}

						$rf = new \ReflectionFunction($entity[1]);
						$arguments = self::autowireArguments($rf, $arguments, $getter);
						$this->addDependency($rf);
						break;

					case $entity[0] instanceof Statement:
						$entity[0] = $this->completeStatement($entity[0], $this->currentServiceAllowed);
						// break omitted

					case is_string($entity[0]): // static method call
					case $entity[0] instanceof Reference:
						if ($entity[1][0] === '$') { // property getter, setter or appender
							Validators::assert($arguments, 'list:0..1', "setup arguments for '" . Callback::toString($entity) . "'");
							if (!$arguments && str_ends_with($entity[1], '[]')) {
								throw new ServiceCreationException(sprintf('Missing argument for %s.', $entity[1]));
							}
						} elseif (
							$type = $entity[0] instanceof Reference
								? $this->resolveReferenceType($entity[0])
								: $this->resolveEntityType($entity[0] instanceof Statement ? $entity[0] : new Statement($entity[0]))
						) {
							$rc = new ReflectionClass($type);
							if ($rc->hasMethod($entity[1])) {
								$rm = $rc->getMethod($entity[1]);
								if (!$rm->isPublic()) {
									throw new ServiceCreationException(sprintf('%s::%s() is not callable.', $type, $entity[1]));
								}

								$arguments = self::autowireArguments($rm, $arguments, $getter);
								$this->addDependency($rm);

							} elseif (!Nette\Utils\Arrays::isList($arguments)) {
								throw new ServiceCreationException(sprintf('Unable to pass specified arguments to %s::%s().', $type, $entity[1]));
							}
						}
				}
		}

		try {
			$arguments = $this->completeArguments($arguments);

		} catch (ServiceCreationException $e) {
			if (!str_contains($e->getMessage(), "\nRelated to")) {
				if (is_string($entity)) {
					$desc = $entity . '::__construct()';
				} else {
					$desc = Helpers::entityToString($entity);
					$desc = preg_replace('~@self::~A', '', $desc);
				}

				if ($currentServiceAllowed) {
					$desc .= ' in setup';
				}

				$e->setMessage($e->getMessage() . "\nRelated to $desc.");
			}

			throw $e;
		}

		return new Statement($entity, $arguments);
	}


	public function completeArguments(array $arguments): array
	{
		array_walk_recursive($arguments, function (&$val): void {
			if ($val instanceof Statement) {
				$entity = $val->getEntity();
				if ($entity === 'typed' || $entity === 'tagged') {
					$services = [];
					$current = $this->currentService
						? $this->currentService->getName()
						: null;
					foreach ($val->arguments as $argument) {
						foreach ($entity === 'tagged' ? $this->builder->findByTag($argument) : $this->builder->findAutowired($argument) as $name => $foo) {
							if ($name !== $current) {
								$services[] = new Reference($name);
							}
						}
					}

					$val = $this->completeArguments($services);
				} else {
					$val = $this->completeStatement($val, $this->currentServiceAllowed);
				}
			} elseif ($val instanceof Definition || $val instanceof Reference) {
				$val = $this->normalizeEntity(new Statement($val));
			}
		});
		return $arguments;
	}


	/** Returns literal, Class, Reference, [Class, member], [, globalFunc], [Reference, member], [Statement, member] */
	private function normalizeEntity(Statement $statement): string|array|Reference
	{
		$entity = $statement->getEntity();
		if (is_array($entity)) {
			$item = &$entity[0];
		} else {
			$item = &$entity;
		}

		if ($item instanceof Definition) {
			$name = current(array_keys($this->builder->getDefinitions(), $item, true));
			if ($name === false) {
				throw new ServiceCreationException(sprintf("Service '%s' not found in definitions.", $item->getName()));
			}

			$item = new Reference($name);
		}

		if ($item instanceof Reference) {
			$item = $this->normalizeReference($item);
		}

		return $entity;
	}


	/**
	 * Normalizes reference to 'self' or named reference (or leaves it typed if it is not possible during resolving) and checks existence of service.
	 */
	public function normalizeReference(Reference $ref): Reference
	{
		$service = $ref->getValue();
		if ($ref->isSelf()) {
			return $ref;
		} elseif ($ref->isName()) {
			if (!$this->builder->hasDefinition($service)) {
				throw new ServiceCreationException(sprintf("Reference to missing service '%s'.", $service));
			}

			return $this->currentService && $service === $this->currentService->getName()
				? new Reference(Reference::SELF)
				: $ref;
		}

		try {
			return $this->getByType($service);
		} catch (NotAllowedDuringResolvingException $e) {
			return new Reference($service);
		}
	}


	public function resolveReference(Reference $ref): Definition
	{
		return $ref->isSelf()
			? $this->currentService
			: $this->builder->getDefinition($ref->getValue());
	}


	/**
	 * Returns named reference to service resolved by type (or 'self' reference for local-autowiring).
	 * @throws ServiceCreationException when multiple found
	 * @throws MissingServiceException when not found
	 */
	public function getByType(string $type): Reference
	{
		if (
			$this->currentService
			&& $this->currentServiceAllowed
			&& is_a($this->currentServiceType, $type, true)
		) {
			return new Reference(Reference::SELF);
		}

		$name = $this->builder->getByType($type, true);
		if (
			!$this->currentServiceAllowed
			&& $this->currentService === $this->builder->getDefinition($name)
		) {
			throw new MissingServiceException;
		}

		return new Reference($name);
	}


	/**
	 * Adds item to the list of dependencies.
	 */
	public function addDependency(\ReflectionClass|\ReflectionFunctionAbstract|string $dep): static
	{
		$this->builder->addDependency($dep);
		return $this;
	}


	private function completeException(\Throwable $e, Definition $def): ServiceCreationException
	{
		$message = $e->getMessage();
		if ($e instanceof ServiceCreationException && str_starts_with($message, '[Service ')) {
			return $e;
		}

		if ($tmp = $def->getType()) {
			$message = str_replace(" $tmp::", ' ' . preg_replace('~.*\\\\~', '', $tmp) . '::', $message);
		}

		$message = '[' . $def->getDescriptor() . "]\n" . $message;

		return $e instanceof ServiceCreationException
			? $e->setMessage($message)
			: new ServiceCreationException($message, 0, $e);
	}


	private function convertReferences(array $arguments): array
	{
		array_walk_recursive($arguments, function (&$val): void {
			if (is_string($val) && strlen($val) > 1 && $val[0] === '@' && $val[1] !== '@') {
				$pair = explode('::', substr($val, 1), 2);
				if (!isset($pair[1])) { // @service
					$val = new Reference($pair[0]);
				} elseif (preg_match('#^[A-Z][A-Z0-9_]*$#D', $pair[1], $m)) { // @service::CONSTANT
					$val = ContainerBuilder::literal($this->resolveReferenceType(new Reference($pair[0])) . '::' . $pair[1]);
				} else { // @service::property
					$val = new Statement([new Reference($pair[0]), '$' . $pair[1]]);
				}
			} elseif (is_string($val) && str_starts_with($val, '@@')) { // escaped text @@
				$val = substr($val, 1);
			}
		});
		return $arguments;
	}


	/**
	 * Add missing arguments using autowiring.
	 * @param  (callable(string $type, bool $single): (object|object[]|null))  $getter
	 * @throws ServiceCreationException
	 */
	public static function autowireArguments(
		\ReflectionFunctionAbstract $method,
		array $arguments,
		callable $getter,
	): array {
		$useName = false;
		$num = -1;
		$res = [];

		foreach ($method->getParameters() as $num => $param) {
			$paramName = $param->name;

			if ($param->isVariadic()) {
				if (array_key_exists($paramName, $arguments)) {
					if (!is_array($arguments[$paramName])) {
						throw new ServiceCreationException(sprintf(
							'Parameter %s must be array, %s given.',
							Reflection::toString($param),
							gettype($arguments[$paramName]),
						));
					}

					$variadics = $arguments[$paramName];
					unset($arguments[$paramName]);
				} else {
					$variadics = array_merge($arguments);
					$arguments = [];
				}

				if ($useName) {
					$res[$paramName] = $variadics;
				} else {
					$res = array_merge($res, $variadics);
				}

			} elseif (array_key_exists($paramName, $arguments)) {
				$res[$useName ? $paramName : $num] = $arguments[$paramName];
				unset($arguments[$paramName], $arguments[$num]);

			} elseif (array_key_exists($num, $arguments)) {
				$res[$useName ? $paramName : $num] = $arguments[$num];
				unset($arguments[$num]);

			} elseif (($aw = self::autowireArgument($param, $getter)) !== null) {
				$res[$useName ? $paramName : $num] = $aw;

			} elseif ($param->isOptional()) {
				$useName = true;

			} else {
				$res[$num] = null;

				trigger_error(sprintf(
					'The parameter %s should have a declared value in the configuration.',
					Reflection::toString($param),
				), E_USER_DEPRECATED);
			}
		}

		// extra parameters
		while (!$useName && array_key_exists(++$num, $arguments)) {
			$res[$num] = $arguments[$num];
			unset($arguments[$num]);
		}

		if ($arguments) {
			throw new ServiceCreationException(sprintf(
				'Unable to pass specified arguments to %s.',
				Reflection::toString($method),
			));
		}

		return $res;
	}


	/**
	 * Resolves missing argument using autowiring.
	 * @param  (callable(string $type, bool $single): (object|object[]|null))  $getter
	 * @throws ServiceCreationException
	 */
	private static function autowireArgument(\ReflectionParameter $parameter, callable $getter): mixed
	{
		$method = $parameter->getDeclaringFunction();
		$desc = Reflection::toString($parameter);
		$type = Nette\Utils\Type::fromReflection($parameter);

		if ($type?->isIntersection()) {
			throw new ServiceCreationException(sprintf(
				'Parameter %s has intersection type, so its value must be specified.',
				$desc,
			));

		} elseif ($type?->isClass()) {
			$class = $type->getSingleName();
			try {
				$res = $getter($class, true);
			} catch (MissingServiceException $e) {
				$res = null;
			} catch (ServiceCreationException $e) {
				throw new ServiceCreationException(sprintf("%s\nRequired by %s.", $e->getMessage(), $desc), 0, $e);
			}

			if ($res !== null || $parameter->allowsNull()) {
				return $res;
			} elseif (class_exists($class) || interface_exists($class)) {
				throw new ServiceCreationException(sprintf(
					"Service of type %s required by %s not found.\nDid you add it to configuration file?",
					$class,
					$desc,
				));
			} else {
				throw new ServiceCreationException(sprintf(
					"Class '%s' required by %s not found.\nCheck the parameter type and 'use' statements.",
					$class,
					$desc,
				));
			}
		} elseif (
			$method instanceof \ReflectionMethod
			&& $type?->getSingleName() === 'array'
			&& preg_match('#@param[ \t]+(?|([\w\\\\]+)\[\]|array<int,\s*([\w\\\\]+)>)[ \t]+\$' . $parameter->name . '#', (string) $method->getDocComment(), $m)
			&& ($itemType = Reflection::expandClassName($m[1], $method->getDeclaringClass()))
			&& (class_exists($itemType) || interface_exists($itemType))
		) {
			return $getter($itemType, false);

		} elseif ($parameter->isOptional()) {
			return null;

		} else {
			throw new ServiceCreationException(sprintf(
				'Parameter %s has %s, so its value must be specified.',
				$desc,
				$type?->isUnion() ? 'union type and no default value' : 'no class type or default value',
			));
		}
	}
}

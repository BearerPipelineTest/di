<?php

/**
 * This file is part of the Nette Framework (https://nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Nette\DI;

use Nette;
use Nette\DI\Definitions\Reference;
use Nette\DI\Definitions\Statement;
use Nette\Utils\Reflection;
use Nette\Utils\Type;


/**
 * The DI helpers.
 * @internal
 */
final class Helpers
{
	use Nette\StaticClass;

	/**
	 * Expands %placeholders%.
	 * @throws Nette\InvalidArgumentException
	 */
	public static function expand(mixed $var, array $params, bool|array $recursive = false): mixed
	{
		if (is_array($var)) {
			$res = [];
			foreach ($var as $key => $val) {
				$res[self::expand($key, $params, $recursive)] = self::expand($val, $params, $recursive);
			}

			return $res;

		} elseif ($var instanceof Statement) {
			return new Statement(self::expand($var->getEntity(), $params, $recursive), self::expand($var->arguments, $params, $recursive));

		} elseif ($var === '%parameters%' && !array_key_exists('parameters', $params)) {
			return $recursive
				? self::expand($params, $params, (is_array($recursive) ? $recursive : []))
				: $params;

		} elseif (!is_string($var)) {
			return $var;
		}

		$parts = preg_split('#%([\w.-]*)%#i', $var, -1, PREG_SPLIT_DELIM_CAPTURE);
		$res = [];
		$php = false;
		foreach ($parts as $n => $part) {
			if ($n % 2 === 0) {
				$res[] = $part;

			} elseif ($part === '') {
				$res[] = '%';

			} elseif (isset($recursive[$part])) {
				throw new Nette\InvalidArgumentException(sprintf(
					'Circular reference detected for variables: %s.',
					implode(', ', array_keys($recursive)),
				));

			} else {
				$val = $params;
				foreach (explode('.', $part) as $key) {
					if (is_array($val) && array_key_exists($key, $val)) {
						$val = $val[$key];
					} elseif ($val instanceof DynamicParameter) {
						$val = new DynamicParameter($val . '[' . var_export($key, true) . ']');
					} else {
						throw new Nette\InvalidArgumentException(sprintf("Missing parameter '%s'.", $part));
					}
				}

				if ($recursive) {
					$val = self::expand($val, $params, (is_array($recursive) ? $recursive : []) + [$part => 1]);
				}

				if (strlen($part) + 2 === strlen($var)) {
					return $val;
				}

				if ($val instanceof DynamicParameter) {
					$php = true;
				} elseif (!is_scalar($val)) {
					throw new Nette\InvalidArgumentException(sprintf("Unable to concatenate non-scalar parameter '%s' into '%s'.", $part, $var));
				}

				$res[] = $val;
			}
		}

		if ($php) {
			$res = array_filter($res, fn($val): bool => $val !== '');
			$res = array_map(fn($val): string => $val instanceof DynamicParameter
					? "($val)"
					: var_export((string) $val, true), $res);
			return new DynamicParameter(implode(' . ', $res));
		}

		return implode('', $res);
	}


	/**
	 * Escapes '%' and '@'
	 */
	public static function escape(mixed $value): mixed
	{
		if (is_array($value)) {
			$res = [];
			foreach ($value as $key => $val) {
				$key = is_string($key) ? str_replace('%', '%%', $key) : $key;
				$res[$key] = self::escape($val);
			}

			return $res;
		} elseif (is_string($value)) {
			return preg_replace('#^@|%#', '$0$0', $value);
		}

		return $value;
	}


	/**
	 * Process constants recursively.
	 */
	public static function filterArguments(array $args): array
	{
		foreach ($args as $k => $v) {
			if (
				PHP_VERSION_ID >= 80100
				&& is_string($v)
				&& preg_match('#^([\w\\\\]+)::\w+$#D', $v, $m)
				&& enum_exists($m[1])
			) {
				$args[$k] = new Nette\PhpGenerator\PhpLiteral($v);
			} elseif (is_string($v) && preg_match('#^[\w\\\\]*::[A-Z][A-Z0-9_]*$#D', $v)) {
				$args[$k] = constant(ltrim($v, ':'));
			} elseif (is_string($v) && preg_match('#^@[\w\\\\]+$#D', $v)) {
				$args[$k] = new Reference(substr($v, 1));
			} elseif (is_array($v)) {
				$args[$k] = self::filterArguments($v);
			} elseif ($v instanceof Statement) {
				[$tmp] = self::filterArguments([$v->getEntity()]);
				$args[$k] = new Statement($tmp, self::filterArguments($v->arguments));
			}
		}

		return $args;
	}


	/**
	 * Replaces @extension with real extension name in service definition.
	 */
	public static function prefixServiceName(mixed $config, string $namespace): mixed
	{
		if (is_string($config)) {
			if (strncmp($config, '@extension.', 10) === 0) {
				$config = '@' . $namespace . '.' . substr($config, 11);
			}
		} elseif ($config instanceof Reference) {
			if (strncmp($config->getValue(), 'extension.', 9) === 0) {
				$config = new Reference($namespace . '.' . substr($config->getValue(), 10));
			}
		} elseif ($config instanceof Statement) {
			return new Statement(
				self::prefixServiceName($config->getEntity(), $namespace),
				self::prefixServiceName($config->arguments, $namespace),
			);
		} elseif (is_array($config)) {
			foreach ($config as &$val) {
				$val = self::prefixServiceName($val, $namespace);
			}
		}

		return $config;
	}


	/**
	 * Returns an annotation value.
	 */
	public static function parseAnnotation(\Reflector $ref, string $name): ?string
	{
		if (!Reflection::areCommentsAvailable()) {
			throw new Nette\InvalidStateException('You have to enable phpDoc comments in opcode cache.');
		}

		$re = '#[\s*]@' . preg_quote($name, '#') . '(?=\s|$)(?:[ \t]+([^@\s]\S*))?#';
		if ($ref->getDocComment() && preg_match($re, trim($ref->getDocComment(), '/*'), $m)) {
			return $m[1] ?? '';
		}

		return null;
	}


	public static function ensureClassType(
		?Type $type,
		string $hint,
		string $descriptor = '',
		bool $allowNullable = false,
	): string {
		$descriptor = $descriptor ? "[$descriptor]\n" : '';
		if (!$type) {
			throw new ServiceCreationException(sprintf('%s%s is not declared.', $descriptor, ucfirst($hint)));
		} elseif (!$type->isClass() || (!$allowNullable && $type->isUnion())) {
			throw new ServiceCreationException(sprintf("%s%s is not expected to be %sunion/intersection/built-in, '%s' given.", $descriptor, ucfirst($hint), $allowNullable ? '' : 'nullable/', $type));
		}

		$class = $type->getSingleName();
		if (!class_exists($class) && !interface_exists($class)) {
			throw new ServiceCreationException(sprintf("%sClass '%s' not found.\nCheck the %s.", $descriptor, $class, $hint));
		}

		return $class;
	}


	public static function normalizeClass(string $type): string
	{
		return class_exists($type) || interface_exists($type)
			? (new \ReflectionClass($type))->name
			: $type;
	}


	/**
	 * Non data-loss type conversion.
	 * @throws Nette\InvalidStateException
	 */
	public static function convertType(mixed $value, string $type): mixed
	{
		if (is_scalar($value)) {
			$norm = ($value === false ? '0' : (string) $value);
			if ($type === 'float') {
				$norm = preg_replace('#\.0*$#D', '', $norm);
			}

			$orig = $norm;
			settype($norm, $type);
			if ($orig === ($norm === false ? '0' : (string) $norm)) {
				return $norm;
			}
		}

		throw new Nette\InvalidStateException(sprintf(
			'Cannot convert %s to %s.',
			is_scalar($value) ? "'$value'" : gettype($value),
			$type,
		));
	}


	public static function entityToString(string|array|Reference $entity, bool $inner = false): string
	{
		if (is_string($entity)) {
			return $entity . ($inner ? '()' : '');

		} elseif ($entity instanceof Reference) {
			return '@' . $entity->getValue();

		} else {
			[$a, $b] = $entity;
			return self::entityToString($a instanceof Statement ? $a->entity : $a, true)
				. '::'
				. $b
				. (str_contains($b, '$') ? '' : '()');
		}
	}
}

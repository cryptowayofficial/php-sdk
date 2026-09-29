<?php
/**
 * Canonical JSON as Cryptoway signs it, Python's json.dumps(data, separators=(",", ":"),
 * sort_keys=True): object keys sorted recursively, lists in order, non-ASCII escaped,
 * "/" unescaped. Objects are stdClass, so an empty object encodes as {} and not [].
 */

namespace Cryptoway\Core;

final class CanonicalJson {

	private function __construct() {}

	/**
	 * @throws \JsonException When the data cannot be encoded (INF, bad UTF-8).
	 */
	public static function encode(mixed $data): string {
		// serialize_precision 17 (some php.ini files) prints 30.26 as 30.260000000000002
		// and breaks every signature; -1 matches Python. Restored after the call.
		$previous = ini_get('serialize_precision');

		if ('-1' !== $previous) {
			@ini_set('serialize_precision', '-1');
		}

		try {
			return json_encode(
				self::sortRecursive($data),
				JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
			);
		} finally {
			if ('-1' !== $previous && false !== $previous) {
				@ini_set('serialize_precision', $previous);
			}
		}
	}

	/** Associative arrays become stdClass; lists stay arrays. */
	public static function toObject(mixed $value): mixed {
		if ($value instanceof \stdClass) {
			foreach ($value as $k => $v) {
				$value->{$k} = self::toObject($v);
			}

			return $value;
		}

		if (!is_array($value)) {
			return $value;
		}

		if (self::isList($value)) {
			return array_map([self::class, 'toObject'], $value);
		}

		$object = new \stdClass();

		foreach ($value as $k => $v) {
			$object->{(string)$k} = self::toObject($v);
		}

		return $object;
	}

	/** Whether an array is a JSON list rather than an object. */
	public static function isList(array $array): bool {
		if ([] === $array) {
			return true;
		}

		return array_keys($array) === range(0, count($array) - 1);
	}

	private static function sortRecursive(mixed $value): mixed {
		if ($value instanceof \stdClass) {
			$pairs = (array)$value;

			ksort($pairs, SORT_STRING);

			$sorted = new \stdClass();

			foreach ($pairs as $k => $v) {
				$sorted->{(string)$k} = self::sortRecursive($v);
			}

			return $sorted;
		}

		if (!is_array($value)) {
			return $value;
		}

		if (self::isList($value)) {
			return array_map([self::class, 'sortRecursive'], $value);
		}

		ksort($value, SORT_STRING);

		$sorted = new \stdClass();

		foreach ($value as $k => $v) {
			$sorted->{(string)$k} = self::sortRecursive($v);
		}

		return $sorted;
	}
}

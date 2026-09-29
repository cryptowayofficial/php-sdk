<?php
/**
 * HMAC signing for API requests and webhooks:
 * signature = HMAC-SHA256(key, timestamp + canonical_json(data)).
 */

namespace Cryptoway\Core;

final class Signature {

	/** Allowed webhook timestamp skew, seconds; wider than the API's own 15 s for network delay. */
	public const WEBHOOK_TOLERANCE = 300;

	private function __construct() {}

	public static function canonical(mixed $data): string {
		return CanonicalJson::encode($data);
	}

	public static function sign(int $timestamp, mixed $data, string $secret): string {
		return hash_hmac('sha256', (string)$timestamp . self::canonical($data), $secret);
	}

	public static function verify(string $candidate, int $timestamp, mixed $data, string $secret): bool {
		if ('' === $candidate || '' === $secret) {
			return false;
		}

		// Data that cannot be canonicalised (1e999 decodes to INF) cannot match;
		// verification runs before authentication, so it must not throw.
		try {
			$expected = self::sign($timestamp, $data, $secret);
		} catch (\JsonException $e) {
			return false;
		}

		return hash_equals($expected, $candidate);
	}

	/**
	 * Envelope signed for API requests: params, body, path.
	 *
	 * @param array|\stdClass $body Request body; empty for GET.
	 */
	public static function apiEnvelope(string $path, string $params, array|\stdClass $body): \stdClass {
		$envelope = new \stdClass();

		$envelope->params = $params;
		$envelope->body = is_array($body) && [] === $body ? new \stdClass() : CanonicalJson::toObject($body);
		$envelope->path = $path;

		return $envelope;
	}

	/** Associative arrays become stdClass; lists stay arrays. */
	public static function toObject(mixed $value): mixed {
		return CanonicalJson::toObject($value);
	}
}

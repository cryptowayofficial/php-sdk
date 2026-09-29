<?php
/**
 * Authenticates a webhook Cryptoway posted to the shop. The freshness of the
 * timestamp, the signature and the replay guard are checked here in that order:
 * skipping any one of them lets a stranger mark an order paid.
 *
 *     signature = HMAC-SHA256(callback key, timestamp + canonical_json(body))
 *     headers:    X-SIGNATURE, X-TIMESTAMP
 */

namespace Cryptoway\Core;

final class Webhook {

	/** Largest body accepted; everything before the signature check runs unauthenticated. */
	public const MAX_BODY_BYTES = 65536;

	public const OK = 'ok';
	public const BODY_EMPTY = 'body_empty';
	public const BODY_TOO_LARGE = 'body_too_large';
	public const BAD_JSON = 'bad_json';
	public const BAD_TIMESTAMP = 'bad_timestamp';
	public const STALE = 'stale';
	public const BAD_SIGNATURE = 'bad_signature';
	public const NO_KEY = 'no_key';
	public const REPLAY = 'replay';

	private string $callback_key;

	private int $tolerance;

	/** @var callable|null */
	private $seen;

	/**
	 * @param string        $callback_key The project's callback key (Cryptoway account -> project), not the API secret.
	 * @param callable|null $seen         fn(string $fingerprint, array $payload): bool — true when this delivery was already applied.
	 * @param int           $tolerance    Accepted clock difference in seconds; the default allows for delivery delay.
	 */
	public function __construct(string $callback_key, ?callable $seen = null, int $tolerance = Signature::WEBHOOK_TOLERANCE) {
		$this->callback_key = trim($callback_key);
		$this->seen = $seen;
		$this->tolerance = $tolerance > 0 ? $tolerance : Signature::WEBHOOK_TOLERANCE;
	}

	/**
	 * @param string   $raw_body The request body exactly as received; do not re-encode it first.
	 * @param int|null $now      Current time; for tests.
	 */
	public function check(string $raw_body, string $timestamp_header, string $signature_header, ?int $now = null): WebhookResult {
		$now = null === $now ? time() : $now;

		if ('' === $this->callback_key) {
			// Answered 503, not 401: the delivery is worth having again once a key is stored.
			return new WebhookResult(false, self::NO_KEY, 'No callback key is configured, so the notification cannot be authenticated.');
		}

		$signature = trim($signature_header);
		$timestamp_header = trim($timestamp_header);

		if ('' === $signature) {
			return new WebhookResult(false, self::BAD_SIGNATURE, 'The X-SIGNATURE header is missing.');
		}

		if ('' === $timestamp_header || !ctype_digit(ltrim($timestamp_header, '-')) || 0 === (int)$timestamp_header) {
			return new WebhookResult(false, self::BAD_TIMESTAMP, 'The X-TIMESTAMP header is missing or is not a Unix timestamp.');
		}

		$timestamp = (int)$timestamp_header;

		// Without a freshness check an intercepted delivery stays valid for ever.
		$skew = $now - $timestamp;

		if (abs($skew) > $this->tolerance) {
			return new WebhookResult(false, self::STALE, sprintf('Time skew %+d s over a %d s tolerance.', $skew, $this->tolerance));
		}

		if (strlen($raw_body) > self::MAX_BODY_BYTES) {
			return new WebhookResult(false, self::BODY_TOO_LARGE, sprintf('Body of %d bytes is over the %d byte limit.', strlen($raw_body), self::MAX_BODY_BYTES));
		}

		if ('' === $raw_body) {
			return new WebhookResult(false, self::BODY_EMPTY, 'The body is empty.');
		}

		// Decoded as objects: the signed canonical string depends on the object/list distinction.
		$decoded = json_decode($raw_body);

		if (null === $decoded && JSON_ERROR_NONE !== json_last_error()) {
			return new WebhookResult(false, self::BAD_JSON, 'The body is not valid JSON.');
		}

		$form = $this->matchSignature($signature, $timestamp, $decoded);

		if (null === $form) {
			return new WebhookResult(false, self::BAD_SIGNATURE, 'The signature does not match the body and the callback key.');
		}

		$payload = self::extractPayload($decoded);

		if (null === $payload) {
			return new WebhookResult(false, self::BAD_JSON, 'The signed body is not a JSON object.', null, '', $form);
		}

		$fingerprint = self::fingerprint($payload, $signature);

		if (null !== $this->seen && true === call_user_func($this->seen, $fingerprint, $payload)) {
			// Cryptoway redelivers until it gets a 2xx; a repeat is normal, not an error.
			return new WebhookResult(false, self::REPLAY, 'This delivery was already applied.', $payload, $fingerprint, $form);
		}

		return new WebhookResult(true, self::OK, 'Authentic.', $payload, $fingerprint, $form);
	}

	/** Sign a body the way Cryptoway does; for tests and stubs, not for a shop. */
	public static function sign(mixed $body, int $timestamp, string $callback_key): string {
		return Signature::sign($timestamp, Signature::toObject($body), $callback_key);
	}

	/**
	 * A stable id for a delivery: the event and the signature that carried it.
	 * Two deliveries of the same event share it; a later status does not.
	 */
	public static function fingerprint(array $payload, string $signature): string {
		$type = (string)($payload['type'] ?? '');
		$id = (string)($payload['id'] ?? ($payload['invoice']['id'] ?? ($payload['external_id'] ?? '')));

		return hash('sha256', $type . '|' . $id . '|' . (string)($payload['status'] ?? '') . '|' . $signature);
	}

	/**
	 * Try the body forms Cryptoway signs. The docs show both a flat body and a
	 * {params, body} envelope, so both are checked with the same key.
	 *
	 * @return string|null Name of the form that matched, or null.
	 */
	private function matchSignature(string $signature, int $timestamp, mixed $decoded): ?string {
		if (Signature::verify($signature, $timestamp, $decoded, $this->callback_key)) {
			return 'body as received';
		}

		$is_wrapped = $decoded instanceof \stdClass
			&& property_exists($decoded, 'params')
			&& property_exists($decoded, 'body');

		if (!$is_wrapped) {
			$wrapped = new \stdClass();
			$wrapped->params = '';
			$wrapped->body = $decoded;

			if (Signature::verify($signature, $timestamp, $wrapped, $this->callback_key)) {
				return 'body in a params/body envelope';
			}

			return null;
		}

		if (Signature::verify($signature, $timestamp, $decoded->body, $this->callback_key)) {
			return 'nested body only';
		}

		return null;
	}

	/** The payload from the envelope or the flat body, as an array. */
	private static function extractPayload(mixed $decoded): ?array {
		if ($decoded instanceof \stdClass && property_exists($decoded, 'params') && property_exists($decoded, 'body')) {
			$decoded = $decoded->body;
		}

		$array = json_decode((string)json_encode($decoded), true);

		return is_array($array) && !CanonicalJson::isList($array) ? $array : null;
	}
}

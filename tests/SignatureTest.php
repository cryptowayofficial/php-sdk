<?php
/**
 * Signing and verification against fixed vectors. The canonical strings and
 * digests below were produced by CPython (json.dumps + hmac.new), not by this
 * library, so a change in either half of the algorithm fails the suite.
 *
 * @license MIT
 */

declare(strict_types=1);

namespace Cryptoway\Core\Tests;

use Cryptoway\Core\CanonicalJson;
use Cryptoway\Core\Signature;
use PHPUnit\Framework\TestCase;

final class SignatureTest extends TestCase {

	private const TIMESTAMP = 1757500000;

	private const CALLBACK_KEY = 'cw_callback_key_vector';

	private const API_SECRET = 'cw_api_secret_vector';

	/** @return array<string, mixed> The body of an invoice event. */
	private function webhookBody(): array {
		return [
			'type'        => 'invoice',
			'id'          => 'inv_0123456789abcdef',
			'status'      => 'executed',
			'external_id' => '12-9f8e7d6c',
		];
	}

	public function testWebhookVector(): void {
		$body = Signature::toObject($this->webhookBody());

		$this->assertSame(
			'{"external_id":"12-9f8e7d6c","id":"inv_0123456789abcdef","status":"executed","type":"invoice"}',
			Signature::canonical($body)
		);

		$this->assertSame(
			'06ef50cc480a29f2157dde96b237e4b896d71efb106cee7baf6fc8581d5d93d8',
			Signature::sign(self::TIMESTAMP, $body, self::CALLBACK_KEY)
		);
	}

	public function testPostEnvelopeVector(): void {
		$envelope = Signature::apiEnvelope('/invoice/', '', [
			'name'                 => 'Order 12',
			'target_value_fiat'    => 30.26,
			'target_currency_fiat' => 'EUR',
			'external_id'          => '12-9f8e7d6c',
			'callback_url'         => 'https://shop.example.com/webhook?x=1&y=2',
		]);

		$this->assertSame(
			'{"body":{"callback_url":"https://shop.example.com/webhook?x=1&y=2","external_id":"12-9f8e7d6c",'
				. '"name":"Order 12","target_currency_fiat":"EUR","target_value_fiat":30.26},"params":"","path":"/invoice/"}',
			Signature::canonical($envelope)
		);

		$this->assertSame(
			'e00fa8f122a08ae004550424fddc6f9fc1f91d620cbd2afe9faafbb021fb7565',
			Signature::sign(self::TIMESTAMP, $envelope, self::API_SECRET)
		);
	}

	/** A GET signs an empty object for a body, not an empty list. */
	public function testGetEnvelopeVector(): void {
		$envelope = Signature::apiEnvelope('/invoice/my/inv_0123456789abcdef/', '', []);

		$this->assertSame('{"body":{},"params":"","path":"/invoice/my/inv_0123456789abcdef/"}', Signature::canonical($envelope));

		$this->assertSame(
			'38d4152c79c2aee190d6ba620704714ac54abe9e2423c7a94170f89c5c05bddc',
			Signature::sign(self::TIMESTAMP, $envelope, self::API_SECRET)
		);
	}

	public function testMixedTypesVector(): void {
		$data = Signature::toObject([
			'zeta'          => [3, 1, ['z' => 1, 'y' => 2]],
			'alpha'         => "Заказ №7 / 50%",
			'amount'        => 30.26,
			'big'           => 1234567.891,
			'flag'          => true,
			'nothing'       => null,
			'nested'        => ['b' => 2, 'a' => ['d' => 4, 'c' => 3]],
			'list_of_lists' => [[2, 1], []],
		]);

		$this->assertSame(
			'0091c1ed4c751d69dae3362a2d5d6d2e8a832f49bc92ba29029a32517a33dd20',
			Signature::sign(self::TIMESTAMP, $data, self::API_SECRET)
		);
	}

	public function testEmptyObjectVector(): void {
		$this->assertSame(
			'3a0862e37ed1906791bb2e996ad0b3a1e23aa1e14725f2c020af33a77ddc6eec',
			Signature::sign(self::TIMESTAMP, new \stdClass(), self::CALLBACK_KEY)
		);
	}

	public function testVerifyAcceptsTheMatchingSignature(): void {
		$body = Signature::toObject($this->webhookBody());
		$signature = Signature::sign(self::TIMESTAMP, $body, self::CALLBACK_KEY);

		$this->assertTrue(Signature::verify($signature, self::TIMESTAMP, $body, self::CALLBACK_KEY));
	}

	public function testVerifyRefusesEverythingElse(): void {
		$body = Signature::toObject($this->webhookBody());
		$signature = Signature::sign(self::TIMESTAMP, $body, self::CALLBACK_KEY);

		$this->assertFalse(Signature::verify($signature, self::TIMESTAMP, $body, 'another-key'), 'wrong key');
		$this->assertFalse(Signature::verify($signature, self::TIMESTAMP + 1, $body, self::CALLBACK_KEY), 'wrong timestamp');
		$this->assertFalse(Signature::verify(strrev($signature), self::TIMESTAMP, $body, self::CALLBACK_KEY), 'wrong digest');
		$this->assertFalse(Signature::verify('', self::TIMESTAMP, $body, self::CALLBACK_KEY), 'no signature');

		// An empty key still makes a computable digest, so the guard is what stops
		// a forgery; verifying with the wrong signature would pass for the wrong reason.
		$forged = hash_hmac('sha256', (string)self::TIMESTAMP . Signature::canonical($body), '');

		$this->assertFalse(Signature::verify($forged, self::TIMESTAMP, $body, ''), 'no key');

		$changed = $this->webhookBody();
		$changed['status'] = 'expired';

		$this->assertFalse(Signature::verify($signature, self::TIMESTAMP, Signature::toObject($changed), self::CALLBACK_KEY), 'changed body');
	}

	/**
	 * Verification runs before the sender is known, so a body built to break the
	 * encoder (1e999 decodes to INF) has to come back false, not throw.
	 */
	public function testVerifyDoesNotThrowOnDataItCannotEncode(): void {
		$decoded = json_decode('{"v":1e999}');

		$this->assertFalse(Signature::verify(str_repeat('a', 64), self::TIMESTAMP, $decoded, self::CALLBACK_KEY));
	}

	public function testEnvelopeCarriesTheQueryStringVerbatim(): void {
		$envelope = Signature::apiEnvelope('/invoice/', 'limit=10&offset=20', []);

		$this->assertSame('limit=10&offset=20', $envelope->params);
		$this->assertSame('/invoice/', $envelope->path);
		$this->assertInstanceOf(\stdClass::class, $envelope->body);
	}

	public function testToObjectIsTheCanonicalJsonHelper(): void {
		$this->assertEquals(CanonicalJson::toObject(['a' => 1]), Signature::toObject(['a' => 1]));
	}

	public function testWebhookToleranceIsFiveMinutes(): void {
		$this->assertSame(300, Signature::WEBHOOK_TOLERANCE);
	}
}

<?php
/**
 * Webhook authentication. A forged or replayed notification marks an order paid,
 * so each refusal below is a separate test rather than one table.
 *
 * @license MIT
 */

declare(strict_types=1);

namespace Cryptoway\Core\Tests;

use Cryptoway\Core\Signature;
use Cryptoway\Core\Webhook;
use PHPUnit\Framework\TestCase;

final class WebhookTest extends TestCase {

	private const KEY = 'cw_callback_key_vector';

	private const NOW = 1757500000;

	/** @return array<string, mixed> */
	private function event(): array {
		return [
			'type'        => 'invoice',
			'id'          => 'inv_0123456789abcdef',
			'status'      => 'executed',
			'external_id' => '12-9f8e7d6c',
		];
	}

	/**
	 * The body as Cryptoway sends it and the matching signature.
	 *
	 * @return array{0:string, 1:string}
	 */
	private function delivery(mixed $body, int $timestamp = self::NOW, string $key = self::KEY): array {
		$raw = (string)json_encode($body, JSON_UNESCAPED_SLASHES);

		return [$raw, Webhook::sign(json_decode($raw), $timestamp, $key)];
	}

	public function testAGenuineNotificationIsAccepted(): void {
		[$raw, $signature] = $this->delivery($this->event());

		$result = (new Webhook(self::KEY))->check($raw, (string)self::NOW, $signature, self::NOW);

		$this->assertTrue($result->isOk());
		$this->assertSame(Webhook::OK, $result->getReason());
		$this->assertSame('invoice', $result->getType());
		$this->assertSame('executed', $result->getPayload()['status']);
		$this->assertSame(200, $result->getHttpStatus());
		$this->assertNotSame('', $result->getFingerprint());
		$this->assertSame('body as received', $result->getSignedForm());
	}

	/** The docs show a params/body envelope as well; both forms carry the same event. */
	public function testTheParamsBodyEnvelopeIsAccepted(): void {
		$envelope = ['params' => '', 'body' => $this->event()];

		[$raw, $signature] = $this->delivery($envelope);

		$result = (new Webhook(self::KEY))->check($raw, (string)self::NOW, $signature, self::NOW);

		$this->assertTrue($result->isOk());
		$this->assertSame('inv_0123456789abcdef', $result->getPayload()['id'], 'the envelope is unwrapped');
	}

	public function testAFlatBodySignedAsAnEnvelopeIsAccepted(): void {
		$raw = (string)json_encode($this->event(), JSON_UNESCAPED_SLASHES);

		$wrapped = new \stdClass();
		$wrapped->params = '';
		$wrapped->body = json_decode($raw);

		$signature = Signature::sign(self::NOW, $wrapped, self::KEY);

		$result = (new Webhook(self::KEY))->check($raw, (string)self::NOW, $signature, self::NOW);

		$this->assertTrue($result->isOk());
		$this->assertSame('body in a params/body envelope', $result->getSignedForm());
	}

	public function testAnEnvelopeSignedOverItsBodyOnlyIsAccepted(): void {
		$envelope = ['params' => '', 'body' => $this->event()];
		$raw = (string)json_encode($envelope, JSON_UNESCAPED_SLASHES);
		$signature = Webhook::sign($this->event(), self::NOW, self::KEY);

		$result = (new Webhook(self::KEY))->check($raw, (string)self::NOW, $signature, self::NOW);

		$this->assertTrue($result->isOk());
		$this->assertSame('nested body only', $result->getSignedForm());
	}

	// Refusals

	public function testABadSignatureIsRefusedWith401(): void {
		[$raw] = $this->delivery($this->event());

		$result = (new Webhook(self::KEY))->check($raw, (string)self::NOW, str_repeat('0', 64), self::NOW);

		$this->assertFalse($result->isOk());
		$this->assertSame(Webhook::BAD_SIGNATURE, $result->getReason());
		$this->assertSame(401, $result->getHttpStatus());
		$this->assertSame([], $result->getPayload(), 'nothing unauthenticated reaches the caller');
	}

	public function testASignatureMadeWithAnotherKeyIsRefused(): void {
		[$raw, $signature] = $this->delivery($this->event(), self::NOW, 'someone-elses-key');

		$this->assertSame(Webhook::BAD_SIGNATURE, (new Webhook(self::KEY))->check($raw, (string)self::NOW, $signature, self::NOW)->getReason());
	}

	public function testAChangedBodyIsRefused(): void {
		[, $signature] = $this->delivery($this->event());

		$tampered = $this->event();
		$tampered['status'] = 'executed ';

		$raw = (string)json_encode($tampered, JSON_UNESCAPED_SLASHES);

		$this->assertSame(Webhook::BAD_SIGNATURE, (new Webhook(self::KEY))->check($raw, (string)self::NOW, $signature, self::NOW)->getReason());
	}

	/** A signature captured an hour ago must not still work. */
	public function testAStaleTimestampIsRefused(): void {
		$old = self::NOW - 3600;

		[$raw, $signature] = $this->delivery($this->event(), $old);

		$result = (new Webhook(self::KEY))->check($raw, (string)$old, $signature, self::NOW);

		$this->assertFalse($result->isOk());
		$this->assertSame(Webhook::STALE, $result->getReason());
		$this->assertSame(400, $result->getHttpStatus());
		$this->assertStringContainsString('+3600 s', $result->getMessage(), 'a positive skew means the delivery is old');
	}

	public function testATimestampFromTheFutureIsRefused(): void {
		$ahead = self::NOW + 3600;

		[$raw, $signature] = $this->delivery($this->event(), $ahead);

		$this->assertSame(Webhook::STALE, (new Webhook(self::KEY))->check($raw, (string)$ahead, $signature, self::NOW)->getReason());
	}

	public function testTheToleranceEdgeIsAccepted(): void {
		$edge = self::NOW - Signature::WEBHOOK_TOLERANCE;

		[$raw, $signature] = $this->delivery($this->event(), $edge);

		$this->assertTrue((new Webhook(self::KEY))->check($raw, (string)$edge, $signature, self::NOW)->isOk());
	}

	/**
	 * Cryptoway redelivers an event until it is answered 2xx, so the same signed
	 * request arrives more than once and must be applied only the first time.
	 */
	public function testAReplayedDeliveryIsRecognised(): void {
		[$raw, $signature] = $this->delivery($this->event());

		$applied = [];
		$webhook = new Webhook(self::KEY, static function (string $fingerprint) use (&$applied): bool {
			return isset($applied[$fingerprint]);
		});

		$first = $webhook->check($raw, (string)self::NOW, $signature, self::NOW);

		$this->assertTrue($first->isOk());

		// The shop stores the fingerprint once the event's work has succeeded.
		$applied[$first->getFingerprint()] = true;

		$second = $webhook->check($raw, (string)self::NOW, $signature, self::NOW);

		$this->assertFalse($second->isOk());
		$this->assertSame(Webhook::REPLAY, $second->getReason());
		$this->assertSame(200, $second->getHttpStatus(), 'a repeat is answered 200 so Cryptoway stops resending');
		$this->assertSame($first->getFingerprint(), $second->getFingerprint());
	}

	public function testALaterStatusForTheSameInvoiceIsNotAReplay(): void {
		[$raw, $signature] = $this->delivery($this->event());

		$later = $this->event();
		$later['status'] = 'expired';

		[$raw_later, $signature_later] = $this->delivery($later);

		$webhook = new Webhook(self::KEY);

		$this->assertNotSame(
			$webhook->check($raw, (string)self::NOW, $signature, self::NOW)->getFingerprint(),
			$webhook->check($raw_later, (string)self::NOW, $signature_later, self::NOW)->getFingerprint()
		);
	}

	public function testADepositEventIsFingerprintedByItsInvoice(): void {
		$deposit = [
			'type'        => 'deposit',
			'status'      => 'executed',
			'invoice'     => ['id' => 'inv_0123456789abcdef'],
			'external_id' => '12-9f8e7d6c',
			'value'       => '19.909971790000000000',
		];

		[$raw, $signature] = $this->delivery($deposit);

		$result = (new Webhook(self::KEY))->check($raw, (string)self::NOW, $signature, self::NOW);

		$this->assertTrue($result->isOk());
		$this->assertSame('deposit', $result->getType());
		$this->assertSame(Webhook::fingerprint($deposit, $signature), $result->getFingerprint());
	}

	public function testAMissingCallbackKeyAsksForARedelivery(): void {
		[$raw, $signature] = $this->delivery($this->event());

		$result = (new Webhook(''))->check($raw, (string)self::NOW, $signature, self::NOW);

		$this->assertSame(Webhook::NO_KEY, $result->getReason());
		$this->assertSame(503, $result->getHttpStatus(), 'the event is worth having again once a key is stored');
	}

	public function testMissingHeadersAreRefused(): void {
		[$raw, $signature] = $this->delivery($this->event());

		$webhook = new Webhook(self::KEY);

		$this->assertSame(Webhook::BAD_SIGNATURE, $webhook->check($raw, (string)self::NOW, '', self::NOW)->getReason());
		$this->assertSame(Webhook::BAD_TIMESTAMP, $webhook->check($raw, '', $signature, self::NOW)->getReason());
		$this->assertSame(Webhook::BAD_TIMESTAMP, $webhook->check($raw, 'not-a-number', $signature, self::NOW)->getReason());
	}

	public function testAnEmptyOrBrokenBodyIsRefused(): void {
		$webhook = new Webhook(self::KEY);

		$this->assertSame(Webhook::BODY_EMPTY, $webhook->check('', (string)self::NOW, str_repeat('0', 64), self::NOW)->getReason());
		$this->assertSame(Webhook::BAD_JSON, $webhook->check('{oops', (string)self::NOW, str_repeat('0', 64), self::NOW)->getReason());
	}

	/** The body is read before the sender is known, so its size is capped. */
	public function testAnOversizedBodyIsRefusedBeforeItIsParsed(): void {
		$body = '{"pad":"' . str_repeat('x', Webhook::MAX_BODY_BYTES) . '"}';

		$result = (new Webhook(self::KEY))->check($body, (string)self::NOW, str_repeat('0', 64), self::NOW);

		$this->assertSame(Webhook::BODY_TOO_LARGE, $result->getReason());
		$this->assertSame(413, $result->getHttpStatus());
	}

	public function testATighterToleranceCanBeAsked(): void {
		$edge = self::NOW - 60;

		[$raw, $signature] = $this->delivery($this->event(), $edge);

		$this->assertSame(Webhook::STALE, (new Webhook(self::KEY, null, 30))->check($raw, (string)$edge, $signature, self::NOW)->getReason());
		$this->assertTrue((new Webhook(self::KEY, null, 120))->check($raw, (string)$edge, $signature, self::NOW)->isOk());
	}
}

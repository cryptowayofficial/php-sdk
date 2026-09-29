<?php
/**
 * Outcome of Webhook::check(): whether the notification is authentic, why it
 * was refused, the decoded payload and the HTTP status to answer with.
 */

namespace Cryptoway\Core;

final class WebhookResult {

	/**
	 * @param string     $reason      One of the Webhook reason constants.
	 * @param array|null $payload     Decoded event, envelope unwrapped; null when nothing could be read.
	 * @param string     $fingerprint Stable id of this delivery; '' when it was not authenticated.
	 * @param string     $form        Which signed form matched; '' when none did.
	 */
	public function __construct(
		private readonly bool $ok,
		private readonly string $reason,
		private readonly string $message,
		private readonly ?array $payload = null,
		private readonly string $fingerprint = '',
		private readonly string $form = ''
	) {}

	public function isOk(): bool {
		return $this->ok;
	}

	/** Webhook::OK, or one of the refusal constants. */
	public function getReason(): string {
		return $this->reason;
	}

	/** English explanation for the shop's log; never shown to the payer. */
	public function getMessage(): string {
		return $this->message;
	}

	/** @return array<string, mixed> The event; [] when nothing authentic was read. */
	public function getPayload(): array {
		return $this->payload ?? [];
	}

	public function getType(): string {
		return (string)($this->payload['type'] ?? '');
	}

	/** Store this once the event's work succeeded, and a redelivery is recognised. */
	public function getFingerprint(): string {
		return $this->fingerprint;
	}

	public function getSignedForm(): string {
		return $this->form;
	}

	/**
	 * The status to answer Cryptoway with. 5xx asks for a redelivery, 4xx does
	 * not: a refused signature must not be retried, a missing key must be.
	 */
	public function getHttpStatus(): int {
		return match ($this->reason) {
			Webhook::OK, Webhook::REPLAY => 200,
			Webhook::BAD_SIGNATURE       => 401,
			Webhook::BODY_TOO_LARGE      => 413,
			Webhook::NO_KEY              => 503,
			default                      => 400,
		};
	}
}

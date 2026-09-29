<?php
/**
 * A failed API call returned as a value, not thrown: callers test
 * `instanceof ApiError`, so a webhook still writes its note and its reply.
 */

namespace Cryptoway\Core;

final class ApiError {

	/**
	 * @param string $code    Machine-readable code, e.g. http_error, api_error, bad_invoice_id.
	 * @param string $message Explanation, already translated.
	 * @param array  $data    Context: status, url, body.
	 */
	public function __construct(
		private string $code,
		private string $message,
		private array $data = []
	) {}

	public function getCode(): string {
		return $this->code;
	}

	public function getMessage(): string {
		return $this->message;
	}

	/** HTTP status of the failed response; 0 when there was none. */
	public function getStatus(): int {
		return (int)($this->data['status'] ?? 0);
	}
}

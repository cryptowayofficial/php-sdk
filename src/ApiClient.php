<?php
/**
 * HTTP client for the Cryptoway API. Request methods return the decoded
 * response array or an ApiError; a failed request never throws.
 */

namespace Cryptoway\Core;

class ApiClient {

	public const DEFAULT_API_BASE = 'https://api.cryptoway.com';

	/** Clock skew the API accepts, seconds. */
	public const CLOCK_TOLERANCE = 15;

	/** Domains the pay link may live on; subdomains count. */
	public const PAY_URL_DOMAINS = ['cryptoway.com'];

	/** Retries after a network or 5xx error, GET only: a repeated POST could open a second invoice. */
	private const RETRIES = 2;

	/** Pause before a retry, microseconds; multiplied by the attempt number. */
	private const RETRY_PAUSE_US = 600000;

	/** Seconds for the whole request and for the connection alone. */
	private const TIMEOUT = 20;
	private const CONNECT_TIMEOUT = 10;

	/** Profile fields holding the callback key (the webhook signing secret); the first present wins. */
	private const CALLBACK_KEY_FIELDS = ['callback_key', 'callback_key_secret', 'callback_secret'];

	/** Field names whose values are masked in the log, including names the API may add later. */
	/** Masked in the log: credentials, and the account's personal data from the profile. */
	private const SECRET_FIELDS = [
		'callback_key', 'callback_key_secret', 'callback_secret',
		'api_key_secret', 'api_secret', 'secret', 'token', 'access_token', 'private_key',
		'email', 'receipt_email', 'telegram_id', 'telegram_username', 'referral_code',
	];

	/** English fallbacks for the translator keys. */
	public const MESSAGES = [
		'error_api_unreachable'    => 'Could not reach Cryptoway at %s: %s',
		'error_api_bad_invoice_id' => 'Unknown payment number: %s',
		'error_api_wrong_ip'       => 'Cryptoway rejects the keys from this server\'s IP address. A project\'s keys are bound to the first address they are used from. Use a separate project per store, or clear Allowed IPs in the project settings.',
		'error_api_unauthorized'   => 'Cryptoway did not accept the keys. Check the Project ID and the Secret key, and make sure the project is active.',
		'error_api_not_found'      => 'Cryptoway answered "not found". Usually the project cannot accept payments yet: check that it is active in the Cryptoway account.',
		'error_api_unprocessable'  => 'Cryptoway did not accept the order details. Check the store currency and the order total.',
		'error_api_rate_limited'   => 'Too many requests to Cryptoway. Please try again in a minute.',
		'error_api_server'         => 'Cryptoway returned a server error. Please try again later.',
		'error_api_clock_skew'     => 'The server clock is %+d s off from Cryptoway; only ±%d s is allowed. Payments fail until the server time is corrected.',
	];

	/** Requests sent to Cryptoway in this PHP run. */
	private static int $requests = 0;

	private string $api_key_id;
	private string $api_key_secret;
	private bool $debug;
	/** @var callable|null */
	private $logger;
	/** @var callable|null */
	private $translator;
	private string $api_base;
	private string $user_agent;

	/**
	 * @param bool          $debug      Also log every request and response, not only errors.
	 * @param callable|null $logger     fn(string $message): void.
	 * @param callable|null $translator fn(string $key, array $args): string; keys as in MESSAGES.
	 * @param string        $api_base   Base URL without a trailing slash.
	 */
	public function __construct(string $api_key_id, string $api_key_secret, bool $debug = false, ?callable $logger = null, ?callable $translator = null, string $api_base = self::DEFAULT_API_BASE, string $user_agent = '') {
		$this->api_key_id = trim($api_key_id);
		$this->api_key_secret = trim($api_key_secret);
		$this->debug = $debug;
		$this->logger = $logger;
		$this->translator = $translator;
		$this->api_base = rtrim('' !== trim($api_base) ? $api_base : self::DEFAULT_API_BASE, '/');
		$this->user_agent = '' !== $user_agent ? $user_agent : 'CryptowayPHP';
	}

	public function getProfile(): array|ApiError {
		return $this->get('/user/profile/');
	}

	/**
	 * Create an invoice. Keys: name, target_value_fiat, target_currency_fiat, external_id,
	 * callback_url; optional deadline_at, description, symbol_id, redirect_url, is_payer_fee.
	 */
	public function createInvoice(array $params): array|ApiError {
		$body = [
			'name'                 => self::cleanText((string)($params['name'] ?? '')),
			'target_value_fiat'    => $params['target_value_fiat'] ?? 0,
			'target_currency_fiat' => strtoupper((string)($params['target_currency_fiat'] ?? '')),
			'external_id'          => (string)($params['external_id'] ?? ''),
			'callback_url'         => (string)($params['callback_url'] ?? ''),
		];

		// Sent only when given: a default would override the project setting.
		if (array_key_exists('is_payer_fee', $params) && null !== $params['is_payer_fee']) {
			$body['is_payer_fee'] = (bool)$params['is_payer_fee'];
		}

		if (!empty($params['deadline_at'])) {
			$body['deadline_at'] = $params['deadline_at'];
		}

		if (!empty($params['description'])) {
			$body['description'] = self::cleanText((string)$params['description']);
		}

		if (!empty($params['symbol_id'])) {
			$body['symbol_id'] = self::cleanText((string)$params['symbol_id']);
		}

		$redirect_url = (string)($params['redirect_url'] ?? '');

		if ('' !== $redirect_url) {
			$body['redirect_url'] = $redirect_url;
		}

		$result = $this->post('/invoice/', $body);

		if (!$result instanceof ApiError || !isset($body['redirect_url'])) {
			return $result;
		}

		// When the API rejects redirect_url (400 or 422), retry once without it so checkout
		// still works. Not after a 500: the invoice may exist already, a retry would open a second one.
		$status = $result->getStatus();

		if (!in_array($status, [400, 422], true)) {
			return $result;
		}

		unset($body['redirect_url']);

		$this->log(sprintf('Invoice rejected (HTTP %d) with redirect_url — retrying without that field.', $status));

		$retry = $this->post('/invoice/', $body);

		if (!$retry instanceof ApiError) {
			$this->log('Invoice created without redirect_url.');
		}

		return $retry;
	}

	public function getInvoice(string $invoice_id): array|ApiError {
		$invoice_id = trim($invoice_id);

		if (!preg_match('/^[A-Za-z0-9._-]{6,64}$/', $invoice_id)) {
			return new ApiError('bad_invoice_id', $this->translate('error_api_bad_invoice_id', [$invoice_id]));
		}

		return $this->get('/invoice/my/' . rawurlencode($invoice_id) . '/');
	}

	/** Requests sent to Cryptoway in this PHP run. */
	public static function requestsMade(): int {
		return self::$requests;
	}

	/** The callback key from a /user/profile/ response; '' when absent. */
	public static function readCallbackKey(mixed $profile): string {
		if (!is_array($profile)) {
			return '';
		}

		foreach (self::CALLBACK_KEY_FIELDS as $field) {
			if (!empty($profile[$field]) && is_string($profile[$field])) {
				return $profile[$field];
			}
		}

		return '';
	}

	/**
	 * Whether the pay link the buyer is redirected to points at a Cryptoway domain.
	 *
	 * @param string[] $domains
	 */
	public static function payUrlIsTrusted(string $url, array $domains = self::PAY_URL_DOMAINS): bool {
		$host = strtolower((string)parse_url($url, PHP_URL_HOST));

		if ('' === $host) {
			return false;
		}

		foreach ($domains as $domain) {
			$domain = strtolower(ltrim((string)$domain, '.'));

			if ('' !== $domain && ($host === $domain || str_ends_with($host, '.' . $domain))) {
				return true;
			}
		}

		return false;
	}

	/** Whether a URL is unreachable from the internet, so Cryptoway could not deliver webhooks to it. */
	public static function urlIsLocal(string $url): bool {
		$host = parse_url($url, PHP_URL_HOST);

		if (!is_string($host) || '' === $host) {
			return true;
		}

		$host = strtolower($host);

		if (in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true)) {
			return true;
		}

		foreach (['.local', '.localhost', '.test', '.invalid', '.example'] as $suffix) {
			if (str_ends_with($host, $suffix)) {
				return true;
			}
		}

		if (filter_var($host, FILTER_VALIDATE_IP)) {
			return !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
		}

		return false;
	}

	/** A failed response explained: HTTP status, API detail, a hint and a clock-skew warning. */
	private function explainStatus(int $status, string $raw, ?int $skew): string {
		$decoded = json_decode($raw, true);
		$detail = $decoded['detail'] ?? '';

		if (is_array($detail)) {
			$detail = json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		}

		$detail = (string)$detail;

		// A key used from an IP it is not bound to gets the same 401 as a wrong key;
		// only the detail ("Invalid IP for this API key!") tells them apart.
		$wrong_ip = (401 === $status || 403 === $status) && 1 === preg_match('/\bip\b/i', $detail);

		$hint = match (true) {
			$wrong_ip                        => $this->translate('error_api_wrong_ip'),
			401 === $status, 403 === $status => $this->translate('error_api_unauthorized'),
			404 === $status                  => $this->translate('error_api_not_found'),
			422 === $status                  => $this->translate('error_api_unprocessable'),
			429 === $status                  => $this->translate('error_api_rate_limited'),
			$status >= 500                   => $this->translate('error_api_server'),
			default                          => '',
		};

		$parts = [sprintf('HTTP %d', $status)];

		if ('' !== $detail) {
			$parts[] = $detail;
		}

		if ('' !== $hint) {
			$parts[] = $hint;
		}

		if (null !== $skew && abs($skew) > self::CLOCK_TOLERANCE) {
			$parts[] = $this->translate('error_api_clock_skew', [$skew, self::CLOCK_TOLERANCE]);
		}

		return implode(' — ', $parts);
	}

	private function get(string $path): array|ApiError {
		return $this->unwrap($this->request('GET', $path));
	}

	private function post(string $path, array $body): array|ApiError {
		return $this->unwrap($this->request('POST', $path, $body));
	}

	/** Run the request; returns status, raw, decoded, skew, error and url. */
	private function request(string $method, string $path, array $body = []): array {
		$parts = explode('?', $path, 2);
		$clean_path = $parts[0];
		$params = $parts[1] ?? '';

		$timestamp = time();
		$envelope = Signature::apiEnvelope($clean_path, $params, 'POST' === $method ? $body : []);
		$signature = Signature::sign($timestamp, $envelope, $this->api_key_secret);

		$url = $this->api_base . $path;

		// The same canonical string is signed and sent.
		$payload = 'POST' === $method ? Signature::canonical(Signature::toObject($body)) : null;

		if ($this->debug) {
			$this->log(sprintf(
				"%s %s\n  signed string: %s%s\n  body: %s",
				$method,
				$url,
				$timestamp,
				self::redact(Signature::canonical($envelope)),
				null !== $payload ? self::redact($payload) : '(none)'
			));
		}

		++self::$requests;
		$response = $this->transport($method, $url, $payload, $timestamp, $signature);
		$received_at = time();

		$repeatable = 'GET' === $method;
		$attempt = 0;

		while ($repeatable && $attempt < self::RETRIES && (null !== $response['error'] || $response['status'] >= 500)) {
			++$attempt;

			usleep(self::RETRY_PAUSE_US * $attempt);

			$timestamp = time();
			$signature = Signature::sign($timestamp, $envelope, $this->api_key_secret);

			++self::$requests;
			$response = $this->transport($method, $url, $payload, $timestamp, $signature);
			$received_at = time();

			$this->log(sprintf(
				'Retry %d of %d: %s %s -> %s',
				$attempt,
				self::RETRIES,
				$method,
				$url,
				null !== $response['error'] ? $response['error'] : 'HTTP ' . $response['status']
			));
		}

		$out = [
			'status'  => 0,
			'raw'     => '',
			'decoded' => null,
			'skew'    => null,
			'error'   => null,
			'url'     => $url,
		];

		if (null !== $response['error']) {
			$out['error'] = new ApiError('http_error', $this->translate('error_api_unreachable', [$url, $response['error']]), ['url' => $url]);

			$this->log(sprintf('NETWORK ERROR %s %s: %s', $method, $url, $response['error']));

			return $out;
		}

		$out['status'] = $response['status'];
		$out['raw'] = $response['body'];
		$out['decoded'] = json_decode($response['body'], true);
		$out['skew'] = $this->measureClockSkew($response['headers'], $timestamp, $received_at);

		if ($this->debug) {
			$this->log(sprintf('%s %s -> HTTP %d: %s', $method, $url, $out['status'], self::redact($out['raw'])));
		}

		if ($out['status'] < 200 || $out['status'] >= 300) {
			// Request-id and rate-limit headers let Cryptoway support find the request.
			$trace = '';

			foreach ([
				'x-request-id', 'x-correlation-id', 'x-trace-id', 'cf-ray', 'request-id',
				'retry-after', 'x-ratelimit-limit', 'x-ratelimit-remaining', 'x-ratelimit-reset',
			] as $header) {
				if (isset($response['headers'][$header]) && '' !== $response['headers'][$header]) {
					$trace .= sprintf("\n  %s: %s", $header, $response['headers'][$header]);
				}
			}

			$this->log(sprintf(
				"API ERROR: %s %s -> HTTP %d\n  response: %s%s%s%s",
				$method,
				$url,
				$out['status'],
				'' === $out['raw'] ? '(empty)' : self::redact($out['raw']),
				null !== $out['skew'] ? sprintf("\n  clock skew: %+d s", $out['skew']) : '',
				$trace,
				429 === $out['status']
					? "\n  rate limit reached"
					: ($out['status'] >= 500
						? "\n  server error on the Cryptoway side"
						: '')
			));
		}

		return $out;
	}

	/**
	 * One HTTP exchange over curl.
	 *
	 * @return array{status:int, body:string, headers:array<string,string>, error:?string}
	 */
	private function transport(string $method, string $url, ?string $payload, int $timestamp, string $signature): array {
		$headers = [];

		$curl = curl_init();

		if (false === $curl) {
			return ['status' => 0, 'body' => '', 'headers' => [], 'error' => 'curl is not available'];
		}

		curl_setopt_array($curl, [
			CURLOPT_URL            => $url,
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_TIMEOUT        => self::TIMEOUT,
			CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
			CURLOPT_USERAGENT      => $this->user_agent,
			CURLOPT_HTTPHEADER     => [
				'Content-Type: application/json',
				'Accept: application/json',
				'X-API-KEY-ID: ' . $this->api_key_id,
				'X-TIMESTAMP: ' . $timestamp,
				'X-SIGNATURE: ' . $signature,
			],
			CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$headers): int {
				$pos = strpos($line, ':');

				if (false !== $pos) {
					$name = strtolower(trim(substr($line, 0, $pos)));
					$value = trim(substr($line, $pos + 1));

					$headers[$name] = isset($headers[$name]) ? $headers[$name] . ', ' . $value : $value;
				}

				return strlen($line);
			},
		]);

		if (null !== $payload) {
			curl_setopt($curl, CURLOPT_POSTFIELDS, $payload);
		}

		$body = curl_exec($curl);

		// No curl_close(): a no-op since PHP 8.0 and deprecated in 8.5.
		if (false === $body) {
			$error = curl_error($curl) ?: 'curl error ' . curl_errno($curl);

			return ['status' => 0, 'body' => '', 'headers' => $headers, 'error' => $error];
		}

		$status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

		return ['status' => $status, 'body' => (string)$body, 'headers' => $headers, 'error' => null];
	}

	/** Request summary -> decoded data or ApiError. */
	private function unwrap(array $response): array|ApiError {
		if ($response['error'] instanceof ApiError) {
			return $response['error'];
		}

		$status = (int)$response['status'];

		if ($status >= 200 && $status < 300) {
			return is_array($response['decoded']) ? $response['decoded'] : [];
		}

		return new ApiError(
			'api_error',
			$this->explainStatus($status, (string)$response['raw'], $response['skew']),
			[
				'status' => $status,
				'url'    => $response['url'],
				'body'   => $response['raw'],
			]
		);
	}

	/** Server clock minus local clock from the Date header; null when missing or unreliable. */
	private function measureClockSkew(array $headers, int $sent_at, ?int $received_at = null): ?int {
		$date = $headers['date'] ?? '';

		if (!is_string($date) || '' === $date) {
			return null;
		}

		$server_time = strtotime($date);

		if (false === $server_time) {
			return null;
		}

		// Date is stamped between send and receive: compare with the midpoint, so a
		// slow response does not look like skew.
		$received_at = null === $received_at ? $sent_at : max($sent_at, $received_at);
		$midpoint = intdiv($sent_at + $received_at, 2);

		$skew = $server_time - $midpoint;

		if (($received_at - $sent_at) > self::CLOCK_TOLERANCE) {
			return null;
		}

		return $skew;
	}

	/**
	 * A body with every secret value replaced by ***. JSON is masked by field and
	 * returned unchanged when nothing matched; other bodies fall back to a regex.
	 */
	private static function redact(string $raw): string {
		if ('' === $raw) {
			return $raw;
		}

		$decoded = json_decode($raw, true);

		if (JSON_ERROR_NONE === json_last_error()) {
			if (is_array($decoded)) {
				$masked = self::maskSecrets($decoded);

				if ($masked === $decoded) {
					// Byte for byte, so a logged canonical string still matches the signed one.
					return $raw;
				}

				$json = json_encode($masked, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

				if (false !== $json) {
					return $json;
				}
			} elseif (is_string($decoded) && 1 === preg_match('~^\S{16,}$~', $decoded)) {
				// A bare JSON string with no spaces and 16+ characters looks like a credential.
				return '"***"';
			}
		}

		foreach (self::SECRET_FIELDS as $field) {
			// Name quoted or bare, then ":", "=>" or "=": JSON, form bodies, header lines and dumps alike.
			$raw = (string)preg_replace(
				'~(["\']?' . preg_quote($field, '~') . '["\']?\s*(?:=>|=|:)\s*)(?:"[^"]+"|\'[^\']+\'|[^\s,;&"\'\]}]+)~i',
				'${1}***',
				$raw
			);
		}

		return $raw;
	}

	/**
	 * The array with every non-empty secret field replaced by ***; empty ones stay visible.
	 *
	 * @param array<mixed> $data
	 *
	 * @return array<mixed>
	 */
	private static function maskSecrets(array $data): array {
		foreach ($data as $key => $value) {
			if (is_array($value)) {
				$data[$key] = self::maskSecrets($value);

				continue;
			}

			if (is_string($key) && in_array(strtolower($key), self::SECRET_FIELDS, true) && '' !== (string)$value) {
				$data[$key] = '***';
			}
		}

		return $data;
	}

	/** Plain text for invoice fields: no tags, no control characters, single spaces. */
	public static function cleanText(string $value): string {
		$value = strip_tags($value);
		$value = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);

		return trim((string)preg_replace('/\s+/', ' ', $value));
	}

	private function translate(string $key, array $args = []): string {
		if (null !== $this->translator) {
			$text = (string)call_user_func($this->translator, $key, $args);

			if ('' !== $text && $text !== $key) {
				return $text;
			}
		}

		$template = self::MESSAGES[$key] ?? $key;

		return $args ? vsprintf($template, $args) : $template;
	}

	/** Log a line with URL secrets masked: return URLs carry the key to the customer's order page. */
	private function log(string $message): void {
		if (null !== $this->logger) {
			call_user_func($this->logger, self::maskUrlSecrets($message));
		}
	}

	/**
	 * Mask the values of secret-looking URL parameters (key, secure_key, token, secret,
	 * password, signature) after "?", "&", "&amp;" or a JSON-escaped ampersand.
	 */
	public static function maskUrlSecrets(string $text): string {
		$masked = preg_replace(
			'~((?:[?&;]|&amp;|\\\\u0026)(?:[A-Za-z0-9]+_)*(?:key|token|secret|password|signature)=)[^&\s"\'<>#\\\\]+~i',
			'${1}***',
			$text
		);

		return null === $masked ? $text : $masked;
	}
}

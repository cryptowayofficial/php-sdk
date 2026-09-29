<?php
/**
 * Invoice creation and read-back against a stub that speaks the API's protocol
 * on the loopback interface. The stub verifies the signature itself, so a green
 * run proves the client signs what the server checks. Nothing here leaves the
 * machine: the suite passes with the network switched off.
 *
 * @license MIT
 */

declare(strict_types=1);

namespace Cryptoway\Core\Tests;

use Cryptoway\Core\Amounts;
use Cryptoway\Core\ApiClient;
use Cryptoway\Core\ApiError;
use Cryptoway\Core\Tests\Support\StubApi;
use PHPUnit\Framework\TestCase;

final class ApiClientTest extends TestCase {

	private static ?StubApi $stub = null;

	public static function setUpBeforeClass(): void {
		self::$stub = StubApi::start();
	}

	public static function tearDownAfterClass(): void {
		self::$stub?->stop();
		self::$stub = null;
	}

	protected function setUp(): void {
		self::$stub->reset();
	}

	private function client(string $secret = StubApi::SECRET): ApiClient {
		return new ApiClient(
			api_key_id: StubApi::KEY_ID,
			api_key_secret: $secret,
			api_base: self::$stub->baseUrl(),
			user_agent: 'CryptowayPhpSdkTests'
		);
	}

	/** @return array<string, mixed> */
	private function invoiceParams(array $extra = []): array {
		return $extra + [
			'name'                 => 'Order 12',
			'target_value_fiat'    => 30.26,
			'target_currency_fiat' => 'EUR',
			'external_id'          => '12-9f8e7d6c',
			'callback_url'         => 'https://shop.example.com/webhook',
		];
	}

	/** The suite must never be able to reach the real service. */
	public function testTheStubIsOnLoopbackAndNotTheRealApi(): void {
		$this->assertStringStartsWith('http://127.0.0.1:', self::$stub->baseUrl());
		$this->assertSame('https://api.cryptoway.com', ApiClient::DEFAULT_API_BASE);
		$this->assertStringNotContainsString('cryptoway.com', self::$stub->baseUrl());
	}

	public function testAnInvoiceIsCreatedAndSigned(): void {
		$invoice = $this->client()->createInvoice($this->invoiceParams());

		$this->assertIsArray($invoice, 'a rejected signature would have come back as an ApiError');
		$this->assertMatchesRegularExpression('/^inv_[0-9a-f]{16}$/', $invoice['id']);
		$this->assertSame('active', $invoice['status']);
		$this->assertSame('EUR', $invoice['target_currency_fiat']);
		$this->assertSame('30.26000000', substr($invoice['target_value_fiat'], 0, 11));
		$this->assertTrue(ApiClient::payUrlIsTrusted($invoice['pay_url']));
		$this->assertCount(1, self::$stub->requests(), 'one request, no retry');
	}

	public function testTheInvoiceReadsBackUnchanged(): void {
		$client = $this->client();
		$created = $client->createInvoice($this->invoiceParams());

		$this->assertIsArray($created);

		$fetched = $client->getInvoice($created['id']);

		$this->assertIsArray($fetched);
		$this->assertSame($created['id'], $fetched['id']);
		$this->assertSame($created['target_value_fiat'], $fetched['target_value_fiat']);
		$this->assertSame($created['external_id'], $fetched['external_id']);
		$this->assertSame($created['target_value_symbol'], $fetched['target_value_symbol']);
	}

	public function testAPaidInvoiceReadsBackWithTheMoneyOnIt(): void {
		$client = $this->client();
		$created = $client->createInvoice($this->invoiceParams());

		$this->assertIsArray($created);

		self::$stub->control(['invoice' => ['status' => 'executed', 'current_value' => $created['target_value_symbol']]]);

		$fetched = $client->getInvoice($created['id']);

		$this->assertIsArray($fetched);
		$this->assertSame('executed', $fetched['status']);
		$this->assertTrue(Amounts::invoiceIsCovered($fetched));
	}

	public function testAWrongSecretIsRefusedByTheStub(): void {
		$result = $this->client('the-wrong-secret')->createInvoice($this->invoiceParams());

		$this->assertInstanceOf(ApiError::class, $result);
		$this->assertSame(401, $result->getStatus());
		$this->assertStringContainsString('Invalid signature!', $result->getMessage());
	}

	public function testAnUnknownInvoiceIsAnApiError(): void {
		$result = $this->client()->getInvoice('inv_ffffffffffffffff');

		$this->assertInstanceOf(ApiError::class, $result);
		$this->assertSame(404, $result->getStatus());
		$this->assertSame('api_error', $result->getCode());
	}

	/** A malformed id is refused locally, so nothing is sent. */
	public function testAMalformedInvoiceIdNeverLeavesTheProcess(): void {
		$result = $this->client()->getInvoice('../../etc/passwd');

		$this->assertInstanceOf(ApiError::class, $result);
		$this->assertSame('bad_invoice_id', $result->getCode());
		$this->assertSame([], self::$stub->requests());
	}

	/** A key bound to another IP answers 401 like a wrong key; only the detail tells them apart. */
	public function testTheWrongIpAnswerGetsItsOwnExplanation(): void {
		self::$stub->control(['wrong_ip' => true]);

		$result = $this->client()->createInvoice($this->invoiceParams());

		$this->assertInstanceOf(ApiError::class, $result);
		$this->assertStringContainsString('Allowed IPs', $result->getMessage());
	}

	public function testAPlain401GetsTheKeysExplanation(): void {
		self::$stub->control(['unauthorized' => true]);

		$result = $this->client()->createInvoice($this->invoiceParams());

		$this->assertInstanceOf(ApiError::class, $result);
		$this->assertStringContainsString('Check the Project ID and the Secret key', $result->getMessage());
	}

	/**
	 * redirect_url is undocumented and some deployments reject it. Checkout has
	 * to survive that, so the client drops the field and tries once more.
	 */
	public function testARejectedRedirectUrlIsDroppedAndTheInvoiceStillOpens(): void {
		self::$stub->control(['reject_redirect_url' => 422]);

		$invoice = $this->client()->createInvoice($this->invoiceParams(['redirect_url' => 'https://shop.example.com/thanks']));

		$this->assertIsArray($invoice);
		$this->assertSame('', $invoice['redirect_url']);

		$requests = self::$stub->requests();

		$this->assertCount(2, $requests);
		$this->assertStringContainsString('redirect_url', $requests[0]);
		$this->assertStringNotContainsString('redirect_url', $requests[1], 'the second attempt goes without the field');
	}

	/** Nothing to drop, nothing to repeat: a second create could open a second invoice for one order. */
	public function testAFailedInvoiceCreationIsNotRetried(): void {
		self::$stub->control(['server_error' => true]);

		$result = $this->client()->createInvoice($this->invoiceParams());

		$this->assertInstanceOf(ApiError::class, $result);
		$this->assertSame(500, $result->getStatus());
		$this->assertCount(1, self::$stub->requests());
	}

	/** A 500 can arrive after the invoice was created: even with redirect_url a create is not repeated. */
	public function testACreateThatCarriedRedirectUrlIsNotRepeatedOnAServerError(): void {
		self::$stub->control(['server_error' => true]);

		$result = $this->client()->createInvoice($this->invoiceParams(['redirect_url' => 'https://shop.example.com/thanks']));

		$this->assertInstanceOf(ApiError::class, $result);
		$this->assertSame(500, $result->getStatus());
		$this->assertCount(1, self::$stub->requests());
	}

	/** A read is safe to repeat, and a 5xx on the way to the answer usually is transient. */
	public function testAFailedReadIsRetried(): void {
		$client = $this->client();
		$created = $client->createInvoice($this->invoiceParams());

		$this->assertIsArray($created);

		self::$stub->reset();
		self::$stub->control(['server_error' => true]);

		$result = $client->getInvoice($created['id']);

		$this->assertInstanceOf(ApiError::class, $result);
		$this->assertCount(3, self::$stub->requests(), 'the first attempt and two retries');
	}

	public function testTheProfileCarriesTheCallbackKey(): void {
		$profile = $this->client()->getProfile();

		$this->assertIsArray($profile);
		$this->assertSame('callback-key-for-tests', ApiClient::readCallbackKey($profile));
	}

	public function testRequestsAreCounted(): void {
		$before = ApiClient::requestsMade();

		$this->client()->getProfile();

		$this->assertSame($before + 1, ApiClient::requestsMade());
	}

	/** Secrets must not reach the shop's log, whatever the caller passes in. */
	public function testTheDebugLogMasksSecrets(): void {
		$lines = [];

		$client = new ApiClient(
			api_key_id: StubApi::KEY_ID,
			api_key_secret: StubApi::SECRET,
			debug: true,
			logger: static function (string $line) use (&$lines): void {
				$lines[] = $line;
			},
			api_base: self::$stub->baseUrl()
		);

		$client->getProfile();

		$log = implode("\n", $lines);

		$this->assertNotSame('', $log);
		$this->assertStringNotContainsString('callback-key-for-tests', $log);
		$this->assertStringContainsString('***', $log);

		// The profile is the account holder's: their contacts stay out of the log too.
		$this->assertStringNotContainsString('owner@shop.example.com', $log);
		$this->assertStringNotContainsString('shop_owner', $log);
	}

	public function testALocalCallbackUrlIsRecognised(): void {
		$this->assertTrue(ApiClient::urlIsLocal('http://localhost/webhook'));
		$this->assertTrue(ApiClient::urlIsLocal('http://127.0.0.1:8092/webhook'));
		$this->assertTrue(ApiClient::urlIsLocal('http://shop.test/webhook'));
		$this->assertFalse(ApiClient::urlIsLocal('https://shop.example.com/webhook'));
	}

	public function testOnlyCryptowayPayLinksAreTrusted(): void {
		$this->assertTrue(ApiClient::payUrlIsTrusted('https://pay.cryptoway.com/invoice/inv_1'));
		$this->assertTrue(ApiClient::payUrlIsTrusted('https://cryptoway.com/invoice/inv_1'));
		$this->assertFalse(ApiClient::payUrlIsTrusted('https://cryptoway.com.evil.test/invoice/inv_1'));
		$this->assertFalse(ApiClient::payUrlIsTrusted('https://evil.test/invoice/inv_1'));
	}
}

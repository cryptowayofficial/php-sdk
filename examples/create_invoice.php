<?php
/**
 * Creates one invoice and prints the link the buyer should be sent to.
 *
 *     export CRYPTOWAY_PROJECT_ID=...      # Project ID from the Cryptoway account
 *     export CRYPTOWAY_SECRET_KEY=...      # Secret key of the same project
 *     php examples/create_invoice.php 30.26 EUR
 *
 * Set CRYPTOWAY_API_BASE to point the script at a test server instead of the
 * live API.
 *
 * @license MIT
 */

declare(strict_types=1);

// A clone keeps its autoloader in the package; an installation has it three
// levels up, at vendor/autoload.php.
require autoloader();

use Cryptoway\Core\ApiClient;
use Cryptoway\Core\ApiError;

$project_id = (string)getenv('CRYPTOWAY_PROJECT_ID');
$secret_key = (string)getenv('CRYPTOWAY_SECRET_KEY');

if ('' === $project_id || '' === $secret_key) {
	exit("Set CRYPTOWAY_PROJECT_ID and CRYPTOWAY_SECRET_KEY first.\n");
}

$amount = (float)($argv[1] ?? '30.26');
$currency = strtoupper((string)($argv[2] ?? 'EUR'));
$callback_url = (string)(getenv('CRYPTOWAY_CALLBACK_URL') ?: 'https://shop.example.com/cryptoway/webhook');

// Cryptoway cannot deliver a webhook to an address that is not reachable from
// the internet, so an order paid against one would never be confirmed.
if (ApiClient::urlIsLocal($callback_url)) {
	fwrite(STDERR, sprintf("Warning: %s is not reachable from the internet; no webhook will arrive.\n", $callback_url));
}

$api = new ApiClient(
	api_key_id: $project_id,
	api_key_secret: $secret_key,
	debug: (bool)getenv('CRYPTOWAY_DEBUG'),
	logger: static function (string $line): void {
		fwrite(STDERR, '[cryptoway] ' . $line . "\n");
	},
	api_base: (string)(getenv('CRYPTOWAY_API_BASE') ?: ApiClient::DEFAULT_API_BASE),
	user_agent: 'CryptowayPhpSdkExample'
);

// external_id is your own reference. A random part keeps a retried checkout
// from colliding with the invoice the first attempt opened.
$order_id = 1042;

$invoice = $api->createInvoice([
	'name'                 => 'Order ' . $order_id,
	'description'          => 'Example order',
	'target_value_fiat'    => $amount,
	'target_currency_fiat' => $currency,
	'external_id'          => $order_id . '-' . bin2hex(random_bytes(8)),
	'callback_url'         => $callback_url,
	'redirect_url'         => 'https://shop.example.com/checkout/thanks',
	'deadline_at'          => gmdate('Y-m-d\TH:i:s\Z', time() + 3600),
]);

if ($invoice instanceof ApiError) {
	fwrite(STDERR, sprintf("Invoice not created (%s): %s\n", $invoice->getCode(), $invoice->getMessage()));

	exit(1);
}

// Never redirect to a link the answer did not put on a Cryptoway domain.
if (!ApiClient::payUrlIsTrusted((string)($invoice['pay_url'] ?? ''))) {
	fwrite(STDERR, "The pay link is not on a Cryptoway domain; refusing to use it.\n");

	exit(1);
}

printf("Invoice:     %s\n", $invoice['id']);
printf("Status:      %s\n", $invoice['status']);
printf("Asks for:    %s %s\n", $invoice['target_value_fiat'], $invoice['target_currency_fiat']);
printf("In coins:    %s %s\n", $invoice['target_value_symbol'], $invoice['symbol_id']);
printf("Deadline:    %s\n", $invoice['deadline_at']);
printf("Send buyer:  %s\n", $invoice['pay_url']);
printf("\nStore the invoice id and the external id on the order; the webhook names them.\n");

/** The Composer autoloader of a clone or of an installed package. */
function autoloader(): string {
	foreach ([dirname(__DIR__) . '/vendor/autoload.php', dirname(__DIR__, 3) . '/autoload.php'] as $candidate) {
		if (is_file($candidate)) {
			return $candidate;
		}
	}

	fwrite(STDERR, "No Composer autoloader found; run \"composer install\" in the package.\n");

	exit(1);
}

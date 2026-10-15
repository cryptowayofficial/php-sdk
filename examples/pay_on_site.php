<?php
/**
 * Payment on your own page: the buyer picks a coin on your site, the invoice is
 * created with that coin, and the address and amount are shown by you. Nobody
 * is sent to pay.cryptoway.com.
 *
 *     export CRYPTOWAY_PROJECT_ID=...
 *     export CRYPTOWAY_SECRET_KEY=...
 *     php examples/pay_on_site.php                 # list the coins to offer
 *     php examples/pay_on_site.php USDTBEP20 2 USD  # invoice for 2 USD in USDT BEP-20
 *
 * @license MIT
 */

declare(strict_types=1);

require autoloader();

use Cryptoway\Core\Amounts;
use Cryptoway\Core\ApiClient;
use Cryptoway\Core\ApiError;
use Cryptoway\Core\Coins;

$api = new ApiClient(
	api_key_id: (string)getenv('CRYPTOWAY_PROJECT_ID'),
	api_key_secret: (string)getenv('CRYPTOWAY_SECRET_KEY'),
	api_base: (string)(getenv('CRYPTOWAY_API_BASE') ?: ApiClient::DEFAULT_API_BASE),
	user_agent: 'CryptowayPhpSdkExample'
);

$symbol = strtoupper((string)($argv[1] ?? ''));

// Step 1: the coin picker. Offer these on your page; `id` is what you send back.
if ('' === $symbol) {
	$symbols = $api->getSymbols();

	if ($symbols instanceof ApiError) {
		exit('Could not load the coins: ' . $symbols->getMessage() . "\n");
	}

	foreach ($symbols as $item) {
		printf("%-12s %s\n", $item['id'], Coins::label((string)$item['id']));
	}

	exit;
}

// Step 2: the invoice with the coin chosen up front. The address comes back at once.
$invoice = $api->createInvoice([
	'name'                 => 'Order 1042',
	'target_value_fiat'    => (float)($argv[2] ?? '2'),
	'target_currency_fiat' => strtoupper((string)($argv[3] ?? 'USD')),
	'symbol_id'            => $symbol,
	'external_id'          => '1042-' . bin2hex(random_bytes(8)),
	'callback_url'         => (string)(getenv('CRYPTOWAY_CALLBACK_URL') ?: 'https://shop.example.com/cryptoway/webhook'),
	'deadline_at'          => gmdate('Y-m-d\TH:i:s\Z', time() + 1800),
]);

if ($invoice instanceof ApiError) {
	exit('Invoice not created: ' . $invoice->getMessage() . "\n");
}

// Step 3: what your page shows. Put the address in a QR code; the amount is what
// the buyer must send, fees and markup already included.
printf("Invoice:  %s\n", $invoice['id']);
printf("Send:     %s %s\n", Amounts::tidy((string)$invoice['target_value_symbol']), Coins::label((string)$invoice['symbol_id']));
printf("To:       %s\n", $invoice['target_wallet']['value'] ?? '(no address in the answer)');
printf("Until:    %s\n", $invoice['deadline_at']);
printf("\nThe deposit webhook names this invoice; re-read it and check Amounts::invoiceIsCovered() before releasing the goods.\n");

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

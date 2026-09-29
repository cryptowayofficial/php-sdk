<?php
/**
 * A webhook endpoint. Run it as a one-file server to try it out:
 *
 *     export CRYPTOWAY_CALLBACK_KEY=...    # Callback key from the Cryptoway account
 *     php -S 127.0.0.1:8080 examples/handle_webhook.php
 *
 * and point callback_url at http://<your public host>/ . In a real shop this is
 * a route in your framework; only the three steps under "authenticate" and the
 * amount check at the end matter.
 *
 * The replay store here is a file of fingerprints in the temporary directory.
 * Use your database instead — the point is that a fingerprint is written only
 * after the work succeeded, so an event that failed halfway is delivered again
 * and applied again.
 *
 * @license MIT
 */

declare(strict_types=1);

// A clone keeps its autoloader in the package; an installation has it three
// levels up, at vendor/autoload.php.
require autoloader();

use Cryptoway\Core\Amounts;
use Cryptoway\Core\ApiClient;
use Cryptoway\Core\ApiError;
use Cryptoway\Core\Webhook;

// Not inside the package: after composer require that would be vendor/.
$applied_events = sys_get_temp_dir() . '/cryptoway-applied-events';

$callback_key = (string)getenv('CRYPTOWAY_CALLBACK_KEY');

// Authenticate: freshness, signature and replay, in that order.
$webhook = new Webhook($callback_key, static function (string $fingerprint) use ($applied_events): bool {
	return is_file($applied_events)
		&& in_array($fingerprint, (array)file($applied_events, FILE_IGNORE_NEW_LINES), true);
});

$event = $webhook->check(
	(string)file_get_contents('php://input'),
	(string)($_SERVER['HTTP_X_TIMESTAMP'] ?? ''),
	(string)($_SERVER['HTTP_X_SIGNATURE'] ?? '')
);

if (!$event->isOk()) {
	// 200 for a repeat, 401 for a forgery, 400 for a stale one, 503 to be asked again.
	reply($event->getHttpStatus(), $event->getReason(), $event->getMessage());
}

$payload = $event->getPayload();

// The event body carries no amount, so an "executed" invoice is read back from
// the API before anything is released. Without this an order could be settled
// on the word of whoever sent the request.
$api = new ApiClient(
	api_key_id: (string)getenv('CRYPTOWAY_PROJECT_ID'),
	api_key_secret: (string)getenv('CRYPTOWAY_SECRET_KEY'),
	api_base: (string)(getenv('CRYPTOWAY_API_BASE') ?: ApiClient::DEFAULT_API_BASE)
);

$invoice_id = (string)($payload['id'] ?? ($payload['invoice']['id'] ?? ''));

if ('' === $invoice_id) {
	reply(200, 'ignored', 'The event names no invoice.');
}

$invoice = $api->getInvoice($invoice_id);

if ($invoice instanceof ApiError) {
	// Unknown whether the money is there: ask for the event again rather than guess.
	reply(503, 'unavailable', $invoice->getMessage());
}

$status = (string)($invoice['status'] ?? '');
$covered = Amounts::invoiceIsCovered($invoice);
$shortfall = Amounts::shortfall($invoice);

if ('executed' === $status && $covered) {
	// The only branch that may release the goods.
	$outcome = sprintf('Order for invoice %s is paid in full.', $invoice_id);
} elseif ('executed' === $status) {
	// Closed short: the merchant collects the rest or refunds. Never ship on this.
	$outcome = sprintf(
		'Invoice %s closed with only %s of the %s it asked for — hold the order for review.',
		$invoice_id,
		$shortfall['paid'] ?? '0',
		$shortfall['target'] ?? '?'
	);
} elseif ('expired' === $status && Amounts::hasPartialPayment((string)($invoice['current_value'] ?? ''))) {
	// Coins arrived after the deadline: a person has to decide, not a cron job.
	$outcome = sprintf('Invoice %s expired holding coins — a person must decide what to do.', $invoice_id);
} else {
	$outcome = sprintf('Invoice %s is "%s"; nothing to do yet.', $invoice_id, $status);
}

// Remembered only now: a fingerprint written before the work would lose a
// redelivery of an event that failed halfway.
file_put_contents($applied_events, $event->getFingerprint() . "\n", FILE_APPEND | LOCK_EX);

reply(200, 'ok', $outcome);

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

/** JSON answer; ends the request. */
function reply(int $status, string $result, string $message): never {
	http_response_code($status);
	header('Content-Type: application/json; charset=utf-8');

	echo json_encode(['result' => $result, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

	exit;
}

<?php
/**
 * A stand-in for api.cryptoway.com, served by PHP's built-in web server on the
 * loopback interface. It verifies the request signature with the SDK's own
 * Signature class, so a test that passes here proves the client signs what the
 * API checks. It never reaches the internet and knows nothing about the real
 * service beyond the shape of its answers.
 *
 * Driven by files in CRYPTOWAY_STUB_DIR:
 *   control.json   what to answer next (see control())
 *   inv_*.json     the invoices created so far
 *   requests.log   one line per request, for assertions about retries
 *
 * @license MIT
 */

declare(strict_types=1);

// The autoloader of the run that started this server; the default covers a
// plain "composer install" in the package root.
$autoload = (string)getenv('CRYPTOWAY_STUB_AUTOLOAD');

require '' !== $autoload ? $autoload : __DIR__ . '/../../vendor/autoload.php';

use Cryptoway\Core\Signature;

$dir = (string)getenv('CRYPTOWAY_STUB_DIR');
$secret = (string)getenv('CRYPTOWAY_STUB_SECRET');
$key_id = (string)getenv('CRYPTOWAY_STUB_KEY_ID');

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$target = (string)($_SERVER['REQUEST_URI'] ?? '/');
$parts = explode('?', $target, 2);
$path = $parts[0];
$query = $parts[1] ?? '';
$raw_body = (string)file_get_contents('php://input');

$control = control($dir);

record($dir, $method . ' ' . $target . ' ' . $raw_body);

// Scenarios that answer before the signature is looked at, the way the real
// API does for an IP that a key is not bound to.
if (!empty($control['wrong_ip'])) {
	reply(401, ['detail' => 'Invalid IP for this API key!']);
}

if (!empty($control['unauthorized'])) {
	reply(401, ['detail' => 'Invalid API key!']);
}

if (!empty($control['server_error'])) {
	reply(500, ['detail' => 'Internal server error']);
}

$refusal = authenticate($key_id, $secret, $method, $path, $query, $raw_body);

if (null !== $refusal) {
	reply(401, ['detail' => $refusal]);
}

if ('POST' === $method && '/invoice/' === $path) {
	createInvoice($dir, $control, $raw_body);
}

if ('GET' === $method && 1 === preg_match('~^/invoice/my/([^/]+)/$~', $path, $m)) {
	readInvoice($dir, $control, rawurldecode($m[1]));
}

if ('GET' === $method && '/user/profile/' === $path) {
	reply(200, ['id' => 1001, 'email' => 'owner@shop.example.com', 'telegram_username' => 'shop_owner', 'callback_key' => (string)($control['callback_key'] ?? 'callback-key-for-tests')]);
}

if ('GET' === $method && '/symbol/' === $path) {
	listSymbols($control, $query);
}

if ('POST' === $method && '/user/address/' === $path) {
	createAddress($dir, $raw_body);
}

if ('GET' === $method && '/user/address/' === $path) {
	listAddresses($dir, $query);
}

reply(404, ['detail' => 'Not found.']);

/**
 * GET /symbol/ — a paginated list. control.symbols replaces the default set,
 * control.symbols_per_page the page size (so pagination can be exercised).
 */
function listSymbols(array $control, string $query): void {
	parse_str($query, $q);

	$all = $control['symbols'] ?? [
		['id' => 'USDTBEP20', 'name' => 'Tether BEP-20', 'short_name' => 'USDT', 'blockchain' => ['id' => 'BEP20', 'name' => 'BNB Smart Chain'], 'is_active' => true, 'is_virtual' => false, 'precision' => 2],
		['id' => 'USDTTRC20', 'name' => 'Tether TRC-20', 'short_name' => 'USDT', 'blockchain' => ['id' => 'TRC20', 'name' => 'TRON'], 'is_active' => true, 'is_virtual' => false, 'precision' => 6],
		['id' => 'BTC', 'name' => 'Bitcoin', 'short_name' => 'BTC', 'blockchain' => ['id' => 'BTC', 'name' => 'Bitcoin'], 'is_active' => true, 'is_virtual' => false, 'precision' => 8],
		['id' => 'USDT', 'name' => 'Tether', 'short_name' => 'USDT', 'blockchain' => null, 'is_active' => true, 'is_virtual' => true, 'precision' => 2],
		['id' => 'DOGE', 'name' => 'Dogecoin', 'short_name' => 'DOGE', 'blockchain' => ['id' => 'DOGE', 'name' => 'Dogecoin'], 'is_active' => false, 'is_virtual' => false, 'precision' => 8],
	];

	$per_page = max(1, (int)($control['symbols_per_page'] ?? ($q['per_page'] ?? 100)));
	$page = max(1, (int)($q['page'] ?? 1));
	$pages = max(1, (int)ceil(count($all) / $per_page));

	reply(200, [
		'data'         => array_values(array_slice($all, ($page - 1) * $per_page, $per_page)),
		'pages_count'  => $pages,
		'current_page' => $page,
		'total'        => count($all),
	]);
}

/** POST /user/address/ — a permanent address; the real API refuses a virtual symbol. */
function createAddress(string $dir, string $raw_body): void {
	$params = json_decode($raw_body, true);

	if (!is_array($params)) {
		reply(400, ['detail' => 'Invalid JSON body']);
	}

	$symbol = (string)($params['symbol_id'] ?? '');

	if ('' === $symbol) {
		reply(422, ['detail' => ['symbol_id' => ['This field is required.']]]);
	}

	if (in_array($symbol, ['USDT', 'USDC'], true)) {
		reply(422, ['detail' => ['symbol_id' => ['Virtual symbol is not allowed.']]]);
	}

	$id = count((array)glob($dir . '/addr_*.json')) + 1;

	$address = [
		'id'           => $id,
		'value'        => '0x' . bin2hex(random_bytes(20)),
		'description'  => (string)($params['description'] ?? ''),
		'symbol_id'    => $symbol,
		'user_id'      => '1001',
		'callback_url' => (string)($params['callback_url'] ?? ''),
		'external_id'  => isset($params['external_id']) ? (string)$params['external_id'] : null,
		'created_at'   => gmdate('Y-m-d\TH:i:s\Z'),
	];

	file_put_contents($dir . '/addr_' . $id . '.json', (string)json_encode($address));

	reply(200, $address);
}

/** GET /user/address/ — the stored addresses, filtered by symbol_id and search_query. */
function listAddresses(string $dir, string $query): void {
	parse_str($query, $q);

	$items = [];

	foreach ((array)glob($dir . '/addr_*.json') as $file) {
		$address = json_decode((string)file_get_contents((string)$file), true);

		if (!is_array($address)) {
			continue;
		}

		if (!empty($q['symbol_id']) && $address['symbol_id'] !== $q['symbol_id']) {
			continue;
		}

		$needle = (string)($q['search_query'] ?? '');

		if ('' !== $needle && !str_contains((string)$address['external_id'] . ' ' . $address['description'] . ' ' . $address['value'], $needle)) {
			continue;
		}

		$items[] = $address;
	}

	usort($items, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

	reply(200, ['data' => $items, 'pages_count' => 1, 'current_page' => 1, 'total' => count($items)]);
}

// Handlers

/** POST /invoice/ — validate, store and return the new invoice. */
function createInvoice(string $dir, array $control, string $raw_body): void {
	$params = json_decode($raw_body, true);

	if (!is_array($params)) {
		reply(400, ['detail' => 'Invalid JSON body']);
	}

	// The real API rejects an undocumented field on some deployments; the client
	// is expected to drop redirect_url and try once more.
	if (isset($params['redirect_url']) && !empty($control['reject_redirect_url'])) {
		reply((int)$control['reject_redirect_url'], ['detail' => 'Unexpected field: redirect_url']);
	}

	foreach (['name', 'target_value_fiat', 'target_currency_fiat', 'external_id', 'callback_url'] as $field) {
		if (!isset($params[$field]) || '' === (string)$params[$field]) {
			reply(422, ['detail' => [$field => ['This field is required.']]]);
		}
	}

	if (!is_numeric($params['target_value_fiat']) || (float)$params['target_value_fiat'] <= 0) {
		reply(422, ['detail' => ['target_value_fiat' => ['Ensure this value is greater than 0.']]]);
	}

	$rate = (float)($control['rate'] ?? 1.0);
	$precision = (int)($control['precision'] ?? 2);
	// A symbol sent at creation is kept, the way the real API does; otherwise the
	// stub assumes the buyer picked USDT BEP-20, so amount tests have a coin to work with.
	$symbol = (string)($control['symbol_id'] ?? ($params['symbol_id'] ?? 'USDTBEP20'));
	$id = 'inv_' . bin2hex(random_bytes(8));
	$fiat = (float)$params['target_value_fiat'];
	$now = gmdate('Y-m-d\TH:i:s\Z');

	// Eighteen-decimal strings for every amount, as the real API sends them.
	$invoice = [
		'id'                   => $id,
		'status'               => 'active',
		'name'                 => (string)$params['name'],
		'description'          => (string)($params['description'] ?? ''),
		'external_id'          => (string)$params['external_id'],
		'target_value_fiat'    => amount($fiat),
		'target_currency_fiat' => strtoupper((string)$params['target_currency_fiat']),
		'target_value_symbol'  => amount($fiat * $rate),
		'current_value'        => amount(0),
		'symbol_id'            => $symbol,
		'is_payer_fee'         => (bool)($params['is_payer_fee'] ?? false),
		'pay_url'              => 'https://pay.cryptoway.com/invoice/' . $id,
		'callback_url'         => (string)$params['callback_url'],
		'redirect_url'         => (string)($params['redirect_url'] ?? ''),
		'deadline_at'          => (string)($params['deadline_at'] ?? gmdate('Y-m-d\TH:i:s\Z', time() + 3600)),
		'created_at'           => $now,
		'updated_at'           => $now,
		'target_wallet'        => [
			'value'  => '0x' . bin2hex(random_bytes(20)),
			'symbol' => ['id' => $symbol, 'precision' => $precision],
		],
		'deposits'             => [],
	];

	file_put_contents($dir . '/' . $id . '.json', (string)json_encode($invoice));

	reply(201, $invoice);
}

/** GET /invoice/my/{id}/ — the stored invoice with the control overrides applied. */
function readInvoice(string $dir, array $control, string $id): void {
	$file = $dir . '/' . basename($id) . '.json';

	if (!is_file($file)) {
		reply(404, ['detail' => 'Not found.']);
	}

	$invoice = json_decode((string)file_get_contents($file), true);

	if (!is_array($invoice)) {
		reply(500, ['detail' => 'Stored invoice is unreadable']);
	}

	foreach (['status', 'current_value', 'target_value_symbol'] as $field) {
		if (isset($control['invoice'][$field])) {
			$invoice[$field] = (string)$control['invoice'][$field];
		}
	}

	reply(200, $invoice);
}

// Helpers

/**
 * The header and signature check; returns the rejection detail or null.
 * The envelope rebuilt here is the one the client signed: {body, params, path}.
 */
function authenticate(string $key_id, string $secret, string $method, string $path, string $query, string $raw_body): ?string {
	$sent_key_id = (string)($_SERVER['HTTP_X_API_KEY_ID'] ?? '');
	$timestamp = (string)($_SERVER['HTTP_X_TIMESTAMP'] ?? '');
	$signature = (string)($_SERVER['HTTP_X_SIGNATURE'] ?? '');

	if ('' === $sent_key_id || '' === $timestamp || '' === $signature) {
		return 'Authentication credentials were not provided.';
	}

	if ('' !== $key_id && $sent_key_id !== $key_id) {
		return 'Invalid API key!';
	}

	$body = new stdClass();

	if ('POST' === $method && '' !== $raw_body) {
		$decoded = json_decode($raw_body);

		if (null === $decoded && JSON_ERROR_NONE !== json_last_error()) {
			return 'Invalid JSON body';
		}

		$body = $decoded;
	}

	if (!Signature::verify($signature, (int)$timestamp, Signature::apiEnvelope($path, $query, $body), $secret)) {
		return 'Invalid signature!';
	}

	return null;
}

/** What to answer next; an absent or unreadable file means "behave normally". */
function control(string $dir): array {
	$file = $dir . '/control.json';

	if (!is_file($file)) {
		return [];
	}

	$decoded = json_decode((string)file_get_contents($file), true);

	return is_array($decoded) ? $decoded : [];
}

/** One line per request, so a test can count the attempts the client made. */
function record(string $dir, string $line): void {
	file_put_contents($dir . '/requests.log', $line . "\n", FILE_APPEND);
}

/** An amount as the API sends it: eighteen decimals. */
function amount(float $value): string {
	return sprintf('%.8F', $value) . '0000000000';
}

/** JSON answer; ends the request. */
function reply(int $status, array $data): void {
	http_response_code($status);
	header('Content-Type: application/json');

	echo json_encode($data, JSON_UNESCAPED_SLASHES);

	exit;
}

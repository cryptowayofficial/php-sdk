<?php
/**
 * Money arithmetic behind order decisions. Returns facts (covered, mismatch,
 * amounts to quote); callers turn them into order-history comments.
 */

namespace Cryptoway\Core;

final class Amounts {

	/** An overpayment above this fraction of the invoice is flagged. */
	public const OVERPAYMENT_THRESHOLD = 0.01;

	/** Fiat tolerance for rate rounding. */
	public const FIAT_TOLERANCE = 0.01;

	/** Coin tolerance for amounts that are not plain decimals (exponent form), not for missing money. */
	public const COIN_TOLERANCE = 0.00000001;

	private function __construct() {}

	/** A coin amount for display: "19.909971790000000000" -> "19.90997179"; non-numeric input is returned cleaned. */
	public static function tidy(string $amount): string {
		$amount = ApiClient::cleanText($amount);

		if (!is_numeric($amount)) {
			return $amount;
		}

		return rtrim(rtrim(number_format((float)$amount, 8, '.', ''), '0'), '.');
	}

	/** A fiat amount for a comment: two decimals, dot, no thousands separator. */
	public static function fiat(float $amount): string {
		return number_format($amount, 2, '.', '');
	}

	/**
	 * Whether the coins received cover the invoice target (both in coin units). Plain decimals
	 * are compared exactly, as in shortfall(); other numeric forms within COIN_TOLERANCE.
	 * Missing figures count as not covered: an unpaid invoice must never pass as paid.
	 */
	public static function invoiceIsCovered(?array $invoice): bool {
		if (!is_array($invoice)) {
			return false;
		}

		$target = (string)($invoice['target_value_symbol'] ?? '');
		$paid = (string)($invoice['current_value'] ?? '');

		if (!is_numeric($target) || !is_numeric($paid)) {
			return false;
		}

		if ((float)$target <= 0) {
			return false;
		}

		$cmp = self::compareDecimal($paid, $target);

		if (null !== $cmp) {
			return $cmp >= 0;
		}

		return ((float)$paid) + self::COIN_TOLERANCE >= (float)$target;
	}

	/**
	 * Paid and target coin amounts when an invoice received less than it asks for
	 * (the target already includes markup and buyer fee). Exact decimal comparison.
	 *
	 * @return array{paid:string, target:string}|null Null when nothing is missing or either figure is absent or not a plain decimal.
	 */
	public static function shortfall(?array $invoice): ?array {
		if (!is_array($invoice)) {
			return null;
		}

		$target = $invoice['target_value_symbol'] ?? null;
		$paid = $invoice['current_value'] ?? null;

		if (!is_string($target) && !is_int($target) && !is_float($target)) {
			return null;
		}

		if (!is_string($paid) && !is_int($paid) && !is_float($paid)) {
			return null;
		}

		$target = (string)$target;
		$paid = (string)$paid;

		$cmp = self::compareDecimal($paid, $target);

		if (null === $cmp || $cmp >= 0) {
			return null;
		}

		return [
			'paid'   => self::plainDecimal($paid),
			'target' => self::plainDecimal($target),
		];
	}

	/**
	 * Compare two non-negative plain decimal strings digit by digit.
	 *
	 * @return int|null -1, 0 or 1; null when either is not a plain decimal.
	 */
	public static function compareDecimal(string $a, string $b): ?int {
		$x = self::splitDecimal($a);
		$y = self::splitDecimal($b);

		if (null === $x || null === $y) {
			return null;
		}

		if (strlen($x[0]) !== strlen($y[0])) {
			return strlen($x[0]) <=> strlen($y[0]);
		}

		if ($x[0] !== $y[0]) {
			return strcmp($x[0], $y[0]) <=> 0;
		}

		$length = max(strlen($x[1]), strlen($y[1]));

		return strcmp(str_pad($x[1], $length, '0'), str_pad($y[1], $length, '0')) <=> 0;
	}

	/** A plain decimal without padding zeros, without rounding: "105.024465000" -> "105.024465". */
	public static function plainDecimal(string $value): string {
		$parts = self::splitDecimal($value);

		if (null === $parts) {
			return ApiClient::cleanText($value);
		}

		return ('' !== $parts[0] ? $parts[0] : '0') . ('' !== $parts[1] ? '.' . $parts[1] : '');
	}

	/**
	 * Integer and fraction digits without leading or trailing zeros; null for a sign, an exponent or text.
	 *
	 * @return array{0:string, 1:string}|null
	 */
	private static function splitDecimal(string $value): ?array {
		if (1 !== preg_match('/^\s*\+?(\d*)(?:\.(\d*))?\s*$/', $value, $m) || '' === $m[1] . ($m[2] ?? '')) {
			return null;
		}

		return [ltrim($m[1], '0'), rtrim($m[2] ?? '', '0')];
	}

	/**
	 * Paid and target amounts when an overpayment is worth a comment. Wallets
	 * round amounts up, so the test is proportional.
	 *
	 * @return array{paid:string, target:string}|null
	 */
	public static function overpayment(?array $invoice): ?array {
		if (!is_array($invoice)) {
			return null;
		}

		$target = (string)($invoice['target_value_symbol'] ?? '');
		$paid = (string)($invoice['current_value'] ?? '');

		if (!is_numeric($target) || !is_numeric($paid) || (float)$target <= 0) {
			return null;
		}

		$excess = (float)$paid - (float)$target;

		if ($excess <= 0) {
			return null;
		}

		// One step of the coin's precision is wallet rounding (5.1 sent for 5.00046
		// on a one-decimal coin), not an overpayment.
		$precision = $invoice['target_wallet']['symbol']['precision'] ?? null;

		if (is_numeric($precision) && $excess <= pow(10, -(int)$precision) + 1e-9) {
			return null;
		}

		if (($excess / (float)$target) < self::OVERPAYMENT_THRESHOLD) {
			return null;
		}

		return [
			'paid'   => self::tidy($paid),
			'target' => self::tidy($target),
		];
	}

	/**
	 * Verify the invoice amount and currency against the order.
	 *
	 * @param float  $expected_total  Order total in the order currency.
	 * @param string $stored_amount   Invoice amount saved at creation, used when the invoice is not at hand.
	 * @param string $stored_currency Invoice currency saved at creation.
	 *
	 * @return array{result:string, over:bool, amount:string, currency:string, expected_total:string, expected_currency:string}
	 *         result: ok | unknown | amount_mismatch | currency_mismatch; over: the invoice asked for more than the order total.
	 */
	public static function check(?array $invoice, float $expected_total, string $expected_currency, string $stored_amount = '', string $stored_currency = ''): array {
		$expected_currency = strtoupper($expected_currency);

		$amount = $invoice['target_value_fiat'] ?? $stored_amount;
		$currency = $invoice['target_currency_fiat'] ?? $stored_currency;

		$out = [
			'result'            => 'ok',
			'over'              => false,
			'amount'            => '',
			'currency'          => strtoupper((string)$currency),
			'expected_total'    => self::fiat($expected_total),
			'expected_currency' => $expected_currency,
		];

		if (null === $amount || '' === (string)$amount) {
			$out['result'] = 'unknown';

			return $out;
		}

		$amount = (float)$amount;
		$out['amount'] = self::fiat($amount);

		// One-sided: less than the order total is a mismatch; more is legitimate
		// (project markup or buyer fee) and only flagged.
		if ($expected_total - $amount > self::FIAT_TOLERANCE) {
			$out['result'] = 'amount_mismatch';

			return $out;
		}

		$out['over'] = ($amount - $expected_total) > self::FIAT_TOLERANCE;

		if ('' !== (string)$currency && strtoupper((string)$currency) !== $expected_currency) {
			$out['result'] = 'currency_mismatch';

			return $out;
		}

		return $out;
	}

	/** Whether a positive amount was received. */
	public static function hasPartialPayment(string $paid_amount): bool {
		return is_numeric($paid_amount) && (float)$paid_amount > 0;
	}
}

<?php
/**
 * The money rules. Every case here is a decision that can cost the merchant the
 * goods: an invoice one satoshi short must not read as covered, and an invoice
 * that asks for more than the order total is normal (project markup, buyer fee)
 * and must not read as a mismatch.
 *
 * @license MIT
 */

declare(strict_types=1);

namespace Cryptoway\Core\Tests;

use Cryptoway\Core\Amounts;
use PHPUnit\Framework\TestCase;

final class AmountsTest extends TestCase {

	/**
	 * An invoice as the API returns it: eighteen-decimal strings.
	 *
	 * @return array<string, mixed>
	 */
	private function invoice(string $paid, string $target, int $precision = 8): array {
		return [
			'current_value'        => $paid,
			'target_value_symbol'  => $target,
			'target_wallet'        => ['symbol' => ['precision' => $precision]],
			'target_value_fiat'    => '30.26',
			'target_currency_fiat' => 'EUR',
		];
	}

	// Covered or not

	public function testExactPaymentIsCovered(): void {
		$this->assertTrue(Amounts::invoiceIsCovered($this->invoice('19.909971790000000000', '19.909971790000000000')));
	}

	public function testOverpaymentIsCovered(): void {
		$this->assertTrue(Amounts::invoiceIsCovered($this->invoice('20.000000000000000000', '19.909971790000000000')));
	}

	/** One unit of the last decimal short is still short; no tolerance closes that gap. */
	public function testUnderpaymentIsNotCovered(): void {
		$this->assertFalse(Amounts::invoiceIsCovered($this->invoice('19.909971780000000000', '19.909971790000000000')));
		$this->assertFalse(Amounts::invoiceIsCovered($this->invoice('0.000000000000000000', '19.909971790000000000')));
	}

	public function testMissingOrUnusableFiguresAreNotCovered(): void {
		$this->assertFalse(Amounts::invoiceIsCovered(null));
		$this->assertFalse(Amounts::invoiceIsCovered([]));
		$this->assertFalse(Amounts::invoiceIsCovered(['target_value_symbol' => '19.9']), 'nothing received');
		$this->assertFalse(Amounts::invoiceIsCovered($this->invoice('paid', '19.9')), 'not a number');
		$this->assertFalse(Amounts::invoiceIsCovered($this->invoice('19.9', '0')), 'no target to cover');
	}

	/** Amounts in exponent form are not plain decimals; they fall back to the coin tolerance. */
	public function testExponentFormFallsBackToTheCoinTolerance(): void {
		$this->assertTrue(Amounts::invoiceIsCovered($this->invoice('2.0e1', '19.9')));
		$this->assertFalse(Amounts::invoiceIsCovered($this->invoice('1.9e1', '19.9')));
	}

	/**
	 * The fallback is the one place a tolerance could swallow a shortfall, so its
	 * edge is pinned: one coin unit short still counts, ten times that does not.
	 */
	public function testTheCoinToleranceEndsWhereItSaysItDoes(): void {
		// 19.9 minus exactly one COIN_TOLERANCE (1e-8), and minus ten of them.
		$this->assertTrue(Amounts::invoiceIsCovered($this->invoice('1.989999999e1', '19.9')), 'short by the tolerance itself');
		$this->assertFalse(Amounts::invoiceIsCovered($this->invoice('1.98999999e1', '19.9')), 'short by ten times the tolerance');
	}

	// Shortfall

	public function testShortfallReportsWhatIsMissing(): void {
		$this->assertSame(
			['paid' => '19.90997178', 'target' => '19.90997179'],
			Amounts::shortfall($this->invoice('19.909971780000000000', '19.909971790000000000'))
		);
	}

	public function testShortfallIsNullWhenNothingIsMissing(): void {
		$this->assertNull(Amounts::shortfall($this->invoice('19.909971790000000000', '19.909971790000000000')));
		$this->assertNull(Amounts::shortfall($this->invoice('25', '19.9')));
		$this->assertNull(Amounts::shortfall(null));
		$this->assertNull(Amounts::shortfall($this->invoice('1.9e1', '19.9')), 'not a plain decimal');
	}

	// Decimal comparison

	public function testPaddingZerosAndLeadingZerosDoNotChangeAValue(): void {
		$this->assertSame(0, Amounts::compareDecimal('105.024465000', '105.024465'));
		$this->assertSame(0, Amounts::compareDecimal('0009', '9'));
		$this->assertSame(0, Amounts::compareDecimal('1.50', '1.5'));
		$this->assertSame(0, Amounts::compareDecimal('0', '0.000'));
	}

	public function testLongerIntegerPartsAreLarger(): void {
		$this->assertSame(-1, Amounts::compareDecimal('9', '10'));
		$this->assertSame(1, Amounts::compareDecimal('100', '99.99'));
		$this->assertSame(-1, Amounts::compareDecimal('19.90997178', '19.90997179'));
	}

	public function testNonDecimalsCannotBeCompared(): void {
		$this->assertNull(Amounts::compareDecimal('-1', '1'));
		$this->assertNull(Amounts::compareDecimal('1e3', '1000'));
		$this->assertNull(Amounts::compareDecimal('abc', '1'));
		$this->assertNull(Amounts::compareDecimal('', '1'));
	}

	public function testPlainDecimalDropsPaddingWithoutRounding(): void {
		$this->assertSame('105.024465', Amounts::plainDecimal('105.024465000'));
		$this->assertSame('0.00000001', Amounts::plainDecimal('0.000000010'));
		$this->assertSame('0', Amounts::plainDecimal('0.000'));
	}

	// Overpayment

	public function testOverpaymentWithinOneStepOfTheCoinPrecisionIsWalletRounding(): void {
		$this->assertNull(Amounts::overpayment($this->invoice('100.005', '100', 2)));
	}

	public function testOverpaymentBelowOnePercentIsNotWorthANote(): void {
		$this->assertNull(Amounts::overpayment($this->invoice('100.5', '100', 8)));
	}

	public function testRealOverpaymentIsReported(): void {
		$this->assertSame(['paid' => '102', 'target' => '100'], Amounts::overpayment($this->invoice('102', '100', 8)));
	}

	public function testUnderpaymentIsNeverAnOverpayment(): void {
		$this->assertNull(Amounts::overpayment($this->invoice('99', '100', 8)));
		$this->assertNull(Amounts::overpayment(null));
	}

	// Invoice against order: markup is expected, a shortfall is not

	public function testAnInvoiceAboveTheOrderTotalIsMarkupNotAMismatch(): void {
		$result = Amounts::check($this->invoice('0', '19.9'), 30.00, 'EUR');

		$this->assertSame('ok', $result['result']);
		$this->assertTrue($result['over'], 'the buyer fee and the project markup sit on top of the order total');
		$this->assertSame('30.26', $result['amount']);
		$this->assertSame('30.00', $result['expected_total']);
	}

	public function testAnInvoiceBelowTheOrderTotalIsAMismatch(): void {
		$invoice = $this->invoice('0', '19.9');
		$invoice['target_value_fiat'] = '29.50';

		$this->assertSame('amount_mismatch', Amounts::check($invoice, 30.00, 'EUR')['result']);
	}

	public function testRateRoundingStaysWithinTheFiatTolerance(): void {
		$invoice = $this->invoice('0', '19.9');
		$invoice['target_value_fiat'] = '29.995';

		$result = Amounts::check($invoice, 30.00, 'EUR');

		$this->assertSame('ok', $result['result']);
		$this->assertFalse($result['over']);
	}

	public function testCurrencyMismatchIsReported(): void {
		$invoice = $this->invoice('0', '19.9');
		$invoice['target_currency_fiat'] = 'USD';

		$this->assertSame('currency_mismatch', Amounts::check($invoice, 30.00, 'EUR')['result']);
	}

	public function testWithoutAnInvoiceTheStoredAmountIsUsedAndOtherwiseTheResultIsUnknown(): void {
		$this->assertSame('ok', Amounts::check(null, 30.00, 'EUR', '30.26', 'EUR')['result']);
		$this->assertSame('unknown', Amounts::check(null, 30.00, 'EUR')['result']);
	}

	// Formatting

	public function testTidyAndFiatFormatting(): void {
		$this->assertSame('19.90997179', Amounts::tidy('19.909971790000000000'));
		$this->assertSame('0', Amounts::tidy('0.000000000000000000'));
		$this->assertSame('30.00', Amounts::fiat(30.0));
		$this->assertSame('1234.57', Amounts::fiat(1234.567));
	}

	public function testPartialPaymentIsAnyPositiveAmount(): void {
		$this->assertTrue(Amounts::hasPartialPayment('0.00000001'));
		$this->assertFalse(Amounts::hasPartialPayment('0'));
		$this->assertFalse(Amounts::hasPartialPayment(''));
	}
}

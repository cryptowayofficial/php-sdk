<?php
/**
 * Coin symbols. Cryptoway sends the coin and the network as one string, and the
 * checkout page shows them apart.
 *
 * @license MIT
 */

declare(strict_types=1);

namespace Cryptoway\Core\Tests;

use Cryptoway\Core\Coins;
use PHPUnit\Framework\TestCase;

final class CoinsTest extends TestCase {

	public function testASymbolSplitsIntoCoinAndNetwork(): void {
		$this->assertSame(['coin' => 'USDT', 'network' => 'BEP20'], Coins::split('USDTBEP20'));
		$this->assertSame(['coin' => 'USDT', 'network' => 'TRC20'], Coins::split('usdttrc20'));
		$this->assertSame(['coin' => 'BTC', 'network' => ''], Coins::split('BTC'));
	}

	/** BEP2 must not be found at the tail of BEP20. */
	public function testTheLongestNetworkSuffixWins(): void {
		$this->assertSame('BEP-20', Coins::network('USDTBEP20'));
		$this->assertSame('BEP-2', Coins::network('USDTBEP2'));
	}

	public function testAnUnknownSymbolIsLeftWhole(): void {
		$this->assertSame(['coin' => 'WHATEVER99', 'network' => ''], Coins::split('whatever99'));
	}

	public function testLabelAndTicker(): void {
		$this->assertSame('USDT BEP-20', Coins::label('USDTBEP20'));
		$this->assertSame('BTC', Coins::label('BTC'));
		$this->assertSame('USDT', Coins::ticker('USDTERC20'));
		$this->assertSame('?', Coins::ticker(''));
	}
}

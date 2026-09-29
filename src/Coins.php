<?php
/**
 * Coin symbols for display. Cryptoway sends coin and network as one string
 * (USDTBEP20); this splits it into a coin and a network.
 */

namespace Cryptoway\Core;

final class Coins {

	/** Coin symbols recognised in front of a network suffix. */
	private const KNOWN = [
		'BTC', 'ETH', 'USDT', 'USDC', 'BNB', 'LTC', 'TON', 'TRX', 'SOL', 'XRP', 'DOGE', 'MATIC',
		'POL', 'DAI', 'BCH', 'ADA', 'DOT', 'AVAX', 'XMR', 'SHIB', 'BUSD', 'TUSD', 'FRAX',
	];

	/** Network suffixes, longest first so BEP2 does not match the tail of BEP20. */
	private const NETWORKS = [
		'ERC20', 'TRC20', 'BEP20', 'BEP2', 'POLYGON', 'ARBITRUM', 'OPTIMISM',
		'AVAXC', 'MATIC', 'BASE', 'SOL', 'TON', 'BSC', 'ETH', 'TRX',
	];

	private const NETWORK_LABELS = [
		'ERC20'    => 'ERC-20',
		'TRC20'    => 'TRC-20',
		'BEP20'    => 'BEP-20',
		'BEP2'     => 'BEP-2',
		'POLYGON'  => 'Polygon',
		'ARBITRUM' => 'Arbitrum',
		'OPTIMISM' => 'Optimism',
		'AVAXC'    => 'Avalanche C',
		'MATIC'    => 'Polygon',
		'BASE'     => 'Base',
		'SOL'      => 'Solana',
		'TON'      => 'TON',
		'BSC'      => 'BNB Chain',
		'ETH'      => 'Ethereum',
		'TRX'      => 'TRON',
	];

	private function __construct() {}

	public static function normalise(string $symbol): string {
		return strtoupper(trim($symbol));
	}

	/**
	 * Split a symbol into coin and network: USDTBEP20 -> USDT + BEP20.
	 *
	 * @return array{coin:string, network:string}
	 */
	public static function split(string $symbol): array {
		$symbol = self::normalise($symbol);

		if (in_array($symbol, self::KNOWN, true)) {
			return ['coin' => $symbol, 'network' => ''];
		}

		foreach (self::NETWORKS as $network) {
			if (!str_ends_with($symbol, $network)) {
				continue;
			}

			$coin = substr($symbol, 0, -strlen($network));
			$coin = rtrim($coin, '-_. ');

			if ('' !== $coin && in_array($coin, self::KNOWN, true)) {
				return ['coin' => $coin, 'network' => $network];
			}
		}

		return ['coin' => $symbol, 'network' => ''];
	}

	/** The coin without the network. */
	public static function base(string $symbol): string {
		return self::split($symbol)['coin'];
	}

	/** Readable network name; empty when the symbol has no network. */
	public static function network(string $symbol): string {
		$network = self::split($symbol)['network'];

		if ('' === $network) {
			return '';
		}

		return self::NETWORK_LABELS[$network] ?? $network;
	}

	/** The symbol for display: "USDT BEP-20". */
	public static function label(string $symbol): string {
		$network = self::network($symbol);
		$coin = self::base($symbol);

		return '' === $network ? $coin : $coin . ' ' . $network;
	}

	/** Up to four letters of the coin; "?" when empty. */
	public static function ticker(string $symbol): string {
		$coin = self::base($symbol);

		if ('' === $coin) {
			return '?';
		}

		return mb_substr($coin, 0, 4);
	}
}

<?php
/**
 * Canonical JSON is the whole of the signature: two implementations that
 * disagree by one byte produce different HMACs. The expected strings here were
 * produced by json.dumps(data, separators=(",", ":"), sort_keys=True) in
 * CPython, which is what Cryptoway signs with.
 *
 * @license MIT
 */

declare(strict_types=1);

namespace Cryptoway\Core\Tests;

use Cryptoway\Core\CanonicalJson;
use Cryptoway\Core\Signature;
use PHPUnit\Framework\TestCase;

final class CanonicalJsonTest extends TestCase {

	public function testKeysAreSortedRecursivelyAndListsKeepTheirOrder(): void {
		$data = [
			'zeta'          => [3, 1, ['z' => 1, 'y' => 2]],
			'alpha'         => 'order/50%',
			'amount'        => 30.26,
			'big'           => 1234567.891,
			'flag'          => true,
			'nothing'       => null,
			'nested'        => ['b' => 2, 'a' => ['d' => 4, 'c' => 3]],
			'list_of_lists' => [[2, 1], []],
		];

		$this->assertSame(
			'{"alpha":"order/50%","amount":30.26,"big":1234567.891,"flag":true,"list_of_lists":[[2,1],[]],'
				. '"nested":{"a":{"c":3,"d":4},"b":2},"nothing":null,"zeta":[3,1,{"y":2,"z":1}]}',
			CanonicalJson::encode(CanonicalJson::toObject($data))
		);
	}

	/**
	 * Non-ASCII is escaped, "/" is not: the two rules that differ from PHP's
	 * defaults. The escaped bytes themselves are pinned by the HMAC vectors in
	 * SignatureTest, which cover the same text.
	 */
	public function testEscapingMatchesTheReference(): void {
		$encoded = CanonicalJson::encode(CanonicalJson::toObject(['url' => 'https://pay.cryptoway.com/invoice/inv_1', 'ru' => 'Оплата']));

		$this->assertMatchesRegularExpression('/^[\x20-\x7E]+$/', $encoded, 'every non-ASCII character is escaped');
		$this->assertStringContainsString('"url":"https://pay.cryptoway.com/invoice/inv_1"', $encoded, 'slashes stay as they are');
		$this->assertSame(['ru' => 'Оплата', 'url' => 'https://pay.cryptoway.com/invoice/inv_1'], json_decode($encoded, true));
	}

	public function testEmptyObjectIsNotAnEmptyList(): void {
		$this->assertSame('{}', CanonicalJson::encode(new \stdClass()));
		$this->assertSame('[]', CanonicalJson::encode([]));
		$this->assertSame('{"body":{}}', CanonicalJson::encode(CanonicalJson::toObject(['body' => new \stdClass()])));
	}

	/**
	 * With serialize_precision 17 — the value some php.ini files ship — plain
	 * json_encode prints 30.26 as 30.260000000000002 and every signature breaks.
	 * The library pins the setting for the call and puts it back.
	 */
	public function testSerializePrecisionIsPinnedAndRestored(): void {
		$previous = ini_get('serialize_precision');

		ini_set('serialize_precision', '17');

		try {
			$this->assertSame('{"v":30.260000000000002}', json_encode(['v' => 30.26]), 'the setting must really be in force');
			$this->assertSame('{"v":30.26}', CanonicalJson::encode(CanonicalJson::toObject(['v' => 30.26])));
			$this->assertSame('17', ini_get('serialize_precision'), 'the caller keeps the setting it had');
		} finally {
			ini_set('serialize_precision', false === $previous ? '-1' : $previous);
		}
	}

	public function testFloatsPrintAsTheReferenceDoes(): void {
		$this->assertSame('{"v":0.1}', CanonicalJson::encode(CanonicalJson::toObject(['v' => 0.1])));
		$this->assertSame('{"v":19.90997179}', CanonicalJson::encode(CanonicalJson::toObject(['v' => 19.909971790000000000])));
		$this->assertSame('{"v":105.024465}', CanonicalJson::encode(CanonicalJson::toObject(['v' => 105.024465])));
	}

	/**
	 * Signature::canonical() takes mixed and the README offers it directly, so an
	 * associative array must be sorted on its way in, not only a stdClass.
	 */
	public function testAPlainAssociativeArrayIsSortedToo(): void {
		$this->assertSame('{"a":2,"b":1}', Signature::canonical(['b' => 1, 'a' => 2]));
		$this->assertSame('{"a":{"c":3,"d":4},"b":2}', CanonicalJson::encode(['b' => 2, 'a' => ['d' => 4, 'c' => 3]]));
	}

	public function testToObjectKeepsListsAndConvertsMaps(): void {
		$converted = CanonicalJson::toObject(['list' => [1, 2], 'map' => ['a' => 1]]);

		$this->assertInstanceOf(\stdClass::class, $converted);
		$this->assertIsArray($converted->list);
		$this->assertInstanceOf(\stdClass::class, $converted->map);
	}

	public function testIsListRecognisesJsonLists(): void {
		$this->assertTrue(CanonicalJson::isList([]));
		$this->assertTrue(CanonicalJson::isList([1, 2, 3]));
		$this->assertFalse(CanonicalJson::isList([1 => 'a']));
		$this->assertFalse(CanonicalJson::isList(['a' => 1]));
	}

	public function testUnencodableDataThrows(): void {
		$this->expectException(\JsonException::class);

		CanonicalJson::encode(CanonicalJson::toObject(['v' => INF]));
	}
}

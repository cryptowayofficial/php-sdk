<?php
/**
 * Runs stub_api.php on PHP's built-in web server, bound to the loopback
 * interface, and hands the tests its base URL. Nothing here opens a connection
 * that leaves the machine: the suite is expected to pass with the network
 * switched off (docker run --network none).
 *
 * @license MIT
 */

declare(strict_types=1);

namespace Cryptoway\Core\Tests\Support;

final class StubApi {

	/** Credentials the stub expects; a test that signs with anything else is refused. */
	public const KEY_ID = 'cw_test_key_id';
	public const SECRET = 'cw_test_api_secret';

	/** Seconds to wait for the server to accept connections. */
	private const BOOT_TIMEOUT = 10;

	/** @var resource|null */
	private $process = null;

	private string $dir;

	private string $base_url;

	private function __construct(string $dir, string $base_url) {
		$this->dir = $dir;
		$this->base_url = $base_url;
	}

	/** Start a server on a free loopback port and wait until it answers. */
	public static function start(): self {
		$dir = sys_get_temp_dir() . '/cryptoway-stub-' . bin2hex(random_bytes(6));

		if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
			throw new \RuntimeException('Could not create the stub directory ' . $dir);
		}

		$port = self::freePort();
		$stub = new self($dir, 'http://127.0.0.1:' . $port);

		// stdin is a pipe that is closed right away (portable; /dev/null is not);
		// the server's own log goes to a file so it cannot pollute the test output.
		$descriptors = [
			0 => ['pipe', 'r'],
			1 => ['file', $dir . '/server.log', 'a'],
			2 => ['file', $dir . '/server.log', 'a'],
		];

		$process = proc_open(
			[PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', __DIR__, __DIR__ . '/stub_api.php'],
			$descriptors,
			$pipes,
			__DIR__,
			[
				'CRYPTOWAY_STUB_DIR'      => $dir,
				'CRYPTOWAY_STUB_SECRET'   => self::SECRET,
				'CRYPTOWAY_STUB_KEY_ID'   => self::KEY_ID,
				'CRYPTOWAY_STUB_AUTOLOAD' => self::autoloader(),
				'PATH'                    => (string)getenv('PATH'),
			]
		);

		if (!is_resource($process)) {
			throw new \RuntimeException('Could not start the stub API server');
		}

		if (isset($pipes[0]) && is_resource($pipes[0])) {
			fclose($pipes[0]);
		}

		$stub->process = $process;

		$deadline = microtime(true) + self::BOOT_TIMEOUT;

		while (microtime(true) < $deadline) {
			$socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);

			if (is_resource($socket)) {
				fclose($socket);

				return $stub;
			}

			usleep(50000);
		}

		$stub->stop();

		throw new \RuntimeException('The stub API server did not start on port ' . $port);
	}

	public function baseUrl(): string {
		return $this->base_url;
	}

	/** What the stub should answer next; replaces the previous settings. */
	public function control(array $control): void {
		file_put_contents($this->dir . '/control.json', (string)json_encode($control));
	}

	/** One entry per request the stub received, oldest first. */
	public function requests(): array {
		$log = $this->dir . '/requests.log';

		if (!is_file($log)) {
			return [];
		}

		return array_values(array_filter(explode("\n", (string)file_get_contents($log)), static fn ($line) => '' !== $line));
	}

	/** Forget the requests seen so far, so a test can count its own. */
	public function reset(): void {
		@unlink($this->dir . '/requests.log');
		@unlink($this->dir . '/control.json');
	}

	/** Stop the server and remove its directory. */
	public function stop(): void {
		if (is_resource($this->process)) {
			proc_terminate($this->process);
			proc_close($this->process);

			$this->process = null;
		}

		foreach ((array)glob($this->dir . '/*') as $file) {
			@unlink((string)$file);
		}

		@rmdir($this->dir);
	}

	/**
	 * The autoloader this run is using, so the server loads the same classes
	 * even when the vendor directory is not the default one.
	 */
	private static function autoloader(): string {
		$loader = (new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName();

		return false !== $loader ? dirname((string)$loader, 2) . '/autoload.php' : '';
	}

	/** A port nothing is listening on: bind port 0, read what was assigned, release it. */
	private static function freePort(): int {
		$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

		if (false === $socket) {
			throw new \RuntimeException('Could not reserve a loopback port: ' . $error);
		}

		$name = (string)stream_socket_get_name($socket, false);

		fclose($socket);

		return (int)substr($name, strrpos($name, ':') + 1);
	}
}

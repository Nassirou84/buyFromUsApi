<?php

declare(strict_types=1);

namespace App\Tests\Redis;

use App\Message\NewProductAddedMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransportFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Throwable;

use function extension_loaded;

/**
 * Talks to a real Redis (REDIS_URL, default redis://localhost:6379; `docker compose up -d` starts one).
 * Skipped when ext-redis is missing or the server is unreachable. Every key is namespaced per run and removed afterwards.
 */
final class RedisIntegrationTest extends TestCase
{
    private string $url;

    private string $run;

    /** @var array<string, \Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransport> keyed by stream name */
    private array $transports = [];

    protected function setUp(): void
    {
        if (!extension_loaded('redis')) {
            self::markTestSkipped('ext-redis is not installed.');
        }

        $this->url = $_SERVER['REDIS_URL'] ?? $_ENV['REDIS_URL'] ?? 'redis://localhost:6379';
        $this->run = 'phpunit_' . bin2hex(random_bytes(4));

        try {
            $redis = RedisAdapter::createConnection($this->url);
            $redis->ping();
        } catch (Throwable $e) {
            self::markTestSkipped('Redis is not reachable at ' . $this->url . ': ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if ([] === $this->transports) {
            return;
        }

        $redis = RedisAdapter::createConnection($this->url);
        foreach ($this->transports as $stream => $transport) {
            $transport->close();
            $redis->del($stream);
        }
    }

    public function testCacheStoresAndReturnsValues(): void
    {
        $cache = new RedisAdapter(RedisAdapter::createConnection($this->url), $this->run);

        $item = $cache->getItem('settings');
        self::assertFalse($item->isHit());

        $cache->save($item->set(['currency' => 'XOF']));

        self::assertSame(['currency' => 'XOF'], $cache->getItem('settings')->get());

        $cache->clear();
    }

    public function testCacheIsSharedBetweenConnections(): void
    {
        // The API and the Messenger worker are separate processes: they only share what is in Redis.
        $api = new RedisAdapter(RedisAdapter::createConnection($this->url), $this->run);
        $worker = new RedisAdapter(RedisAdapter::createConnection($this->url), $this->run);

        $api->save($api->getItem('auth.code')->set('123456'));
        self::assertSame('123456', $worker->getItem('auth.code')->get());

        $api->deleteItem('auth.code');
        self::assertFalse($worker->getItem('auth.code')->isHit());

        $api->clear();
    }

    public function testCacheEntriesExpire(): void
    {
        $cache = new RedisAdapter(RedisAdapter::createConnection($this->url), $this->run, 1);

        $cache->save($cache->getItem('otp')->set('42'));
        self::assertTrue($cache->getItem('otp')->isHit());

        sleep(2);

        self::assertFalse($cache->getItem('otp')->isHit());
        $cache->clear();
    }

    public function testMessengerTransportDeliversAndAcknowledgesAMessage(): void
    {
        $transport = $this->transport('high');

        $transport->send(new Envelope(new NewProductAddedMessage('uid-1')));
        self::assertSame(1, $transport->getMessageCount());

        $received = iterator_to_array($transport->get(), false);
        self::assertCount(1, $received);
        $message = $received[0]->getMessage();
        self::assertInstanceOf(NewProductAddedMessage::class, $message);
        self::assertSame('uid-1', $message->uid);

        $transport->ack($received[0]);
        self::assertSame(0, $transport->getMessageCount());
        self::assertSame([], iterator_to_array($transport->get(), false));
    }

    public function testPriorityStreamsAreIndependent(): void
    {
        $high = $this->transport('high');
        $low = $this->transport('low');

        $high->send(new Envelope(new NewProductAddedMessage('urgent')));

        self::assertSame(1, $high->getMessageCount());
        self::assertSame(1, RedisAdapter::createConnection($this->url)->xLen($this->run . '_high'), 'The message must land in the stream named by the `stream` option.');
        self::assertSame(0, $low->getMessageCount());
        self::assertSame([], iterator_to_array($low->get(), false));
    }

    private function transport(string $stream): \Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransport
    {
        $name = $this->run . '_' . $stream;
        $transport = (new RedisTransportFactory())->createTransport(
            rtrim($this->url, '/'), // no DSN path: it would override the `stream` option
            ['stream' => $name],
            new PhpSerializer(),
        );
        $transport->setup();

        return $this->transports[$name] = $transport;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Redis;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards the Redis wiring in config/ without needing a Redis server.
 */
final class RedisConfigurationTest extends TestCase
{
    private const CONFIG_DIR = __DIR__ . '/../../config/packages';

    public function testCacheAppAndPoolsUseRedis(): void
    {
        $cache = Yaml::parseFile(self::CONFIG_DIR . '/cache.yaml')['framework']['cache'];

        self::assertSame('cache.adapter.redis', $cache['app']);
        self::assertSame('%env(REDIS_URL)%', $cache['default_redis_provider']);

        foreach (['auth_codes.cache', 'settings.cache'] as $pool) {
            self::assertSame('cache.adapter.redis', $cache['pools'][$pool]['adapter'], $pool);
        }
    }

    public function testTestEnvironmentDoesNotNeedRedisForCache(): void
    {
        $cache = Yaml::parseFile(self::CONFIG_DIR . '/cache.yaml')['when@test']['framework']['cache'];

        self::assertSame('cache.adapter.array', $cache['app']);
        foreach (['auth_codes.cache', 'settings.cache'] as $pool) {
            self::assertSame('cache.adapter.array', $cache['pools'][$pool]['adapter'], $pool);
        }
    }

    public function testAsyncTransportsUseOneRedisStreamEach(): void
    {
        $transports = Yaml::parseFile(self::CONFIG_DIR . '/messenger.yaml')['framework']['messenger']['transports'];

        $streams = [];
        foreach (['async_priority_high' => 'high', 'async_default' => 'default', 'async_priority_low' => 'low'] as $name => $stream) {
            self::assertSame('%env(MESSENGER_TRANSPORT_DSN)%', $transports[$name]['dsn'], $name);
            self::assertSame($stream, $transports[$name]['options']['stream'], $name);
            $streams[] = $transports[$name]['options']['stream'];
        }

        self::assertCount(3, array_unique($streams), 'Each priority needs its own stream.');
    }

    public function testEnvTemplateDeclaresRedisSettingsWithoutCredentials(): void
    {
        $env = file_get_contents(__DIR__ . '/../../.env');

        self::assertMatchesRegularExpression('#^REDIS_URL=redis://localhost:\d+$#m', $env);
        self::assertMatchesRegularExpression('#^MESSENGER_TRANSPORT_DSN=redis://localhost:\d+$#m', $env);
    }
}

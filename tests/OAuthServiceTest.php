<?php
declare(strict_types=1);
namespace Tests;

use App\Services\OAuthService;
use PHPUnit\Framework\TestCase;

final class OAuthServiceTest extends TestCase
{
    public function testPkcePairUsesS256AndValidVerifierLength(): void
    {
        $pair = OAuthService::createPkcePair();
        self::assertArrayHasKey('verifier', $pair);
        self::assertArrayHasKey('challenge', $pair);
        self::assertGreaterThanOrEqual(43, strlen($pair['verifier']));
        self::assertLessThanOrEqual(128, strlen($pair['verifier']));
        $expected = rtrim(strtr(base64_encode(hash('sha256', $pair['verifier'], true)), '+/', '-_'), '=');
        self::assertSame($expected, $pair['challenge']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $pair['challenge']);
    }

    public function testOnlyConfiguredSocialProviderNamesAreAccepted(): void
    {
        self::assertTrue(OAuthService::isAllowedProvider('vk'));
        self::assertTrue(OAuthService::isAllowedProvider('ok'));
        self::assertTrue(OAuthService::isAllowedProvider('yandex'));
        self::assertFalse(OAuthService::isAllowedProvider('facebook'));
        self::assertFalse(OAuthService::isAllowedProvider('VK'));
        self::assertFalse(OAuthService::isAllowedProvider(''));
    }
}
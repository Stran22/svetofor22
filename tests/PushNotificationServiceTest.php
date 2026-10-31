<?php
declare(strict_types=1);
namespace Tests;

use App\Services\PushNotificationService;
use PHPUnit\Framework\TestCase;

final class PushNotificationServiceTest extends TestCase
{
    public function testIdempotencyKeyIsStableAndUuidFormatted(): void
    {
        $first = PushNotificationService::idempotencyKey(17, 'restock:import:42:branch:2:product:900');
        $second = PushNotificationService::idempotencyKey(17, 'restock:import:42:branch:2:product:900');
        $different = PushNotificationService::idempotencyKey(18, 'restock:import:42:branch:2:product:900');

        self::assertSame($first, $second);
        self::assertNotSame($first, $different);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $first);
    }
}
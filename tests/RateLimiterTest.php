<?php
declare(strict_types=1);
namespace Tests;

use App\Services\RateLimiter;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase
{
    public function testRejectsInvalidWindowWithoutOpeningDatabaseConnection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RateLimiter::hit('test', 'identity', 1, 86401);
    }

    public function testRejectsEmptyScope(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RateLimiter::hit('', 'identity', 1, 60);
    }
}

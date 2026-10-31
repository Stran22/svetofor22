<?php
declare(strict_types=1);
namespace Tests;

use App\Security\AdminGuard;
use PHPUnit\Framework\TestCase;

final class AdminGuardTest extends TestCase
{
    public function testCsrfTokenMatchesOnlyExactNonEmptyValue(): void
    {
        self::assertTrue(AdminGuard::matchesCsrfToken('abc123', 'abc123'));
        self::assertFalse(AdminGuard::matchesCsrfToken('abc123', 'ABC123'));
        self::assertFalse(AdminGuard::matchesCsrfToken('abc123', 'wrong'));
        self::assertFalse(AdminGuard::matchesCsrfToken('', ''));
        self::assertFalse(AdminGuard::matchesCsrfToken(null, 'abc123'));
    }
}
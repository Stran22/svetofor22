<?php
declare(strict_types=1);
namespace Tests;

use App\Services\ImportHeaderMapper;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ImportHeaderMapperTest extends TestCase
{
    public function testMapsRussianStoreHeaders(): void
    {
        $map = ImportHeaderMapper::map(['Штрихкод', 'Артикул', 'Наименование товара', 'Розничная цена', 'Остаток на складе', 'Ед. изм.']);
        self::assertSame([
            'barcode' => 0,
            'sku' => 1,
            'name' => 2,
            'price' => 3,
            'quantity' => 4,
            'unit' => 5,
        ], $map);

        $map = ImportHeaderMapper::map(['Штрихкод', 'Артикул', 'Наименование', 'Цена', 'Остаток', 'Ед.']);
        self::assertSame([
            'barcode' => 0,
            'sku' => 1,
            'name' => 2,
            'price' => 3,
            'quantity' => 4,
            'unit' => 5,
        ], $map);
    }

    public function testMapsBarcodeWithoutSkuColumn(): void
    {
        self::assertSame(
            ['barcode' => 0, 'name' => 1, 'price' => 2, 'quantity' => 3],
            ImportHeaderMapper::map(['EAN-13', 'Название', 'Стоимость', 'Количество'])
        );
    }

    public function testParsesRussianDecimalFormatting(): void
    {
        self::assertSame('1234.56', ImportHeaderMapper::parseDecimal('1 234,56 ₽', 2));
        self::assertSame('2.125', ImportHeaderMapper::parseDecimal('2,125', 3));
        self::assertSame('0.00', ImportHeaderMapper::parseDecimal(0, 2));
    }

    public function testRejectsNegativeAndOverPreciseNumbers(): void
    {
        $this->expectException(RuntimeException::class);
        ImportHeaderMapper::parseDecimal('-1,00', 2);
    }
}
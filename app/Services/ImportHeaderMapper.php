<?php
declare(strict_types=1);
namespace App\Services;

use RuntimeException;

final class ImportHeaderMapper
{
    private const ALIASES = [
        'barcode' => ['штрихкод', 'штрихкодтовара', 'barcode', 'ean', 'ean13', 'gtin'],
        'sku' => ['артикул', 'sku', 'кодтовара', 'кодпозиции', 'кодноменклатуры'],
        'name' => ['наименование', 'наименованиетовара', 'наименованиепродукции', 'название', 'товар', 'product', 'name'],
        'price' => ['цена', 'розничнаяцена', 'ценарозничная', 'ценазаед', 'ценаединицы', 'стоимость', 'price'],
        'quantity' => ['остаток', 'остатокнаскладе', 'количество', 'количествоостаток', 'наличие', 'quantity', 'qty'],
        'unit' => ['ед', 'едизм', 'единица', 'единицаизмерения', 'unit'],
    ];

    public static function normalizeHeader(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = str_replace('ё', 'е', $value);
        return preg_replace('/[^a-zа-я0-9]+/u', '', $value) ?? '';
    }

    public static function map(array $headers): ?array
    {
        $map = [];
        foreach ($headers as $index => $header) {
            $normalized = self::normalizeHeader((string)$header);
            if ($normalized === '') continue;
            foreach (self::ALIASES as $field => $aliases) {
                if (!isset($map[$field]) && in_array($normalized, $aliases, true)) {
                    $map[$field] = (int)$index;
                }
            }
        }
        if (!isset($map['name'], $map['price'], $map['quantity'])) return null;
        if (!isset($map['barcode']) && !isset($map['sku'])) return null;
        return $map;
    }

    public static function parseDecimal(mixed $value, int $scale): string
    {
        if (!in_array($scale, [2, 3], true)) throw new RuntimeException('Неподдерживаемая точность числа.');
        if (is_int($value) || is_float($value)) {
            if (!is_finite((float)$value) || (float)$value < 0) throw new RuntimeException('Число должно быть неотрицательным.');
            $normalized = number_format((float)$value, $scale, '.', '');
        } else {
            $raw = trim((string)$value);
            $raw = str_replace(["\xc2\xa0", "\xe2\x80\xaf", ' '], '', $raw);
            $raw = str_ireplace(['рублей', 'руб.', 'руб', '₽'], '', $raw);
            $raw = preg_replace('/[^0-9,.\-]/u', '', $raw) ?? '';
            if (str_contains($raw, ',') && str_contains($raw, '.')) {
                if (strrpos($raw, ',') > strrpos($raw, '.')) {
                    $raw = str_replace('.', '', $raw);
                    $raw = str_replace(',', '.', $raw);
                } else {
                    $raw = str_replace(',', '', $raw);
                }
            } else {
                $raw = str_replace(',', '.', $raw);
            }
            if (!preg_match('/^\d+(?:\.\d{1,' . $scale . '})?$/', $raw)) {
                throw new RuntimeException('Цена или количество имеют неверный формат.');
            }
            $normalized = number_format((float)$raw, $scale, '.', '');
        }

        $maxIntegerDigits = $scale === 2 ? 10 : 9;
        $integerPart = strstr($normalized, '.', true);
        if ($integerPart === false || strlen($integerPart) > $maxIntegerDigits) {
            throw new RuntimeException('Число превышает допустимый размер поля.');
        }
        return $normalized;
    }
}
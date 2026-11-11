<?php
declare(strict_types=1);

namespace Svet22\Services;

final class ImportRowValidator
{
    private const HEADERS = [
        'Штрихкод' => 'barcode',
        'Артикул' => 'article',
        'Название' => 'name',
        'Цена' => 'price',
        'Остаток' => 'quantity',
        'Единица измерения' => 'unit',
        'Категория' => 'category',
        'Производитель' => 'manufacturer',
    ];

    public function normalizeHeaders(array $headerRow): array
    {
        $map = [];
        foreach ($headerRow as $index => $value) {
            $header = trim((string) $value);
            if (isset(self::HEADERS[$header])) {
                $key = self::HEADERS[$header];
                if (isset($map[$key])) {
                    throw new \InvalidArgumentException("Повторяется колонка «{$header}».");
                }
                $map[$key] = (int) $index;
            }
        }

        foreach (['name', 'price', 'quantity'] as $required) {
            if (!isset($map[$required])) {
                throw new \InvalidArgumentException('Не найдена обязательная колонка: ' . $this->label($required) . '.');
            }
        }
        return $map;
    }

    public function validate(array $cells, array $columns): array
    {
        $row = [];
        foreach ($columns as $key => $index) {
            $value = $cells[$index] ?? null;
            $row[$key] = is_scalar($value) ? trim((string) $value) : '';
        }

        foreach (['barcode', 'article', 'unit', 'category', 'manufacturer'] as $optional) {
            $row[$optional] = ($row[$optional] ?? '') !== '' ? $row[$optional] : null;
        }

        if (($row['barcode'] ?? null) === null && ($row['article'] ?? null) === null) {
            throw new \InvalidArgumentException('Нужен хотя бы один идентификатор: штрихкод или артикул.');
        }
        if (($row['name'] ?? '') === '') {
            throw new \InvalidArgumentException('Не заполнено название товара.');
        }
        if (mb_strlen($row['name']) > 500) {
            throw new \InvalidArgumentException('Название товара длиннее 500 символов.');
        }

        $row['price'] = $this->decimal($row['price'] ?? '', 2, 10, 'Цена');
        $row['quantity'] = $this->decimal($row['quantity'] ?? '', 3, 9, 'Остаток');

        foreach (['barcode' => 64, 'article' => 100, 'unit' => 32, 'category' => 190, 'manufacturer' => 190] as $key => $max) {
            if ($row[$key] !== null && mb_strlen($row[$key]) > $max) {
                throw new \InvalidArgumentException($this->label($key) . " длиннее {$max} символов.");
            }
        }
        return $row;
    }

    private function decimal(string $value, int $scale, int $maxIntegerDigits, string $label): string
    {
        $value = str_replace([" ", "\u{00A0}"], '', trim($value));
        $value = str_replace(',', '.', $value);
        if ($value === '' || !preg_match('/^\d+(?:\.\d+)?$/', $value)) {
            throw new \InvalidArgumentException($label . ' должно быть неотрицательным числом.');
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = ltrim($whole, '0') ?: '0';
        if (strlen($whole) > $maxIntegerDigits || strlen($fraction) > $scale) {
            throw new \InvalidArgumentException($label . ' имеет слишком много знаков.');
        }
        return $whole . ($scale > 0 ? '.' . str_pad($fraction, $scale, '0') : '');
    }

    private function label(string $key): string
    {
        return [
            'name' => 'Название', 'price' => 'Цена', 'quantity' => 'Остаток',
            'barcode' => 'Штрихкод', 'article' => 'Артикул', 'unit' => 'Единица измерения',
            'category' => 'Категория', 'manufacturer' => 'Производитель',
        ][$key] ?? $key;
    }
}

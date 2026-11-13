<?php
declare(strict_types=1);

namespace Svet22\Services;

final class ImportRowValidator
{
    private const HEADERS = [
        'Штрихкод' => 'barcode',
        'Артикул' => 'article',
        'Название' => 'name',
        'Наименование' => 'name',
        'Цена' => 'price',
        'Цена продажи' => 'price',
        'Остаток' => 'quantity',
        'Количество' => 'quantity',
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

    public function validateMapping(array $mapping, int $columnCount): array
    {
        $allowed = ['barcode', 'article', 'name', 'price', 'quantity', 'unit', 'category', 'manufacturer'];
        $required = ['name', 'price', 'quantity'];

        foreach ($required as $field) {
            if (!array_key_exists($field, $mapping) || $mapping[$field] === '' || $mapping[$field] === null) {
                throw new \InvalidArgumentException(
                    'Не выбрана колонка для поля: ' . $this->label($field) . '.'
                );
            }
        }

        if ((!array_key_exists('barcode', $mapping) || $mapping['barcode'] === '' || $mapping['barcode'] === null)
            && (!array_key_exists('article', $mapping) || $mapping['article'] === '' || $mapping['article'] === null)) {
            throw new \InvalidArgumentException('Выберите хотя бы один идентификатор: штрихкод или артикул.');
        }

        $result = [];
        $used = [];

        foreach ($allowed as $field) {
            if (!array_key_exists($field, $mapping) || $mapping[$field] === '' || $mapping[$field] === null) {
                continue;
            }

            $index = filter_var($mapping[$field], FILTER_VALIDATE_INT);
            if ($index === false || $index < 0 || $index >= $columnCount) {
                throw new \InvalidArgumentException(
                    'Некорректный номер колонки для поля: ' . $this->label($field) . '.'
                );
            }

            if (isset($used[$index])) {
                throw new \InvalidArgumentException(
                    'Одна колонка Excel не может соответствовать нескольким полям.'
                );
            }

            $used[$index] = true;
            $result[$field] = $index;
        }

        return $result;
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

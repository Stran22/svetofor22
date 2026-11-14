<?php
declare(strict_types=1);

require dirname(__DIR__) . '/backend/app/Services/ImportRowValidator.php';

use Svet22\Services\ImportRowValidator;

$validator = new ImportRowValidator();

function expectFailure(callable $operation, string $description): void
{
    try {
        $operation();
    } catch (InvalidArgumentException) {
        return;
    }
    throw new RuntimeException('Expected validation failure: ' . $description);
}

$mapping = $validator->normalizeHeaders(['Артикул', 'Название', 'Цена', 'Остаток']);
if ($mapping !== ['article' => 0, 'name' => 1, 'price' => 2, 'quantity' => 3]) {
    throw new RuntimeException('Column recognition mismatch');
}

$row = $validator->validate(['A-100', 'Молоко', '123,45', '7'], $mapping);
if ($row['price'] !== '123.45' || $row['quantity'] !== '7.000') {
    throw new RuntimeException('Price or stock normalization mismatch');
}

$weight = $validator->validate(['A-101', 'Яблоки', '95', '2,5'], $mapping);
if ($weight['quantity'] !== '2.500') {
    throw new RuntimeException('Fractional stock normalization mismatch');
}

expectFailure(
    fn() => $validator->normalizeHeaders(['Название', 'Цена', 'Остаток']),
    'missing article and barcode'
);
expectFailure(
    fn() => $validator->validate(['A-102', 'Товар', '100', '-1'], $mapping),
    'negative stock'
);
expectFailure(
    fn() => $validator->validate(['A-103', 'Товар', '-10', '1'], $mapping),
    'negative price'
);
expectFailure(
    fn() => $validator->validateMapping(
        ['article' => 0, 'name' => 1, 'price' => 1, 'quantity' => 3],
        4
    ),
    'same Excel column mapped to two fields'
);
expectFailure(
    fn() => $validator->validate(['', 'Товар', '10', '1'], $mapping),
    'missing identifier in row'
);

echo "Excel validation tests passed\n";

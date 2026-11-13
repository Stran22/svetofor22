<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Svet22\Services\ImportRowValidator;

$validator = new ImportRowValidator();
$passed = 0;

function expect(bool $condition, string $message): void
{
    global $passed;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    $passed++;
}

function expectInvalid(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (InvalidArgumentException) {
        expect(true, $message);
        return;
    }
    expect(false, $message);
}

$headers = $validator->normalizeHeaders(['Штрихкод', 'Наименование', 'Цена продажи', 'Количество']);
expect($headers === ['barcode' => 0, 'name' => 1, 'price' => 2, 'quantity' => 3], 'header aliases are normalized');

$barcodeMapping = $validator->validateMapping([
    'barcode' => '0', 'name' => '1', 'price' => '2', 'quantity' => '3',
    'article' => '', 'unit' => '', 'category' => '', 'manufacturer' => '',
], 4);
expect($barcodeMapping === ['barcode' => 0, 'name' => 1, 'price' => 2, 'quantity' => 3], 'barcode mapping is accepted');

$articleMapping = $validator->validateMapping([
    'article' => '0', 'name' => '1', 'price' => '2', 'quantity' => '3',
], 4);
expect($articleMapping === ['article' => 0, 'name' => 1, 'price' => 2, 'quantity' => 3], 'article can replace barcode');

expectInvalid(
    fn() => $validator->validateMapping(['name' => 0, 'price' => 1, 'quantity' => 2], 3),
    'mapping requires barcode or article'
);
expectInvalid(
    fn() => $validator->validateMapping(['barcode' => 0, 'name' => 0, 'price' => 1, 'quantity' => 2], 3),
    'one Excel column cannot be reused'
);
expectInvalid(
    fn() => $validator->validateMapping(['barcode' => 0, 'name' => 1, 'price' => 2, 'quantity' => 4], 4),
    'column index must be inside file'
);

$row = $validator->validate(
    ['4601234567890', 'Кефир', '89,90', '12'],
    ['barcode' => 0, 'name' => 1, 'price' => 2, 'quantity' => 3]
);
expect($row['price'] === '89.90' && $row['quantity'] === '12.000', 'price and quantity are normalized');

echo "OK: {$passed} assertions\n";

<?php

function parseInventoryCsv(string $path): array
{
    if (!is_readable($path)) {
        throw new InvalidArgumentException('The inventory CSV could not be read.');
    }

    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new InvalidArgumentException('The inventory CSV could not be opened.');
    }

    try {
        $header = fgetcsv($handle, 0, ',', '"', '\\');
        if (!is_array($header)) {
            throw new InvalidArgumentException('The inventory CSV is empty.');
        }
        $header = array_map(function ($value): string {
            $value = trim((string)$value);
            return strtolower(ltrim($value, "\xEF\xBB\xBF"));
        }, $header);
        $columns = array_flip($header);
        foreach (['name', 'price', 'description'] as $required) {
            if (!array_key_exists($required, $columns)) {
                throw new InvalidArgumentException('The CSV must contain name, price, and description columns.');
            }
        }

        $zeroPriceSkus = ['SOA-2010', 'SOA-2011', 'SOA-2020', 'SOA-4530'];
        $excludedNames = ['bad debt', 'nonstock', 'pack and ship', 'services'];
        $itemsBySku = [];
        $excludedCount = 0;
        $blankPriceCount = 0;
        $lineNumber = 1;

        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $lineNumber++;
            if ($row === [null] || count(array_filter($row, function ($value): bool {
                return trim((string)$value) !== '';
            })) === 0) {
                continue;
            }

            $name = trim((string)($row[$columns['name']] ?? ''));
            $description = trim((string)($row[$columns['description']] ?? ''));
            $priceValue = trim((string)($row[$columns['price']] ?? ''));
            if ($name === '') {
                throw new InvalidArgumentException("Line $lineNumber has no SKU/name.");
            }
            if (in_array(strtolower($name), $excludedNames, true)) {
                $excludedCount++;
                continue;
            }
            if ($priceValue === '') {
                if (!in_array(strtoupper($name), $zeroPriceSkus, true)) {
                    $blankPriceCount++;
                    continue;
                }
                $price = 0.0;
            } else {
                $normalizedPrice = str_replace(['$', ','], '', $priceValue);
                if (!is_numeric($normalizedPrice) || (float)$normalizedPrice < 0) {
                    throw new InvalidArgumentException("Line $lineNumber has an invalid price for $name.");
                }
                $price = (float)$normalizedPrice;
            }

            $skuKey = strtoupper($name);
            if (isset($itemsBySku[$skuKey])) {
                throw new InvalidArgumentException("The CSV contains duplicate SKU/name $name.");
            }
            $itemsBySku[$skuKey] = [
                'name' => $name,
                'description' => $description,
                'price' => $price,
            ];
        }
    } finally {
        fclose($handle);
    }

    $items = array_values($itemsBySku);
    if (!$items) {
        throw new InvalidArgumentException('The CSV contains no importable inventory rows.');
    }
    usort($items, function (array $left, array $right): int {
        return strcasecmp($left['name'], $right['name']);
    });

    return [
        'items' => $items,
        'imported_count' => count($items),
        'excluded_count' => $excludedCount,
        'blank_price_count' => $blankPriceCount,
    ];
}

function writeInventoryJson(string $path, array $items): void
{
    $json = json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('The inventory data could not be encoded.');
    }

    $temporaryPath = $path . '.tmp';
    if (file_put_contents($temporaryPath, $json . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('The inventory data directory is not writable.');
    }
    if (is_file($path) && !@unlink($path)) {
        @unlink($temporaryPath);
        throw new RuntimeException('The existing inventory file could not be replaced.');
    }
    if (!@rename($temporaryPath, $path)) {
        @unlink($temporaryPath);
        throw new RuntimeException('The inventory file could not be finalized.');
    }
}

function snapshotCurrentInventory(PDO $db): array
{
    $statement = $db->query('SELECT name, description, price, deal, deal_price FROM products ORDER BY name');
    return array_map(function (array $row): array {
        return [
            'name' => (string)$row['name'],
            'description' => (string)($row['description'] ?? ''),
            'price' => (float)$row['price'],
            'deal' => (int)($row['deal'] ?? 0),
            'deal_price' => $row['deal_price'] === null ? null : (float)$row['deal_price'],
        ];
    }, $statement->fetchAll(PDO::FETCH_ASSOC));
}

function readInventoryJson(string $path): array
{
    if (!is_readable($path)) {
        throw new RuntimeException('No previous inventory is available to restore.');
    }
    $items = json_decode((string)file_get_contents($path), true);
    if (!is_array($items) || !$items) {
        throw new RuntimeException('The previous inventory backup is invalid.');
    }
    return $items;
}
# BinaryStorage

![License](https://img.shields.io/badge/License-MIT-yellow.svg)
![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4?logo=php&logoColor=white)

> A high-performance binary storage system for PHP, reducing disk overhead and I/O operations

## The Problem

Storing 50,000 products with prices + options in JSON files creates 100,000+ files, causing:
- Massive disk overhead (filesystem metadata)
- Slow read operations (file open/close overhead)
- Difficult maintenance

## The Solution

BinaryStorage uses an index-based approach (id => offset|length) to store all data in a single binary file.

**Benchmarks:** 20x faster than JSON files on 50,000 read operations.

## Installation

composer require olivier-ls/binary-storage

## Quick Start

```php
require '../src/BinaryStorage.php';

use OlivierLS\BinaryStorage\BinaryStorage;
use OlivierLS\BinaryStorage\TrieNode;

$store = new BinaryStorage(__DIR__ . '/data');

// Open a cache
$store->open('products')
    ->set('products', 'product_123', [
        'name' => 'MacBook Pro',
        'price' => 2499.99,
        'stock' => 42
    ])
    ->set('products', 'product_456', ['name' => 'iPhone'])
    ->set('products', 'product_789', ['name' => 'iPad']);

$store->set('products', 'product_abc', ['id' => 1024]);
$store->set('products', 'product_bcd', ['id' => 1025]);

// Save the index
$store->saveIndex('products');

// Retrieve data
$productData = $store->get('products', 'product_123');
echo '<pre>';
print_r($productData);
echo '</pre>';

// Prefix search (ultra-fast with the Trie)
$allProducts = $store->startsWith('products', 'product_');
echo "Found " . count($allProducts) . " products<br>";

// Search keys containing a substring
$allProducts = $store->contains('products', ['bc']);
echo "Found " . count($allProducts) . " products<br>";

// Compact to save disk space
$stats = $store->compact('products');
echo "Saved {$stats['saved_percent']}% disk space<br>";

echo '<pre>';
print_r($store->stats('products'));
echo '</pre>';

// Close the cache
$store->close('products');

// Delete the cache
$store->deleteCache('products');
```

## Features

- Extremely fast reads/writes
- Low disk overhead
- Search capabilities
- Data compaction
- Designed for high-volume key/value data

## Use Cases

- E-commerce price caching
- Session storage
- Large product catalogs
- High-volume read-heavy workloads
- Any system needing fast random-access lookup

## Contributing

Issues and PRs welcome!

## License

MIT 

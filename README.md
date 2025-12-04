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
$store = new BinaryStorage(__DIR__ . '/data');

// Open a storage file
$store->open('products');

// Write data
$store->set('products', 'product_456', [
    'name' => 'iPhone'
]);

// Read data
$product = $store->get('products', 'product_123');

// Close storage
$store->close('products');

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

# BinaryStorage

![License](https://img.shields.io/badge/License-MIT-yellow.svg)
![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4?logo=php&logoColor=white)

BinaryStorage is a lightweight binary key/value store for PHP.
It keeps all values inside a single data file and all keys inside a binary index, reducing filesystem overhead and allowing fast random-access lookups.

It is designed for projects that need simple, fast persistent storage without running Redis, SQLite, or a full database.

**BinaryStorage is a fast, lightweight, zero-dependency binary key/value store for PHP (one data file, one index file).**

## ✔️ Why use BinaryStorage?

BinaryStorage may be useful if you want:

- A simple file-based storage (no server to run, no extensions)
- Fast lookup by key
- Efficient prefix search (startsWith) and substring search (contains)
- Low disk usage (one data file + one index file)
- Automatic cleanup via file compaction
- The ability to store any PHP value (array, scalar, object → via serialize)

It is not a replacement for a database, but fits well for caches, catalogs, and read-heavy workloads.

## 📦 Installation

composer require olivier-ls/binary-storage

## 🚀 Basic Usage

```php
use OlivierLS\BinaryStorage\BinaryStorage;

$store = new BinaryStorage(__DIR__ . '/data');

// Open (or create) a storage
$store->open('products');

// Write values
$store->set('products', 'product_123', [
    'name'  => 'MacBook Pro',
    'price' => 2499.99,
    'stock' => 42
]);

$store->set('products', 'product_456', ['name' => 'iPhone']);
$store->set('products', 'product_789', ['name' => 'iPad']);

// Save index
$store->saveIndex('products');

// Read a value
$product = $store->get('products', 'product_123');

// Prefix search
$list = $store->startsWith('products', 'product_');

// Substring search
$list = $store->contains('products', ['pad']);

// Compact the data file (optional)
$stats = $store->compact('products');

// Stats
print_r($store->stats('products'));

// Close
$store->close('products');
```

## 📘 API Overview

| Method | Description | Example |
|--------|-------------|---------|
| **open(string $store)** | Opens (or creates) a storage file. Must be called before reading/writing. | `$store->open('products');` |
| **close(string $store)** | Closes a storage file. | `$store->close('products');` |
| **closeAll()** | Closes all opened storage files. | `$store->closeAll();` |
| **set(string $store, string $key, mixed $value)** | Stores a value for a key (serialized automatically). | `$store->set('products', 'p123', $data);` |
| **saveIndex(string $store)** | Persists the in-memory index to disk (important after many writes). | `$store->saveIndex('products');` |
| **get(string $store, string $key)** | Retrieves a value by key. Returns `null` if the key doesn't exist. | `$product = $store->get('products', 'p123');` |
| **getAllKeys(string $store)** | Returns all keys. | `$keys = $store->getAllKeys('products');` |
| **delete(string $store, string $key)** | Deletes a value by key. | `$store->delete('products', 'p123');` |
| **exists(string $store, string $key)** | Checks whether a key exists. | `if ($store->exists('products', 'p123')) { ... }` |
| **startsWith(string $store, string $prefix)** | Returns all values where the key starts with a prefix. | `$list = $store->startsWith('products', 'p1');` |
| **contains(string $store, array|string $patterns)** | Returns all values where the key contains one or more substrings. | `$list = $store->contains('products', ['12']);` |
| **stats(string $store)** | Returns file statistics (sizes, fragmentation, counts). | `$info = $store->stats('products');` |
| **compact(string $store)** | Rebuilds the file and removes fragmentation. | `$saved = $store->compact('products');` |
| **deleteCache(string $store)** | Deletes both the data and index files for a store. | `$store->deleteCache('products');` |

## 🧰 When to use it

Good scenarios:
- Large product lists or catalog caching
- Simple local persistent storage
- High-volume read access
- CLI tools needing a lightweight storage
- Storing precomputed data
- Replacing many small JSON files

Less good scenarios:
- Heavy concurrent writes
- Complex queries
- Replacing a real database

## 🤝 Contributing

Issues and pull requests are welcome.
If you find a performance bottleneck or have an idea for improved index structures, feel free to open a discussion.

## 📄 License

MIT 

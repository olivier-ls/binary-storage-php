<?php

require '../src/BinaryStorage.php';

use OlivierLS\BinaryStorage\BinaryStorage;

// Initialize store manager
$store = new BinaryStorage(__DIR__ . '/data');

// Open a store and add entries
$store->open('products')
    ->set('products', 'product_123', [
        'name' => 'MacBook Pro',
        'price' => 2499.99,
        'stock' => 42
    ])
    ->set('products', 'product_456', ['name' => 'iPhone'])
    ->set('products', 'product_789', ['name' => 'iPad']);

// Set more entries individually
$store->set('products', 'product_abc', ['id' => 1024]);
$store->set('products', 'product_bcd', ['id' => 1025]);

// Set a single entry with a TTL of 3600 seconds (1 hour)
$store->set('products', 'product_klm', ['id' => 1026], 3600);

// Set multiple entries at once
$store->setMultiple('products', [
    'product_efg' => 'test...123',
    'product_hij' => [
        'ref' => 'ref123',
        'name' => 'Black t-shirt',
        'option' => [
            'color' => 'black',
            'size' => 'xl'
        ]
    ]
]);

// Save index to disk
$store->saveIndex('products');

// Retrieve entries
$productData = $store->get('products', 'product_123');
echo '<pre>';
print_r($productData);
echo '</pre>';

$productData = $store->get('products', 'product_hij');
echo '<pre>';
print_r($productData);
echo '</pre>';

// Search by prefix
$allProducts = $store->startsWith('products', 'product_');
echo "Found " . count($allProducts) . " products<br>";

// Search keys containing substring
$allProducts = $store->contains('products', ['bc']);
echo "Found " . count($allProducts) . " products<br>";

// Compact the store to reclaim space
$stats = $store->compact('products');
echo "Saved {$stats['saved_percent']}% disk space<br>";

// Show store statistics
echo '<pre>';
print_r($store->stats('products'));
echo '</pre>';

// Close the store
$store->close('products');

// Delete the entire store
//$store->deleteCache('products');

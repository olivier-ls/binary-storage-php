<?php

require '../src/BinaryStorage.php';

use OlivierLS\BinaryStorage\BinaryStorage;
use OlivierLS\BinaryStorage\TrieNode;

$store = new BinaryStorage(__DIR__ . '/data');

// Ouvrir un cache
$store->open('products');

// Stocker des données
$store->set('products', 'product_123', [
    'name' => 'MacBook Pro',
    'price' => 2499.99,
    'stock' => 42
]);

// Récupérer
$product = $store->get('products', 'product_123');
var_dump($product);

// Recherche par préfixe (ultra-rapide avec le Trie)
$store->set('products', 'product_456', ['name' => 'iPhone']);
$store->set('products', 'product_789', ['name' => 'iPad']);

$allProducts = $store->startsWith('products', 'product_');
echo "Found " . count($allProducts) . " products\n";

// Compacter pour récupérer l'espace
$stats = $store->compact('products');
echo "Saved {$stats['saved_percent']}% disk space\n";

echo '<pre>';
print_r($store->stats('products'));
echo '</pre>';

$store->close('products');

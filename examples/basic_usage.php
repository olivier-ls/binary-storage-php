<?php

require '../src/BinaryStorage.php';

use OlivierLS\BinaryStorage\BinaryStorage;
use OlivierLS\BinaryStorage\TrieNode;

$store = new BinaryStorage(__DIR__ . '/data');

// Ouvrir un cache
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

// Sauvegarde
$store->saveIndex('products');

// Récupérer
$productData = $store->get('products', 'product_123');
echo '<pre>';
print_r($productData);
echo '</pre>';

$productData = $store->get('products', 'product_hij');
echo '<pre>';
print_r($productData);
echo '</pre>';

// Recherche par préfixe (ultra-rapide avec le Trie)
$allProducts = $store->startsWith('products', 'product_');
echo "Found " . count($allProducts) . " products<br>";

// Recherche dans les clés avec contains
$allProducts = $store->contains('products', ['bc']);
echo "Found " . count($allProducts) . " products<br>";

// Compacter pour récupérer l'espace
$stats = $store->compact('products');
echo "Saved {$stats['saved_percent']}% disk space<br>";

echo '<pre>';
print_r($store->stats('products'));
echo '</pre>';

$store->close('products');

// Supprimer l'élément
$store->deleteCache('products');

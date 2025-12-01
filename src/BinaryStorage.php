<?php

namespace OlivierLS\BinaryStorage;

/**
 * Nœud de l'arbre Trie pour l'indexation des clés
 */
class TrieNode {
    public array $children = [];
    public array $keys = [];  // Clés complètes qui passent par ce nœud
}

class BinaryStorage {

    private array $handles = [];  // ['name' => ['index' => [...], 'fh' => resource, 'trie' => TrieNode]]
    private string $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/');
    }

    public function open(string $name): void
    {
        $indexFile = "{$this->basePath}/{$name}.bin";
        $dataFile  = "{$this->basePath}/{$name}.dat";

        if (!is_dir($this->basePath)) {
            mkdir($this->basePath, 0777, true);
        }

        $index = [];

        // Lecture de l'index si existant
        if (file_exists($indexFile)) {
            $f = fopen($indexFile, 'rb');
            while (!feof($f)) {
                $keyLenRaw = fread($f, 4);
                if (strlen($keyLenRaw) < 4) break;
                $keyLen = unpack('N', $keyLenRaw)[1];
                $key = fread($f, $keyLen);
                $metaRaw = fread($f, 16);
                if (strlen($metaRaw) < 16) break;
                $meta = unpack('Joffset/Jlength', $metaRaw);
                $index[$key] = ['offset' => $meta['offset'], 'length' => $meta['length']];
            }
            fclose($f);
        }

        // Ouverture du fichier de données (append + lecture)
        $fh = fopen($dataFile, 'c+b');
        if (!$fh) {
            throw new RuntimeException("Impossible d'ouvrir $dataFile");
        }

        // Construction du Trie à partir de l'index existant
        $trie = $this->buildTrie($index);

        $this->handles[$name] = [
            'indexFile' => $indexFile,
            'dataFile' => $dataFile,
            'index' => $index,
            'fh' => $fh,
            'trie' => $trie,
        ];
    }

    /**
     * Construit un arbre Trie à partir d'un index de clés
     */
    private function buildTrie(array $index): TrieNode
    {
        $root = new TrieNode();
        foreach (array_keys($index) as $key) {
            $this->insertInTrie($root, $key);
        }
        return $root;
    }

    /**
     * Insère une clé dans l'arbre Trie
     */
    private function insertInTrie(TrieNode $node, string $key): void
    {
        $current = $node;
        $len = strlen($key);

        for ($i = 0; $i < $len; $i++) {
            $char = $key[$i];
            if (!isset($current->children[$char])) {
                $current->children[$char] = new TrieNode();
            }
            $current = $current->children[$char];
            // On stocke la clé à chaque niveau pour pouvoir la retrouver
            $current->keys[] = $key;
        }
    }

    /**
     * Supprime une clé de l'arbre Trie
     */
    private function removeFromTrie(TrieNode $node, string $key): void
    {
        $current = $node;
        $len = strlen($key);
        $path = [$current];

        // Descendre dans l'arbre en enregistrant le chemin
        for ($i = 0; $i < $len; $i++) {
            $char = $key[$i];
            if (!isset($current->children[$char])) {
                return; // La clé n'existe pas dans le Trie
            }
            $current = $current->children[$char];
            $path[] = $current;
        }

        // Retirer la clé de tous les nœuds du chemin
        foreach ($path as $pathNode) {
            $pathNode->keys = array_values(array_diff($pathNode->keys, [$key]));
        }
    }

    /**
     * Collecte toutes les clés sous un nœud donné (récursif)
     */
    private function collectAllKeys(TrieNode $node): array
    {
        // On utilise array_unique car les clés sont stockées à chaque niveau
        return array_values(array_unique($node->keys));
    }

    public function set(string $name, string $key, mixed $value): void
    {
        if (!isset($this->handles[$name])) {
            throw new RuntimeException("Le cache '$name' n'est pas ouvert");
        }

        $h = &$this->handles[$name];
        $data = serialize($value);
        $offset = fstat($h['fh'])['size'];
        $length = strlen($data);

        fseek($h['fh'], 0, SEEK_END);
        fwrite($h['fh'], $data);

        // Mettre à jour l'index
        $isNewKey = !isset($h['index'][$key]);
        $h['index'][$key] = ['offset' => $offset, 'length' => $length];

        // Mettre à jour le Trie seulement si c'est une nouvelle clé
        if ($isNewKey) {
            $this->insertInTrie($h['trie'], $key);
        }
    }

    public function get(string $name, string $key): mixed
    {
        if (!isset($this->handles[$name])) {
            throw new RuntimeException("Le cache '$name' n'est pas ouvert");
        }

        $h = &$this->handles[$name];
        if (!isset($h['index'][$key])) {
            return null;
        }

        $meta = $h['index'][$key];
        fseek($h['fh'], $meta['offset']);
        $data = fread($h['fh'], $meta['length']);
        return unserialize($data);
    }

    /**
     * Récupère plusieurs valeurs d'un coup (optimisé)
     */
    public function getMultiple(string $name, array $keys): array
    {
        if (!isset($this->handles[$name])) {
            throw new RuntimeException("Le cache '$name' n'est pas ouvert");
        }

        $results = [];
        foreach ($keys as $key) {
            $value = $this->get($name, $key);
            if ($value !== null) {
                $results[$key] = $value;
            }
        }
        return $results;
    }

    /**
     * Recherche toutes les clés qui commencent par un préfixe donné (optimisé avec Trie)
     */
    public function startsWith(string $name, string $prefix): array
    {
        if (!isset($this->handles[$name])) {
            throw new RuntimeException("Le cache '$name' n'est pas ouvert");
        }

        $node = $this->handles[$name]['trie'];

        // Descendre jusqu'au préfixe dans le Trie
        for ($i = 0; $i < strlen($prefix); $i++) {
            $char = $prefix[$i];
            if (!isset($node->children[$char])) {
                return []; // Préfixe n'existe pas
            }
            $node = $node->children[$char];
        }

        // Récupérer toutes les clés sous ce nœud
        return $this->collectAllKeys($node);
    }

    /**
     * Recherche toutes les clés qui contiennent TOUS les patterns donnés
     * Note: Cette méthode reste en O(n) car elle nécessite de vérifier chaque clé
     */
    public function contains(string $name, array $patterns): array
    {
        if (!isset($this->handles[$name])) {
            throw new RuntimeException("Le cache '$name' n'est pas ouvert");
        }

        if (empty($patterns)) {
            return [];
        }

        $matchingKeys = [];
        foreach ($this->handles[$name]['index'] as $key => $meta) {
            $matches = true;
            foreach ($patterns as $pattern) {
                if (!str_contains($key, $pattern)) {
                    $matches = false;
                    break;
                }
            }
            if ($matches) {
                $matchingKeys[] = $key;
            }
        }

        return $matchingKeys;
    }

    /**
     * Récupère toutes les clés du cache
     */
    public function getAllKeys(string $name): array
    {
        if (!isset($this->handles[$name])) {
            throw new RuntimeException("Le cache '$name' n'est pas ouvert");
        }

        return array_keys($this->handles[$name]['index']);
    }

    /**
     * Vérifie si une clé existe
     */
    public function exists(string $name, string $key): bool
    {
        if (!isset($this->handles[$name])) {
            throw new RuntimeException("Le cache '$name' n'est pas ouvert");
        }

        return isset($this->handles[$name]['index'][$key]);
    }

    /**
     * Compte le nombre de clés dans le cache
     */
    public function count(string $name): int
    {
        if (!isset($this->handles[$name])) {
            throw new RuntimeException("Le cache '$name' n'est pas ouvert");
        }

        return count($this->handles[$name]['index']);
    }

    public function saveIndex(string $name): void
    {
        if (!isset($this->handles[$name])) return;
        $h = $this->handles[$name];

        $f = fopen($h['indexFile'], 'wb');
        foreach ($h['index'] as $key => $meta) {
            $keyLen = strlen($key);
            fwrite($f, pack('N', $keyLen));
            fwrite($f, $key);
            fwrite($f, pack('J', $meta['offset']));
            fwrite($f, pack('J', $meta['length']));
        }
        fclose($f);
    }

    public function delete(string $name, string $key): bool
    {
        if (!isset($this->handles[$name])) {
            throw new RuntimeException("Le cache '$name' n'est pas ouvert");
        }

        $h = &$this->handles[$name];

        if (!isset($h['index'][$key])) {
            return false;
        }

        // Supprimer de l'index
        unset($h['index'][$key]);

        // Supprimer du Trie
        $this->removeFromTrie($h['trie'], $key);

        return true;
    }

    public function deleteCache(string $name): bool
    {
        if (!isset($this->handles[$name])) {

            $indexFile = "{$this->basePath}/{$name}.bin";
            $dataFile  = "{$this->basePath}/{$name}.dat";
            $deleted = true;

            if (file_exists($indexFile)) $deleted = $deleted && unlink($indexFile);
            if (file_exists($dataFile)) $deleted = $deleted && unlink($dataFile);

            return $deleted;
        }

        $h = $this->handles[$name];
        fclose($h['fh']);
        unset($this->handles[$name]);

        $deleted = true;
        if (file_exists($h['indexFile'])) $deleted = $deleted && unlink($h['indexFile']);
        if (file_exists($h['dataFile']))  $deleted = $deleted && unlink($h['dataFile']);

        return $deleted;
    }

    /**
     * Compacte le fichier de données en supprimant les espaces inutilisés
     * Réécrit le fichier .dat sans les données des clés supprimées
     *
     * @param string $name Nom du cache à compacter
     * @return array Statistiques ['old_size' => ..., 'new_size' => ..., 'saved' => ...]
     */
    public function compact(string $name): array
    {
        if (!isset($this->handles[$name])) {
            throw new RuntimeException("Le cache '$name' n'est pas ouvert");
        }

        $h = &$this->handles[$name];

        // Mesurer la taille avant
        $oldSize = filesize($h['dataFile']);

        // Créer un fichier temporaire
        $tempFile = $h['dataFile'] . '.tmp';
        $tempFh = fopen($tempFile, 'wb');
        if (!$tempFh) {
            throw new RuntimeException("Impossible de créer le fichier temporaire");
        }

        // Nouveau tableau d'index
        $newIndex = [];
        $currentOffset = 0;

        // Copier uniquement les données encore référencées
        foreach ($h['index'] as $key => $meta) {
            // Lire les données depuis l'ancien fichier
            fseek($h['fh'], $meta['offset']);
            $data = fread($h['fh'], $meta['length']);

            // Écrire dans le nouveau fichier
            fwrite($tempFh, $data);

            // Mettre à jour l'index avec le nouvel offset
            $newIndex[$key] = [
                'offset' => $currentOffset,
                'length' => $meta['length']
            ];

            $currentOffset += $meta['length'];
        }

        // Fermer les fichiers
        fclose($tempFh);
        fclose($h['fh']);

        // Remplacer l'ancien fichier par le nouveau
        if (!rename($tempFile, $h['dataFile'])) {
            throw new RuntimeException("Impossible de remplacer le fichier de données");
        }

        // Rouvrir le fichier de données
        $h['fh'] = fopen($h['dataFile'], 'c+b');
        if (!$h['fh']) {
            throw new RuntimeException("Impossible de rouvrir le fichier de données");
        }

        // Mettre à jour l'index
        $h['index'] = $newIndex;

        // Reconstruire le Trie avec le nouvel index
        $h['trie'] = $this->buildTrie($newIndex);

        // Sauvegarder le nouvel index
        $this->saveIndex($name);

        // Mesurer la taille après
        $newSize = filesize($h['dataFile']);

        return [
            'old_size' => $oldSize,
            'new_size' => $newSize,
            'saved' => $oldSize - $newSize,
            'saved_percent' => round((($oldSize - $newSize) / $oldSize) * 100, 2)
        ];
    }

    public function close(string $name): void
    {
        if (!isset($this->handles[$name])) return;
        $this->saveIndex($name);
        fclose($this->handles[$name]['fh']);
        unset($this->handles[$name]);
    }

    public function closeAll(): void
    {
        foreach (array_keys($this->handles) as $name) {
            $this->close($name);
        }
    }
}
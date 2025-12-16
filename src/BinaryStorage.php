<?php

namespace OlivierLS\BinaryStorage;

class BinaryStorage
{

    private array $handles = [];  // ['name' => ['index' => [...], 'fh' => resource, 'trie' => TrieNode]]
    private string $basePath;

    /**
     * Initializes the store manager with a base path for data files.
     *
     * @param string $basePath Directory where store files (.dat and .bin) will be stored
     */
    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/');
    }

    /**
     * Destructor that ensures all open stores are properly closed.
     *
     * Calls {@see closeAll()} to save all in-memory indexes to disk and close
     * all file handles, preventing data loss.
     */
    public function __destruct()
    {
        $this->closeAll();
    }

    /* Opens a store and loads its index into memory.
    *
    * This method initializes the index and data files associated with the given
    * store name. If the index file exists, it is parsed and loaded into memory,
    * supporting both the current format (with TTL metadata) and legacy formats.
    * The data file is then opened for read/write operations.
    *
    * @param string $name Store identifier (used as file base name)
    * @return self
    * @throws RuntimeException If the data file cannot be opened
    */
    public function open(string $name): self
    {
        $indexFile = "{$this->basePath}/{$name}.bin";
        $dataFile  = "{$this->basePath}/{$name}.dat";

        if (!is_dir($this->basePath)) {
            mkdir($this->basePath, 0777, true);
        }

        $index = [];

        if (file_exists($indexFile)) {
            $f = fopen($indexFile, 'rb');

            // Read header if present
            $magic = fread($f, 8);
            if ($magic === 'BINSTORE') {
                $version = unpack('C', fread($f, 1))[1];
                $keyCount = unpack('N', fread($f, 4))[1];

                // v2 format with TTL support
                for ($i = 0; $i < $keyCount; $i++) {
                    $keyLen = unpack('N', fread($f, 4))[1];
                    $key = fread($f, $keyLen);
                    $metaRaw = unpack('Joffset/Jlength/Jcreated_at/Jexpires_at', fread($f, 32));

                    $index[$key] = [
                        'offset' => $metaRaw['offset'],
                        'length' => $metaRaw['length'],
                        'created_at' => $metaRaw['created_at'],
                        'expires_at' => $metaRaw['expires_at'] === 0 ? null : $metaRaw['expires_at']
                    ];
                }
            } else {
                // Backward compatibility (legacy index format)
                fseek($f, 0);
                while (!feof($f)) {
                    $keyLenRaw = fread($f, 4);
                    if (strlen($keyLenRaw) < 4) break;
                    $keyLen = unpack('N', $keyLenRaw)[1];
                    $key = fread($f, $keyLen);
                    $metaRaw = fread($f, 16);
                    if (strlen($metaRaw) < 16) break;
                    $meta = unpack('Joffset/Jlength', $metaRaw);

                    $index[$key] = [
                        'offset' => $meta['offset'],
                        'length' => $meta['length'],
                        'created_at' => null,
                        'expires_at' => null
                    ];
                }
            }
            fclose($f);
        }

        $fh = fopen($dataFile, 'c+b');
        if (!$fh) {
            throw new RuntimeException("Unable to open $dataFile");
        }

        $this->handles[$name] = [
            'indexFile' => $indexFile,
            'dataFile' => $dataFile,
            'index' => $index,
            'fh' => $fh,
        ];

        return $this;
    }

    /**
     * Stores a value in a store.
     *
     * The value is serialized and appended to the data file. Its metadata
     * (offset, length, creation time and optional expiration time) is then
     * stored in the in-memory index. The operation is protected by an exclusive
     * file lock to ensure consistency.
     *
     * @param string   $name  Store name
     * @param string   $key   Entry key
     * @param mixed    $value Value to store (will be serialized)
     * @param int|null $ttl   Time-to-live in seconds, or null for no expiration
     * @return self
     * @throws RuntimeException If the store is not open or the lock cannot be acquired
     */
    public function set(string $name, string $key, mixed $value, ?int $ttl = null): self
    {

        if (!isset($this->handles[$name])) {
            throw new RuntimeException("Store '$name' is not open");
        }

        $h = &$this->handles[$name];

        if (!flock($h['fh'], LOCK_EX)) {
            throw new RuntimeException("Unable to acquire file lock");
        }

        try {
            $data = serialize($value);

            // Seek
            fseek($h['fh'], 0, SEEK_END);
            $offset = ftell($h['fh']);
            $length = strlen($data);

            fwrite($h['fh'], $data);
            fflush($h['fh']);  // Force write

            // Store metadata with optional TTL
            $h['index'][$key] = [
                'offset' => $offset,
                'length' => $length,
                'created_at' => time(),
                'expires_at' => $ttl ? time() + $ttl : null
            ];
        } finally {
            flock($h['fh'], LOCK_UN);
        }

        return $this;
    }

    /**
     * Stores multiple values in a store.
     *
     * Each value is serialized and appended to the data file. For every entry,
     * its metadata (offset, length, creation time and optional expiration time)
     * is stored in the in-memory index. The entire operation is protected by a
     * single exclusive file lock to ensure consistency.
     *
     * @param string   $name  Store name
     * @param array    $items Key/value pairs to store
     * @param int|null $ttl   Time-to-live in seconds, or null for no expiration
     * @return self
     * @throws RuntimeException If the store is not open or the lock cannot be acquired
     */
    public function setMultiple(string $name, array $items, ?int $ttl = null): self
    {
        if (!isset($this->handles[$name])) {
            throw new RuntimeException("Store '$name' is not open");
        }

        $h = &$this->handles[$name];

        if (!flock($h['fh'], LOCK_EX)) {
            throw new RuntimeException("Unable to acquire file lock");
        }

        try {
            foreach ($items as $key => $value) {
                $data = serialize($value);

                // Move file pointer to the end of the data file
                fseek($h['fh'], 0, SEEK_END);
                $offset = ftell($h['fh']);
                $length = strlen($data);

                fwrite($h['fh'], $data);

                // Store metadata with optional TTL
                $h['index'][$key] = [
                    'offset' => $offset,
                    'length' => $length,
                    'created_at' => time(),
                    'expires_at' => $ttl ? time() + $ttl : null
                ];
            }
            fflush($h['fh']);
        } finally {
            flock($h['fh'], LOCK_UN);
        }

        return $this;
    }

    /**
     * Retrieves a value from a store.
     *
     * The method looks up the entry in the in-memory index, checks for expiration
     * if a TTL is defined, and lazily deletes the entry if it has expired.
     * If the entry is valid, its serialized value is read from the data file
     * and unserialized before being returned.
     *
     * @param string $name Store name
     * @param string $key  Entry key
     * @return mixed|null The stored value, or null if not found or expired
     * @throws RuntimeException If the store is not open
     */
    public function get(string $name, string $key): mixed
    {
        if (!isset($this->handles[$name])) {
            throw new RuntimeException("Store '$name' is not open");
        }

        $h = &$this->handles[$name];
        if (!isset($h['index'][$key])) {
            return null;
        }

        $meta = $h['index'][$key];

        // Check entry expiration
        if (isset($meta['expires_at']) && $meta['expires_at'] !== null && $meta['expires_at'] < time()) {
            $this->delete($name, $key);
            return null;
        }

        // Read serialized value from data file
        fseek($h['fh'], $meta['offset']);
        $data = fread($h['fh'], $meta['length']);
        return unserialize($data);
    }

    /**
     * Retrieves multiple values from a store.
     *
     * This method iterates over the given keys and delegates retrieval to
     * {@see get()}. Expired or missing entries are silently ignored.
     *
     * @param string $name Store name
     * @param array  $keys List of entry keys to retrieve
     * @return array Key/value pairs of successfully retrieved entries
     * @throws RuntimeException If the store is not open
     */
    public function getMultiple(string $name, array $keys): array
    {
        if (!isset($this->handles[$name])) {
            throw new RuntimeException("Store '$name' is not open");
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
     * Returns all keys starting with the given prefix.
     *
     * This method scans the in-memory index and filters keys using a
     * prefix comparison. No I/O is performed.
     *
     * @param string $name   Store name
     * @param string $prefix Key prefix to match
     * @return string[] List of matching keys
     * @throws RuntimeException If the store is not open
     */
    public function startsWith(string $name, string $prefix): array
    {
        if (!isset($this->handles[$name])) {
            throw new RuntimeException("Store '$name' is not open");
        }

        return array_filter(
            array_keys($this->handles[$name]['index']),
            fn($key) => str_starts_with($key, $prefix)
        );
    }

    /**
     * Returns all keys containing all given patterns.
     *
     * Each key in the in-memory index is checked to ensure it contains every
     * provided pattern. This method performs a full scan of the index and
     * therefore runs in O(n) time.
     *
     * @param string $name     Store name
     * @param array  $patterns List of substrings that must all be present in the key
     * @return string[] List of matching keys
     * @throws RuntimeException If the store is not open
     */
    public function contains(string $name, array $patterns): array
    {
        if (!isset($this->handles[$name])) {
            throw new RuntimeException("Store '$name' is not open");
        }

        if (empty($patterns)) {
            return [];
        }

        $matchingKeys = [];
        foreach ($this->handles[$name]['index'] as $key => $meta) {
            $matches = true;
            foreach ($patterns as $pattern) {

                // Ensure the key contains the current pattern
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
     * Returns all keys stored in a store.
     *
     * This method reads directly from the in-memory index and does not
     * perform any file I/O.
     *
     * @param string $name Store name
     * @return string[] List of all keys in the store
     * @throws RuntimeException If the store is not open
     */
    public function getAllKeys(string $name): array
    {
        if (!isset($this->handles[$name])) {
            throw new RuntimeException("Store '$name' is not open");
        }

        return array_keys($this->handles[$name]['index']);
    }

    /**
     * Checks if a key exists in a store.
     *
     * This method inspects the in-memory index and returns true if the key
     * is present. It does not check for expiration or perform any file I/O.
     *
     * @param string $name Store name
     * @param string $key  Entry key to check
     * @return bool True if the key exists, false otherwise
     * @throws RuntimeException If the store is not open
     */
    public function exists(string $name, string $key): bool
    {
        if (!isset($this->handles[$name])) {
            throw new RuntimeException("Store '$name' is not open");
        }

        return isset($this->handles[$name]['index'][$key]);
    }

    /**
     * Returns the number of keys in a store.
     *
     * This method counts the entries in the in-memory index. Expired keys
     * are not automatically removed, so the count may include them.
     *
     * @param string $name Store name
     * @return int Number of keys in the store
     * @throws RuntimeException If the store is not open
     */
    public function count(string $name): int
    {
        if (!isset($this->handles[$name])) {
            throw new RuntimeException("Store '$name' is not open");
        }

        return count($this->handles[$name]['index']);
    }

    /**
     * Persists the in-memory index to the index file.
     *
     * This method writes the index to disk in the v2 format:
     * - Header 'BINSTORE' + version byte
     * - Number of entries
     * - For each entry: key length, key, offset, length, created_at, expires_at
     *
     * @param string $name Store name
     * @return void
     */
    public function saveIndex(string $name): void
    {
        if (!isset($this->handles[$name])) return;
        $h = $this->handles[$name];

        $f = fopen($h['indexFile'], 'wb');

        // Write header (v2)
        fwrite($f, 'BINSTORE');
        fwrite($f, pack('C', 2));
        fwrite($f, pack('N', count($h['index'])));

        foreach ($h['index'] as $key => $meta) {
            $keyLen = strlen($key);
            fwrite($f, pack('N', $keyLen)); // Key length
            fwrite($f, $key);  // Key string
            fwrite($f, pack('J', $meta['offset'])); // Data offset
            fwrite($f, pack('J', $meta['length'])); // Data length
            fwrite($f, pack('J', $meta['created_at'] ?? time())); // Creation time
            fwrite($f, pack('J', $meta['expires_at'] ?? 0)); // Expiration time
        }

        fclose($f);
    }

    /**
     * Deletes a key from a store.
     *
     * This method removes the entry from the in-memory index only. The actual
     * data in the data file is not immediately removed, but the index will no
     * longer reference it. Call {@see saveIndex()} to persist the updated index.
     *
     * @param string $name Store name
     * @param string $key  Entry key to delete
     * @return bool True if the key existed and was deleted, false otherwise
     * @throws RuntimeException If the store is not open
     */
    public function delete(string $name, string $key): bool
    {
        if (!isset($this->handles[$name])) {
            throw new RuntimeException("Store '$name' is not open");
        }

        $h = &$this->handles[$name];

        if (!isset($h['index'][$key])) {
            return false;
        }

        unset($h['index'][$key]);

        return true;
    }

    /**
     * Deletes an entire store, including its data and index files.
     *
     * If the store is currently open, its file handle is closed and the in-memory
     * reference is removed. Both the index and data files are then deleted from disk.
     * If the store is not open, this method attempts to delete the files directly.
     *
     * @param string $name Store name
     * @return bool True if all existing files were successfully deleted, false otherwise
     */
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
     * Compacts the data file by removing gaps left by deleted entries.
     *
     * This method rewrites the .dat file, keeping only the data still referenced
     * in the in-memory index. It updates the offsets in the index accordingly
     * and persists the updated index to disk.
     *
     * @param string $name Store name
     * @return array Statistics about compaction:
     *               [
     *                 'old_size' => int,       // Original file size in bytes
     *                 'new_size' => int,       // New file size after compaction
     *                 'saved' => int,          // Bytes saved
     *                 'saved_percent' => float // Percentage of space reclaimed
     *               ]
     * @throws RuntimeException If the store is not open or file operations fail
     */
    public function compact(string $name): array
    {
        if (!isset($this->handles[$name])) {
            throw new RuntimeException("Store '$name' is not open");
        }

        $h = &$this->handles[$name];

        // Mesurer la taille avant
        $oldSize = filesize($h['dataFile']);

        // Créer un fichier temporaire
        $tempFile = $h['dataFile'] . '.tmp';
        $tempFh = fopen($tempFile, 'wb');
        if (!$tempFh) {
            throw new RuntimeException("Unable to create temporary file");
        }

        // New index with updated offsets
        $newIndex = [];
        $currentOffset = 0;

        // Copy only the referenced data
        foreach ($h['index'] as $key => $meta) {
            // Read data from the old file
            fseek($h['fh'], $meta['offset']);
            $data = fread($h['fh'], $meta['length']);

            // Write to the new temporary file
            fwrite($tempFh, $data);

            // Update the index with the new offset
            $newIndex[$key] = [
                'offset' => $currentOffset,
                'length' => $meta['length']
            ];

            $currentOffset += $meta['length'];
        }

        // Close files
        fclose($tempFh);
        fclose($h['fh']);

        // Replace old data file with new compacted file
        if (!rename($tempFile, $h['dataFile'])) {
            throw new RuntimeException("Unable to replace data file with compacted file");
        }

        // Reopen the data file
        $h['fh'] = fopen($h['dataFile'], 'c+b');
        if (!$h['fh']) {
            throw new RuntimeException("Unable to reopen data file");
        }

        // Update the in-memory index
        $h['index'] = $newIndex;

        // Persist the updated index
        $this->saveIndex($name);

        // Measure size after compaction
        $newSize = filesize($h['dataFile']);

        return [
            'old_size' => $oldSize,
            'new_size' => $newSize,
            'saved' => $oldSize - $newSize,
            'saved_percent' => round((($oldSize - $newSize) / $oldSize) * 100, 2)
        ];
    }

    /**
     * Removes expired entries from a store.
     *
     * This method scans the in-memory index and deletes entries whose TTL
     * has passed. The actual data in the data file is not immediately removed,
     * but the index will no longer reference expired entries. Call
     * {@see saveIndex()} to persist the updated index.
     *
     * @param string $name Store name
     * @return int Number of entries deleted
     * @throws RuntimeException If the store is not open
     */
    public function cleanup(string $name): int
    {
        if (!isset($this->handles[$name])) {
            throw new RuntimeException("Store '$name' is not open");
        }

        $h = &$this->handles[$name];
        $now = time();
        $deleted = 0;

        foreach ($h['index'] as $key => $meta) {
            if (isset($meta['expires_at']) && $meta['expires_at'] !== null && $meta['expires_at'] < $now) {
                $this->delete($name, $key);
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Closes the specified store.
     *
     * This method flushes the in-memory index to disk by calling {@see saveIndex()},
     * closes the file handle, and removes the store from the in-memory handles.
     *
     * @param string $name Store name
     * @return void
     */
    public function close(string $name): void
    {
        if (!isset($this->handles[$name])) return;
        $this->saveIndex($name);
        fclose($this->handles[$name]['fh']);
        unset($this->handles[$name]);
    }

    /**
     * Closes all open stores.
     *
     * This method iterates over all in-memory handles, saves each index to disk,
     * closes the corresponding file handles, and removes them from memory.
     *
     * @return void
     */
    public function closeAll(): void
    {
        foreach (array_keys($this->handles) as $name) {
            $this->close($name);
        }
    }

    /**
     * Returns statistics for the specified store.
     *
     * Provides information about the number of keys, data and index file sizes,
     * total size, fragmentation percentage, and average value size. Fragmentation
     * is calculated based on the difference between the data file size and the sum
     * of stored value lengths.
     *
     * @param string $name Store name
     * @return array Associative array containing:
     *               [
     *                 'keys' => int,                 // Number of entries
     *                 'data_size' => int,            // Size of .dat file in bytes
     *                 'index_size' => int,           // Size of .bin index file in bytes
     *                 'total_size' => int,           // Sum of data and index sizes
     *                 'fragmentation_percent' => float, // Percentage of unused space
     *                 'avg_value_size' => float      // Average size of stored values
     *               ]
     * @throws RuntimeException If the store is not open
     */
    public function stats(string $name): array
    {
        if (!isset($this->handles[$name])) {
            throw new RuntimeException("Store '$name' is not open");
        }

        $h = $this->handles[$name];
        $dataSize = filesize($h['dataFile']);
        $indexSize = filesize($h['indexFile']);
        $keyCount = count($h['index']);

        // Calculate fragmentation
        $usedSpace = array_sum(array_column($h['index'], 'length'));
        $fragmentation = $dataSize > 0
            ? round((1 - $usedSpace / $dataSize) * 100, 2)
            : 0;

        return [
            'keys' => $keyCount,
            'data_size' => $dataSize,
            'index_size' => $indexSize,
            'total_size' => $dataSize + $indexSize,
            'fragmentation_percent' => $fragmentation,
            'avg_value_size' => $keyCount > 0 ? round($usedSpace / $keyCount) : 0,
        ];
    }
}

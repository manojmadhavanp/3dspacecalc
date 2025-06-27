```php
<?php

namespace App\Config;

use App\Model\Container; // Assuming Container.php is in App\Model

class ContainerLoader {
    private static ?array $allContainersCache = null; // Static cache
    private static float $usableFactor = 0.95; // Default usable factor

    /**
     * Loads container definitions from a JSON file.
     * Uses a static cache to avoid reloading the file on every call.
     *
     * @param string|null $filePath Path to the JSON config file. If null, uses a default.
     * @return array<string, Container> Associative array of Container objects, keyed by container key.
     * @throws \Exception If file not found or JSON is invalid.
     */
    public static function getAllContainers(?string $filePath = null): array {
        if (self::$allContainersCache !== null) {
            return self::$allContainersCache;
        }

        if ($filePath === null) {
            // Default path relative to this file or a known config directory
            // Adjust this path as per your project structure.
            // Assumes config folder is at the same level as src, or use absolute path.
            $filePath = __DIR__ . '/../../config/container_definitions.json';
        }

        if (!file_exists($filePath)) {
            throw new \Exception("Container configuration file not found at: " . realpath($filePath) ?: $filePath);
        }

        $jsonString = file_get_contents($filePath);
        if ($jsonString === false) {
            throw new \Exception("Could not read container configuration file: $filePath");
        }

        $configData = json_decode($jsonString, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Error decoding container_definitions.json: " . json_last_error_msg());
        }

        if (!isset($configData['containerTypes']) || !is_array($configData['containerTypes'])) {
            throw new \Exception("Missing or invalid 'containerTypes' key in container configuration.");
        }

        self::$usableFactor = $configData['usableFactor'] ?? 0.95;

        $parsedContainers = [];
        foreach ($configData['containerTypes'] as $key => $data) {
            // Basic validation for essential fields
            $requiredKeys = ['name', 'length', 'width', 'height', 'doorWidth', 'doorHeight', 'maxPayload', 'tareWeight', 'loadingTypes', 'floorType', 'category', 'loadCapacityPerMeter'];
            foreach($requiredKeys as $reqKey) {
                if(!isset($data[$reqKey])) {
                    throw new \Exception("Missing required key '$reqKey' for container '$key' in configuration.");
                }
            }

            $parsedContainers[$key] = new Container(
                $key,
                $data['name'],
                (float)$data['length'], (float)$data['width'], (float)$data['height'],
                (float)$data['doorWidth'], (float)$data['doorHeight'],
                (float)$data['maxPayload'], (float)$data['tareWeight'],
                (array)$data['loadingTypes'], $data['floorType'], $data['category'],
                (float)$data['loadCapacityPerMeter'],
                self::$usableFactor // Pass the loaded usableFactor
            );
        }

        self::$allContainersCache = $parsedContainers;
        return self::$allContainersCache;
    }

    /**
     * Gets a single container by its key.
     *
     * @param string $key The key of the container (e.g., "20ftGPWood").
     * @param string|null $filePath Path to the JSON config file.
     * @return Container|null The Container object if found, else null.
     */
    public static function getContainerByKey(string $key, ?string $filePath = null): ?Container {
        $all = self::getAllContainers($filePath);
        return $all[$key] ?? null;
    }

    /**
     * Returns the usable factor loaded from the container definitions.
     */
    public static function getUsableFactor(): float {
        if (self::$allContainersCache === null) { // Ensure config is loaded
            self::getAllContainers();
        }
        return self::$usableFactor;
    }
}
```

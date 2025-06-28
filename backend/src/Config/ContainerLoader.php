```php
<?php

namespace App\Config;

use App\Model\Container;

class ContainerLoader {
    private static ?array $allContainersCache = null;
    private static float $usableFactor = 0.95;

    public static function getAllContainers(?string $filePath = null): array {
        // Use cache only if the default filepath is being implicitly used
        if (self::$allContainersCache !== null && $filePath === null) {
            return self::$allContainersCache;
        }

        $effectiveFilePath = $filePath;
        if ($effectiveFilePath === null) {
            $effectiveFilePath = getenv('CONTAINER_CONFIG_PATH') ?: __DIR__ . '/../../config/container_definitions.json';
        }

        if (!file_exists($effectiveFilePath)) {
            throw new \Exception("Container configuration file not found at: " . realpath($effectiveFilePath) ?: $effectiveFilePath);
        }

        $jsonString = file_get_contents($effectiveFilePath);
        if ($jsonString === false) {
            throw new \Exception("Could not read container configuration file: $effectiveFilePath");
        }

        $configData = json_decode($jsonString, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Error decoding container_definitions.json: " . json_last_error_msg());
        }

        if (!isset($configData['containerTypes']) || !is_array($configData['containerTypes'])) {
            throw new \Exception("Missing or invalid 'containerTypes' key in container configuration.");
        }

        $loadedUsableFactor = $configData['usableFactor'] ?? 0.95; // Use factor from JSON if present

        $parsedContainers = [];
        foreach ($configData['containerTypes'] as $key => $data) {
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
                $loadedUsableFactor
            );
        }

        // Cache only if the default path was used for loading
        if ($filePath === null) {
            self::$allContainersCache = $parsedContainers;
            self::$usableFactor = $loadedUsableFactor; // Store the factor that was used for this cache
        }

        return $parsedContainers;
    }

    public static function getContainerByKey(string $key, ?string $filePath = null): ?Container {
        $all = self::getAllContainers($filePath);
        return $all[$key] ?? null;
    }

    public static function getUsableFactor(): float {
        if (self::$allContainersCache === null) {
            self::getAllContainers(); // Load config to ensure usableFactor is populated
        }
        return self::$usableFactor;
    }

    // Call to clear cache, e.g., for testing with different config files
    public static function clearCache(): void {
        self::$allContainersCache = null;
    }
}
```

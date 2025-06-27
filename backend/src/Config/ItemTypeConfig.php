```php
<?php

namespace App\Config;

class ItemTypeConfig {
    private static ?array $configData = null;
    private static float $defaultMaxSupportWeight = 0; // Default if not specified for a type

    /**
     * Loads item type configurations from a JSON file.
     * Uses a static cache.
     *
     * @param string|null $filePath Path to the item type config JSON.
     * @throws \Exception If file not found or JSON is invalid.
     */
    public static function loadConfig(?string $filePath = null): void {
        if (self::$configData !== null) {
            return; // Already loaded
        }

        if ($filePath === null) {
            // Adjust path as per your project structure
            $filePath = __DIR__ . '/../../config/item_type_config.json';
        }

        if (!file_exists($filePath)) {
            throw new \Exception("Item type configuration file not found at: " . realpath($filePath) ?: $filePath);
        }

        $jsonString = file_get_contents($filePath);
        if ($jsonString === false) {
            throw new \Exception("Could not read item type configuration file: $filePath");
        }

        $decodedData = json_decode($jsonString, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Error decoding item_type_config.json: " . json_last_error_msg());
        }

        if (!isset($decodedData['itemTypes']) || !is_array($decodedData['itemTypes'])) {
            throw new \Exception("Missing or invalid 'itemTypes' key in item type configuration.");
        }

        self::$configData = $decodedData['itemTypes'];
        self::$defaultMaxSupportWeight = (float)($decodedData['defaultMaxSupportWeightKg'] ?? 0);
    }

    /**
     * Gets the maximum support weight (kg) an item of this type can bear on top of it.
     *
     * @param string $itemType The type of the item (e.g., "pallet", "box").
     * @return float The max support weight in kg.
     */
    public static function getMaxSupportWeightKg(string $itemType): float {
        if (self::$configData === null) {
            self::loadConfig(); // Ensure config is loaded
        }
        return (float)(self::$configData[$itemType]['maxSupportWeightKg'] ?? self::$defaultMaxSupportWeight);
    }

    /**
     * Gets a specific configuration property for an item type.
     *
     * @param string $itemType The type of the item.
     * @param string $propertyKey The configuration property key (e.g., "settlingFactor", "isCylindrical").
     * @param mixed $defaultValue Default value if property not found.
     * @return mixed The property value or default.
     */
    public static function getProperty(string $itemType, string $propertyKey, mixed $defaultValue = null): mixed {
        if (self::$configData === null) {
            self::loadConfig();
        }
        return self::$configData[$itemType][$propertyKey] ?? $defaultValue;
    }
}

// Initialize by loading the config - typically done in a bootstrap file or at the start of api.php
// ItemTypeConfig::loadConfig();
// However, to ensure it's loaded when any static method is first called,
// the methods themselves call loadConfig() if $configData is null.
```

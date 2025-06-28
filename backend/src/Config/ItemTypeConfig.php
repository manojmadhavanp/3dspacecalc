```php
<?php

namespace App\Config;

class ItemTypeConfig {
    private static ?array $itemTypeProperties = null; // Changed from $configData for clarity
    private static float $defaultMaxSupportWeightKg = 0;

    public static function loadConfig(?string $filePath = null): void {
        // Use cache only if the default filepath is being implicitly used
        if (self::$itemTypeProperties !== null && $filePath === null) {
            return;
        }

        $effectiveFilePath = $filePath;
        if ($effectiveFilePath === null) {
            $effectiveFilePath = getenv('ITEM_TYPE_CONFIG_PATH') ?: __DIR__ . '/../../config/item_type_config.json';
        }

        if (!file_exists($effectiveFilePath)) {
            throw new \Exception("Item type configuration file not found at: " . realpath($effectiveFilePath) ?: $effectiveFilePath);
        }

        $jsonString = file_get_contents($effectiveFilePath);
        if ($jsonString === false) {
            throw new \Exception("Could not read item type configuration file: $effectiveFilePath");
        }

        $decodedData = json_decode($jsonString, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Error decoding item_type_config.json: " . json_last_error_msg());
        }

        if (!isset($decodedData['itemTypes']) || !is_array($decodedData['itemTypes'])) {
            throw new \Exception("Missing or invalid 'itemTypes' key in item type configuration.");
        }

        $loadedItemTypeProperties = $decodedData['itemTypes'];
        $loadedDefaultMaxSupport = (float)($decodedData['defaultMaxSupportWeightKg'] ?? 0);

        // Cache only if the default path was used for loading
        if ($filePath === null) {
            self::$itemTypeProperties = $loadedItemTypeProperties;
            self::$defaultMaxSupportWeightKg = $loadedDefaultMaxSupport;
        } else { // If a specific path is given, return data directly or use for this instance only
             // For simplicity, this static loader will always set the static props when called with default path.
             // If called with specific path, it means it's likely for a specific non-default use,
             // so we don't overwrite the static cache meant for the application's default config.
             // This part could be redesigned if dynamic config switching per request is needed.
             // For now, loadConfig() primarily populates the static properties.
             self::$itemTypeProperties = $loadedItemTypeProperties; // Overwrite for this call if path specified
             self::$defaultMaxSupportWeightKg = $loadedDefaultMaxSupport;
        }
    }

    public static function getMaxSupportWeightKg(string $itemType): float {
        if (self::$itemTypeProperties === null) {
            self::loadConfig();
        }
        return (float)(self::$itemTypeProperties[$itemType]['maxSupportWeightKg'] ?? self::$defaultMaxSupportWeightKg);
    }

    public static function getProperty(string $itemType, string $propertyKey, mixed $defaultValue = null): mixed {
        if (self::$itemTypeProperties === null) {
            self::loadConfig();
        }
        return self::$itemTypeProperties[$itemType][$propertyKey] ?? $defaultValue;
    }

    // Call to clear cache, e.g., for testing with different config files
    public static function clearCache(): void {
        self::$itemTypeProperties = null;
    }
}
```

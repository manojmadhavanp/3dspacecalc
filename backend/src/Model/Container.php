```php
<?php

namespace App\Model;

class Container {
    public string $key; // e.g., "20ftGPWood"
    public string $name;
    public float $length; // Max internal dimension (Y-axis in placement, typically longest)
    public float $width;  // Shorter internal dimension (X-axis in placement)
    public float $height; // Vertical internal dimension (Z-axis in placement)
    public float $doorWidth;
    public float $doorHeight;
    public float $maxPayload; // kg
    public float $tareWeight; // kg
    public float $maxVolume;  // cm³ (raw internal L*W*H)
    public float $usablePayload; // kg
    public float $usableVolume;  // cm³
    public array $loadingTypes; // e.g., ["front"], ["top"]
    public string $floorType; // "Wooden", "Steel"
    public string $category;  // "GP", "OOG"
    public float $loadCapacityPerMeter; // tonnes/meter

    // This property will be populated by GetContainersService
    public array $assignedItems = [];

    public function __construct(
        string $key, string $name,
        float $length, float $width, float $height,
        float $doorWidth, float $doorHeight,
        float $maxPayload, float $tareWeight,
        array $loadingTypes, string $floorType, string $category,
        float $loadCapacityPerMeter, float $usableFactor = 0.95 // Make usableFactor injectable or global const
    ) {
        $this->key = $key;
        $this->name = $name;
        $this->length = $length;
        $this->width = $width;
        $this->height = $height;
        $this->doorWidth = $doorWidth;
        $this->doorHeight = $doorHeight;
        $this->maxPayload = $maxPayload;
        $this->tareWeight = $tareWeight;
        $this->loadingTypes = $loadingTypes;
        $this->floorType = $floorType;
        $this->category = $category;
        $this->loadCapacityPerMeter = $loadCapacityPerMeter;

        $this->maxVolume = $this->length * $this->width * $this->height;
        $this->usablePayload = $this->maxPayload * $usableFactor;
        $this->usableVolume = $this->maxVolume * $usableFactor;
    }

    // Helper to quickly check if it's a standard GP type often used for GPC
    public function isStandardGP(): bool {
        return $this->category === "GP" && in_array("front", $this->loadingTypes);
    }

    // Helper for Open Top check
    public function isOpenTop(): bool {
        return in_array("top", $this->loadingTypes);
    }
}
```

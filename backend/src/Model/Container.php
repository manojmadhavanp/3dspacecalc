```php
<?php

namespace App\Model;

class Container {
    public string $key;
    public string $name;
    public float $length;
    public float $width;
    public float $height;
    public float $doorWidth;
    public float $doorHeight;
    public float $maxPayload;
    public float $tareWeight;
    public float $maxVolume;
    public float $usablePayload;
    public float $usableVolume;
    public array $loadingTypes;
    public string $floorType;
    public string $category;
    public float $loadCapacityPerMeter;

    public array $assignedItems = [];

    public function __construct(
        string $key, string $name,
        float $length, float $width, float $height,
        float $doorWidth, float $doorHeight,
        float $maxPayload, float $tareWeight,
        array $loadingTypes, string $floorType, string $category,
        float $loadCapacityPerMeter, float $usableFactor = 0.95
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

    public function isStandardGP(): bool {
        return $this->category === "GP" && in_array("front", $this->loadingTypes);
    }

    public function isOpenTop(): bool {
        return in_array("top", $this->loadingTypes);
    }
}
```

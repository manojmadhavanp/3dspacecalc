```php
<?php

namespace App\Model;

/**
 * Represents a 2D rectangular surface within a layer where items can be placed.
 * Used by the layer-based placement strategy.
 */
class PlacementSurface {
    public string $id;
    public float $x;
    public float $y;
    public float $width;
    public float $length;
    public float $availableHeightOnSurface;
    public float $area;
    private static int $idCounter = 0;

    public function __construct(
        float $x,
        float $y,
        float $width,
        float $length,
        float $availableHeight,
        ?string $idSuffix = null // Optional suffix for more descriptive IDs
    ) {
        $this->id = "SFC_" . ($idSuffix ?? self::$idCounter++);
        $this->x = $x;
        $this->y = $y;
        $this->width = $width;
        $this->length = $length;
        $this->availableHeightOnSurface = $availableHeight;
        $this->area = $this->width * $this->length;
    }

    public static function resetIdCounter() {
        self::$idCounter = 0;
    }

    public function isValid(): bool {
        $epsilon = 0.01;
        return $this->width > $epsilon && $this->length > $epsilon && $this->availableHeightOnSurface > $epsilon;
    }

    public function __toString(): string {
        return sprintf(
            "Surface %s: (x:%.1f, y:%.1f) Dims(W:%.1f, L:%.1f) AvailH:%.1f Area:%.1f",
            $this->id, $this->x, $this->y,
            $this->width, $this->length, $this->availableHeightOnSurface, $this->area
        );
    }
}
```

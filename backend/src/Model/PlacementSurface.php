```php
<?php

namespace App\Model;

/**
 * Represents a 2D rectangular surface within a layer where items can be placed.
 */
class PlacementSurface {
    public string $id;    // Unique ID for tracking/debugging
    public float $x;       // Starting X coordinate of this surface within the container
    public float $y;       // Starting Y coordinate of this surface within the container
    public float $width;   // Width of this surface (along container's X-axis)
    public float $length;  // Length of this surface (along container's Y-axis)

    /**
     * @var float The maximum height an item placed on this surface can have,
     *            determined by the layer's definition (e.g., container_height - layer_z_start).
     */
    public float $availableHeightOnSurface;

    public float $area;

    private static int $idCounter = 0; // Simple counter for unique IDs

    public function __construct(
        float $x,
        float $y,
        float $width,
        float $length,
        float $availableHeight
    ) {
        $this->id = "SFC_" . self::$idCounter++;
        $this->x = $x;
        $this->y = $y;
        $this->width = $width;
        $this->length = $length;
        $this->availableHeightOnSurface = $availableHeight;
        $this->area = $this->width * $this->length;
    }

    public function isValid(): bool {
        $epsilon = 0.01; // Minimum sensible dimension
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

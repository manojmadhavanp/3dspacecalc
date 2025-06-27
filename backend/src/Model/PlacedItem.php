```php
<?php

namespace App\Model;

// Requires Item.php to be loaded or autoloaded
// use App\Model\Item;

class PlacedItem {
    public string $itemName;
    public int $originalQtyIndex; // To identify this specific unit if qty > 1
    public string $type;

    // Store original dimensions for reference in output
    public array $originalDimensions; // ['width' => float, 'length' => float, 'height' => float]
    public float $weight;

    // Placement details within the container
    public float $x; // Absolute X coordinate of the front-left-bottom corner of the item
    public float $y; // Absolute Y coordinate
    public float $z; // Absolute Z coordinate

    public float $orientedWidth;  // Actual width the item occupies in the container (along X-axis)
    public float $orientedLength; // Actual length the item occupies in the container (along Y-axis)
    public float $orientedHeight; // Actual height the item occupies in the container (along Z-axis)

    // Could also store the chosen orientation index if needed for complex rendering
    // public int $orientationIndex;

    public function __construct(
        Item $originalItem, // Pass the full Item object used for placement decision
        int $originalQtyIndex,
        float $x, float $y, float $z,
        float $orientedWidth, float $orientedLength, float $orientedHeight
        // int $orientationIndex // Potentially add this
    ) {
        $this->itemName = $originalItem->name;
        $this->originalQtyIndex = $originalQtyIndex;
        $this->type = $originalItem->type;
        $this->originalDimensions = [
            'width' => $originalItem->width,
            'length' => $originalItem->length,
            'height' => $originalItem->height
        ];
        $this->weight = $originalItem->weight;

        $this->x = $x;
        $this->y = $y;
        $this->z = $z;
        $this->orientedWidth = $orientedWidth;
        $this->orientedLength = $orientedLength;
        $this->orientedHeight = $orientedHeight;
        // $this->orientationIndex = $orientationIndex;
    }

    // Helper to get the volume this placed item occupies
    public function getPlacedVolume(): float {
        return $this->orientedWidth * $this->orientedLength * $this->orientedHeight;
    }
}
```

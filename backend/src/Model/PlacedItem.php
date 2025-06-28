```php
<?php

namespace App\Model;

class PlacedItem {
    public string $itemName;
    public int $originalQtyIndex;
    public string $type;
    public array $originalDimensions;
    public float $weight;

    public float $x;
    public float $y;
    public float $z;

    public float $orientedWidth;
    public float $orientedLength;
    public float $orientedHeight;

    public ?Item $originalItemRef; // Optional: reference to original Item object, not for JSON output directly

    public function __construct(
        Item $originalItem,
        int $originalQtyIndex,
        float $x, float $y, float $z,
        float $orientedWidth, float $orientedLength, float $orientedHeight
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
        $this->originalItemRef = $originalItem; // Store for potential internal use (e.g. stacking checks)
    }

    public function getPlacedVolume(): float {
        return $this->orientedWidth * $this->orientedLength * $this->orientedHeight;
    }

    // Helper to convert to array for JSON output (matches what api.php expects)
    public function toArray(): array {
        return [
            "itemName" => $this->itemName,
            "originalQtyIndex" => $this->originalQtyIndex,
            "type" => $this->type,
            "originalDimensions" => $this->originalDimensions,
            "weight" => $this->weight,
            "placement" => [
                "x" => $this->x, "y" => $this->y, "z" => $this->z,
                "orientedWidth" => $this->orientedWidth,
                "orientedLength" => $this->orientedLength,
                "orientedHeight" => $this->orientedHeight
            ]
        ];
    }
}
```

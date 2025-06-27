```php
<?php

namespace App\Model;

class Item {
    public string $name;
    public string $type; // e.g., "box", "pallet"
    public float $width;  // Original width (cm)
    public float $length; // Original length (cm)
    public float $height; // Original height (cm)
    public float $weight; // Weight per unit (kg)
    public bool $stackable; // Whether items of THIS TYPE can be stacked on each other, or if this item can support others (see ItemTypeConfig)
    public bool $tiltable;  // Whether the item can be rotated on X or Y axes
    public int $qty;       // Original quantity from input

    // Properties populated during processing
    public int $originalQtyIndex = 0; // Unique index if items are expanded (1 to N)
    public string $category = '';      // GPC, GPINGC, OOG - determined by SortItemsService

    // Stores placement details if item is placed.
    // Could be an array or a reference to a PlacedItem object, depending on final structure.
    // For now, let's assume it will store an array like:
    // ['x' => x, 'y' => y, 'z' => z, 'orientedWidth' => oW, 'orientedLength' => oL, 'orientedHeight' => oH]
    public ?array $placement = null;

    public function __construct(
        string $name, string $type,
        float $width, float $length, float $height,
        float $weight, bool $stackable, bool $tiltable, int $qty
    ) {
        $this->name = $name;
        $this->type = $type;
        $this->width = $width;
        $this->length = $length;
        $this->height = $height;
        $this->weight = $weight;
        $this->stackable = $stackable;
        $this->tiltable = $tiltable;
        $this->qty = $qty;
    }

    public function getVolume(): float {
        return $this->width * $this->length * $this->height;
    }

    /**
     * Returns dimensions based on a specific orientation.
     * Orientation index:
     * For non-tiltable (or default for tiltable):
     *   0: W, L, H (width as X-dim, length as Y-dim, height as Z-dim for placement)
     *   1: L, W, H (length as X-dim, width as Y-dim, height as Z-dim for placement)
     * For tiltable (6 orientations - L,W,H != H,W,L etc.):
     *   0: W, L, H
     *   1: L, W, H (Rotated Z)
     *   2: W, H, L (Rotated X, width base, height becomes length, length becomes height)
     *   3: H, W, L (Rotated X then Z)
     *   4: L, H, W (Rotated Y, length base, height becomes width, width becomes height)
     *   5: H, L, W (Rotated Y then Z)
     * TODO: This needs robust implementation if 6-way tilting is fully supported.
     * For now, simplified to 2 orientations for planning.
     */
    public function getOrientedDimensions(int $orientation = 0): array {
        $w = $this->width;
        $l = $this->length;
        $h = $this->height;

        if ($this->tiltable) {
            switch ($orientation) {
                case 0: return ['width' => $w, 'length' => $l, 'height' => $h]; // Default WxLxH
                case 1: return ['width' => $l, 'length' => $w, 'height' => $h]; // Rotated on Z (L becomes width)
                // Placeholder for other 4 tiltable orientations - these need careful definition
                // For example, if item is laid on its side:
                case 2: return ['width' => $w, 'length' => $h, 'height' => $l]; // Width base, height is new length, old length is new height
                case 3: return ['width' => $h, 'length' => $w, 'height' => $l]; // Height base, width is new length, old length is new height
                case 4: return ['width' => $l, 'length' => $h, 'height' => $w]; // Length base, height is new width, old width is new height
                case 5: return ['width' => $h, 'length' => $l, 'height' => $w]; // Height base, length is new width, old width is new height
                default: return ['width' => $w, 'length' => $l, 'height' => $h];
            }
        } else { // Not tiltable, only Z-axis rotation allowed
            if ($orientation === 1) { // Rotated 90 degrees on Z axis
                return ['width' => $l, 'length' => $w, 'height' => $h];
            }
            // Default: original orientation (orientation 0)
            return ['width' => $w, 'length' => $l, 'height' => $h];
        }
    }

    public function getNumberOfOrientations(): int {
        return $this->tiltable ? 6 : 2; // Simplified for now, actual tiltable might just be 2 if sides not distinct
    }
}
```

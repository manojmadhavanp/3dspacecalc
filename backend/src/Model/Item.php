```php
<?php

namespace App\Model;

class Item {
    public string $name;
    public string $type;
    public float $width;
    public float $length;
    public float $height;
    public float $weight;
    public bool $stackable;
    public bool $tiltable;
    public int $qty;

    public int $originalQtyIndex = 0;
    public string $category = '';
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

    public function getOrientedDimensions(int $orientation = 0): array {
        $w = $this->width;
        $l = $this->length;
        $h = $this->height;

        if ($this->tiltable) {
            // Simplified: actual 6-way rotation logic is complex and depends on definition
            // For now, only providing 2 basic orientations if tiltable, same as non-tiltable
            switch ($orientation) {
                case 0: return ['width' => $w, 'length' => $l, 'height' => $h];
                case 1: return ['width' => $l, 'length' => $w, 'height' => $h];
                // TODO: Add cases 2-5 for full 3D tilting if required by placement strategy
                // case 2: return ['width' => $w, 'length' => $h, 'height' => $l]; // Example: On side
                // case 3: return ['width' => $h, 'length' => $w, 'height' => $l]; // Example: On side rotated
                // case 4: return ['width' => $l, 'length' => $h, 'height' => $w]; // Example: On end
                // case 5: return ['width' => $h, 'length' => $l, 'height' => $w]; // Example: On end rotated
                default: return ['width' => $w, 'length' => $l, 'height' => $h];
            }
        } else {
            if ($orientation === 1) {
                return ['width' => $l, 'length' => $w, 'height' => $h];
            }
            return ['width' => $w, 'length' => $l, 'height' => $h];
        }
    }

    public function getNumberOfOrientations(): int {
        // TODO: Return 6 if tiltable and all 6 unique orientations are implemented in getOrientedDimensions
        return $this->tiltable ? 2 : 2; // Simplified for now
    }
}
```

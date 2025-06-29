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
    public ?array $placement = null; // Stores final placement data for an individual item instance
    public ?float $maxSupportWeightKgOverride = null; // User-defined override for how much this item can support
    public ?string $placementFailureReason = null; // Reason why this item couldn't be placed (set by services)

    public const EPSILON_COMPARISON = 0.01; // For comparing float dimensions if needed elsewhere

    public function __construct(
        string $name, string $type,
        float $width, float $length, float $height,
        float $weight, bool $stackable, bool $tiltable, int $qty,
        ?float $maxSupportWeightKgOverride = null // Added optional constructor param
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
        $originalW = $this->width;
        $originalL = $this->length;
        $originalH = $this->height;

        // Standard behavior for boxes or default
        $w = $originalW;
        $l = $originalL;
        $h = $originalH;

        $isBarrel = ($this->type === 'barels' && ItemTypeConfig::getProperty('barels', 'isCylindrical', false));
        // Add similar $isPipe if needed for very specific pipe orientations

        if ($this->tiltable) {
            if ($isBarrel) {
                // For barrels, tiltable means switching between "stand_on_end" and "lay_on_side"
                // Assumed input for barrel: W=Diameter, L=Diameter, H=BarrelActualHeight (stand_on_end)
                switch ($orientation) {
                    case 0: // Stand on end (primary)
                        return ['width' => $originalW, 'length' => $originalL, 'height' => $originalH];
                    case 1: // Lay on side: Diameter becomes height, Original Height becomes length, Width remains Diameter
                        return ['width' => $originalW, 'length' => $originalH, 'height' => $originalL]; // Assuming L was also Diameter
                                                                                                    // More robust: W=Diameter, L=OriginalHeight, H=Diameter
                                                                                                    // If W=L=Diameter, then H_new=Diameter, L_new=OriginalHeight, W_new=Diameter
                    default: return ['width' => $originalW, 'length' => $originalL, 'height' => $originalH];
                }
            } else { // General tiltable (box-like) - current simplified 2-way, TODO: 6-way
                 switch ($orientation) {
                    case 0: return ['width' => $w, 'length' => $l, 'height' => $h]; // WxLxH
                    case 1: return ['width' => $l, 'length' => $w, 'height' => $h]; // LxWxH (Z-rotation)
                    // TODO: Add cases 2-5 for full 3D tilting for boxes if required
                    // case 2: return ['width' => $w, 'length' => $h, 'height' => $l]; // WxHxL (X-rotation, L becomes height)
                    // case 3: return ['width' => $h, 'length' => $l, 'height' => $w]; // HxLxW (Y-rotation, W becomes height)
                    // case 4: return ['width' => $l, 'length' => $h, 'height' => $w]; // LxHxW (X-rotation then Z, W becomes height)
                    // case 5: return ['width' => $h, 'length' => $w, 'height' => $l]; // HxWxL (Y-rotation then Z, L becomes height)
                    default: return ['width' => $w, 'length' => $l, 'height' => $h];
                }
            }
        } else { // Not tiltable
            if ($isBarrel) { // Barrel on end, no other orientation if not tiltable
                 return ['width' => $originalW, 'length' => $originalL, 'height' => $originalH];
            }
            // For other non-tiltable items (like pipes assumed lay_flat or boxes)
            // A Z-axis rotation (swapping W and L) is often considered a standard orientation, not "tilting".
            // The "tiltable" flag should mean "can it be put on a different face than its base".
            // Let's refine: getNumberOfOrientations will return 2 if W!=L for non-tiltable boxes/pipes (Z-rotation)
            // and 1 if W==L (like a barrel on end).
            if ($orientation === 1 && $originalW !== $originalL) { // Allow Z-axis 90deg rotation if W and L are different
                return ['width' => $originalL, 'length' => $originalW, 'height' => $originalH];
            }
            return ['width' => $originalW, 'length' => $originalL, 'height' => $originalH];
        }
    }

    public function getNumberOfOrientations(): int {
        $isBarrel = ($this->type === 'barels' && ItemTypeConfig::getProperty('barels', 'isCylindrical', false));
        // Add $isPipe if specific logic needed

        if ($this->tiltable) {
            if ($isBarrel) {
                // Stand-on-end (W=D,L=D,H=H_orig) vs Lay-on-side (W=D,L=H_orig,H=D)
                // These are 2 distinct orientations if H_orig != D
                return ($this->height !== $this->width) ? 2 : 1;
            }
            // TODO: Return 6 for general tiltable boxes when all 6 orientations are implemented
            return 2; // Simplified for now for other tiltable (W,L,H and L,W,H)
        } else { // Not tiltable
            if ($isBarrel) { // Barrel on end, only 1 orientation if not tiltable
                return 1;
            }
            // For other non-tiltable (boxes, pipes assumed in primary orientation)
            // Allow swapping W and L if they are different (Z-axis rotation)
            return ($this->width !== $this->length) ? 2 : 1;
        }
    }
}
```

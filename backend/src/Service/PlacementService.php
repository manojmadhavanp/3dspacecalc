```php
<?php

namespace App\Service;

use App\Model\Container;
use App\Model\Item;
use App\Model\BoxArea; // For 3D BoxArea placement
use App\Model\PlacedItem;
use App\Config\ItemTypeConfig; // For future use with stacking rules

class PlacementService {
    private int $boxAreaIdCounter = 0;
    private const EPSILON = 0.01; // Epsilon for float comparisons

    public function PlaceItemsInContainer(Container $container, array $itemGroupsAssigned): array {
        $placedItemsList = [];
        $unplacedItemsList = [];

        $expandedItemList = [];
        $originalQtyIdxCounter = 1;
        foreach ($itemGroupsAssigned as $group) {
            for ($i = 0; $i < $group->qty; $i++) {
                $individualItem = clone $group;
                $individualItem->qty = 1;
                $individualItem->originalQtyIndex = $originalQtyIdxCounter++;
                $expandedItemList[] = $individualItem;
            }
        }
        // $expandedItemList should already be sorted by original group weight (heaviest first)
        // from SortItemsService -> GetContainersService assignment.

        BoxArea::resetIdCounter(); // Reset BoxArea static ID counter for this new placement run
        $initialBoxArea = new BoxArea(
            "INIT", 0, 0, 0, // Suffix, x, y, z
            $container->width, $container->length, $container->height
        );
        $availableBoxAreas = [$initialBoxArea];
        $currentTotalWeightInContainer = 0;

        foreach ($expandedItemList as $itemToPlace) {
            $itemSuccessfullyPlaced = false;
            usort($availableBoxAreas, [$this, 'sortBoxAreas']); // Sort by R,F,T then Volume

            $bestTargetBoxAreaIndex = -1;
            $chosenOrientationDetails = null;

            foreach ($availableBoxAreas as $baIndex => $targetBoxArea) {
                if (!$targetBoxArea->isValid()) {
                    // Optionally remove invalid areas from list to prevent re-checking
                    // unset($availableBoxAreas[$baIndex]); // Be careful with unsetting during iteration
                    continue;
                }

                $orientationDetails = $this->findBestOrientationForItemIn3DBoxArea($itemToPlace, $targetBoxArea, $placedItemsList);

                if ($orientationDetails !== null) {
                    if (($currentTotalWeightInContainer + $itemToPlace->weight) > ($container->usablePayload + self::EPSILON)) {
                        continue;
                    }
                    // Stacking checks are now partially within findBestOrientationForItemIn3DBoxArea
                    $bestTargetBoxAreaIndex = $baIndex;
                    $chosenOrientationDetails = $orientationDetails;
                    break;
                }
            }

            if ($bestTargetBoxAreaIndex !== -1) {
                $targetBoxArea = $availableBoxAreas[$bestTargetBoxAreaIndex];
                $oDims = $chosenOrientationDetails['dimensions'];

                $placedX = $targetBoxArea->x;
                $placedY = $targetBoxArea->y;
                $placedZ = $targetBoxArea->z;

                $newlyPlacedItem = new PlacedItem(
                    $itemToPlace, $itemToPlace->originalQtyIndex,
                    $placedX, $placedY, $placedZ,
                    $oDims['width'], $oDims['length'], $oDims['height']
                );
                $placedItemsList[] = $newlyPlacedItem;
                // $itemToPlace->placement = $newlyPlacedItem->toArray()['placement']; // Mark item with placement

                $currentTotalWeightInContainer += $itemToPlace->weight;
                $itemSuccessfullyPlaced = true;

                array_splice($availableBoxAreas, $bestTargetBoxAreaIndex, 1);
                $newlyGeneratedAreas = $this->splitConsumed3DBoxArea($targetBoxArea, $oDims['width'], $oDims['length'], $oDims['height']);
                foreach ($newlyGeneratedAreas as $newArea) {
                    if ($newArea->isValid()) {
                        $availableBoxAreas[] = $newArea;
                    }
                }
            }

            if (!$itemSuccessfullyPlaced) {
                $unplacedItemsList[] = clone $itemToPlace;
            }
        }

        $layersData = $this->groupPlacedItemsIntoLayers($placedItemsList, $container);

        return [
            // 'placedItems' => $placedItemsList, // This flat list is now represented within layers
            'unplacedItems' => $unplacedItemsList,
            'finalEmptyBoxAreas' => array_map(fn(BoxArea $ba) => $ba->toArray(), $availableBoxAreas), // For debugging 3D spaces
            'layers' => $layersData
        ];
    }

    private function sortBoxAreas(BoxArea $a, BoxArea $b): int {
        // Priority: Rightmost (larger X), then Frontmost (larger Y), then Topmost (larger Z)
        // If all same, prefer smaller volume to fill tight spots first.
        if (abs($a->x - $b->x) > self::EPSILON) return $b->x <=> $a->x; // X descending
        if (abs($a->y - $b->y) > self::EPSILON) return $b->y <=> $a->y; // Y descending
        if (abs($a->z - $b->z) > self::EPSILON) return $b->z <=> $a->z; // Z descending
        return $a->volume <=> $b->volume;           // Volume ascending (smaller volume first)
    }

    private function findBestOrientationForItemIn3DBoxArea(Item $item, BoxArea $boxArea, array $placedItemsList): ?array {
        for ($orientationIndex = 0; $orientationIndex < $item->getNumberOfOrientations(); $orientationIndex++) {
            $dims = $item->getOrientedDimensions($orientationIndex); // ['width', 'length', 'height']

            if ($dims['width'] <= ($boxArea->width + self::EPSILON) &&
                $dims['length'] <= ($boxArea->length + self::EPSILON) &&
                $dims['height'] <= ($boxArea->height + self::EPSILON)) {

                // Dimensional fit is OK. Now check stacking rules if not on floor.
                if ($boxArea->z > self::EPSILON) {
                    if (!$this->canStackItemIn3DBoxArea(
                        $item,
                        $boxArea->x, // Target X for item (origin of BoxArea)
                        $boxArea->y, // Target Y for item
                        $boxArea->z, // Target Z for item (base of item)
                        $dims,
                        $placedItemsList
                    )) {
                        continue; // Stacking rule failed for this orientation in this BoxArea
                    }
                }
                return ['orientationIndex' => $orientationIndex, 'dimensions' => $dims]; // First fit
            }
        }
        return null;
    }

    private function canStackItemIn3DBoxArea(
        Item $itemToPlace,
        float $targetAbsX, float $targetAbsY, float $targetAbsZ,
        array $itemToPlaceOrientedDims,
        array $placedItemsList
    ): bool {
        if ($targetAbsZ < self::EPSILON) return true; // On the floor is always allowed from stacking perspective

        // Check for items directly below the footprint of itemToPlace
        $itemToPlaceFootprint = [
            'x_min' => $targetAbsX, 'x_max' => $targetAbsX + $itemToPlaceOrientedDims['width'] - self::EPSILON,
            'y_min' => $targetAbsY, 'y_max' => $targetAbsY + $itemToPlaceOrientedDims['length'] - self::EPSILON,
            'z_top_of_item_below' => $targetAbsZ
        ];

        $supportingSurfaceArea = 0;
        $minSupportingItemWeight = PHP_FLOAT_MAX;
        $minMaxSupportWeightOfSupportingItems = PHP_FLOAT_MAX;
        $allSupportingItemsStackable = true;

        foreach ($placedItemsList as $placedItem) {
            if (!$placedItem instanceof PlacedItem) continue;

            // Check if placedItem's top surface is at the base of where itemToPlace would sit
            if (abs(($placedItem->z + $placedItem->orientedHeight) - $itemToPlaceFootprint['z_top_of_item_below']) < self::EPSILON) {
                // Check for horizontal overlap
                $overlapXMin = max($itemToPlaceFootprint['x_min'], $placedItem->x);
                $overlapXMax = min($itemToPlaceFootprint['x_max'], $placedItem->x + $placedItem->orientedWidth);
                $overlapYMin = max($itemToPlaceFootprint['y_min'], $placedItem->y);
                $overlapYMax = min($itemToPlaceFootprint['y_max'], $placedItem->y + $placedItem->orientedLength);

                $overlapWidth = $overlapXMax - $overlapXMin;
                $overlapLength = $overlapYMax - $overlapYMin;

                if ($overlapWidth > self::EPSILON && $overlapLength > self::EPSILON) { // Significant overlap
                    $supportingSurfaceArea += $overlapWidth * $overlapLength;
                    $itemBelow = $placedItem->originalItemRef; // Get original item for properties

                    if ($itemBelow === null) { /* error_log("Original item ref missing in PlacedItem"); */ return false; }

                    if (!$itemBelow->stackable && ItemTypeConfig::getMaxSupportWeightKg($itemBelow->type) < self::EPSILON) {
                        $allSupportingItemsStackable = false; break;
                    }
                    if ($itemToPlace->weight > ($itemBelow->weight + self::EPSILON)) {
                         $allSupportingItemsStackable = false; break; // Cannot place heavier on lighter
                    }
                    $minMaxSupportWeightOfSupportingItems = min($minMaxSupportWeightOfSupportingItems, ItemTypeConfig::getMaxSupportWeightKg($itemBelow->type));
                    $minSupportingItemWeight = min($minSupportingItemWeight, $itemBelow->weight);
                }
            }
        }

        if ($supportingSurfaceArea < ($itemToPlaceOrientedDims['width'] * $itemToPlaceOrientedDims['length'] * 0.75 - self::EPSILON) ) { // e.g. needs 75% base support
            return false; // Not enough support area
        }
        if (!$allSupportingItemsStackable) return false;
        if ($itemToPlace->weight > ($minMaxSupportWeightOfSupportingItems + self::EPSILON)) return false;
        // Implicitly, if $itemToPlace->weight > $minSupportingItemWeight, it was caught by allSupportingItemsStackable logic.

        return true;
    }


    private function splitConsumed3DBoxArea(BoxArea $parentArea, float $blockWidth, float $blockLength, float $blockHeight): array {
        $newAreas = [];
        // Right
        if (($parentArea->width - $blockWidth) > self::EPSILON) {
            $newAreas[] = new BoxArea("R", $parentArea->x + $blockWidth, $parentArea->y, $parentArea->z, $parentArea->width - $blockWidth, $blockLength, $blockHeight);
        }
        // Front (larger Y)
        if (($parentArea->length - $blockLength) > self::EPSILON) {
            // This area should span the full width of the parent, *not* just the block width.
            $newAreas[] = new BoxArea("F", $parentArea->x, $parentArea->y + $blockLength, $parentArea->z, $parentArea->width, $parentArea->length - $blockLength, $blockHeight);
        }
        // Top
        if (($parentArea->height - $blockHeight) > self::EPSILON) {
            // This area spans the full width and length of the parent.
            $newAreas[] = new BoxArea("T", $parentArea->x, $parentArea->y, $parentArea->z + $blockHeight, $parentArea->width, $parentArea->length, $parentArea->height - $blockHeight);
        }
        // Refined splitting for more complete space coverage (addresses the L-shape remainder problem from simple 2-split)
        // The previous 3 splits are better for standard packer. Let's ensure they are correct.
        // If block is placed at parentArea->x,y,z:
        $newAreas = []; // Reset for standard 3-way split relative to block.
        // 1. Space to the RIGHT of the block
        if ($parentArea->width - $blockWidth > self::EPSILON) {
            $newAreas[] = new BoxArea("R_SPLIT", $parentArea->x + $blockWidth, $parentArea->y, $parentArea->z,
                                      $parentArea->width - $blockWidth, $parentArea->length /* Full length of PARENT */, $parentArea->height /* Full height of PARENT */);
        }
        // 2. Space IN FRONT of the block (larger Y)
        if ($parentArea->length - $blockLength > self::EPSILON) {
            $newAreas[] = new BoxArea("F_SPLIT", $parentArea->x, $parentArea->y + $blockLength, $parentArea->z,
                                      $blockWidth /* Only width of block for this slice */, $parentArea->length - $blockLength, $parentArea->height /* Full height of PARENT */);
        }
        // 3. Space ON TOP of the block
        if ($parentArea->height - $blockHeight > self::EPSILON) {
            $newAreas[] = new BoxArea("T_SPLIT", $parentArea->x, $parentArea->y, $parentArea->z + $blockHeight,
                                      $blockWidth, $blockLength, $parentArea->height - $blockHeight);
        }
        // This 3-way split still has issues with creating overlapping or not fully utilizing space.
        // The most robust way is to generate 3 non-overlapping new spaces from the remainder of parentArea
        // *after* the block is notionally removed.
        // Simplified: Place block at (parentX, parentY, parentZ).
        // New spaces are:
        // 1. Top: (parentX, parentY, parentZ + blockH, parentW, parentL, parentH - blockH)
        // 2. Front: (parentX, parentY + blockL, parentZ, parentW, parentL - blockL, blockH)
        // 3. Right: (parentX + blockW, parentY, parentZ, parentW - blockW, blockL, blockH)
        $newAreas = []; // Final reset for this standard 3-slice method
        if (($parentArea->height - $blockHeight) > self::EPSILON) { // Top
            $newAreas[] = new BoxArea("T_". $this->generateBoxAreaIdSuffix(), $parentArea->x, $parentArea->y, $parentArea->z + $blockHeight, $parentArea->width, $parentArea->length, $parentArea->height - $blockHeight);
        }
        if (($parentArea->length - $blockLength) > self::EPSILON) { // Front (of block, for height of block)
            $newAreas[] = new BoxArea("F_". $this->generateBoxAreaIdSuffix(), $parentArea->x, $parentArea->y + $blockLength, $parentArea->z, $parentArea->width, $parentArea->length - $blockLength, $blockHeight);
        }
        if (($parentArea->width - $blockWidth) > self::EPSILON) { // Right (of block, for length & height of block)
            $newAreas[] = new BoxArea("R_". $this->generateBoxAreaIdSuffix(), $parentArea->x + $blockWidth, $parentArea->y, $parentArea->z, $parentArea->width - $blockWidth, $blockLength, $blockHeight);
        }

        return $newAreas;
    }

    private function generateBoxAreaIdSuffix(): string {
        return (string) $this->boxAreaIdCounter++;
    }

    private function groupPlacedItemsIntoLayers(array $placedItemsList, Container $container): array {
        if (empty($placedItemsList)) return [];
        $uniqueZStartsMap = [];
        foreach ($placedItemsList as $placedItem) {
            if ($placedItem instanceof PlacedItem) {
                $zRounded = round($placedItem->z, 2);
                $uniqueZStartsMap[(string)$zRounded] = $zRounded;
            }
        }
        if(empty($uniqueZStartsMap)) return [];
        $uniqueZStarts = array_values($uniqueZStartsMap);
        sort($uniqueZStarts);

        $layersOutput = []; $layerIdCounter = 1;
        foreach ($uniqueZStarts as $zStart) {
            $itemsInLayer = [];
            foreach ($placedItemsList as $placedItem) {
                 if ($placedItem instanceof PlacedItem) {
                    if (abs($placedItem->z - $zStart) < self::EPSILON) {
                        $itemsInLayer[] = $placedItem->toArray(); // Use PlacedItem's toArray method
                    }
                }
            }
            if (!empty($itemsInLayer)) {
                $layersOutput[] = ['layerId' => $layerIdCounter++, 'zStart' => (float)$zStart, 'items' => $itemsInLayer];
            }
        }
        return $layersOutput;
    }
}

namespace App\Service;
if (!function_exists('App\\Service\\in_array_float_namespaced')) {
    function in_array_float_namespaced($needle, $haystack, $epsilon = 0.01): bool {
        foreach ($haystack as $value) { if (abs((float)$value - (float)$needle) < $epsilon) return true; }
        return false;
    }
}
```

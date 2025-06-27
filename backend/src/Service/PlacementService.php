```php
<?php

namespace App\Service;

use App\Model\Item;
use App\Model\Container;
use App\Model\BoxArea;
use App\Model\PlacedItem;
use App\Config\ItemTypeConfig; // For maxSupportWeight etc.

class PlacementService {
    private int $boxAreaIdCounter = 0; // For generating unique BoxArea IDs

    public function PlaceItemsInContainer(Container $container, array $itemGroupsAssigned): array {
        $placedItemsList = [];
        $unplacedItemsList = []; // Items from $itemGroupsAssigned that couldn't be placed

        // 1. Expand item groups into a list of individual items for this container
        $expandedItemList = [];
        $originalQtyIdxCounter = 1; // Sequential index for all items in this container load
        foreach ($itemGroupsAssigned as $group) {
            for ($i = 0; $i < $group->qty; $i++) {
                $individualItem = clone $group;
                $individualItem->qty = 1;
                $individualItem->originalQtyIndex = $originalQtyIdxCounter++;
                $expandedItemList[] = $individualItem;
            }
        }
        // $expandedItemList is already sorted by original group weight (heaviest first)

        // 2. Initialize availableBoxAreas
        $this->boxAreaIdCounter = 0; // Reset for each new container placement
        $initialBoxArea = new BoxArea(
            "BA_INIT_" . $this->generateBoxAreaIdSuffix(), 0, 0, 0,
            $container->width, $container->length, $container->height
        );
        $availableBoxAreas = [$initialBoxArea];

        $currentTotalWeightInContainer = 0;

        // 3. Loop through each individual item to be placed
        foreach ($expandedItemList as $itemToPlace) {
            $itemSuccessfullyPlaced = false;

            // Sort availableBoxAreas: X desc (Rightmost), Y desc (Frontmost), Z desc (Topmost), then Volume asc
            usort($availableBoxAreas, [$this, 'sortBoxAreas']);

            $bestTargetBoxAreaIndex = -1;
            $chosenOrientationDetails = null;

            foreach ($availableBoxAreas as $baIndex => $targetBoxArea) {
                if (!$targetBoxArea->isValid()) continue; // Skip tiny/invalid areas

                $orientationDetails = $this->findBestOrientationForItemInBoxArea($itemToPlace, $targetBoxArea);

                if ($orientationDetails !== null) {
                    // Payload Check
                    if (($currentTotalWeightInContainer + $itemToPlace->weight) > $container->usablePayload) {
                        continue; // Would exceed max payload for the container
                    }

                    // Stacking Check (Simplified for now, needs full implementation)
                    // Rule 1: Is targetBoxArea.z on floor (z=0) OR is item below stackable?
                    // Rule 2: Is itemToPlace lighter or equal weight to item below? (Handled by group sort for same type)
                    // Rule 3: Does item below allow itemToPlace->weight on top (maxSupportWeight)?
                    // Rule 4: Does footprint fit? (Handled by findBestOrientation)
                    // TODO: Implement detailed stacking check, needs info about item below.
                    // For now, we assume if space is available at targetBoxArea.z, it's placeable.

                    $bestTargetBoxAreaIndex = $baIndex;
                    $chosenOrientationDetails = $orientationDetails;
                    break; // Found a suitable BoxArea and orientation
                }
            }

            if ($bestTargetBoxAreaIndex !== -1) {
                $targetBoxArea = $availableBoxAreas[$bestTargetBoxAreaIndex];
                $oDims = $chosenOrientationDetails['dimensions']; // oriented W, L, H

                $placedX = $targetBoxArea->x;
                $placedY = $targetBoxArea->y;
                $placedZ = $targetBoxArea->z;

                $placedItemsList[] = new PlacedItem(
                    $itemToPlace, $itemToPlace->originalQtyIndex,
                    $placedX, $placedY, $placedZ,
                    $oDims['width'], $oDims['length'], $oDims['height']
                    // $chosenOrientationDetails['orientationIndex'] // If you want to store it
                );
                $currentTotalWeightInContainer += $itemToPlace->weight;
                $itemSuccessfullyPlaced = true;

                array_splice($availableBoxAreas, $bestTargetBoxAreaIndex, 1); // Remove consumed BoxArea

                // Split remainder of $targetBoxArea
                $newlyGeneratedAreas = $this->splitConsumedBoxArea($targetBoxArea, $oDims['width'], $oDims['length'], $oDims['height']);
                foreach ($newlyGeneratedAreas as $newArea) {
                    if ($newArea->isValid()) { // Only add valid new areas
                        $availableBoxAreas[] = $newArea;
                    }
                }
            }

            if (!$itemSuccessfullyPlaced) {
                $unplacedItemCopy = clone $itemToPlace; // Ensure we don't modify original group objects
                $unplacedItemsList[] = $unplacedItemCopy;
            }
        }

        return [
            'placedItems' => $placedItemsList,
            'unplacedItems' => $unplacedItemsList,
            'finalEmptyBoxAreas' => $availableBoxAreas
        ];
    }

    private function sortBoxAreas(BoxArea $a, BoxArea $b): int {
        // Priority: Rightmost (larger X), then Frontmost (larger Y), then Topmost (larger Z)
        // If all same, prefer smaller volume to fill tight spots first.
        if ($a->x !== $b->x) return $b->x <=> $a->x; // X descending
        if ($a->y !== $b->y) return $b->y <=> $a->y; // Y descending
        if ($a->z !== $b->z) return $b->z <=> $a->z; // Z descending
        return $a->volume <=> $b->volume;           // Volume ascending
    }

    private function findBestOrientationForItemInBoxArea(Item $item, BoxArea $boxArea): ?array {
        // Try all orientations of the item
        // For now, using simplified 2 orientations for non-tiltable, and first fit for tiltable
        // TODO: Implement full 6-way check for tiltable and possibly a "best-fit" heuristic (e.g. min wasted vol)
        for ($orientationIndex = 0; $orientationIndex < $item->getNumberOfOrientations(); $orientationIndex++) {
            $dims = $item->getOrientedDimensions($orientationIndex);
            if ($dims['width'] <= ($boxArea->width + 0.01) &&    // Add epsilon for float comparisons
                $dims['length'] <= ($boxArea->length + 0.01) &&
                $dims['height'] <= ($boxArea->height + 0.01)) {
                return ['orientationIndex' => $orientationIndex, 'dimensions' => $dims]; // First fit
            }
        }
        return null; // No orientation fits
    }

    /**
     * Splits a parent BoxArea after a block of items has been placed at its origin (x,y,z).
     * Generates new BoxAreas based on "Right, Front, Top" of the placed block.
     */
    private function splitConsumedBoxArea(BoxArea $parentArea, float $blockWidth, float $blockLength, float $blockHeight): array {
        $newAreas = [];
        $epsilon = 0.01; // To avoid creating tiny unusable spaces due to float precision

        // 1. Space to the RIGHT of the block (within parentArea)
        if (($parentArea->width - $blockWidth) > $epsilon) {
            $newAreas[] = new BoxArea(
                "BA_R_" . $this->generateBoxAreaIdSuffix(),
                $parentArea->x + $blockWidth,
                $parentArea->y,
                $parentArea->z,
                $parentArea->width - $blockWidth,
                $blockLength, // Takes the length of the block
                $blockHeight  // Takes the height of the block
            );
        }

        // 2. Space to the FRONT of the block (within parentArea)
        // This space can span the full width of the parentArea initially for this layer
        if (($parentArea->length - $blockLength) > $epsilon) {
            $newAreas[] = new BoxArea(
                "BA_F_" . $this->generateBoxAreaIdSuffix(),
                $parentArea->x,
                $parentArea->y + $blockLength,
                $parentArea->z,
                $parentArea->width, // Full width of parent for this slice
                $parentArea->length - $blockLength,
                $blockHeight // Height of the block
            );
        }

        // 3. Space on TOP of the block (within parentArea)
        if (($parentArea->height - $blockHeight) > $epsilon) {
            $newAreas[] = new BoxArea(
                "BA_T_" . $this->generateBoxAreaIdSuffix(),
                $parentArea->x,
                $parentArea->y,
                $parentArea->z + $blockHeight,
                $parentArea->width,  // Full width of parent
                $parentArea->length, // Full length of parent
                $parentArea->height - $blockHeight
            );
        }
        return $newAreas;
    }

    private function generateBoxAreaIdSuffix(): string {
        return (string) $this->boxAreaIdCounter++;
    }
}
```

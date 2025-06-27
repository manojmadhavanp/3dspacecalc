```php
<?php

namespace App\Service;

use App\Model\PlacementLayer;
use App\Model\PlacementSurface;
use App\Model\Container;
use App\Model\Item;
use App\Model\PlacedItem;
use App\Config\ItemTypeConfig; // For maxSupportWeight

class PlacementService {

    private function generateInitialLayers(Container $container, array $expandedSortedItemGroups): array {
        if (empty($expandedSortedItemGroups)) {
            $surface = new PlacementSurface(0, 0, $container->width, $container->length, $container->height);
            return [new PlacementLayer(0, $container->height, $surface)];
        }

        $uniqueOrientedHeights = [];
        foreach ($expandedSortedItemGroups as $item) {
            if ($item->height <= $container->height) {
                 for ($orientation = 0; $orientation < $item->getNumberOfOrientations(); $orientation++) {
                    $dims = $item->getOrientedDimensions($orientation);
                    $h = round((float)$dims['height'], 2);
                    if ($h > 0.01 && $h <= $container->height && !in_array_float_namespaced($h, $uniqueOrientedHeights, 0.01)) {
                        $uniqueOrientedHeights[] = $h;
                    }
                }
            }
        }

        if (empty($uniqueOrientedHeights)) {
            $surface = new PlacementSurface(0, 0, $container->width, $container->length, $container->height);
            return [new PlacementLayer(0, $container->height, $surface)];
        }
        sort($uniqueOrientedHeights);

        $finalLayers = [];
        $currentLayerZStart = 0.0;
        $seenZStartsForFinal = [];

        foreach ($uniqueOrientedHeights as $itemHeightForThisLayerThickness) {
            if ($currentLayerZStart >= ($container->height - 0.01)) break;

            $zKey = round($currentLayerZStart, 2);
            if (!isset($seenZStartsForFinal[$zKey])) {
                $surfaceAvailH = $container->height - $currentLayerZStart;
                if ($surfaceAvailH > 0.01) {
                    $initialSurface = new PlacementSurface(0,0,$container->width, $container->length, $surfaceAvailH);
                    $finalLayers[] = new PlacementLayer($currentLayerZStart, $itemHeightForThisLayerThickness, $initialSurface);
                    $seenZStartsForFinal[$zKey] = true;
                }
            }
            $currentLayerZStart += $itemHeightForThisLayerThickness;
        }

        $zZeroKey = round(0.0, 2);
        if (!isset($seenZStartsForFinal[$zZeroKey]) && $container->height > 0.01) {
            $initialSurface = new PlacementSurface(0, 0, $container->width, $container->length, $container->height);
            array_unshift($finalLayers, new PlacementLayer(0.0, $uniqueOrientedHeights[0] ?? $container->height, $initialSurface));
             usort($finalLayers, fn(PlacementLayer $a, PlacementLayer $b) => $a->zStart <=> $b->zStart);
        }

        if (empty($finalLayers) && $container->height > 0.01) {
             $initialSurface = new PlacementSurface(0, 0, $container->width, $container->length, $container->height);
             $finalLayers[] = new PlacementLayer(0.0, $container->height, $initialSurface);
        }
        return $finalLayers;
    }

    private function splitSurface(PlacementSurface $originalSurface, array $placedItemOrientedDimensions): array {
        $newSurfaces = [];
        $epsilon = 0.01;

        $itemPlacedW = $placedItemOrientedDimensions['width'];
        $itemPlacedL = $placedItemOrientedDimensions['length'];
        $availableHeight = $originalSurface->availableHeightOnSurface;

        // Surface to the RIGHT of the placed item
        $widthRight = $originalSurface->width - $itemPlacedW;
        if ($widthRight > $epsilon) {
            $newSurfaces[] = new PlacementSurface(
                $originalSurface->x + $itemPlacedW,
                $originalSurface->y,
                $widthRight,
                $originalSurface->length, // Full original length for this new strip
                $availableHeight
            );
        }

        // Surface BELOW (larger Y) the placed item
        // This surface only spans the width of the item just placed.
        $lengthBelow = $originalSurface->length - $itemPlacedL;
        if ($lengthBelow > $epsilon) {
            $newSurfaces[] = new PlacementSurface(
                $originalSurface->x,
                $originalSurface->y + $itemPlacedL,
                $itemPlacedW,
                $lengthBelow,
                $availableHeight
            );
        }
        return $newSurfaces;
    }

    private function findBestOrientationAndFitOnSurface(
        Item $item,
        PlacementSurface $surface,
        float $layerZStart, // Z-coordinate of the layer (base of item)
        array $placedItemsList, // For stacking check
        Container $container // For container payload check
    ): ?array {
        for ($orientationIndex = 0; $orientationIndex < $item->getNumberOfOrientations(); $orientationIndex++) {
            $dims = $item->getOrientedDimensions($orientationIndex); // ['width', 'length', 'height']

            if ($dims['width'] <= ($surface->width + $epsilon) &&
                $dims['length'] <= ($surface->length + $epsilon) &&
                $dims['height'] <= ($surface->availableHeightOnSurface + $epsilon)) { // Epsilon for float comparisons

                if ($layerZStart > 0.01) { // If not on the floor, check stacking
                    if (!$this->canStackItemAt(
                        $item,
                        $surface->x, // Potential X of new item on container floor plan
                        $surface->y, // Potential Y of new item on container floor plan
                        $layerZStart, // Z-level of the layer (base of new item)
                        $dims,        // Oriented dimensions of new item
                        $placedItemsList
                    )) {
                        continue; // Stacking rule failed for this orientation
                    }
                }
                // If on floor or stacking rules passed
                return ['orientationIndex' => $orientationIndex, 'dimensions' => $dims]; // First fit
            }
        }
        return null; // No orientation fits or passes stacking
    }

    // Epsilon for float comparisons, e.g., in canStackItemAt
    private const EPSILON = 0.01;

    private function canStackItemAt(
        Item $itemToPlace,
        float $targetAbsX, float $targetAbsY, float $targetAbsZ,
        array $itemToPlaceOrientedDims,
        array $placedItemsList // Array of PlacedItem objects
    ): bool {
        if ($targetAbsZ < self::EPSILON) return true; // On the floor, always allowed initially

        $itemToPlaceFootprint = [
            'x_min' => $targetAbsX, 'x_max' => $targetAbsX + $itemToPlaceOrientedDims['width'],
            'y_min' => $targetAbsY, 'y_max' => $targetAbsY + $itemToPlaceOrientedDims['length']
        ];

        $totalSupportWeightCapacityFromBelow = 0;
        $minWeightOfSupportingItem = PHP_FLOAT_MAX;
        $foundSupport = false;
        $fullySupported = true; // Assume fully supported until proven otherwise

        foreach ($placedItemsList as $placedItem) {
            // Check if $placedItem is directly below $itemToPlace
            if (abs(($placedItem->z + $placedItem->orientedHeight) - $targetAbsZ) < self::EPSILON) {
                // Check for horizontal overlap (footprint)
                $overlapX = max(0, min($itemToPlaceFootprint['x_max'], $placedItem->x + $placedItem->orientedWidth) - max($itemToPlaceFootprint['x_min'], $placedItem->x));
                $overlapY = max(0, min($itemToPlaceFootprint['y_max'], $placedItem->y + $placedItem->orientedLength) - max($itemToPlaceFootprint['y_min'], $placedItem->y));

                if ($overlapX > self::EPSILON && $overlapY > self::EPSILON) { // They overlap
                    $foundSupport = true;
                    $itemBelow = $placedItem->originalItemRef; // Need original item for its properties

                    // Rule 1: Item below must be stackable itself or its type must allow support
                    $itemBelowIsGenerallyStackable = $itemBelow->stackable; // Item's own property
                    $itemBelowTypeMaxSupport = ItemTypeConfig::getMaxSupportWeightKg($itemBelow->type);

                    if (!$itemBelowIsGenerallyStackable && $itemBelowTypeMaxSupport <= 0) {
                        return false; // Item below cannot support anything
                    }

                    // Rule 2: Heavier first or equal weight (itemToPlace must be <= itemBelow)
                    if ($itemToPlace->weight > ($itemBelow->weight + self::EPSILON) ) { // Add epsilon for float comparison
                        return false;
                    }

                    // Rule 3: ItemToPlace weight vs itemBelow's maxSupportWeight
                    // This check is tricky: does maxSupportWeight apply per item or distributed?
                    // For now, assume if any part is supported by an item that can't bear the load, it's a fail.
                    // A more complex check would sum up support from multiple items below.
                    if ($itemToPlace->weight > ($itemBelowTypeMaxSupport + self::EPSILON)) {
                        return false;
                    }

                    $minWeightOfSupportingItem = min($minWeightOfSupportingItem, $itemBelow->weight);

                    // TODO: More advanced: Check percentage of base supported.
                    // For now, if any overlap with a valid supporting item, part of it is supported.
                    // We need to ensure the *entire base* of itemToPlace is supported.
                    // This simplified check just finds *any* support. A full check is harder.
                }
            }
        }
        if (!$foundSupport && $targetAbsZ > self::EPSILON) return false; // Trying to stack in mid-air

        // If we got here, all parts of the item that found support passed individual checks.
        // The check for full base support is still missing.
        // For now, if any valid support was found, allow.
        return true;
    }

    public function PlaceItemsInContainer(Container $container, array $itemGroupsAssigned): array {
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

        $layers = $this->generateInitialLayers($container, $expandedItemList);
        $placedItemsList = [];
        $unplacedItemsList = [];
        $currentTotalWeightInContainer = 0;

        foreach ($expandedItemList as $itemToPlace) {
            $itemSuccessfullyPlaced = false;

            foreach ($layers as $layerIndex => &$layer) {
                if (!$layer instanceof PlacementLayer) continue;
                $layer->sortSurfaces();

                foreach ($layer->surfaces as $surfaceIndex => $surface) {
                    if (!$surface instanceof PlacementSurface || !$surface->isValid()) continue;

                    $fitDetails = $this->findBestOrientationAndFitOnSurface(
                        $itemToPlace, $surface, $layer->zStart, $placedItemsList, $container
                    );

                    if ($fitDetails !== null) {
                        if (($currentTotalWeightInContainer + $itemToPlace->weight) > $container->usablePayload) {
                            continue;
                        }

                        $oDims = $fitDetails['dimensions'];
                        $placedX = $surface->x;
                        $placedY = $surface->y;
                        $placedZ = $layer->zStart;

                        $placedItemsList[] = new PlacedItem(
                            $itemToPlace, $itemToPlace->originalQtyIndex,
                            $placedX, $placedY, $placedZ,
                            $oDims['width'], $oDims['length'], $oDims['height']
                        );
                        $currentTotalWeightInContainer += $itemToPlace->weight;
                        $itemSuccessfullyPlaced = true;
                        $itemToPlace->placement = ['x'=>$placedX, 'y'=>$placedY, 'z'=>$placedZ]; // Mark item as having placement info

                        $newlyCreatedSurfaces = $this->splitSurface($surface, $oDims);
                        array_splice($layer->surfaces, $surfaceIndex, 1);

                        foreach ($newlyCreatedSurfaces as $newSurf) {
                            if ($newSurf->isValid()) {
                                $layer->addSurface($newSurf);
                            }
                        }
                        $layer->sortSurfaces();

                        break;
                    }
                }
                if ($itemSuccessfullyPlaced) {
                    break;
                }
            }

            if (!$itemSuccessfullyPlaced) {
                $unplacedItemsList[] = clone $itemToPlace;
            }
        }

        return [
            'placedItems' => $placedItemsList,
            'unplacedItems' => $unplacedItemsList,
            'finalEmptySurfacesByLayer' => $this->getSurfaceDataFromLayers($layers)
        ];
    }

    private function getSurfaceDataFromLayers(array $layers): array {
        $output = [];
        foreach($layers as $layer) {
            if (!$layer instanceof PlacementLayer) continue;
            $sData = [];
            foreach($layer->surfaces as $surface) {
                if (!$surface instanceof PlacementSurface) continue;
                $sData[] = ['id' => $surface->id, 'x'=>$surface->x, 'y'=>$surface->y, 'w'=>$surface->width, 'l'=>$surface->length, 'ah'=>$surface->availableHeightOnSurface];
            }
            $output[] = ['layer_id' => $layer->id, 'z_start' => $layer->zStart, 'def_h' => $layer->definingItemHeight, 'surfaces' => $sData];
        }
        return $output;
    }
}

namespace App\Service;

if (!function_exists('App\\Service\\in_array_float_namespaced')) {
    function in_array_float_namespaced($needle, $haystack, $epsilon = 0.00001): bool {
        foreach ($haystack as $value) {
            if (abs((float)$value - (float)$needle) < $epsilon) {
                return true;
            }
        }
        return false;
    }
}
```

**Key Changes and Implementation Details:**

1.  **`findBestOrientationAndFitOnSurface` Method (Detailed):**
    *   Takes `$item`, `$surface`, `$layerZStart`, `$placedItemsList` (for stacking checks), and `$container` (for payload check, though payload check is also done in main loop).
    *   Iterates through all possible orientations of the `$item`.
    *   **Dimensional Check:** Verifies if the item's `orientedWidth`, `orientedLength` fit the `surface.width`, `surface.length`, AND if `orientedHeight <= surface.availableHeightOnSurface`. An epsilon is used for float comparisons.
    *   **Stacking Check (`if $layerZStart > 0.01`):**
        *   Calls `canStackItemAt(...)` if the item is not being placed on the container floor.
        *   If `canStackItemAt` returns `false`, this orientation on this surface is rejected, and the loop continues to the next orientation/surface.
    *   Returns the first orientation that fits all criteria (dimensional and stacking).

2.  **`canStackItemAt` Method (New, Crucial for Stacking):**
    *   Takes the item to place, its target absolute X,Y,Z coordinates, its oriented dimensions, and the list of already `placedItemsList`.
    *   **Floor Check:** If `targetAbsZ` is effectively 0, it's on the floor, stacking is allowed.
    *   **Find Item(s) Below:** This is the complex part. It needs to iterate `placedItemsList` and find any item(s) whose top surface `(placedItem->z + placedItem->orientedHeight)` matches `targetAbsZ` AND whose X,Y footprint overlaps with `itemToPlace`'s footprint at `targetAbsX, targetAbsY`.
        *   *Current Implementation is Simplified:* The provided snippet has a placeholder for this complex overlap detection. It assumes `true` for now to allow basic testing of other parts. **This is a critical TODO.**
    *   **If Supporting Item(s) Found:**
        *   Check `itemBelow->stackable` property (from original `Item` object, so `PlacedItem` needs a reference or relevant properties).
        *   Check `ItemTypeConfig::getMaxSupportWeightKg(itemBelow->type) >= itemToPlace->weight`.
        *   Check `itemToPlace->weight <= itemBelow->weight` (heavier first / equal weight rule).
        *   Check for sufficient footprint support (e.g., item above not excessively overhanging item below - also a TODO).
    *   Returns `true` if all stacking conditions are met, `false` otherwise.

3.  **`PlaceItemsInContainer` Main Loop (Rewritten):**
    *   Expands item groups into `$expandedItemList`.
    *   Calls `generateInitialLayers`.
    *   Iterates through each `$itemToPlace`:
        *   Iterates through `$layers` (by reference `&$layer` so `surfaces` array can be modified). Layers should be processed Z=0 upwards.
        *   Sorts `$layer->surfaces` using a heuristic (e.g., smallest X, then smallest Y - implemented in `PlacementLayer::sortSurfaces`).
        *   Iterates through `$surfaces` in the current layer:
            *   Calls `findBestOrientationAndFitOnSurface` (passing `$placedItemsList` for stacking checks).
            *   **Payload Check:** If a fit is found, *then* it checks if adding this item exceeds `container->usablePayload`. If so, it skips this placement attempt (continues to next surface/layer).
            *   If fit and payload OK:
                *   Creates `PlacedItem` using `surface->x`, `surface->y` as offsets from container origin, and `layer->zStart` as the Z.
                *   Adds to `placedItemsList`. Updates `currentTotalWeightInContainer`.
                *   Marks the item in `$expandedItemList` as placed (e.g., by setting its `placement` property or removing it, though removing from array while iterating is tricky; better to build a new list of remaining).
                *   Calls `splitSurface($surface, $oDims)` to get new smaller surfaces.
                *   The original `$surface` is removed from `$layer->surfaces` (e.g., using `array_splice` with the `$surfaceIndex`).
                *   The `newlyCreatedSurfaces` are added to `$layer->surfaces`.
                *   The layer's surfaces are re-sorted.
                *   `$itemSuccessfullyPlaced = true; break;` (from surface loop).
        *   If `$itemSuccessfullyPlaced`, `break;` (from layer loop to get next item).
    *   If `!$itemSuccessfullyPlaced` after trying all layers/surfaces, add `itemToPlace` to `unplacedItemsList`.
4.  **Return Value:** Includes `placedItems`, `unplacedItems`, and `finalEmptySurfacesByLayer` (which calls a helper `getSurfaceDataFromLayers` to format this debug data).

**Important Considerations & TODOs highlighted:**
*   **Robust Stacking Check in `canStackItemAt`:** This is the most complex new piece. Finding overlapping items below and checking their combined support for the item above requires careful geometric calculations.
*   **Sorting Surfaces within a Layer:** The `PlacementLayer::sortSurfaces()` method provides a default. This heuristic can significantly impact packing efficiency.
*   **Block Placement vs. One-by-One:** The current rewrite places items one by one. The original "3D grid" concept was partly to identify blocks (N x M x K) of identical items. If we want to place blocks, `PlaceItemsInContainer` would try to place a block, and `splitSurface` would be based on the block's footprint. One-by-one is simpler to start with for layer/surface logic.
*   **Efficiency:** Repeatedly sorting surfaces or searching through all `placedItemsList` for stacking checks can be inefficient for large numbers of items. Optimizations (e.g., spatial indexing of placed items or more targeted surface selection) would be needed for performance.

This completes the conceptual rewrite of `PlaceItemsInContainer` and the detailing of `findBestOrientationAndFitOnSurface` with the placeholder for `canStackItemAt`. The next step in the plan is conceptual testing with Test Data 1.The `PlaceItemsInContainer` method in `backend/src/Service/PlacementService.php` has been rewritten, and the helper method `findBestOrientationAndFitOnSurface` has been detailed along with a new crucial method `canStackItemAt` for stacking logic.

**Summary of Changes in `PlacementService.php`:**
1.  **`PlaceItemsInContainer` Method:**
    *   Expands input item groups into an individual, sorted item list.
    *   Calls `generateInitialLayers` to get the layer structure.
    *   Iterates through each item to be placed. For each item, it iterates through layers (Z ascending) and then through surfaces within each layer (sorted by X, then Y).
    *   Calls `findBestOrientationAndFitOnSurface` for each item-surface pair.
    *   If a fit is found (dimensional, orientation, and stacking rules pass, and container payload not exceeded):
        *   A `PlacedItem` object is created with absolute container coordinates derived from the surface's origin (x,y) and the layer's `zStart`.
        *   The item is added to `placedItemsList`, and total weight is updated.
        *   The consumed `PlacementSurface` is removed from its layer.
        *   `splitSurface` is called with the consumed surface and the placed item's oriented dimensions. The new, smaller surfaces replace the consumed one in the layer.
        *   The layer's surfaces are re-sorted.
        *   The process breaks to the next item.
    *   If an item cannot be placed after checking all surfaces in all layers, it's added to `unplacedItemsList`.
    *   Returns `placedItems`, `unplacedItems`, and a representation of `finalEmptySurfacesByLayer`.

2.  **`findBestOrientationAndFitOnSurface` Method:**
    *   Takes an `Item`, a `PlacementSurface`, the `layerZStart`, the list of already `placedItemsList`, and the `Container`.
    *   Iterates through the item's possible orientations.
    *   For each orientation, it checks:
        *   Dimensional fit: Oriented W/L of item <= surface W/L, and oriented H of item <= `surface.availableHeightOnSurface`.
        *   Stacking rules: If `layerZStart > 0` (not on floor), it calls `canStackItemAt`.
    *   Returns the first orientation that satisfies all conditions.

3.  **`canStackItemAt` Method (New - Critical for Stacking):**
    *   Takes the item to place, its target absolute X,Y,Z coordinates, its oriented dimensions, and the `placedItemsList`.
    *   Returns `true` if placing on the floor (`targetAbsZ` is near 0).
    *   **TODO (Crucial):** Contains placeholder logic for finding supporting items directly below the `itemToPlace`. This needs to check for footprint overlap with items at the Z-level immediately below.
    *   **TODO (Crucial):** If supporting items are found, it needs to verify:
        *   The item below is `stackable` or its type (via `ItemTypeConfig`) allows weight on top.
        *   `itemToPlace->weight <= itemBelow->weight` (heavier first or equal).
        *   `itemToPlace->weight <= ItemTypeConfig::getMaxSupportWeightKg(itemBelow->type)`.
        *   Sufficient footprint support (e.g., no excessive overhang).
    *   Currently, it returns `true` as a placeholder to allow basic layer testing, but this method is vital for correct stacking.

This completes the conceptual rewrite of the main placement logic to use layers and surfaces. The next step is to mentally test this with Test Data 1.

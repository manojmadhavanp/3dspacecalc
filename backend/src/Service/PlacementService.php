```php
<?php

namespace App\Service;

use App\Model\Container;
use App\Model\Item;
use App\Model\BoxArea;
use App\Model\PlacedItem;
use App\Config\ItemTypeConfig;

class PlacementService {
    private int $boxAreaIdCounter = 0;
    private const EPSILON = 0.01;
    private const MIN_SUPPORT_PERCENTAGE_RIGID = 0.75;
    private const MIN_SUPPORT_PERCENTAGE_DEFORMABLE = 0.50;

    public function PlaceItemsInContainer(Container $container, array $itemGroupsAssigned): array {
        $placedItemsList = []; $unplacedItemsList = [];
        $expandedItemList = []; $originalQtyIdxCounter = 1;
        foreach ($itemGroupsAssigned as $group) {
            for ($i = 0; $i < $group->qty; $i++) {
                $individualItem = clone $group; $individualItem->qty = 1;
                $individualItem->originalQtyIndex = $originalQtyIdxCounter++;
                $expandedItemList[] = $individualItem;
            }
        }
        BoxArea::resetIdCounter();
        $initialBoxArea = new BoxArea("INIT",0,0,0, $container->width, $container->length, $container->height);
        $availableBoxAreas = [$initialBoxArea];
        $currentTotalWeightInContainer = 0;

        foreach ($expandedItemList as $itemToPlace) {
            $itemSuccessfullyPlaced = false;
            $itemToPlace->placementFailureReason = "No suitable BoxArea or orientation found after all checks.";
            usort($availableBoxAreas, [$this, 'sortBoxAreas']);
            $bestTargetBoxAreaIndex = -1; $chosenOrientationDetails = null;

            foreach ($availableBoxAreas as $baIndex => $targetBoxArea) {
                if (!$targetBoxArea->isValid()) continue;
                $orientationDetails = $this->findBestOrientationForItemIn3DBoxArea($itemToPlace, $targetBoxArea, $placedItemsList);
                if ($orientationDetails !== null) {
                    if (($currentTotalWeightInContainer + $itemToPlace->weight) > ($container->usablePayload + self::EPSILON)) {
                        $itemToPlace->placementFailureReason = "Placement would exceed container payload limit ({$container->usablePayload}kg). Item weight: {$itemToPlace->weight}kg, Current load: {$currentTotalWeightInContainer}kg.";
                        continue;
                    }
                    $bestTargetBoxAreaIndex = $baIndex; $chosenOrientationDetails = $orientationDetails; break;
                }
            }
            if ($bestTargetBoxAreaIndex !== -1) {
                $targetBoxArea = $availableBoxAreas[$bestTargetBoxAreaIndex];
                $oDims = $chosenOrientationDetails['dimensions'];
                $placedX = $targetBoxArea->x; $placedY = $targetBoxArea->y; $placedZ = $targetBoxArea->z;
                $newlyPlacedItem = new PlacedItem($itemToPlace, $itemToPlace->originalQtyIndex, $placedX, $placedY, $placedZ, $oDims['width'], $oDims['length'], $oDims['height']);
                $placedItemsList[] = $newlyPlacedItem;
                $currentTotalWeightInContainer += $itemToPlace->weight; $itemSuccessfullyPlaced = true;
                array_splice($availableBoxAreas, $bestTargetBoxAreaIndex, 1);
                $newlyGeneratedAreas = $this->splitConsumed3DBoxArea($targetBoxArea, $oDims['width'], $oDims['length'], $oDims['height']);
                foreach ($newlyGeneratedAreas as $newArea) { if ($newArea->isValid()) { $availableBoxAreas[] = $newArea; }}
            }
            if (!$itemSuccessfullyPlaced) { $unplacedItemsList[] = clone $itemToPlace; }
        }
        $layersData = $this->groupPlacedItemsIntoLayers($placedItemsList, $container);
        return ['placedItems' => $placedItemsList, 'unplacedItems' => $unplacedItemsList, 'finalEmptyBoxAreas' => array_map(fn(BoxArea $ba) => $ba->toArray(), $availableBoxAreas), 'layers' => $layersData ];
    }

    private function sortBoxAreas(BoxArea $a, BoxArea $b): int { /* ... as before: Z asc, Y asc, X asc, Vol desc ... */
        if (abs($a->z - $b->z) > self::EPSILON) return $a->z <=> $b->z;
        if (abs($a->y - $b->y) > self::EPSILON) return $a->y <=> $b->y;
        if (abs($a->x - $b->x) > self::EPSILON) return $a->x <=> $b->x;
        return $b->volume <=> $a->volume;
    }

    private function findBestOrientationForItemIn3DBoxArea(Item $item, BoxArea $boxArea, array $placedItemsList): ?array {
        for ($orientationIndex = 0; $orientationIndex < $item->getNumberOfOrientations(); $orientationIndex++) {
            $dims = $item->getOrientedDimensions($orientationIndex);
            if ($dims['width'] <= ($boxArea->width + self::EPSILON) && $dims['length'] <= ($boxArea->length + self::EPSILON) && $dims['height'] <= ($boxArea->height + self::EPSILON)) {
                if ($boxArea->z > self::EPSILON) {
                    $canStackResult = $this->canStackItemIn3DBoxArea($item, $boxArea->x, $boxArea->y, $boxArea->z, $dims, $placedItemsList);
                    if (!$canStackResult['allowed']) { $item->placementFailureReason = $canStackResult['reason']; continue; }
                }
                $item->placementFailureReason = null; return ['orientationIndex' => $orientationIndex, 'dimensions' => $dims];
            }
        }
        $item->placementFailureReason = "Item dimensions ({$item->name}, any orientation) exceed dimensions of BoxArea {$boxArea->id} (W:{$boxArea->width}, L:{$boxArea->length}, H:{$boxArea->height}).";
        return null;
    }

    private function canStackItemIn3DBoxArea(Item $itemToPlace, float $targetAbsX, float $targetAbsY, float $targetAbsZ, array $itemToPlaceOrientedDims, array $placedItemsList): array {
        if ($targetAbsZ < self::EPSILON) return ['allowed' => true, 'reason' => 'On floor.'];

        $newItemMinX = $targetAbsX; $newItemMaxX = $targetAbsX + $itemToPlaceOrientedDims['width'];
        $newItemMinY = $targetAbsY; $newItemMaxY = $targetAbsY + $itemToPlaceOrientedDims['length'];
        $newItemBaseArea = $itemToPlaceOrientedDims['width'] * $itemToPlaceOrientedDims['length'];
        if ($newItemBaseArea < self::EPSILON) return ['allowed' => true, 'reason' => 'Zero base area item.'];

        $corners = [
            ['x' => $newItemMinX + self::EPSILON, 'y' => $newItemMinY + self::EPSILON], ['x' => $newItemMaxX - self::EPSILON, 'y' => $newItemMinY + self::EPSILON],
            ['x' => $newItemMinX + self::EPSILON, 'y' => $newItemMaxY - self::EPSILON], ['x' => $newItemMaxX - self::EPSILON, 'y' => $newItemMaxY - self::EPSILON]
        ];
        $cornerSupportStatus = [false, false, false, false];
        $foundAnyValidSupportItem = false;
        $accumulatedSupportAreaFromValidSupporters = 0;

        foreach ($placedItemsList as $pItem) {
            if (!$pItem instanceof PlacedItem || $pItem->originalItemRef === null) continue;
            $itemBelow = $pItem->originalItemRef;

            if (abs(($pItem->z + $pItem->orientedHeight) - $targetAbsZ) < self::EPSILON) {
                $pItemMinX = $pItem->x; $pItemMaxX = $pItem->x + $pItem->orientedWidth;
                $pItemMinY = $pItem->y; $pItemMaxY = $pItem->y + $pItem->orientedLength;
                $overlapMinX = max($newItemMinX, $pItemMinX); $overlapMaxX = min($newItemMaxX, $pItemMaxX);
                $overlapMinY = max($newItemMinY, $pItemMinY); $overlapMaxY = min($newItemMaxY, $pItemMaxY);
                $overlapWidth = $overlapMaxX - $overlapMinX; $overlapLength = $overlapMaxY - $overlapMinY;

                if ($overlapWidth > self::EPSILON && $overlapLength > self::EPSILON) {
                    $isCurrentSupporterValid = true; $currentFailReason = '';
                    $itemBelowType = $itemBelow->type;
                    $itemBelowOrientedHeight = $pItem->orientedHeight; // Height of the item below as it was placed

                    // Determine effective max support weight for itemBelow
                    $effectiveMaxSupportKgItemBelow = $itemBelow->maxSupportWeightKgOverride ?? ItemTypeConfig::getMaxSupportWeightKg($itemBelowType);

                    // Check if itemBelow is a barrel and if it's on its side
                    $isItemBelowBarrelOnSide = false;
                    if ($itemBelowType === 'barels' && ItemTypeConfig::getProperty($itemBelowType, 'isCylindrical', false)) {
                        // If oriented height is diameter (original width/length) and oriented W/L is original height
                        if ( (abs($itemBelowOrientedHeight - $itemBelow->width) < self::EPSILON || abs($itemBelowOrientedHeight - $itemBelow->length) < self::EPSILON) &&
                             (abs($pItem->orientedLength - $itemBelow->height) < self::EPSILON || abs($pItem->orientedWidth - $itemBelow->height) < self::EPSILON ) ) {
                            $isItemBelowBarrelOnSide = true;
                        }
                    }

                    if ($isItemBelowBarrelOnSide) {
                        $allowsStacking = ItemTypeConfig::getProperty($itemBelowType, 'allowsDirectStackingOnTop', false); // Should be false for barrel on side from config usually
                        $effectiveMaxSupportKgItemBelow = ItemTypeConfig::getProperty($itemBelowType, 'maxSupportOnSideKg', 5); // Use specific side support
                        if (!$allowsStacking && !($itemToPlace->type === $itemBelowType && ItemTypeConfig::getProperty($itemBelowType, 'allowsStackingOfSameType', false))) {
                             $isCurrentSupporterValid = false; $currentFailReason = "Item type below ('{$itemBelowType}' on side) does not allow general items on top.";
                        }
                    } else { // Not a barrel on side, or not a barrel
                        $itemBelowAllowsGeneralStacking = ItemTypeConfig::getProperty($itemBelowType, 'allowsDirectStackingOnTop', ItemTypeConfig::getProperty('defaultAllowsDirectStackingOnTop'));
                        $itemBelowAllowsSameTypeStacking = ItemTypeConfig::getProperty($itemBelowType, 'allowsStackingOfSameType', ItemTypeConfig::getProperty('defaultAllowsStackingOfSameType'));
                        if ($itemToPlace->type === $itemBelowType) {
                            if (!$itemBelowAllowsSameTypeStacking) { $isCurrentSupporterValid = false; $currentFailReason = "Item type below ('{$itemBelowType}') does not allow stacking of its own type.";}
                            // ... (maxStackHeightUnits check from previous version for deformables) ...
                            $isItemToPlaceDeformableCheck = ItemTypeConfig::getProperty($itemToPlace->type, 'isDeformable', ItemTypeConfig::getProperty('defaultIsDeformable'));
                            if ($isItemToPlaceDeformableCheck && $isCurrentSupporterValid) {
                                $maxUnits = ItemTypeConfig::getProperty($itemToPlace->type, 'maxStackHeightUnits', ItemTypeConfig::getProperty('defaultMaxStackHeightUnits'));
                                if ($maxUnits !== null) {
                                    $currentStackCount = $this->countItemsStackedAt($targetAbsX, $targetAbsY, $targetAbsZ, $itemToPlace->type, $placedItemsList, $itemToPlaceOrientedDims) + 1;
                                    if ($currentStackCount > $maxUnits) { $isCurrentSupporterValid = false; $currentFailReason = "Stacking limit of {$maxUnits} units for type '{$itemToPlace->type}' reached.";}
                                }}}}}
                    else { // Different types
                        if (!$itemBelowAllowsGeneralStacking) { $isCurrentSupporterValid = false; $currentFailReason = "Item type below ('{$itemBelowType}') does not allow different item types ('{$itemToPlace->type}') on top.";}
                    }

                    if ($isCurrentSupporterValid && !$itemBelow->stackable && $effectiveMaxSupportKgItemBelow < self::EPSILON) {
                        $isCurrentSupporterValid = false; $currentFailReason = "Item below '{$itemBelow->name}' is not flagged stackable and its type/override provides no specific weight support.";
                    }
                    if ($isCurrentSupporterValid && $itemToPlace->weight > ($itemBelow->weight + self::EPSILON)) {
                        $isCurrentSupporterValid = false; $currentFailReason = "Item '{$itemToPlace->name}' ({$itemToPlace->weight}kg) is heavier than item below '{$itemBelow->name}' ({$itemBelow->weight}kg).";
                    }
                    if ($isCurrentSupporterValid && $effectiveMaxSupportKgItemBelow > self::EPSILON && $itemToPlace->weight > ($effectiveMaxSupportKgItemBelow + self::EPSILON)) {
                        $isCurrentSupporterValid = false; $currentFailReason = "Item '{$itemToPlace->name}' ({$itemToPlace->weight}kg) exceeds max support ({$effectiveMaxSupportKgItemBelow}kg) of item below '{$itemBelow->name}'.";
                    }

                    if ($isCurrentSupporterValid) {
                        $foundAnyValidSupportItem = true; $accumulatedSupportAreaFromValidSupporters += ($overlapWidth * $overlapLength);
                        for ($i = 0; $i < 4; $i++) {
                            if (!$cornerSupportStatus[$i]) {
                                if ($corners[$i]['x'] >= ($pItemMinX - self::EPSILON) && $corners[$i]['x'] <= ($pItemMaxX + self::EPSILON) &&
                                    $corners[$i]['y'] >= ($pItemMinY - self::EPSILON) && $corners[$i]['y'] <= ($pItemMaxY + self::EPSILON)) {
                                    $cornerSupportStatus[$i] = true;
                                }}}}}}}
        if ($targetAbsZ > self::EPSILON && !$foundAnyValidSupportItem) {
            return ['allowed' => false, 'reason' => "Stacking failed: No valid supporting item found directly below at Z=".round($targetAbsZ,1)."."];
        }
        $isItemToPlaceDeformableCheck = ItemTypeConfig::getProperty($itemToPlace->type, 'isDeformable', ItemTypeConfig::getProperty('defaultIsDeformable'));
        if ($targetAbsZ > self::EPSILON) {
            $minSupportPercentage = $isItemToPlaceDeformableCheck ? self::MIN_SUPPORT_PERCENTAGE_DEFORMABLE : self::MIN_SUPPORT_PERCENTAGE_RIGID;
            $minRequiredSupportArea = $newItemBaseArea * $minSupportPercentage;
            if ($accumulatedSupportAreaFromValidSupporters < ($minRequiredSupportArea - self::EPSILON)) {
                return ['allowed' => false, 'reason' => "Stacking failed: Item '{$itemToPlace->name}' has insufficient support area. Found: ".round($accumulatedSupportAreaFromValidSupporters,1).", Requires: ".round($minRequiredSupportArea,1)." (".($minSupportPercentage*100)."% of item base)."];
            }
            if (!$isItemToPlaceDeformableCheck) {
                $allCornersSupported = true; foreach ($cornerSupportStatus as $isSupported) { if (!$isSupported) { $allCornersSupported = false; break; }}
                if (!$allCornersSupported) {
                    return ['allowed' => false, 'reason' => "Stacking failed: Not all base corners of rigid item '{$itemToPlace->name}' are supported by valid items below."];
                }}}}
        return ['allowed' => true, 'reason' => 'Stacking permitted.'];
    }

    private function countItemsStackedAt(float $targetX, float $targetY, float $currentZ, string $itemType, array $placedItemsList, array $itemToPlaceOrientedDims): int {
        $count = 0; $nextZToCheck = $currentZ;
        // This needs the dimensions of itemToPlace to check consistent footprint alignment below.
        $itemW = $itemToPlaceOrientedDims['width']; $itemL = $itemToPlaceOrientedDims['length'];

        while ($nextZToCheck > self::EPSILON) {
            $foundItemDirectlyBelowInStack = false;
            foreach ($placedItemsList as $pItem) {
                if ($pItem->originalItemRef && $pItem->originalItemRef->type === $itemType &&
                    abs(($pItem->z + $pItem->orientedHeight) - $nextZToCheck) < self::EPSILON) {
                    // Check if pItem's X,Y aligns with targetX, targetY and has same W,L (for a stable stack)
                    if (abs($pItem->x - $targetX) < self::EPSILON && abs($pItem->y - $targetY) < self::EPSILON &&
                        abs($pItem->orientedWidth - $itemW) < self::EPSILON && abs($pItem->orientedLength - $itemL) < self::EPSILON) {
                        $count++; $nextZToCheck = $pItem->z; $foundItemDirectlyBelowInStack = true; break;
                    }}}}
            if (!$foundItemDirectlyBelowInStack) break;
        }
        return $count;
    }

    private function splitConsumed3DBoxArea(BoxArea $parentArea, float $blockWidth, float $blockLength, float $blockHeight): array { /* ... as before ... */
        $newAreas = [];
        if (($parentArea->height - $blockHeight) > self::EPSILON) {
            $newAreas[] = new BoxArea("T_". $this->generateBoxAreaIdSuffix(), $parentArea->x, $parentArea->y, $parentArea->z + $blockHeight, $parentArea->width, $parentArea->length, $parentArea->height - $blockHeight);
        }
        if (($parentArea->length - $blockLength) > self::EPSILON) {
            $newAreas[] = new BoxArea("F_". $this->generateBoxAreaIdSuffix(), $parentArea->x, $parentArea->y + $blockLength, $parentArea->z, $parentArea->width, $parentArea->length - $blockLength, $blockHeight);
        }
        if (($parentArea->width - $blockWidth) > self::EPSILON) {
            $newAreas[] = new BoxArea("R_". $this->generateBoxAreaIdSuffix(), $parentArea->x + $blockWidth, $parentArea->y, $parentArea->z, $parentArea->width - $blockWidth, $blockLength, $blockHeight);
        }
        return $newAreas;
    }

    private function generateBoxAreaIdSuffix(): string { /* ... as before ... */ return (string) $this->boxAreaIdCounter++; }
    private function groupPlacedItemsIntoLayers(array $placedItemsList, Container $container): array { /* ... as before ... */
        if (empty($placedItemsList)) return []; $uniqueZStartsMap = [];
        foreach ($placedItemsList as $placedItem) {
            if ($placedItem instanceof PlacedItem) {
                $zRounded = round($placedItem->z, 2); $uniqueZStartsMap[(string)$zRounded] = $zRounded;
            }
        }
        if(empty($uniqueZStartsMap)) return []; $uniqueZStarts = array_values($uniqueZStartsMap); sort($uniqueZStarts);
        $layersOutput = []; $layerIdCounter = 1;
        foreach ($uniqueZStarts as $zStart) {
            $itemsInLayer = [];
            foreach ($placedItemsList as $placedItem) {
                 if ($placedItem instanceof PlacedItem) {
                    if (abs($placedItem->z - $zStart) < self::EPSILON) {
                        $itemsInLayer[] = $placedItem->toArrayV4();
                    }}}}
            if (!empty($itemsInLayer)) { $layersOutput[] = ['layerId' => $layerIdCounter++, 'zStart' => (float)$zStart, 'items' => $itemsInLayer]; }
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

**Summary of Changes in `PlacementService.php` for Barrels:**

1.  **`canStackItemIn3DBoxArea()` Modified:**
    *   **Identify Barrel Orientation:** When `itemBelow->type === 'barels'`, it now attempts to determine if the placed barrel (`$pItem`) is on its side or standing on end.
        *   It does this by comparing the `orientedHeight` of the placed barrel (`$pItem->orientedHeight`) with its original dimensions (`$itemBelow->width`, `$itemBelow->length`, `$itemBelow->height`).
        *   If `orientedHeight` matches original diameter (i.e., `itemBelow->width` or `itemBelow->length`, assuming diameter was input as W/L for stand_on_end), and `orientedLength/Width` matches original barrel height, it's considered "on its side" (`$isItemBelowBarrelOnSide = true`).
    *   **Apply Different Rules based on Barrel Orientation:**
        *   **If `itemBelow` is a barrel on its side:**
            *   It uses `ItemTypeConfig::getProperty('barels', 'maxSupportOnSideKg', ...)` to get the (very low) support capacity.
            *   It also checks the general `allowsDirectStackingOnTop` for barrels (which is `true` in config, but this applies to its *primary* stand_on_end orientation). A more nuanced check specific to "on side" might be `if ($isItemBelowBarrelOnSide && !ConfigAllowsStackingOnSideBarrel) return false;`. For now, it relies on the very low `maxSupportOnSideKg`.
        *   **If `itemBelow` is a barrel standing on end (or any other non-barrel-on-side item):**
            *   It proceeds with the existing logic: checking `allowsDirectStackingOnTop` for the general type, then `itemBelow->stackable`, then heavier-first, then `effectiveMaxSupportKgItemBelow` (which would be the standard `maxSupportWeightKg` for a barrel on end).
    *   The rest of the footprint support logic (corner checks for rigid, percentage for deformable) remains.

2.  **`Item.php` (from previous step):**
    *   `getOrientedDimensions()` for a `tiltable:true` barrel now correctly provides "stand_on_end" (W:D, L:D, H:BarrelH) and "lay_on_side" (W:D, L:BarrelH, H:D) as the two primary orientations.
    *   `getNumberOfOrientations()` reflects this (1 if not tiltable or D=H, 2 if tiltable and D!=H).

**Conceptual Implications:**
*   Placing a box on a barrel standing on end: Should work fine if weight/footprint allows, as the top is flat and `allowsDirectStackingOnTop` is true for barrels in this state (implicitly).
*   Placing a box on a barrel lying on its side: Should likely fail for most boxes due to the very low `maxSupportOnSideKg` (e.g., 5kg) configured for barrels in `ItemTypeConfig.json`. The rectangular footprint check would also be hard to satisfy fully.
*   Placing barrel on barrel (end-on-end): Should work if weights allow.
*   Placing barrel on barrel (one on side, one on top): Would depend on the complex interaction of rules above; likely fails due to low side support.

This provides a more refined way to handle barrels based on how they are actually placed in the container.The conceptual refinement for handling "Barrels" (`barels`) is complete.

**Key Changes and Logic:**

1.  **`backend/config/item_type_config.json` Updated:**
    *   For the `"barels"` type, a new property `maxSupportOnSideKg: 5` (example value) was added. This defines the very limited weight a barrel can support if it's placed on its side and another item is stacked directly on its curved surface.
    *   The existing `maxSupportWeightKg: 200` is for when the barrel is standing on its end (flat surface).
    *   `allowsDirectStackingOnTop: true` still applies primarily to its stable "stand_on_end" orientation.

2.  **`backend/src/Model/Item.php` (`getOrientedDimensions()` and `getNumberOfOrientations()`):**
    *   These methods were updated to correctly provide two distinct bounding box orientations for a `tiltable:true` barrel:
        1.  Standing on end (primary, from input W=D, L=D, H=BarrelHeight).
        2.  Lying on its side (derived: W=D, L=BarrelHeight, H=D).
    *   If not `tiltable`, only the standing orientation is considered for barrels.

3.  **`backend/src/Service/PlacementService.php` (`canStackItemIn3DBoxArea()`):**
    *   **Orientation Detection of `itemBelow`:** When `itemBelow->type === 'barels'`, the logic now attempts to determine if the placed barrel (`$pItem`) is on its side or standing on end by comparing its `$pItem->orientedHeight` with its original dimensions from `$itemBelow` (the `Item` object).
    *   **Conditional Stacking Rules:**
        *   **If `itemBelow` (barrel) is on its side:** The service now uses the specific `maxSupportOnSideKg` from `ItemTypeConfig` for weight checks. This will typically be very low, preventing most items from being stacked on it. The general `allowsDirectStackingOnTop` for barrels might be overridden or interpreted in this context as not applicable.
        *   **If `itemBelow` (barrel) is standing on end:** It's treated as a normal flat surface. The standard `maxSupportWeightKg` (for barrels on end), `allowsDirectStackingOnTop: true`, heavier-first rule, and footprint support checks apply.
    *   The logic for stacking *other items* on a barrel, or *barrels* on other items, now more accurately considers the barrel's placed orientation.

This completes the refinement for barrels, making their stacking behavior dependent on their orientation within the container. The next step is conceptual testing.

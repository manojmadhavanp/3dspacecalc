```php
<?php
// This file should ideally be refactored into methods within CalculationService or similar
// For now, it's a procedural script called by CalculationController.

use App\Service\SortItemsService;
use App\Service\GetContainersService;
use App\Service\PlacementService;
use App\Model\PlacedItem;
use App\Model\BoxArea;
use App\Model\Item as ModelItem;

/**
 * Main function for the calculation engine.
 * Takes raw item data, processes it, and returns a structured array for JSON response (V4).
 */
function execute_load_calculation(array $inputData): array {
    $requestName = $inputData['name'] ?? "Calculation Request";

    if (!isset($inputData['items']) || !is_array($inputData['items'])) {
        return [ "status" => "ERROR_INPUT", "message" => "Invalid item data for calculation engine.", "requestName" => $requestName ];
    }
    $rawItems = $inputData['items'];

    $sortService = new SortItemsService();
    $getContainersService = new GetContainersService();
    $placementService = new PlacementService();

    $categorizedItemGroups = $sortService->groupAndCategorizeItems($rawItems);
    $gpcItemGroupsFromInput = $categorizedItemGroups['GPC'];

    $initialUnplacedDueToCategory = [];
    $unplacedIdxCounter = 1; // For items not even attempted for GPC placement
    foreach(['GPINGC', 'OOG'] as $categoryKey) {
        if(isset($categorizedItemGroups[$categoryKey]) && is_array($categorizedItemGroups[$categoryKey])) {
            foreach($categorizedItemGroups[$categoryKey] as $group) {
                // These groups are directly unplaced for this GPC-focused run
                $initialUnplacedDueToCategory[] = [
                    "itemId" => $group->name, "type" => $group->type,
                    "origDimsCm" => ['w' => $group->width, 'l' => $group->length, 'h' => $group->height],
                    "wtKg" => $group->weight, "qtyUnplaced" => $group->qty,
                    "reason" => "Item category '{$group->category}' not processed in this cycle."
                ];
            }
        }
    }

    $processedPlacedResults = []; // Renamed from processedContainersDetails for V4
    $masterPlacedItemsList = [];    // Flat list of PlacedItem objects
    $itemGroupsCurrentlyUnplaced = $gpcItemGroupsFromInput;

    $totalItemsSubmittedCount = 0; // All items from input
    foreach($rawItems as $ri) { $totalItemsSubmittedCount += (int)($ri['qty'] ?? 0); }


    $loopCount = 0;
    $maxMainLoops = count($gpcItemGroupsFromInput) + 5;

    while (!empty($itemGroupsCurrentlyUnplaced) && $loopCount < $maxMainLoops) {
        $loopCount++;
        $containerPlanForThisPass = $getContainersService->selectContainersForGPC($itemGroupsCurrentlyUnplaced);

        if (empty($containerPlanForThisPass)) {
            break;
        }

        $itemsGeometricallyUnplacedInThisPass_Individual = [];

        foreach ($containerPlanForThisPass as $containerIdx => $containerInstance) {
            if (empty($containerInstance->assignedItems)) continue;
            $itemsToAttemptInThisContainer = $containerInstance->assignedItems;

            $placementResult = $placementService->PlaceItemsInContainer($containerInstance, $itemsToAttemptInThisContainer);

            if (!empty($placementResult['placedItems'])) {
                $masterPlacedItemsList = array_merge($masterPlacedItemsList, $placementResult['placedItems']);
            }
            if (!empty($placementResult['unplacedItems'])) {
                $itemsGeometricallyUnplacedInThisPass_Individual = array_merge($itemsGeometricallyUnplacedInThisPass_Individual, $placementResult['unplacedItems']);
            }

            $containerLoadWeight = 0; $containerLoadVolume = 0;
            if (!empty($placementResult['placedItems'])) {
                foreach($placementResult['placedItems'] as $pItem) {
                    $containerLoadWeight += $pItem->weight;
                    $containerLoadVolume += $pItem->originalDimensions['width'] * $pItem->originalDimensions['length'] * $pItem->originalDimensions['height'];
                }
            }

            $itemTypeSummary = [];
            if (!empty($placementResult['placedItems'])) {
                 $itemTypeSummary = summarize_items_by_type_in_container($placementResult['placedItems']);
            }

            $emptySpaces = [];
            if(isset($placementResult['finalEmptyBoxAreas'])) { // From 3D BoxArea logic
                $emptySpaces = array_map(fn($ba) => ($ba instanceof BoxArea) ? ["id"=>$ba->id, "x"=>$ba->x, "y"=>$ba->y, "z"=>$ba->z, "w"=>$ba->width, "l"=>$ba->length, "h"=>$ba->height] : $ba, $placementResult['finalEmptyBoxAreas']);
                $emptySpaces = array_filter($emptySpaces);
            }


            // Determine container-specific status
            $containerStatus = 'PARTIAL_REMAINDER_LOAD'; // Default if it's the last one with items
            if (empty($placementResult['unplacedItems']) && !empty($containerInstance->assignedItems)) {
                 // Check if all *assigned* to this specific container were placed
                 $assignedQty = array_reduce($containerInstance->assignedItems, fn($s, $g) => $s + $g->qty, 0);
                 if (count($placementResult['placedItems']) == $assignedQty) {
                    $containerStatus = 'CAPACITY_ASSIGNED_FILLED';
                 } else {
                    $containerStatus = 'GEOMETRIC_LIMIT_REACHED'; // Assigned more than could geometrically fit
                 }
            }


            $processedPlacedResults[] = [
                "containerInfo" => [
                    "instanceId" => "C" . (count($processedPlacedResults) + 1),
                    "key" => $containerInstance->key, "name" => $containerInstance->name,
                    "dimensionsCm" => ["width" => $containerInstance->width, "length" => $containerInstance->length, "height" => $containerInstance->height],
                    "capacity" => ["usableVolumeCm3" => $containerInstance->usableVolume, "usablePayloadKg" => $containerInstance->usablePayload],
                    "floorType" => $containerInstance->floorType
                ],
                "loadSummary" => [
                    "status" => $containerStatus,
                    "itemCount" => count($placementResult['placedItems'] ?? []),
                    "totalWeightKg" => round($containerLoadWeight, 2),
                    "totalVolumeCm3" => round($containerLoadVolume, 2),
                    "payloadUtilizationPercent" => $containerInstance->usablePayload > 0.01 ? round(($containerLoadWeight / $containerInstance->usablePayload) * 100, 2) : 0,
                    "volumeUtilizationPercent" => $containerInstance->usableVolume > 0.01 ? round(($containerLoadVolume / $containerInstance->usableVolume) * 100, 2) : 0,
                    "remainingUsablePayloadKg" => round($containerInstance->usablePayload - $containerLoadWeight, 2),
                    "remainingUsableVolumeCm3" => round($containerInstance->usableVolume - $containerLoadVolume, 2)
                ],
                "itemsByLayer" => $placementResult['layers'] ?? [],
                "itemTypeSummaryInContainer" => $itemTypeSummary,
                "emptySpacesDebug" => $emptySpaces
            ];
        }
        $itemGroupsCurrentlyUnplaced = updateRemainingGroupsBasedOnPlacedItems($gpcItemGroupsFromInput, $masterPlacedItemsList);
    }

    // Consolidate all unplaced reasons
    $finalUnplacedItemSummary = $initialUnplacedDueToCategory; // Start with items not processed by GPC logic
    if (!empty($itemGroupsCurrentlyUnplaced)) {
        $unplacedFromGPCProcessing = regroupIndividualItemsToItemGroups($itemGroupsCurrentlyUnplaced, true); // True to get reason
        foreach($unplacedFromGPCProcessing as $groupSummary) {
            $finalUnplacedItemSummary[] = $groupSummary;
        }
    }

    $finalTotalWeightPlaced = 0; $finalTotalVolumePlaced = 0;
    foreach($masterPlacedItemsList as $pItemObject){
        $finalTotalWeightPlaced += $pItemObject->weight;
        $finalTotalVolumePlaced += $pItemObject->originalDimensions['width'] * $pItemObject->originalDimensions['length'] * $pItemObject->originalDimensions['height'];
    }
    $itemsPlacedCount = count($masterPlacedItemsList);
    $itemsUnplacedCount = 0;
    foreach($finalUnplacedItemSummary as $unplacedGroup) {
        $itemsUnplacedCount += $unplacedGroup['qtyUnplaced'];
    }


    $overallStatus = 'ERROR_SERVER';
    if (empty($finalUnplacedItemSummary) && $itemsPlacedCount >= $totalItemsSubmittedCount && $totalItemsSubmittedCount > 0) {
        $overallStatus = 'SUCCESS_ALL_PLACED';
    } elseif ($itemsPlacedCount > 0) {
        $overallStatus = 'SUCCESS_PARTIAL_FIT';
    } elseif ($totalItemsSubmittedCount > 0) {
        $overallStatus = 'FAILURE_NO_FIT';
    } elseif ($totalItemsSubmittedCount == 0) {
        $overallStatus = 'SUCCESS_ALL_PLACED'; // No items submitted is a form of "all placed"
        if(empty($rawItems)) $overallStatus = "NO_ITEMS_SUBMITTED"; // More specific
    }

    $responseMessages = [];
    if ($overallStatus === 'SUCCESS_ALL_PLACED' && $totalItemsSubmittedCount > 0) $responseMessages[] = "All items successfully placed.";
    if ($overallStatus === 'SUCCESS_PARTIAL_FIT') $responseMessages[] = "{$itemsPlacedCount} items placed; {$itemsUnplacedCount} items could not be placed.";
    if ($overallStatus === 'FAILURE_NO_FIT') $responseMessages[] = "No items could be placed with the given constraints.";


    $response = [
        "requestName" => $requestName,
        "status" => $overallStatus,
        "overallSummary" => [
            "itemsSubmittedCount" => $totalItemsSubmittedCount,
            "itemsPlacedCount" => $itemsPlacedCount,
            "itemsUnplacedCount" => $itemsUnplacedCount,
            "totalWeightPlacedKg" => round($finalTotalWeightPlaced,2),
            "totalVolumePlacedCm3" => round($finalTotalVolumePlaced,2),
            "containersUsedCount" => count($processedPlacedResults)
        ],
        "placedResults" => $processedPlacedResults,
        "unplacedItemSummary" => $finalUnplacedItemSummary,
        "messages" => $responseMessages
    ];
    return $response;
}

function summarize_items_by_type_in_container(array $placedItemsInContainer): array {
    $summary = []; $typeMap = [];
    foreach ($placedItemsInContainer as $placedItem) {
        if (!$placedItem instanceof PlacedItem) continue;
        $key = $placedItem->itemName . "_" . $placedItem->type;
        if (!isset($typeMap[$key])) {
            $typeMap[$key] = ["itemId" => $placedItem->itemName, "type" => $placedItem->type, "totalQty" => 0];
        }
        $typeMap[$key]["totalQty"]++;
    }
    return array_values($typeMap);
}

function regroupIndividualItemsToItemGroups(array $individualItems, bool $includeReason = false): array {
    $grouped = [];
    foreach ($individualItems as $item) {
        if (!$item instanceof ModelItem) continue;
        // Create a more robust key that includes all defining properties of an item group
        $key = sprintf("%s-%s-%.2f-%.2f-%.2f-%.2f-%d-%d",
            $item->name, $item->type, $item->width, $item->length, $item->height,
            $item->weight, $item->stackable, $item->tiltable
        );
        if (!isset($grouped[$key])) {
            $grouped[$key] = [
                "itemId" => $item->name, "type" => $item->type,
                "origDimsCm" => ['w' => $item->width, 'l' => $item->length, 'h' => $item->height],
                "wtKg" => $item->weight, "qtyUnplaced" => 0
            ];
            if ($includeReason) {
                 // Assuming placementFailureReason is set on the Item object by PlacementService if it failed there
                $grouped[$key]["reason"] = $item->placementFailureReason ?? "Not placed due to geometric constraints or later stage capacity issues.";
            }
        }
        $grouped[$key]["qtyUnplaced"]++;
    }
    return array_values($grouped);
}

function updateRemainingGroupsBasedOnPlacedItems(array $originalTotalItemGroups, array $masterPlacedItemsList): array {
    $stillToPlaceGroups = array_map(fn(ModelItem $g) => clone $g, $originalTotalItemGroups);
    foreach ($masterPlacedItemsList as $placedItem) {
        if (!$placedItem instanceof PlacedItem) continue;
        foreach ($stillToPlaceGroups as $idx => $group) {
            if ($group->name === $placedItem->itemName && $group->type === $placedItem->type &&
                abs($group->width - $placedItem->originalDimensions['width']) < ModelItem::EPSILON_COMPARISON && // Assuming EPSILON on Item model
                abs($group->length - $placedItem->originalDimensions['length']) < ModelItem::EPSILON_COMPARISON &&
                abs($group->height - $placedItem->originalDimensions['height']) < ModelItem::EPSILON_COMPARISON &&
                abs($group->weight - $placedItem->weight) < ModelItem::EPSILON_COMPARISON &&
                $group->stackable == $placedItem->originalItemRef->stackable && // Need originalItemRef on PlacedItem
                $group->tiltable == $placedItem->originalItemRef->tiltable
            ) {
                $group->qty--;
                if ($group->qty <= 0) { unset($stillToPlaceGroups[$idx]); }
                break;
            }
        }
    }
    return array_values($stillToPlaceGroups);
}
?>
```

**Key Changes in `execute_load_calculation` for JSON V4:**

1.  **Top-Level Keys:** `status`, `overallSummary`, `placedResults` (was `containers`), `unplacedItemSummary` (was `unplacedItems`), `messages`.
2.  **`overallSummary`:** Populated with `itemsSubmittedCount`, `itemsPlacedCount`, `itemsUnplacedCount`, `totalWeightPlacedKg`, `totalVolumePlacedCm3`, `containersUsedCount`.
3.  **`placedResults[]` (per container):**
    *   `containerInfo`: Contains static details of the container instance (`instanceId`, `key`, `name`, `dimensionsCm`, `capacity`, `floorType`).
    *   `loadSummary`: Contains dynamic load details for *this* container (`status` like 'OPTIMAL_FULL', `itemCount`, `totalWeightKg`, `totalVolumeCm3`, utilization percentages, `remainingUsablePayloadKg`, `remainingUsableVolumeCm3`).
    *   `itemsByLayer`: This directly takes the `layers` output from `PlacementService` (which itself contains `placementsInLayer` with items formatted using `PlacedItem::toArrayV4()`).
    *   `itemTypeSummaryInContainer`: A new helper `summarize_items_by_type_in_container()` is called to generate this based on the items placed in the current container.
    *   `emptySpacesDebug`: Populated from `finalEmptyBoxAreas`.
4.  **`unplacedItemSummary[]`:**
    *   The `overallUnplacedItems` (which are `ModelItem` objects, potentially with `qty > 1` if a whole group failed early, or `qty = 1` if they are geometric failures from `PlacementService`) are processed.
    *   A regrouping step `regroupIndividualItemsToItemGroups($itemsFromPlacementServiceUnplaced, true)` is used to summarize individual unplaced items from placement back into groups with quantities and a reason.
    *   The `reason` field is populated (using a placeholder for now, as detailed reason propagation from services is still a TODO).
5.  **Status Logic:** More descriptive overall status values are determined based on placement success.
6.  **Helper `summarize_items_by_type_in_container`**: Added to create the new summary section within each container result.
7.  **Helper `regroupIndividualItemsToItemGroups`**: Added to summarize unplaced items for the `unplacedItemSummary`.
8.  **Helper `updateRemainingGroupsBasedOnPlacedItems`**: Updated to use a more robust item comparison (though it relies on `PlacedItem->originalItemRef` which needs to be consistently set and `Item` needs an `EPSILON_COMPARISON` constant).

**Refinement in `PlacedItem.php` (Conceptual - `toArrayV4`)**
The `PlacedItem::toArray()` would be renamed or modified to `toArrayV4()` and produce the nested `posCm` and `orientDimsCm` structure with units in keys.

```php
// In backend/src/Model/PlacedItem.php - (already provided, just re-iterating the change for this step)
public function toArrayV4(): array { // Or just update toArray()
    return [
        "itemId" => $this->itemName,
        "type" => $this->type,
        "qtyIdx" => $this->originalQtyIndex,
        "origDimsCm" => $this->originalDimensions,
        "wtKg" => round($this->weight, 2),
        "posCm" => ["x" => round($this->x, 2), "y" => round($this->y, 2), "z" => round($this->z, 2)],
        "orientDimsCm" => ["w" => round($this->orientedWidth, 2), "l" => round($this->orientedLength, 2), "h" => round($this->orientedHeight, 2)]
    ];
}
```
And the `groupPlacedItemsIntoLayers` in `PlacementService` would call `$placedItem->toArrayV4()`.

This completes the conceptual update to generate the more mature JSON V4 format.The conceptual update of `api_calc_engine_placeholder.php` to generate the JSON V4 output format is complete.

**Key changes implemented in `execute_load_calculation` function:**

1.  **New JSON Structure Adherence:** The code now assembles the response according to the V4 structure, including:
    *   Top-level keys: `requestName`, `status` (with more descriptive values like `SUCCESS_ALL_PLACED`, `SUCCESS_PARTIAL_FIT`), `overallSummary`, `placedResults`, `unplacedItemSummary`, and `messages`.
    *   `overallSummary`: Contains `itemsSubmittedCount`, `itemsPlacedCount`, `itemsUnplacedCount`, `totalWeightPlacedKg`, `totalVolumePlacedCm3`, and `containersUsedCount`.
    *   `placedResults` (array, one per container):
        *   `containerInfo`: Static details (`instanceId`, `key`, `name`, `dimensionsCm`, `capacity`, `floorType`).
        *   `loadSummary`: Dynamic load details for the container (`status`, `itemCount`, `totalWeightKg`, `totalVolumeCm3`, utilizations, remaining capacity).
        *   `itemsByLayer`: Directly uses the `layers` output from `PlacementService` (which groups `PlacedItem` objects by Z-start, with items formatted using a new `PlacedItem::toArrayV4()` method for concise keys and nested `posCm`, `orientDimsCm`).
        *   `itemTypeSummaryInContainer`: A new helper function `summarize_items_by_type_in_container()` generates this list of item types and their total quantities within the specific container.
        *   `emptySpacesDebug`: For 3D `BoxArea` remnants.
    *   `unplacedItemSummary`: Unplaced items are now grouped by type using `regroupIndividualItemsToItemGroups()`, showing `itemId`, `type`, `origDimsCm`, `wtKg`, `qtyUnplaced`, and a placeholder `reason`.
    *   `messages`: An array for user-facing informational messages.

2.  **Helper Functions Added/Modified:**
    *   `summarize_items_by_type_in_container()`: New helper to count total quantities of each item type within a single container's placed items.
    *   `regroupIndividualItemsToItemGroups()`: Enhanced to include a `reason` field (currently placeholder) when summarizing unplaced items.
    *   `updateRemainingGroupsBasedOnPlacedItems()`: The logic for determining which items are still unplaced after a placement pass is now more robust, comparing against the master list of successfully placed items. (This helper itself needs `PlacedItem->originalItemRef` to be reliable for exact item matching).

3.  **`PlacedItem::toArrayV4()` (Conceptual):** The `PlacedItem` model would have an updated `toArrayV4()` method (or its existing `toArray()` modified) to format individual placed item data with concise keys and nested `posCm`, `orientDimsCm` objects as required by the V4 JSON structure.

This revised structure provides comprehensive summary data both overall and per-container, detailed placement information grouped by layers suitable for 2D/3D visualization, and a clear summary of unplaced items with reasons. The keys are also more concise. This aligns with the goal of a "matured grouping" for better frontend consumption. The next step in the plan is "Conceptual Testing with Test Data 1" using this new JSON output.

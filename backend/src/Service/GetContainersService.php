```php
<?php

namespace App\Service;

use App\Model\Item;
use App\Model\Container;
use App\Config\ContainerLoader;

class GetContainersService {
    private array $allContainers;
    private const EPSILON = 0.01;

    public function __construct(?array $containers = null) {
        $this->allContainers = $containers ?? ContainerLoader::getAllContainers();
    }

    public function selectContainersForGPC(array $gpcItemGroupsToPlace): array {
        if (empty($gpcItemGroupsToPlace)) {
            return [];
        }

        $selectedContainerInstances = [];
        // Deep clone to prevent modification of original item group quantities during processing
        $remainingItemGroups = array_map(fn(Item $item) => clone $item, $gpcItemGroupsToPlace);

        // Attempt to fit all into a single largest suitable container first
        $singleFitContainer = $this->tryFitAllInSingleLargestSuitableContainer($remainingItemGroups);
        if ($singleFitContainer) {
            return [$singleFitContainer];
        }

        // Multi-container logic: "escalate if >1 of current size needed, then optimize remainder"
        // Escalation order: 20ftGP -> 40ftGP -> 40ftHC
        $containerSizeProgression = ['20ftGP', '40ftGP', '40ftHC'];
        $currentIterationItems = $this->deepCloneItemGroups($remainingItemGroups); // Start with all items

        foreach ($containerSizeProgression as $sizeKeyToTry) {
            if (empty($currentIterationItems)) break;

            $dominantItemGroupForFitEst = $currentIterationItems[0]; // Use heaviest for estimation
            $geomFitInThisSizeType = $this->estimateGeometricFit($dominantItemGroupForFitEst, $sizeKeyToTry);

            if ($geomFitInThisSizeType == 0) { // This container type cannot even fit one of the dominant items
                if ($sizeKeyToTry === '40ftHC') break; // If even HC can't fit, stop escalation
                continue; // Try next larger size
            }

            $numContainersOfThisTypeNeeded = ceil($this->countTotalItems($currentIterationItems) / $geomFitInThisSizeType);

            if ($numContainersOfThisTypeNeeded == 1 && $sizeKeyToTry !== '40ftHC') {
                // All remaining items fit in ONE container of the current type (20GP or 40GP)
                // And we are not yet at the largest type (40HC)
                $container = $this->getPreferredContainerOfType($sizeKeyToTry, $this->calculateTotalWeight($currentIterationItems), $this->calculateTotalVolume($currentIterationItems));
                if ($container) {
                    $newInstance = clone $container;
                    $newInstance->assignedItems = $this->deepCloneItemGroups($currentIterationItems);
                    $selectedContainerInstances[] = $newInstance;
                    $currentIterationItems = []; // All items assigned
                }
                break; // Stop escalation, all items handled
            }

            if ($numContainersOfThisTypeNeeded > 1 || $sizeKeyToTry === '40ftHC') {
                // This means we either need multiple of the current type, or we are at the largest type (40HC)
                // So, this $sizeKeyToTry (or 40HC if we jumped here) becomes our primary workhorse.
                $workhorseSizeTypeKey = $sizeKeyToTry;

                // Special HC utility check if we've escalated to or are at HC
                if ($workhorseSizeTypeKey === '40ftHC') {
                    $ref40GPForUtilCheck = $this->getPreferredContainerOfType('40ftGP', $this->calculateTotalWeight([$dominantItemGroupForFitEst]), $this->calculateTotalVolume([$dominantItemGroupForFitEst]));
                    if ($ref40GPForUtilCheck) {
                         $hcContainerForUtilCheck = $this->getPreferredContainerOfType('40ftHC', $this->calculateTotalWeight([$dominantItemGroupForFitEst]), $this->calculateTotalVolume([$dominantItemGroupForFitEst]));
                         if ($hcContainerForUtilCheck && !$this->checkHCUtility($dominantItemGroupForFitEst, $hcContainerForUtilCheck, $ref40GPForUtilCheck)) {
                            $workhorseSizeTypeKey = '40ftGP'; // HC not useful, revert to 40GP as workhorse
                            $geomFitInThisSizeType = $this->estimateGeometricFit($dominantItemGroupForFitEst, $workhorseSizeTypeKey);
                            if($geomFitInThisSizeType == 0) { // Should not happen if 40GP was checked before
                                 $currentIterationItems = []; // Mark as unplaceable for safety
                                 break;
                            }
                         }
                    }
                }

                $preferredWorkhorseContainer = $this->getPreferredContainerOfType($workhorseSizeTypeKey, $this->calculateTotalWeight($currentIterationItems), $this->calculateTotalVolume($currentIterationItems));
                if (!$preferredWorkhorseContainer) {
                     $currentIterationItems = []; // No suitable workhorse container found
                     break;
                }
                $geomFitInWorkhorse = $this->estimateGeometricFit($dominantItemGroupForFitEst, $workhorseSizeTypeKey);
                 if($geomFitInWorkhorse == 0) { $currentIterationItems = []; break; }


                // Fill workhorse containers
                $numFullWorkhorseContainers = floor($this->countTotalItems($currentIterationItems) / $geomFitInWorkhorse);
                for ($i = 0; $i < $numFullWorkhorseContainers; $i++) {
                    $itemsForThis = $this->getItemsForOneContainer($currentIterationItems, $preferredWorkhorseContainer, $geomFitInWorkhorse);
                    if (empty($itemsForThis)) break; // Should not happen if numFull > 0

                    $newInstance = clone $preferredWorkhorseContainer;
                    $newInstance->assignedItems = $itemsForThis;
                    $selectedContainerInstances[] = $newInstance;
                    $currentIterationItems = $this->getRemainingItems($currentIterationItems, $itemsForThis);
                    if (empty($currentIterationItems)) break;
                }
                // Any remaining items will be handled by the "optimize remainder" step
                break; // We've selected our workhorse type and filled them.
            }
            // If numContainersOfThisTypeNeeded == 1, it means items fit in one of this type.
            // The loop will break after this iteration if $currentIterationItems becomes empty.
        } // End container size progression loop

        // Final remainder optimization
        if (!empty($currentIterationItems)) {
            $finalRemainderContainers = $this->handleFinalRemainderWithSmallestFit($currentIterationItems);
            $selectedContainerInstances = array_merge($selectedContainerInstances, $finalRemainderContainers);
        }

        return $selectedContainerInstances;
    }

    private function tryFitAllInSingleLargestSuitableContainer(array $itemGroups): ?Container {
        $totalWeight = $this->calculateTotalWeight($itemGroups);
        $totalVolume = $this->calculateTotalVolume($itemGroups);
        if(empty($itemGroups)) return null;
        $dominantItemForHC = $itemGroups[0];

        $checkOrder = ['40ftHC', '40ftGP', '20ftGP'];
        foreach ($checkOrder as $sizeTypeKey) {
            $container = $this->getPreferredContainerOfType($sizeTypeKey, $totalWeight, $totalVolume);
            if ($container) {
                if ($totalWeight <= ($container->usablePayload + self::EPSILON) && $totalVolume <= ($container->usableVolume + self::EPSILON)) {
                    if ($sizeTypeKey === '40ftHC') {
                        $ref40GP = $this->getPreferredContainerOfType('40ftGP', $totalWeight, $totalVolume); // Check against same load
                        if ($ref40GP && !$this->checkHCUtility($dominantItemForHC, $container, $ref40GP)) {
                            continue;
                        }
                    }
                    $containerInstance = clone $container;
                    $containerInstance->assignedItems = $this->deepCloneItemGroups($itemGroups);
                    return $containerInstance;
                }
            }
        }
        return null;
    }

    private function handleFinalRemainderWithSmallestFit(array $itemGroups): array {
        if (empty($itemGroups)) return [];
        $selectedForRemainder = [];
        $remainingForRemainder = $this->deepCloneItemGroups($itemGroups);

        $remainderCheckOrder = ['20ftGP', '40ftGP', '40ftHC']; // Try smallest first for remainder
        while(!empty($remainingForRemainder)) {
            $placedInThisPass = false;
            foreach ($remainderCheckOrder as $sizeTypeKey) {
                $totalWeightRem = $this->calculateTotalWeight($remainingForRemainder);
                $totalVolumeRem = $this->calculateTotalVolume($remainingForRemainder);
                $dominantItemRem = $remainingForRemainder[0];

                $container = $this->getPreferredContainerOfType($sizeTypeKey, $totalWeightRem, $totalVolumeRem);
                if ($container) {
                    // HC Utility Check for remainder if considering HC
                    if ($sizeTypeKey === '40ftHC') {
                        $ref40GPRem = $this->getPreferredContainerOfType('40ftGP', $totalWeightRem, $totalVolumeRem);
                        if ($ref40GPRem && !$this->checkHCUtility($dominantItemRem, $container, $ref40GPRem)) {
                            continue; // HC not best for this small remainder, try next in $remainderCheckOrder if any
                        }
                    }

                    $geomFitInRemContainer = $this->estimateGeometricFit($dominantItemRem, $sizeTypeKey);
                    if ($geomFitInRemContainer > 0) {
                         $itemsForThisRemContainer = $this->getItemsForOneContainer($remainingForRemainder, $container, $geomFitInRemContainer);
                         if(!empty($itemsForThisRemContainer)) {
                            $newInstance = clone $container;
                            $newInstance->assignedItems = $itemsForThisRemContainer;
                            $selectedForRemainder[] = $newInstance;
                            $remainingForRemainder = $this->getRemainingItems($remainingForRemainder, $itemsForThisRemContainer);
                            $placedInThisPass = true;
                            break; // Break from $remainderCheckOrder, restart while with new $remainingForRemainder
                         }
                    }
                }
            }
            if(!$placedInThisPass && !empty($remainingForRemainder)) {
                // error_log("Could not place final remainder: " . $this->countTotalItems($remainingForRemainder) . " items.");
                break; // Avoid infinite loop if no container can take the rest
            }
        }
        return $selectedForRemainder;
    }

    private function estimateGeometricFit(?Item $item, string $sizeTypeKey): int {
        if ($item === null) return 0;
        $container = $this->getPreferredContainerOfType($sizeTypeKey, $item->weight * $item->qty, $item->getVolume() * $item->qty); // Pass total weight/vol of group
        if (!$container) return 0;

        $itemH = $item->height; // Assuming initial orientation for this estimate
        $itemW = $item->width;
        $itemL = $item->length;
        if ($itemH < self::EPSILON || $itemW < self::EPSILON || $itemL < self::EPSILON) return 0;

        // Try two basic orientations for floor fit
        $itemsPerFloor1 = floor(($container->width + self::EPSILON) / $itemW) * floor(($container->length + self::EPSILON) / $itemL);
        $itemsPerFloor2 = floor(($container->width + self::EPSILON) / $itemL) * floor(($container->length + self::EPSILON) / $itemW);
        $itemsPerFloor = max($itemsPerFloor1, $itemsPerFloor2);
        if ($itemsPerFloor == 0) return 0;

        $layers = floor(($container->height + self::EPSILON) / $itemH);
        if ($layers == 0) return 0;

        return (int)($itemsPerFloor * $layers);
    }

    private function getItemsForOneContainer(array $itemGroups, Container $container, int $estimatedMaxItemCount): array {
        $assignedToThisContainer = []; $currentWeight = 0; $currentVolume = 0; $itemCountInThisContainer = 0;
        $tempItemGroups = $this->deepCloneItemGroups($itemGroups); // Work on copies

        foreach ($tempItemGroups as $group) {
            if ($group->qty == 0) continue;
            $itemUnitWeight = $group->weight; $itemUnitVolume = $group->getVolume();

            $canTakeQty = $group->qty;
            // Limit by count
            if ($itemCountInThisContainer + $canTakeQty > $estimatedMaxItemCount) {
                $canTakeQty = $estimatedMaxItemCount - $itemCountInThisContainer;
            }
            // Limit by weight
            if ($itemUnitWeight > self::EPSILON) { // Avoid division by zero
                 $canTakeByWeight = floor(($container->usablePayload - $currentWeight) / $itemUnitWeight);
                 $canTakeQty = min($canTakeQty, $canTakeByWeight);
            }
            // Limit by volume
            if ($itemUnitVolume > self::EPSILON) {
                $canTakeByVolume = floor(($container->usableVolume - $currentVolume) / $itemUnitVolume);
                $canTakeQty = min($canTakeQty, $canTakeByVolume);
            }

            if ($canTakeQty <= 0) continue;

            $itemToAdd = clone $group;
            $itemToAdd->qty = $canTakeQty;
            // $itemToAdd->originalQtyIndex = -1; // This is a group, not individual item
            $assignedToThisContainer[] = $itemToAdd;

            $currentWeight += $itemUnitWeight * $canTakeQty;
            $currentVolume += $itemUnitVolume * $canTakeQty;
            $itemCountInThisContainer += $canTakeQty;

            if ($itemCountInThisContainer >= $estimatedMaxItemCount) break;
            if ($currentWeight >= $container->usablePayload) break;
            if ($currentVolume >= $container->usableVolume) break;
        }
        return $assignedToThisContainer; // List of item GROUPS with adjusted quantities
    }

    private function getRemainingItems(array $originalGroups, array $justAssignedGroups): array {
        $remaining = $this->deepCloneItemGroups($originalGroups);
        foreach ($justAssignedGroups as $assignedGroup) {
            foreach ($remaining as $idx => $originalGroup) {
                if ($this->getItemSignature($originalGroup) === $this->getItemSignature($assignedGroup)) {
                    $originalGroup->qty -= $assignedGroup->qty;
                    if ($originalGroup->qty <= 0) {
                        unset($remaining[$idx]);
                    }
                    break;
                }
            }
        }
        return array_values($remaining); // Re-index
    }

    private function getItemSignature(Item $item): string { /* ... as before ... */
        return sprintf("%s-%s-%.2f-%.2f-%.2f-%.2f-%d-%d", $item->name, $item->type, $item->width, $item->length, $item->height, $item->weight, $item->stackable, $item->tiltable);
    }

    private function getPreferredContainerOfType(string $sizeTypeKey, float $requiredWeight, float $requiredVolume): ?Container {
        $candidates = [];
        foreach ($this->allContainers as $container) {
            $matchesSizeType = false;
            if ($sizeTypeKey === '20ftGP' && str_contains($container->key, '20ft') && $container->category === 'GP') $matchesSizeType = true;
            if ($sizeTypeKey === '40ftGP' && str_contains($container->key, '40ft') && !str_contains($container->key, 'HC') && $container->category === 'GP') $matchesSizeType = true;
            if ($sizeTypeKey === '40ftHC' && str_contains($container->key, '40ftHC')) $matchesSizeType = true;

            if ($matchesSizeType && in_array('front', $container->loadingTypes)) {
                 // Check if this candidate can even hold the required weight/volume by itself
                if ($requiredWeight <= ($container->usablePayload + self::EPSILON) && $requiredVolume <= ($container->usableVolume + self::EPSILON)) {
                    $candidates[] = $container;
                }
            }
        }
        if (empty($candidates)) return null;

        usort($candidates, function(Container $a, Container $b) use ($requiredWeight) {
            // Check load/meter first - disqualify if fails for the *required* weight
            $avgLoadA = ($requiredWeight / 1000) / ($a->length / 100);
            $avgLoadB = ($requiredWeight / 1000) / ($b->length / 100);
            $passA = $avgLoadA <= ($a->loadCapacityPerMeter + self::EPSILON);
            $passB = $avgLoadB <= ($b->loadCapacityPerMeter + self::EPSILON);

            if ($passA && !$passB) return -1;
            if (!$passA && $passB) return 1;
            if (!$passA && !$passB) return $a->usablePayload <=> $b->usablePayload; // If both fail, prefer higher payload (less likely to be chosen)

            if ($a->floorType === 'Wooden' && $b->floorType !== 'Wooden') return -1;
            if ($a->floorType !== 'Wooden' && $b->floorType === 'Wooden') return 1;
            return $b->usablePayload <=> $a->usablePayload;
        });

        // After sorting, the first one is the most preferred that can hold the totals
        // Now, re-check its load/meter for the requiredWeight specifically
        if (!empty($candidates)) {
            $chosen = $candidates[0];
            $avgLoadChosen = ($requiredWeight / 1000) / ($chosen->length / 100);
            if ($avgLoadChosen <= ($chosen->loadCapacityPerMeter + self::EPSILON)) {
                return $chosen;
            }
            // If the most preferred (e.g. wood) fails load/meter, try to find next best (e.g. steel)
            // This part of logic might need iteration if the first sorted doesn't pass load/meter
            foreach($candidates as $c) {
                 $avgLoad = ($requiredWeight / 1000) / ($c->length / 100);
                 if($avgLoad <= ($c->loadCapacityPerMeter + self::EPSILON)) return $c;
            }
        }
        return null; // No suitable container found
    }

    private function checkHCUtility(Item $item, Container $hcContainer, Container $gpContainer): bool {
        if (!$item || !$hcContainer || !$gpContainer || $item->height < self::EPSILON) return false;
        if ($hcContainer->height < self::EPSILON || $gpContainer->height < self::EPSILON) return false;

        $layersInGP = floor(($gpContainer->height + self::EPSILON) / $item->height);
        $layersInHC = floor(($hcContainer->height + self::EPSILON) / $item->height);
        return $layersInHC > $layersInGP;
    }

    private function calculateTotalWeight(array $itemGroups): float { /* ... as before ... */ return array_reduce($itemGroups, fn($sum, Item $g) => $sum + ($g->weight * $g->qty), 0); }
    private function calculateTotalVolume(array $itemGroups): float { /* ... as before ... */ return array_reduce($itemGroups, fn($sum, Item $g) => $sum + ($g->getVolume() * $g->qty), 0); }
    private function countTotalItems(array $itemGroups): int { /* ... as before ... */ return array_reduce($itemGroups, fn($sum, Item $g) => $sum + $g->qty, 0); }
    private function deepCloneItemGroups(array $itemGroups): array { return array_map(fn(Item $item) => clone $item, $itemGroups); }
}
```

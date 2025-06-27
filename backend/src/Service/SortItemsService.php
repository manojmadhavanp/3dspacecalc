```php
<?php

namespace App\Service;

use App\Model\Item;
use App\Model\Container;
use App\Config\ContainerLoader;

class SortItemsService {
    private array $allContainers; // Array of Container objects
    private ?Container $ref20ftGP = null;
    private ?Container $ref40ftGP = null;
    private ?Container $ref40ftHC = null; // Can be OOG category but front loading
    private ?Container $refOpenTop = null;
    // Add refs for FlatRacks if detailed OOG check is needed

    public function __construct(?array $containers = null) {
        $this->allContainers = $containers ?? ContainerLoader::getAllContainers();
        $this->setRepresentativeContainers();
    }

    private function setRepresentativeContainers(): void {
        // Find representative containers for dimension checks
        // This logic can be enhanced to pick the "smallest" or "most typical"
        foreach ($this->allContainers as $container) {
            if ($this->ref20ftGP === null && $container->key === '20ftGPWood') { // Example specific key
                $this->ref20ftGP = $container;
            } else if ($this->ref20ftGP === null && $container->category === 'GP' && str_contains($container->key, '20ft') && in_array('front', $container->loadingTypes)) {
                 $this->ref20ftGP = $container; // Fallback
            }

            if ($this->ref40ftGP === null && $container->key === '40ftGPWood') {
                $this->ref40ftGP = $container;
            } else if ($this->ref40ftGP === null && $container->category === 'GP' && str_contains($container->key, '40ft') && !str_contains($container->key, 'HC') && in_array('front', $container->loadingTypes)) {
                 $this->ref40ftGP = $container;
            }

            if ($this->ref40ftHC === null && $container->key === '40ftHCWood') { // OOG category but front-load
                $this->ref40ftHC = $container;
            } else if ($this->ref40ftHC === null && str_contains($container->key, '40ftHC') && in_array('front', $container->loadingTypes)) {
                $this->ref40ftHC = $container;
            }

            if ($this->refOpenTop === null && $container->isOpenTop()) {
                $this->refOpenTop = $container;
            }
        }
         // Fallback if specific keys aren't found but some GP containers exist
        if ($this->ref20ftGP === null) foreach($this->allContainers as $c) if($c->category === 'GP' && str_contains($c->key, '20ft')) {$this->ref20ftGP = $c; break;}
        if ($this->ref40ftGP === null) foreach($this->allContainers as $c) if($c->category === 'GP' && str_contains($c->key, '40ft') && !str_contains($c->key, 'HC')) {$this->ref40ftGP = $c; break;}
        if ($this->ref40ftHC === null) foreach($this->allContainers as $c) if(str_contains($c->key, '40ftHC')) {$this->ref40ftHC = $c; break;} // May include OOG category
    }

    public function groupAndCategorizeItems(array $rawItemsData): array {
        $groupedItemsMap = [];
        foreach ($rawItemsData as $itemData) {
            // Validate basic itemData structure
            $requiredKeys = ['name', 'type', 'width', 'length', 'height', 'weight', 'stackable', 'tiltable', 'qty'];
            foreach($requiredKeys as $reqKey) {
                if(!isset($itemData[$reqKey])) throw new \InvalidArgumentException("Missing key '$reqKey' in item data.");
            }

            $key = sprintf("%s-%s-%.2f-%.2f-%.2f-%.2f-%d-%d",
                $itemData['name'], $itemData['type'],
                (float)$itemData['width'], (float)$itemData['length'], (float)$itemData['height'],
                (float)$itemData['weight'], (bool)$itemData['stackable'], (bool)$itemData['tiltable']
            );

            if (!isset($groupedItemsMap[$key])) {
                $groupedItemsMap[$key] = new Item(
                    $itemData['name'], $itemData['type'],
                    (float)$itemData['width'], (float)$itemData['length'], (float)$itemData['height'],
                    (float)$itemData['weight'], (bool)$itemData['stackable'], (bool)$itemData['tiltable'],
                    0 // Qty will be summed
                );
            }
            $groupedItemsMap[$key]->qty += (int)$itemData['qty'];
        }
        $groupedItems = array_values($groupedItemsMap);

        $gpcItems = []; $gpingcItems = []; $oogItems = [];

        foreach ($groupedItems as $item) {
            $item->category = $this->categorizeItem($item);
            switch ($item->category) {
                case 'GPC': $gpcItems[] = $item; break;
                case 'GPINGC': $gpingcItems[] = $item; break;
                case 'OOG': $oogItems[] = $item; break;
            }
        }

        $sortFn = fn(Item $a, Item $b) => $b->weight <=> $a->weight; // Descending weight
        usort($gpcItems, $sortFn);
        usort($gpingcItems, $sortFn);
        usort($oogItems, $sortFn);

        return ["GPC" => $gpcItems, "GPINGC" => $gpingcItems, "OOG" => $oogItems];
    }

    private function categorizeItem(Item $item): string {
        // Check GPC: Fits through standard GP container doors and internal dimensions
        $gpContainersForCheck = array_filter([$this->ref20ftGP, $this->ref40ftGP, $this->ref40ftHC]); // HC can sometimes be used for GPC

        $fitsAnyGpDoor = false;
        $fitsAnyGpInternal = false;

        foreach ($gpContainersForCheck as $gpContainer) {
            if ($gpContainer === null) continue;
            if ($this->checkFit($item, $gpContainer, true)) { // Check door
                $fitsAnyGpDoor = true;
            }
            if ($this->checkFit($item, $gpContainer, false)) { // Check internal
                $fitsAnyGpInternal = true;
            }
            if ($fitsAnyGpDoor && $fitsAnyGpInternal) { // Found a GP it fully fits
                return 'GPC';
            }
        }
        // If it fits internally in a GP but not through any GP door, it might be GPINGC (if it fits OpenTop)
        // Or if it didn't fit any GP door but fits an OpenTop
        if ($this->refOpenTop !== null && $this->checkFit($item, $this->refOpenTop, false)) { // Check internal for OpenTop
            // If it fits an OpenTop internally, AND EITHER it didn't fit GP doors OR it could fit GP internal (but not door)
             if (!$fitsAnyGpDoor || ($fitsAnyGpInternal && !$fitsAnyGpDoor)) {
                return 'GPINGC';
             }
        }

        // OOG: If not GPC and not GPINGC. More robust check would be against all container types including flat racks.
        // For now, this simplified fallback.
        // A true OOG check: if any one dimension of the item (in all orientations) is greater than
        // the corresponding internal dimension of ALL available relevant container types (GP, OT, FR).
        // Or if it requires special handling not fitting above categories.
        return 'OOG';
    }

    /**
     * Checks if an item fits into a container, optionally checking against door dimensions.
     * @param Item $item The item to check.
     * @param Container $container The container to check against.
     * @param bool $checkDoor If true, checks against door dimensions; otherwise, internal dimensions.
     * @return bool True if the item fits, false otherwise.
     */
    private function checkFit(Item $item, Container $container, bool $checkDoor): bool {
        $containerWidth = $checkDoor ? $container->doorWidth : $container->width;
        $containerLength = $checkDoor ? $container->length : $container->length; // Door doesn't constrain length directly, but internal does
        $containerHeight = $checkDoor ? $container->doorHeight : $container->height;

        if ($checkDoor && $container->doorWidth == 0) return false; // No door to check (e.g. Flat Rack for internal check)

        for ($orientation = 0; $orientation < $item->getNumberOfOrientations(); $orientation++) {
            $dims = $item->getOrientedDimensions($orientation); // ['width', 'length', 'height']

            // For door check, item's height must pass container's door height.
            // One of item's horizontal dims must pass door width, other must pass container length (as it enters)
            if ($checkDoor) {
                 if ($dims['height'] <= $containerHeight &&
                    (($dims['width'] <= $containerWidth && $dims['length'] <= $containerLength) || // Enters straight
                     ($dims['length'] <= $containerWidth && $dims['width'] <= $containerLength))    // Enters turned
                 ) {
                    return true;
                }
            } else { // Internal check
                if ($dims['width'] <= $containerWidth &&
                    $dims['length'] <= $containerLength &&
                    $dims['height'] <= $containerHeight) {
                    return true;
                }
            }
        }
        return false;
    }
}
```

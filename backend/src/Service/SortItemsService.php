```php
<?php

namespace App\Service;

use App\Model\Item;
use App\Model\Container;
use App\Config\ContainerLoader;

class SortItemsService {
    private array $allContainers;
    private ?Container $ref20ftGP = null;
    private ?Container $ref40ftGP = null;
    private ?Container $ref40ftHC = null;
    private ?Container $refOpenTop = null;
    private const EPSILON = 0.01;


    public function __construct(?array $containers = null) {
        $this->allContainers = $containers ?? ContainerLoader::getAllContainers();
        $this->setRepresentativeContainers();
    }

    private function setRepresentativeContainers(): void {
        // Prioritize specific keys for known typical containers
        $this->ref20ftGP = $this->allContainers['20ftGPWood'] ?? null;
        $this->ref40ftGP = $this->allContainers['40ftGPWood'] ?? null;
        $this->ref40ftHC = $this->allContainers['40ftHCWood'] ?? null; // OOG category but can be front-loaded

        // Fallbacks if specific keys aren't present
        if (!$this->ref20ftGP) foreach ($this->allContainers as $c) if ($c->category === 'GP' && str_contains($c->key, '20ft') && in_array('front', $c->loadingTypes)) {$this->ref20ftGP = $c; break;}
        if (!$this->ref40ftGP) foreach ($this->allContainers as $c) if ($c->category === 'GP' && str_contains($c->key, '40ft') && !str_contains($c->key, 'HC') && in_array('front', $c->loadingTypes)) {$this->ref40ftGP = $c; break;}
        if (!$this->ref40ftHC) foreach ($this->allContainers as $c) if (str_contains($c->key, '40ftHC') && in_array('front', $c->loadingTypes)) {$this->ref40ftHC = $c; break;}

        foreach ($this->allContainers as $c) if ($c->isOpenTop()) {$this->refOpenTop = $c; break;}
    }

    public function groupAndCategorizeItems(array $rawItemsData): array {
        $groupedItemsMap = [];
        foreach ($rawItemsData as $itemData) {
            $requiredKeys = ['name', 'type', 'width', 'length', 'height', 'weight', 'stackable', 'tiltable', 'qty'];
            foreach($requiredKeys as $reqKey) { if(!isset($itemData[$reqKey])) throw new \InvalidArgumentException("Missing key '$reqKey' in item data for item '{$itemData['name']}'."); }
            if((int)$itemData['qty'] <=0) continue;

            // Include maxSupportOverrideKg in the grouping key if present, using -1 as a sentinel for null/not set
            $maxSupportOverrideForGrouping = isset($itemData['maxSupportOverrideKg']) ? (float)$itemData['maxSupportOverrideKg'] : -1.0;
            $key = sprintf("%s-%s-%.2f-%.2f-%.2f-%.2f-%d-%d-%.2f",
                $itemData['name'], $itemData['type'],
                (float)$itemData['width'], (float)$itemData['length'], (float)$itemData['height'],
                (float)$itemData['weight'], (bool)$itemData['stackable'], (bool)$itemData['tiltable'],
                $maxSupportOverrideForGrouping
            );

            if (!isset($groupedItemsMap[$key])) {
                $maxSupportVal = isset($itemData['maxSupportOverrideKg']) ? (float)$itemData['maxSupportOverrideKg'] : null;
                if ($maxSupportVal !== null && $maxSupportVal < 0) $maxSupportVal = null; // Treat negative override as not set

                $groupedItemsMap[$key] = new Item(
                    $itemData['name'], $itemData['type'],
                    (float)$itemData['width'], (float)$itemData['length'], (float)$itemData['height'],
                    (float)$itemData['weight'], (bool)$itemData['stackable'], (bool)$itemData['tiltable'],
                    0, // Qty will be summed
                    $maxSupportVal // Pass the override to the Item constructor
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

        $sortFn = fn(Item $a, Item $b) => $b->weight <=> $a->weight;
        usort($gpcItems, $sortFn); usort($gpingcItems, $sortFn); usort($oogItems, $sortFn);
        return ["GPC" => $gpcItems, "GPINGC" => $gpingcItems, "OOG" => $oogItems];
    }

    private function categorizeItem(Item $item): string {
        $fitsAnyGpDoorAndInternal = false;
        $fitsAnyGpInternalOnly = false; // Fits GP internal, but not necessarily door
        $fitsAnyOpenTopInternal = false;

        $gpContainersForCheck = array_filter([$this->ref20ftGP, $this->ref40ftGP, $this->ref40ftHC]);

        foreach ($gpContainersForCheck as $gpContainer) {
            if (!$gpContainer) continue;
            $canPassDoor = $this->checkFit($item, $gpContainer, true);
            $fitsInternally = $this->checkFit($item, $gpContainer, false);

            if ($canPassDoor && $fitsInternally) {
                $fitsAnyGpDoorAndInternal = true;
                break;
            }
            if ($fitsInternally) $fitsAnyGpInternalOnly = true;
        }

        if ($fitsAnyGpDoorAndInternal) return 'GPC';

        // Check OpenTop if it didn't fit GPC (either door or internal) or only fit internal but not door
        if ($this->refOpenTop && $this->checkFit($item, $this->refOpenTop, false)) {
            $fitsAnyOpenTopInternal = true;
        }

        if ($fitsAnyOpenTopInternal && ($fitsAnyGpInternalOnly && !$fitsAnyGpDoorAndInternal)) { // Fits OT, and would fit GP if not for door
             return 'GPINGC';
        }
        if ($fitsAnyOpenTopInternal && !$fitsAnyGpDoorAndInternal && !$fitsAnyGpInternalOnly ) { // Fits OT, but not any GP internal/door
             return 'GPINGC'; // Still GPINGC as it needs open top loading
        }

        // OOG: If not GPC and not GPINGC. This needs a more robust check against ALL container types (incl flat racks)
        // For now, if it couldn't be GPC or GPINGC by the representative checks.
        // A true OOG means it exceeds internal dimensions of *any* suitable container type.
        // For simplicity: If it didn't fit any GP internal and didn't fit OpenTop internal, it's likely OOG.
        if (!$fitsAnyGpInternalOnly && !$fitsAnyOpenTopInternal) {
             // Final check against all containers for OOG (most robust)
            foreach($this->allContainers as $c) {
                if($this->checkFit($item, $c, false)) return 'OOG'; // If it fits *any* container internally, but not GP/OT rules above, it's special OOG
            }
            return 'OOG'; // Truly doesn't fit anything, or exceeds all specific category rules.
        }


        return 'OOG'; // Default to OOG if not clearly GPC/GPINGC by above logic
    }

    private function checkFit(Item $item, Container $container, bool $checkDoor): bool {
        $targetWidth = $checkDoor ? $container->doorWidth : $container->width;
        $targetLength = $container->length; // Length of container is for internal check, door doesn't restrict this way
        $targetHeight = $checkDoor ? $container->doorHeight : $container->height;

        if ($checkDoor && ($container->doorWidth < self::EPSILON || $container->doorHeight < self::EPSILON)) return false;
        if ($targetWidth < self::EPSILON || $targetHeight < self::EPSILON) return false;


        for ($orientation = 0; $orientation < $item->getNumberOfOrientations(); $orientation++) {
            $dims = $item->getOrientedDimensions($orientation);

            if ($checkDoor) {
                // Item must pass with its height <= doorHeight
                // And one of its horizontal dimensions (w,l) <= doorWidth, while the other <= containerLength (for depth clearance)
                if ($dims['height'] <= ($targetHeight + self::EPSILON)) {
                    if (($dims['width'] <= ($targetWidth + self::EPSILON) && $dims['length'] <= ($container->length + self::EPSILON)) ||
                        ($dims['length'] <= ($targetWidth + self::EPSILON) && $dims['width'] <= ($container->length + self::EPSILON))) {
                        return true;
                    }
                }
            } else { // Internal check
                if ($dims['width'] <= ($targetWidth + self::EPSILON) &&
                    $dims['length'] <= ($targetLength + self::EPSILON) && // targetLength is container->length here
                    $dims['height'] <= ($targetHeight + self::EPSILON)) {
                    return true;
                }
            }
        }
        return false;
    }
}

// Ensure helper is available if not globally defined via autoloader
namespace App\Service;
if (!function_exists('App\\Service\\in_array_float_namespaced')) {
    function in_array_float_namespaced($needle, $haystack, $epsilon = 0.01): bool {
        foreach ($haystack as $value) {
            if (abs((float)$value - (float)$needle) < $epsilon) {
                return true;
            }
        }
        return false;
    }
}
```

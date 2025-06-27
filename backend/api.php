```php
<?php

// Ensure the path to autoload.php is correct if using Composer
// require_once __DIR__ . '/vendor/autoload.php';

// Manual requires if not using Composer (adjust paths as needed)
require_once __DIR__ . '/src/Model/Item.php';
require_once __DIR__ . '/src/Model/Container.php';
require_once __DIR__ . '/src/Model/BoxArea.php';
require_once __DIR__ . '/src/Model/PlacedItem.php';
require_once __DIR__ . '/src/Config/ContainerLoader.php';
require_once __DIR__ . '/src/Config/ItemTypeConfig.php';
require_once __DIR__ . '/src/Service/SortItemsService.php';
require_once __DIR__ . '/src/Service/GetContainersService.php';
require_once __DIR__ . '/src/Service/PlacementService.php';

use App\Service\SortItemsService;
use App\Service\GetContainersService;
use App\Service\PlacementService;
use App\Config\ItemTypeConfig;
use App\Model\PlacedItem; // For type hinting in array_map
use App\Model\BoxArea;    // For type hinting in array_map
use App\Model\Item;       // For type hinting in array_map for unplaced

// --- Error Reporting & Headers ---
ini_set('display_errors', 1); // Should be 0 in production
error_reporting(E_ALL);     // Should be less verbose in production

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // Restrict in production
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    // Handle preflight request for CORS
    exit(0);
}

// --- Global Configuration Loading ---
try {
    // Load item type configurations (once)
    // Pass null to use default path, or specify one e.g. from an environment variable
    ItemTypeConfig::loadConfig(null);
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Failed to load server configuration: " . $e->getMessage(),
        "requestName" => "Configuration Error"
    ]);
    exit;
}


// --- Main API Logic ---
$response = [];
$requestName = "Unknown Request";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $inputJson = file_get_contents('php://input');
    $inputData = json_decode($inputJson, true);
    $requestName = $inputData['name'] ?? $requestName;

    if (json_last_error() !== JSON_ERROR_NONE || !isset($inputData['items']) || !is_array($inputData['items'])) {
        $response = [
            "status" => "error",
            "message" => "Invalid JSON input or missing 'items' array.",
            "requestName" => $requestName
        ];
        http_response_code(400);
        echo json_encode($response, JSON_PRETTY_PRINT);
        exit;
    }

    $rawItems = $inputData['items'];

    try {
        // Initialize services
        $sortService = new SortItemsService(); // ContainerLoader is called within its constructor
        $getContainersService = new GetContainersService();
        $placementService = new PlacementService();

        // Step 1: Sort and Categorize Items
        $categorizedItemGroups = $sortService->groupAndCategorizeItems($rawItems);
        $gpcItemGroups = $categorizedItemGroups['GPC'];
        $gpingcItemGroups = $categorizedItemGroups['GPINGC']; // For future use
        $oogItemGroups = $categorizedItemGroups['OOG'];       // For future use

        // For now, focusing on GPC. TODO: Implement full GPC->GPINGC->OOG logic flow.
        $itemsToProcessForContainerSelection = $gpcItemGroups;
        $overallUnplacedItems = []; // Will collect items unplaced from all categories

        // Step 2: Get Container(s)
        // This needs to become iterative if PlacementService returns unplaced items
        // that need to be re-fed into GetContainersService.
        $selectedContainerInstances = $getContainersService->selectContainersForGPC($itemsToProcessForContainerSelection);

        $processedContainersDetails = [];
        $finalPlacedItemsOverallCount = 0;
        $finalTotalWeightPlaced = 0;
        $finalTotalVolumePlaced = 0;

        $itemsSuccessfullyPlacedInAnyContainer = [];


        if (empty($selectedContainerInstances) && !empty($itemsToProcessForContainerSelection)) {
            // All items initially assigned become unplaced if no containers selected
            foreach($itemsToProcessForContainerSelection as $group) {
                for($i=0; $i < $group->qty; $i++) {
                    $tempItem = clone $group; $tempItem->qty=1; $tempItem->originalQtyIndex = $i +1; // Simplistic index
                    $overallUnplacedItems[] = $tempItem;
                }
            }
        } else {
            foreach ($selectedContainerInstances as $containerInstance) {
                $placementResult = $placementService->PlaceItemsInContainer(
                    $containerInstance,
                    $containerInstance->assignedItems
                );

                // Add successfully placed items from this container to a master list for summary
                foreach($placementResult['placedItems'] as $pItem) {
                     $itemsSuccessfullyPlacedInAnyContainer[] = $pItem; // $pItem is PlacedItem object
                }
                // Add items unplaced from THIS container to the overall unplaced list
                // These might be tried in a *new* container if the overall logic supports it
                foreach($placementResult['unplacedItems'] as $upItem) {
                    $overallUnplacedItems[] = $upItem; // $upItem is Item object
                }

                $containerLoadWeight = 0;
                $containerLoadVolume = 0;
                foreach($placementResult['placedItems'] as $pItem) {
                    $containerLoadWeight += $pItem->weight;
                    $containerLoadVolume += $pItem->originalDimensions['width'] * $pItem->originalDimensions['length'] * $pItem->originalDimensions['height'];
                }

                $processedContainersDetails[] = [
                    "containerKey" => $containerInstance->key,
                    "containerName" => $containerInstance->name,
                    "containerDimensions" => [
                        "width" => $containerInstance->width,
                        "length" => $containerInstance->length,
                        "height" => $containerInstance->height,
                        "usableVolume" => $containerInstance->usableVolume,
                        "usablePayload" => $containerInstance->usablePayload
                    ],
                    "floorType" => $containerInstance->floorType,
                    "loadSummary" => [
                        "itemCount" => count($placementResult['placedItems']),
                        "totalWeight" => round($containerLoadWeight, 2),
                        "totalVolume" => round($containerLoadVolume, 2),
                        "volumeUtilizationPercent" => $containerInstance->usableVolume > 0.01 ? round(($containerLoadVolume / $containerInstance->usableVolume) * 100, 2) : 0,
                        "payloadUtilizationPercent" => $containerInstance->usablePayload > 0.01 ? round(($containerLoadWeight / $containerInstance->usablePayload) * 100, 2) : 0
                    ],
                    "placedItems" => array_map(function(PlacedItem $p) {
                        return [
                            "itemName" => $p->itemName, "originalQtyIndex" => $p->originalQtyIndex,
                            "type" => $p->type, "originalDimensions" => $p->originalDimensions,
                            "weight" => $p->weight,
                            "placement" => ["x" => $p->x, "y" => $p->y, "z" => $p->z,
                                "orientedWidth" => $p->orientedWidth, "orientedLength" => $p->orientedLength, "orientedHeight" => $p->orientedHeight]
                        ];
                    }, $placementResult['placedItems']),
                    "layers" => $placementResult['layers'], // Add the new layers data directly
                    "remainingEmptyBoxAreas" => array_map(function(BoxArea $ba) {
                        return ["id"=>$ba->id, "x"=>$ba->x, "y"=>$ba->y, "z"=>$ba->z, "width"=>$ba->width, "length"=>$ba->length, "height"=>$ba->height];
                    }, $placementResult['finalEmptyBoxAreas'])
                ];
            }
        }

        // Overall Summary Calculation
        // This calculation should use $itemsSuccessfullyPlacedInAnyContainer which holds PlacedItem objects
        $finalTotalWeightPlaced = 0;
        $finalTotalVolumePlaced = 0; // Based on original dimensions for consistency
        if (is_array($itemsSuccessfullyPlacedInAnyContainer)) { // Ensure it's an array
            foreach($itemsSuccessfullyPlacedInAnyContainer as $pItemObject) {
                if ($pItemObject instanceof PlacedItem) {
                    $finalTotalWeightPlaced += $pItemObject->weight;
                    $finalTotalVolumePlaced += $pItemObject->originalDimensions['width'] * $pItemObject->originalDimensions['length'] * $pItemObject->originalDimensions['height'];
                }
            }
        }
        $totalItemsToPlaceCount = array_reduce($rawItems, fn($sum, $itemData) => $sum + (int)($itemData['qty'] ?? 0),0);


        $response = [
            "requestName" => $requestName,
            "status" => empty($overallUnplacedItems) ? "success" : (count($itemsSuccessfullyPlacedInAnyContainer) > 0 ? "partial_fit" : "no_fit"),
            "summary" => [
                "totalItemsToPlace" => $totalItemsToPlaceCount,
                "totalItemsPlaced" => count($itemsSuccessfullyPlacedInAnyContainer), // Count of PlacedItem objects
                "totalWeightPlaced" => round($finalTotalWeightPlaced,2),
                "totalVolumePlaced" => round($finalTotalVolumePlaced,2),
            ],
            "containers" => $processedContainersDetails,
            "unplacedItems" => array_map(function(Item $item) {
                 return ["itemName" => $item->name, "type" => $item->type, "originalQtyIndex" => $item->originalQtyIndex, "reason" => "No suitable space found or constraints not met"];
            }, $overallUnplacedItems)
        ];
        http_response_code(200);

    } catch (\InvalidArgumentException $e) {
        $response = ["status" => "error", "message" => "Invalid input: " . $e->getMessage(), "requestName" => $requestName];
        http_response_code(400);
    } catch (\Exception $e) {
        // Log the full error for debugging on the server
        error_log("API Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
        $response = ["status" => "error", "message" => "An unexpected server error occurred.", "requestName" => $requestName];
        http_response_code(500);
    }

} else {
    $response = ["status" => "error", "message" => "Invalid request method. Please use POST."];
    http_response_code(405);
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

?>
```

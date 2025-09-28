<?php
declare(strict_types=1);

/**
 * Stock Transfers API Router
 * 
 * Main API endpoint that handles all freight carrier requests
 * with dynamic token-based authentication per outlet.
 * 
 * @author Pearce Stephens <pearce.stephens@ecigdis.co.nz>
 * @copyright 2025 Ecigdis Limited
 * @since 1.0.0
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/AuthManager.php';

// Set JSON response headers
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer-when-downgrade');

// CORS headers for JavaScript requests
$allowedOrigins = [
    'https://staff.vapeshed.co.nz',
    'https://vapeshed.co.nz',
    'https://www.vapeshed.co.nz',
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins)) {
    header("Access-Control-Allow-Origin: {$origin}");
}

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    // Initialize authentication manager
    $authManager = new ApiAuthManager();
    
    // Get request parameters
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    $carrier = $_GET['carrier'] ?? $_POST['carrier'] ?? '';
    
    if (empty($action)) {
        jsonResponse([
            'success' => false,
            'error' => 'Missing action parameter',
            'available_actions' => [
                'auth_status', 'rates', 'create_label', 'track', 'void_label',
                'address_validate', 'services', 'capabilities'
            ]
        ], 400);
    }
    
    // Route to appropriate handler
    switch ($action) {
        case 'auth_status':
            handleAuthStatus($authManager);
            break;
            
        case 'rates':
            handleRates($authManager, $carrier);
            break;
            
        case 'create_label':
            handleCreateLabel($authManager, $carrier);
            break;
            
        case 'track':
            handleTracking($authManager, $carrier);
            break;
            
        case 'void_label':
            handleVoidLabel($authManager, $carrier);
            break;
            
        case 'address_validate':
            handleAddressValidation($authManager, $carrier);
            break;
            
        case 'services':
            handleServices($authManager, $carrier);
            break;
            
        case 'capabilities':
            handleCapabilities($authManager, $carrier);
            break;
            
        default:
            jsonResponse([
                'success' => false,
                'error' => "Unknown action: {$action}",
            ], 400);
    }
    
} catch (Exception $e) {
    jsonResponse([
        'success' => false,
        'error' => 'API request failed',
        'message' => config('app.debug') ? $e->getMessage() : 'Internal server error',
        'timestamp' => date('c'),
    ], 500);
}

/**
 * Handle authentication status check
 */
function handleAuthStatus(ApiAuthManager $authManager): void
{
    jsonResponse([
        'success' => true,
        'data' => $authManager->getAuthStatus(),
    ]);
}

/**
 * Handle freight rates request
 */
function handleRates(ApiAuthManager $authManager, string $carrier): void
{
    $requestData = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    
    if (empty($requestData)) {
        jsonResponse([
            'success' => false,
            'error' => 'Missing rate request data',
            'required_fields' => [
                'from_address', 'to_address', 'parcels'
            ]
        ], 400);
    }
    
    try {
        if ($carrier === 'nzpost' && $authManager->hasNzPostAuth()) {
            $result = $authManager->callNzPostApi('/rates', $requestData);
        } elseif ($carrier === 'gss' && $authManager->hasGssAuth()) {
            $result = $authManager->callGssApi('/rates', $requestData);
        } else {
            // Try all available carriers
            $results = [];
            foreach ($authManager->getAvailableCarriers() as $availableCarrier) {
                try {
                    if ($availableCarrier === 'nzpost') {
                        $results['nzpost'] = $authManager->callNzPostApi('/rates', $requestData);
                    } elseif ($availableCarrier === 'gss') {
                        $results['gss'] = $authManager->callGssApi('/rates', $requestData);
                    }
                } catch (Exception $e) {
                    $results[$availableCarrier] = [
                        'success' => false,
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            if (empty($results)) {
                jsonResponse([
                    'success' => false,
                    'error' => 'No carrier authentication available',
                    'available_carriers' => $authManager->getAvailableCarriers(),
                ], 401);
            }
            
            jsonResponse([
                'success' => true,
                'data' => $results,
                'outlet_id' => $authManager->getCurrentOutletId(),
            ]);
            return;
        }
        
        jsonResponse($result);
        
    } catch (Exception $e) {
        jsonResponse([
            'success' => false,
            'error' => $e->getMessage(),
            'carrier' => $carrier,
        ], 500);
    }
}

/**
 * Handle label creation request
 */
function handleCreateLabel(ApiAuthManager $authManager, string $carrier): void
{
    $requestData = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    
    if (empty($requestData)) {
        jsonResponse([
            'success' => false,
            'error' => 'Missing label creation data',
        ], 400);
    }
    
    try {
        if ($carrier === 'nzpost' && $authManager->hasNzPostAuth()) {
            $result = $authManager->callNzPostApi('/labels', $requestData);
        } elseif ($carrier === 'gss' && $authManager->hasGssAuth()) {
            $result = $authManager->callGssApi('/labels', $requestData);
        } else {
            jsonResponse([
                'success' => false,
                'error' => "Carrier '{$carrier}' not available or not authenticated",
                'available_carriers' => $authManager->getAvailableCarriers(),
            ], 401);
        }
        
        jsonResponse($result);
        
    } catch (Exception $e) {
        jsonResponse([
            'success' => false,
            'error' => $e->getMessage(),
            'carrier' => $carrier,
        ], 500);
    }
}

/**
 * Handle tracking request
 */
function handleTracking(ApiAuthManager $authManager, string $carrier): void
{
    $trackingNumber = $_GET['tracking_number'] ?? $_POST['tracking_number'] ?? '';
    
    if (empty($trackingNumber)) {
        jsonResponse([
            'success' => false,
            'error' => 'Missing tracking number',
        ], 400);
    }
    
    try {
        if ($carrier === 'nzpost' && $authManager->hasNzPostAuth()) {
            $result = $authManager->callNzPostApi("/tracking/{$trackingNumber}", [], 'GET');
        } elseif ($carrier === 'gss' && $authManager->hasGssAuth()) {
            $result = $authManager->callGssApi("/tracking/{$trackingNumber}", [], 'GET');
        } else {
            jsonResponse([
                'success' => false,
                'error' => "Carrier '{$carrier}' not available or not authenticated",
                'available_carriers' => $authManager->getAvailableCarriers(),
            ], 401);
        }
        
        jsonResponse($result);
        
    } catch (Exception $e) {
        jsonResponse([
            'success' => false,
            'error' => $e->getMessage(),
            'tracking_number' => $trackingNumber,
            'carrier' => $carrier,
        ], 500);
    }
}

/**
 * Handle void label request
 */
function handleVoidLabel(ApiAuthManager $authManager, string $carrier): void
{
    $labelId = $_POST['label_id'] ?? '';
    
    if (empty($labelId)) {
        jsonResponse([
            'success' => false,
            'error' => 'Missing label ID',
        ], 400);
    }
    
    try {
        if ($carrier === 'nzpost' && $authManager->hasNzPostAuth()) {
            $result = $authManager->callNzPostApi("/labels/{$labelId}/void", [], 'PUT');
        } elseif ($carrier === 'gss' && $authManager->hasGssAuth()) {
            $result = $authManager->callGssApi("/labels/{$labelId}/void", [], 'PUT');
        } else {
            jsonResponse([
                'success' => false,
                'error' => "Carrier '{$carrier}' not available or not authenticated",
                'available_carriers' => $authManager->getAvailableCarriers(),
            ], 401);
        }
        
        jsonResponse($result);
        
    } catch (Exception $e) {
        jsonResponse([
            'success' => false,
            'error' => $e->getMessage(),
            'label_id' => $labelId,
            'carrier' => $carrier,
        ], 500);
    }
}

/**
 * Handle address validation request
 */
function handleAddressValidation(ApiAuthManager $authManager, string $carrier): void
{
    $requestData = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    
    if (empty($requestData['address'])) {
        jsonResponse([
            'success' => false,
            'error' => 'Missing address data',
        ], 400);
    }
    
    try {
        if ($carrier === 'nzpost' && $authManager->hasNzPostAuth()) {
            $result = $authManager->callNzPostApi('/address/validate', $requestData);
        } elseif ($carrier === 'gss' && $authManager->hasGssAuth()) {
            $result = $authManager->callGssApi('/address/validate', $requestData);
        } else {
            jsonResponse([
                'success' => false,
                'error' => "Carrier '{$carrier}' not available or not authenticated",
                'available_carriers' => $authManager->getAvailableCarriers(),
            ], 401);
        }
        
        jsonResponse($result);
        
    } catch (Exception $e) {
        jsonResponse([
            'success' => false,
            'error' => $e->getMessage(),
            'carrier' => $carrier,
        ], 500);
    }
}

/**
 * Handle services request
 */
function handleServices(ApiAuthManager $authManager, string $carrier): void
{
    try {
        if ($carrier === 'nzpost' && $authManager->hasNzPostAuth()) {
            $result = $authManager->callNzPostApi('/services', [], 'GET');
        } elseif ($carrier === 'gss' && $authManager->hasGssAuth()) {
            $result = $authManager->callGssApi('/services', [], 'GET');
        } else {
            // Return services for all available carriers
            $results = [];
            foreach ($authManager->getAvailableCarriers() as $availableCarrier) {
                try {
                    if ($availableCarrier === 'nzpost') {
                        $results['nzpost'] = $authManager->callNzPostApi('/services', [], 'GET');
                    } elseif ($availableCarrier === 'gss') {
                        $results['gss'] = $authManager->callGssApi('/services', [], 'GET');
                    }
                } catch (Exception $e) {
                    $results[$availableCarrier] = [
                        'success' => false,
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            jsonResponse([
                'success' => true,
                'data' => $results,
                'outlet_id' => $authManager->getCurrentOutletId(),
            ]);
            return;
        }
        
        jsonResponse($result);
        
    } catch (Exception $e) {
        jsonResponse([
            'success' => false,
            'error' => $e->getMessage(),
            'carrier' => $carrier,
        ], 500);
    }
}

/**
 * Handle capabilities request
 */
function handleCapabilities(ApiAuthManager $authManager, string $carrier): void
{
    jsonResponse([
        'success' => true,
        'data' => [
            'outlet_id' => $authManager->getCurrentOutletId(),
            'available_carriers' => $authManager->getAvailableCarriers(),
            'supported_actions' => [
                'auth_status', 'rates', 'create_label', 'track', 'void_label',
                'address_validate', 'services', 'capabilities'
            ],
            'carrier_capabilities' => [
                'nzpost' => [
                    'rates' => $authManager->hasNzPostAuth(),
                    'labels' => $authManager->hasNzPostAuth(),
                    'tracking' => $authManager->hasNzPostAuth(),
                    'address_validation' => $authManager->hasNzPostAuth(),
                ],
                'gss' => [
                    'rates' => $authManager->hasGssAuth(),
                    'labels' => $authManager->hasGssAuth(),
                    'tracking' => $authManager->hasGssAuth(),
                    'address_validation' => $authManager->hasGssAuth(),
                ],
            ],
            'timestamp' => date('c'),
        ],
    ]);
}
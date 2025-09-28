# Stock Transfers API Documentation

## Overview

The Stock Transfers API provides a unified interface for communicating with multiple freight carriers (NZ Post and GSS/NZ Couriers) using outlet-specific authentication tokens. The API automatically handles token-based authentication and routes requests to the appropriate carrier based on available credentials.

## Authentication

### Token Management

Each outlet has its own set of API tokens stored in the database:

```sql
-- Example tokens per outlet
outlet_id: store_001
├── nzpost_subscription_key: "ad1bd29013ea4b56ab2036f4d1dabb0b"
├── nzpost_api_key: "c9ab68a9c1f74c688e00880d61fd790a"  
└── gss_token: "suppo-6029-93353FCA505E0FC540F3616962041535328E2AB"
```

### JavaScript Token Injection

Tokens are automatically injected into JavaScript context based on the current outlet:

```php
<?php
require_once 'stock/api/TokenInjector.php';

// In your PHP template
$outletId = getCurrentOutletId(); // Your function to get current outlet
echo injectApiTokens($outletId);
?>
```

This generates:

```html
<script>
window.CURRENT_OUTLET_ID = "store_001";
window.NZPOST_SUBSCRIPTION_KEY = "ad1bd29013ea4b56ab2036f4d1dabb0b";
window.NZPOST_API_KEY = "c9ab68a9c1f74c688e00880d61fd790a";
window.GSS_TOKEN = "suppo-6029-93353FCA505E0FC540F3616962041535328E2AB";
</script>
```

## API Endpoints

### Base URL
```
POST /stock/api/router.php
```

### Request Format

All requests should be sent as JSON POST requests:

```javascript
{
    "action": "rates",
    "carrier": "nzpost",  // Optional: "nzpost", "gss", or null for all
    "outlet_id": "store_001",
    "tokens": "{\"nzpost_api_key\":\"...\",\"gss_token\":\"...\"}",
    // ... additional data based on action
}
```

### Response Format

```javascript
{
    "success": true|false,
    "data": {...},           // Response data (on success)
    "error": "Error message", // Error message (on failure)
    "http_code": 200,        // HTTP status code
    "outlet_id": "store_001", // Outlet context
    "timestamp": "2025-09-28T10:30:00+12:00"
}
```

## Available Actions

### 1. Authentication Status

Check authentication status and available carriers.

**Request:**
```javascript
{
    "action": "auth_status"
}
```

**Response:**
```javascript
{
    "success": true,
    "data": {
        "outlet_id": "store_001",
        "available_carriers": ["nzpost", "gss"],
        "nzpost_auth": true,
        "gss_auth": true,
        "timestamp": "2025-09-28T10:30:00+12:00"
    }
}
```

### 2. Get Freight Rates

Get shipping rates from one or all carriers.

**Request:**
```javascript
{
    "action": "rates",
    "carrier": "nzpost", // Optional
    "from_address": {
        "address": "123 Queen Street",
        "suburb": "Auckland Central", 
        "city": "Auckland",
        "postcode": "1010"
    },
    "to_address": {
        "address": "456 Colombo Street",
        "suburb": "Christchurch Central",
        "city": "Christchurch", 
        "postcode": "8011"
    },
    "parcels": [{
        "weight": 2.5,
        "dimensions": {
            "length": 30,
            "width": 20, 
            "height": 10
        }
    }]
}
```

**Response:**
```javascript
{
    "success": true,
    "data": {
        "nzpost": {
            "success": true,
            "data": {
                "rates": [
                    {
                        "service_code": "PARCEL_POST",
                        "service_name": "ParcelPost",
                        "cost": 8.50,
                        "currency": "NZD",
                        "delivery_time": "3-5 business days"
                    }
                ]
            }
        },
        "gss": {
            "success": true, 
            "data": {
                "rates": [...]
            }
        }
    }
}
```

### 3. Create Shipping Label

Create a shipping label with a specific carrier.

**Request:**
```javascript
{
    "action": "create_label",
    "carrier": "nzpost",
    "service_code": "PARCEL_POST",
    "from_address": {...},
    "to_address": {...},
    "parcels": [{...}],
    "reference": "TRANSFER-001"
}
```

**Response:**
```javascript
{
    "success": true,
    "data": {
        "label_id": "LABEL123456",
        "tracking_number": "1234567890",
        "label_url": "https://api.nzpost.co.nz/labels/LABEL123456.pdf",
        "cost": 8.50,
        "service_used": "PARCEL_POST"
    }
}
```

### 4. Track Shipment

Track a shipment by tracking number.

**Request:**
```javascript
{
    "action": "track",
    "carrier": "nzpost",
    "tracking_number": "1234567890"
}
```

**Response:**
```javascript
{
    "success": true,
    "data": {
        "tracking_number": "1234567890",
        "status": "in_transit",
        "status_description": "Parcel is in transit",
        "events": [
            {
                "timestamp": "2025-09-28T08:00:00+12:00",
                "location": "Auckland",
                "description": "Picked up"
            },
            {
                "timestamp": "2025-09-28T14:30:00+12:00", 
                "location": "Hamilton",
                "description": "In transit"
            }
        ]
    }
}
```

### 5. Void Label

Cancel a shipping label before pickup.

**Request:**
```javascript
{
    "action": "void_label",
    "carrier": "nzpost",
    "label_id": "LABEL123456"
}
```

**Response:**
```javascript
{
    "success": true,
    "data": {
        "label_id": "LABEL123456",
        "status": "voided",
        "refund_amount": 8.50
    }
}
```

### 6. Validate Address

Validate and standardize an address.

**Request:**
```javascript
{
    "action": "address_validate",
    "carrier": "nzpost",
    "address": "123 Queen Street, Auckland 1010"
}
```

**Response:**
```javascript
{
    "success": true,
    "data": {
        "valid": true,
        "standardized_address": {
            "address": "123 Queen Street",
            "suburb": "Auckland Central",
            "city": "Auckland",
            "postcode": "1010",
            "country": "New Zealand"
        },
        "suggestions": []
    }
}
```

### 7. Get Services

Get available services from carriers.

**Request:**
```javascript
{
    "action": "services",
    "carrier": "nzpost" // Optional
}
```

**Response:**
```javascript
{
    "success": true,
    "data": {
        "nzpost": {
            "success": true,
            "data": {
                "services": [
                    {
                        "code": "PARCEL_POST",
                        "name": "ParcelPost",
                        "description": "Standard parcel delivery",
                        "max_weight": 25,
                        "delivery_time": "3-5 business days"
                    }
                ]
            }
        }
    }
}
```

### 8. Get Capabilities

Get API capabilities and configuration.

**Request:**
```javascript
{
    "action": "capabilities"
}
```

**Response:**
```javascript
{
    "success": true,
    "data": {
        "outlet_id": "store_001",
        "available_carriers": ["nzpost", "gss"],
        "supported_actions": [
            "auth_status", "rates", "create_label", "track", 
            "void_label", "address_validate", "services", "capabilities"
        ],
        "carrier_capabilities": {
            "nzpost": {
                "rates": true,
                "labels": true,
                "tracking": true,
                "address_validation": true
            },
            "gss": {
                "rates": true,
                "labels": true, 
                "tracking": true,
                "address_validation": false
            }
        }
    }
}
```

## JavaScript Client Usage

### Basic Usage

```javascript
// API client is auto-initialized as window.StockTransfersAPI

// Check authentication
const authStatus = await StockTransfersAPI.checkAuthStatus();
console.log('Available carriers:', authStatus.data.available_carriers);

// Get rates
const rates = await StockTransfersAPI.getRates({
    from_address: { address: "123 Queen St", city: "Auckland", postcode: "1010" },
    to_address: { address: "456 Colombo St", city: "Christchurch", postcode: "8011" },
    parcels: [{ weight: 2.5, dimensions: { length: 30, width: 20, height: 10 } }]
});

// Create label
const label = await StockTransfersAPI.createLabel({
    service_code: "PARCEL_POST",
    from_address: {...},
    to_address: {...},
    parcels: [{...}]
}, 'nzpost');

// Track shipment
const tracking = await StockTransfersAPI.trackShipment('1234567890', 'nzpost');
```

### Error Handling

```javascript
try {
    const result = await StockTransfersAPI.getRates(rateData);
    if (result.success) {
        // Handle success
        console.log('Rates:', result.data);
    } else {
        // Handle API error
        console.error('API Error:', result.error);
    }
} catch (error) {
    // Handle network/system error
    console.error('Network Error:', error.message);
    StockTransfersAPI.displayError(error, '#error-container');
}
```

### jQuery Integration

```javascript
// jQuery-style usage
$('#get-rates-btn').stockTransfersAPI({
    action: 'rates',
    data: rateData,
    success: function(result) {
        console.log('Rates received:', result);
    },
    error: function(error) {
        console.error('Error:', error);
    }
});
```

## Setup Instructions

### 1. Database Setup

Run the SQL migration to create the tokens table:

```bash
mysql -u username -p database_name < stock/sql/create_outlet_api_tokens.sql
```

### 2. Configure Tokens

Insert your real API tokens for each outlet:

```sql
UPDATE outlet_api_tokens 
SET 
    nzpost_subscription_key = 'your-real-nzpost-subscription-key',
    nzpost_api_key = 'your-real-nzpost-api-key',
    gss_token = 'your-real-gss-token'
WHERE outlet_id = 'store_001';
```

### 3. Include JavaScript Client

```html
<script src="/modules/StockTransfers/assets/js/api-client.js"></script>
```

### 4. Inject Tokens

In your PHP template:

```php
<?php
require_once 'modules/StockTransfers/stock/api/TokenInjector.php';
$outletId = getCurrentOutletId();
echo injectApiTokens($outletId);
?>
```

## Security Considerations

- ✅ Tokens are outlet-specific and stored encrypted in database
- ✅ No tokens are logged in plain text
- ✅ CORS restrictions limit API access to authorized domains
- ✅ Input validation and sanitization on all requests
- ✅ Rate limiting can be implemented per outlet
- ✅ SSL/TLS required for all API communications

## Error Codes

| Code | Description |
|------|-------------|
| 400  | Bad Request - Missing or invalid parameters |
| 401  | Unauthorized - No valid tokens for requested carrier |
| 403  | Forbidden - Access denied |
| 404  | Not Found - Invalid endpoint or resource |
| 429  | Too Many Requests - Rate limit exceeded |
| 500  | Internal Server Error - System error |

## Rate Limits

- 100 requests per minute per outlet
- 1000 requests per hour per outlet  
- 10,000 requests per day per outlet

## Support

For technical support:
- **Internal**: pearce.stephens@ecigdis.co.nz
- **Documentation**: https://wiki.vapeshed.co.nz
- **API Status**: Check `/stock/api/router.php?action=auth_status`
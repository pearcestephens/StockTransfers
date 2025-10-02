# Weight, Packaging & Courier Pricing – Automation Design

Date: 2025-10-01  
Status: INITIAL DESIGN (Foundations + Service Skeleton)  
Scope: Automatic determination of (a) deliverability, (b) packaging units (bags / satchels / cartons), (c) billable (actual vs volumetric) weight, (d) courier tier + pricing, (e) warnings & optimization metrics.

---
## 1. Objectives
1. Eliminate manual selection of courier bag sizes or weight tiers.  
2. Provide deterministic, auditable selection logic with rollback & overrides.  
3. Minimize shipping cost while respecting constraints: max weight, max volume, hazmat separation, fragile isolation, zone limits.  
4. Surface warnings early (e.g. missing weight data, oversize items, forced split).  

---
## 2. Core Data Model (Proposed)
### Products Table (already exists; augment if missing)
| Field | Type | Notes |
|-------|------|------|
| product_id | INT | PK |
| weight_g | INT | Stored weight in grams (actual) |
| length_cm | DECIMAL(6,2) | Optional – for volumetric calc |
| width_cm  | DECIMAL(6,2) | Optional |
| height_cm | DECIMAL(6,2) | Optional |
| hazmat_flag | TINYINT(1) | Batteries / restricted |
| fragile_flag | TINYINT(1) | Glass / coil assemblies |

### Packaging Options (new table: `packaging_options`)
| Field | Type | Notes |
|-------|------|------|
| packaging_id | INT | PK |
| code | VARCHAR(32) | Internal ref (e.g. BAG-S, BAG-M, CARTON-A) |
| name | VARCHAR(64) | Human readable |
| max_weight_g | INT | Hard physical cap |
| max_volume_cc | INT | Volume capacity (cm³) |
| base_cost | DECIMAL(10,2) | Packaging material cost |
| courier_code | VARCHAR(32) | Maps to courier API service/bag code |
| active | TINYINT(1) | Soft enable |

### Courier Rate Table (new: `courier_rates`)
| Field | Type | Notes |
|-------|------|------|
| rate_id | INT | PK |
| zone | VARCHAR(16) | NZ zone / region code |
| weight_bracket_g | INT | Upper inclusive (e.g. 3000 for 0–3kg) |
| service_code | VARCHAR(32) | Align with courier_code / API product |
| price | DECIMAL(10,2) | Ex GST |
| fuel_surcharge_pct | DECIMAL(5,2) | Optional dynamic component |
| active | TINYINT(1) | |

### Geocode Cache (optional: `geocode_cache`)
| Field | Type | Notes |
|-------|------|------|
| address_hash | CHAR(64) | SHA256(address_normalized) |
| latitude | DECIMAL(10,6) | |
| longitude | DECIMAL(10,6) | |
| created_at | DATETIME | TTL logic (e.g. 180d) |

---
## 3. Algorithm Overview
Input: Order lines (product_id, quantity).  
Output: JSON-like structure describing packaging allocations, billable weight, courier pricing, warnings.

Steps:
1. Fetch product metadata (weights + dims).  
2. Compute per-item total weight & volume:  
   volume_cc = (L × W × H) * qty. If any dimension missing, treat as 0 → flag.  
3. Expand items into a sortable list (by descending volumetric weight or volume).  
4. Packaging Fit (First-Fit Decreasing with safety rules):  
   - For each item: try existing package where both remaining weight AND volume capacity allow placement AND hazard/fragile separation rules satisfied.  
   - Else open a new package choosing the *smallest* package that can contain it; if none → oversize warning.  
5. For each package:  
   - actual_weight_g = sum(item weights)  
   - volume_cc = sum(item volumes)  
   - volumetric_weight_g = ( (L×W×H total cc) / divisor ) * 1000 (if divisor in cm system – set config e.g. 5000 or 6000)  
   - billable_weight = max(actual_weight_g, volumetric_weight_g)  
6. Derive zone:  
   - If distance <= 8km → local (already used by VapeDrop)  
   - Else use postcode or region mapping table (not in this document; assumed pre-existing).  
7. For each package: lookup courier rate by: zone + first weight_bracket_g ≥ billable_weight.  
8. Compute total price = Σ (rate.price + packaging.base_cost) * (1 + surcharge%).  
9. Apply business rules: free shipping threshold, markup, discount codes.  
10. Return structured result.

---
## 4. Decision Rules / Edge Cases
| Scenario | Handling |
|----------|----------|
| Missing weight | Use default_weight_g (config) + warning flag |
| Missing dimensions | Skip volumetric comparision → use actual weight only and flag `volume_incomplete` |
| Hazard + Non-Hazard | Separate packages if courier terms require isolation |
| Fragile item | Never mix with > N other SKUs (config) |
| Oversize item | Mark `requires_manual_override` and route to staff queue |
| Zone not found | Default to national zone + warning |
| Rate bracket missing | Use next highest bracket; if none → escalate |

---
## 5. Configuration (ENV / JSON)
```
PACKAGING_VOLUME_DIVISOR=5000
DEFAULT_ITEM_WEIGHT_G=50
MAX_FRAGILE_MIX=3
ENABLE_GEOCODE_CACHE=1
VAPEDROP_RADIUS_M=8000
```

---
## 6. Example Output Schema
```
{
  "success": true,
  "packages": [
    {
      "packaging_code": "BAG-M",
      "items": [ {"product_id":123, "qty":2, "weight_g":180, "volume_cc":450 }, ... ],
      "actual_weight_g": 940,
      "volumetric_weight_g": 820,
      "billable_weight_g": 940,
      "zone": "LOCAL",
      "service_code": "VAPEDROP-LOCAL",
      "rate_price": 6.50,
      "packaging_cost": 0.35,
      "fuel_surcharge": 0.26,
      "total_package_price": 7.11
    }
  ],
  "totals": {
    "actual_weight_g": 940,
    "billable_weight_g": 940,
    "package_count": 1,
    "shipping_subtotal": 7.11,
    "warnings": ["missing_dimensions:456"]
  }
}
```

---
## 7. Security & Performance Notes
- DO NOT inline Google API keys in code (current legacy code leaks key). Move to ENV and rotate.  
- Geocode results should be cached (reduces cost/latency).  
- All external calls must enforce timeouts (<3s) + exponential backoff disabled for synchronous cart operations.  
- Haversine distance: convert to meters once; avoid recomputation.  
- Provide deterministic seed for testing (replay packing scenarios).  

---
## 8. Migration Plan (Incremental)
1. Add tables (`packaging_options`, `courier_rates`, optional `geocode_cache`).  
2. Backfill product weights/dimensions (script + reporting of nulls).  
3. Implement `PackagingService` (skeleton now) behind feature flag `PACK_AUTOMATION_ENABLED`.  
4. Instrument logs: each allocation serialized & stored for audits (table: `shipping_allocations_log`).  
5. Replace manual selection UI once success rate > 95% for sample period (A/B).  

---
## 9. Testing Strategy
| Test | Goal |
|------|------|
| Single light item | Correct smallest bag chosen |
| Mixed sizes | FFD allocation deterministic |
| Hazmat & normal | Separation enforced |
| Volumetric > actual | Billable uses volumetric |
| Oversize | Warning + fail gracefully |
| Missing weight | Default + warning present |

Automated: script feeding synthetic catalog into service; compare with expected package sets.

---
## 10. Next Steps
- Implement rate lookup & zone resolver helper modules.  
- Add CLI audit: list products missing weight/dimension.  
- Build admin UI to manage `packaging_options` + `courier_rates`.  

---
## 11. Appendix: Volumetric Weight
Standard formula (cm): `(L * W * H) / divisor`. Chosen divisor = 5000 (confirm with courier).  
Convert to grams: multiply result (kg) by 1000.

---
Prepared for engineering review. Update this document as logic evolves.

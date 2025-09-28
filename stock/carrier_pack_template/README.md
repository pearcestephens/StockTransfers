# Shipping Control Tower – Carrier Pack Template

Purpose: Send this folder (or a ZIP of it) to any carrier / broker / logistics partner so they can supply all required data for integration (rates, labels, tracking, surcharges, compliance, permissions).

How To Use (Carrier Instructions)
1. Open each CSV in Excel / LibreOffice (UTF-8). Do not rename columns.
2. Fill rows. Leave blanks if unknown (we will propose defaults) – DO NOT delete required columns.
3. Export updated CSVs preserving the same filenames.
4. Put real sample JSON responses in `samples_json/` replacing the placeholders (quote, reserve, create_label, tracking variants, void, error).
5. Zip the whole `carrier_pack_template/` directory and return via secure channel (email or SFTP). Avoid embedding secrets in email body; keep them in `Credentials.csv` only.

Return Checklist (Carrier ticks):
- [ ] Credentials.csv complete (live + test if applicable)
- [ ] Services.csv lists every service code offered
- [ ] Dimensional.csv includes divisor per service OR global divisor note
- [ ] Zones_Rural.csv included (or explicit statement zones not used)
- [ ] Surcharges.csv lists fuel, rural, Saturday, oversize rules
- [ ] Labels.csv defines format(s) + DPI
- [ ] Permissions_Roles.csv (optional guidance) – leave if not applicable
- [ ] Compliance.csv retention + PII policy filled
- [ ] Samples_List.csv updated if additional samples provided
- [ ] JSON samples replaced with real payloads
- [ ] All secrets provided securely (API key / OAuth) – NOT screenshots

Field Guidance (Key Columns)
Credentials.csv:
  - Environment: Live or Test
  - Auth Method: API Key or OAuth
  - API Base URL: Root endpoint (e.g. https://api.carrier.com/v1)

Services.csv:
  - Service Code: Exact machine code used in API
  - Domestic/International: One of Domestic / International
  - Signature / Saturday / Rural / ATL / Insurance: Y or N
  - Max Girth: Carrier definition (usually 2*(W+H)+L) – leave blank if NA

Dimensional.csv:
  - Divisor: e.g. 5000 (cm³ per kg). One row per service if they differ.
  - Rounding Mode: ceil (most carriers) or nearest.

Zones_Rural.csv:
  - Postcode Range: Use `1230-1299; 1300-1399` pattern for multiple.
  - Rural? Y if entire range rural; else split ranges.

Surcharges.csv:
  - Calculation Method: %, per kg, per consignment
  - Rate/Value: Numeric – if % list just the percent number (e.g. 12.5)

Labels.csv:
  - Format: PDF A6, ZPL 4x6, etc.
  - Label Size (mm): width x height (e.g. 100x150)

Permissions_Roles.csv:
  - Define what roles are allowed to do (optional; helps align RBAC).

Compliance.csv:
  - Retention Period: e.g. 24 months, 7 years (regulatory) etc.

Samples_JSON:
  Replace placeholders with real raw JSON (no prettification restrictions) exactly as the carrier returns them. We use these to build parsers.

After Receipt (Our Internal Steps)
1. Run validator tool: `php modules/transfers/stock/tools/carrier_pack_validator.php /path/to/returned_pack`
2. Feed JSON samples into parser harness.
3. Generate carrier adapter mapping file.
4. Commit sanitized meta (no secrets) into repo for reproducible integration.

Security Notes
- Secrets will be stored in environment variables (.env) and NOT committed.
- Do not include live customer addresses in samples; synthetic demo addresses only.

Version: v1.0 (base)

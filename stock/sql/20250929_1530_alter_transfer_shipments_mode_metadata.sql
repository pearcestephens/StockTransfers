-- 20250929_1530_alter_transfer_shipments_mode_metadata.sql
-- Purpose: Add mode-specific metadata columns for pickup/internal/depot workflows

ALTER TABLE transfer_shipments
    ADD COLUMN IF NOT EXISTS pickup_contact_name VARCHAR(120) NULL AFTER dest_instructions,
    ADD COLUMN IF NOT EXISTS pickup_contact_phone VARCHAR(40) NULL AFTER pickup_contact_name,
    ADD COLUMN IF NOT EXISTS pickup_ready_at DATETIME NULL AFTER pickup_contact_phone,
    ADD COLUMN IF NOT EXISTS pickup_box_count INT UNSIGNED NULL AFTER pickup_ready_at,
    ADD COLUMN IF NOT EXISTS pickup_notes VARCHAR(255) NULL AFTER pickup_box_count,
    ADD COLUMN IF NOT EXISTS internal_driver_name VARCHAR(120) NULL AFTER pickup_notes,
    ADD COLUMN IF NOT EXISTS internal_vehicle VARCHAR(120) NULL AFTER internal_driver_name,
    ADD COLUMN IF NOT EXISTS internal_depart_at DATETIME NULL AFTER internal_vehicle,
    ADD COLUMN IF NOT EXISTS internal_box_count INT UNSIGNED NULL AFTER internal_depart_at,
    ADD COLUMN IF NOT EXISTS internal_notes VARCHAR(255) NULL AFTER internal_box_count,
    ADD COLUMN IF NOT EXISTS depot_location VARCHAR(160) NULL AFTER internal_notes,
    ADD COLUMN IF NOT EXISTS depot_drop_at DATETIME NULL AFTER depot_location,
    ADD COLUMN IF NOT EXISTS depot_box_count INT UNSIGNED NULL AFTER depot_drop_at,
    ADD COLUMN IF NOT EXISTS depot_notes VARCHAR(255) NULL AFTER depot_box_count,
    ADD COLUMN IF NOT EXISTS mode_notes VARCHAR(255) NULL AFTER depot_notes;

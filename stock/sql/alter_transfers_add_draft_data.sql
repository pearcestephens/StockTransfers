-- Add draft_data field to transfers table for auto-save functionality
-- This stores temporary draft data directly in the transfer record

ALTER TABLE transfers 
ADD COLUMN draft_data JSON NULL AFTER total_weight_g,
ADD COLUMN draft_updated_at TIMESTAMP NULL AFTER draft_data;
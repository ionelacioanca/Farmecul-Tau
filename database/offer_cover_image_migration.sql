-- Farmecul Tau offer cover image migration
-- Adds one optional public cover image per salon offer.

ALTER TABLE offers
	ADD COLUMN IF NOT EXISTS image_path VARCHAR(500) NULL AFTER description;

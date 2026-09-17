-- „Práve hráme": nepovinný odkaz k miestu (napr. stránka miesta alebo mapa).
-- Keď je vyplnený, miesto nad termínmi je na stránke odkazom.

ALTER TABLE runs ADD COLUMN venue_url varchar(500);

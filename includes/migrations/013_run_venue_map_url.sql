-- „Práve hráme": nepovinný odkaz na mapu k miestu. Keď je vyplnený, pod miestom
-- nad termínmi sa zobrazí „Zobraziť na mape"; prázdny = odkaz sa nezobrazí.

ALTER TABLE runs ADD COLUMN venue_map_url varchar(500);

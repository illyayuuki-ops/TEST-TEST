-- Optional: swaps the old neon office colours for the softer new palette. Only touches offices still using the old defaults.
UPDATE offices SET brand_color='#5E94CF' WHERE brand_color='#00d4ff';
UPDATE offices SET brand_color='#7B6BC4' WHERE brand_color='#a78bfa';
UPDATE offices SET brand_color='#2F9E6F' WHERE brand_color='#00e5a0';

# Corpus attribution

## Handcrafted traps

`french-search-traps.json` is original test content written for this project (GPL-compatible).

## Wikipedia bulk excerpts

`french-search-bulk.json.gz` contains short plain-text extracts from [French Wikipedia](https://fr.wikipedia.org/), fetched via the MediaWiki API.

- License: [Creative Commons Attribution-ShareAlike 4.0 International (CC BY-SA 4.0)](https://creativecommons.org/licenses/by-sa/4.0/)
- Source: Wikimedia Foundation / French Wikipedia contributors
- Each seeded post includes a source URL and a CC BY-SA credit line in the content

Regenerate with:

```bash
ddev composer fetch:bulk
```

Or:

```bash
php bin/fetch-bulk-corpus.php --count=980
```

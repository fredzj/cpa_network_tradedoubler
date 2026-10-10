# Tradedoubler sync (Vimexx cron)

`sync.php` copies your Tradedoubler data into a MySQL database, per site (source):

| Table | Source | Contents |
|---|---|---|
| `td_sources` | Publisher API | your sites |
| `td_programs` | Publisher API | programs per site (statuses from `program_status_ids`) |
| `td_statistics` | Publisher API | daily clicks/leads/sales/commission per site × program |
| `td_transactions` | Publisher API | **conversions**: every sale and lead, with status, commission, EPI and products |
| `td_payments`, `td_snapshots` | Publisher API | payments; daily snapshot of account and earnings |
| `td_claims` | Claims API | claims (with `source_id` = affiliateSiteId) |
| `td_vouchers` | Vouchers API | vouchers per site token (source 0 = publisher token) |
| `td_product_feeds` | Products API | which feeds each site can access |
| `td_products`, `td_feed_downloads` | Products API | all products, stored **once per feed** (not per site) |
| `td_conversion_subscriptions` | Conversions API | your postback subscriptions |
| `td_sync_state` | – | last run / last error per task |

Each row keeps the full API record as JSON in `data`, with the main fields in their own columns.

**Requirements:** PHP 8.1 or newer with the `curl` and `pdo_mysql` extensions, and MySQL 5.7+ or MariaDB 10.2+.

## Setup on Vimexx (DirectAdmin)

1. **Database**: in DirectAdmin go to *MySQL Management* and create a database and user.
2. **API client**: in Tradedoubler go to <https://publishers.tradedoubler.com/en/uaa/clients> and create a client. Note the Client ID and Secret; the Secret is shown only once.
3. **Legacy tokens**: in Tradedoubler go to *Settings → Tokens* and make sure tokens exist for PRODUCTS, VOUCHERS, CLAIMS and CONVERSIONS. Site tokens give per-site vouchers and products with your tracking links. The script finds these tokens automatically.
4. **Upload** this folder outside `public_html`, e.g. `/home/USERNAME/tradedoubler/`.
5. Copy `config.example.php` to `config.php` and fill it in.
6. **Test** via SSH (if enabled): `php ~/tradedoubler/sync.php --force`. The first run backfills `backfill_days` (365) per site and can take a while because of the limit of 60 requests per minute.
7. **Cron**: in DirectAdmin open *Advanced Features → Cron Jobs* and add an entry that runs every 30 minutes:
   ```
   */30 * * * *   /usr/local/bin/php /home/USERNAME/tradedoubler/sync.php > /dev/null 2>&1
   ```
   If that PHP binary is older than 8.1, use a versioned one, e.g. `/usr/local/php83/bin/php`. Each task has its own interval in `config.php` (`intervals`), so running the cron often is cheap. A lock file prevents overlapping runs.

   If your package has no shell cron, set `web_key` and let the cron fetch `https://yourdomain/…/sync.php?key=YOURKEY`. This option requires the folder to be inside `public_html`; the bundled `.htaccess` protects the config and logs.

## Options

```
php sync.php                       # run the tasks that are due
php sync.php --force               # run everything now
php sync.php --only=transactions,claims
php sync.php --only=statistics --backfill-days=730
```

Logs go to `logs/sync.log`. The exit code is 1 when a task fails, and the error is stored in `td_sync_state.last_error`.

## Notes

- **Conversions**: the Conversions API only manages postback subscriptions and cannot be used to fetch conversion data. Conversion data therefore comes from Publisher API transactions (`td_transactions`). Each run reloads the last `lookback_days` (45) by transaction date and by last modification, so status changes (pending → approved/denied) are picked up.
- **Products**: each feed is downloaded **once**, using the token of one site that has access to it, then read line by line so memory use stays low. Tradedoubler allows such a file to be downloaded only 3 times per 24 hours. The script therefore skips feeds whose `lastModifiedTime` has not changed. When a site hits the limit, the next site's token is tried. Products that disappear from a feed are removed, and so are the products of feeds that become inactive or that no site can access any more.
- **Limiting feeds**: set `feed_ids` in `config.php`, e.g. `'feed_ids' => [25304, 259663]`, to sync only those feeds. Products of other feeds are then removed from the database. To look up feed IDs, check `td_product_feeds` from a run made without the limit, which lists every feed with its name.
- **Product links per site**: the stored `productUrl` tracks for the site recorded in `td_feed_downloads.source_id`, e.g. `https://pdt.tradedoubler.com/click?a(1234567)p(123456)…`. For another site, replace the number in `a(…)` with that site's ID. Check `td_product_feeds` to confirm the site has access to the feed:
  ```sql
  SELECT p.*, REPLACE(JSON_UNQUOTE(JSON_EXTRACT(p.data, '$.offers[0].productUrl')),
                      CONCAT('a(', d.source_id, ')'), CONCAT('a(', 7654321, ')')) AS url_for_site
  FROM td_products p
  JOIN td_feed_downloads d ON d.feed_id = p.feed_id
  JOIN td_product_feeds  f ON f.feed_id = p.feed_id AND f.source_id = 7654321;
  ```
- **Site tokens** are matched to sites by name. If a token can't be matched, the log tells you; add it under `legacy_tokens` in `config.php`.

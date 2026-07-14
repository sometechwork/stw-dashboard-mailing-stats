# STW Dashboard Stats Gateway

WordPress plugin exposing MailPoet Premium, rasa.io v1, and Advanced Ads Tracking stats to the publisher analytics dashboard.

## Endpoint

`GET /wp-json/stw-dashboard/v1/stats`

`GET /wp-json/stw-dashboard/v1/mailing/stats`

`GET /wp-json/stw-dashboard/v1/ads/summary`

`GET /wp-json/stw-dashboard/v1/ads/timeseries`

`GET /wp-json/stw-dashboard/v1/ads/top`

`GET /wp-json/stw-dashboard/v1/ads/table`

Required header:

`Authorization: Bearer <wordpress-dashboard-api-key>`

Query parameters:

- `startDate=YYYY-MM-DD`
- `endDate=YYYY-MM-DD`
- `blogId=<multisite-blog-id>`
- `pageSize=<1-100>`

## Settings

After activation, open **Settings > Dashboard Stats Gateway** inside each multisite blog. Settings are stored per blog. If **STW MailPoet Rasa Sync** is already configured on a blog, this gateway reuses its existing `mailpoet_rasa_settings` values for rasa username, password, and API key.

- vegconomist.com
- vegconomist.de
- cultivated-x.com

Configure:

- Dashboard API key for this blog, copied to that site's dashboard env variable
- Optional rasa.io user ID/email override
- Optional rasa.io password override
- Optional rasa.io API key override
- Optional rasa API base URL, defaulting to `https://api.rasa.io/v1`

The settings page shows status cards for the dashboard token and each rasa credential. Existing credentials from constants, this gateway, or **STW MailPoet Rasa Sync** are reflected there; password and API key values are never printed back to the page.

For production, credentials may also be supplied through constants. These are the same rasa constants used by **STW MailPoet Rasa Sync**, so existing constants can be reused:

```php
define( 'STW_DASHBOARD_API_KEY', 'replace-with-dashboard-token' );
define( 'STW_RASA_USERNAME', 'user@example.com' );
define( 'STW_RASA_PASSWORD', 'secret' );
define( 'STW_RASA_API_KEY', 'rasa-api-key' );
```

Per-blog constants override the shared constants:

```php
define( 'STW_DASHBOARD_API_KEY_2', 'vegconomist-de-dashboard-token' );
define( 'STW_RASA_USERNAME_2', 'de-user@example.com' );
define( 'STW_RASA_PASSWORD_2', 'secret' );
define( 'STW_RASA_API_KEY_2', 'de-rasa-api-key' );
```

rasa credential resolution order:

1. Per-blog constants, for example `STW_RASA_USERNAME_2`
2. Shared constants, for example `STW_RASA_USERNAME`
3. Gateway settings saved in **Settings > Dashboard Stats Gateway**
4. Existing **STW MailPoet Rasa Sync** option `mailpoet_rasa_settings`

## Data Sources

MailPoet:

- Public MailPoet PHP API for subscriber and list counts.
- MailPoet stats tables for sent campaigns, opens, machine opens, clicks, unsubscribes, and bounces.

rasa.io:

- v1 `/tokens` for session token creation.
- v1 `/persons` for audience counts.
- v1 `/analytics/activities` for delivered/open/click/bounce/unsubscribe totals.

The plugin returns aggregated data only. It does not expose email addresses, recipient IDs, subscriber tokens, IP addresses, or recipient-level actions.

Advanced Ads:

- Reads the current blog's `advads_impressions` and `advads_clicks` tracking tables.
- Joins tracked rows to Advanced Ads posts.
- Returns ad ID, name, status, impressions, clicks, CTR, and last updated time.

The dashboard sends `blogId`; this plugin switches to that blog before reading MailPoet, rasa settings, or Advanced Ads data.

## Dashboard Connection Steps

1. Activate this plugin network-wide or activate it on each of the three blogs.
2. In each blog admin, open **Settings > Dashboard Stats Gateway**, use **Generate token**, copy the token, and save changes.
3. Add each blog's copied token to the matching dashboard environment variable:

   ```env
   WORDPRESS_DASHBOARD_API_KEY_VEGCONOMIST_COM=token-from-vegconomist-com
   WORDPRESS_DASHBOARD_API_KEY_VEGCONOMIST_DE=token-from-vegconomist-de
   WORDPRESS_DASHBOARD_API_KEY_CULTIVATED_X=token-from-cultivated-x
   ```

   You can also generate a token in the terminal if needed:

   ```bash
   openssl rand -base64 48
   ```

4. If using constants instead of settings, set per-blog constants in `wp-config.php`, for example `STW_DASHBOARD_API_KEY_3`, `STW_DASHBOARD_API_KEY_2`, and `STW_DASHBOARD_API_KEY_13`.
5. In each blog admin, confirm **STW MailPoet Rasa Sync** already has the rasa username, password, and API key for that site. Add values in **Settings > Dashboard Stats Gateway** only when you need to override them.
6. Confirm these dashboard environment values point at the multisite domains:

   ```env
   WORDPRESS_BASE_URL_VEGCONOMIST_COM=https://vegconomist.com
   WORDPRESS_BASE_URL_VEGCONOMIST_DE=https://vegconomist.de
   WORDPRESS_BASE_URL_CULTIVATED_X=https://cultivated-x.com
   ```

7. Leave `MAILING_STATS_API_BASE_URL_*` and `ADVANCED_ADS_API_BASE_URL_*` blank unless the gateway route is hosted somewhere other than `/wp-json/stw-dashboard/v1`.
8. Test with:

   ```bash
   curl -H "Authorization: Bearer $WORDPRESS_DASHBOARD_API_KEY_VEGCONOMIST_COM" \
     "https://vegconomist.com/wp-json/stw-dashboard/v1/stats?blogId=3&startDate=2026-06-01&endDate=2026-06-30"
   ```

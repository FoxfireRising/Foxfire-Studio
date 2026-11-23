# Security

## Supported versions

Only the latest release gets security fixes, so please update when a new version comes out.

## Reporting a problem

Please report security problems privately through GitHub's **private vulnerability reporting** for this repository (the **Security** tab, then **Report a vulnerability**). Do not open a public issue.

Include the plugin version, your WordPress and PHP versions, what you did, and what happened. A short proof of concept helps. You will get a reply as soon as a maintainer can look at it, and a fix will be released before details are made public.

## How the plugin protects a site

- **The teleprompter is private.** It opens only for logged-in users who can edit posts, lists and loads only posts that person may edit, and asks WordPress and common caching plugins not to cache it.
- **Every change needs permission and a security token.** The settings screen and every download button need administrator rights (`manage_options`). The Reading audio box needs the right to edit that post. Each checks a WordPress nonce. The REST routes for reader stats need a logged-in user who can edit posts, and the right to edit the specific post; WordPress's REST nonce protects them from cross-site requests.
- **Uploads are checked.** "Use this recording" needs the right to upload files and to edit the post. Only an MP3 (checked by its first bytes) and a WebVTT captions file (checked by its header) are accepted, and both go through WordPress's own media handling.
- **Database queries are prepared.** Every query that includes outside input uses `$wpdb->prepare()` or WordPress's insert and update helpers.
- **Everything printed is escaped,** so an unusual title, file name, referrer or setting cannot inject code. Settings are cleaned when saved (colours, numbers, field names and the font link are all checked).
- **The listening player never reveals a hidden post's reading.** Drafts, private posts and password-protected posts stay hidden from anyone who cannot read them.
- **Outside files are pinned.** Every self-hosted Whisper file, the MP3 encoder and the noise model must match a known SHA-256 fingerprint before they are saved. The MP3 encoder and the noise model are also checked in the browser every time they load, from the site or from jsDelivr, and are never run if they do not match.
- **Self-hosted fonts are checked.** The font link must point at Google Fonts, font files are accepted only from Google's font host and only as woff2, and the stylesheet saved on the site is rebuilt from a short list of safe properties. The download cannot be pointed at other servers.
- **Listener analytics accepts only what it expects.** Its route is the plugin's only public REST route (the feeds, member feeds and download addresses check every request themselves). It takes small batches (24 KB at most) for public posts that have a reading, keeps only known event types and numbers in range, limits events per batch and per page view, drops crawlers and impossible timing, and always answers 204 so it reveals nothing. It stores no IP address.
- **Visitors cannot choose their own address.** The address used for the listener id, the country and the rate limits is the connection's own (`REMOTE_ADDR`). Forwarding headers such as `X-Forwarded-For` and `CF-Connecting-IP`, and country headers such as `CF-IPCountry`, are ignored unless the request arrives from a proxy the site owner has listed as trusted (see [Trusted proxies](#trusted-proxies)). Addresses are validated and normalised before use.
- **New page views are rate limited three ways:** 60 an hour per listener id, 300 an hour per address however the browser name changes, and 5,000 an hour for the whole site. The last two are counters in transients that expire within the hour; the per-address one is keyed by a salted hash, never the address.
- **CSV downloads are safe to open in a spreadsheet.** The dashboard's downloads (administrators only, each behind a nonce) prefix any cell that a spreadsheet app could run as a formula, since campaign tags and referrers come from visitors' links.
- **A reading's file is checked before it is sent or copied** (1.25.0). The download address, storage offload, collections and sponsor messages only use a listening file (by its name and first bytes) inside uploads, never one in the plugin's private folders or in any folder whose `.htaccess` turns visitors away, and a file kept for members only for its own post. The plugin's own fields (the reading, its captions, Read by) are set in the Reading audio box and are kept out of WordPress's Custom Fields panel.
- **Private folders.** Readings for members, readings kept aside, takes sent from a phone, shared takes and audiobook copies live in folders in uploads with an `.htaccess` that denies every request; they are served only through routes that check who is asking. Readings for members are kept in a folder with a random name of their own. **Check private folders** (Podcast feed) asks for a test file in each folder from your browser, as a visitor would, and says if any answered.
- **Sponsor messages are the site owner's** (1.25.0). A message is spliced into every reading, so only users with `manage_options` (filter `rrt_spot_capability`) can make, record or publish one.
- **No telemetry, no remote code.** The plugin never contacts its authors. It does not download or run PHP from anywhere.

## Known limits

- **When Whisper is not self-hosted,** transformers.js and the model load in the reader's browser from jsDelivr and Hugging Face at pinned versions. The browser cannot check a fingerprint on that kind of load. Self-hosting (Settings, Foxfire Studio, Download Whisper to this site) is the safer choice.
- **The analytics route can be written to by anyone.** That is how anonymous analytics works, and the route is built to keep only plausible data. Someone with many addresses can still add rows up to the site-wide limit (5,000 page views an hour by default). They cannot read data, change settings or reach anything else, and raw events are trimmed after 30 days. Before 0.10.1, a forged `CF-Connecting-IP` header was enough to get past the per-listener limit; that is fixed.
- **A misconfigured trusted proxy list is trusted.** If you list a range that also contains ordinary visitors (for example a whole hosting provider), those visitors can choose the address and country recorded for their own visits. List only your CDN's or proxy's published ranges.

## Trusted proxies

Most sites need nothing here. If your site sits behind a CDN or reverse proxy (Cloudflare, a load balancer, a caching proxy), every request reaches WordPress from the proxy's address. Without configuration, the plugin then sees all visitors as a few addresses: plays still count, but countries come from the proxy's location, and the per-address limit applies to the proxy as a whole.

To fix that, go to **Settings, Foxfire Studio, Listener analytics, Trusted proxies**:

1. List the proxy's addresses or CIDR ranges, one per line, from your provider's published list (Cloudflare publishes theirs at cloudflare.com/ips). IPv4 and IPv6 are both accepted; anything that is not a valid address or range is dropped when you save.
2. Choose the header the proxy uses to pass on the visitor's address: `X-Forwarded-For` (the default), `CF-Connecting-IP`, `X-Real-IP` or `True-Client-IP`.
3. Save, then check **Your visit right now** further down the page. It says whether your own visit came through a trusted proxy and which country it was placed in.

How the address is chosen:

- The request did not come from a trusted proxy: `REMOTE_ADDR`, and every forwarding and country header is ignored.
- It came from a trusted proxy, with `X-Forwarded-For`: the list is read from the right, skipping trusted proxies, and the first address that is not one of them is the visitor. Anything further left was written by the visitor and is ignored. A malformed entry stops the walk and the proxy's own address is used.
- It came from a trusted proxy, with a single-address header: that address, if valid; otherwise the proxy's own.
- Country headers (`CF-IPCountry`, `X-Country-Code`, `X-Geo-Country`) are used only on requests from a trusted proxy. A country set by the web server itself (`GEOIP_COUNTRY_CODE`) is always used. Otherwise the site's own country database answers.

Developers can add proxies in code with the `rrt_trusted_proxies` filter (an array of addresses or ranges), and change the hourly limits with `rrt_listen_address_hourly_limit` and `rrt_listen_hourly_session_limit` (0 turns a limit off).

## For site owners

- Keep WordPress, your theme and your plugins up to date.
- Give the editor role only to people you trust with your posts: the teleprompter shows them every post they may edit, including drafts.
- **On nginx,** `.htaccess` files are ignored. Add the lines shown under Check private folders (Foxfire Studio, Podcast feed) to your site's nginx setup, then press the button again.
- If your site is behind a CDN or proxy, set up [Trusted proxies](#trusted-proxies). If it is not, leave that setting empty.

# Setup

Installing, updating and self-hosting. Server addresses, usernames and passwords never go in this repository; where a command needs them, it uses placeholders like `USER@HOST` and `PORT`.

---

## Installing the plugin

1. Get the zip: download it from the latest [release](https://github.com/FoxfireRising/Foxfire-Studio/releases), or zip the `plugin/foxfire-studio` folder yourself (the zip must contain the folder itself, with the files inside it).
2. In WordPress: **Plugins**, then **Add New Plugin**, then **Upload Plugin**. Choose the zip, **Install Now**, **Activate**.
3. **Set up** (1.29.0): the Plugins screen says "Foxfire Studio is ready." Press **Set up** for a few short steps: the show's name, the look, which posts can have readings and where the player goes, privacy and the studio's files on your site, and a one-minute practice reading with a microphone check. Each step is saved with **Next**, can be skipped, and shows what the site uses now. **Not now** hides the notice; **Set up** stays under Foxfire Studio until it is finished, and **Run setup again** is at the top of **Settings**, then **Foxfire Studio** afterwards. Everything else is in Settings.
4. Open it from **Tools**, then **Teleprompter**, or hover over a post in the Posts list and click **Teleprompter**. The address is `yoursite.com/?teleprompter=1`.

### Updating
Upload the new zip the same way and choose **Replace current with uploaded**. Settings are kept in the database, so an update never overwrites them.

### Customising
Everything is in **Settings**, then **Foxfire Studio**. Start from a preset, then change any wording, colour or font. Developers can adjust the final settings in code with the `rrt_config` filter.

### Coming from Reading Room Teleprompter
The plugin moved to a new folder in 0.11.1, so WordPress installs Foxfire Studio beside the old copy. Upload the new zip and choose **Activate Plugin**: Foxfire Studio switches the old copy off and takes over its settings, readings, stats and self-hosted files. Then delete Reading Room Teleprompter from the Plugins screen. While Foxfire Studio is active, deleting the old copy keeps all of your data.

### Coming from the 0.0.4 plugin
The MAKE IT YOURS block is no longer read. Before updating, note any wording, colours or fonts you changed there, then enter them in the settings screen. Each reader's own slider settings carry over.

---

## The listening player

1. Give a post its reading: on the post's edit screen, in the **Reading audio** box, choose the MP3 from the media library. For read along, also choose the captions file (the **Captions (.vtt)** button in the review studio makes it).
2. If your readings are already in another field, enter that field's name under **Settings, then Foxfire Studio, Listening player**, and every post with a reading gets the player without touching the box.
3. **Show the player to** starts as *Only logged-in editors, as a preview*. Check a few posts while logged in, then switch it to *Everyone*.
4. **Where:** *At the top of the post* works with any theme; a post that places the player itself (the block or the shortcode) shows it there instead. To put it somewhere specific everywhere, choose *Only where I put it* and add the **Reading player** block (in the block editor, or in the Site Editor's Single Posts template on a block theme), the `[foxfire_player]` shortcode (the older `[reading_room_player]` still works), or this to a theme template:

```php
<?php if ( function_exists( 'rrt_player' ) ) { echo rrt_player(); } ?>
```

To replace a theme's own player and keep it as a fallback, wrap the theme's player in a check like this, so posts go back to the old player by themselves if the plugin is ever switched off:

```php
<?php if ( function_exists( 'rrt_player' ) ) : echo rrt_player(); elseif ( $has_reading ) : ?>
```

To undo the swap, change it back to the theme's original check and set **Where** to *At the top of the post* (or switch the player off).

**Caching:** the player is the same for every visitor, so cached pages are fine. Clear the cache after switching it on for everyone.

---

## Self-hosting Whisper

By default the Whisper model loads from Hugging Face and its code from jsDelivr. To serve both from your own site:

**From WP-admin (plugin, easiest):** **Settings**, then **Foxfire Studio**, then **Download Whisper to this site**. It fetches 12 files (about 116 MB) into `wp-content/uploads/rrt-whisper/`, one at a time, showing each file's progress. Keep the page open until it finishes. If a file fails, click **Try again**; files already there are simply refreshed.

**The larger model (0.16.0):** under *The larger model* on the same screen, **Download the larger model to this site** fetches 7 more files (about 250 MB, two of them 92 MB and 157 MB) into the same folder. Download the standard set first, since the larger model uses its code. Readers then choose *Larger model* beside Whisper review in the teleprompter. If a large file times out on a slow host, click **Try again**.

**Voice following (1.14.0):** under *Voice following on this site*, **Download voice following to this site** fetches its speech model, 4 files (about 45 MB), into `wp-content/uploads/rrt-whisper/csukuangfj/`. Until then *Follow my voice* loads the model from Hugging Face, usually only once per browser. Its engine already comes with the plugin.

**From the command line:** for servers where PHP cannot download large files, or if you prefer SSH.

1. Open `tools/whisper-setup.sh` and set `G` at the top to your site's `wp-content/uploads/rrt-whisper` folder.
2. Copy it to the server and run it:

```bash
scp -P PORT whisper-setup.sh USER@HOST:~/whisper-setup.sh
ssh -p PORT USER@HOST bash whisper-setup.sh
```

3. It downloads about 116 MB and ends with **All done** or a list of anything missing. Safe to run again.

The teleprompter notices the files automatically. While Whisper works, the status line says where it was served from. Any file that is missing or fails to load falls back to the public source.

Running a script file, instead of a long one-line SSH command, avoids shell quoting problems.

---

## Fonts, MP3 encoder and noise model on your own site

**Settings**, then **Foxfire Studio**, then **Download to this site** under *Fonts, MP3 encoder and noise model on this site*. It saves the MP3 encoder, the noise model (110 KB) and every font your font link uses into `wp-content/uploads/rrt-assets/`, usually a few hundred KB to a few MB. After that the teleprompter page loads them from your site.

If you change the font link, save, then click **Download again**. Until you do, the new fonts load from Google Fonts, so the page always shows the fonts you chose.

If a download fails, nothing changes: the page keeps using what it used before. Click **Try again** later.

---

## Broadcast sound

**Settings**, then **Foxfire Studio**, then **Broadcast sound**:

- **Finish for:** *Podcasts and the web* (the default), *Audiobooks* (Audible's ACX rules), or *Neither* (the simple leveling from before 0.5.0).
- **Podcast loudness:** -19 LUFS suits a mono reading and plays like -16 on phones and headphones. Choose -16 if your readings sound quieter than other shows.
- **Steps:** each part of the chain can be switched off if it ever does more harm than good on your voice.

The read-out after each take shows what each step did and whether the finished MP3 meets the rules. For audiobooks, leave a second or two of silence before you start reading and after you finish, so there is room tone to keep.

---

## Caching

The teleprompter tells WordPress, LiteSpeed and most caching plugins not to cache it. If an old copy still appears after an update, exclude the page (or for the plugin, the address containing `?teleprompter=1`) from caching.

Listener analytics (0.10.0) works with page caching, because the page is the same for every visitor and the events go to a REST route that caches never store. Empty the cache after switching analytics on or off, so cached posts pick up the change.

---

## Listener analytics

Off on new installs from 0.10.1. Switch it on under Settings, Foxfire Studio, Listener analytics, after adding the privacy paragraph at the bottom of that page to your privacy policy. Sites that already used 0.10.0 keep it on.

1. **Download the country database:** Settings, Foxfire Studio, Listener countries, **Download to this site**. It takes up to a minute and refreshes itself each month. Until then, countries show as unknown unless your host or CDN sends them.
2. **Add the privacy paragraph** from Settings (or Settings, Privacy, Policy Guide) to your privacy policy page.
3. **Tag the links you share,** such as `https://example.com/my-post/?src=instagram`, so each one shows up as its own source.
4. **Check that listens arrive:** open a post with a reading in a browser where you are not logged in (a phone works well), play part of it, and close it. Within a minute it appears under **Recent listens** in Settings.

WordPress runs the hourly summaries when the site gets visits. On a quiet site, a real cron job calling `wp-cron.php` keeps them on time (see below).

---

## The report email

1. Open **Foxfire Studio, Reports**.
2. Tick **Send the report by email on a schedule**, choose how often and the time of day, and add the addresses it should go to (or leave them empty for the site's admin email). Save.
3. Look at the **Preview**, then press **Send this report now** to check it arrives. Look in the spam folder the first time.

The first scheduled report covers the first full period after switching on. Nothing older is sent.

**Mail:** the report uses WordPress's own mail. Many hosts deliver that poorly, so an SMTP plugin (connected to a real mailbox) is the reliable way to make it arrive.

**On time, every time:** WordPress runs scheduled jobs only when someone visits the site, so on a quiet day a report can be hours late. The Reports screen warns when that happens. The fix, in this order:

1. In your hosting control panel, add a cron job that runs every 15 minutes:
   ```
   curl -s https://example.com/wp-cron.php?doing_wp_cron > /dev/null 2>&1
   ```
   (with your own address; `wget -q -O - ...` works too where curl is missing).
2. Once it runs, add this line to `wp-config.php`, above the "That's all, stop editing" line:
   ```php
   define( 'DISABLE_WP_CRON', true );
   ```

Doing step 2 first would stop scheduled jobs altogether.

---

## Sharing a take for feedback

1. Add each person who will give feedback under **Users, Add New**, with the role **Subscriber**. Subscribers can sign in and open takes shared with them, and nothing else in Foxfire Studio.
2. After a take, open its review in the teleprompter and press **Share for feedback**. Tick the people, leave **Email them the link** ticked (or copy the link from the dialog), and press **Share**.
3. Their comments arrive by email, one email per visit. All of them are on the share's page; **Foxfire Studio, Shared takes** lists every share, with **End now**.

Shared audio is kept in `wp-content/uploads/rrt-shares/`, which refuses direct requests through its `.htaccess` file. On nginx, which ignores `.htaccess`, add `location ^~ /wp-content/uploads/rrt-shares/ { deny all; }` to the site's server block.

Share pages and their audio are never cached. If a caching plugin other than LiteSpeed Cache caches pages for signed-in users, exclude the query string `rrt_share`.

## Sending a take to your computer

Nothing to set up. After a take on a phone, the review offers **Send to my computer**; the next time the same account opens the teleprompter on a computer, it offers the take. Sent takes are kept in `wp-content/uploads/rrt-sent/`, which refuses direct requests like `rrt-shares/` (on nginx, add the same `deny all` rule for `/wp-content/uploads/rrt-sent/`). Waiting takes are listed under **Foxfire Studio, Recordings** and are deleted after 14 days at most. Your host's upload limit must allow the take's WAV (about 5 MB a minute).

## The podcast feed

1. **Foxfire Studio, Podcast feed:** add cover art (a square JPEG or PNG, 1400 to 3000 pixels, under 512 KB is safest), a description, an owner email (it is public in the feed, so use an address meant to be seen), a category and the rating.
2. Fix anything the checks on that screen list, then tick **Publish the podcast feed** and save. Until then the feed's address answers 404.
3. Empty the page cache, open the feed's address while logged out, and check it in a feed validator.
4. Submit the address to Apple Podcasts Connect and Spotify for Creators yourself. Every published post with a reading becomes an episode, newest first.

### Storage offload (optional, 1.23.0)

For when podcast apps' downloads are too much for your host. Foxfire Studio, Podcast feed, **Storage offload**:

1. **Bunny** (simplest with a CDN): in bunny.net make a storage zone, then **Connect Pull Zone** on it. Copy the zone's name and its password (FTP & API Access), and the pull zone's address (`https://NAME.b-cdn.net`). In the pull zone's **Headers** settings, add `mp3`, `vtt` and `json` to the CORS extensions. Optional: the account's API key, so a reading that goes to members is cleared from the CDN at once.
2. **S3-compatible** (Cloudflare R2, Backblaze B2, Wasabi, Amazon S3): make a bucket that anyone may read at a public address (R2 needs a custom domain), and a key that may only read and write that bucket. Pick the service in **Fill in for**, then the bucket, the key and the public address.
3. **A CDN in front of your site:** make a pull zone or distribution with your site as its origin, let it pass on query strings and turn on CORS for `mp3`, `vtt` and `json`. Nothing is uploaded.
4. **Save**, then **Check this setup**. Fix what it lists (for S3 without CORS, **Set the CORS rules for me**). Once it passes, tick **Serve readings from the storage below**, save, and press **Copy everything now**.
5. Add the line Foxfire Studio, Settings shows to your privacy policy.

Keys are safer in `wp-config.php` (the section shows the lines). Your site keeps every file: switching off brings every address back at once, and the plugin does the same by itself if the storage stops answering. Deleting the plugin never touches the storage; press **Delete the outside copies** first if you want it emptied.

## The music bed

Add tracks under **Settings, Music bed**: audio files from the Media Library that you made, own or have a licence to use, each with an optional credit (it goes into the MP3 and the episode notes). Choose the show's default track, how far under the voice it sits (Standard 20 dB, Softer 24 dB, Fuller 16 dB) and whether it rises a little in long pauses. Each post can choose its own track, or none, in its **Reading audio** box, and each take has a **Music bed** switch. Listen on a phone and in a podcast app after the first reading with music goes up; if the voice is not clearly on top, try Softer.

## The intro video

Choose a short video from the Media Library under **Settings, Teleprompter look, Intro video**. It plays once in each new tab when the studio opens; **Skip it from now on**, or **Play the intro** in the studio's Controls panel, switches it off and on in that browser. It never plays when the browser asks for less motion.

## Deleting the plugin

Deactivating changes nothing. Deleting from the Plugins screen always removes the settings, the scheduled jobs, the short-lived counters, `wp-content/uploads/rrt-assets/` (fonts, encoder, noise model, country database) and the feed's address. Everything else stays for a reinstall unless the box under **Settings, Reader stats** ("When the plugin is deleted, also delete everything else it keeps") is ticked first: then the stats and analytics tables, every reader's and post's data, shared and sent takes, and `wp-content/uploads/rrt-whisper/` go too. Files in the Media Library are never deleted. Updating with **Replace current with uploaded** never runs any of this. The full list is in [PRIVACY.md](https://github.com/FoxfireRising/Foxfire-Studio/blob/main/PRIVACY.md#retention-and-deletion).

## Audio field on posts

The **Reading audio** box on each post is the simplest place for a reading. If your theme or another plugin already stores readings in a custom field, enter that field's name under **Settings, Foxfire Studio, Listening player**. The field can hold an attachment ID, a URL, or an array with a `url` or `ID` key (the format Advanced Custom Fields and similar plugins use).

Allow `mp3` (and `m4a`, if you upload other audio) in your media settings. MP3 is what the teleprompter produces.

---

## Troubleshooting

The common problems are also in [the guide](guide/trouble.md) and on the plugin's Help screen (Foxfire Studio, Help, When something goes wrong). This table has the rarer ones.

| Symptom | Likely cause | Fix |
|---|---|---|
| Page shows the old version | Page cache | Empty the page cache; check the uploaded file size |
| Follow my voice turns itself off | The model could not load, or the device fell behind (the line under the script says which) | Download voice following to this site in Settings; close other tabs on a busy computer, or use timed pace |
| Whisper review stuck on loading | First load (about 80 MB) or a blocked download | Wait; check the status line for the source; self-host Whisper from Settings |
| "Whisper did not hear enough" | Recording too short or very quiet | Record a longer section; check the mic level |
| MP3 could not be made | Unusual browser or recording format | Download the original; the review still works with **Open a recording** |
| Fan hum in recordings | Built-in mic near the laptop fan | Timed pace while recording, let the laptop cool, set it on something soft to damp vibration, or use a USB mic |
| No listens under Recent listens | Analytics off, player still an editors-only preview, your own visits excluded, or Do Not Track on | Check the settings, then test from a logged-out browser without Do Not Track |
| Listens show the wrong country | The visitor's mobile carrier or privacy relay routes through another country | Expected: the country is where the visitor's internet address is registered |
| Every listen shows the same country, or *Your visit right now* mentions a forwarded address | The site is behind a CDN or proxy that is not listed as trusted | Add the proxy under Settings, Foxfire Studio, Listener analytics, Trusted proxies (see [SECURITY.md](https://github.com/FoxfireRising/Foxfire-Studio/blob/main/SECURITY.md#trusted-proxies)) |
| Listen times look an hour or more off | WordPress time zone | Set Settings, General, Timezone to your city |

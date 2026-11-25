# Privacy

What Foxfire Studio collects, stores, sends and shows, based on the code in this repository (version 1.2.0; checked line by line against the code in 0.27.0, and each version's changes since). It is written for site owners deciding whether to use the plugin and what to put in their privacy policy.

The plugin has no telemetry. It never reports anything to its authors, and it contains no advertising, tracking pixels or third-party analytics.

---

## Contents

- [At a glance](#at-a-glance)
- [Visitors to your site](#visitors-to-your-site)
- [People who use the teleprompter](#people-who-use-the-teleprompter)
- [The Foxfire Studio dashboard](#the-foxfire-studio-dashboard)
- [The report email](#the-report-email)
- [Clips for social media](#clips-for-social-media)
- [Sharing a take for feedback](#sharing-a-take-for-feedback)
- [Sending a take to your computer](#sending-a-take-to-your-computer)
- [Readings kept aside](#readings-kept-aside)
- [The podcast feed](#the-podcast-feed)
- [The music bed](#the-music-bed)
- [Requests to other sites](#requests-to-other-sites)
- [Follow my voice](#follow-my-voice)
- [What published MP3 files reveal](#what-published-mp3-files-reveal)
- [Retention and deletion](#retention-and-deletion)
- [Your privacy policy](#your-privacy-policy)

---

## At a glance

| Who | What | Where it goes | Can it be switched off? |
|---|---|---|---|
| Visitors who play a reading | How they listen (plays, parts heard, speed, skips, how the visit ended), referring site, campaign tags, device class, country | Your own database only | Yes, and off until switched on: Settings, Foxfire Studio, Listener analytics |
| Visitors who play a reading | Chosen speed and resume position | Their own browser only | Resume can be switched off in Settings, Listening player |
| Visitors who listen to a reading (1.2.0) | The month of their last real listen, and a yes or no sent to your site on later visits | The month in their own browser; the yes or no in your own database | Yes, and off until switched on: Settings, Foxfire Studio, Returning listeners |
| Visitors who play a reading (1.2.0) | Start, halfway and end events with the reading's title | The Google Analytics your site already loads | Yes, and off until switched on: Settings, Foxfire Studio, Google Analytics |
| Podcast apps that download an episode (1.2.0; IAB guidelines v2.3 since 1.24.0), members' feeds included | A count per episode, app, kind of device, feed and day; podcast consumers a day; sponsor messages delivered | Your own database only | Yes, and off until switched on: Foxfire Studio, Podcast feed, Count downloads |
| Podcast apps, while a measurement prefix is set (1.24.0) | Their IP address and app, as any web server sees them | The prefix service the owner chose (such as OP3) | Yes, and off until one is added: Foxfire Studio, Podcast feed, Download counting |
| Visitors and podcast apps that play or download a reading, while storage offload is on (1.23.0) | Their IP address and app or browser, as any web server sees them | The storage or CDN company the owner chose, which delivers the file | Yes, and off until switched on: Foxfire Studio, Podcast feed, Storage offload |
| Your site, when a new episode is in the feed (1.10.0) | The podcast feed's address only, nothing about visitors | Podcast Index and a WebSub hub (Google's by default) | Yes, and off until switched on: Foxfire Studio, Podcast feed, Pings |
| Editors who record | Settings, unfinished takes, a copy of their take history, the take shelf for Combine takes (1.3.0) | Their own browser only | No (needed for the tool to work) |
| Visitors who press play on a YouTube or SoundCloud panel, while Embedded players is on (1.35.0) | Their IP address and browser, from the moment they press play, as the service's own player always sees them | YouTube (from `youtube-nocookie.com`) or SoundCloud | Yes, and off until switched on: Settings, Foxfire Studio, Embedded players. Before play, nothing is sent to either service |
| Editors who record, while the take shelf on the site is on (1.34.0) | Each reviewed take's recording (lossless FLAC), the words Whisper heard in it and its flags, for 30 days at most, the newest six a post; each post's Combine takes choices | A folder visitors cannot open, listed in the editor's own user data, served only to them | Yes, and off until switched on: Settings, Foxfire Studio, Take shelf |
| Editors who record | Cue marks on a post's script (pauses, breaths, emphasis, fades meant), and its kind of reading | Your own database, with the post; no user is named | Not set unless they add them |
| Editors who record | Each take's numbers, words taught to Whisper, a record of each published reading | Your own database | Yes: Settings, Foxfire Studio, Reader stats |
| Readers using the dashboard | Goals and practice log notes | Your own database, in their user data | Not set unless they add them |
| Administrators who set up reports | Recipients' email addresses and the archive of sent reports | Your own database; the email goes through your site's mail setup | Yes: Foxfire Studio, Reports (off until switched on) |
| Editors who record | The recording itself | Your media library, only when they press **Use this recording** or upload it themselves | Not uploaded unless they choose to |
| Editors who share a take, and the people they share it with | The take's audio and script (for 14 days at most), who it was shared with, and the comments left on it with the name each person gave | Your own database and a folder visitors cannot open; emails go through your site's mail setup | Nothing is shared unless an editor presses **Share for feedback** |
| Editors who record on a phone | The take's original recording (for 14 days at most), with its post and how far the reading got | A folder visitors cannot open, listed in the editor's own user data | In phone take mode (1.12.0, on for phones) each take is sent as it is recorded; switched off, nothing is sent unless they press **Send to my computer** |
| Editors who record (1.13.0) | Each recording setup's name (the device and its microphone, as the browser names it) and six numbers per take for its EQ, five takes a setup | Your own database, in their user data | Kept only for takes reviewed with broadcast sound; the EQ itself is off until switched on in Settings |
| Editors who take a reading off its post | The reading's file and captions, until put back or deleted, with who took it off and when | A folder visitors cannot open, listed with the post | Nothing moves unless they press **Take off the post** |

---

## Visitors to your site

### The listening player

The player shows on posts that have a reading. It loads its files from your site only, unless storage offload is on (below).

It keeps two things in the visitor's browser (`localStorage`):

- `rrtSpeed`: the playback speed they chose
- `rrtResume_<post id>`: where they stopped in that reading and when, so the player can offer to resume

Neither is sent anywhere. The visitor can clear them with their browser's site data controls.

### YouTube and SoundCloud players that load on play

Off until an administrator switches it on, for each service, under Settings, Foxfire Studio, **Embedded players** (1.35.0). While it is on, every YouTube or SoundCloud player in posts, pages and block widgets is shown as a panel: a picture of the video or track, a title and a play button. The picture comes from your site, so a visitor's browser contacts neither service when the page opens or as they scroll. Only when the visitor presses play does their browser load the service's own player (YouTube's from its privacy-enhanced address, `youtube-nocookie.com`), and from then on that service's own privacy policy applies, as it always does for an ordinary player.

To make the panels, **your server** asks the service once for each video's or track's title and picture (its oEmbed address, as WordPress itself does for embed code; nothing about visitors is sent) and keeps the picture in `uploads/rrt-embeds/`, which is public on purpose, with the list of saved pictures in the option `rrt_embeds_list` (each one's title, file name and when it was fetched and last shown). A picture not shown for 90 days is removed by the hourly job, and Clear saved pictures removes them all. Nothing is kept about visitors. With the setting off, players are shown exactly as WordPress shows them. Uninstalling always removes the two switches (`rrt_embeds`); with the delete box ticked it also removes the folder and the list.

### Storage offload

**Off until a site owner switches it on** (1.23.0, **Foxfire Studio, Podcast feed, Storage offload**). While it is on, the readings' MP3s and captions for podcast apps, and (unless the owner chose *Podcast apps only*) the MP3 in the post's player, are fetched from the storage or CDN the owner chose (a bunny.net pull zone, an S3-compatible bucket's own domain, or another CDN in front of the site). That company's servers see each listener's IP address and app or browser, the same as this site's server would, and may keep them in their logs under their own policy; bunny.net blurs IP addresses in its logs by default and keeps its logs for 3 days. The plugin sends the storage nothing about listeners: only the readings' files, uploaded with the owner's own key. Files for members, readings kept aside, audiobook copies, sent and shared takes and the studio's files never leave the site. The captions the player reads along with, and its Download button, always come from the site. The privacy paragraph in Settings gains a line naming where the readings come from while this is on.

**Kept on the site:** the settings in `rrt_offload` (the storage's keys, unless they are set in `wp-config.php`, are never shown again once saved) and a list of the copies in `rrt_offload_files` (each file's path, its key in the storage, a short fingerprint of its content and when it was checked). Uninstalling always removes both; it never deletes anything in the outside storage.

Only while **Returning listeners** is switched on (1.2.0) does listener analytics keep a third: `rrt_listened_month`, the month of the visitor's last real listen; see [Returning listeners](#returning-listeners). With the switch off, the player removes it.

### Listener analytics

**Off until a site owner switches it on**, from 0.10.1 (**Settings, Foxfire Studio, Listener analytics**). Sites that already ran 0.10.0, where it was on by default, keep it on when they update; a choice already saved is never changed. Even when it is on, visitors are measured only once the player is shown to everyone (it starts as an editors-only preview). Add the plugin's privacy paragraph to your privacy policy before switching it on.

When it is on, the player sends small batches of events to your own site's REST route (`/wp-json/rrt/v1/events`) while a reading plays. Nothing goes to any other site.

**Not measured at all:**

- visitors whose browser sends Do Not Track or Global Privacy Control (when *Privacy signals* is ticked, the default)
- logged-in editors and administrators (the default; can be changed to all logged-in users, or nobody)
- browsers that identify as crawlers or bots
- password-protected, private and draft posts

**Stored, per page view with a reading** (tables `rrt_sessions`, `rrt_buckets`, `rrt_events`):

- a listener id: a keyed hash of the IP address, the browser's user agent and the site's host name, using a random salt that is replaced every day. The same browser gets a different id each day, and old ids cannot be recreated once the salt is replaced.
- the post and reading, the day and time
- the referring site's domain (never the full address), and campaign tags on the link (`?src=`, `utm_source`, `utm_medium`, `utm_campaign`, `utm_content`)
- device class (phone, tablet or desktop), worked out from the user agent and screen size; the user agent itself is not stored
- country, two letters
- what happened: whether it played, which five-second stretches were heard and how often, speed and volume changes, seeks, scroll depth, time on the page, whether read along or download was used, playback errors, and how the visit ended

**Also stored:** daily totals per reading and per source (`rrt_daily`, `rrt_daily_sources`), with no listener ids.

**Not stored:** IP addresses, user agents, full referrer addresses, cookies, names, email addresses or any account details. The IP address is held only while the request is handled, to make the listener id, look up the country and apply the rate limits, and is then discarded.

**Rate limit counters:** to stop one source flooding the analytics tables, the plugin counts new page views per hour, per address and for the whole site, in WordPress transients that expire within the hour. The per-address counter's name is a keyed hash of the address and the hour, using the same daily salt as the listener id; the address itself is never written. Nothing else is kept from these counters.

**How the country is found:** from a country set by your web server, or a country header from a proxy you have listed as trusted (such as Cloudflare's `CF-IPCountry`; see [SECURITY.md](SECURITY.md#trusted-proxies)), otherwise from a copy of DB-IP's free IP to Country Lite database kept on your site in `wp-content/uploads/rrt-assets/country.bin`. The lookup happens on your server; the visitor's address is never sent to DB-IP.

**Read or listen and time to press play (1.2.0)** are worked out from what is listed above (scroll depth, time on the page, the parts heard and the time of the first play) and kept only as counts in the daily totals: four counts of visits (listened only, read and listened, read only, neither) and a histogram of seconds before the first play for phones and tablets and for computers. Nothing new is collected for them.

### Returning listeners

**Off until a site owner switches it on** (1.2.0, **Settings, Foxfire Studio, Returning listeners**), and only while listener analytics is on. Add the line the privacy paragraph gains to your privacy policy before switching it on.

- **In the visitor's browser:** after a real listen (30 seconds heard, or the whole of a shorter reading), the player keeps the month in `localStorage` as `rrt_listened_month`. The visitor can clear it with their browser's site data controls.
- **Sent to your site:** on a later visit, one yes or no with the visit's first batch of events: whether that month is within the window chosen in Settings (1, 3, 6 or 12 months). The month itself is never sent, and nothing links one day's listener id to another's.
- **Stored:** the yes or no in the visit's summary (`came_back` in `rrt_sessions`), and counts of new and returning listeners in the daily totals.
- **Not measured** for the same visitors listener analytics leaves out. In the EU and UK, browser storage is treated much like a cookie; check whether your visitors there need to be asked first.

### The Google Analytics link

**Off until a site owner switches it on** (1.2.0, **Settings, Foxfire Studio, Google Analytics**). It works whether or not listener analytics is on.

- When it is on and the page already has a Google Analytics tag (`gtag`), the player sends three events to it: `audio_start` at the first play, `audio_progress` with `audio_percent` 50 at halfway, and `audio_complete` at the end, each with the reading's title (`audio_title`). The plugin adds no Google Analytics tag of its own; without one, nothing is sent.
- The same visitors are left out as for listener analytics: those whose browser sends Do Not Track or Global Privacy Control (when *Privacy signals* is ticked) and the logged-in people chosen under *Leave out*.
- What Google Analytics does with the events, and the cookies it sets, follow your Google Analytics setup and Google's own terms. Say in your privacy policy that listening is reported to Google Analytics.

**A note on "anonymous":** within one day, the listener id lets the plugin tell that several page views came from the same browser. That is what makes plays count once per listener per day. It cannot be linked to a person or to other days without the IP address, which is not kept. Some privacy laws still treat a daily pseudonymous id as personal data, so mention listener analytics in your privacy policy (see [below](#your-privacy-policy)).

---

## People who use the teleprompter

The teleprompter only opens for logged-in users who can edit posts.

**In the reader's browser:**

- display settings (pace, text size, width, reading line, font, checkboxes), in `localStorage` (`rrtTeleprompter`)
- unfinished takes, in IndexedDB (`rrt-takes`), until their MP3 or WAV is downloaded (at most three)
- the take shelf (1.3.0), in IndexedDB (`rrt-shelf`): each reviewed take of a post, its recording and the words Whisper heard in it, for Combine takes, with each post's choices. At most the newest six takes a post, each kept 30 days, or until removed or the shelf is cleared in Combine takes. Nothing on the shelf is sent to the site unless the take shelf on the site is switched on (1.34.0, below). Since 1.34.0 each take there also notes whether it is on the site, and a take read on another computer is listed with its words until its recording is first needed here.
- a copy of the take history (`rrtTakes_` and the post's ID: up to 50 takes per post with server storage on, 12 with it off) and taught words (`rrtLearned`; since 1.33.0 `rrtLearned_` and a language code for a reading in another language, and `rrtLangPace_` and the code: the paces of the last ten reviews in that language, for judging the pace), in `localStorage`, and a mark that this browser's history from before 0.9.0 has been sent to the site (`rrtImported_` and the user's ID)
- the last microphone check (`rrtMicCheck`): when it ran and the levels it measured (since 1.9.0 also the voice's loudness and the lift a take would need), in `localStorage`. The check itself records nothing and sends nothing.
- the chosen Whisper model (standard or larger), with the display settings; since 1.30.0 also a mark that the larger model ran slower than a take on this browser's graphics chip, so Automatic uses the standard one (cleared by choosing Automatic again)
- words taught for one post that could not reach the site yet (`rrtLearnedPostPending_` and the post's ID), in `localStorage`, until the next successful save
- whether the Controls panel is open or folded away (`rrtPanel`), in `localStorage`
- that this browser has been offered the practice reading (1.29.0, `rrtWelcomed`: when, in `localStorage`), so New here is offered once
- whether the intro video is switched off in this browser (`rrtIntro`), in `localStorage`, and that it has played in this tab (`rrtIntroSeen`), in `sessionStorage`, which the browser clears when the tab closes
- while a Whisper review is running, when it started (`rrtWhisperRunning`), in `localStorage`. It is removed when the review ends; if the page was closed under it, the next visit removes it and says so.
- cue marks that could not reach the site yet (`rrtCuesPending_` and the post's ID), and pronunciation notes that could not (`rrtNotesPending_` and the post's ID, and `rrtNotesMinePending`), in `localStorage`, until the next successful save. The warm-up opens the microphone only to show a live level bar while its panel is open; it records nothing, sends nothing and saves nothing.

**On the site** (when *Reader stats, server storage* is on, the default):

- each take's numbers and flagged moments, linked to the reader's user account and the post (`rrt_takes`), including whether it was an opening drill and its practice focus. Each reader sees only their own takes. A take of the built-in practice reading (1.29.0) is kept the same way, with no post (post 0).
- a record of each post's reading: file, length, bitrate, tempo, when it went live, and which take it came from (`rrt_recordings`)
- the words each reader has taught Whisper, in their user meta (`rrt_learned`); since 1.15.0 with when each was taught and forgotten, so a forgotten word stays forgotten in every browser; since 1.33.0 words taught in another language are kept apart in `rrt_learned_` and its code (`rrt_learned_fr`), included in the personal data export and erase
- a post's reading language (1.33.0), in the post's meta (`_rrt_lang`), only when it differs from the site's; it is a language code and nothing else

**On the site, with the post** (whatever the Reader stats setting):

- words taught to Whisper for this post only (0.16.0), in the post's meta (`_rrt_learned`): the script word, what Whisper heard instead, and the line the review shows. They hold no user ID and no audio. Anyone who can edit the post reviews it with them and can forget them. Forgetting the last one removes the meta.
- pronunciation notes (1.1.0) for every use of a word in one post, in the post's meta (`_rrt_notes`): the word and the note. They hold no user ID. Anyone who can edit the post can see and change them. Removing every note on a post removes the meta. Each reader's own notes for every post are kept the same way in their user meta (`rrt_notes`), and are included in WordPress's personal data export and erase.
- the Pronunciation screen (1.15.0) shows and changes the notes and taught words above and adds nothing about visitors. It keeps the words a reader marked Not needed in its suggestions, in their user meta (`rrt_pron_skip`, included in the personal data export and erase), and a word index of the published posts' own text in a transient (`rrt_pron_index`), made again when a post changes. **Export backup** downloads the reader's own notes and taught words and each editable post's, with post ids, slugs and titles, to the reader's computer; nothing is sent anywhere else.
- cue marks set in the teleprompter, in the post's meta (`_rrt_cues`): for each marked word, its place in the script, the word itself and the mark (pause, breath or emphasis). They hold no user ID and no audio, and the post's text is not changed. Anyone who can edit the post can see and change them. Removing every mark on a post removes the meta. Since 1.11.0 a mark can also be a fade meant.
- the post's kind of reading (1.11.0), in the post's meta (`_rrt_kind`): one word (prose, story, verse or ritual), set in the teleprompter or the Reading audio box, with no user ID. Choosing Not chosen removes it. The pace a reader sets on a post with a kind is kept in that browser only (`rrtPace_` and the post's ID, in `localStorage`), as is the review's Show pauses switch (`rrtShowPauses`).

**Recordings** are processed entirely in the reader's browser: recording, cleanup, noise reduction (with either engine; the stronger cleanup's model, 1.16.0, ships with the plugin), MP3 encoding and the Whisper review (on the reader's graphics chip where the browser can, since 1.17.0). The audio reaches the site only when the reader presses **Use this recording** (which adds the MP3, or the WAV when the Broadcast finishing preset is chosen (1.6.0), and its captions if any, to the media library and attaches them to the post) or uploads a file themselves.

**The take shelf on the site** (1.34.0), off until an administrator switches it on under Settings, Foxfire Studio, Take shelf. While it is on, each take a reader reviews (on a post, never the practice reading) is also sent to the site after its review: the recording as Whisper heard it, before cleanup, as lossless FLAC made in the reader's browser, with a check of its samples, the words and times Whisper heard, the review's flags, the take's length, a fingerprint of its levels and when it was recorded; and each post's Combine takes choices. They are kept in `uploads/rrt-shelf/` under a random folder name (kept in the option `rrt_shelf_key`), which refuses visitors, and listed in the reader's own user meta (`_rrt_shelf`). Only the reader can list, fetch or delete them; nobody else sees another person's shelf, administrators included, though an administrator sees the total space used and can delete every shelf at once. A take leaves the site 30 days after it was recorded, when a newer take pushes it out of the newest six for its post, when the reader removes it or clears the shelf (in Combine takes or under Foxfire Studio, Recordings), when their account is deleted, or with Delete every shelf on the site. Storage offload never copies them. Switching the setting off stops sending and fetching; what is on the site then leaves within 30 days. They are included in WordPress's **Export Personal Data** (a line for each take; the recording itself is not part of the export) and **Erase Personal Data**. With the delete box ticked, uninstalling removes the folder, the lists and the folder's name; the setting itself always goes.

**Member feeds** (1.21.0), off until switched on under Podcast feed, Member feeds. Each member's feed address is made of a random code kept in their user meta (`_rrt_mfeed`, with when it was made in `_rrt_mfeed_at`), and an administrator's choice for a person (Always or Never) in `_rrt_member`. The feed holds the show's readings and nothing about the member: no name, email or account. Podcast apps that read feeds on their own servers (Overcast and Pocket Casts do) see the address and what the feed holds. A post's choice of who can hear it is kept with the post (`_rrt_access`). While a reading is for members, its files are kept in `uploads/rrt-members/`, a folder its `.htaccess` refuses to visitors, and each is marked with its post (`_rrt_member_of`); they are served only to a signed-in person who may hear the reading, or through a member's own feed address. **Email them their feed address** sends one email to that member through the site's mail, only when an administrator clicks it. **Feed activity**, off by default, keeps for each member the time of the last fetch, the app's name worked out from its user agent and a count of the networks that fetched the feed in the last 7 days, as short hashes made with the daily code download counting uses, so no IP address is stored (`_rrt_mfeed_seen`); it is deleted after 30 days, and all of it when the switch is turned off. The privacy paragraph in Settings includes lines for these while they are on. Nothing about visitors is involved. With the delete box ticked, uninstalling puts every reading for members back at its public place first, then removes the folder and these records; unticked, they stay.

**Sponsor messages** (1.22.0), off until switched on under Podcast feed, Sponsor messages. Each message's choices (who it is for, its link, dates and readings) are kept with it (`_rrt_spot`), with a log of when it ran on each reading (`_rrt_spot_log`); its copies in each reading's sound are attachments of it (`_rrt_spot_copy`). A reading's choices and the pauses found in it are kept with the post (`_rrt_spots`, `_rrt_pauses`), and its sponsored copy in `uploads/rrt-sponsored/`, a public folder the podcast feed points into, with a record of it (`_rrt_spot_built`). Every listener gets the same file: nothing is chosen per listener, nothing about listeners is used or kept, and nothing is sent to an advertising company. A message's link goes where the owner points it. With the delete box ticked, uninstalling removes the folder, the messages, their copies and these records; the messages' own recordings stay in the Media Library.

**Audiobook export** (1.20.0): an audiobook copy of a reading (its take finished for audiobooks) and a collection's recorded credits are kept on the site in `uploads/rrt-audiobook/`, a folder its `.htaccess` refuses to visitors, and served only to people who can edit the post; each post keeps a record of its copy (`_rrt_book`: the file name, length, size and the check's levels). The credits' scripts are posts of the private type `rrt_credit`, and a collection's sample choice is kept with it (`_rrt_book_col`). The audiobook itself is made in the editor's browser from these copies and the readings already on the site, downloaded as a ZIP and sent nowhere. Nothing about visitors is involved. With the delete box ticked, uninstalling removes the copies, their folder, these records and the credits.

**Collections** (1.19.0) are joined on the site from readings already published on it. The joined MP3, its captions and any reading matched for a collection are kept in the Media Library as the collection's own attachments, and each reading's measured loudness is kept with its post (`_rrt_level`: a number and which file it belongs to). Matching decodes and encodes one reading in the editor's browser and sends the copy only to the site. Nothing about visitors is involved; a collection's listening numbers are counted like any reading's.

**Full-length video** (1.18.0) is made in the browser of the person who presses Make the video, from the reading already on the post. It is kept in that browser's own private storage until it downloads, and removed from there when the page is opened again or a new video is started. Nothing is sent to the site or anywhere else, and nothing about visitors is involved.

Reader stats are covered by WordPress's own **Export Personal Data** and **Erase Personal Data** tools (Tools menu). Deleting a user also deletes their takes.

---

## The Foxfire Studio dashboard

The dashboard (from 0.11.0) adds a **Foxfire Studio** menu in WP-admin. It reads the listener analytics and reader stats described above; it collects nothing new from visitors and loads nothing from other sites (its charts are drawn on the page).

**Who sees what:**

- **Listener screens** (Overview, Readings, Readings to make, Sources, Recordings) and their downloads: administrators only (`manage_options`; the `rrt_dashboard_cap` filter can change this). Anyone else who opens the Overview sees Your progress instead.
- **Reports, Settings and Podcast feed:** administrators only (`manage_options`).
- **Shared takes:** every user who can edit posts sees the shares they made; administrators see every share.
- **Your progress:** every user who can edit posts sees their own takes, goals and practice log. Administrators can also choose another reader and see theirs.
- **Insights** (from 0.19.0): every user who can edit posts sees observations about their own takes; listener observations are for administrators only, like the listener screens.

**Stored by the dashboard:**

- **Goals** (one to three targets per reader) and **practice log notes** (one short note per day), in each reader's user data (`rrt_goals`, `rrt_practice_notes`). Both are included in WordPress's **Export Personal Data** and removed by **Erase Personal Data** and when the user is deleted.
- **Change markers:** a date, an optional post, a short label written by an administrator, and the ID of the user who added it (table `rrt_markers`). They describe the site, not visitors.
- **Readings to make** (1.4.0): the IDs of posts an administrator took off the list, in the option `rrt_backlog_hidden`. The list itself is worked out on the fly from the posts, the reader's own pace and their pronunciation notes and taught words; nothing about visitors is involved. The option goes with the plugin's settings when the plugin is deleted.

**Insights** (from 0.19.0, Foxfire Studio, Insights, and two of them on the Overview): sentences worked out on the fly from the same analytics and reader stats. Nothing new is stored or collected. Listener observations are shown only to those who may see the listener screens; each reader sees observations about their own takes. In the teleprompter, people who may see the listener screens get a one-line suggestion drawn from the same observations (for example how long readings were when listeners stayed); no visitor is named in any of it.

**CSV downloads:** administrators can download the visit records and raw events behind every number, and any table on screen. These contain what the analytics tables hold (see [Listener analytics](#listener-analytics)): no IP addresses, user agents, cookies or account details. Campaign tags and referrers in them come from visitors' links, so any cell a spreadsheet could run as a formula is written as plain text. Treat downloaded files like the rest of your site's data: store them privately and delete them when you no longer need them.

---

## The report email

From 0.12.0, an administrator can have a report emailed on a schedule (**Foxfire Studio, Reports**). It is off until switched on.

- **What it holds:** totals for the period from the listener analytics and reader stats described above: plays, estimated listeners, listening time, which readings were played and how far, sources, speed mix, new readings, and each reader's takes, averages and goals. From 0.19.0 it also holds up to two observations from the insights described under the dashboard. Nothing in it identifies a visitor. Reader progress names each reader by their WordPress display name, so it goes only to the addresses an administrator sets.
- **Where it goes:** to the addresses set on the Reports screen (up to 10), or the site's admin email. It is sent with WordPress's `wp_mail`, so it travels through whatever mail setup the site uses (an SMTP plugin, or the host's mail). The plugin sends it nowhere else.
- **Attachments** (off by default): CSV files of the period's readings and sources, with the same totals.
- **Kept on your site:** the settings and recipients (option `rrt_report`), and each sent email with its date, recipients, status and headline numbers (table `rrt_reports`), so past reports can be viewed on the Reports screen. The archive follows the same rule as the stats tables when the plugin is deleted.

---

## Clips for social media

From 0.13.0, editors can make short videos of a reading (**Make a clip** on a reading's page in Foxfire Studio, and in the review studio).

- **Made in the editor's browser.** The reading, the post's image and text are read from your own site; drawing, captions and video encoding happen on the editor's computer. When the clip has no review to take word timings from, Whisper listens to the chosen stretch on the editor's computer, loading its files as described under [Requests to other sites](#requests-to-other-sites).
- **The MP4 writer and its AAC encoder** (1.30.0, Mediabunny and FFmpeg's AAC encoder as WebAssembly) ship with the plugin and load from your own site; nothing goes to their makers.
- **Nothing is uploaded** unless the editor presses **Save to Media Library**, which adds the MP4 to your media library (attached to the post) like any other upload. Otherwise the clip exists only in the editor's browser until downloaded.
- **Tagged links:** each clip's link adds the channel and the clip's name as campaign tags (`src`, `utm_campaign`). Visits through it are measured like any tagged link, as described under [Listener analytics](#listener-analytics). The tags name the clip, not the visitor.
- **What a clip shows:** the post's title and featured image, the show name, the words spoken in that stretch, and the site's address. Check a clip before posting it, as with any public post.
- **Suggested clips** (1.7.0) are worked out on your site from the reading's own captions and, once it has 10 plays, the listening curve the dashboard already shows. Nothing new is collected from visitors. **Use for the feed** keeps the chosen stretch (its start, length and words) with the post, for the podcast feed's soundbite; a new reading on the post clears it, and uninstalling always removes it.

---

## Sharing a take for feedback

From 0.17.0, an editor can share one take with people who already have an account on your site (**Share for feedback** in the review studio). A friend is usually added under Users as a Subscriber.

- **What is uploaded:** the take's audio (the file playing in the review), the post's text split into words with the times Whisper heard them, the post's title, and the user IDs it was shared with. The audio is saved in `uploads/rrt-shares/` under a random name. That folder refuses direct requests (an `.htaccess` file, which Apache and LiteSpeed obey; on nginx, add a rule that denies the folder). The audio reaches a browser only through a REST route that checks the signed-in user is the editor, an administrator, or someone it was shared with, and it is sent with no-store headers.
- **Who can open it:** signed-in users it was shared with, until the share ends; the editor who shared it, and administrators, at any time. Anyone else sees "Not available". The page is never cached and asks search engines not to index it.
- **What a friend gives:** a name (their display name unless they change it) and comments, each with the moment and word it is pinned to. Friends see only their own comments; the editor sees everyone's.
- **Emails:** if the editor ticks "Email them the link", each person chosen gets one email with the link. When a friend presses Done, or an hour after their last comment if they never do, the editor gets one email listing that friend's comments. Nothing else is sent.
- **How long:** a share lasts 14 days, or until the editor presses End now (Foxfire Studio, Shared takes, or on the share's page). Then the audio file is deleted within the hour (at once for End now), the friends lose access, and the comments, script and title stay for the editor.
- **Limits:** 200 comments per person per take, 2,000 characters each, and no more than 20 a minute.
- **Personal data:** comments are included in WordPress's **Export Personal Data** and removed by **Erase Personal Data** (Tools menu), by the commenter's email. Deleting a user removes their comments, and the shares they made with their audio.

---

## Sending a take to your computer

From 0.21.0, an editor who records on a phone can press **Send to my computer**, so the take waits on the site and opens for the full Whisper review in the teleprompter on a computer.

- **What is uploaded:** the take's original recording (a WAV, or what the phone's recorder made when it could not record losslessly), the post it was read from, how many words the reading reached, its length, and, for an opening drill, the drill and its length and focus. The file is saved in `uploads/rrt-sent/` under a random name. That folder refuses direct requests (an `.htaccess` file, which Apache and LiteSpeed obey; on nginx, add a rule that denies the folder). The list of waiting takes is kept in the editor's own user data (`_rrt_sent`).
- **Who can open it:** only the signed-in account that sent it, through a REST route that checks who is asking, sent with no-store headers. Other editors and administrators are not offered it and cannot fetch it.
- **How long:** until the editor opens it on a computer and downloads it, presses Use this recording, or deletes it (in the teleprompter or under Foxfire Studio, Recordings); and 14 days at most, after which the hourly job deletes it. At most five takes wait at once.
- **Nothing about visitors** is involved. With phone take mode off, the phone sends nothing unless the button is pressed.

### Phone take mode

From 1.12.0, on a phone, the teleprompter sends each take to the site while it is recorded, so a closed page loses nothing.

- **What is uploaded:** the same as above, in pieces of about ten seconds as the take goes, each with how far the reading has reached. The site joins them into one WAV in `uploads/rrt-sent/`, with the same random name, refused folder, list in the editor's user data, 14 days, five waiting takes and single account as a sent take. A take begun when five are already waiting stays on the phone only, and the phone says so.
- **On the phone:** the take is still kept in the browser as before until the site has all of it, then forgotten. The posts where Carry on has been offered are remembered in that browser (`rrtCarried`), so it is offered once. Since 1.12.1 the browser also remembers the ids of the takes it started on the site (`rrtLiveMine`, at most 20, each forgotten once its take is finished), so a take cut off on that phone is finished as cut off as soon as the post is opened again. Nothing about visitors is involved.
- **The switch:** on for phones and tablets; off in the teleprompter's Controls, Recording, Phone take mode, remembered in that browser. Computers never use it. Opening drills stay on the phone.
- **Mobile data:** a minute of a phone's 24-bit audio is about 8.6 MB, and the teleprompter shows how much it has sent while recording.
- **Personal data:** deleting a user deletes their waiting takes. When the review of a sent take is saved to the reader's stats, it is a take like any other (marked as from the phone).

### Voice polish

From 1.13.0, the teleprompter learns a gentle EQ for each recording setup.

- **What is kept:** the setup's name (the kind of device, such as iPhone or Computer, and the microphone's name as the browser gives it) and, for each of its first five takes of 30 seconds or more, the voice's level in six frequency bands. No audio, no words and nothing about visitors. It is kept in the editor's own user data (`_rrt_eq`), at most twelve setups, and sent from the editor's browser to your site only. A take sent from a phone carries its setup's name with it.
- **Forgetting:** Forget this setup in the review studio's Hear the polish clears one setup. The personal data tools export and erase it, deleting the user deletes it, and uninstall removes it with the delete box ticked.
- **While recording,** the energy note listens to the level in the browser only; nothing is kept.

---

## Readings kept aside

From 1.8.0, **Take off the post** on a reading's page takes a published reading off its post without deleting it.

- **What moves:** the reading's audio file and its captions file leave the Media Library for `uploads/rrt-kept/`, each under a random name. That folder refuses direct requests (an `.htaccess` file, which Apache and LiteSpeed obey; on nginx, add a rule that denies the folder). The file's old public address stops answering. The post's list of readings kept aside (`_rrt_kept`, post meta) records the file's old place in the Media Library, its title and details, the field it came from, the music bed's credit, the pick for the feed, who took it off and when.
- **Who can open it:** people who can edit the post and upload files, through an admin address that checks who is asking and sends no-store headers.
- **How long:** until **Put back** (the files return to the Media Library) or **Delete for good**, with no time limit. Deleting the post for good deletes its readings kept aside.
- **What stays:** the reading's listening history, which was never about the file itself. Podcast apps that already downloaded the episode may keep their own copy.
- **A file outside the Media Library** (the extra field from Settings holding an address elsewhere), or one that is also another post's reading, is never moved; only the post's field is cleared and remembered.
- **Nothing about visitors** is collected.

---

## The podcast feed

From 0.22.0, an administrator can publish a podcast feed (Foxfire Studio, Podcast feed). It is off until switched on.

- **What the feed shows:** what is already public on the site (each published reading, its post's title, excerpt and address, its captions file and its "read by" credit), plus the show's details from the settings. The **owner email** is in the feed for anyone to read, because Apple and Spotify ask for one; use an address meant to be public.
- **Nothing is collected** unless Count downloads is on: the feed records nothing about who reads it, and podcast apps download the MP3 files straight from the site; like every request to your site, those downloads appear in your host's own access logs.
- **Count downloads (1.2.0, rebuilt in 1.24.0 to follow the IAB Tech Lab Podcast Technical Measurement Guidelines v2.3)**, off until switched on: each episode in the feed points to a short address on your site (`/podcast-file/<post id>/<file name>`), which counts the request and then has the web server send the file (or redirects the app to it). A download counts once per podcast consumer, episode file and day, once the app has asked for the file's tags and a minute of audio. To tell consumers apart for that day, the plugin keeps a keyed hash of the IP address (an IPv6 address cut to its first 64 bits), the app's user agent and the file, with a random salt that is replaced every day (`rrt_dl_salt`), together with the byte ranges that hash asked for and which sponsor messages it reached; the hashes are kept in `rrt_dl_seen` for two days, then deleted. A second hash per consumer (without the file) counts podcast consumers a day. The counts are kept in `rrt_dl_counts` (day, episode, the app's name, the kind of device, public or members' feed, the number), `rrt_dl_days` (podcast consumers a day) and `rrt_dl_ads` (sponsor messages delivered a day); 1.2.0's counts stay in `rrt_downloads`. For a member's private feed (1.21.0), the member's user id takes the place of the address and user agent inside the hash; only totals are kept, never which member downloaded what. IP addresses and user agents are never stored. Each address is checked against a list of data centre and VPN networks kept on the site (see below), and rate-limit counters per address, hour and day, in transients whose names are keyed hashes, expire within the day. Nothing about listeners is sent elsewhere unless the owner adds a **measurement prefix** (1.24.0, Download counting): OP3 (`op3.dev`) or another service then sits in front of every episode address in the public feed, and sees each app's IP address and user agent as any web server does. Where the web server keeps the Range header from PHP (LiteSpeed does), Check counting adds marked lines to the site's `.htaccess` that hand that header on for the short addresses only; they carry nothing about listeners anywhere else. The settings are in `rrt_dl`; **Make the method page** adds a draft page that describes all this from the settings, and nothing else.
- **Password-protected and unpublished posts** are never in the feed.
- **Podcasting 2.0 (1.10.0):** the feed also gives each episode its chapters (read from the MP3's own chapter tags and served at `/podcast-chapters/<post id>.json` while the feed is published), a transcript language and a soundbite (the pick from Use for the feed, else the top suggested clip, both worked out from the reading's captions). For the show it gives the host's name, link and picture, a support link, the medium, an optional place and optional text lines, all from the Podcast feed settings and all public in the feed. What the site works out about each reading (its chapters and top suggestion) is kept with the post (`_rrt_feed`) until the reading, its captions or the post's text change, and is always removed on uninstall. Nothing is collected from visitors.
- **Pings (1.10.0)**, off until switched on, and only allowed while the feed is published: when a published post gets a reading, the site sends the feed's address, and nothing else, to Podcast Index (`api.podcastindex.org`) and a WebSub hub (by default Google's `pubsubhubbub.appspot.com`; another can be set), at most once every 10 minutes. The time of the last pings and the two services' answers are kept in `rrt_podcast_ping`, and a ping waiting for its 10 minutes is a scheduled job; both are always removed on uninstall. While pings are on, the privacy paragraph gains a line saying so.

---

## The music bed

From 0.24.0, an administrator can add music tracks to Settings, Music bed, and each post can choose one (or none) in its Reading audio box.

- **Nothing is collected:** the music bed records nothing about visitors or readers. The music is mixed in the reader's own browser; the track is fetched from your own media library.
- **What is stored:** the list of tracks with their credits (in the plugin's settings), each post's choice (`_rrt_music`), and, when **Use this recording** puts a reading with music on a post, which track is under it (`_rrt_music_used`), so the podcast feed can credit it. Deleting the plugin with "When the plugin is deleted, also delete everything else it keeps" ticked removes the two post fields. The tracks themselves are Media Library files and are never deleted.
- **What the MP3 reveals:** a track's credit, when one is written, goes into the MP3's comment tag and the podcast episode notes as "Music: ...".

---

## Requests to other sites

**From visitors' browsers:** none. The player and analytics load only from your site, and the player loads no fonts of its own. The exceptions are the optional [Google Analytics link](#the-google-analytics-link) (1.2.0, off by default), which passes play events to the Google Analytics tag your site already loads, and [storage offload](#storage-offload) (1.23.0, off by default), which has the player fetch the reading's MP3 from the storage or CDN the owner chose. [Embedded players](#youtube-and-soundcloud-players-that-load-on-play) (1.35.0, off by default) sends nothing either: a YouTube or SoundCloud player loads from its service only after the visitor presses play (an ordinary player, with the setting off, loads as soon as it is scrolled near, as WordPress shows it).

**From the share page, in a friend's browser** (0.17.0): the studio's fonts from Google Fonts (`fonts.googleapis.com`, `fonts.gstatic.com`), when they have not been downloaded to your site. Everything else on the page, the take's audio included, comes from your site.

**From the teleprompter, in the reader's browser,** when a file has not been saved to your site yet:

| File | From |
|---|---|
| Fonts | Google Fonts (`fonts.googleapis.com`, `fonts.gstatic.com`) |
| MP3 encoder, noise model | jsDelivr (`cdn.jsdelivr.net`) |
| Whisper code (transformers.js, ONNX Runtime Web) | jsDelivr |
| The stronger cleanup's runtime (1.16.0, the same ONNX Runtime Web files as Whisper's), when the engine is Stronger | jsDelivr |
| Whisper model, and the larger model (0.16.0) when a reader chooses it or (1.17.0) when Automatic runs it on the graphics chip, with its graphics chip files | Hugging Face (`huggingface.co`) |
| The multilingual Whisper models (1.33.0), when a reading in a language other than English is reviewed, pinned to one revision | Hugging Face |
| Voice following's speech model (1.14.0), when *Follow my voice* is on | Hugging Face |

These requests reveal the reader's IP address and browser to those services, as any web request does. They carry no recordings or post text. To avoid them, use **Download Whisper to this site** (which also serves the stronger cleanup's runtime, 1.16.0) (and **Download the larger model to this site**, if readers use it), **Download voice following to this site** and **Download to this site** in Settings, Foxfire Studio. Fonts can also be switched off altogether by clearing the font link, so the studio and share page use fonts already on the reader's computer. The clip maker uses the same files, and only the self-hosted fonts. Every self-hosted file is checked against a known fingerprint before it is saved.

**From your server:**

- while [Embedded players](#youtube-and-soundcloud-players-that-load-on-play) is on (1.35.0): each YouTube video's or SoundCloud track's title and picture, once, from `www.youtube.com/oembed` and `i.ytimg.com`, or `soundcloud.com/oembed` and SoundCloud's picture address
- the downloads above, only when an administrator presses a download button (and Google's font stylesheet, read once when the fonts are downloaded)
- the DB-IP country database from `download.db-ip.com`, when an administrator downloads it and then automatically once a month while listener analytics is on
- X4BNet's list of data centre and VPN networks from `raw.githubusercontent.com`, automatically once a month while Count downloads is on (1.24.0; a copy ships with the plugin for the first month); its date is kept in `rrt_net`
- while [storage offload](#storage-offload) is on (1.23.0): the readings' files uploaded to the storage the owner chose (none through a CDN, which copies them itself), a request back for each to check it answers, an hourly check of one, deletes when a file leaves public use, and, with a bunny.net API key, a request to clear a file from the CDN; none of them carries anything about visitors

---

## Follow my voice

Since 1.14.0, *Follow my voice* runs a small speech model on the reader's own device, in the browser, with the sherpa-onnx engine that comes with the plugin. The microphone's audio goes only to that model, inside the page; nothing it hears is kept, saved or sent anywhere, and the site receives nothing from it. While a take is recorded it hears the recorder's own copy of the microphone, so the recording itself is unchanged.

The model's files (about 45 MB) load from your site when they have been downloaded in Settings, otherwise from Hugging Face, like the Whisper model (see the table above). That request carries no audio.

Until 1.13.0, *Follow my voice* used the browser's built-in speech recognition (the Web Speech API), which in Chrome and Edge sends the audio to the browser maker's speech service. It no longer does.

---

## What published MP3 files reveal

Each MP3 the teleprompter makes carries ID3 tags, which anyone who downloads the file can read (a WAV from the Broadcast preset, since 1.6.0, carries none):

- the post title, the year, the genre "Podcast" and a link to the post
- **the post author's WordPress display name**, as the artist
- the show name (from Settings, or the site title, or the site's address when the site has no title)
- the featured image, as artwork
- chapter titles from the post's headings
- the music bed's credit, as a comment, when the reading has music with a credit (0.24.0)

If you do not want your name in published files, set your WordPress display name (Users, Profile, *Display name publicly as*) to the name you publish under before making a recording.

The WAV download and the captions (.vtt) file carry no tags.

---

## Retention and deletion

| Data | Kept |
|---|---|
| Raw listener events | 30 days (setting) |
| Listener page views | 395 days, about 13 months (setting) |
| Daily totals | Until the plugin's data is deleted |
| Reader takes and recordings records | Until deleted with the reader, by the personal data eraser, or on uninstall |
| Daily salt | Replaced every day; only the current one exists |
| Podcast download hashes (1.2.0) | Two days, with their own daily salt |
| Podcast download counts (1.2.0) | Until the plugin's data is deleted, with the stats tables |
| `rrt_listened_month` in a visitor's browser (1.2.0) | Until the visitor clears it, or the next visit after Returning listeners is switched off |
| Goals and practice notes | Until the reader removes them, the personal data eraser runs, or the user is deleted |
| Change markers | Until an administrator deletes them, or on uninstall with the stats tables |
| Sent reports (archive) | Until the plugin's data is deleted, with the stats tables |
| Cue marks, a post's kind of reading and reading language, pronunciation notes, and words taught for one post | Until removed in the teleprompter, the post is deleted (a reader's own notes: the user is deleted or the personal data eraser runs), or on uninstall with the stats tables |
| A post's reading, captions and Read by links | Until changed in the post's Reading audio box, or on uninstall with the stats tables (the files themselves stay in the Media Library) |
| Reading files and their rows on Recordings (1.36.2) | Until deleted on Recordings (Delete, Clear readings of deleted posts), or the post is deleted for good while Settings, Reader stats, When a post is deleted for good is on (its readings, captions, phone takes and take shelf takes go with it; a file another post uses stays). Moving a post to the Trash deletes nothing |
| Shared take audio | 14 days at most: deleted within the hour once a share ends, or at once with End now |
| Takes sent from a phone | Until used, downloaded or deleted on a computer, 14 days at most, or until the user is deleted |
| Recording setups and their band measurements (1.13.0) | Until forgotten in Hear the polish, the user is deleted, the personal data eraser runs, or on uninstall with the stats tables |
| Saved pictures of YouTube and SoundCloud panels (1.35.0) | 90 days after each was last shown, until Clear saved pictures, or on uninstall with the delete box ticked |
| Readings kept aside (1.8.0) | Until put back or deleted for good, or the post is deleted for good, or on uninstall with the stats tables |
| Shares (title, script, who) and their comments | Until the commenter or the editor is deleted, the personal data eraser runs, or on uninstall with the stats tables |

**On deleting the plugin** (from the Plugins screen, which runs its uninstall routine): settings, the podcast and report settings and schedules, the posts hidden from Readings to make, the Pronunciation screen's word index (1.15.0), each post's pick for the feed (1.7.0), what the feed worked out about each reading and the last pings (1.10.0), the salts, the day's podcast download hashes, every short-lived counter and lock, the country database, the self-hosted fonts, encoder and noise model, and the podcast feed's address in WordPress's saved rewrite rules are always removed. The rest is removed only if the box under Reader stats ("When the plugin is deleted, also delete everything else it keeps") is ticked: the stats and analytics tables (podcast download counts included), each reader's taught words, goals and practice notes, every post's reading, captions and Read by links, cue marks, kind of reading (1.11.0), reading language (1.33.0), pronunciation notes (each post's and each reader's own), taught words and music bed choices, shared takes with their comments and audio, takes waiting to be sent to a computer, readings kept aside (1.8.0, `uploads/rrt-kept/` and each post's list), each reader's recording setups for the EQ (1.13.0), the words each reader marked Not needed on the Pronunciation screen (1.15.0), and the self-hosted Whisper files in `uploads/rrt-whisper/` (since 0.26.0). It is off by default, so a reinstall keeps your history; a small database version marker stays with it. MP3s and captions in the Media Library are never deleted. The automatic checks delete the plugin both ways and confirm this list.

---

## Your privacy policy

When listener analytics, the Google Analytics link, download counting or pings are on, the plugin adds a ready-made paragraph to WordPress's privacy policy guide (**Settings, Privacy, Policy Guide**). The same text is at the bottom of **Settings, Foxfire Studio**. Copy it into your privacy policy and adjust it to your site. It gains a line for each 1.2.0 option that is switched on (returning listeners, Google Analytics, podcast downloads), so check it again after switching one on.

The paragraph covers listener analytics and those options only. If your site also uses the teleprompter's reader stats for several authors, publishes MP3s with authors' names in their tags, shares takes with friends for feedback (their names and comments are stored, they receive an email with the link, and the share page may load Google Fonts), or publishes the podcast feed (its owner email is public), mention those too.

This document describes the plugin's behaviour. It is not legal advice about which laws apply to your site.

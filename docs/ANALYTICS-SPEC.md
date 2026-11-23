# Analytics specification

The blueprint for reader stats, listener stats, the WP-admin dashboard, the monthly email and the settings screen. It covers roadmap versions 0.9.0 to 0.13.0 and 0.19.0 in the [roadmap](../ROADMAP.md) (numbered 0.2.0 to 0.7.0 before November 2026).

Reader stats (0.9.0) and listener analytics (0.10.0) are built. Where the build differs from the plan, the plan has been updated and the difference is marked **Built:**. The rest is still a plan.

---

## Contents

1. [What we want to learn](#what-we-want-to-learn)
2. [What is worth knowing](#what-is-worth-knowing)
3. [Two kinds of stats](#two-kinds-of-stats)
4. [Reader stats](#reader-stats)
5. [Recording stats](#recording-stats)
6. [Listener events](#listener-events)
7. [Listener metrics](#listener-metrics)
8. [Page and source metrics](#page-and-source-metrics)
9. [Data model](#data-model)
10. [How events travel](#how-events-travel)
11. [Privacy](#privacy)
12. [The dashboard](#the-dashboard)
13. [The monthly email](#the-monthly-email)
14. [Settings](#settings)
15. [Performance on shared hosting](#performance-on-shared-hosting)
16. [Testing the numbers](#testing-the-numbers)

---

## What we want to learn

The aim is to see how people behave with the content: whether they engage from beginning to end, when they disengage, when they speed up or slow down the audio, what gets the most clicks from socials and the site, and to use all of it to adjust future readings. On the recording side, the aim is self-improvement: learning what works and what doesn't in the readings themselves.

So every stat below should help answer one of these:

1. **Did they listen?** Of the people who saw the post, how many pressed play?
2. **How far did they get?** Where did they stop, and is there a moment where many people stop?
3. **How did they listen?** Did they speed it up, slow it down, skip ahead, go back?
4. **Where did they come from?** Which links and channels bring people who actually listen?
5. **What do the best readings have in common?** Length, tempo, topic, delivery, timing?
6. **Am I getting better?** Are my takes cleaner, steadier, more alive over time?

---

## What is worth knowing

Collecting everything is easy. Reading it is the hard part. Three rules keep the data useful:

- **Every stat must point at an action.** "Most people sped up to 1.5x during the second paragraph" suggests that paragraph drags. A stat with no possible action belongs in the CSV export and stays off the dashboard.
- **Small numbers get labelled.** Ten listens is an anecdote. Every percentage shows the count behind it, and comparisons are hidden below a minimum (default: 30 plays for one reading, 10 readings for comparisons across readings).
- **Your own plays don't count.** Logged-in editors are excluded by default.

Everything that is collected is available in raw form through CSV export, so nothing is hidden. The dashboard just leads with what matters.

---

## Two kinds of stats

| | Reader stats | Listener stats |
|---|---|---|
| About | The person recording | The people listening |
| Collected in | The teleprompter (private page) | The public post player |
| Identity | The logged-in WordPress user | Anonymous |
| Purpose | Self-improvement | Learning what holds an audience |
| Arrives in | 0.9.0 | 0.10.0 |

The insight work in 0.19.0 joins them: each published recording links back to the take it came from, so delivery measures (pace, slips, pitch movement, faded endings) can be compared with how listeners responded.

---

## Reader stats

Saved for every take, whether or not it becomes the published recording.

| Field | Meaning |
|---|---|
| `post_id` | The post read |
| `user_id` | Who read it |
| `created_at` | When |
| `mode` | Timed pace or voice following |
| `set_wpm` | Pace setting, if timed |
| `duration_s` | Length of the take |
| `words_read` | Words in the read range |
| `wpm` | Actual pace |
| `sentence_wpm_median` | Typical sentence pace |
| `accuracy` | Share of words read as written (with "worth a listen" counted as correct) |
| `skipped`, `misread`, `check`, `added`, `repeated`, `fillers` | Counts |
| `long_pauses`, `hesitations` | Counts |
| `retake_sentences` | Sentences with a real slip |
| `rest_share` | Share of time resting |
| `pause_trend` | Change in pause length, first half to second |
| `ending_drop_db` | Typical phrase-ending fade |
| `pitch_spread` | Typical pitch movement (semitones) |
| `flagged_rushed`, `flagged_slow`, `flagged_flat`, `flagged_faded`, `flagged_unfinished`, `flagged_paraphrased` | Sentence flag counts |
| `room_noise_db` | Background level |
| `clicks_found` | Clicks the cleanup quieted |
| `whistle_share` | Share of S sounds that whistled |
| `cleanup_on`, `whisper_on` | Settings used |
| `whisper_model` | Which model reviewed it |
| `became_recording_id` | Set if this take was published |

The full flag list (each flagged moment with its time and text) is stored as JSON alongside, so a past review can be reopened.

**Shown as:** trend lines per reader over time, a "personal bests" panel, and per-post take history.

---

## Recording stats

Saved for every MP3 that is kept.

| Field | Meaning |
|---|---|
| `post_id`, `post_link` | Where it belongs |
| `attachment_id` | The media library item, if uploaded |
| `file_name` | As saved |
| `duration_s` | Length |
| `size_bytes` | File size |
| `bitrate_kbps` | 128 currently |
| `tempo_wpm` | Average pace of the reading |
| `take_id` | The take it came from |
| `published_at` | When it went live on the post |
| `is_current` | Whether it is the reading on the post now |
| `tags` | Copied from the post, plus any reading-specific tags |

This is the table for planning social posts: what was published, how long it runs, how fast it moves, and (joined with listener stats) how it did.

---

## Listener events

The public player sends small events. Each event carries: `session` (random per page view, see Privacy), `post_id`, `recording_id`, `t` (position in the audio, seconds), `at` (client time), and the fields below.

| Event | Sent when | Extra fields |
|---|---|---|
| `view` | Page with a reading loads | referrer domain, campaign tags, device class, viewport size |
| `ready` | Audio metadata loaded | duration |
| `play` | Playback starts or resumes | `first` (true for the first play this session), seconds since `view` |
| `pause` | Paused by the listener | |
| `progress` | Each 5 second bucket finishes playing | bucket index, playback rate during it |
| `seek` | Listener jumps | from, to, method (scrubber, skip button, chapter) |
| `rate` | Speed changed | from, to |
| `volume` | Volume changed or muted | level, muted |
| `ended` | Reached the end | |
| `hidden` | Tab hidden while playing | |
| `visible` | Tab visible again | |
| `leave` | Page unloading | last position, whether playing |
| `follow` | Follow-along turned on or off | on/off |
| `captions` | Captions turned on or off | on/off |
| `download` | Download clicked | |
| `scroll` | Scroll depth crosses 25/50/75/100% | depth |
| `error` | Playback error | error code |

**Built (0.10.0):** all of the events above. Each also carries `ms` (milliseconds since the page view, from the browser's monotonic clock) and `rate`. The `view` event's details travel once, with the first batch: referring site name, campaign tags, viewport width and touch points; the device class is worked out on the server from those and the browser name. `captions` is not sent, because the player has no separate captions switch (read along is the captions view). `progress` is sent when playback leaves a bucket after at least 2 seconds of it were played (half of a short last bucket), counted from the audio's own position so jumps are ignored. `page` is extra: the visible time on the page, sent when the tab is hidden, because phones often close hidden tabs without any further event. `recording_id` is set by the server from the post's current Recordings row.

**Why 5 second buckets:** fine enough to tie a drop-off to a sentence, coarse enough to keep the data small. A 4 minute reading has 48 buckets.

**Why `progress` events instead of `timeupdate`:** `timeupdate` fires several times a second. Recording which buckets were *actually played* (and at what speed) gives exact retention and replay data with a handful of events.

---

## Listener metrics

Computed from events. Each one has a plain definition so the numbers can be checked.

**Reach**
- **Views:** sessions that loaded the page.
- **Plays:** sessions with at least one `play`, counting the same estimated listener on the same reading only once in 24 hours (the IAB's deduplication window). **Built:** once per listener id per reading per site-local day, since the listener id itself lasts one day. Views are deduplicated the same way; the raw count is kept as `page_loads`.
- **Estimated listeners:** distinct daily listener ids that played (see [Privacy](#privacy)). Always labelled as an estimate: shared and changing IP addresses push it up or down.
- **Play rate:** plays ÷ views. How inviting the player is.
- **Time to first play:** median seconds from view to first play.

**Depth**
- **Listened:** seconds of audio actually heard (sum of played buckets × 5).
- **Average listened:** listened ÷ plays.
- **Completion rate:** plays that reached the last bucket (or `ended`) ÷ plays.
- **Quartiles:** share of plays reaching 25%, 50%, 75%, 100%.
- **Opening hold rate:** share of plays still listening at 60 seconds (or a quarter of the reading, if shorter). The clearest single sign of whether the opening works.
- **Retention curve:** for each bucket, share of plays that heard it. The single most useful chart.
- **Drop-off points:** buckets where the curve falls much more steeply than its neighbours. Each is matched to the script sentence playing at that moment, using the take's word timings.
- **Abandon reason guess:** whether the last event was `pause`, `leave`, `hidden`, or `seek` past the end, to tell "stopped listening" from "closed the tab".

**Behaviour**
- **Speed:** share of listening at each speed; average speed; **speed changes along the timeline** (where listeners tend to speed up or slow down).
- **Replay hot spots:** buckets heard more than once in the same session (from backwards seeks). Often the best or most confusing moments.
- **Skip zones:** stretches jumped over by forward seeks.
- **Background listening:** share of listening time with the tab hidden. High means people listen like a podcast; low means they read along.
- **Follow-along use:** share of plays with follow-along on, and whether those plays complete more often.
- **Return listeners:** sessions that come back to the same post within 30 days (only if the optional return counter is on; see Privacy).

**Per period**
- **Listening hours**, **plays per reading**, **new readings published**, and change against the previous period.

---

## Page and source metrics

- **Source:** referrer domain grouped into channels (Instagram, Facebook, Threads, Bluesky, TikTok, X, Reddit, Google, other search, newsletter, direct, other sites, internal).
- **Campaign tags:** `utm_source`, `utm_medium`, `utm_campaign`, `utm_content`, plus a short `src` alias. The dashboard's link builder makes these.
- **Per source:** views, play rate, completion, average listened. This answers "which socials send people who actually listen", which matters more than raw clicks.
- **Scroll depth** and **time on page** for posts, with and without a play, to see whether audio changes how people read.
- **Device class** (phone, tablet, desktop) and **country**. Country is looked up from the request at the moment it arrives (from a country header if the host or CDN provides one, otherwise a small local lookup database), and only the country code is kept. The IP address itself is never stored.

---

## Data model

Custom tables, created with `dbDelta` on activation, prefixed with the site's table prefix.

**`rrt_takes`** and **`rrt_recordings`**: as in [Reader stats](#reader-stats) and [Recording stats](#recording-stats).

**`rrt_learned`**: `user_id`, `script_key`, `heard_key`, `kind` (pair or ignore), `label`, `created_at`.

**`rrt_sessions`**: one row per listener page view.
`id`, `session_hash`, `listener_day_hash`, `post_id`, `recording_id`, `started_at`, `channel`, `referrer_domain`, `utm_source`, `utm_medium`, `utm_campaign`, `utm_content`, `device`, `country`, `played` (bool), `first_play_after_s`, `listened_s`, `max_position_s`, `completed` (bool), `avg_rate`, `rate_changes`, `seeks_back`, `seeks_forward`, `hidden_s`, `follow_used` (bool), `scroll_max`, `time_on_page_s`, `exit_type`.

**`rrt_buckets`**: one row per session per bucket heard.
`session_id`, `bucket`, `plays` (how many times heard), `rate` (speed when heard).

**`rrt_events`**: raw events, kept only for a short window (default 30 days) for debugging and for recomputing summaries.
`session_id`, `type`, `t`, `at`, `data` (JSON).

**`rrt_daily`**: rollups per day per recording, which the dashboard and email read.
`day`, `recording_id`, `post_id`, `views`, `plays`, `listened_s`, `completions`, `q25`, `q50`, `q75`, `avg_rate`, plus a JSON column of per-bucket counts (heard, replayed, skipped, sped up, slowed down).

**`rrt_daily_sources`**: the same per day per recording per channel.

**Built (0.10.0):** `rrt_sessions` as above, plus `day` (site-local date), `duration_s`, `buckets_heard`, `rate_sum` and `rate_n` (for the average), `hidden_at_ms` (while hidden), `downloaded`, `errors`, `events`, `last_ms`, `last_at`, `junk` (impossible timing, never counted) and `rolled`. The IAB-style counting happens in the rollup, so there is no per-session `counted` flag. `rrt_daily` adds `page_loads`, `held60`, `first_play_s` (median), `hidden_s` and `follow_plays`, and its buckets JSON holds `heard`, `replayed`, `faster` and `slower` arrays. `rrt_daily_sources` is keyed by day, post, recording, channel and campaign, with views, plays, listened_s and completions. `rrt_learned` became user meta in 0.9.0. `rrt_markers` and `rrt_reports` come with 0.11.0 and 0.12.0.

**`rrt_markers`**: change markers. `id`, `day`, `post_id` (optional), `label`, `created_by`. Shown on every chart.

**`rrt_reports`**: one row per email sent: period, recipients, sent_at, a copy of the summary numbers.

**Built (0.12.0):** `rrt_reports` has `period_from`, `period_to`, `kind` (the interval, with `-partial` for a period so far), `trigger_by` (schedule or manual), `sent_at` (UTC), `recipients`, `status`, `error`, `subject`, `summary` (JSON of the headline numbers) and `html` (the email as sent).

Settings live in a single `rrt_settings` option.

---

## How events travel

1. The player script queues events in memory.
2. Every 15 seconds, on pause, and when the page is hidden or closed, the queue is sent with `navigator.sendBeacon` to a REST route, `POST /wp-json/rrt/v1/events`. `sendBeacon` is used because it still delivers when the tab is closing, which is exactly when the most important event (`leave`) happens.
3. The route checks the post and recording exist, the batch is small, and the session has not sent too much (simple rate limit by session and by a short-lived, salted hash of the IP, which is never stored).
4. Known bots (user agent list) and sessions with impossible timing are dropped.
5. Events are written to `rrt_events`, and the session row and bucket rows are updated.
6. Once an hour, WP-Cron (or a real server cron job) rolls finished sessions into `rrt_daily` and `rrt_daily_sources`.
7. Once a day, raw events past the retention window are deleted.

No cookies are set for this by default. The session id lives only in the page's memory.

**Built (0.10.0):** as above, with these details. The route is `POST rrt/v1/events` and always answers 204. Batches go every 15 seconds, on pause, end and leave, and when the tab is hidden, as `text/plain` so no preflight request is needed. Limits: 24 KB and 60 events per batch, 1,500 events per session, 60 new page views an hour per listener id (counted from the sessions table, so nothing extra is stored). The rollup rebuilds whole days from their sessions, so running it twice changes nothing; a session is finished 30 minutes after its last event. Trimming runs daily.

---

## Privacy

Measuring listening behaviour is reasonable for a site owner improving their own content. Doing it without outside trackers is also a selling point: no Google Analytics, no third party sees anything.

Defaults, all adjustable in Settings:

- **No IP addresses stored.** Each request's IP address, user agent and the site's domain are hashed with a **salt that is rotated and deleted every 24 hours**. The hash becomes the day's listener id (used for unique listener estimates, 24 hour play deduplication and rate limiting). Once the salt is gone, no one, including the site owner, can link that id to a person or to any other day. This is the method Plausible uses.
- **No cookies, no cross-site tracking.** Nothing is written to the listener's browser for analytics. An optional **return counter** stores a random id in the listener's browser (`localStorage`) to count returning listeners across days; it is off by default and should be mentioned in the privacy policy if turned on. **Resume where you left off** stores only the playback position, in the listener's own browser, and is never sent.
- **Do Not Track and Global Privacy Control respected:** those visitors are not measured at all.
- **Logged-in editors excluded.**
- **Retention:** raw events 30 days, sessions 13 months, daily rollups kept.
- **Consent mode** (optional): measurement starts only after a consent banner says yes, for sites with visitors in places that require it.
- **Privacy policy text** generated for the site's policy page.
- **Export and delete:** hooks into WordPress's personal data tools for logged-in readers' stats.

Privacy laws such as Canada's PIPEDA and Europe's GDPR treat anonymous, aggregated, first-party measurement more lightly than personal tracking. Keeping visitor data anonymous by design is the simplest way to stay on the right side of all of them. This is a design note, not legal advice.

---

## The dashboard

A **Foxfire Studio** menu in WP-admin. Every screen has a date range (default: last 30 days), a "compare to previous period" toggle, and CSV export.

Every chart has an **ⓘ** that opens the exact definition of each number, and every chart title includes the date range and the site's time zone. Change markers appear as small flags along the time axis of every time chart.

**Built (0.11.0):** Overview, Readings, Reading detail, Sources (with the link builder), Your progress (with goals and the practice log), and Recordings, as sketched below. The Reports screen comes with 0.12.0, and Settings stay at Settings, Foxfire Studio with a link in the menu. Differences from the sketch: the Overview's tiles are plays (with estimated listeners), pressed play, still listening at one minute, heard to the end, average speed and listening time; the reading detail shows replays, skips and speed changes as a second chart under the curve, with the script in the tooltip and a "Where listeners leave" list instead of a script strip; drop-offs and comparisons are hidden below 30 plays (`rrt_dashboard_min_plays` filter). The listener screens need `manage_options` (`rrt_dashboard_cap` filter).

### Overview
```
┌─────────────────────────────────────────────────────────────────────┐
│ Last 30 days ▾   compare: previous 30 days ☑            Export CSV  │
├──────────┬──────────┬──────────┬──────────┬──────────┬──────────────┤
│ Plays    │ Play rate│ Held 60s │Completion│ Avg speed│ Listened     │
│ 412 ▲18% │ 31% ▲3pt │ 81% ▲4pt │ 46% ▼2pt │ 1.12x    │ 19.4 h       │
├──────────┴──────────┴──────────┴──────────┴──────────┴──────────────┤
│ Plays per day ▁▂▃▅▃▂▂▃▇█▅▃▂▂▃▄▃▂▁▂▃▅▆▄▃▂▂▃▄▅                          │
├───────────────────────────────────┬─────────────────────────────────┤
│ Top readings                      │ Top sources                     │
│ Autumn notes      128  58% done  │ Instagram  140  play 38% 51%    │
│ The Thin Veil       96  41% done  │ Direct      98  play 44% 49%    │
│ ...                               │ ...                             │
├───────────────────────────────────┴─────────────────────────────────┤
│ Worth a look: "The Thin Veil" loses a third of listeners at 1:45.   │
└─────────────────────────────────────────────────────────────────────┘
```

### Readings
Table of every published reading: title, published date, duration, tempo, plays, play rate, average listened, completion, average speed, biggest drop-off (time), top source. Sort by any column; filter by tag and date.

### Reading detail
- **Retention curve** across the full duration, with the previous period as a faint line.
- Under it, the **script**, aligned to time, so the sentence at any point is right there.
- Overlays you can switch on: drop-off spikes, replay hot spots, skip zones, speed-up and slow-down zones.
- Click anywhere to hear that moment.
- Side panel: plays, completion, quartiles, speed mix, background listening share, follow-along use, sources for this reading, devices.
- The take it came from: its pace, accuracy, flags, room noise, with a link to open its review.

### Sources
Channels and campaigns with views, play rate, completion and average listened; a chart of each channel over time; the **link builder** (pick post, channel, campaign name, copy link).

### Your progress
Per reader: accuracy, pace, fillers, retakes, ending drop, pitch movement and room noise over time; personal bests; most improved; the words Whisper has learned.

### Recordings
Every saved recording with file name, post, link, duration, size, tempo, date and whether it is live. Links to the take and the reading detail.

### Reports
Schedule, recipients, last sent, preview, **Send now**, and an archive of past reports.

### Settings
See below.

---

## The monthly email

**Built (0.12.0):** calendar periods instead of rolling 30 days: each month by default, sent on the 1st at 8 am site time for the month before; weekly (Monday to Sunday), every two weeks (counted from Monday 1 January 2024) and quarterly too. Subject: *Show name: your readings, October 2026*. Contents follow the list below, with item 1's tiles as plays (with estimated listeners), still listening at one minute, heard to the end and listening time; item 5 as a speed mix from each play's average speed, background listening and read along; item 8 limited to two observations (shorter against longer readings when at least 6 readings have 30 plays each and the gap is 10 points or more; best and worst source by completion when two sources have 30 plays and the gap is 10 points or more). Settings are in their own `rrt_report` option and the Reports screen. **From 0.19.0** item 8 comes from the insights: the two clearest listener observations for the period, by the same rules as the Insights screen (each reading counts once; 6 readings with 30 plays, 3 on each side, and a 10 point gap). Each email is archived in `rrt_reports` with its HTML. The hourly `rrt_report_check` job sends each period once, catches up after late cron, and tries a failed send three more times.

Sent every 30 days by default. Subject: *Foxfire Studio: your readings, Oct 10 to Nov 9*.

**Contents, in order**
1. **Headline:** plays, estimated listeners, opening hold rate, listening hours, completion rate, each with change from the previous period. Period and time zone stated at the top.
2. **Top readings** (up to 5): plays, completion, average listened.
3. **Held them longest** and **lost them soonest**, with the moment and sentence where most people left.
4. **Where listeners came from:** top channels with play rate and completion.
5. **How they listened:** speed mix, background listening, follow-along use.
6. **New this period:** readings published, with their duration and tempo.
7. **Your recording:** takes recorded, accuracy and pace trend, best take.
8. **One or two observations** from the insights work, each with its sample size, or none if the data is too thin.
9. Link to the dashboard for the same period.

Attachments (optional): CSV of the period's readings and sources.

**Delivery:** WP-Cron schedules it, which only runs when the site gets a visit. For reliable timing, a real cron job on the host calls `wp-cron.php` every 15 minutes; SETUP.md will have the exact steps. Mail goes through `wp_mail`; an SMTP plugin is recommended so it does not land in spam.

---

## Settings

Everything in one screen, in tabs. Each setting shows its default.

**Look**
- Preset (Neutral, the dark preset, custom)
- Colours (background, text, highlight, said, ahead, accent, panel, line)
- Fonts for reading and controls; load from Google or self-host
- On-screen wording (title, welcome, prompts)

**Reading**
- Content types offered (posts, pages, custom types)
- Default pace, text size, column width, reading line
- Breath between paragraphs on/off
- Countdown length

**Recording**
- Clean up audio on/off by default, and each cleanup step on/off
- MP3 bitrate (96, 128, 160)
- Fade lengths
- Keep the original download on/off

**Whisper**
- Review on/off by default
- Model (base.en; larger model when available)
- Where to load from (this site, Hugging Face) with a **Download to this site** button and status
- Sound-alike handling on/off
- Pace flag sensitivity
- Learned words: global, per post, or both

**Player** (public)
- Add player to posts with a reading automatically
- Speed options, skip lengths
- Follow-along on/off and default state
- Captions on/off
- Download button on/off

**Analytics**
- Listener measurement on/off
- Exclude logged-in users / editors only / nobody
- Respect DNT and GPC
- Consent mode
- Return counter on/off
- Bucket size (5 s default)
- Retention periods for events, sessions
- Minimum data for comparisons
- Campaign alias parameter name (`src`)

**Reports**
- On/off, interval, send day and time
- Recipients
- Sections included
- Attach CSV

**Advanced**
- Who can open the teleprompter (capability)
- Who can see the dashboard (capability)
- Delete all data on uninstall
- Debug logging

---

## Performance on shared hosting

- The public player script is small and loads only on posts with a reading.
- Events are batched; a typical 4 minute listen sends 5 to 10 small requests.
- Writes go to narrow tables with indexes on `post_id`, `recording_id` and dates.
- The dashboard reads only rollup tables, never raw events, so it stays fast as data grows.
- Rollups run in small batches so a cron run never times out.
- The REST route skips loading anything the event handler does not need.
- The page cache must not cache the REST route (it is excluded by default for logged-out POSTs, but the docs will note it).

---

## Testing the numbers

- Unit tests for the metric math: retention, quartiles, completion, drop-off detection, replay and skip zones, using hand-made event streams with known answers.
- A simulator that plays a reading in a headless browser with scripted behaviour (listen to 60%, speed up at 1:00, skip 30 s at 2:00, leave) and checks the dashboard shows exactly that.
- A manual check list for each release: listen on a phone from a tagged link, then confirm the session, source, buckets and speed change appear correctly.

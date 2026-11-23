# Roadmap

Where Foxfire Studio is headed, in the order it makes sense to build. Each milestone has a goal, what it includes, and a **finish line**: the conditions that have to be true before it is tagged and released.

The big direction is a feedback loop with two halves:

- **The reader's side:** every take is measured, kept, and compared, so the person recording can see what is improving and what is not.
- **The listener's side:** every play of a published reading is measured, so the person recording can see what listeners actually do with it: where they stop, where they skip, where they speed up, and which readings hold them to the end.

Put together, the two halves answer the real question: **what makes a reading work?** Is it length, pace, the topic, where it was shared? The dashboard and monthly email exist to answer that.

The detailed design for stats, the dashboard and the email is in [docs/ANALYTICS-SPEC.md](docs/ANALYTICS-SPEC.md). This page is the plan; that page is the blueprint.

Items marked **(R)** came out of the best-practice research in [docs/RESEARCH.md](docs/RESEARCH.md), which explains the reasoning and links the sources. Each new item also has a rough **effort**: S (an evening or two), M (a weekend), L (several weekends).

---

## At a glance

Version numbers rise in the order things are built. In November 2026 the podcast audio work (0.4.0 to 0.8.0) was moved ahead of the stats work, and the stats milestones were renumbered to start at 0.9.0. Notes written before then use the old numbers: reader stats was 0.2.0, listener analytics 0.4.0, the dashboard 0.5.0, the monthly report 0.6.0, clips 0.6.5, insights 0.7.0 and studio upgrades 0.8.0. Later in November 2026 three small studio upgrades were built before insights, which needs a few months of data, so studio upgrades took 0.14.0 and insights moved to 0.15.0. Then three more small studio upgrades (font switch, warm-up, cue marks) took 0.15.0, and insights moved to 0.16.0. Then learned words per post and the larger Whisper model took 0.16.0, sharing a take for feedback took 0.17.0, and insights moved to 0.18.0. Then the studio look took 0.18.0, and insights moved to 0.19.0.

| Version | Theme | Depends on |
|---|---|---|
| [0.1.0](#010-one-codebase) | One codebase (released) | |
| [0.3.0](#030-the-listening-player) | The listening player (released) | 0.1.0 |
| [0.4.0](#040-lossless-recording) | Lossless recording (released) | 0.1.0 |
| [0.5.0](#050-broadcast-sound) | Broadcast sound (released) | 0.4.0 |
| [0.6.0](#060-speech-model-noise-reduction) | Speech-model noise reduction (released) | 0.4.0 |
| [0.7.0](#070-podcast-ready-files) | Podcast-ready files (released) | 0.5.0 |
| [0.8.0](#080-text-based-editing) | Text-based editing (released) | 0.4.0 |
| [0.9.0](#090-reader-stats-on-the-server) | Reader stats on the server (released) | 0.1.0 |
| [0.10.0](#0100-listener-analytics) | Listener analytics (0.10.1: trusted proxies and hourly limits) (released) | 0.3.0 |
| [0.11.0](#0110-the-dashboard) | The dashboard (released) | 0.9.0, 0.10.0 |
| [0.12.0](#0120-the-monthly-report) | The monthly report (released) | 0.11.0 |
| [0.13.0](#0130-clips-for-social-media) | Clips for social media (released) | 0.3.0; better with 0.11.0 |
| [0.14.0](#0140-studio-upgrades-microphone-check-opening-drill-practice-focus) | Studio upgrades: microphone check, opening drill, practice focus (released) | 0.9.0 |
| [0.15.0](#0150-studio-upgrades-font-switch-warm-up-cue-marks) | Studio upgrades: font switch, warm-up, cue marks (released) | 0.1.0 |
| [0.16.0](#0160-better-listening-learned-words-per-post-larger-whisper-model) | Better listening: learned words per post, larger Whisper model (released) | 0.9.0 |
| [0.17.0](#0170-share-a-take-for-feedback) | Share a take for feedback (released) | 0.9.0 |
| [0.18.0](#0180-the-studio-look) | The studio look (released) | 0.9.0 |
| [0.19.0](#0190-what-works-insights) | What works: insights (released) | 0.11.0, and a few months of data |
| [0.20.0](#0200-retake-edge-nudge) | Retake edge nudge (released) | 0.8.0 |
| [0.21.0](#0210-send-to-my-computer) | Send to my computer (released) | 0.19.1 |
| [0.22.0](#0220-the-podcast-feed) | The podcast feed, and a "read by" credit (released) | 0.3.0, 0.7.0 |
| [0.23.0](#0230-an-intro-for-the-studio) | An intro video when the studio opens (released) | 0.18.0 |
| [0.24.0](#0240-a-music-bed) | A music bed under a reading, ducked under the voice (released) | 0.5.0 |
| [0.25.0](#0250-groundwork-for-100) | Groundwork for 1.0: translation-ready text and automatic checks (0.25.1 and 0.25.2: one file per area) (released) | 0.24.0 |
| [0.26.0](#0260-upgrade-and-uninstall-checks) | Upgrade and uninstall checks (released) | 0.25.0 |
| [0.27.0](#0270-docs-pass-and-release-candidate) | Docs pass and the 1.0 release candidate (released) | 0.26.0 |
| [Not yet numbered](#more-studio-upgrades) | More studio upgrades | 0.1.0 |
| [1.0.0](#100-stable) | Stable (released) | All of the above |
| [1.1.0](#110-pronunciation-notes-and-flags-after-edits) | Pronunciation notes, and flags after edits (released) | 1.0.0 |
| [1.2.0](#120-wider-listening-numbers) | Wider listening numbers: read or listen, time to press play, new and returning listeners, podcast app downloads, and an optional Google Analytics link (released) | 1.0.0, 0.10.0; 0.22.0 for downloads |
| [1.3.0](#130-one-reading-from-several-takes) | One reading from several takes (released) | 1.0.0, 0.8.0 |
| [1.4.0](#140-the-backlog-readings-to-make-and-a-long-post-read-in-parts) | The backlog: readings to make, and a long post read in parts (released) | 1.3.0 |
| [1.5.0](#150-prompter-view-for-glass-rigs) | Prompter view for glass rigs (released) | 1.4.0 |
| [1.6.0](#160-finishing-presets) | Finishing presets (released) | 1.4.0, 0.5.0 |
| [1.7.0](#170-clip-suggestions) | Clip suggestions (released) | 0.13.0, 1.6.0 |
| [1.8.0](#180-keep-a-reading-aside) | Keep a reading aside (released) | 1.7.0, 0.21.0 |
| [1.9.0](#190-phone-level) | Phone level (released; 1.9.1 fixed the review of long readings) | 0.14.0, 1.6.0 |
| [1.10.0](#1100-the-full-podcasting-20-feed) | The full Podcasting 2.0 feed | 1.7.0, 0.22.0 |
| [1.11.0](#1110-review-for-verse-and-pace) | Review for verse and pace | 0.15.0, 0.8.0 |
| [1.12.0](#1120-phone-take-mode) | Phone take mode (released; 1.12.1 tidied it) | 0.21.0, 1.9.0 |
| [1.13.0](#1130-voice-polish) | Voice polish (released) | 0.5.0, 1.6.0 |
| [1.14.0](#1140-voice-following) | Voice following on the device (released) | 0.0.3, 1.12.0 |
| [1.15.0](#1150-the-pronunciation-list) | The pronunciation list (released; 1.15.1 judged verse as verse in the Endings line and How hard) | 1.1.0, 0.16.0 |
| [1.16.0](#1160-the-stronger-cleanup) | The stronger cleanup (released) | 0.6.0, 1.13.0 |
| [1.17.0](#1170-the-faster-review) | The faster review, the last of Phase 1 (released) | 0.16.0, 1.9.1 |
| [1.18.0](#1180-full-length-video) | Full-length video, the first of Phase 2 (released) | 0.13.0, 1.10.0 |
| [1.19.0](#1190-collections) | Collections, the second of Phase 2 (released) | 1.10.0, 1.6.0 |
| [1.20.0](#1200-audiobook-export) | Audiobook export, the third of Phase 2 (released) | 1.19.0, 0.5.0 |
| [1.21.0](#1210-member-feeds) | Member feeds, the fourth of Phase 2 (released) | 1.10.0, 1.8.0 |
| [1.22.0](#1220-sponsor-slots) | Sponsor slots, the fifth of Phase 2 (released) | 1.19.0, 1.21.0 |
| [1.23.0](#1230-storage-offload) | Storage offload, the sixth of Phase 2 (released) | 1.10.0, 1.21.0 |
| [1.24.0](#1240-download-counting-to-the-iab-guidelines) | Download counting to the IAB guidelines, the last of Phase 2 (released) | 1.2.0, 1.21.0, 1.22.0, 1.23.0 |
| [1.25.0](#1250-the-security-review) | The security review, the first of Phase 3 | 1.24.0 |
| [Not yet numbered](#phase-2-reach) | Phase 2, Reach: getting readings everywhere | Phase 1 |
| [Not yet numbered](#phase-3-ready-for-others) | Phase 3, Ready for others: before any public release | Phase 2 |
| [Not yet numbered](#phase-4-more-voices) | Phase 4, More voices | A public release |
| [Not yet numbered](#phase-5-optional-ai-helpers) | Phase 5, Optional AI helpers | A public release |
| [Numbered when built](#listener-reactions) | Listener reactions at a moment (held 1.1.0 until 15 November 2026) | 1.0.0, 0.10.0; a public privacy policy |

The remaining studio upgrades do not depend on anything else and can be picked up any time, in pieces. Each gets a number when it is picked up.

### Quick wins

If you want visible progress before the big plugin merge, these research-backed items are small, useful on their own, and do not need the stats work:

| Item | Effort | Why |
|---|---|---|
| Lock screen controls for the public player | S | Phone listeners keep title, artwork and skip buttons when the screen locks |
| Opening drill | S | The opening is where listeners decide to stay; practise just that part |
| Practice focus | S | One habit per session is how speaking coaches recommend practising |
| Share-a-moment links | S | Point a social post at the exact moment, and measure it |

---

## 0.1.0 One codebase

**Goal:** one plugin that does everything both editions do, so features are built once.

**Progress:** released as v0.1.0, and the live site has moved to the plugin. Done: feature parity, settings screen, presets, folder split, Whisper download button, browser storage migration, and (in 0.3.2) self-hosted fonts and MP3 encoder. Still to do: removing the old template page once its week as a fallback is up. Splitting the script into one file per area started in 0.25.1 and was finished in 0.25.2 (see 0.25.0 below); it changes nothing a reader sees.

**Includes**
- Bring the plugin up to feature parity with the theme template edition (read-out, Whisper review, teachable words, cleanup, self-hosting).
- Move all the MAKE IT YOURS options into a real **Settings** screen in WP-admin, stored in the database. Keep the `rrt_config` filter for developers.
- A **preset** system so the original site's look (palette, Cinzel Decorative, Cormorant Garamond, Raleway) is one saved preset, and the neutral look another.
- Split the single file into folders: `includes/` (PHP), `assets/js/`, `assets/css/`. Each JS area becomes its own file: reader, voice, recorder, cleaner, mp3, analyse, review, studio. Loaded with `wp_enqueue_script` and versioned for cache busting.
- Self-host Google Fonts and lamejs as an option, so the page can run with no outside requests at all.
- Whisper files manageable from WP-admin: a "Download Whisper to this site" button that does what `whisper-setup.sh` does, with progress, so no SSH is needed.
- A short migration step: retire the theme template on the live site and point the private page at the plugin.

**Finish line**
- The live site runs on the plugin with no visible change in look or behaviour.
- A fresh WordPress install can install the zip and use every feature.
- Nothing is lost from the browser: settings, takes and learned words carry over (same storage keys, or migrated).

---

## 0.3.0 The listening player

**Goal:** a player on the public post that listeners enjoy using, and that can be measured.

**Progress:** released as v0.3.0 and live on the site. Done: the player, skips, speed, lock screen controls, read along as an interactive transcript, resume, share-a-moment links, no-JavaScript fallback, the Reading audio box. Moved on: chapter marks at headings go to [0.7.0](#070-podcast-ready-files), and the transcript accuracy check to [0.9.0](#090-reader-stats-on-the-server), since it needs the review studio to save its results.

Today the published MP3 plays in whatever player the theme provides. To learn how listeners behave, the plugin needs its own player.

**Includes**
- An accessible, theme-matching player shortcode and block, added automatically to posts that have a reading: play/pause, scrubber, time, **speed control** (0.75x to 2x), skip back 15 s and forward 30 s, volume, download (optional).
- **Follow along:** an option for the post text to highlight in step with the audio, using the take's timings (the same karaoke as the studio, on the public page). This doubles as accessibility for people who read and listen at once.
- Captions/transcript using the take's .vtt.
- Chapter marks at headings.
- Works without JavaScript as a plain `<audio>` element.
- **(R) Lock screen controls** with the Media Session API: title, the post's featured image as artwork, play, pause, skip back, skip forward and seek, from the lock screen, headphones and car. *Effort S.*
- **(R) Follow along built as an interactive transcript** to the W3C pattern: text on the same page, near the player, highlighted as it is spoken, clickable to jump. A transcript is required at WCAG Level A for prerecorded audio. *Effort M.*
- **(R) Transcript accuracy check.** When a take is published, the review lists any places the spoken reading differs from the post text, so the page can be corrected or a note added and stays a true transcript. *Effort S.*
- **(R) Resume where you left off.** Returning listeners pick up from their last position (kept in their own browser only, and only if they started listening). *Effort S.*
- **(R) Share-a-moment links.** A link ending in `?t=1:45` starts the reading at that moment. Useful for social posts and measurable through campaign tags. *Effort S.*

**Finish line**
- Plays on iOS Safari, Android Chrome, desktop browsers.
- Keyboard and screen reader friendly.
- Looks at home in the live site's design.

---

## 0.4.0 Lossless recording

**Progress:** released as v0.4.0 on 10 November 2026 and live on the site. The real-voice test found that cleanup confused Whisper on lossless audio, so Whisper now hears the untouched recording.

**Goal:** every reading is kept at full quality from the microphone to the final MP3, and a take survives a crash.

Today the browser records into a compressed format (WebM with Opus) and the MP3 step compresses it again, so every reading goes through lossy compression twice. A frozen tab or a closed laptop also loses the whole take. See [RESEARCH.md](docs/RESEARCH.md#recording-and-processing-for-podcasts).

**Includes**
- **(R) Lossless capture.** The microphone is captured as raw audio by an AudioWorklet, so cleanup and the MP3 work from the untouched signal. The browser's compressed recorder stays as a fallback where AudioWorklet is missing. *Effort M.*
- **(R) Crash-safe takes.** The recording is saved to the browser's storage in small chunks as it happens. If the page closes before the MP3 is made, the teleprompter offers to recover the take next time it opens. *Effort M.*
- The **Original** download becomes a WAV file, the true master.
- Recording follows the reading as before: it pauses when the reading pauses.

**Finish line**
- A real-voice take is identical in timing to today's, and the Whisper review and read-out work as before.
- Closing the tab mid-take and reopening the teleprompter recovers everything recorded up to that moment.
- Firefox and Safari still record, through the fallback if needed.

---

## 0.5.0 Broadcast sound

**Progress:** released as v0.5.0 on 10 November 2026 and live on the site. On a real 1:54 take: -19.2 LUFS, -1.9 dBTP, Whisper 99%. The maintainer judged it fine against the 0.4.0 sound of the same take; a second listener is still to come. Default loudness is -19 LUFS for a mono reading, which plays like -16 (see DESIGN.md, section 9).

**Goal:** readings sound like a well-produced podcast and match the loudness of every other show in a listener's app.

**Includes**
- **(R) A voice processing chain** before the MP3, using the audio tools every browser already has: a high-pass filter at about 80 Hz for rumble and bumps, gentle compression so loudness stays even within sentences, a de-esser for harsh S sounds generally (today only the narrow whistle is tamed), plosive softening for "p" and "b" pops, and breath taming that lowers breaths a few dB and keeps them. *Effort M.*
- **(R) Loudness to the podcast standard.** Measure in LUFS (ITU-R BS.1770) and level to -16 LUFS integrated with true peak no higher than -1 dBFS, which is what Apple Podcasts asks for and most shows deliver. Show the measured loudness on the Room line. Replaces today's home-made leveling target. *Effort M.*
- **(R) Studio-ready check.** A short pass/fail list after each take: loudness, true peak, noise floor, clipping, head and tail silence. With an **audiobook preset** that follows ACX's rules (RMS -23 to -18 dB, peaks under -3 dB, noise floor at or below -60 dB, 1 to 5 seconds of room tone at each end, 192 kbps CBR MP3), in case a reading ever goes to Audible. *Effort M.*
- Each step can be switched off, and the read-out says what each one did.

**Finish line**
- A real-voice take measures its target loudness (within 1 dB) with true peak at or under -1 dBTP in the finished MP3.
- Whisper accuracy on the same take is unchanged with the processing on (Whisper hears the untouched recording since 0.4.0, and that must stay true).
- The maintainer and one other listener prefer the processed take to the 0.4.0 one, on headphones and on a phone speaker.

---

## 0.6.0 Speech-model noise reduction

**Progress:** released as v0.6.0 on 10 November 2026 and live on the site. RNNoise with a Gentle, Medium or Strong setting, self-hosted from Settings, fingerprint checked on every load, and the Room line reports before and after. On a real 4:27 take Medium lowered the background by 10 dB (the room was already quiet, 37 dB below the voice), the MP3's background was -76.2 dB, and the maintainer heard no warbling or cut-off word endings on headphones. DeepFilterNet3 stays a later option if RNNoise is not enough.

**Goal:** a reading recorded in an ordinary room sounds like it was recorded in a quiet one.

**Includes**
- **(R) RNNoise** (BSD licence, about 110 KB as WebAssembly), a small model trained to separate speech from noise. It handles uneven noise the current cleanup cannot, such as traffic, a chair, or a fan that changes speed. It runs after recording, like Whisper, so the laptop stays quiet while reading. *Effort M.*
- Self-hosted from Settings with a fingerprint check, like Whisper and the fonts.
- A strength setting, and the Room line reports the background level before and after.
- **(R) DeepFilterNet3** (MIT or Apache) as a later, heavier option if RNNoise is not enough. It runs on ONNX Runtime, which the Whisper review already loads. *Effort L.*

**Finish line**
- On a take recorded with a noisy background, the background drops by at least 10 dB with no warbling or swallowed word endings that the maintainer can hear.

---

## 0.7.0 Podcast-ready files

**Progress:** released as v0.7.0 on 10 November 2026. Tags with artwork in every MP3, a Show name setting, chapters at the post's headings from the Whisper review, a chapters file, chapters in the player, and LAME as WebAssembly (four times faster than lamejs in a real browser, ten times in Node). Variable bitrate was dropped: this LAME build cannot write the length header such files need. Chapters were tested locally; the first live take on a post with headings, opened in a chapter-aware podcast app, completes the finish line.

**Goal:** the MP3 carries everything a podcast app shows, and is made by a faster, current encoder.

**Includes**
- **(R) ID3 tags:** title, author, show name, year, the post's featured image as artwork, and a link back to the post. Podcast apps and phones show these. *Effort S.*
- **(R) Chapter marks** from the post's headings, written into the MP3 (ID3 chapters) and offered as a Podcasting 2.0 chapters file. The player shows them too (moved here from 0.3.0). *Effort M.*
- **(R) A WebAssembly LAME encoder** (wasm-media-encoders) in place of lamejs: faster, current, and able to make constant 192 kbps files for the audiobook preset or smaller variable-bitrate files. Self-hosted with a fingerprint check. *Effort S.*

**Finish line**
- A downloaded MP3 shows its title, artwork and chapters in a chapter-aware podcast app.
- Encoding a four-minute take is no slower than today.

---

## 0.8.0 Text-based editing

**Progress:** released as v0.8.0 on 10 November 2026 and live on the site: strike to cut with quiet-point snapping and crossfades, Tidy with previews, punch-in retakes, undo, and an edited MP3 with chapters and captions moved to match. On a real take, the maintainer found the editing fast and the joins natural on headphones.

**Goal:** fix a reading by editing words, without re-recording the whole post.

The Whisper review already knows when every word was said. That makes it possible to edit the audio by editing the script.

**Includes**
- **(R) Strike to cut.** Strike a word, a stumble or a sentence in the review's marked-up script and the audio is cut to match, with short crossfades so no edit clicks. *Effort L.*
- **(R) One-click tidy:** remove fillers ("um", "uh") and shorten pauses over about a second and a half, with a preview before anything changes. *Effort M.*
- **Punch-in retakes** (moved from the studio upgrades): re-record just a flagged sentence and splice it into the take. *Effort L.*
- Every edit can be undone, and the original recording is always kept.

**Finish line**
- A five-minute take with three fillers and one stumble is fixed in under two minutes with no audible edit points.

---

## 0.9.0 Reader stats on the server

**Progress:** released as v0.9.0 on 10 November 2026 and live on the site: takes and recordings tables, taught words per reader, take comparison from the site, "Use this recording" with captions, the energy measure, a one-time import of each browser's old history, and a switch back to browser-only storage. On the live site, a take in Firefox compared against Chrome's history and Chrome then showed the Firefox take. Goals and the practice log move to 0.11.0, alongside the progress dashboard. Taught words are kept in each reader's user data instead of an `rrt_learned` table.

**Goal:** every take is kept, on the server, for every reader, so progress can be seen over weeks and across devices.

**Includes**
- A **Takes** table recording, for each take: post, reader, date, duration, words, pace, accuracy, skipped/misread/added/repeated/filler counts, long pauses, retake count, rest share, ending drop, pitch spread, background level, clicks, whistle share, which mode (timed or voice), and the settings used.
- A **Recordings** table for every saved MP3: file name, the post it belongs to, its link, duration, size, average tempo (wpm), date, which take it came from, and whether it is the one published on the post.
- Learned Whisper words stored per reader on the server, so teaching once works on every computer.
- The take comparison in the review studio reads from the server.
- Optional **upload straight to the post**: "Use this recording" puts the MP3 in the media library and attaches it to the post's audio field, instead of downloading and uploading by hand.
- **(R) Goals and a practice log.** Set one or two targets ("pace between 135 and 150", "fewer than 3 fillers") and see each take against them. A simple log of sessions, without streaks or badges. *Effort M.*
- **(R) Energy measure.** How much the voice's loudness moves within sentences, alongside pitch movement. The one common coaching measure not yet tracked. *Effort S.*
- Recordings themselves still never leave the computer unless "Use this recording" is clicked.

**Finish line**
- Takes recorded on two computers show up in one history.
- Every published reading has a Recordings row with its tempo and duration.
- Turning server storage off falls back to today's browser-only behaviour.

---

## 0.10.0 Listener analytics

**Progress:** released as v0.10.0 on 10 November 2026 and live on the site. A phone listen and a computer listen from tagged links both appeared within a minute with the right source, device and behaviour. The maintainer chose to download the country database (DB-IP Lite) and to leave out editors and admins. Plays are counted once per listener id per reading per day, since the daily salt is what limits the 24 hour window. The full dashboard stays in 0.11.0; this version adds a Recent listens list in Settings for checking. Still to come here: the optional return counter and consent mode from ANALYTICS-SPEC.md, which are off by default and were not needed on the live site.

**Goal:** measure what listeners do with each reading, privately and without outside services.

**Includes** (full detail in [ANALYTICS-SPEC.md](docs/ANALYTICS-SPEC.md#listener-events))
- The player reports events to the site: play, pause, seek (from where to where), speed changes (from what to what, and when), volume and mute, ending, leaving the page while playing, returning, and follow-along turned on or off.
- Progress recorded in **5 second buckets**, so every second of every reading has a "how many people heard this part" count.
- Per post: also the reading time on the page, how far down the page people scrolled, and whether they played the audio at all.
- **Where listeners came from:** the referring site, and campaign tags on links (for example `?src=instagram`), so each social post can be compared.
- Device type and rough region (country), with no IP addresses stored.
- Stored in the site's own database. Raw events are rolled up into daily summaries and then trimmed after a set period.
- Respects Do Not Track and Global Privacy Control. Logged-in editors are excluded by default, so your own testing does not skew things.
- **(R) Estimated daily unique listeners** without cookies, using a hash of IP address, browser and site with a salt that is deleted every 24 hours (the approach Plausible uses). Nothing that identifies a person is stored. *Effort S.*
- **(R) Plays deduplicated within 24 hours**, following the IAB's podcast measurement practice, so refreshes and reloads do not inflate counts. *Effort S.*
- A short paragraph to add to the site's privacy policy is generated for you.

**Finish line**
- A test listen from a phone shows up correctly in the raw data within a minute.
- Bots and repeat refreshes do not inflate counts.
- No personal data (IP, exact location, account) is stored for visitors.

---

**0.10.1 (security fix and privacy review):** forwarding and country headers trusted only from listed proxies, per-address and site-wide hourly limits, PRIVACY.md, and documentation rewritten for public release.

## 0.11.0 The dashboard

**Progress:** released as v0.11.0 on 12 November 2026, with 0.10.1's security fix and privacy review included, and live on the site. Built and tested on a local site with a year of made-up data. Everything listed below is in, plus goals and the practice log (moved here from 0.9.0). The Reports screen moves to 0.12.0 with the email it schedules, and Settings stay where they are, linked from the new menu.

**Goal:** see everything in WP-admin, any time, without waiting for an email.

**Includes** (screens are sketched in [ANALYTICS-SPEC.md](docs/ANALYTICS-SPEC.md#the-dashboard))
- A **Foxfire Studio** menu in WP-admin with:
  - **Overview:** plays, listeners, listening hours, completion rate, average speed, top readings, top sources, trend against the previous period.
  - **Readings:** every published reading with its duration, tempo, plays, completion, average listened, and drop-off point. Sortable, filterable by date and tag.
  - **Reading detail:** the **retention curve** (how many listeners are still there at each moment), drop-off spikes, **replay hot spots**, **skip zones**, **speed changes along the timeline**, with the script lined up underneath so you can see exactly which sentence lost people. Click any point to hear it.
  - **Sources:** plays and completion by social site and campaign link, so you can see which channel brings people who actually listen.
  - **Your progress:** reader stats from 0.9.0: accuracy, pace, fillers, retakes, room noise over time, per reader.
  - **Recordings:** the file list from 0.9.0.
  - **Reports:** email schedule, recipients, "send now", past reports.
  - **Settings:** everything configurable (see [ANALYTICS-SPEC.md](docs/ANALYTICS-SPEC.md#settings)).
- Date range picker, comparison to the previous period, CSV export on every table.
- **(R) Metric definitions** one click away on every chart, and the site's time zone printed on every chart and report, so a number is never ambiguous. *Effort S.*
- **(R) Opening hold rate:** the share of plays still listening at 60 seconds, as a headline number. Early drop-off is the clearest sign the opening needs work. *Effort S.*
- **(R) Change markers.** Mark a date or a reading with what you changed ("shorter intro", "recorded with USB mic", "posted at 7 pm") and see it on every chart, so before and after can be compared honestly. *Effort M.*
- A campaign **link builder**: pick a post and a channel, copy a tagged link.

**Finish line**
- Every number in the dashboard can be traced back to raw events.
- Loads in under two seconds with a year of data on shared hosting (summary tables make this possible).

---

**0.11.1:** renamed Foxfire Studio, in the `foxfire-studio` folder, with a safe hand-over from Reading Room Teleprompter.

## 0.12.0 The monthly report

**Progress:** released as v0.12.0 on 13 November 2026 and live on the site, after the local test of three periods and a first report sent from the live site. Tested on a local site with 13 months of made-up data. Calendar months by default (sent on the 1st, covering the month before), with weekly, every two weeks and quarterly. Settings live on a new Reports screen. Observations are limited to two cautious ones until insights (now 0.19.0). The maintainer chose to release once the local test of three periods passed and a report went out from the live site, and to watch the first three scheduled reports there (December to February) instead of waiting three months before tagging.

**Goal:** a regular email that tells you how your readings did, so you do not have to remember to check.

**Includes**
- An HTML email every **30 days** by default (interval adjustable: weekly, every 2 weeks, 30 days, quarterly), sent to one or more addresses set in Settings.
- Contents: headline numbers and change since last period, top readings, the reading that held people longest and the one that lost them soonest (with the sentence where most left), top sources, speed habits, new readings published, your own recording progress, and one or two plain-language observations.
- CSV of the period's data attached (optional).
- A **Send now** button, and a preview in the dashboard.
- Scheduling through WP-Cron, with instructions for a real server cron job so it does not depend on site traffic. Uses the site's mail setup; works best with an SMTP plugin.
- **(R) Cron health check.** The Reports screen notices when scheduled jobs are running late and shows the exact fix: a server cron calling `wp-cron.php` every 5 to 15 minutes, then `DISABLE_WP_CRON` in `wp-config.php`, in that order. *Effort S.*

**Finish line**
- Reports arrive on schedule for three periods in a row.
- The email reads well on a phone.
- Numbers match the dashboard for the same period.

---

## 0.13.0 Clips for social media

**Progress:** released as v0.13.0 on 13 November 2026 and live on the site. A 30 second square clip of a real reading, made on the site in Chrome on Windows (Whisper from the site, AAC sound), rendered in 30 seconds and posted to Instagram, where it played correctly. The second finish line (visits through a clip's link showing under its name in Sources) is checked as clips get shared. Made in the browser from each reading's page and from the review studio, in three shapes, with the post's image, waveform, captions spelled from the post, the site's address and an end card, and a tagged link per clip. Suggested moments: the opening, most replayed and most lively. Whisper runs on the chosen stretch when there is no review. Chrome or Edge only; AAC sound on Windows and Mac.

**Goal:** turn the best moments of a reading into short captioned clips for Instagram, Facebook, TikTok and YouTube, and measure which clips bring listeners back to the full reading. **(R)** *Effort L.*

**Includes**
- Pick a moment by dragging across the review timeline, or start from a **suggested moment**: the replay hot spots and highest-held stretches from listener data (once 0.11.0 exists), or the most lively sentences by pitch and energy.
- Default length **about 30 seconds**, never over 60.
- Render in the browser to video: **square** for feeds, **vertical** for Stories and Reels, **16:9** for YouTube. Background from the post's featured image or the site palette, a moving waveform, the title, and **burned-in captions** timed from the take's word timings (spelled from the script, so no mishearings).
- Each clip gets its own **campaign link** to the full reading, so the dashboard shows which clip brought listeners and whether they stayed.
- Captions matter because many people scroll with the sound off.

**Finish line**
- A 30 second square clip renders in under a minute on a laptop and plays correctly on Instagram.
- Clicks from a clip's link show up under that clip in Sources.

---

## 0.14.0 Studio upgrades: microphone check, opening drill, practice focus

**Progress:** released as v0.14.0 on 13 November 2026 and live on the site. A microphone check and three 30 second opening drills with the fillers focus were run there, each compared with the one before.

**Goal:** three small tools that make each practice session count.

**Includes**
- **(R) Microphone check.** A 10 second test in the teleprompter: four seconds of quiet to measure the room, then six seconds reading the script's first line. It reports how far the room sits below your voice and whether your level is about right, with tips when the room is noisy. Everything stays in the browser; the last result shows beside the button. *Effort S.*
- **(R) Opening drill.** Read just the opening, over and over, for 20, 30, 45 or 60 seconds (30 by default). The teleprompter stops at the end of the sentence it is on when the time is up. Each drill is compared with the last drill only, and drills are kept out of the goals, charts and bests, which follow full readings. The Your progress screen has its own Opening drills section. The opening is where listeners decide to stay. *Effort S.*
- **(R) Practice focus.** Before a take, pick one habit to work on: pace, endings, fillers, pauses, pitch, energy or accuracy. The review leads with that one, compared with your last take on the same focus, shows its flags first and folds the other notes away. Speaking coaches recommend one or two focus points per session. *Effort S.*

**Finish line**
- The microphone check gives a sensible reading on the laptop used for recording.
- Two opening drills in a row are compared with each other, with the second one showing the change.
- A take with a focus opens its review on that focus, and the focus is kept with the take.

---

## 0.15.0 Studio upgrades: font switch, warm-up, cue marks

**Progress:** released as v0.15.0 on 13 November 2026 and live on the site. The first try there asked for a sign that the warm-up heard the reader, so a live level bar was added before the release.

**Goal:** three more small tools in the teleprompter, for comfort and control while reading.

**Includes**
- **Font switch.** A Font menu in the control bar: Serif (the reading font), Sans (the interface font, already loaded, so nothing new to download) or System (the computer's own sans serif). It changes the teleprompter, the review script and the warm-up, and is remembered in the browser. *Effort S.*
- **Warm-up.** A Warm up button opens a panel of about three minutes, one step at a time with Next and Back: a breathing circle (in for four seconds, out on a long "sss" for six, three rounds), humming and lip trills, four tongue twisters picked fresh each time (read slowly, then quicker, then quick), and a pacing passage that lights up at 110 words a minute, then 170, then your own pace. A level bar shows the microphone hears you; nothing is recorded or saved. *Effort S.*
- **Cue marks.** A Cues switch. While it is on, clicking a word gives Pause after, Breath after, Emphasis or Clear. Marks show in the script (a slash for a pause, a tick for a breath, a gold line under an emphasised word). In the timed scroll a pause holds for 0.7 seconds, a breath for 1.1, and an emphasised word gets a fifth more time. The marks are saved on the site with the post, so they follow you to any computer, and the post's text is never changed. If the post is edited, each mark finds its word again nearby, or is left off with a note. The review script shows the marks too, and Tidy never offers to cut a pause you marked. *Effort S.*

**Finish line**
- The script can be read in each of the three fonts, with the reading line still on the right word after a switch.
- A full warm-up runs from start to finish on the laptop used for recording.
- Cue marks set on one computer show on another, change the timed scroll, and survive a small edit to the post.

---

## 0.16.0 Better listening: learned words per post, larger Whisper model

**Progress:** released as v0.16.0 on 13 November 2026 and live on the site, with the larger model downloaded to the site. On the laptop used for recording, a 43 second opening drill took about 36 seconds to review with the standard model (5 s loading, 31 s listening) and about 1 minute 44 seconds with the larger one (7 s loading, 97 s listening); both read 100%, and the larger model made none of the mishearings the standard one made on earlier drills of the same opening.

**Goal:** fewer of Whisper's own mistakes in the review, so more of what it flags is real.

**Includes**
- **Learned words per post.** "I said it right" asks where to remember the word: in every post (the reader's own words, as before) or just this post (kept with the post, for a name or an invented word that belongs to one piece). The learned words list shows the two apart, each with Forget. *Effort S.*
- **Larger Whisper model.** A menu beside Whisper review: Standard (base, about 80 MB) or Larger (small, about 250 MB), remembered in the browser. Larger hears names, unusual words and quiet endings more accurately and takes about two to three times as long. It downloads from Hugging Face the first time, or from the site once an administrator downloads it there (Settings, a second button under Whisper). If it cannot load or run on a computer, the review uses the standard model instead and says so. Each take records which model reviewed it. *Effort M.*

**Finish line**
- A word taught for one post is accepted in that post's reviews and still flagged in other posts.
- The larger model downloads to the site, every file matches its fingerprint, and a real review runs with it on the laptop used for recording.

---

## 0.17.0 Share a take for feedback

**Progress:** released as v0.17.0 on 13 November 2026 and live on the site, after the local test with two Subscriber accounts, a stand-in microphone, a stand-in for Whisper and a stand-in mailbox. On the live site the share pages, Shared takes and the share button were checked; the finish lines (a friend's visit, the email, the refused folder) are checked when the first friend tries it.

**Goal:** other ears catch things yours do not. Let a trusted friend hear a take and leave comments pinned to moments.

**Decided so far**
- The friend signs in with a WordPress account on the site; there is no open link.
- A share lasts 14 days, or until the reader ends it. Then the audio is deleted from the site and the comments stay with the take.
- The friend gives a name with their comments, and no email.
- The reader gets an email when comments arrive.
- The friend hears the take with the script lighting up as it plays, and can comment on a word or a moment.
- The page is kept out of search engines and never cached, with a limit on comments per person. Privacy notes for friends' names and comments.
- Friends are people who already have an account on the site, usually added as Subscribers; the share screen lists them.
- **Share for feedback** sits in the review studio beside Make a clip, and **Foxfire Studio, Shared takes** lists every share with End now.
- One email per visit to the reader, when a friend presses Done (or an hour after their last comment).

**Finish line**
- A friend with a Subscriber account opens a shared take on a phone, hears it, and leaves comments pinned to words.
- The reader gets one email listing them, and sees them on the share's page.
- The audio cannot be opened from its folder directly, and is gone once the share ends.

---

## 0.18.0 The studio look

**Goal:** a teleprompter that feels like a studio: the script framed in the middle, controls grouped and out of the way, and the other Foxfire Studio screens a click away, with every feature working as before.

**Includes**
- A menu along the top with the brand and links to the other Foxfire Studio screens (each opens in a new tab, so a reading in progress is never left), and Settings for administrators.
- The post bar under it, with the post menu, the address box, Open a recording, and the reading's state: ready, reading, following your voice, recording, paused or finished.
- A fold-away **Controls** panel on the right, grouped into Reading, Recording and Practice, with switches and the value beside each slider. It fades while reading, like the old bar, and is remembered open or folded in each browser; on a phone it slides over the script.
- The script framed by gold lines, with an ornament under the title and sparks on the reading line.
- A dock along the bottom: where you are and the time left, Top, a larger Begin that shows play or pause, Finish recording, the recording timer and level, and level waves either side of Begin that move with the microphone while recording.
- **Your last take:** a drawer with this post's latest full take (pace, accuracy, fillers, long pauses, ending fade), each compared with the take before, and your goals met or missed. Drills are left out, as in Your progress.
- Two looks in Settings: Foxfire Studio (a night sky, a fox and gold ornaments) or the site's WordPress theme (no artwork, in colours picked from the theme's palette), and an optional short line in the corner of the dock.
- The review studio and every panel (the finished reading, warm-up, microphone check, cue menu, sharing) restyled to match.

**Finish line**
- Every control keeps its behaviour: the existing tests of reading, recording, the Whisper review, cues, warm-up, microphone check and sharing pass in the new layout, at computer and phone widths.
- Nothing new is stored on the server, and the only new browser setting is whether the panel is open.

---

(0.18.1 fixed the stutter left by a retake and added more ways to select words to edit.)

## 0.19.0 What works: insights

**Progress:** built and tested on a local site with 13 months of made-up data and on an empty one. The live site had one reading with listeners when it was built, so the listener observations wait there and turn on by themselves as readings and plays arrive; the finish line (20 readings with listener data) is checked then, and the minimums and wording are tuned against the real data as a patch. Reading observations from takes work from the first 5 full takes.

**Goal:** turn the data into observations that help plan the next reading.

**Includes**
- Comparisons across readings, once there are enough of them: completion against duration, against tempo, against topic or tag, against day and time published, against source.
- Comparisons that connect both halves: do takes with fewer slips or more pitch movement hold listeners longer? Do faded endings line up with drop-off?
- Each observation shows how much data it rests on, and stays quiet when there is too little. ("Readings under 4 minutes were finished 22% more often, based on 14 readings" is useful; the same claim from 3 readings is noise.)
- Suggestions in the studio based on your own data, for example "your listeners tend to drop off past 5 minutes; this script will run about 6."

**Finish line**
- At least 20 published readings with listener data.
- Every observation states its sample size and is never shown below a minimum.

---

## 0.20.0 Retake edge nudge

**Goal:** when a retake's join still sounds off (a word heard twice, or two words running together), fix it by ear instead of recording again.

**Includes**
- Clicking a retaken word opens its retake again, with **Start** and **End** controls that move each side of the splice 10 ms at a time (also the [ and ] keys, with Shift for the end).
- **Space before** and **Space after** add a pause at a join, 50 ms at a time up to 2 s, filled with the room's own background taken from a quiet stretch of the take nearby.
- Each step plays 1.5 s either side of that join, as it will sound.
- **Reset** puts the edges back where they were found and takes out any space; **Bring back the original** takes the retake out.
- Edges move at most 500 ms from where they were found, never into another edit or past the take's ends.

**Finish line**
- A nudged retake carries into Make edited MP3, the captions and the chapters, and undo steps back through kept nudges.
- Nothing new is stored on the server or in the browser.

---

## 0.21.0 Send to my computer

**Goal:** record on the phone, review on the computer, without moving files by hand.

**Includes**
- On a phone, after a take, **Send to my computer** beside Try it here anyway uploads the take's original recording, with how far the reading got and any drill and focus.
- It is kept in a folder visitors cannot open and reaches only the account that sent it.
- The computer's teleprompter offers it on any page, opens the post it was read from, and runs the full review; the review is saved as one take however often it is opened.
- Deleted from the site once used, downloaded or deleted on the computer, or after 14 days; listed under Foxfire Studio, Recordings; at most five waiting.

**Finish line**
- A take sent from a real iPhone opens and reviews on the computer, and is gone from the site once used.

---

## 0.22.0 The podcast feed

**Goal:** readings reach listeners who never visit the site, in the podcast app they already use.

**Includes**
- An RSS feed of every published post with a reading, newest first (up to 300), switched on in Foxfire Studio, Podcast feed.
- The show's title (the show name by default), description, square cover art, author, public owner email, Apple category and subcategory, clean or explicit, copyright, and the Podcasting 2.0 lock and permanent show id.
- Each episode: the MP3 with its size and length, a guid that never changes for the post, the excerpt, the "read by" credit, and the reading's captions as `podcast:transcript`.
- The screen checks the cover art (square JPEG or PNG, 1400 to 3000 pixels), the description and the email, counts the episodes and shows the feed's address and where to submit it.
- A "read by" credit on each post's Reading audio box (or another custom field), shown under the player's label and in the feed.

**Finish line**
- The live feed passes a podcast validator and is accepted by Apple Podcasts and Spotify.

---

## 0.23.0 An intro for the studio

**Goal:** the studio opens with its own short title sequence, which never gets in the way.

**Includes**
- An intro video chosen from the media library in Settings, Teleprompter look (the video stays on the site, out of the plugin).
- Played once in each new tab, with sound when the browser allows, else muted with Sound on.
- Skip (or Space, Enter, Escape); Skip it from now on, and Play the intro in the Controls panel, switch it off and on for that browser.
- Never played to someone who asks for less motion, or while a sent take is being opened; the studio's keys wait while it plays.

**Finish line**
- The intro plays on the site, and skipping it, or switching it off, goes straight to the studio.

---

## 0.24.0 A music bed

**Goal:** quiet music under a reading that dips while the voice speaks.

**Includes**
- Tracks from the media library, with credits, in Settings; one default for the show, and a choice (or none) on each post's Reading audio box.
- Music alone for a few seconds, a dip before the first word, low and steady under the voice (16, 20 or 24 dB), a rise after the last word and a fade; looped with a crossfade when shorter than the reading; optionally a small rise in long pauses.
- Mixed when the take leaves the studio (Download MP3, Use this recording, Listen with music), after the voice chain and before loudness, so it is mastered with the rest; a switch with each take leaves it out.
- Chapters and captions moved by the lead-in; the credit in the MP3 and the podcast feed.
- The intro's cover from the first paint (folded in from 0.23.0).

**Finish line**
- A reading with music goes on the site and sounds right on phones and in a podcast app, with the voice clear over the music.

---

## 0.25.0 Groundwork for 1.0

**Goal:** two of 1.0's finish lines, done early so everything after them is built the same way.

**Includes**
- Translation-ready text: every visible string through WordPress's translation functions, in PHP and in the scripts, with a `.pot` template in `languages/`.
- Automatic checks on every change: unit tests for the pure helpers, PHP checks in a test WordPress, a browser pass over every screen, a check of translated placeholders, and a test translation shown through every screen. GitHub Actions runs them on every pull request into main (since 17 November 2026; before that on every push too).

**Still open for 1.0:** tests for the comparison of a take with the script (inside `teleprompter.js`, so they come with the file split) and for the listener analytics rollups. Both done in 0.25.1.

**0.25.1 and 0.25.2: one file per area.** Nothing a reader sees changes. 0.25.1 moved the parts that hardly touch the page into files of their own, with tests: cleanup (`clean.js`), the read-out (`readout.js`), the comparison (`compare.js`) and the Whisper worker (`whisper.js`, now shared with the clip maker), plus checks of the listener rollups and a test that takes a whole recording through the studio. 0.25.2 moves the page areas, which share the studio's state: settings and the script on screen, voice following, cue marks, recording, the MP3 and music, the review studio and the editor, one file each, with `teleprompter.js` left as the short starter. **Done in 0.25.2:** `studio-core.js`, `record.js`, `cues.js`, `voice.js`, `master.js`, `review.js` and `editor.js`, sharing `window.RRTStudio`, and `teleprompter.js` down to 42 lines; the whole-take test now also records, recovers, downloads and edits a take.

---

## 0.26.0 Upgrade and uninstall checks

**Progress:** built on 14 November 2026. Both checks run in `tests/run.sh` and on GitHub: `tests/upgrade.py` (376 checks over nine releases) and `tests/wp/uninstall.sh`. They found the gaps listed in the changelog, now fixed, and goals in an older shape breaking Your progress.

**Goal:** two of 1.0's finish lines, checked automatically. Fixes these find ship in this version.

**Includes (agreed 14 November 2026)**
- `tests/upgrade.py`: a fresh test site with an older release installed and filled with made-up data (settings, takes, listens, cue marks, shared takes, a music bed, the podcast setup), upgraded to the build under test. Checks the database version, every table and column, that settings and data come through unchanged, that no screen shows a PHP error and that the studio starts. Covers each release that changed the database or the folder: 0.3.x, 0.9.0, 0.10.0, 0.11.0, the 0.11.1 rename, 0.12.0, 0.14.0, 0.17.0. The older zips are fetched from the development repository's releases while the test runs and are never committed; where they cannot be reached, the test skips.
- An uninstall check: the real `uninstall.php` on a copy of the plugin, once keeping data and once with "Also delete all reader stats and cue marks" ticked. Kept: nothing but the settings and asset copies go. Deleted: no `rrt_` option, table, post meta, user meta, scheduled job or upload folder is left.
- With data deleted, the self-hosted Whisper files in `uploads/rrt-whisper` go too (they stay when data is kept). The older reason for always keeping them, a second copy under the old name, no longer applies.

---

## 0.27.0 Docs pass and release candidate

**Progress:** built on 14 November 2026. Every document checked against the code, readme.txt in WordPress.org's shape (with Tested up to 7.1, a FAQ and the outside services), and the six split settings sentences joined. The checks pass on WordPress 6.0, 7.1.4 and the newest. 0.27.1 then ran on a live site until 15 November 2026, and every scheduled job was tested as if days and weeks had passed; 1.0.0 followed.

**Goal:** the documentation matches the code, and 1.0 has a candidate running on a real site.

**Includes**
- README, readme.txt in full WordPress.org shape (FAQ, Requires at least, Tested up to), ARCHITECTURE, CONTRIBUTING and PRIVACY.md, each checked against the code.
- The last settings sentences still in pieces for translators, joined up.
- Run on a live site for a few days before 1.0.0.

---

## More studio upgrades

Independent of everything else. Any of these can be done on its own, sooner if it becomes useful. Each gets a version number when it is picked up.

- (Cue marks, the warm-up and the font switch went into 0.15.0; learned words per post and the larger Whisper model into 0.16.0; sharing a take for feedback is 0.17.0.)
- (Pronunciation notes went into 1.1.0.)
- (One reading from several takes went into 1.3.0; reading a long post in parts, and a list of posts still to read, into 1.4.0.)
- (The plan after 1.4.0 lists the next milestones in order; small upgrades that fit none of its phases still go here until they are numbered.)

---

## 1.0.0 Stable

**Progress:** released as v1.0.0 on 15 November 2026. Every line of the finish line below is met.

**Finish line**
- All numbered milestones above done (More studio upgrades is an open list of ideas, each numbered when it is picked up).
- Upgrades from every 0.x version tested (done in 0.26.0: every release that changed the database or the folder).
- Uninstall removes tables and options cleanly (with an option to keep data) (done in 0.26.0, checked both ways).
- Translation-ready strings (done in 0.25.0).
- A test suite for the comparison, alignment and analytics math, run on every change (started in 0.25.0: alignment, loudness, edits, practice tools, tags, clips and insights; the comparison, cleanup, read-out, Whisper worker and analytics rollups added in 0.25.1).
- Documentation updated to match (done in 0.27.0).
- The automatic checks also run on the oldest WordPress and PHP the plugin claims, 6.0 and 7.4, on GitHub for every change (done 14 November 2026: a second job in the workflow runs `tests/run.sh oldest` on PHP 7.4 and WordPress 6.0 with the SQLite plugin's v2.1.3: PHP syntax, the PHP checks, the rollups, the browser smoke test and the debug log scan, all passing with the plugin unchanged).

---

## 1.1.0 Pronunciation notes and flags after edits

**Goal:** fewer names said wrong, and a review that keeps up with the edits. Agreed on 15 November 2026; built the same day.

**Includes**
- **Pronunciation notes.** A note such as "SOW-in" shown small above a word while reading, set from the cue menu, for one spot (Only here), every use of the word in the post (Just this post) or every use in any post (In every post, the default). Edit and remove any note. The review shows a word's note beside its flag and on hover, offers to teach Whisper every noted word it misheard at once, and offers to add a note after a word is taught.
- **Flags after edits.** A flagged moment a cut or retake covers is marked at once (Cut, Shortened, Retaken), the tiles count what is left, and the timeline dims it. Check the edited version has Whisper listen to the edited recording; its flags, tiles and timeline show with a switch between Edited version and As recorded. The take's own numbers in the history and goals do not change.

**Finish line**
- Both work on the site with a real reading: a note on a hard name in a post with several uses of it, and a take with a retake checked again.
- Unit tests for the notes and the flag cover, PHP checks for the notes' cleaning and route, and the whole-take test through both.

---

## Listener reactions

Held the number 1.1.0 until 15 November 2026, when pronunciation notes and flags after edits were built first. It gets the next free number when it is built.

**Goal:** listeners mark the moments that move them, lightly.

**Includes (to be agreed before building)**
- A small reaction at a moment in the player, counted on the reading's page and in insights.
- Needs a line in the site's privacy policy, so it waits until that page is public.

---

## 1.2.0 Wider listening numbers

**Goal:** answer the listening questions the dashboard cannot answer yet, keeping its privacy promise: measured on the site, no cookies, nothing that follows a person from one day to the next. Agreed in outline on 14 November 2026; the details are agreed before building.

**Includes**
- **Read or listen.** The player already records how far each visitor scrolls and how long the page stays open (`scroll_max`, `time_on_page_s`), but no screen uses them. Shown beside plays, they say whether visitors listen instead of reading, read and listen, or read without pressing play. Data already collected, so this can come first. Effort: S.
- **Time to press play.** Also collected and unused (`first_play_s`, a daily median): how long visitors take to press play, which shows whether the player is placed where people notice it. Effort: S.
- **New and returning listeners.** The listener id changes every day by design, so returning listeners cannot be told apart today. The player would send one yes or no, "has listened on this site before", worked out from its own browser storage; no id is kept or sent. Needs a line in the privacy notes and the site's policy. Effort: S.
- **Downloads by podcast apps.** Apps fetch the MP3 straight from the site, so feed listening is invisible today. Each episode's file in the feed would go through a short address on the site that counts the download and passes it on, counted the way the podcast industry's measurement guidelines describe (one listener and episode per day, partial and repeated requests merged, known bots left out), with the app named from its user agent. No IP address stored, nothing sent elsewhere. Only useful once the feed is switched on. Effort: M.
- **An optional link to Google Analytics.** Off by default. When switched on and the site already loads Google Analytics, the player also sends play, halfway and finished events to it, with no Google Cloud project or API key. The Settings help says plainly that the numbers will differ from Foxfire Studio's (cookies, ad blockers, Do Not Track) and that the site's privacy policy should mention it. Effort: S.

**Details agreed on 15 November 2026:**
- **Read** means the visitor scrolled at least 75% of the post text and the page was on screen for at least a third of the time an average silent reader needs for its words (238 words a minute). A **real listen** means at least 30 seconds heard, or the whole reading when it is shorter. Each visit (one listener, reading and day) falls in one of four groups: listened only, read and listened, read only, passed through. The four counts are kept in the daily summaries, so they outlast the visits, and are filled in on upgrade for every visit still kept.
- **Time to press play** is shown as a median and four bands (under 10 s, 10 to 30 s, 30 s to 2 min, over 2 min), split into phones and tablets against computers. The daily summaries keep a small histogram for each, because a median of daily medians is wrong.
- **Returning listeners:** after a real listen the player keeps the month in that browser's own storage; on a later visit it sends only yes or no. The window is a choice of 1, 3, 6 or 12 months (12 by default, matching how long visits are kept). Visitors left out of the measuring today are left out of this too.
- **Downloads by podcast apps:** the short address is `/podcast-file/<post id>/<file name>`, answered with a redirect that is never cached. It keeps working for good once used, even if counting is switched off later, so episodes already in apps never break. A download counts once per listener, episode and day (a daily-salted hash, as for visits), only on a request for at least a minute's worth of audio from the start, so byte probes do not count.
- **Google Analytics:** `audio_start`, `audio_progress` (at 50%) and `audio_complete`, with the reading's title, and the same exclusions as Foxfire Studio's own measuring (Do Not Track and Global Privacy Control, editors).

**Every new option is off by default** (agreed 14 November 2026). Nothing new is collected on a site until its owner switches it on: the returning-listener flag, the download counting and the Google Analytics link each have their own switch, so a site's privacy policy stays true until it changes a setting. Read or listen and time to press play only show what the player already measures.

**Left out on purpose:** anything that follows a person across days, demographics, and heatmaps; they would break the privacy promise and add little beside the listening curve. Pulling Google Analytics reports into the dashboard through Google's API would mostly repeat what Site Kit already shows.

**Finish line**
- Each new number shows on the dashboard with a plain definition under it, and in the report email where it helps.
- PRIVACY.md, the plugin's privacy paragraph and readme.txt's External services updated for the Google Analytics option and the returning-listener flag.
- The automatic checks cover the download counting (deduplication, bots, partial requests) and the new rollups.

---

## 1.3.0 One reading from several takes

**Goal:** after two or more takes of a post, build one reading from the best parts of each (for example the second take's opening and the third take's ending), without recording a retake for every slip. Agreed on 14 November 2026 in outline and on 15 November in detail; built the same day.

**Includes**
- **A take shelf for each post, in the reader's browser.** Every take that has had its Whisper review goes on it by itself, with its recording (as Whisper heard it, before cleanup) and Whisper's words: takes recorded in the studio, opened with Open a recording, sent from a phone or recovered. Partial takes count. Opening drills and combined readings do not. The newest six takes a post, each kept 30 days; the same take opened twice is kept once. Nothing goes to the site.
- **Combine takes**, in the review studio's header and beside Open a recording, once a post has two takes on its shelf. A take for each paragraph, or for each sentence of a paragraph the reader splits. Each choice shows that take's flags there, with the details on hover, and plays that take's reading. A take only offers the pieces it read.
- **Best of each:** the take with the fewest flags for each piece, flags weighed by kind, each join counting as a little less than one flag, so takes switch only for a clear gain. It is where the screen starts.
- **Joins** go in the pause before a sentence: the earlier take stops just after its voice, and the later take starts in its own pause, so the pause is the one the later take made there. A take that starts right at the word gets the room's background for the rest. Takes are leveled to the one that reads the most. Each join can be heard, and its End, Start and Space moved as a retake's are.
- **Build the reading** puts it together from the recordings and opens it like any recording: cleanup, the MP3 and the read-out run once over the whole of it, and the review uses the words Whisper heard in each take, moved to their new places, so Whisper does not listen again. Edit audio, retakes, Check the edited version, captions, chapters, clips, sharing, the music bed and Use this recording work on it as on any take.
- **The take history stays honest:** the takes keep their own numbers, and the combined reading is not added to the history, goals or charts.
- **The plan is kept** with the shelf, so Combine takes opens with the last choices. After a combined reading is put on the post, the studio offers to clear the shelf.

**Finish line**
- Unit tests for the pieces, coverage, flag counts, best of each, the joins (pause, room tone, nudges, no click), leveling and the words' new times.
- A browser test with three made-up takes, one partial: the shelf, best of each, a paragraph by sentence, a join's space, the plan after a reload, the built reading's review, the take history and clearing the shelf.
- README, readme.txt, PRIVACY.md and the docs updated.

---

## 1.4.0 The backlog: readings to make, and a long post read in parts

**Goal:** more readings made, with less effort for each: a list of the posts still to read, and a way to read a long post over several sittings. Agreed on 15 November 2026; built, tried on a real site (a long post read in four takes and combined) and released the same day.

**Includes**
- **Readings to make**, a Foxfire Studio screen after Readings: every published post with no reading, newest first, sortable, with its words, how long it would run at the reader's own pace (the median of their latest five full takes, or the default pace until there are some), How hard, and their takes of it so far. **Start** opens the studio on the post; **Hide** takes it off the list, and Show hidden brings it back.
- **How hard:** Easy, Some care or Demanding, per 100 words. Words that tripped the reader or Whisper before (pronunciation notes and taught words, for every post or that post) count most, with long sentences (over 30 words); names and words of five syllables or more count less. The reasons on hover.
- **Continue** in the studio once a post's shelf has a take: a new take starting one sentence before the first paragraph no take has read, as a run-up. The review says how much is left after each take.
- **A coverage bar** on the Combine takes screen: which take reads each part, and what no take reads yet.
- Nothing new from visitors; the hidden posts are the only thing stored.

**Finish line**
- Unit tests for the coverage and where Continue starts; PHP checks for the measuring, the list and hiding; the browser test reads a post in two parts with Continue and builds it.
- On the site: the list shows the posts without a reading with sensible estimates, and a long post read in two sittings combines into one reading.

## 1.5.0 Prompter view for glass rigs

**Goal:** read through a glass teleprompter rig, or from a screen across the room. The first of Phase 1's small milestones, agreed on 15 November 2026. Finishing presets (1.6.0), clip suggestions (1.7.0) and the full Podcasting 2.0 feed follow as their own versions, in that order (the feed's soundbites come from clip suggestions).

**Includes**
- A full-screen prompter view with only the script and a thin strip (recording time, time left, messages); the dock appears when the mouse moves.
- Mirror: none, left to right, upside down or both. Everything the reader sees is mirrored; what only the operator reads is not.
- Its own size, width and reading line, plus Shift (over the lens), Fade above and below, and bright text on black.
- Keys for presentation clickers and foot pedals: Page Down, Page Up (also in the normal view), B or the full stop to blank, plus and minus, F5 caught, Escape.
- Nothing new is stored on the site or collected from visitors.

**Finish line**
- Unit tests for the stored settings, the mirror, the fade and the keys; a browser test opens, mirrors, resizes, blanks and leaves the view by key and button.
- On the site: the script reads the right way round in a mirror, and the keys work from the laptop's keyboard. (No rig, clicker or pedal was at hand for the first check.)

## 1.6.0 Finishing presets

**Goal:** a finished reading that is right for wherever it is going, measured as it is delivered. The second of Phase 1's small milestones, agreed on 15 November 2026.

**Includes**
- One **Finish for** menu in Settings: Podcast, mono (-19 LUFS, -1 dBTP, the default, as before); Podcast, stereo (-16, -1); Spotify and YouTube (-14, -1, stereo); Broadcast, EBU R128 (-23, -1, a 48 kHz 24-bit WAV); Audiobook (ACX: RMS -18 to -23, -3 dB peaks, 192 kbps, room tone); Custom (-24 to -12 LUFS, a true peak limit of -3 to -0.5 dBTP, mono or stereo); and the simple leveling.
- A **true peak limit** filled in by each preset; MP3s are still aimed a little lower for the encoder's overshoot.
- The **loudness range** (EBU Tech 3342) after every finish, in the studio check, with a note over 10 LU. Not stored.
- A **Finish for** menu in the review beside Download, for one-off versions. Use this recording always uses the Settings choice.
- **Clips at -14 LUFS** automatically.
- Upgrade: podcasts at -19 become Podcast, mono; any other saved loudness becomes Custom with the same number. No database change, and nothing new from visitors.

**Finish line**
- Unit tests for stereo loudness, the loudness range, each preset's plan and level, the WAV writer and the encoder in stereo; PHP checks for the presets and the upgrade; a browser test of the Settings menu, a stereo take, a one-off WAV and a WAV put on the post; the upgrade test from 1.5.0.
- On the site: a real reading finished as Podcast, mono sounds and measures as before, and a one-off Spotify and YouTube version measures -14 LUFS.

## 1.7.0 Clip suggestions

**Goal:** the moments of a reading worth a clip, found from what the site already knows, ready to make in one click and to give the podcast feed its soundbite. The third of Phase 1's small milestones, agreed on 15 November 2026.

**Includes**
- Worked out on the site from each reading's sentence-timed captions and its listening curve: stretches of 30 to 60 seconds that start at a sentence start and end at a sentence end. Paragraph edges are preferred, and so is a length of 30 to 45 seconds. A start on a word that points back ("And", "But", "This", "It", "They" and the like) and long pauses count against a stretch. With 10 or more plays, the stretches listeners replay and stay through count for more.
- The opening plus the best three, overlapping by at most a quarter.
- On a reading's page, **Suggested clips**: the time, the first and last words, the reasons, ▶, **Make this clip** with its exact length, and **Use for the feed**.
- In the clip maker's suggestion row, the same suggestions; its length menu gains the exact length. After a take, the clip maker's existing suggestions snap to sentence edges from the review's words.
- No captions: the clip maker's suggestions from before only.
- **Use for the feed** keeps the pick for each post; a new reading on the post clears it. The full Podcasting 2.0 feed (1.10.0; numbered 1.8.0, then 1.9.0, as other milestones came first) uses the pick, else the top suggestion.
- No database change, and nothing new from visitors.

**Finish line**
- Unit tests for sentences and sentence edges in the clip maker; PHP checks for the sentences, the scoring, the choice and the pick; a browser test of the reading page, the clip maker, a clip made at a suggestion's exact length at -14 LUFS from a finished and a quiet reading, and Use for the feed; the uninstall and upgrade checks.
- On the site: a reading with captions shows sensible suggestions, and a clip made from one starts and ends cleanly.

## 1.8.0 Keep a reading aside

**Goal:** take a reading off its post without losing it, and put it back later as the same episode. Asked for by the maintainer on 16 November 2026, between the third and fourth of Phase 1's small milestones; the full Podcasting 2.0 feed moved on (now 1.10.0, after Phone level).

**Includes**
- **Take off the post** on a reading's page (and a link in the post's Reading audio box): the file and captions move out of the Media Library into a folder visitors cannot open. The post loses its player and the feed loses the episode; the post is back on Readings to make, marked. The listening history stays.
- **Kept aside** on Recordings: a private player, Download, Put back and Delete for good, with no time limit.
- **Put back**: the same address, captions, music bed credit and pick for the feed, and the same Recordings row current again; a newer reading on the post is kept aside in its place.
- A reading from a theme's own field: moved the same way when it is in the Media Library; otherwise only the field is cleared and remembered.
- No database change, and nothing new from visitors.

**Finish line**
- PHP checks for take off, put back, swaps, the extra field, files outside the Media Library or shared, and deleting; a browser test of the whole flow with its questions and the private player; the uninstall and upgrade checks.
- On the site: a reading taken off and put back plays at the same address, and the post's numbers carry on.

## 1.9.0 Phone level

**Goal:** a reading recorded on a phone reaches its loudness as cleanly as one from a laptop. Agreed with the maintainer on 16 November 2026 after the research below; the laptop path is unchanged.

**Research first (done):** on a phone in a quiet room, the same passage three ways, captured raw. With the browser's processing off (as the studio records): -53.6 LUFS, the room about 39 dB under the voice, the full frequency range. Automatic gain alone: -52.1 LUFS, so it does almost nothing on that phone. Echo cancellation, noise suppression and automatic gain together: -25.1 LUFS, but nothing above about 11 kHz and the pauses gated to digital silence, which would break room tone, cleanup and noise reduction. So the browser's processing stays off, and the answer is in the lift.

**Includes**
- **A lift limit that follows the room:** up to 45 dB when the room, measured after cleanup and noise reduction, would still sit at -60 dBFS or below for a -19 LUFS voice; 30 dB as before in a noisy room. The read-out says which limit held a take back, or that a quiet room allowed more.
- **Check microphone reports the lift a take will need,** whether the room allows it, and how quiet the take would come out if not, with a suggestion to move closer; the control bar line warns until the next check.
- **Phone takes in 24 bits,** in the browser, in Original, when recovered and when sent to the computer, so a large lift stays clean. Computers keep 16 bits.
- **The trim of a take's ends follows its own level,** found on the first real phone reading: a fixed -38 dBFS threshold had cut it after its last louder word.
- No database change, and nothing new from visitors.

**Finish line**
- Unit tests with a phone-level and a laptop-level fixture, a noisy room and a music bed; a browser test with a stand-in microphone at a phone's level on an Android phone, an iPhone sending its take, and a computer; the upgrade check.
- On the site: a take on the phone reaches about -19 LUFS, and a laptop take sounds as before.

## 1.10.0 The full Podcasting 2.0 feed

**Goal:** the podcast feed carries everything the Podcasting 2.0 apps can show, from what the site already has. Designed on 15 November 2026 for 1.8.0, and its open details agreed with the maintainer on 16 November 2026.

**Includes**
- **Chapters:** read on the site from each MP3's own chapter tags (the ones the studio writes at the post's headings) and served as a chapters file at `/podcast-chapters/<post id>.json` while the feed is published. Worked out once and kept until the reading, its captions or the post change. A WAV reading has no tags, so no chapters.
- **A soundbite** for each episode: the pick from Use for the feed, else the top suggested clip, else the opening, titled with its first words.
- **The host** (`podcast:person`): a name (the author by default), a link (the site by default) and an optional square picture.
- **A support link** (`podcast:funding`), such as a donation page, with the words apps show beside it ("Support" and the show's title by default).
- `podcast:medium` (podcast), an optional `podcast:location`, optional `podcast:txt` lines for directories' ownership codes, and the transcript's language.
- **Pings,** off until switched on and only while the feed is published: when a published post gets a reading, the feed's address alone goes to Podcast Index and a WebSub hub (Google's by default, with the hub named in the feed), at most once every 10 minutes. The privacy paragraph and PRIVACY.md gain a line.
- The Podcast feed screen lists each episode's chapters, transcript and soundbite, and checks that links are https and the host picture square.
- No database change.

**Finish line**
- Server checks for the chapter reader, the chapters file, the kept facts, the soundbite and the pings (to a stand-in); a browser test of the screen, the feed and the chapters file; the uninstall checks.
- On the site: the new fields set, and the feed checked in a validator when it is published.

## 1.11.0 Review for verse and pace

**Goal:** a review that knows a poem or a chant from an essay, so a line meant to trail off or a slow ritual pace is not marked as a fault, and the reader can see where every pause fell. Designed and its open details agreed with the maintainer on 16 November 2026.

**Includes**
- **Kind of reading** for each post: Prose (140 to 160 wpm), Story (130 to 150), Verse (110 to 135) or Ritual and chant (95 to 120), the ranges set in Settings, Reading. Chosen in the teleprompter's Practice section or the post's Reading audio box and saved with the post. For a post of mostly short lines the studio suggests Verse, with a button to set it; it never sets a kind by itself. A post with no kind behaves as before.
- **Pace:** with a kind, the prompter starts in the middle of its range. Moving the slider on that post is kept for that post in that browser, and the pace for other posts stays. The review's Pace line judges the take against the range (inside, just under or over, well under or over) and shows in How it sounded. The Rushed and Slow edges move with the range, a quarter beyond it (for prose, the 200 and 105 used before), and a sentence is still compared with the reader's own pace too.
- **Intended fades:** a new cue, Fade meant, a tilde after the word: a sentence with one is never flagged Faded ending. In Verse and Ritual and chant, only a fade deeper than 14 dB is flagged (8 dB otherwise), so a trail passes and a swallowed ending is still caught.
- **A pause map:** every gap of a second or more from Whisper's word times, by where it falls (between sentences, at a paragraph or stanza break, inside a sentence, or where a pause was cued), with a summary in the review, thin ticks on the timeline, and the length of each in the script with Show pauses on (remembered in the browser). A cued pause or breath the reading ran straight past (under 0.3 s) is noted gently and not counted as a flag.
- No database change; the kind is post meta `_rrt_kind`, kept on uninstall unless the delete box is ticked, like cue marks. Nothing new about visitors.

**Finish line**
- Unit tests for the kinds, the fade threshold and cue, the Rushed edge, the pause map and the missed cue; server checks for the kinds, ranges, the guess and the Reading audio box; a browser test of the menu, pace, saving and the Fade meant cue; the pause map in the whole-take test.
- On the site: a verse post and a ritual post reviewed with their kinds set, and the circle-casting lines of a ritual reading no longer flagged for a trail.

**1.11.1:** the first and last words of a reading, when Whisper runs them together ("Hex the" heard as "Hexa"), are paired with the script, so they are no longer left unread or out of the captions.

## 1.12.0 Phone take mode

**Goal:** a take recorded on a phone is safe on the site as it is made, and waits there for the review on the computer, so a call, a reload, a full phone or a closed page loses no more than a few seconds. Designed and its open details agreed with the maintainer on 17 November 2026.

**Includes**
- **Sent in pieces while recording:** on a phone, with the switch on, a take is started on the site as recording begins and sent in pieces of about ten seconds (about 1.4 MB of 24-bit audio), each saying where it belongs, so a piece sent twice is kept once and one that arrives early is refused with how much the site holds. The file on the site is a WAV from the start, its sizes brought up to date after each piece, so a take cut off partway is still a recording the computer can open. A piece that cannot go up waits on the phone and is tried again (after 2, 5, 10, 20, then every 30 seconds, and at once when the connection returns); the take is also kept in the browser every second as before, until the site has all of it. When the page is hidden for a call or another app, what is ready goes at once.
- **The next visit** sends whatever a take that was cut off is missing, from the browser's copy, then finishes it on the site as cut off.
- **After Stop:** the take is finished on the site at once. The phone says where it is and until when, and offers Listen back (the original, quiet until leveled) and **Make the MP3 here**; the review then points to the computer and offers no Send. On the computer the take is offered like a sent take (one still arriving is left until it is finished or cut off), and a cut-off one says so.
- **The large meter:** while recording, a wide bar across the bottom of the screen with a peak mark and a gold band where the voice should sit, a word under it (Good; A little quiet: hold the phone closer, within 4 dB of the most the studio can lift in this room; Too quiet: move closer, over it; Too loud: move back a little, at -1 dBFS or above), worked out as Check microphone does, and a line saying how much is on the site and how many MB have been sent, or that the phone is waiting for the connection.
- **Carry on:** opening a post on the phone whose last phone take was cut off offers a new take from the start of the sentence before the one the prompter was in. The two arrive on the computer, where Combine takes joins them. Offered once per take.
- **The switch:** Controls, Recording, Phone take mode, on by default on phones and tablets, remembered in the browser. Opening drills, and takes the browser can only record with its own recorder, stay on the phone as before.
- It shares Send to my computer's folder, list, 14 days and five waiting takes; over five, the take stays on the phone and the line says why. No database change, nothing new from visitors; PRIVACY.md has a section.

**Finish line**
- Unit tests for the pieces, what is left after an interruption, where Carry on starts, the state of a take, and the meter at a phone's and a laptop's level, a voice that drops partway, and a clipped one; server checks for pieces in order, twice, early, overlapping and too large, the WAV's sizes, finishing and cutting off, someone else's take, the limit and empty takes; a browser test of a whole iPhone take (the site's copy byte for byte the same as Original), one whose pieces stop and whose page is reloaded mid-take, Carry on, and the cut take reviewed on a computer.
- On the site: a full phone take arrives on the computer without Send, and a take cut off by closing the page can be carried on and combined.

## 1.13.0 Voice polish

**Goal:** the Broadcast sound steps can be heard and tuned, and a reading is evened out and cleaned where real readings showed it was needed. Designed with the maintainer on 17 November 2026.

**Includes**
- **Strengths:** Off, Gentle, Standard or Strong for pops, S sounds, breaths and compression, in Settings, Broadcast sound. Standard is exactly the sound before, so nothing changes until a setting moves.
- **Hear the polish** in the review studio: pick a sentence; Before and After switch while it loops; a one-off strength, EQ, click lowering and slow leveling for this take only, used with Use for this take.
- **Slow leveling,** on by default: a level that drifts over minutes is evened out, by at most 4 dB in prose and story and 2 dB in verse and ritual. The Energy line keeps measuring the take before it.
- **The live energy note:** "Your voice has dropped a little since the start", at 4 dB or more below the first minute, at most once a minute, under the large meter on the phone and in the control bar on the computer; never in an opening drill or on a Ritual and chant post.
- **EQ per setup** (the device and its microphone), suggested from the first three takes on it, a few broad bands, 3 dB at most; saved on the site, so a phone take reviewed on the computer gets the phone's EQ. Off by default; try it in the preview.
- **Clicks between words,** found from Whisper's word times, under 15 ms, lowered and never cut. Off by default.
- New takes, Use this recording, clips and Share for feedback are polished; the Original stays as recorded, and readings already on posts stay until remade.
- No database change; the setups are user meta `_rrt_eq`. Nothing new from visitors; PRIVACY.md has a section.

**Finish line**
- Unit tests for Standard against the sound before, each strength, slow leveling, clicks, the bands and suggestion, the preview, setups, gaps and the energy note; server checks for the strengths and the setups' route; a browser test of Settings, clicks after the review, Hear the polish, Use for this take and a setup's EQ.
- On the site: a long reading with slow leveling, Hear the polish on a phone take, and the phone's EQ suggested after three takes.

## 1.14.0 Voice following

**Goal:** the prompter scrolls with the reader's voice, using a small speech model on the reader's own device, so no audio leaves the browser. Researched first (docs/RESEARCH.md, Voice following) and designed with the maintainer on 17 November 2026.

**Includes**
- **Follow my voice** (Controls, Reading) now runs a small streaming speech model with the sherpa-onnx engine in a Web Worker, in place of the browser's speech recognition. The word being read stays on the reading line; a pause stops the scroll; an ad-lib holds the place; a skip ahead or going back is followed once about five words line up; Page Up, Page Down and a click on a word still move it by hand.
- **The model** (about 45 MB) loads from the site when downloaded in Settings (Voice following on this site), otherwise from Hugging Face, pinned to one revision and fingerprinted. **The engine** ships with the plugin, from the official release, fingerprinted too.
- **One microphone:** while a take is recorded the follower hears the recorder's copy; otherwise it opens the microphone itself.
- **Fallback:** if the engine or model cannot load, or the device falls more than 1.5 s behind for 3 s, the timed pace takes over from the current word with a line saying why.
- **The pace while following:** words per minute over the last 30 s, against the post's kind of reading when it has one, in the control bar and under the large meter on the phone. Not in opening drills.
- **The first look:** the moment a voiced take stops, the result shows its length, pace, any paragraph never heard and how well the follower kept up, until the Whisper review is ready.
- On phones too, in phone take mode, with the fallback as the safety net.
- No database change. Nothing new from visitors; PRIVACY.md's Follow my voice section is rewritten.

**Finish line**
- Unit tests for the follower on a reading heard with slips, ad-libs, a skip, going back and a hand jump, the pace and the first look; server checks for the model's files, fingerprints, where the studio loads them from and the shipped engine; a browser test with a stand-in listener for following, holding, skipping, one microphone, the pace, the first look and both fallbacks.
- On the site: a real reading followed on the laptop, and one on the phone in phone take mode.

## 1.15.0 The pronunciation list

**Goal:** one screen for every pronunciation note and every word taught to Whisper, wherever it is kept, so they can be found, changed, moved, backed up and added before a reading. Designed with the maintainer on 17 November 2026.

**Includes**
- **Foxfire Studio, Pronunciation:** one row per word per place (every post, one post, or one word only), with the note, what Whisper hears it as, where it is kept and how many posts have it; search, filters for kind, place and post, and sorting.
- **Editing:** a note changed in place, Add a note without the studio, Move to (every post, one post, every use in the post) asking which note to keep when both places have one, and Forget for taught words.
- **Words that may need a note:** names and long words in the published posts with no note yet, posts still to read first, with Add a note and Not needed.
- **Export CSV, Export backup and Import** with a preview; import adds, and replaces a note only where the reader chooses.
- **All notes** in the studio's note menu.
- **Fixes found while designing it:** a forgotten taught word no longer comes back from another browser's copy (taught and forgotten times, merged the same way in the browser and on the site), and an open studio tab sends only the notes and cue marks it changed.
- No database change. Nothing new from visitors.

**Finish line**
- Unit tests for merging taught words and the change lists, and the screen's filter, sort and CSV; server checks for every change, clash, permission, the backup and import; a browser test of the screen with a studio tab opened beforehand.
- On the site: the screen with the real notes and taught words, one note changed and one word moved, and a backup exported.

## 1.16.0 The stronger cleanup

**Goal:** a second noise reduction engine for rooms RNNoise cannot quiet, kept on the reader's device. Researched first (docs/RESEARCH.md) and designed with the maintainer on 17 November 2026.

**Includes**
- **Engine** beside the amount in Settings, Broadcast sound, Background noise reduction: Standard (RNNoise) or Stronger (DeepFilterNet 3). Standard stays the default.
- **The model shipped with the plugin** (8.6 MB) and ONNX Runtime Web's loader; the runtime shared with the Whisper review. Every file checked against its fingerprint before use.
- **Lift before the model,** chunks of 10 s, and a fallback to Standard when it cannot load or the device is too slow, said on the Room line.
- **A suggestion on the Room line** on Standard, for a noisy room or steady tones in the pauses.
- **Hear the polish: Cleanup,** Before with this take's engine and After with the other, and Use for this take with it.
- No database change. Nothing new from visitors.

**Finish line**
- Unit tests of the engine with a stand-in model (transform, chunk seams, caps, lift, speed limit) and of the tone count and Room line; server checks of the files and setting; a browser test with the real model on a stand-in voice over a bell.
- On the site: real noisy takes cleaned with Stronger and compared in Hear the polish.

## 1.17.0 The faster review

**Goal:** a full review with the larger Whisper model in well under the reading's length, on the reader's own computer. Researched first (docs/RESEARCH.md) and designed with the maintainer on 18 November 2026. The last milestone of Phase 1.

**Includes**
- **The larger model on the graphics chip** (WebGPU with half precision): the half-precision encoder and the 4-bit half-precision decoder, about 5 to 8 minutes for a 14-minute reading on a laptop.
- **Automatic,** the Model menu's new default: the larger model on the graphics chip where it can run, the standard model elsewhere and for opening drills. Larger also uses the graphics chip.
- **A fallback to the processor** when the graphics chip fails, said in the result.
- **The status line** says when Whisper is listening on the graphics chip.
- **The two files** (about 322 MB) in the larger model's Download, pinned; the Download fetches only missing files.
- **A Settings note** for laptops with two graphics chips.
- No database change. Nothing new from visitors.

**Finish line**
- Unit tests of the worker's graphics chip loading; server checks of the files and boot data; a browser test with a stand-in graphics chip and Whisper, with the fallbacks.
- On the site: a real reading reviewed on the laptop's graphics chip.

## 1.18.0 Full-length video

**Goal:** the whole reading as a video for YouTube, Spotify and other apps that show video, made on the reader's own computer. Researched first (docs/RESEARCH.md) and designed with the maintainer on 18 November 2026. The first milestone of Phase 2.

**Includes**
- **A Full-length video section** on each reading's page: the picture (featured image, the show's cover art, the look's colours, or one chosen), captions on the picture, Standard (5 Mbps) or Smaller file (2.5 Mbps), the size before it is made, progress and Cancel.
- **The video:** 1920 by 1080 H.264 at 30 frames a second with a keyframe every second, AAC stereo; the clip maker's picture with the chapter being read and the captions a phrase at a time; written into the browser's private storage as it is made, then downloaded.
- **The upload kit:** a description for YouTube or Spotify with the tagged link and YouTube chapter times, the captions file and a 1280 by 720 thumbnail.
- No database change. Nothing new from visitors, nothing kept on the site.

**Left for later:** the video in the podcast feed (`podcast:alternateEnclosure` with an HLS playlist, and keeping the video on the site), once a site's feed is published and can be checked in a real app; vertical and square full-length videos.

**Finish line**
- Unit tests of the captions, chapters, description and sizes; server checks of the section and its boot; a browser test that makes a video and checks its tracks, length, frame rate, keyframes and picture, Cancel and the private storage.
- On the site: a real reading made into a video on the laptop and played through.

## 1.19.0 Collections

**Goal:** several readings joined into one MP3 with a chapter for each (a series, a season, a themed set), with its own page, player and feed episode. Designed with the maintainer on 18 November 2026, after a check that the plugin's own MP3s join frame by frame with every reading unchanged. The second milestone of Phase 2.

**Includes**
- **Foxfire Studio, Collections:** a post type of its own, edited on the usual screen with a Readings in this collection box (search, add, reorder, remove, the gap, In the podcast feed, the episode type).
- **The join on the site:** each reading's frames copied exactly with silence between, chapters for each reading and its headings, captions from each reading's, a tag with the collection's title and artwork; joined again by itself when a reading changes, keeping what it has when one goes missing.
- **Matching in the browser:** each reading's loudness measured once; a reading in another format, or more than 1 LU from the rest, made again for the collection only.
- **The page and the feed:** the player, the list of readings with ▶, and a Bonus (or Full) episode with its chapters file and transcript.
- No database change. Nothing new from visitors.

**Left for later:** a full-length video of a collection; which readings inside a collection hold listeners; a spoken introduction; season numbers in the feed.

**Finish line**
- Unit tests of the box's helpers and the stereo leveling; server checks of the frame walk, silence, tag, chapters, captions, the join byte for byte, matching, levels, rebuilding, the routes, the page and the feed; a browser test that makes a collection from three readings, matches two, plays it and checks its chapters, gaps and loudness; uninstall checks.
- On the site: a real collection made from two readings, played across the join, its chapters and page checked.

## 1.20.0 Audiobook export

**Goal:** a collection made into the files an audiobook store asks for, to ACX's rules (Audible), which Spotify for Authors, Authors Republic and Findaway also accept. Designed with the maintainer on 18 November 2026, after ACX's rules were checked and two real readings were measured (docs/RESEARCH.md, Audiobook export). The third milestone of Phase 2.

**Includes**
- **Audiobook copies:** a take finished once more with the Audiobook (ACX) preset, voice only, 192 kbps mono, with room tone at both ends, kept on the site in a folder visitors cannot open. **Keep an audiobook copy** (Settings, Broadcast sound, off by default) makes one with every Use this recording; the review's Finish for menu has **Keep as this post's audiobook chapter** for a reading already on its post.
- **Room tone from the reading's own pauses** when a take or a post's MP3 has less than 1.2 s before the first word or 3 s after the last.
- **The Audiobook box** on a collection: where each chapter comes from (the copy, the post's MP3 when it has no music bed, or a note that it needs a copy), the opening and closing credits recorded in the studio from a script the box writes, a retail sample of one to five minutes cut at pauses, the cover's size, and **Make the audiobook**: every file made and checked in the browser and downloaded as one ZIP with a check report.
- No database change. Nothing new from visitors.

**Left for later:** a Kindle or EPUB edition of a collection's text (ACX takes audio only for a book already on Amazon); WAV or FLAC export; a chapter over 120 minutes split at a pause with a second heading; uploading straight to a store.

**Finish line**
- Unit tests of the room tone, the leveling, the sample's stretch, the heading check, file names, the report's rules and the ZIP; server checks of the copies, the sources, the credits, the routes and their permissions, and clearing up; a browser test that records credits and an audiobook copy in the studio and makes a ZIP whose every MP3 is checked; uninstall checks.
- On the site: a real collection exported, with one chapter from a post's MP3 and one from a take, and its files checked.

## The plan after 1.4.0

Agreed on 15 November 2026, after comparing the plugin with the main podcast, teleprompter and audio tools (Descript, Riverside, Adobe Podcast, Audacity, PromptSmart, Yoodli, Auphonic, Buzzsprout and Spotify for Creators). The phases are the suggested order. Each milestone gets a version number when it is built, and its design is agreed with the maintainer first. Items marked **Research first** start with a short test and a report before any design.

### Guiding rules

1. **Private by default.** Anything that sends data to another company, stores more about visitors, or keeps audio on the server where it was not kept before ships switched off, with its own switch, a line for the privacy paragraph in Settings, and a matching update to PRIVACY.md.
2. **On the device first.** Where a feature can run in the browser or on the site, that is the default. An outside service is only ever an option the site owner chooses and pays for with their own key.
3. **Options are fine.** A feature one site never turns on is still worth building if it makes the plugin better for others.
4. **Output quality first.** Every milestone should make the finished reading, feed or page as good as the best tool in that area.
5. **Tested like the rest.** Each milestone adds unit, browser and upgrade checks, and passes `tests/run.sh` and `tests/run.sh oldest`.

### Phase 1: Shine

Output quality and quick wins. Start with the small ones together (glass rigs, feed tags, clip suggestions, finishing presets), then the voice-following prompter, voice polish and stronger cleanup.

**Order after 1.8.0** (agreed on 16 November 2026, after a voice and setup analysis of a real 14-minute reading made on a laptop and on a phone):

1. **Phone level** (1.9.0), built: see [1.9.0 Phone level](#190-phone-level).
2. **The full Podcasting 2.0 feed** (1.10.0), built: see [1.10.0 The full Podcasting 2.0 feed](#1100-the-full-podcasting-20-feed).
3. **Review for verse and pace** (1.11.0), built: see [1.11.0 Review for verse and pace](#1110-review-for-verse-and-pace).
4. **Phone take mode** (1.12.0), built: see [1.12.0 Phone take mode](#1120-phone-take-mode).
5. **Voice polish** (1.13.0), built: see [1.13.0 Voice polish](#1130-voice-polish).
6. **Voice-following prompter** (1.14.0), built: see [1.14.0 Voice following](#1140-voice-following). The research for a faster review beside it found Whisper on the graphics chip worth measuring on a reader's own laptop, and gave the first look at once.
7. **Pronunciation list** (1.15.0), built: see [1.15.0 The pronunciation list](#1150-the-pronunciation-list).
8. **Stronger noise cleanup** (1.16.0), built: see [1.16.0 The stronger cleanup](#1160-the-stronger-cleanup). Two real phone takes with a ringing bell and a creaking chair called for it.
9. **Faster review** (1.17.0), built: see [1.17.0 The faster review](#1170-the-faster-review). The research on a reader's laptop found the larger model on the graphics chip more accurate than the standard model and quick enough to be the default.

What the analysis found already in place: the 80 Hz high-pass (two stages, so the rumble under the voice goes), plosive removal, a de-esser, breath reduction and compression have run in Broadcast sound since 0.5.0, and cleanup quiets clicks before each phrase and bumps in pauses. A reading finished under 1.6.0's 30 dB lift measured -19.1 LUFS, on target; the analysis's 6 dB shortfall came from a file made under the old 24 dB limit. A phone recording made in the phone's own recorder came through about 22 to 25 dB louder than the same phone through the browser, which is what Phone level is for.

| Milestone | What it adds | Like | Default | Effort |
|---|---|---|---|---|
| Prompter for glass rigs | Mirror the text horizontally or vertically, margins for eye line, a full-screen prompter view | Riverside, PromptSmart | Off (a view choice) | S |
| Voice-following prompter | Built as [1.14.0](#1140-voice-following): a small streaming speech model on the reader's device follows the script, holds on an ad-lib and a pause, follows a skip or going back, shows the pace, and gives a first look when a take stops; the timed pace takes over if it cannot keep up | Riverside, PromptSmart VoiceTrack | Follow my voice, a switch | M |
| Full Podcasting 2.0 feed | `podcast:transcript` (from each reading's captions), a `podcast:chapters` file, `podcast:soundbite` (from clip suggestions), `podcast:person`, `podcast:funding`, `podcast:txt`; a ping to Podcast Index and WebSub when an episode is added | Buzzsprout, Auphonic | Tags on, pings off until the feed is published | S to M |
| Clip suggestions | Suggested 30 to 60 second stretches from Most replayed and from paragraph and sentence edges; one click opens one in the clip maker; feeds `podcast:soundbite` | Riverside Magic Clips, Descript | On (it only suggests) | S |
| Finishing presets | Loudness presets by destination: podcast stereo -16 LUFS, podcast mono -19, Spotify and YouTube -14, broadcast EBU R128 -23, audiobook ACX (RMS -18 to -23, peak -3 dB); a true peak limit setting; a loudness range report after each finish | Auphonic | Podcast -19 stays the default | S |
| Phone level | Built as [1.9.0](#190-phone-level). The phone test showed the browser's own processing cuts the sound, so the lift limit follows the measured room instead (up to 45 dB while the background stays under -60 dBFS), Check microphone says how much lift a take will need with a warning over the limit, and phone takes are kept in 24 bits. | Auphonic | The laptop path unchanged | S |
| Review for verse and pace | **Intended fades:** a cue mark, and a verse setting, that tells the review a trailing line is meant, so it is not flagged Faded ending. **Pace target per kind of post** (ritual and chant slower, essays faster), used by the review's Rushed and Slow flags and the Pace setting. **A pause map** of where pauses over 1 s fall, from Whisper's word times, beside the existing Long pause list. | Yoodli, Descript | Off until marked or chosen | S |
| Phone take mode | Built as [1.12.0](#1120-phone-take-mode): the take goes to the site in pieces of about ten seconds while recording, the phone stops at Stop and the review waits for the computer, a large level meter with a word under it stays on screen, and a take cut off partway can be carried on. | Riverside | On for phones | M |
| Voice polish | Built as [1.13.0](#1130-voice-polish): a strength for each Broadcast sound step with Standard as before, Hear the polish (one sentence before and after, and a one-off for the take), slow leveling, a live energy note, an EQ learned for each setup, and clicks between words lowered | Auphonic, Descript, Riverside Magic Audio | Off, except leveling | M |
| Faster review | Built as [1.17.0](#1170-the-faster-review): the larger Whisper model on the graphics chip (WebGPU with half precision), about 5 to 8 minutes for a 14-minute reading against about 27 on the processor, with Automatic as the Model menu's default and a fallback to the processor. Skipping silences and a two-pass review were measured and left out. | Descript | Automatic where supported | M |
| Stronger noise cleanup | Built as [1.16.0](#1160-the-stronger-cleanup): DeepFilterNet 3 in the browser beside RNNoise, shipped with the plugin, with a fallback to RNNoise, a suggestion on the Room line and a Cleanup choice in Hear the polish. It keeps the real voice; nothing here rebuilds a voice the way cloud enhancers do. | Descript Studio Sound, Adobe Enhance Speech | Off (RNNoise stays the default) | M |
| Pronunciation list | Built as [1.15.0](#1150-the-pronunciation-list): one screen for every pronunciation note and taught word, with search, editing, Move to, Forget, suggested words that may need a note, CSV and backup export and import | | | S |

## 1.21.0 Member feeds

**Goal:** a private podcast feed for each member, and readings that only members can hear, or hear first, for a show with supporters, paid members or a circle of friends. Designed with the maintainer on 18 November 2026, after the practice of the private podcast hosts and the apps was checked (docs/RESEARCH.md, Member feeds). The fourth milestone of Phase 2.

**Includes**
- **Who can hear this reading**, in each post's Reading audio box and on each collection: Everyone (the default), Members first for a number of days, or Members only. A collection is as closed as the most closed reading in it.
- **Files kept for members:** while a reading is for members, its files live in a folder visitors cannot open and are served only through checked addresses; they come back when it opens to everyone. The public feed leaves it out.
- **Member feeds** (Podcast feed, off by default): who is a member by role, by a choice on each person's profile, or by an active membership plugin (Paid Memberships Pro, MemberPress, Restrict Content Pro, WooCommerce Memberships, Memberful), with two filters for anything else. Each member has a private feed address with a random key, holding the public readings and theirs, with block tags and nothing personal in it, working whether or not the public feed is published; a reset address answers 410.
- Members find their address on their profile or through the shortcode `[foxfire_member_feed]`, with steps for the apps; the owner can email it, reset it or turn it off.
- On the post, a member hears the reading and everyone else sees why not, with Sign in and Become a member.
- **Feed activity** (off): the last fetch, the app and the networks of the last week for each member, with no IP address kept, to notice an address that may be shared.
- No database change. Nothing new from visitors.

**Left for later:** paid sign-up and billing (a membership plugin's job); Spotify's and Apple's own paid subscriptions; a block for the editor (Phase 3's player block); members-only videos and audiobooks on the site; counting members' downloads (with the IAB milestone).

**Finish line**
- Server checks of who is a member, keys and their reset, the choices and their dates, files moving both ways and the fields that follow them, the member and public feeds, the player, the shortcode, Feed activity and the privacy paragraph; a browser test of the owner, a member and someone who is not, through the post, the profile, the feed and its audio; uninstall checks.
- On the site: a member feed added to a phone's podcast app and played; a reading set to Members only, checked signed out and as a member, then put back.

## 1.22.0 Sponsor slots

**Goal:** short messages before, in the middle of and after readings in the podcast feed, from a library with dates, older episodes included, for a sponsor, a shop of one's own or a cause. Designed with the maintainer on 18 November 2026, after how hosts place self-run messages, the disclosure rules and a clean MP3 cut were checked (docs/RESEARCH.md, Sponsor slots). The fifth milestone of Phase 2.

**Includes**
- **Sponsor messages** under Foxfire Studio: who each is for, Paid or gifted or Your own, a link, a script recorded in the studio, dates, the slots it may use and the readings it goes on; the lower Order wins a shared slot. A paid message's first seconds are checked for a disclosure word.
- **Copies in each reading's sound,** made in the browser on the message's screen (format and loudness), so a message is joined by copying frames.
- **The mid-roll** in a pause of at least 0.6 s, found in the browser, chosen in the Reading audio box (three offered near the middle, or a time snapped to a pause, with Hear 5 seconds either side); only in readings of 10 minutes or more, in prose and story by default, never with the music bed; a collection's between two readings. The frames before the cut are copied again, muted, after the message, so the reading resumes without a tick.
- **A sponsored copy** for each reading in the feed, with its chapters ("A word from ..." for each message) and captions moved; a new file name for every change, the old one kept 2 days; the reading itself never touched. The feed's episode, its notes line and the short download address follow it; the GUID stays.
- The post's player keeps the reading; an optional thank-you line under it. Member feeds stay free of messages unless switched on.
- No database change. Nothing about listeners is used.

**Left for later:** messages from ad marketplaces or ad servers; a different message for different listeners; more than one mid-roll, and mid-rolls with the music bed (a fade made in the browser); messages inside full-length videos (with YouTube's paid promotion declaration); tracking prefixes (with the IAB counting milestone).

**Finish line**
- Server checks of the frames and the cut byte for byte, the plan's rules, the copy's chapters, captions and tag, the feed, the short address, member feeds, the thank-you line, a new name and the old file's two days, dates ending, the routes and a collection's gap; unit checks of the pause finder; a browser test that makes a copy, finds the pauses, chooses one and decodes the result against the original; uninstall checks.
- On the site: a message of one's own recorded and placed as a pre-roll and post-roll, a mid-roll tried on a reading, heard in the box and in a podcast app through a member feed, then taken off.

## 1.23.0 Storage offload

**Goal:** the files podcast apps and the player download served from somewhere that can take the load, without giving up the site's own files or a reading for members staying private. Designed with the maintainer on 18 November 2026, after the storage services, Apple's requirements, what browsers need and S3 with no code library were checked (docs/RESEARCH.md, Storage offload). The sixth milestone of Phase 2.

**Includes**
- Three ways: Bunny Storage, S3-compatible storage (signed in the plugin, uploads in parts for large files), or a CDN in front of the site (nothing uploaded).
- Only public listening files: readings, captions, sponsored copies and collections; everything for members and everything private stays.
- The site keeps every file; offload only adds copies. A copy is named by its content and used only once it answers at the public address with the right size and type.
- Copies kept up to date at the end of the owner's change and hourly; deleted at once when a file leaves public use, or two days after a newer file; cleared from a bunny.net pull zone with an optional API key.
- Three failed checks in a row bring every address back to the site, with a notice and Try again.
- Check this setup (the site's part and this browser's CORS read), Copy everything now, Delete the outside copies; keys in `wp-config.php` or saved and never shown again.
- The feeds, the short address (which stays on the site) and, by choice, the post's player; the player's captions and Download stay on the site.
- A privacy line while it is on. No database change. Uninstall never touches the outside storage.

**Left for later:** removing the site's own copies; signed, expiring addresses so members' files could live outside too; the Media Library's pictures and the studio's models; moving copies from one service to another in one step; video files in the feed (the upload path is ready for them).

**Finish line**
- Server checks of the signing against AWS's published example, which files go and which never do, content-named keys, the check before use, a file changed in place, a newer file's two days, members, unpublished, the purge, parts, the fall-back and its return, the feed, the short address, the player, Bunny's API, a CDN in front of the site, Delete the outside copies, keys in `wp-config.php` and the privacy line; a browser test through the section with a stand-in storage that checks every signature; uninstall checks.
- On the site: a bunny.net trial with a storage zone and a pull zone, Check this setup, Copy everything now, a reading heard in a podcast app from the outside copy, the CDN way tried on the same pull zone, then switched off.

## 1.24.0 Download counting to the IAB guidelines

**Goal:** podcast downloads counted the way the IAB Tech Lab's Podcast Technical Measurement Guidelines (version 2.3) describe, so they compare with any host's numbers, with a method page that says how. Designed with the maintainer on 18 November 2026, after the guidelines were read in full and the hand-off, the rule, the bot list and the data centre list were tested (docs/RESEARCH.md, Download counting to the IAB guidelines). The last milestone of Phase 2.

**Includes**
- The file sent by the web server after counting (LiteSpeed, Apache with X-Sendfile, nginx), so every piece an app asks for is seen; Check counting tries it on the site; a redirect wherever it does not pass, and for files with an outside copy.
- The pieces an app asks for joined, counting once they reach the file's own tag plus a minute of audio (the whole file under a minute); once per consumer, file and calendar day; IPv6 by its first 64 bits.
- Left out: HEAD, "has it changed" checks, two-byte probes, Apple Watch duplicates, bots and bot libraries from OPAWG's open list and OP3's, broken user agents, data centres and VPNs (X4BNet's list, refreshed monthly), and addresses past 30 an hour or 100 a day, with optional shared networks confirmed every 90 days.
- Members' feeds counted with the member as the consumer, kept only as totals.
- Apps and devices by name, podcast consumers a day, sponsor messages delivered, unusual days, a twice-yearly review, the method page as a draft, and optional measurement prefixes (OP3 and others) on the public feed.
- Database version 8; 1.2.0's counts kept and labelled.

**Left for later:** podcast consumers by week or month (a code kept longer); counting from the outside storage's own logs, so Apple's streaming of offloaded files is seen; OP3's figures shown beside these; the post's player counted as downloads; video in the feed, which 2.3 covers the same way.

**Finish line**
- Server checks of the ranges, the bar and the tag, every request pattern, the names, bots and devices, IPv6, the data centre list and its download, the limits, shared networks, members, sponsor messages, unusual days, the review, prefixes and the method page; the hand-off on real nginx and Apache with PHP-FPM; a browser test of the section and the Overview; uninstall checks.
- On the site: Check counting on LiteSpeed, then counting on for a test while the public feed is off, a reading streamed and another downloaded in Apple Podcasts from a member feed, both counted once on the Overview with Apple's names on a phone, then counting set back as the maintainer chooses.

## 1.25.0 The security review

**Goal:** nothing in the plugin lets anyone reach a file, a reading or a setting they should not, on any server, before anyone else installs it. The security part of Phase 3's Directory readiness, moved to the front on 18 November 2026: every route, upload, file write and saved secret reviewed, and WordPress's own security sniffs run on every file (docs/DESIGN.md, section 9u, has the findings).

**Includes**
- One rule for which files a reading may use, applied by the short address, storage offload, collections and sponsor messages: a listening file in uploads, never in a private folder or a folder whose `.htaccess` turns visitors away, and a members' file only for its own post. The plugin's own fields kept out of the Custom Fields panel. The hand-off only while counting is on.
- Sponsor messages made, recorded and published only by Administrators (a filter for Editors).
- Members' files in folders with random names, moved once on upgrade, and **Check private folders** on Podcast feed, with the lines nginx needs.
- A reading set to Members only or taken off publishing loses its sponsored copies at once.
- Share for feedback's full list for Editors and Administrators only, and 20 invitations a day.
- Every translated string escaped; the security sniffs report nothing.

**Finish line**
- Server checks of the rule, the four places, the fields, members' files, the upgrade move, sponsor message permissions by role, a reading becoming private, the share list and the invitation limit, and Check private folders; on real nginx and Apache, a reading pointed into a closed folder never sent; a browser test of Check private folders; the upgrade from 1.24.0.
- On the site: Check private folders passes on LiteSpeed; a reading set to Members only and back still plays from a member feed; a short address still plays in a podcast app.

## 1.26.0 Accessibility: the player and the admin screens

**Goal:** listeners can use the player with a keyboard, a screen reader or voice control on any theme, and every admin screen passes WCAG 2.2 AA's automated checks. The first half of Phase 3's Accessibility row (docs/DESIGN.md, section 9v, has the findings); the studio is 1.27.0.

**Includes**
- A solid player panel; the Readability check and Make it readable in Settings.
- Buttons named by their own words ("Back 15s", "Ahead 30s", "Speed 1×"); a new speed read out.
- A Transcript button on every reading with captions, its lines buttons that play from their moment; read along's fallback list as buttons.
- A clear 2 px focus ring; the progress bar at 6 px with its dot always shown and 24 px to touch.
- Read along in the post's own colours, with a tint and an underline; reduced motion respected.
- No captions yet on Readings and the reading's page; labels on six admin fields and the Reading audio box's buttons.
- docs/ACCESSIBILITY.md.

**Finish line**
- axe-core at WCAG 2.2 A and AA reports nothing on the player (transcript open too) and on every admin screen; the player works by keyboard alone; Make it readable makes a failing set pass; the server checks of the maths, the markup and the box.
- On the site: the player on the real theme measured with the page's own colours, used by keyboard only, the transcript and read along on a real reading.

## 1.27.0 Accessibility: the studio

**Goal:** every control in the studio, the review, the editor and Combine takes can be reached, seen and understood by keyboard and screen reader.

**Includes**
- The teleprompter's keys only when focus is on the script or the page; every control keeps its own keys; Escape returns to the script; Page Up and Page Down from anywhere.
- Controls brought back to full strength while they hold focus during a reading.
- Single-key shortcuts only on the script, and a switch to turn them off.
- One dialog helper for every dialog: focus in, Tab kept inside, Escape where it loses nothing, focus back.
- A word cursor in the script: cues and starting from a word in the teleprompter; playing, selecting, cutting and retaking in the review.
- A "?" button beside each control with its explanation, in place of hover tooltips.
- Status read out (recording, ready, review done), text tags for fast and slow sentences, headings, words ahead held at 3 to 1, the intro's buttons, new-tab links named.

**Finish line**
- axe-core at WCAG 2.2 A and AA reports nothing on the studio with the panel open, during a reading, with each kind of dialog open and on the review; keyboard checks for each finding; the studio at 393 px.
- On the site: the studio used by keyboard on a real post, a switch ticked with Space, a slider moved with the arrows, a dialog opened and closed, a cue set from the word cursor.

## 1.30.0 Every browser

**Goal:** clips and full-length videos come out the same in every browser that has the tools, with AAC sound, and every message about a browser names the tool it lacks. The first half of "Every browser and phone", designed with the maintainer on 19 November 2026 (docs/DESIGN.md, section 9z); the phone half is 1.31.0.

**Includes**
- Mediabunny in place of mp4-muxer, so every browser keeps High profile; the AAC add-on where the browser has no AAC; no edit list.
- One check of what the browser can do (can.js), messages naming the missing tool, and a This browser line in Settings and the studio.
- Automatic timing the larger model and handing over to the standard one where it is slower than the reading.
- The practice reading always records; the hidden tooltips speak of this reading.

**Finish line**
- Chrome's browser tests: the clip and the video with AAC from the add-on, High profile and no edit list; the hand-over; the practice reading. `tests/run.sh firefox`: a clip and the video in Firefox with every frame, High profile and AAC.
- On the site: a clip and the full video made in Firefox on a computer and in Safari on an iPhone, checked with ffprobe; a review in Firefox on the laptop's stronger graphics chip.

## 1.36.0 Fuller help

**Goal:** every screen and every setting has a guide that says what it is for, where it lives and what to press. Agreed with the maintainer on 22 November 2026 (docs/DESIGN.md, section 9zf), after a review found 1.32.0's guides accurate but too short.

**Includes**
- 28 guides in eight groups, with Start here first: a path for site owners and one for readers.
- Thirteen new guides (Settings section by section, finishing the sound, captions and chapters, recordings, kinds of reading, sharing, member feeds, sponsor messages, counting and offload, roles, listeners, upkeep) and the rest filled out with steps.
- Guides in parts; every screen opening its own guide or part; Help with this section under each section of Settings, Podcast feed and Recordings.

**Finish line**
- A PHP test that fails when a screen in the menu, or a section heading on Settings, Podcast feed or Recordings, has no guide; the names the guides use checked against the plugin's strings; the browser test for the groups, parts and section links; docs/guide up to date.
- On the site: the guides read on the laptop and the iPhone.

## 1.35.0 YouTube and SoundCloud players that load on play

**Goal:** a page with a YouTube video or a SoundCloud track loads as fast as one without, and a visitor's browser contacts the service only if they choose to play. Asked for by the maintainer and designed with them on 22 November 2026 (docs/DESIGN.md, section 9ze).

**Includes**
- Settings, Embedded players: a switch for YouTube and one for SoundCloud, both off by default; Clear saved pictures.
- Every player in post and page content and block widgets, however it was added, shown as a panel at the player's size: the picture (kept on the site), the title, where it plays from and a play button.
- One press loads the real player in the same box and starts it (YouTube's privacy-enhanced address, its settings kept); a real button, and a plain link without JavaScript.
- One thing plays at a time with the reading player; the editor, feeds and the REST API untouched; a privacy paragraph; 90 days for unused pictures.

**Finish line**
- PHP checks for every way of adding a player and every kind of address, with stand-in answers; a browser test that counts every request to both services and checks the keyboard, the box, the taking turns, axe-core and 393 px.
- On the site: a draft post with a public YouTube video and SoundCloud track, in Chrome on the laptop and Safari on the iPhone.

## 1.34.0 The take shelf across computers

**Goal:** a reader who records on more than one computer, browser or phone has one take shelf, so Continue and Combine takes work wherever they are. Designed with the maintainer on 22 November 2026 (docs/DESIGN.md, section 9zd).

**Includes**
- Settings, Take shelf: off by default; a space limit (2 GB), the space used and Delete every shelf on the site.
- Each reviewed take sent to the site after its review as lossless FLAC made in the browser, in pieces, carrying on after a reload; its words, flags and fingerprint with it.
- The list from the site when the studio opens a post; a take's recording fetched when Combine takes first needs it, checked sample for sample.
- The site's numbers, six a post for 30 days, the same take kept once, the plan kept, Remove and Clear everywhere; phones send their short takes and never fetch; the practice reading stays in the browser.
- Recordings, Your take shelf on the site; WordPress's personal data export and erase.

**Finish line**
- Unit tests for FLAC written and read back exactly; PHP checks for the routes, the rules, the limit, the folder and the tools; a browser test with two computers and a phone signed in as one person.
- On the site: a part read in Chrome continued and combined in Firefox on the laptop, and Continue on the iPhone knowing both.

## 1.33.0 Other languages

**Goal:** a reading in another language is reviewed as well as an English one, and an English site sees no change. Designed with the maintainer on 21 November 2026 (docs/DESIGN.md, section 9zc).

**Includes**
- A Reading language for each post, in its Reading audio box, the site's language by default.
- Whisper's multilingual models, pinned, with the language set and the task fixed to transcribe; a Settings download for them.
- Word matching in every script, fillers per language, numbers as Check, the pace against the reader's own, How hard outside English, taught words per language, and the language in the feed, captions, player and MP3.

**Not yet:** languages written without spaces between words, and Follow my voice beyond English.

## 1.32.0 Help and docs

**Goal:** someone using the plugin can find how to do each thing from wherever they are. Designed with the maintainer on 21 November 2026 (docs/DESIGN.md, section 9zb).

**Includes**
- Foxfire Studio, Help: fourteen short guides with a search box and What's new.
- A Help link and Help tab on every plugin screen, and Help and guides in the studio's Controls panel.
- The same guides in docs/guide, written from the plugin's own words, ready for a public docs site.

## 1.31.0 Every phone

**Goal:** phones are decided by touch and memory, never by name, and long takes can be reviewed on a phone. The second half of "Every browser and phone" (docs/DESIGN.md, section 9za).

**Includes**
- Touch-first devices and the memory hint in place of the user agent; the Model menu shown everywhere.
- A lighter phone review (a 16 kHz copy first, full-rate copies released, the follower closed), starting by itself for takes up to 5 minutes.
- A pace line on the practice reading and a browser's first take, and Follow my voice offered in New here.

## 1.29.0 The setup wizard

**Goal:** a new site owner gets the few choices that matter on the first day in a few short steps, and every new reader gets a microphone check and a practice reading before their first real take. Designed with the maintainer on 19 November 2026 (docs/DESIGN.md, section 9y).

**Includes**
- Set up (Foxfire Studio, then Set up): Welcome, Your show, The look, Readings and the player, Privacy and files, Your first reading, Done; one step per screen with Next, Skip this step and Back; each step opens with the site's current values and writes only its own settings.
- A notice on new installs only, with Set up and Not now, and no redirect; Run setup again in Settings for everyone.
- Privacy and files: keep takes on the site, one button to download the studio's files, the switches that touch visitors listed and left off.
- The practice reading: a built-in script with no post, Check microphone first, its takes kept as practice takes; New here offered once to a reader new to a browser.

**Finish line**
- Server checks of which sites are offered it, each step's writes (nothing on a site set up already), the form and who may use it, and practice takes; a browser test of the whole wizard by keyboard with axe-core on every step and at iPhone width, the practice reading's review, and New here (also on WordPress 6.0); an upgraded site not offered it.
- On the site: no notice (an existing site), Run setup again through every step changing nothing, and the practice reading opening with Check microphone.

## 1.28.0 The player block

**Goal:** the player can be placed anywhere without editing theme files, on classic and block themes, and never shows twice. Designed with the maintainer on 19 November 2026 (docs/DESIGN.md, section 9x).

**Includes**
- The Reading player block: the post's own reading, or Another reading chosen by search; in posts, the Site Editor's templates, Query Loops and widget areas; the real player as its preview; a card with Open the studio for a post without a reading.
- `[foxfire_player]`, with `[reading_room_player]` kept; both turn into the block when pasted.
- The top of the post gives way to a player placed by hand; each reading once per page; one player playing at a time; read along on the reading's own post; the preview note wherever it is placed.

**Finish line**
- Server checks of every placement and rule; a browser test with a block theme and a classic theme (and WordPress 6.0) of the block on posts and pages and in the editor; axe-core on the blocks and the sidebar.
- On the site: a real post unchanged with the player at the top; a private draft page with the block set to another reading, checked in the editor and in preview, then deleted.

### Phase 2: Reach

Getting readings everywhere. Start with full-length video and collections, then audiobook export.

| Milestone | What it adds | Like | Default | Effort |
|---|---|---|---|---|
| Full-length video | Built as [1.18.0](#1180-full-length-video): a whole reading as a 1080p video (a picture, title, waveform, chapter and captions) for YouTube, Spotify and video podcast apps, made in the browser and downloaded, with a description, captions file and thumbnail for the upload. The feed's alternate enclosure is left until a feed can be checked in a real app. | Riverside, Descript, Spotify video | Made on request | M |
| Collections | Built as [1.19.0](#1190-collections): several readings joined on the site into one MP3 with a chapter for each, every reading copied exactly with silence between, with its own page, player, captions and Bonus feed episode, joined again when a reading changes, and a reading in another format or loudness matched in the browser | | Made on request | M |
| Audiobook export | Built as [1.20.0](#1200-audiobook-export): a collection made into the opening credits, one file per reading, the closing credits, a retail sample and the cover, to ACX's rules (192 kbps mono, -20 dB RMS, peaks under -3 dB, room tone at both ends made from the reading's own pauses when needed), checked and downloaded as one ZIP with a check report; each chapter from the reading's audiobook copy (its take finished for audiobooks), or from its post's MP3 when it has no music bed | Auphonic, Descript | Made on request; copies off | M |
| Member and private feeds | Built as [1.21.0](#1210-member-feeds): a private feed for each member with a random key (block tags, nothing personal in it), readings for Everyone, Members first or Members only with their files kept where visitors cannot open them, members by role, by profile or by an active membership plugin, with filters, and Feed activity (off) to notice a shared address | Buzzsprout subscriptions, Spotify Memberships | Off | M |
| Sponsor slots | Built as [1.22.0](#1220-sponsor-slots): pre-roll, mid-roll and post-roll messages from a library with dates, recorded in the studio, copied into each reading's sound in the browser and joined on the site by copying frames, the mid-roll in a chosen pause with the frames before the cut copied again muted so it never ticks, chapters and captions moved, a new file name for each change; the post's player keeps the reading | Buzzsprout Dynamic Content (self-run) | Off | M |
| Storage offload | Built as [1.23.0](#1230-storage-offload): the readings podcast apps and the player download, served from Bunny Storage, S3-compatible storage or a CDN in front of the site, each copy named by its content and checked before use, the site's own files always kept, back to them by itself if the storage stops answering, copies deleted the moment a reading leaves public use, with a privacy line | Every host | Off | M |
| Download counting to the IAB guidelines | Built as [1.24.0](#1240-download-counting-to-the-iab-guidelines): downloads counted to the IAB Tech Lab guidelines v2.3, the file sent by the web server after counting, the pieces an app asks for joined to the tag plus a minute, bots from OPAWG's list, data centres and VPNs left out, members' feeds counted, apps and devices named, podcast consumers a day, sponsor messages delivered, unusual days, a review twice a year, a method page and optional prefixes. Formal certification stays a paid company audit, never claimed. | Buzzsprout, Spotify for Creators | Counting off, prefixes off | M |

### Phase 3: Ready for others

Everything a public release needs.

| Milestone | What it adds | Default | Effort |
|---|---|---|---|
| Setup wizard | Built as [1.29.0](#1290-the-setup-wizard): first-run steps on a new site (the show's name, the look, readings and the player, privacy and the studio's files on the site), saved one step at a time and changing only what a step shows, offered by one notice and run again from Settings; a built-in practice reading with Check microphone first, offered once to anyone new to the studio | Offered once, new sites only | M |
| Player block and shortcode | Built as [1.28.0](#1280-the-player-block): the Reading player block (posts, templates, Query Loops, widget areas, Another reading), `[foxfire_player]`, the top of the post giving way to a player placed by hand, once per reading on a page, one playing at a time | | S to M |
| Other languages | Built as [1.33.0](#1330-other-languages): a reading language per post (languages written with spaces between words), Whisper's multilingual models with the language set, word matching in every script, fillers, How hard and pace per language, taught words per language. Still to come: Chinese, Japanese, Thai and the other languages without spaces, and voice following beyond English | The English models stay the default | M |
| Every browser and phone | Researched on 19 November 2026 (its faults fixed in 1.29.1). Browsers built as [1.30.0](#1300-every-browser): Mediabunny with AAC in every browser and no edit list, messages naming the missing tool, a This browser line, Automatic timing the larger model, the practice reading recording. Phones built as [1.31.0](#1310-every-phone): touch and memory in place of names, a lighter review, the shelf and Continue on phones, the pace line | | M |
| YouTube and SoundCloud players that load on play | Built as [1.35.0](#1350-youtube-and-soundcloud-players-that-load-on-play): each player shown as a picture with a play button (the picture kept on the site) at the player's own size, the real player loaded and started on one press, taking turns with the reading player; every way of adding a player, block widgets too; the editor, feeds and REST untouched | Off (one switch for each service) | S |
| Take shelf across computers | Built as [1.34.0](#1340-the-take-shelf-across-computers): each person's shelf kept on the site too, as lossless FLAC sent in pieces after each review, listed at once on any computer and fetched when Combine takes needs it; phones send their short takes and never fetch; the site's numbers, six a post for 30 days, a space limit and Delete every shelf | Off (keeps audio on the server) | M |
| Accessibility | The player and the admin screens built as [1.26.0](#1260-accessibility-the-player-and-the-admin-screens): a solid panel, buttons named by their words, a Transcript button (in place of a captions page) with lines that play from the keyboard, a clear focus ring, read along in the post's colours, the Readability check and Make it readable. The studio built as [1.27.0](#1270-accessibility-the-studio): its keys only on the script, a switch for single-key shortcuts, one dialog helper, a word cursor, ? buttons for help, status read out | On | M |
| Help and docs | Built as [1.32.0](#1320-help-and-docs): the Help screen, Help links and tabs on every screen and in the studio, the guides in docs/guide. Filled out as [1.36.0](#1360-fuller-help): 28 guides in groups with Start here, guides in parts, every screen opening its own, Help with this section links. Still to come, when the clean repository goes public: the docs site (GitHub Pages from docs/guide) and short silent, captioned videos made from the test site, kept out of the plugin zip | | M |
| Security review | Built as [1.25.0](#1250-the-security-review): one rule for which files a reading may use, sponsor messages for Administrators, members' files in random folders and Check private folders, a private reading's sponsored copies deleted at once, a narrower share list; the security sniffs clean | | M |
| Directory readiness | Plugin Check clean, no outside requests unless switched on, directory assets and readme (the security review came first, as 1.25.0; see the notes on the directory in the ideas below) | | M |

### Phase 4: More voices

After a public release, guided by what other users ask for.

| Milestone | What it adds | Default | Effort |
|---|---|---|---|
| Guest readers | A guest reads their part on their own device through a private link (like Send to my computer); their take arrives reviewed and ready to combine | Off | L |
| Multitrack editing | Separate tracks per voice in the editor, each with its own cleanup, levels and music ducking | | L |
| Live interviews | Two or three people recorded live, each recorded on their own device and uploaded after, so a weak connection never spoils the audio; peer-to-peer through the browser with the site in between. Some networks need a relay server, which the owner supplies. **Research first:** what shared hosting can carry. | Off | L |
| Camera recording | Video beside the audio, for a video version of a reading or interview | Off | L |
| Delivery coaching on camera | Eye contact and head movement feedback while reading, run in the browser, nothing kept | Off | M |

### Phase 5: Optional AI helpers

After a public release. Each helper is off by default, says exactly what it sends, and never runs without being clicked. Each runs on the device where the browser has a model, or through a service with the owner's own key.

| Milestone | What it adds | Default | Effort |
|---|---|---|---|
| Episode writing helper | Suggested episode titles, descriptions, chapter titles and social posts from the post text (already public) | Off | M |
| Caption translation | Captions translated into other languages for the player and feed | Off | M |
| Word patch in your own voice | A misread word replaced with a generated version of the reader's own voice, through a service with the owner's key; own voice only, with a recorded consent step, every patched word marked in the editor and listed in the episode notes | Off | L |

### Left out on purpose

- Formal IAB certification (a paid audit for companies; the guidelines are followed instead).
- Automatic submission to Apple Podcasts and Spotify (both require the owner to submit; the feed is pinged where open indexes allow).
- Any feature that sends audio or visitor data out without the owner switching it on.

---

## Ideas parking lot

Not planned yet. Add to this list freely.

- (Guest readers are now in Phase 4, the chapters file in Phase 1's Podcasting 2.0 feed, and directory readiness in Phase 3.)
- **Notes on the WordPress.org directory**, for Phase 3's directory readiness, only if the plugin is ever submitted there. Its guidelines do not allow loading executable code from a CDN by default. The MP3 encoder (about 130 KB) and noise model (about 110 KB) could ship in the zip; transformers.js and ONNX Runtime are tens of megabytes, so the directory version would more likely ask for the Settings download before the first review. Releases on GitHub do not need any of this.

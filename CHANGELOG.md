# Changelog

Every finished version, oldest at the bottom. Each entry says what changed and, where it matters, **why**, because the why is what makes the next change easier. The longer reasoning lives in [docs/DESIGN.md](docs/DESIGN.md).

Version numbers follow `MAJOR.MINOR.PATCH`. While the project is in `0.0.x`, each finished, deployed step gets the next patch number. From `0.1.0` onward, minor versions mark roadmap milestones and patch versions are fixes. See [CONTRIBUTING.md](CONTRIBUTING.md#version-numbers).

Editions: **T** = the original theme template, **P** = plugin edition.

---

## [Unreleased]

Nothing yet.

---

## [1.36.1] Small fixes (P)

A patch agreed with the maintainer on 22 November 2026, fixing faults noticed in earlier live checks and parked until now, so the first public copy carries them fixed. Nothing in the database, the settings or what is stored or sent changes.

**Fixed**
- **A misheard first or last word** (compare.js `lone()` in `compareReading`): when Whisper heard one word right beside the reading, the one script word left on that side was the script's own first or last word, and the two did not sound alike, the word was listed as **Added** and the script's word left as not reached, so the paragraph's score fell and Combine takes could count it as read by no take. Words of three letters or fewer never count as alike (`similar()`), so "end" heard as "and" scored nothing in the edge test. It is now a **Misread** ("“end” heard as “and”"), when the heard word is within 1.5 s of the reading and is no filler or ignored word. Two or more stray words ("thank you"), a filler and a word heard later are left as before. Noticed in 1.33.0's live check of a Spanish reading.
- **Follow my voice and a misheard last word** (follow.js `Follower.endHeard`, `onTrack`; voice.js `endWordAt`, `END_QUIET` 1.2 s): the follower moves only on heard words that sound like the script's, so a misheard last word left it one word short and the take kept recording until the reader pressed Stop. Now, when only the last word is left and the words before it were just matched, a word heard there sets `endHeard`, and the page ends the script once no new word has come for 1.2 s; the take then finishes two seconds later as after any last word. A reader who adds words before the last one keeps the script open, and the real last word heard after all ends it at once as before. A follower moved by hand, or lost, notes nothing. Timed from the heard words, since the voice level (`speaking`) flickers in a quiet room. Noticed in 1.14.0.
- **The "?" column in the Controls panel** (teleprompter.css): a control with help is a grid of the control and its 24 px "?" with an 8 px gap, so its switch, slider or menu ended 32 px left of a row without help. Rows without help (`.tp-sec > .tp-switch, .tp-slider, .tp-field, .tp-btn`) keep the same 32 px at their right, so each section's controls end at one edge, on a computer and at 393 px. Rows that share a line by design (Warm up beside Check microphone, the opening drill's switch beside its length) are unchanged. The review's result box has no such column, so it needs nothing. Noticed in 1.27.0.
- **Workers' addresses** (i18n.php `rrt_js_url()`): since 1.33.0 the studio's scripts carry their file's time in their address, but eight addresses of scripts loaded later as workers carried the version only: mp3.js (render.php, audiobook.php, collections.php, sponsors.php), denoise.js and sound.js (render.php), follow-worker.js (follow.php) and deepfilter.js (stronger.php). A same-number rebuild that changed one could leave a phone on the old file for up to a year. All of them, and flac.js (which did this by hand), now use `rrt_js_url( $file )`. `tests/run.sh` fails if an address of the old form comes back.

**Looked at and left alone**
- The literal curly quotes and accented letters in follow.js and voice.js (noticed in 1.14.0): every studio script has some (compare.js 29), the files are UTF-8, workers are always read as UTF-8, and they have worked in every live check.
- The player block's gold button text in the editor (noticed in 1.28.0): on the live site the preview's buttons have the same colour, background and decoration as the published post's player; the gold is the player's own accent from Settings, Colours.

**What's new** has 1.36.1 (1.34.0 drops off, the three newest kept); `docs/guide/` rewritten.

**Tests:** `tests/unit/compare.test.js` (+2: the last word, the first word and a Spanish last word misheard are misreads; two stray words, a filler and a late word are left as before); `tests/unit/follow.test.js` (+2: a misheard last word is noted and kept noted through added words, the real last word ends it, a middle word notes nothing; a follower moved by hand or not on track notes nothing); `tests/browser/follow.py` (+3: with a stand-in listener that mishears the last word, the reading ends by voice, the take finishes and no script errors); `tests/browser/a11y_studio.py` (+2: every control in each Controls panel section ends at one edge, on a computer and at 393 px); `tests/run.sh`'s new "Script addresses carry the file's time" step. Each new check failed on 1.36.0.

---

## [1.36.0] Fuller help (P)

Agreed with the maintainer on 22 November 2026 (docs/DESIGN.md, section 9zf), after a review of the guides found them accurate but thin: about 3,900 words for 35 milestones, no guide for Settings, Recordings, finishing the sound, captions and chapters, kinds of reading, roles, upkeep or listeners' accessibility, and Settings' and Recordings' Help links opening the wrong guides. Nothing outside the help changes, and nothing in the database.

**Why:** a guide that names a feature in one line does not let anyone use it. Every screen and setting now has a guide that says what it is for, where it lives and what to press.

- **28 guides, about 12,000 words** (`includes/help-text.php`), in eight groups (`rrt_help_groups()`: start, record, finish, video, podcast, numbers, site, trouble). Each guide has a `group`. New: Start here (`start`, first), Kinds of reading (`kinds`), Finish the sound (`sound`), Captions and chapters (`captions`), Recordings and waiting takes (`recordings`), Share for feedback (`share`), Member feeds (`members`), Sponsor messages (`sponsors`), Download counting and storage offload (`delivery`), Settings, section by section (`settings`), Who can do what (`roles`), What listeners get (`listeners`) and Update, back up or remove the plugin (`upkeep`). Filled out: Make your first reading, Browsers and phones, Tools for reading (cues, pronunciation notes, prompter view, practice, Readings to make, Your progress and goals), Read the review (the parts of the review, the model, the downloads), Record on a phone (the waiting limit of five), Keyboard shortcuts (the review's keys), Edit and polish a take, Put the player on a post, Make a clip or a full video, The podcast feed, Collections and audiobooks, Listening numbers and reports (each screen), Privacy (Private folders) and When something goes wrong (from 8 problems to 22, in five parts). The duplicated `intro` key in Keyboard shortcuts is gone.
- **Two new pieces:** `array( 'h', key, title )`, a part of a guide with its own heading and anchor (`#rrt-help-<guide>-<key>`, focusable), and `array( 'links', keys )`, a list of links to other guides with their introductions (Start here's two paths).
- **Addresses:** `rrt_help_url()` accepts `guide:part` (an unknown part falls back to the guide), `rrt_help_parts( $topic )` lists a guide's parts, `rrt_help_group_url( $group )` opens a group (`#rrt-help-group-<key>`).
- **Every screen opens its own guide or part** (`rrt_help_screens()`): Settings opens Settings, section by section (it had opened Make your first reading), Recordings opens Recordings and waiting takes (it had opened the player guide), Sponsor messages opens its own guide, Shared takes opens Share for feedback, and Readings, Sources, Insights, Reports, Readings to make, Pronunciation and Your progress open their parts. The Help tab names the part when there is one.
- **Help with this section** (`rrt_help_sections()`, `window.rrtHelpParts`, help.js `addParts()`): a small link under each section heading of Settings, Podcast feed and Recordings that has a guide or part, named with the section for screen readers. The headings are the screens' own translated strings, so the links follow a translation. Members with a feed (shown only while member feeds are on) opens Member feeds, What a member does; it was found missing on the live site, so tests/php/help.php now switches member feeds on, with the take shelf, while it reads the screens.
- **The Help screen:** the list of guides in groups, each group a heading that can be linked to, and the search hides a group when none of its guides matches. The studio's Help and guides link opens the group Recording and reviewing.
- **docs/guide:** README in the same groups (with the changelog link at the top), parts as headings, Start here's links as Markdown links; `start.md` and the thirteen new guides.
- What's new: 1.36.0 added, 1.33.1 dropped.
- The GitHub tests job's limit is 45 minutes (was 40 since 1.30.0): 1.35.0's run took 34.
- Tests: `tests/php/help.php` (42): every guide in a group, parts and links well formed, at least 25 guides and over 9,000 words, every screen and section target a real guide or part, every screen in the Foxfire Studio menu opening a guide, every section heading of Settings, Podcast feed and Recordings (the take shelf switched on for the check) having a Help link and every listed heading being on its screen, the groups and parts on the Help screen, and about 100 more control names (168 in all) checked against the plugin's own strings. `tests/browser/help.py` (43): the eight groups with Start here first, a group hidden while searching, Start here's links, a part's and a group's anchor taking the focus, the new screens' Help links, Help with this section under every section heading of Settings and Podcast feed, named and passing axe-core, landing in its guide, and the Help screen at 393 px.

---

## [1.35.0] YouTube and SoundCloud players that load on play (P)

Asked for by the maintainer and agreed with them on 22 November 2026 (docs/DESIGN.md, section 9ze). Off by default. Nothing in the database changes, and no setting changes on update.

**Why:** a YouTube or SoundCloud player loads all of its files and sets its cookies as soon as it is scrolled near, whether or not anyone plays it. WordPress's `loading="lazy"` only waits for the scroll.

**Added**
- **Settings, Embedded players** (`includes/embeds.php`, option `rrt_embeds`: `yt`, `sc`, both false by default): "YouTube videos load when a visitor presses play" (`#rrt-embeds-yt`), "SoundCloud players load when a visitor presses play" (`#rrt-embeds-sc`), Save embedded players (`#rrt-embeds-form`, `admin_post_rrt_embeds_save`, button `save_embeds`), the number of saved pictures (`#rrt-embeds-saved`) and Clear saved pictures (`#rrt-embeds-clear`, `admin_post_rrt_embeds_clear`, `rrt_embeds_clear()`). Shown through `rrt_settings_files` after the Take shelf section (priority 20).
- **The panels:** `rrt_embeds_filter_html()` on `the_content` and `widget_block_content` at priority 99 turns each YouTube (`youtube.com` or `youtube-nocookie.com`, `/embed/`) and SoundCloud (`w.soundcloud.com/player`) iframe into `div.rrt-embed.rrt-embed-yt|sc.is-wide|is-tall` with `data-rrt-embed`, `data-rrt-key`, `data-rrt-play` (the player address loaded on play), `data-rrt-title`, `data-rrt-allow`, `data-rrt-full`, and the player's box as an inline style (`aspect-ratio` as a single number and a width for YouTube, 315 px wide for a Short; a height and width for SoundCloud, 166 or 450 px when the code gives none). Inside, `a.rrt-embed-go` to the video or track (`rrt_embeds_yt_page_url()`, `rrt_embeds_sc_page_url()`) holding the picture `img.rrt-embed-pic` (lazy), `.rrt-embed-btn` and `.rrt-embed-cap` (the title, with a hidden "Play" before it, and "Plays from YouTube" or "Plays from SoundCloud"). `embed_oembed_html` (priority 20) marks WordPress's own embeds with `data-rrt-from`, the address they were made from, so a Short, a start time (`t`, `start`, `#t=`, in seconds or `1h2m3s`) or a SoundCloud page is known. Never in the admin, feeds, REST, XML-RPC, WordPress's embed template or the studio (`rrt_embeds_context_ok()`, filter `rrt_embeds_context_ok`).
- **Addresses:** `rrt_embeds_yt_parse()` (watch, youtu.be, shorts, live, embed and playlist links; ids of 11 letters, digits, `-` or `_`) and `rrt_embeds_sc_parse()` (player and page addresses; only `api.soundcloud.com` and `soundcloud.com` tracks). `rrt_embeds_yt_player_url()`: `https://www.youtube-nocookie.com/embed/<id or videoseries>` with the original's `start`, `end`, `list`, `listType`, `index`, `playlist`, `loop`, `controls`, `cc_load_policy`, `cc_lang_pref`, `hl`, `rel`, `color`, `iv_load_policy`, `mute`, plus `autoplay=1`, `playsinline=1`, `enablejsapi=1` and `origin`. `rrt_embeds_sc_player_url()`: the same track with its colour and display settings and `auto_play=true`.
- **Pictures:** `rrt_embeds_info()` asks `www.youtube.com/oembed` or `soundcloud.com/oembed` once (`rrt_embeds_fetch()`; YouTube's `hqdefault.jpg` when the answer has no picture), at most four a page view (filter `rrt_embeds_fetch_max`), a failed one again after a day; `rrt_embeds_save_picture()` keeps a copy at most 640 px wide in `uploads/rrt-embeds/` (WebP where the image editor can, otherwise JPEG; the original JPEG, PNG or WebP bytes where it cannot), named `<key>-<random 6>.<ext>`, with an empty `index.php`. The list is the option `rrt_embeds_list` (key `yt-...` or `sc-...` => `t`, `f`, `w`, `h`, `at`, `seen`, `fail`; `seen` updated at most daily). Saving a post with a player schedules `rrt_embeds_prefetch` five seconds later. The hourly job (`rrt_share_hourly`) removes entries not shown for 90 days and files the list no longer names.
- **`assets/js/embeds.js`** (`window.RRTEmbeds`: `init`, `load`, `pauseAll`, `loaded`, `safeSrc`), loaded only on pages with a panel: the link becomes `button.rrt-embed-go`; a press loads `iframe.rrt-embed-frame` in the panel's place (title, `allow`, `allowfullscreen` for YouTube, `referrerpolicy="strict-origin-when-cross-origin"`), moves focus into it and fires `rrt:embed`. Only addresses starting `https://www.youtube-nocookie.com/embed/` or `https://w.soundcloud.com/player/?` are loaded. One at a time: loading pauses the reading players and other panels; YouTube's `onStateChange` or `infoDelivery` playing state and SoundCloud's `play` message pause the reading players and other panels; the reading player's `rrt:player` play pauses the panels (`pauseVideo`, `pause`). No double ampersand, for page optimizers.
- **Styles** in `assets/css/player.css`, with the player's colours (`rrt_player_tokens_css()` now also covers `.rrt-embed`); the styles go in the head on a single post or page with a player; inside an Embed block with responsive embeds the panel fills the block's box. The picture's rule is `.rrt-embed .rrt-embed-go img.rrt-embed-pic` (three classes and the tag) so a theme's rule for pictures in a post's text, often two classes and `img` with `height: auto`, cannot change its size; `tests/browser/embeds.py` checks this with such a rule.
- **Readability:** three rows' names now include the panels (titles, where they play from, the play button); the pairs are unchanged.
- **Privacy:** `rrt_embeds_privacy_text()` in the suggested policy text while a switch is on; PRIVACY.md.
- **Uninstall:** `rrt_embeds` always goes; with the delete box ticked, `uploads/rrt-embeds` and `rrt_embeds_list` too.
- **The guides:** a new YouTube and SoundCloud players guide; Privacy; What's new has 1.35.0 (1.33.0 drops off); `docs/guide/` rewritten.

**Tests:** `tests/php/embeds.php` (67: off by default; the Embed block, copied embed code, a link on its own line, the shortcode, youtu.be with a time, a Short, a playlist, block widgets; each switch alone; look-alikes and other services left alone; feeds, the admin, the studio and REST untouched; fetched once with stand-in answers, a missing picture and its retry a day later, titles escaped; the privacy paragraph; the settings section; the prefetch; 90 days, stray files and Clear saved pictures); `tests/browser/embeds.py` (42, with `tests/wp/embeds.php`: no request to either service before play, even after scrolling, and none without JavaScript; named buttons; Tab, Enter, Space and a click; the started player's address, referrer and focus; the same box; taking turns both ways with the reading player and between panels; axe-core; 393 px; Settings switching it off). `tests/php/uninstall-seed.php` and `uninstall-check.php`, and `tests/php/upgrade-check.php` (both switches off after an update).

---

## [1.34.0] The take shelf across computers (P)

Phase 3's seventh milestone, as agreed with the maintainer on 22 November 2026 (docs/DESIGN.md, section 9zd). Off by default. Nothing in the database changes, and no setting changes on update.

**Why:** the take shelf (1.3.0) lived only in the browser that reviewed each take, so a post read in parts had to be read, reviewed and combined in one browser, a take reviewed on a phone could only be combined after Send to my computer and a second review, and clearing a browser's site data lost its shelf.

**Added**
- **Settings, Take shelf** (`includes/shelf.php`, option `rrt_shelf`: `on`, `limit` in MB, default 2048): "Keep each person's take shelf on the site", the space used by everyone, and Delete every shelf on the site (`rrt_shelf_delete_all()`). Its own form (`#rrt-shelf-form`, `admin_post_rrt_shelf_save`), shown through the new `rrt_settings_files` action after the main Settings form.
- **The site side:** each person's list in user meta `_rrt_shelf` (id, post, n, at, seconds, bytes, have, state `up` or `ready`, sum, updated); each take's words and flags as `<user>-<id>.json` and its recording as `<user>-<id>.flac`, each post's plan as `<user>-plan-<post>.json`, in `uploads/rrt-shelf/<random>/` (`rrt_shelf_dir()`, the name in option `rrt_shelf_key`; `.htaccess` refusing every request in both folders; one of `rrt_private_folders()` and Check private folders). Routes, each for the owner only and only for a post they can edit: `GET rrt/v1/shelf?post=` (every take with its words, the plan, whether there is room), `DELETE rrt/v1/shelf?post=` (Clear the shelf), `POST rrt/v1/shelf/take` (starts a take with its words; the site gives its number), `POST .../take/<id>/piece?offset=` (pieces up to 4 MB, refused with how much the site holds when one arrives early), `.../done` (only when whole and a FLAC), `GET .../audio`, `DELETE .../take/<id>`, `POST rrt/v1/shelf/plan` (cleaned; the later save wins). `rrt_shelf_list()` applies the rules: 30 days from when the take was recorded, the newest six a post, sends not heard from for two days given up. The hourly job (`rrt_share_hourly`) applies them to every shelf. A 507 `rrt_shelf_full` when the limit (or the disk) would be passed. `rrt_shelf_boot( $post )` in the studio's boot as `shelfSite` (null while off, for the practice reading, or for a post the person cannot edit).
- **`assets/js/flac.js`** (`window.RRTFlac`, and a worker with `encode` and `decode` messages): 16-bit mono FLAC with fixed prediction (orders 0 to 4), partitioned Rice residuals, constant and verbatim subframes, 4,096-sample frames, CRC-8 and CRC-16; the decoder also reads LPC subframes. `sum()` is a check of the samples (two FNV-1a hashes and the count). The same samples always give the same bytes.
- **The studio** (takes.js): `siteSync()` when a post opens (takes from the site listed as `remote` without their recording, takes removed elsewhere dropped, the site's numbers taken, a take reviewed in this browser too kept once with this browser's recording, the later plan kept, takes not yet on the site queued); `siteSend()` after each review (encode in the worker, start, `sendPieces()` with up to three tries a piece, done), oldest first, one at a time; `siteFetch()` from Combine takes' `ensure()` (decoded in the worker and refused unless its check matches); Remove and Clear the shelf on the site too ("Click again to remove it on every computer", "Click again to clear the shelf on every computer"); a take from another computer marked "from another computer" in Combine takes. The review's `#tpShelfSite` line says how the send is going. A phone sends and never fetches; the practice reading never uses the site. With the setting off again, takes known only from the site leave the browser's shelf.
- **Foxfire Studio, Recordings:** Your take shelf on the site (`#rrt-shelf`, `#rrt-t-shelf`), each take with its post, length, size and when it was recorded, and Delete.
- **Personal data:** an exporter and an eraser (`rrt-shelf`); a person's shelf goes with their account.
- **Uninstall:** with the delete box ticked, `uploads/rrt-shelf`, `_rrt_shelf` and `rrt_shelf_key` go; `rrt_shelf` always goes.
- **The guides:** Record on a phone, Edit and polish a take and Privacy say what the setting does; What's new has 1.34.0 (1.32.0 drops off); `docs/guide/` rewritten.

**Tests:** `tests/unit/flac.test.js` (11: every sample back at normal, phone and changing levels, clipped, silent and noisy audio, odd lengths, other rates, long takes, the same bytes every time, damaged files refused, the check, FLAC's published CRC values; checked by hand against ffmpeg both ways); `tests/php/shelf.php` (56); `tests/browser/shelf.py` (40: two computers and a phone signed in as one person, a take continued and combined across computers with its recording back sample for sample, the plan carried over, Remove and Clear everywhere, the same take kept once, a send cut off and carried on); `tests/php/uninstall-check.php` keeps and deletes the shelf.

---

## [1.33.1] A way back to the practice reading (P)

A small patch agreed with the maintainer on 22 November 2026. Nothing in the database or the settings changes.

**Why:** the practice reading (1.29.0, `/?teleprompter=1&tp_practice=1`) was only offered by New here's Try it and by the setup wizard's last step. Once New here was dismissed, nothing in the studio led back to it, and the guides only named Try it. The Controls panel's Practice section is only drawn when a script is open, so the studio's first screen had no way in at all.

**Added**
- **Practice reading** in the Controls panel, under Practice (views/teleprompter.php `#tpPracticeOpen`, a button with its `data-href` and a "?" from its title), across the whole row under Warm up and Check microphone (`.tp-btn-pair .tp-practice-wide`). studio-core.js opens it in the same tab (one studio holds the microphone; the studio already refuses to leave a tab with an open take), and does nothing while reading or counting in. Not drawn on the practice reading itself.
- **The studio's first screen** keeps "Want a script to try? Open the practice reading" (`#tpEmptyPractice`, `.tp-empty-practice`). It steps aside while New here shows, and comes back with Not now.
- **Your progress** has **Open the practice reading** (`#rrt-progress-practice`, a new tab, on the reader's own page only), saying that its takes show there as A practice reading.
- These three use `rrt_practice_url()` without the automatic microphone check; New here and the setup wizard keep it, for the first time.
- **The guides:** "Make your first reading", step 2, says where the practice reading is the first time and after; "Tools for reading" adds Practice reading to its Practice line. What's new has 1.33.1 (1.31.0 drops off, the three newest kept); `docs/guide/` rewritten.

**Tests:** `tests/browser/setup.py` (+10: the first screen's line steps aside for New here and takes its place after Not now, with no microphone check in its address; Practice reading in the panel with its "?", axe on the Practice section, the same tab, not offered on the practice reading, no automatic check; Your progress's button).

---

## [1.33.0] Other languages (P)

Phase 3's sixth milestone, as agreed with the maintainer on 21 November 2026 (docs/DESIGN.md, section 9zc). Nothing in the database changes, and no setting changes on update. An English site that never chooses another language sees no change in any review, file or page.

**Why:** both Whisper models were English-only, and everything after them assumed English: the review's word matching kept only Latin letters (a Greek or Russian word was wiped out before it was compared), numbers, fillers, the pace bands, How hard's syllables and names, and voice following.

**Added**
- **A reading language for each post** (`includes/languages.php`, loaded right after kinds.php): `rrt_languages()` (about ninety languages Whisper knows that are written with spaces between words, English first), `rrt_languages_later()` (Chinese, Cantonese, Japanese, Thai, Lao, Khmer, Burmese and Tibetan, named but not offered: the review lines up words, and these are written without spaces), `rrt_site_lang()` from WordPress's Site Language (English when it is not offered), `rrt_post_lang( $id )`, post meta `_rrt_lang` (only when the post differs from the site), `rrt_practice_lang()` (the site's language only when the practice reading has been translated into it), `rrt_lang_boot()` (`RRT.lang` in the studio), `rrt_lang_iso3()`, `rrt_post_lang_tag()`. The **Reading language** menu in the Reading audio box, after Kind of reading (hook `rrt_reading_box_lang`), with "Same as the site (...)" first and the languages coming later named under it.
- **The multilingual Whisper models** (`includes/whisper.php`): `RRT_WHISPER_MULTI` (`onnx-community/whisper-base_timestamped`, revision `608c49e6...`) and `RRT_WHISPER_MSMALL` (`onnx-community/whisper-small_timestamped`, revision `65caa70f...`), both checked on 21 November 2026 to carry word-timing heads, all 99 languages and the transcribe task; every file pinned and fingerprinted. `rrt_whisper_boot()` gives `multi` beside the English models; `rrt_whisper_files( 'multi' )` (16 files, about 650 MB with the larger size's graphics chip files); `rrt_whisper_have( $model, $names )`. Settings, Whisper on this site, **Other languages** with **Download the other-languages models to this site** (files.js set `multi`).
- **The worker** (`assets/js/whisper.js`): `cfg.language` makes every piece be heard with `{ language, task: 'transcribe' }`, so Whisper never translates; a pinned revision is used for the multilingual models on the processor too, and an empty fallback is no longer tried.
- **The review** (review.js): `modelCfg()` picks `WHISPER.multi` for any language but English, passes the language and revision; the larger model, Automatic, the graphics chip and the phone rules work the same. Taught words are kept per language (`rrtLearned_<code>` in the browser, user meta `rrt_learned_<code>` through `/rrt/v1/learned?lang=`, in the personal data export and erase, and on uninstall); the words kept before 0.9.0 are imported as English only. The captions file names the language in its header (`Language: es`) outside English.
- **Word matching in every script** (`assets/js/compare.js`): `setLang()` (called by the studio from `RRT.lang` before the script is split into words), `lang()`, `fillers()`. Outside English `clean()` keeps letters, marks and digits of every script, normalised to NFC and lowercased for the language (Turkish's dotted and dotless i); the English sound-alikes, number words, contractions and word endings are left out; a number heard as digits against one written in words (or the other way round) is **Check**; two words that share most of their start are **Check**. Filler sounds per language (`FILLERS_BY`, only sounds that are never words there) plus the shared hums. English keeps every rule exactly as it was.
- **The studio** (studio-core.js): `studio.READ_LANG`; sentence ends also at the Arabic question mark, the Devanagari danda and the Urdu full stop; a script without Latin vowels is weighed by length for the timed pace. **Follow my voice** on a post in another language shows switched off with "English readings only for now" (`#tpVoiceLater`), keeping the reader's choice for English posts (`studio.VOICE_LATER`).
- **Pace outside English** (`studio.paceJudge()`, `studio.langPaces()`, `studio.langPaceKeep()`; readout.js): with no kind of reading, the pace is a number until three full reviews in that language (`rrtLangPace_<code>`, the last ten), then judged against the reader's own average, 10% either side. The general bands stay English.
- **How hard** (`rrt_backlog_measure()`'s fourth argument): outside English a very long word is one of `RRT_BACKLOG_LONG_WORD` (13) letters or more; names are not counted in German or Luxembourgish; sentence ends as in the studio. `rrt_note_key()` keeps the letters of a word in another script (or with ß); every other word is keyed exactly as before.
- **The feed, the player and the MP3:** the episode's `podcast:transcript` language is the reading's; the player's captions track and Transcript list say the reading's language when it differs from the site's, and read along cleans words in it (player.js); the clip maker hears a reading without captions in its language (clip.js `whisperCfg()`, `C.lang`); a reading in another language gets an ID3 `TLAN` frame and its language on the comment (id3.js, `rrt_mp3_tags()`'s `lang`).
- **Help:** What's new for 1.33.0, and the Read the review, Browsers and phones and When something goes wrong guides.

**Changed**
- **The end of the script with Follow my voice off** (studio-core.js `reachEnd()`): the script scrolling at the pace can reach its last word before the reader does, and on a post in another language Follow my voice is always off, so the recording no longer ends there. The script stops on its last word, the recording keeps going (`studio.heldAtEnd`, body class `is-held`, the wake lock kept), the main button reads Finish and the message says to press Finish recording after the last word. Finish recording or the main button ends it; moving in the script makes it an ordinary paused take; Continue cannot start a new take over it (`beginFrom`). Following the voice, the take still ends by itself two seconds after the last word. Found in the maintainer's live check of a Spanish reading.
- **An accent left off a short word** (compare.js `bare()` in `relation()`): outside English, a heard word that differs from the script only by accents counts as the same word ("rio" for "río"). A long word already passed with one letter changed, but words of three letters or fewer never count as alike, so the last word of a Spanish reading was listed as added and the script's word left as not reached, and the take's paragraph counted as in no take. Only the combining accents of Latin, Greek and Cyrillic letters are set aside; the vowel signs of other scripts stay. English is unchanged. Found in the same live check.
- **The end of the script on a phone** (studio-core.js `heldGoal()`, `heldCover()`): when the script stops at its last word and waits for Finish, it scrolls back so the last paragraph is in view above the message: all of it when it fits, or else as much of its end as fits. On a phone the reading line sits near the top, so a reader a few lines behind lost the words they had still to read. Found in the maintainer's iPhone check.
- **The pace line outside English** (studio-core.js `paceLine()`): where Follow my voice cannot be switched on (a reading in another language, or a browser without it), the line says to pause at each full stop or lower Pace, without offering Follow my voice.
- **The studio's scripts carry their file's time** (`rrt_register_script()` in includes/i18n.php): the version is `RRT_VERSION` plus the file's modified time, as the admin styles already were, so a browser that keeps scripts for a long time (Safari on an iPhone) fetches a changed file at once. Found when the iPhone kept the first build's studio after a same-number rebuild.
- **The plugin's screens at phone width** (assets/css/dashboard.css): at 782 px and narrower, the plugin's tables scroll inside their own box, menus and fields keep to the screen, Pronunciation's filters and the Reports phone preview shrink, and two-column layouts fold to one. In Safari a wide table had let the whole page slide sideways on 13 of the plugin's 19 screens. Test: `tests/browser/phone_admin.py` (20).

**Also**
- Download counting's two lists refreshed (they were not refreshed for 1.32.0).

**Tests:** `tests/unit/languages.test.js` (12: English unchanged, Greek, Russian, Turkish, Hindi, combining accents, Spanish and Russian reviews, fillers per language, numbers as Check, the worker asked for the language and the pinned revision, the clip maker's swap and words), `tests/php/languages.php` (53: the list and tags, the site's and each post's language, the box and its save, the models pinned and fingerprinted, How hard, word keys, taught words per language with export and erase, the feed, the player and the MP3), `tests/browser/languages.py` (29: a stand-in Whisper that answers only the right model, revision and language; English, Spanish and Russian reviews; a Spanish filler; the pace until and after three takes; Follow my voice; the Controls panel at 393 px; the end of the script keeping the recording going until Finish). The uninstall checks seed and look for `_rrt_lang` and `rrt_learned_fr`.

---

## [1.32.0] Help and docs (P)

Phase 3's fifth milestone, as agreed with the maintainer on 21 November 2026 (docs/DESIGN.md, section 9zb). Nothing in the database changes, and no setting changes on update.

**Why:** everything written so far explained one control at a time (the studio's ? buttons, field descriptions) or was written for people reading the code. Nothing answered "how do I do this?" for someone using the plugin, and README's browser table still described 1.29.0.

**Added**
- **Foxfire Studio, Help** (`includes/help.php`, page `rrt-help`, last in the menu, for anyone who can edit posts): fourteen short guides, from Make your first reading to Keyboard shortcuts, each saying which screen, section and button to use, with buttons to the screens they name. What's new sits at the top, then a search box that only shows and hides the guides already on the page (`assets/js/help.js`: every word must match, the count is announced, Escape clears it), then the list of guides. Arriving at a guide moves the focus into it.
- **The words in one place:** `includes/help-text.php` (`rrt_help_topics()`, `rrt_help_news()`, `rrt_help_screens()`), every line translatable, kept apart so **`tools/help-export.php`** can write the same guides into `docs/guide/` without WordPress; `--check` fails when they differ, and `tests/php/help.php` runs it.
- **Help on every plugin screen:** WordPress's Help tab names the screen's guide and links to it, with a sidebar link to every guide (`current_screen`, `rrt_help_screen_topic( $screen )`, by page slug or post type; a reading's own page opens the clips guide). A Help link sits beside each screen's heading, after any Add New button; the script marks the heading `wp-heading-inline` and adds a `wp-header-end` marker so WordPress moves notices below it. The setup wizard, the Help screen itself and WordPress's own screens get neither.
- **The studio:** Help and guides at the foot of the Controls panel (`#tpHelpLink`), opening the first guide in a new tab so an open take is never put at risk, named "(opens in a new tab)" for screen readers.

**Changed**
- README's Browser support table now has Safari on a Mac and on iPhone and iPad as columns, with the larger model, clips and full videos as rows, from 1.30.0 and 1.31.0; README, docs/SETUP.md's Troubleshooting and readme.txt point to the guides, and readme.txt's FAQ starts with Where is the help?

**Not built, by choice:** a public docs site (ready in `docs/guide/`, to publish with GitHub Pages when the clean repository goes public) and short videos (to be made from the test site before going public, kept out of the plugin zip).

**Tests:** `tests/php/help.php` (27: every guide well formed, no long dashes or web addresses, every control a guide names still exists under that name, each screen's guide, the Help tab, the menu, the screen and who may read it, the studio's link, docs/guide up to date; also on WordPress 6.0), `tests/browser/help.py` (27: the search, focus on arriving, axe-core on the Help screen, the link beside five screens' headings and after Add New, above any notice, the Help tab, none on Posts, the studio's link in a new tab and the panel at 393 px).

---

## [1.31.0] Every phone (P)

Phase 3's fourth milestone, second half, as agreed with the maintainer on 19 and 20 November 2026 (docs/DESIGN.md, section 9za). Nothing in the database changes, and no setting changes on update.

**Why:** one guess from the browser's name (`IOS`) decided everything a phone could do. A 16 GB iPad counted as a small phone, an Android phone in Firefox counted as a computer and started Whisper by itself, and clip.js kept its own copy of the guess. Every phone review waited to be asked, even for a 30-second take, and held the take's untrimmed full-rate copy in memory while Whisper listened. New readers ran ahead of the timed pace without knowing it waits for nobody. In 1.30.0's live check, Safari on the iPhone would not preview the 76 MB full video in the page, the review's Pace line said 129 wpm beside a 131 wpm tile, and the Room line listed "0:18, 0:18".

**Changed**
- **One rule, in can.js:** `RRTCan.phone( a )` (touch-first: `pointer: coarse` with no hover, in any browser) and `RRTCan.lowMem( a )` (a memory hint under 4 GB, or a touch-first device with no hint). studio-core.js sets `studio.PHONE` and `studio.LOW_MEM` from them, and clip.js `whisperCfg( cfg, nav, answers )` asks the same rule, so the studio and the clip maker always agree. No browser name is read for either.
- **The Model menu shows on every device.** On a phone its Automatic entry reads "Automatic (standard model)", and `wantModel()` gives the standard model under Automatic on a phone or a small-memory device; Larger chosen by hand may use the graphics chip there (`gpuCheck()` is no longer skipped). A phone whose page closed during a review with Larger goes back to Automatic on the next visit.
- **The lighter phone review.** `startReview` puts the take in a holder; `runReview( hold, ... )` on a phone or small-memory device closes voice following's worker first (`studio.closeVoice()`, voice.js) and, for a take too long for the shelf, lets the untrimmed full-rate copy go once the 16 kHz copy is made, also from the MP3 step's result (`studio.lastMp3.heard`; `studio.reviewHeldFull` says which). The MP3, the cleaned take behind it (for editing) and the copy kept for recovery are untouched; Hear the polish's Cleanup switch, which cleans the untrimmed copy again, is then not offered for that take.
- **Short phone takes review by themselves:** up to `studio.PHONE_AUTO_S` (300 s). Longer takes, a take in phone take mode (already on the site for the computer) and a phone whose last review closed the page keep today's choice: Send to my computer, then Try it here anyway.
- **The shelf on phones.** `studio.shelfWants( seconds )` keeps every take on a computer and takes up to five minutes on a phone, so Continue works there; Combine takes stays a computer tool, hidden on a phone, and the shelf line says so. Waiting phone takes are still offered on computers only (`offerSent`).
- **The pace line.** On the practice reading and on a browser's first take (until a take is finished there, `rrtTookOne`), the timed pace says "The script moves at N words a minute and does not wait for you. Pause at each full stop, or switch on Follow my voice (Controls panel, Reading)." (`studio.paceLineWanted()`, `studio.paceLine()`).
- **New here** offers Follow my voice as a tick (`#tpWelcomeVoice`) with the size of its model, switching the same setting as the Controls panel's switch. The default stays off.
- **The full video on a phone:** the card shows no player, only where the file is (Files, Downloads, or the browser's downloads) and Download again. Clips keep their preview.
- **The review's Pace line** uses the review's own number (`studio.paceFromReview( metrics, R )`: the words Whisper heard from first to last), the same as the At a Glance tile; the read-out at Stop keeps its quick estimate.
- **The Controls panel never scrolls sideways** (`overflow-x: hidden` on `.tp-panel`), and its menus shrink to their row (`.tp-panel .tp-field select`). In Safari on the iPhone a menu kept the width of its longest choice, so the panel was wider than itself and slid left under a finger ("ONTROLS" cut off). Chrome shrank the menus, so it never showed there.
- **The Room line's stray sounds** are listed once per shown second (`RRTReadout.strayTimes`); the count stays true.

**Tests:** `tests/unit/can.test.js` (phone and small memory for seven devices), `tests/unit/clip.test.js` (`whisperCfg` follows the rule, an Android phone in Firefox), `tests/unit/readout.test.js` (the stray list), `tests/browser/faster.py` (+15: a touch device at iPhone width counts as a phone, the menu and its label, Automatic and Larger, a short take reviewed by itself and shelved, a longer one waiting and letting its copy go, Combine hidden, back to Automatic after a closed page; the Pace match), `tests/browser/setup.py` (+6: the pace line, New here's tick), `tests/browser/video.py` (+2: the phone card), and phone.py and livetake.py now make their iPhone a touch device.

---

## [1.30.0] Every browser (P)

Phase 3's fourth milestone, first half: "Every browser and phone" was agreed with the maintainer on 19 November 2026 as two releases, 1.30.0 for browsers and 1.31.0 for phones (docs/DESIGN.md, section 9z; the research is in docs/RESEARCH.md). Nothing in the database changes.

**Why:** after 1.29.1, Firefox made complete clips and videos, but only in Baseline profile (about a quarter larger for the same picture) and only with Opus sound, which Instagram refuses and Spotify and YouTube only tolerate. mp4-muxer, which wrote every MP4, is deprecated in favour of Mediabunny by its own author and cannot store frames that arrive out of display order. Messages still said clips "need Chrome or Edge", which is wrong for Safari 26 and goes stale with every release. And Automatic trusted the graphics chip check alone: Firefox on a laptop passed it and took about 4.7 times the take's length.

**Changed**
- **Mediabunny writes clips and full-length videos** (`assets/vendor/mediabunny/`, Mediabunny 1.66.2, MPL-2.0, with its README.txt and the files' SHA-256 in `rrt_clip_vendor_hashes()`), in place of mp4-muxer 5.2.2 (removed). The page keeps its own `VideoEncoder` (its settings, the graphics chip first, and its pacing by the encoder's `dequeue` and `MessageChannel` from 1.18.0) and hands each finished piece to Mediabunny's `EncodedVideoPacketSource`, which works out decoding times itself, so Firefox's reordered High profile frames are stored as they come (the file carries a `ctts` box) and every browser uses High profile again. The shared writer is **`RRTClip.mp4Writer( o, onFail )`** (o: `fps`, `sound`, `stream` for a file opened in private storage or none for memory, `chunkSize`): `addVideoChunk`, `addAudio( data, frames, channels, rate, t )`, `idle()`, `check( frames )`, `finish( frames )`, `cancel()`. Pieces are queued in order and never waited on inside the encoder's callback; a refused piece is reported once, and a file missing any frame is never finished (1.29.1's guard, now part of the writer). The full video writes through Mediabunny's `StreamTarget` straight into the opened file, which Mediabunny closes when it finishes. 1.29.1's `inOrder`, `firstInOrder` and `muxGuard` are gone; **`RRTClip.firstSupported( tries )`** picks the first setting the browser accepts.
- **AAC in every browser.** The sound goes to Mediabunny as raw samples (`AudioSampleSource`) and Mediabunny encodes it with the browser's own AAC encoder where there is one. Where there is none (Firefox on every system, Chrome on Linux), **`@mediabunny/aac-encoder` 1.66.2** (FFmpeg's AAC-LC encoder as WebAssembly, MPL-2.0 with FFmpeg's LGPL-2.1, about 1.1 MB) is loaded from beside Mediabunny (`RRTClip.aacUrl()`, or `window.RRTClipAac` from the studio) and registered, only then. Opus stays as the last resort, with a message saying the AAC encoder did not load. **`RRTClip.pickSound( channels, aacBps, opusBps )`** gives `{ codec, bitrate, sound, addOn, lead }`; results carry `addOn`.
- **No edit lists.** Mediabunny writes an edit list for a track whose first piece is not at 0, and an AAC encoder's first piece starts 1,024 samples (about 21 ms) before the first sample. Spotify's and Instagram's specs refuse edit lists. **`RRTClip.soundLead( codec, channels, bitrate )`** encodes a little silence once a page to find that offset, and the writer hands the samples over that much later: the first piece sits at 0, neither track has an edit list, and the sound plays at most about 21 ms after the picture (Spotify allows 50 ms). **`RRTClip.editLists( bytes )`** and **`RRTClip.fileEditLists( file )`** count the tracks with an edit list (inside `moov` only); clip and video results carry `edits`. A browser whose encoder hands every piece back later than its frame (Firefox on Windows: one frame late, found in the live check) would still get an edit list on the picture, so `mp4Writer` takes the first piece's time off every piece when it is above 0.
- **Safari on iPhone (found in the live check).** Making the full video there stopped with "the encoder lost 1 of 7505 frames". `mp4Writer` now has **`settle( frames, wait )`**, called after the encoder's `flush()` and before it is closed, which waits up to 2 s for pieces that arrive after the flush; and **`check( frames )`** allows a tiny loss in a long file (at most 1 in 1,000, at least 1 from 300 frames) when the first piece was a key frame. Every piece keeps its own time, so the frame before a missing one stays on screen a frame longer and the sound is unaffected; results carry **`lost`**, and the clip's and video's cards say how many frames were skipped. A large loss (Firefox's before 1.29.1) is still refused. The clip maker's Whisper worker is now ended as soon as it has the clip's word timings (`stopWhisper()`), since on an iPhone its memory and the clip's together can make Safari close and reload the page.
- **One check of what the browser can do**, `assets/js/can.js` (`window.RRTCan`, handle `rrt-can`): `now()` (a video encoder, WebAssembly, the MP4 writer, private storage with `createWritable`, lossless capture, element full screen, the graphics chip's API, touch-first, the memory hint) and `check()` (H.264, the browser's own AAC, a real graphics chip with half precision, storage kept for good), asked by tool and never by name or version. `why( 'clips' | 'video', answers )` names the missing tool and the browsers that have it ("This browser has no H.264 video encoder, so it cannot make clips here. Chrome, Edge, Safari and Firefox on a computer can."), in place of "Clips need Chrome or Edge (version 94 or later)" and "Making a video needs Chrome or Edge on a computer". `C.supported()` and `V.supported()` ask it. `line( answers )` gives the **This browser** sentences, shown in Settings (Whisper, This browser, `#rrt-this-browser`) and in the studio (Controls panel, Recording, `#tpThisBrowser`), filling any `[data-rrt-can]` element: where the AAC comes from, whether the graphics chip can run the larger model, and only where they apply, no lossless capture, no full screen for pages (the prompter view stays in the page, as on iPhone), and storage the browser may clear after a long break (Safari does so after about a week).
- **Automatic times the larger model.** whisper.js's worker now reports each piece as it is heard (`{ type: 'piece', i, n, a, b, chunks }`) and can start from a point (`cfg.fromS`). With Automatic on the graphics chip, the studio times the second piece (the first also warms the chip up); if it took longer than its own length (`studio.gpuPaceLimit`, 1), the browser remembers it (`gpuSlow` in `rrtTeleprompter`) and, when pieces remain, the standard model hears the rest from there; the words of both join in time order. The result says "the larger model ran slower than the take on this browser's graphics chip, so the standard model finished the review". From then on Automatic uses the standard model in that browser; choosing Automatic again in the Model menu clears it. Larger chosen by hand is never timed. `transcribeWith` takes a fourth argument (`judge`, `fromS`).
- **The practice reading always records.** Record my voice is switched on for the practice reading's page when the browser has it off (`studio.recPractice`), and the status line says "Recording is on for this practice." The browser's saved choice is left alone; a choice made on the page is saved as usual, and switching it off there says the practice will end with no review.
- **Wording:** the Combine takes and Continue tooltips speak of "this reading's takes" and "the next part of this reading" (posts, sponsor messages and the practice reading alike), and Combine's message of "parts of the script".

**Tests:** `tests/unit/clip.test.js` (the writer with a stand-in Mediabunny: every frame, a refused frame, a short file, the sound's lead; edit lists counted inside `moov` only), `tests/unit/can.test.js` (new: answers and messages for stand-in browsers), `tests/unit/whisper.test.js` (the piece messages; `fromS`), `tests/php/video.php` (Mediabunny and the add-on match their fingerprints, mp4-muxer gone, the studio told where they are), `tests/browser/video.py` (AAC from the add-on, High profile, no edit list, no Opus warning; the MP4 reader moved to `tests/browser/mp4box.py`), `tests/browser/suggest.py` (the clip: AAC, High profile, no edit list), `tests/browser/faster.py` (+7: the hand-over, the words joined, remembered, cleared), `tests/browser/setup.py` (+5: the practice reading records, the saved choice kept, the tooltips). New **`tests/browser/firefox.py`** with **`tests/run.sh firefox`** (local only, CONTRIBUTING.md, Testing in other browsers): a clip and the full video made in Firefox 160, every frame, High profile with reordered frames, AAC from the add-on, no edit list.

---

## [1.29.1] Firefox's video frames, numbers in the review, and the practice reading's words (P)

A patch for four faults found by the "Every browser and phone" research on 19 November 2026, in released code. Nothing in the database or the plugin's folder changes.

**Fixed**
- **Clips and full-length videos made in Firefox kept only about a third of their frames, and the page still said they were ready.** Firefox's H.264 encoder uses B-frames in High and Main profile (on Linux and on Windows), so its pieces arrive in decoding order, with times that go back. mp4-muxer 5.2.2 refuses each such piece with "Timestamps must be monotonically increasing", and it does so inside the encoder's `output` callback, where an exception is never seen by the code that started the encoder: the render carried on, finished the file without those frames and reported success (measured: 190 of 540 frames in an 18 s square clip, 186 of 567 in a 16 s full video). Now:
  - **`RRTClip.inOrder( cfg )`** encodes 16 moving frames with a setting and says whether the pieces came back in display order (kept for the page, by codec, size, hardware choice and latency mode). A trial that cannot run says nothing either way, and the setting is used as before. **`RRTClip.firstInOrder( tries )`** returns the first setting the browser supports whose frames come back in order; the clip maker's `pickCodecs` and video.js's `pickVideo` both use it, so Chrome, Edge and Safari keep High profile and Firefox gets Baseline (`avc1.42e028`), which has no B-frames.
  - **`RRTClip.muxGuard( muxer, onFail )`** wraps `addVideoChunk` and `addAudioChunk`: a refused piece is caught and counted, the first reason stops the render at its next batch, and `check( frames )` throws unless every drawn frame went into the file. The clip and the video then say "a piece of the video could not be written (...), so the file was not finished" or "the encoder lost N of M frames". The clip's result and the video's now also carry `codec` and `frames`.
  - Checked by hand in Firefox 160 and Chrome on Linux with the real clip maker and video writer: square, vertical and wide clips and a full video, every frame in each file (ffprobe `-count_frames`); Firefox with the old picking forced on now refuses to finish rather than saving a short file.
  - Firefox's sound is still Opus, with the existing message; AAC for Firefox belongs to the "Every browser and phone" milestone.
- **The review flagged numbers that were read correctly.** Whisper writes numbers as digits ("1200", "14th") even when they are read as words, and `norm()` only turned a lone number into one run of words ("1200" as "one thousand two hundred", never "twelve hundred"), which could not match two script words, and left ordinals alone. Every first practice reading met it ("twelve" Skipped, "hundred" Misread as "1200", "fourteenth" as "14th"). New in compare.js: **`numberSpellings( text )`** lists every way a number written in digits may be read (with and without "and", "twelve hundred" for 1100 to 9999, years such as "nineteen eighty four" and "nineteen oh five", "a hundred" and "a thousand", ordinals from 1st to 999999th, and a trailing % as "percent"), as the words run together. **`reshapeHeard`** takes the script's raw keys as a fourth argument: a heard number is split into one heard word per script word where the script spells it out, and heard words that spell a number the script writes as digits are joined into it. A different number is still a misread.
- **A restart at the start of a reading counted as a pause inside the title.** Heard words that are not in the script (a restart, a repeat, an added word) sit between two script words without being silence, but the pause between those two words was measured from one to the other. In a practice take that began "A practice reading. Welcome to your practice reading. ... Welcome to your first practice reading", the title's words were matched across the restart, and the title was flagged Hesitant. Pauses of a second or more are now measured as the longest silence between heard words (`silence()` in `compareReading`), for the Hesitant and Long pause flags and the pause map. (The title already ends a sentence of its own.)
- **The practice reading's messages spoke of a post.** After a take the result line said "Press Use this recording to make it this post's reading..." (record.js, now `afterLine()`); it now says the practice take belongs to no post and is kept with your other takes. The shelf's lines say "the practice shelf", and the review's takes table says "Read the practice reading again" (takes.js, review.js).

**Tests:** `tests/unit/compare.test.js` (+7: numbers both ways, years, a hundred, percent, a different number, the spellings, the restart and a real pause), `tests/unit/clip.test.js` (+3: the guard), `tests/browser/video.py` (every frame is in the file), `tests/browser/setup.py` (the practice review never speaks of a post).

---

## [1.29.0] The setup wizard (P)

Phase 3's third milestone: first-run steps for a new site, and a practice reading anyone can try in the studio. Designed with the maintainer on 19 November 2026 (docs/DESIGN.md, section 9y).

**Why:** after Activate nothing happened. Settings works from its defaults, but it is one long page with more than fifteen sections and nothing says which few choices matter on the first day. Check microphone only appears once a post is open, so a new reader's first take was usually made with an unchecked microphone; a fresh site may have no post worth practising on, and practising on a real post puts practice takes on its shelf. The choice to keep Whisper, the encoder and the fonts on the site (so the studio makes no outside requests) sat at the bottom of Settings. The player starts as an editors-only preview, which a new owner could take for a broken player.

**Added**
- **Set up** (Foxfire Studio, then Set up; `admin.php?page=rrt-setup`), for administrators, in the new `includes/setup.php`, loaded right after admin.php. One step per screen, plain forms that work without scripts, with a step list, "Step N of 6", **Next** (saves the step), **Skip this step** and **Back**: Welcome (Start or Skip setup), Your show (the show name, and the display name readings are credited to), The look (the two presets with a sample of each, the studio's look, the Readability check with Make it readable), Readings and the player (which kinds of posts, who sees the player, where it goes, with how to place the block or `[foxfire_player]`), Privacy and files (keep takes on this site; one button that downloads Whisper, the encoder, the noise model and the fonts, with voice following and the larger model as unticked extras; the seven switches that touch visitors listed with what each does, where it lives and whether it is on, and the Privacy Policy Guide), Your first reading (opens the practice reading in a new tab), and Done (where to go next).
- **Only what a step shows is written.** `rrt_setup_apply()` lays the step's fields over the current settings, checks them with `rrt_sanitize_settings()`, and `rrt_setup_write()` copies only the step's settings into the saved option, and only those that differ. The option's own sanitizer expects the whole Settings form (a missing box means off), so it is set aside for that one write. Going through every step on a site that is set up already changes nothing unless a value is changed.
- **The notice:** on a new install, on the Plugins screen and Foxfire Studio's own screens, for administrators: "Foxfire Studio is ready." with **Set up** and **Not now** (hides it for good; Set up stays in the menu until setup is finished or skipped). No redirect on activation.
- **New installs only.** The option `rrt_setup` (not autoloaded: status, each step's done or skipped, the notice hidden, when it finished) is written once, by `rrt_setup_migrate()` at the start of installation and early on every load: a site that already has saved settings or the plugin's tables is marked existing and never offered setup. Once setup is finished or skipped, its screen leaves the menu (it hangs under `options.php`) and Settings gains **Run setup again** at the top (the new action `rrt_settings_top`).
- **The practice reading:** a short built-in script (`rrt_practice_paragraphs()`, about 150 words: plain sentences, a long one with a comma pause, a question, a name and numbers), read in the studio at `?teleprompter=1&tp_practice=1` with no post. With `tp_check=1` (from the wizard) Check microphone opens by itself once the page is free. Use this recording and the post's tools are not offered. The take is saved with the reader's other takes as post 0, so Your progress has a starting point; it is never on any post's shelf, and Your progress names it "A practice reading" (`rrt_take_title()`).
- **New here:** a reader new to a browser, with no microphone check saved, is offered the practice reading once, on the studio's first screen ("New here? Check your microphone and try a one-minute practice reading.", Try it, Not now) and beside Check microphone on a post. Not now, a microphone check or opening the practice reading ends the offer in that browser (`rrtWelcomed` in `localStorage`).
- **`assets/js/files.js`** (`window.RRTFiles`): the downloader for the studio's files, moved out of the Settings screen so Settings and the wizard share it. Settings' buttons work as before.

**Changed**
- The takes route (`rrt/v1/takes`) accepts post 0 for practice takes, the reader's own like every take. Every other route still needs a real post the reader may edit.
- A recovered practice take goes back to the practice reading.

**Tests**
- `tests/php/setup.php` (69): which sites are offered setup, the notice and who sees it, Not now, the menu entry, each step writing only its own settings and nothing on a site set up already, Next, Skip this step, Back, Skip setup, Finish, the nonce and who may use it, each step's screen, Run setup again, the practice script, practice takes as post 0 (and never on a post, nor a recording on post 0), and uninstall.
- `tests/browser/setup.py` with `tests/wp/setup.php` (82): the whole wizard from the keyboard only on a site made new, axe-core on the notice and every step, each step at 393 px with a screenshot, the downloads answered by the test, Settings afterwards, an editor kept out, Settings' own download buttons, the practice reading (Check microphone by itself, the script, no Use this recording, a stand-in take's review kept as post 0) and New here. It also runs in `tests/run.sh oldest`.
- `tests/upgrade.py` and `tests/php/upgrade-check.php`: an upgraded site is not offered setup.

No database change. Nothing new is collected from visitors; PRIVACY.md gains the practice take and the `rrtWelcomed` mark. Download counting's lists refreshed (the data centre list's edition 2026-11-19; the user agent list unchanged).

---

## [1.28.0] The player block (P)

Phase 3's second milestone: a block for the block editor and the Site Editor, a shortcode with the plugin's own name, and placement rules so the player never shows twice. Designed with the maintainer on 19 November 2026 (docs/DESIGN.md, section 9x).

**Why:** the only ways to place the player by hand were the shortcode, under the plugin's first name, and `rrt_player()` in theme files, which block themes do not have. A post holding the shortcode while Where was At the top of the post showed two players; several players on a page could all play at once; read along on a page of several readings could follow the wrong text; the shortcode showed no preview note while the player was for editors only. On block themes before WordPress 6.4 the post content is drawn outside the main loop, so the top-of-post player may not appear there at all.

**Added**
- **The Reading player block** (`foxfire-studio/player`, `blocks/player/block.json`, block API version 3, in the Media group; keywords listen, audio, podcast, reading, Foxfire). A dynamic block drawn by `rrt_block_render()` in the new `includes/block.php`, loaded right after player.php: a post stores only the block's name and its one attribute, `reading` (0 for the post it sits in). It takes its post from the block context (`postId`), so it works in the Site Editor's templates and inside a Query Loop; in a widget area it shows the reading of the post being viewed, and nothing on other pages. Alignment (wide and full) and margin from WordPress.
- **The editor side** (`assets/js/block-player.js`, plain JavaScript on WordPress's editor packages, no build step): a Reading panel in the sidebar (This post's reading or Another reading, with a search box listing posts and collections that have a reading), the preview through WordPress's block renderer (`.rrt-block-preview`, `inert`, with "Plays on the published post."), a card for a post with no reading yet (Open the studio, Choose another reading), a line when the player is off or for editors only with a link to Settings, and a transform from either shortcode. Its words are translated with `wp_set_script_translations`.
- **`[foxfire_player]`**, with `post="123"` for another reading. `[reading_room_player]` keeps working for good.
- **`rrt_player_placed( $post, $source )`**: every placement (the block, both shortcodes, the top of a post or a collection) goes through it for the visibility setting, the preview note and the once-per-page rule (`rrt_place_once()`). A reading drawn from one place (the content, a widget area, the template) is not drawn from another on the same page; the same place may draw it again, since some themes and plugins run a post's content through `the_content` more than once.
- **`rrt_player_placed_by_hand( $post )`**: true when the post's content holds the block or either shortcode for its own reading (in any nesting; escaped `[[...]]` shortcodes are text), or the page's block template does (`$_wp_current_template_content`). `rrt_blocks_place()` walks parsed blocks.
- **The route `rrt/v1/block/readings`** (GET, for people who can edit posts): `search` or `include`, at most 20; each reading's id, title, type, status, length, whether it has a reading, and the studio's address for posts the person may edit; with who sees the player and, for administrators, the Settings address.
- `rrt_player_on_own_page( $post )`, `rrt_block_types()`, `rrt_block_readings()`, `rrt_block_previewing()`; constants `RRT_BLOCK` and `RRT_SHORTCODES`.

**Changed**
- **The top of the post** (player.php and collections.php) is left out when the post or its template places the player by hand.
- **One player plays at a time** (player.js `pauseOthers`): starting one pauses any other on the page, and the lock screen follows the one playing.
- **Read along only on the reading's own page:** the player's `follow` is on only when the page shown is the reading's post. Transcript works everywhere.
- **The preview note** ("Preview: only editors see this player...") shows wherever the player is placed while it is for editors only, the shortcode included.
- **Settings, Player, Where:** "At the top of the post, unless the post or its template places it" and "Only where I put it: the Reading player block, the [foxfire_player] shortcode, or rrt_player() in the theme".
- The player's stylesheet is registered on `init` (handle `rrt-player`) and is the block's editor style, with the look's colours, so the preview inside the editor's frame matches the site.

**Tests**
- `tests/php/block.php` (55): registration, the block in a post and for another reading, manual placement, a block for another reading beside the top player, the template and the content, content run twice, a block inside a group, both shortcodes and an escaped one, off, editors only with the note, drafts, passwords and ids that are not readings, widget areas on a post and on a list, the editor's preview through the block renderer, the readings route and who may use it, Settings' wording.
- `tests/browser/block.py` with `tests/wp/block.php` (28 per theme): as a visitor, the block where placed, read along on its own post, the top kept without it, two chosen readings with Transcript and one playing at a time, axe-core on the blocks; in the editor, the card with Open the studio, both shortcodes turned into the block, Another reading chosen from the sidebar's search, the preview, axe-core on the sidebar, the saved post playing the chosen reading, and an existing block opening with no warning. run.sh runs it with the site's own theme (a block theme) and with Twenty Twenty-One (classic), and also in `tests/run.sh oldest` on WordPress 6.0.

No database change. Nothing new from visitors (PRIVACY.md unchanged).

---

## [1.27.0] Accessibility: the studio (P)

The second half of Phase 3's accessibility milestone: the studio, the review, the editor and Combine takes checked against WCAG 2.2 AA (the findings, S1 to S10, are in docs/DESIGN.md, section 9w). With 1.26.0 the whole plugin now aims to meet WCAG 2.2 AA; see docs/ACCESSIBILITY.md.

**Why:** axe-core reported nothing on the studio, while a keyboard user could not tick one switch there. The page's keydown handler blurred any focused switch, slider or button and acted as the teleprompter: Space on a switch started the countdown, the right arrow on Size raised the pace, Space on Check microphone did nothing. While reading, the bars faded to 8% even around a focused control. Dialogs opened without moving focus. Words could only be clicked. Over forty controls explained themselves only in hover tooltips, which phones and keyboards never show.

**Added**
- **Where keys act** (studio-core.js): `studio.onScript( el )` (the script, the stage or the page itself), `studio.typing( el )`, `studio.isControl( el )`, `studio.toScript()`, `studio.keysOn()`. The script (`#tpTrack`) is one Tab stop (`tabindex=0`, `role=region`, named Script, described by the keys paragraph `#tpKeysHelp`). Its current word shows a 2 px ring while it has focus.
- **Single-key shortcuts** switch (`#tpKeys`, Controls panel, Reading; browser storage `rrtTeleprompter.keys`, on by default). prompter.js's P, B, full stop, plus and minus and the review's [ and ] act only while it is on and focus is on the script.
- **One dialog helper** (`studio.dialogOpen( box, escape )`, `studio.dialogClosed( box )`, `studio.dialogTop()`): `role=dialog`, `aria-modal`, named by its heading, focus to the heading (tabindex -1) as it opens, Tab and Shift+Tab kept inside, Escape presses the box's `[data-dlg-close]` button (never while a take is recording), focus back to what opened it (or the script). The four page dialogs (`#tpResult`, `#tpRecover`, `#tpCarry`, `#tpSent`) are watched by their `is-on` class; Check microphone, Warm up, Share for feedback and the cue menu by being added to the page.
- **A word cursor.** In the teleprompter with Cues on, left and right move a word (Shift five; the pace keys rest), the word is read out, and Enter opens its cue menu; with Cues off, Enter starts from the word when paused. In the review the script (`#tpRvScript`) is one Tab stop (`role=region`, described by a hidden line): left and right move word by word (`.is-cursor`, each word read out with what was heard and its state), Home and End, Enter plays from the word (`_rrtPlay` on each word and added-word tag), with Edit audio on Shift and the arrows start or grow a selection from the cursor, Delete cuts, and Enter on a cut or retaken word does what a click does.
- **Help beside controls** (`studio.help( ctrl, text, row )`, `studio.helpText()`, `studio.helpAll( scope )`): every control in the script bar, the panel, the ready dialog and the review whose `title` explained it gets a `?` button (`.tp-help-btn`, named "About <control>", `aria-expanded`) that opens the text under it (`.tp-help-text`); the control is described by it. The tooltip stays for mice. Edit audio's text follows its state.
- **The polite line** (`#tpSay`, `studio.say( text )`): "Recording", "Recording stopped", "Your reading is ready", "The full review is ready", and the word under a cursor.
- **Text tags** (`.tp-rv-tag`) at the start of shaded sentences in the review: fast, slow, paraphrased, unfinished; slow shading also has a dotted edge.
- **Words ahead held at 3 to 1:** `RRT_A11Y_AHEAD` 3.0, `rrt_a11y_ahead_pct( $colors )` (42 percent of the text colour, or the least more that reaches 3 to 1 on the page background), `rrt_a11y_ahead()`, `rrt_a11y_with_ahead()`; a pair "Words ahead" in the Readability check; `rrt_tokens_css()` and the Settings look preview use it.
- A hidden main heading with the post's title; `.tp-vh` for text hidden on screen.

**Changed**
- **studio-core.js's keydown:** controls keep their own keys; Escape from a control in the top bar, the script bar, the panel or the dock goes to the script; Page Up and Page Down act from anywhere but a field that takes typing; nothing acts behind an open dialog. In the review and Combine takes, Space plays and pauses only when focus is on no control, and Escape leaves the text fields alone.
- Switches and menus no longer drop focus after a change (no more `blur()`), and Check microphone, Warm up and Share for feedback keep focus to come back to.
- **While reading**, the top bar, the script bar and the panel come back to full strength while they hold focus (`:focus-within`).
- The cue menu is a dialog of buttons (`role=dialog`).
- The review's "Review" is a second-level heading (`#tpRvTitle`) that takes focus as the review opens; closing it goes back to the ready dialog or the script. The timeline is `aria-hidden` (Flagged moments has the same moments as buttons). "I said it right" and "I didn't say this" are described by what they do.
- The panel's section headings are second-level. The menu's links, the logo, Settings and Your progress say they open a new tab.
- The intro: Enter and Space act on the focused button or tick box, and only skip from elsewhere; Escape skips.
- The panel's keys paragraph and Reading the marks describe the keyboard way.
- Words ahead use `var(--ahead)` in the current word's sweep too.

**Tests:** `tests/browser/a11y_studio.py` (run after a11y.py with the same seed): axe-core at WCAG 2.2 A and AA on the studio with the panel open, during a reading, with an explanation, the microphone check, the cue menu, the ready dialog and the warm-up open, and on the review of a stand-in take; Space on every switch, the arrows on every slider, Escape to the script, the script's keys, Page Down from a switch, Tab during a reading, P on a switch and on the script, the switch for single-key shortcuts, the ? help, words ahead at 3 to 1, the cue menu from the word cursor, the four page dialogs and the warm-up, the polite line, the review's heading, cursor, Enter, Space, selection, Delete and undo, the text tags, and the studio at 393 px with a screenshot. `tests/php/a11y.php` +6 (words ahead). No database or folder change.

---

## [1.26.0] Accessibility: the player and the admin screens (P)

The first half of Phase 3's accessibility milestone: the public player and the admin screens checked against WCAG 2.2 AA (the findings, ranked, are in docs/DESIGN.md, section 9v). The studio follows in 1.27.0. See docs/ACCESSIBILITY.md for what is covered, how it is tested and the known gaps.

**Why:** the player's panel was the studio's dark panel colour at 70%, see-through, so on a light theme it turned mid-grey and its label (3.7 to 1), times (1.8 to 1) and skip buttons (3.1 to 1) fell well short of the 4.5 to 1 text needs. The skip and speed buttons showed "−15s", "+30s" and "1×" but were named otherwise, so voice control could not find them. Read along's fallback list could only be clicked. Focus showed as a faint tint with every outline switched off. Read along dimmed the post to 55% and turned the current word gold, which fails on a light page. Six admin fields had no label.

**Added**
- **`includes/a11y.php`** (loaded right after theme-look.php): `rrt_a11y_pairs()` (ten pairs: text, labels, hints and buttons on the panel and the page background, labels on the review's cards, and the page background as text on the accent), `rrt_a11y_check( $colors )`, `rrt_a11y_all_ok()`, `rrt_a11y_fix( $colors )` (Make it readable: each failing foreground colour, of text, muted, faint and accent, mixed towards white or black by the smallest step in whole percent that makes all its pairs pass; backgrounds and accent2 never change), `rrt_a11y_settings_rows()` (the **Readability** row under Colours in Settings, with a **Make it readable** button named `rrt_settings[a11y_fix]` while any pair fails; never stored), `rrt_a11y_needs_captions( $post_id )`. Constant `RRT_A11Y_TEXT` 4.5.
- **The player's Transcript button** (`.rrt-transcript-btn`, `aria-expanded`, `aria-controls` = `rrt-transcript-<post>`) on every reading with captions, collections included: the lines as buttons (`.rrt-line`, with `.rrt-line-time`), each named "Play from 0:05: words", the line being heard marked `aria-current`. player.js: `loadCues()` (fetched once for read along and the transcript; a failed fetch shows "The transcript could not be loaded."), `setList()`, `showList()` (shown when asked for, or when read along falls back to it), `announce()` and a polite line `.rrt-say` that reads a new speed out. Events `transcript-open` and `transcript-close`.
- **No captions yet:** beside the title on Readings, and as a note on the reading's page saying how to add them.
- **`docs/ACCESSIBILITY.md`.**

**Changed**
- **The player's panel is solid** (`--rrt-panel`), so the colours checked are the colours shown on any theme.
- **The buttons say what they do:** "Back 15s", "Ahead 30s" and "Speed 1×" (`speed` text key now "Speed %s"; the `normal` key is gone), with no separate `aria-label`, so the name is the words shown.
- **Focus:** a 2 px solid ring in the accent colour, 2 px out, on Play, every button, the chapter and transcript lines, and 5 px out on the progress bar.
- **The progress bar:** 6 px, the unplayed part in the hint colour (held at 4.5 to 1 by the check), the position dot always shown with a ring of the panel colour, 24 px to touch.
- **Read along** keeps the post's own colours: no dimming; the sentence being read at a 16% accent tint, the word being read at 30% with a 2 px underline. Seeking marks the new line or words at once, even while paused.
- **Chapters:** `aria-current` on the chapter's button. Times on a tinted line use the label colour.
- **Reduced motion:** read along's page scroll jumps when the system asks for less motion.
- **The Reading audio box:** the Choose and Remove buttons are named for their file ("Choose the reading (MP3)", "Remove the captions"), no label points at a hidden field, the file name is announced when it changes, the media window's title is the button's name, and "None" and "Use this file" are translated.
- **Admin labels:** Your progress's three goal menus ("Goal 1" to "Goal 3"), Settings' Leave out menu and the privacy text, the video upload kit's description. The teleprompter look preview is a named picture. Recordings: a recording whose post was deleted shows "(post deleted)" without a link, an untitled one "(untitled)".
- Settings' Colours description now says the player uses these colours too.

**Tests**
- `tests/browser/a11y.py` with `tests/wp/a11y.php` (49): axe-core 4.14.1 (from npm, pinned by SHA-256) on the player, with its transcript open, and on 13 admin screens, with every WCAG 2.2 A and AA rule reporting nothing (the look preview and the Readability samples left out); the player by keyboard (Tab order, 2 px rings, speed read out, transcript lines play from their moment and the current one marked, read along's fallback list as buttons), the solid panel and its contrast on a light theme, the 24 px bar, read along keeping the post's colours, the box's button names, and Make it readable on a failing set. run.sh block "Accessibility of the player and the admin screens (1.26.0)" after Check private folders (env `A11Y_SEED`).
- `tests/php/a11y.php` (36): the contrast maths, both presets, the check, Make it readable (smallest step, backgrounds and accent2 untouched, darker on a light panel, lighter on a dark page), the settings form, the Readability row, the player's markup with and without captions, and the box. run.sh block "Accessibility (1.26.0), on the server" after the security review's.
- `tests/unit/player.test.js`: the speed wording.

---

## [1.25.0] The security review (P)

The first milestone of Phase 3: the security part of Directory readiness, moved to the front. Every route, upload, file write and saved secret was reviewed on 18 November 2026 (the findings, ranked, are in docs/DESIGN.md, section 9u) and WordPress's own security sniffs were run on every PHP file. Nothing a visitor or a Subscriber could do was found; the fixes below are for sites where more people write posts, and for servers that ignore `.htaccess`.

**Why:** a reading's field can hold a plain address, and anyone who can edit a post could type one into the Custom Fields panel. The short download address handed the file behind it to the web server, which sends a file without obeying its folder's `.htaccess` (confirmed with Apache's X-Sendfile on the test site: an Author's post pointed at a file behind a deny-all `.htaccess` got it sent). Collections and sponsored copies read the same addresses, and storage offload left out the plugin's own private folders but not other plugins' closed ones. Separately, sponsor messages had the permissions of ordinary posts, so an Author could publish one into everyone's readings; members' files kept their guessable old names behind an `.htaccess` that nginx ignores; a reading set to Members only kept its sponsored copy public for the two days older copies are kept; and Share for feedback showed every account on the site to anyone who can edit posts.

**Added**
- **`includes/safety.php`**: `rrt_upload_path_ok( $path, $kind )` (inside uploads by its resolved path, outside `rrt_private_folders()` (`rrt-members`, `rrt-kept`, `rrt-sent`, `rrt-shares`, `rrt-audiobook`, `rrt-dl-check`, filter `rrt_private_folders`), outside any folder whose `.htaccess`, or uploads' own, turns visitors away (`rrt_dir_denied`, `rrt_htaccess_denies`: a deny at the top level counts for every file, one inside `<Files>` or `<FilesMatch>` only for the names it matches, so a security plugin's PHP-only rules leave readings alone), and of the kind asked for by extension and first bytes (`rrt_file_kind_ok`: `audio`, `text` for captions and chapters, `listen` for either)); `rrt_att_file( $att, $post_id, $kind )` (a members' file only for its own post); `rrt_att_choosable()` for the Reading audio box.
- **Protected fields:** `rrt_audio`, `rrt_captions` and `rrt_credit` are protected meta (`is_protected_meta`), so WordPress's Custom Fields panel no longer offers them.
- **Check private folders** on Podcast feed (`rrt_private_check_section`, routes `rrt/v1/private-check/start` and `/done`, Administrators only, `assets/js/privatecheck.js`): a test file in each private folder, asked for from the owner's browser with no sign-in and no cache, then deleted; any folder that answered is named; the result kept in `rrt_private_check` (not autoloaded). On nginx, or when a folder answered, the section shows the `location` lines nginx needs (`rrt_private_nginx_lines`).
- **Sponsor message permissions** (`rrt_spot_cap`, filter `rrt_spot_capability`, default `manage_options`): the post type has its own capabilities (`capability_type` `rrt_spot`/`rrt_spots`), held by whoever has that capability (`user_has_cap`); the Sponsor messages menu needs `edit_rrt_spots`.

**Changed**
- **The four places that turn a reading's address into a file use the rule:** the short address (`rrt_dl_local_path`; `rrt_dl_target` answers nothing at all for an address into uploads that the rule refuses), storage offload (`rrt_off_rel`), collections and sponsor messages (`rrt_col_reading_path`, `rrt_podcast_local_path( $url, $post_id, $kind )`, `rrt_feed_audio_path`), and every captions file a collection or sponsored copy reads.
- **The Reading audio box** refuses another post's members' file and files in private or closed folders.
- **The short address hands files to the web server only while Count downloads is on;** otherwise it redirects, as before 1.24.0. An unknown file type is never sent. The repeated Apache branch in `rrt_dl_send_handoff` is gone.
- **Members' files** (`rrt_mfile_protect`) move into a random folder of their own, `rrt-members/<20 letters and numbers>/<year>/<month>/<name>`; going back (`rrt_mfile_release`, `rrt_mfile_unkeyed`) leaves it out and removes the empty folder (`rrt_mfile_tidy`). Files kept before move once (`rrt_mfiles_upgrade`, on `admin_init` and the hourly job, option `rrt_mfiles_layout`); their checked addresses use the attachment number and do not change. Uninstall leaves the random folder out when it moves files back.
- **A reading that becomes members-only, a draft, private or password-protected** (`rrt_spot_now_private`) loses every sponsored copy at once, older ones included, and the short address no longer sends a copy for it (`rrt_spot_dl_target`).
- **Share for feedback** (`rrt_share_allowed_ids`, filter `rrt_share_anyone_capability`, default `edit_others_posts`): Editors and Administrators may share with anyone; Authors and Contributors see and may share with Editors, Administrators and people who shared a take with them. At most `RRT_SHARE_INVITES_DAY` (20) invitation emails a day from one person.
- **Coding standards:** every translated string printed is escaped; every remaining `phpcs:ignore` for the security sniffs says why. WordPress Coding Standards 3.1's security sniffs report nothing.

**Tests**
- `tests/php/security.php` (68): the rule (each private folder, a closed folder and a PHP-only one, a fake MP3, other types, captions, `..`, outside uploads), the four places, protected fields, members' files for their own post only, the box, the random folder and back, the upgrade move (address unchanged, once), sponsor message permissions by role and with the filter, a reading becoming private, the share list by role, the daily invitation limit, and Check private folders.
- `tests/php/handoff.php`: on real nginx and Apache, a reading pointed into a closed folder gets 404, never the file; with counting off the short address redirects.
- `tests/browser/privatecheck.py`: Check private folders on the test site's PHP server (which, like nginx, reads no `.htaccess`) finds the folders open, names them, deletes its test files and shows the nginx lines.
- `tests/upgrade.py` upgrades from 1.24.0 too.

---

## [1.24.0] Download counting to the IAB guidelines (P)

Podcast downloads counted the way the IAB Tech Lab's Podcast Technical Measurement Guidelines (version 2.3, final in 2026) describe, with a method page that says so: the last milestone of Phase 2. Designed with the maintainer on 18 November 2026, after the guidelines were read in full and four questions were tested (docs/RESEARCH.md, Download counting to the IAB guidelines).

**Why:** sponsors and directories compare shows by downloads counted to these guidelines, so a number counted any other way cannot be compared. 1.2.0 followed version 2.2 loosely. Tested against the open OPAWG list's examples, its hand-written patterns let 262 of 364 known bots count as listeners and named 855 of 991 apps only "Other"; it never joined the pieces an app asks for, so apps that download in pieces never counted, and it ignored the size of the MP3's tag. And its redirect let Apple's player send the rest of a stream straight to the file, past the counter. Following the guidelines is open to anyone; a certificate is a paid audit for companies, so the plugin says it follows them and never claims one.

**Added**
- **The hand-off** (`rrt_dl_send_handoff`, `rrt_dl_handoff`): after counting, the short address has the web server send the file itself, with byte ranges, so every piece an app asks for comes back to the counter: LiteSpeed's `X-LiteSpeed-Location`, Apache's `X-Sendfile` (mod_xsendfile) or nginx's `X-Accel-Redirect` (two lines of setup, shown in the section; `rrt_dl_nginx_base`, filter `rrt_dl_nginx_base`). **How files are sent** on Podcast feed: Automatic (default), Apache, nginx or Redirect. **Check counting** (`rrt_dl_check_run`, route `POST rrt/v1/dl/check`, `assets/js/dlcheck.js`) asks the site's own short address for a test file with a byte range (`/podcast-file/0/<token>.mp3`, file in `uploads/rrt-dl-check/`) and checks each step: answered, seen by the counter (a marker file, since a transient read back in the checking request can come from its own cache), no redirect, the exact bytes as a 206, `audio/mpeg`. A hand-off is used only after a check passed for the same way, server kind and site (`rrt_dl_sig`); otherwise, and for any file with an outside copy (1.23.0), the 302 redirect stays.
- **The Range header where the server hides it** (LiteSpeed keeps a request's Range header from PHP, so a two-byte probe looked like a whole download): Check counting has a sixth step, **Counting saw which bytes were asked for** (the answering request writes the header it saw into its marker file). Where it fails on LiteSpeed or Apache while counting is on, the check adds marked lines at the top of the site's `.htaccess` (`# BEGIN Foxfire Studio download counting`: `RewriteRule ^podcast-file/ - [E=RRT_RANGE:%{HTTP:Range}]`, only for the short addresses, with LiteSpeed's cache skipped for them by `E=Cache-Control:no-cache` a marker `RRT_SEEN` so the check can say whether the lines ran, and `Cache-Control: private, no-store` on the counting addresses so a CDN in front keeps no copy; a host's CDN can keep a copy of a `.mp3` short address and answer repeat requests itself). Check counting makes its two test requests from the owner's browser, as an app would (`assets/js/dlcheck.js` with routes `POST rrt/v1/dl/check/start` and `.../check/done`, `rrt_dl_check_prepare`, `rrt_dl_check_judge`; a host's firewall can answer the site's request to itself with a 412; the server's own request stays for `rrt/v1/dl/check` and the tests), and after adding the lines asks for another round from the browser. On LiteSpeed it tries the hand-off's documented forms in turn (`rrt_dl_variants`, `rrt_dl_variant`: the usual one with WordPress's headers, only `X-LiteSpeed-Location`, or a plain `Location` with status 200) and keeps the first that answers a closed byte range (on one server the usual one answered every closed range, `bytes=0-1` included, with 412). It asks twice, with a step **A second request reached counting too**, and names a CDN it finds in front (`rrt_dl_cdn_name`: Hostinger's, Cloudflare, QUIC.cloud or another) and looks again; counting reads `RRT_RANGE` or `REDIRECT_RRT_RANGE` when `HTTP_RANGE` is missing (`rrt_dl_range_header`, `rrt_dl_htaccess_add`, `rrt_dl_htaccess_remove`, filter `rrt_dl_htaccess_file`). Switching Count downloads off, deactivating or uninstalling takes the lines out and leaves the rest of the file as it was. If the header still cannot be seen, the section, the check and the method page say every request counts as a whole download.
- **The one-minute rule, properly** (`rrt_dl_take`): each request's ranges (`rrt_dl_ranges`: open, suffix and several in one header) are joined per consumer and file (`rrt_dl_join`, at most 32 pieces, the closest joined first) and the download counts the moment the joined bytes reach the file's own ID3 tag plus one minute of its audio (`rrt_dl_bar`; the tag measured from the file's first 10 bytes, `rrt_dl_tag_size`, remembered with its size and time), or the whole file for a reading under a minute or of unknown length. Two requests at once count once (only the update that turns the mark from 0 to 1 counts).
- **Never counted:** HEAD; a GET that only asks whether the file changed (If-None-Match or If-Modified-Since with no Range); the `bytes=0-1` probe (also when redirecting, where OP3 would count it); Apple Watch duplicates (`atc/... watchOS`, `(null)/(null) watchOS`); bots, bot libraries and OP3's extra bots; empty, overlong or broken user agents (`includes/agents.php`); data centre and VPN addresses (`includes/netlist.php`); an address past 30 counted downloads an hour (as before) or 100 a day (`RRT_DL_DAILY`, `rrt_dl_address_ok`).
- **Consumers:** IP address (IPv6 cut to its first 64 bits, `rrt_dl_ip_key`) plus user agent, scrambled with the daily code as before; a calendar day in the site's time zone. **Podcast consumers a day** (each consumer once across all episodes) in `rrt_dl_days`.
- **App, bot and device names** (`includes/agents.php`, `rrt_ua_info`, `rrt_ua_device_label`, `rrt_ua_app_label`) from `data/agents.php`, made by `tools/lists/agents.py` from OPAWG user-agents-v2 and OP3's extra bot list (both MIT; `data/agents-LICENSE.txt`), matched in OPAWG's order. Downloads are stored by app (OPAWG's names; AppleCoreMedia shown as Apple's player) and device (phone or tablet, computer, smart speaker, watch, car, TV). Bare `okhttp` is a bot library in OPAWG's list, where 1.2.0 counted it.
- **Data centres and VPNs** (`includes/netlist.php`): X4BNet's lists_vpn "datacenter" list (MIT; IP address data from IPLocate.io, CC BY-SA 4.0; `data/datacenters-LICENSE.txt`) as `data/datacenters.bin`, made by `tools/lists/datacenters.php` with the site's own converter (`rrt_net_convert`: merged ranges, IPv6 by its first 64 bits, searched by halving, `rrt_net_in_file`), refreshed once a month by the daily job while counting is on into `uploads/rrt-assets/datacenters.bin` (`rrt_net_download`, facts in `rrt_net`, filter `rrt_net_urls`).
- **Shared networks** (optional): address ranges exempt from the per-address limits, confirmed again every 90 days (`rrt_net_is_shared`, `rrt_net_shared_due`).
- **Members' feeds counted** (`rrt_dl_take_member`, from `rrt_member_file` for keyed requests): the member is the consumer, so a member counts once a day whatever their address; address limits and the data centre list do not apply; stored only as the feed **members**, never which member.
- **Sponsor messages delivered** (`rrt_dl_ad`, table `rrt_dl_ads`, `rrt_dl_ads_for`): with each counted download a pre-roll counts as delivered, and a mid-roll or post-roll once its last byte was asked for (from where each message sits in the sponsored copy, which `rrt_spot_dl_target` now passes on). The message's screen shows **delivered** beside each reading's downloads.
- **Measurement prefixes** (optional, `rrt_dl_prefixes`, `rrt_dl_prefixed`): OP3 with one tick and up to five others, in front of every episode address in the public feed only, with the re-download warning and a privacy line naming them.
- **The method page:** `[foxfire_download_method]` (`includes/dl-method.php`, `rrt_dl_method`) writes the method out from the live settings: the version followed and no certificate, the threshold, the window and time zone, what is left out, the lists' editions, members, where it differs (bytes asked for, never bytes delivered; the redirect when in use; the player measured apart; prefixes), the last review and the day counting began this way. **Make the method page** adds it as a draft (`rrt_dl_method_page`).
- **Unusual days** (`rrt_dl_unusual`): a day's downloads, or one app's, at least 20 and over three times the median of the 28 days before, on the Overview and in the monthly report. **Review twice a year** (`rrt_dl_review_due`, `rrt_dl_review_html`, `admin_post_rrt_dl_reviewed`): a line with **Reviewed** on the Overview six months after the last review, mentioned in the report when due.
- **The Overview's Podcast apps:** podcast consumers a day (tile and chart line), the share from members' feeds, apps with plain names, devices, unusual days, the review line, and a mark where counting changed; counts from before 1.24.0 stay, labelled "counted the 1.2.0 way".
- **Download counting** section on Podcast feed (`includes/dl-admin.php`, `rrt_dl_section`, `admin_post_rrt_dl_save`, option `rrt_dl` via `rrt_dl_settings`): How files are sent with Check counting, Shared networks, Measurement prefixes, Method page, Lists in use.
- Tests: `tests/php/downloads.php` (114: ranges, joining, the bar, the tag, names and devices, every bot case, the lists, IPv6, data centres and their download, every request pattern measured for the proposal, the limits, shared networks, members, sponsor messages, the old table, unusual days, the review, prefixes, the method page, the feed, how files are sent), `tests/wp/handoff.sh` with `tests/php/handoff.php` (15 for each of nginx and Apache with PHP-FPM, with a must-use plugin that hides the Range header from PHP as LiteSpeed does and WordPress's own `.htaccess` rules on Apache, skipped with a line where not installed: Check counting through the server, a probe, a range byte for byte, the count at the right piece, the rest, HEAD, Redirect), `tests/browser/dlcount.py` (17, with `tests/wp/dlcount.php`; env `DC_SEED`), and the uninstall checks.

**Changed**
- Database version 8: `rrt_dl_seen` gains the bytes asked for, the pieces, a counted mark and messages reached; new tables `rrt_dl_counts` (day, post, app, device, feed), `rrt_dl_days` and `rrt_dl_ads`. 1.2.0's `rrt_downloads` keeps its counts and gets no new ones.
- The privacy paragraph and PRIVACY.md describe the device, members, the monthly list download and any prefix.
- Uninstall always removes `rrt_dl`, `rrt_net`, `uploads/rrt-dl-check/` and the `.htaccess` lines; the new tables go with the delete box, like 1.2.0's. A method page you made stays.

Off by default, as before (Count downloads).

---

## [1.23.0] Storage offload (P)

The readings podcast apps and the player download, served from outside storage or a CDN the owner chooses: the sixth milestone of Phase 2. Designed with the maintainer on 18 November 2026, after the storage services, Apple's hosting requirements, what browsers need to read a file from elsewhere, and whether S3 can be reached with no code library were checked (docs/RESEARCH.md, Storage offload).

**Why:** a podcast's traffic is mostly apps downloading whole episodes, many at once when one comes out, and on shared hosting that is what strains a site. A CDN or object storage delivers files near the listener for about a cent a gigabyte. Other WordPress offload plugins move the whole Media Library and can delete the site's own files, which breaks every part of Foxfire Studio that reads them (collections, sponsored copies, loudness measuring, audiobooks, the feed's sizes), so offload here adds copies of the listening files only and never removes the originals.

**Added**
- **Storage offload** (`includes/offload.php`, loaded after sponsors.php; Foxfire Studio, Podcast feed, a **Storage offload** section with a form of its own; options `rrt_offload` and `rrt_offload_files`, neither loaded on every page). Three ways: **Bunny Storage** (its HTTP API: a PUT with the storage zone's password and a `Checksum`; `rrt_off_bunny`, regions in `rrt_off_bunny_regions`), **S3-compatible storage** (AWS Signature Version 4 written in the plugin, path-style addresses and Amazon's own host style, an endpoint's own path kept, uploads over 64 MB in 8 MB parts; `rrt_off_s3_sign`, `rrt_off_s3`, `rrt_off_s3_parts`; presets for Cloudflare R2, Backblaze B2, Wasabi, Amazon S3, DigitalOcean Spaces and Bunny's S3), and **a CDN in front of this site** (a pull zone with the site as origin: nothing uploaded, the address is the file's own path with `?v=` and the content code). Every body is sent with its own Content-Type: left out, the HTTP library labels it a web form, and some servers then read it as empty.
- **Which files:** each published reading's MP3 and captions for everyone, its sponsored copy (MP3, captions, chapters file), and every published collection's joined MP3 and captions (`rrt_off_wanted`). Never: `rrt-members`, `rrt-kept`, `rrt-audiobook`, `rrt-sent`, `rrt-shares`, `rrt-whisper`, `rrt-assets`, pictures, or any file type other than audio, video, captions and chapters (`rrt_off_type`).
- **Copies named by their content:** `foxfire/<first ten characters of the SHA-256>/<the file's own name>`, uploaded with `Cache-Control: public, max-age=86400` (a day, so a withdrawn file stops within a day even with no purge key). A file is used from outside only once its copy answers at the public address with the right size and type (`rrt_off_check`), and only while it is unchanged on the site (size and time; `rrt_off_rec_fresh`). A file changed in place gets a new copy and its old one is deleted. A captions or chapters file the storage serves under another type stays on the site and its copy goes (Bunny Storage serves `.vtt` as `application/octet-stream`, found on the first live check; `rrt_off_rec_local`); the section counts it as staying, not waiting.
- **Up to date by itself** (`rrt_off_sync`): at the end of any request in which the owner changed a reading's file, captions, access, sponsored copy or collection, or a post's status (after the page has gone, never on a visitor's request; `rrt_off_queue`), and in the hourly job (`rrt_off_hourly`, within about 20 seconds a run). A copy is deleted at once when its file is for members, kept aside, unpublished, trashed or gone, and two days after a reading still public got a newer file (apps may be playing the old one). With a **bunny.net API key** (optional), each deleted file is also cleared from the pull zone (`rrt_off_purge`).
- **If the storage stops answering:** the hourly job checks one copy (the one checked longest ago, `rrt_off_health`); after three failures in a row every address is the site's own again, with a notice on every admin page and **Try again** in the section; the next good check brings the copies back.
- **Where the copies are used** (`rrt_off_url`, `rrt_off_episode`): the public feed and members' feeds (for readings open to everyone): enclosures, transcripts and sponsored copies' chapters files (a reading's own chapters address is the plugin's and stays); the download-counting short address, which stays on the site and redirects to the copy (`rrt_dl_serve`); and the post's player's audio, unless **Use it for** is **Podcast apps only**. The player's captions and Download button keep the site's files (a captions track from elsewhere needs CORS on the audio element itself, and `download` is ignored for another site's file). The studio, collections, audiobooks, sponsor copies and videos always read the site's files.
- **Check this setup** (`rrt_off_check_start`, `rrt_off_check_done`, routes `POST rrt/v1/offload/check` and `.../check/done`; `assets/js/offload.js`): uploads a small test file (in CDN mode writes it to `uploads/rrt-offload-check/`), reads it back at the public address (HEAD, size, type, then one byte range), then this browser reads it from the page (CORS, which the player needs for chapters), and deletes it. The switch cannot be ticked until the check passes for the current settings; changing where copies go needs a new check and starts the list of copies again. For S3, a failed browser read offers **Set the CORS rules for me** (PutBucketCors, `POST rrt/v1/offload/cors`), with the rules to paste when the key may not set them.
- **The section:** the kind of storage and its fields, the optional bunny.net API key, **Use it for**, lines for `wp-config.php` (`FOXFIRE_STORAGE_KEY`, `FOXFIRE_STORAGE_SECRET`, `FOXFIRE_STORAGE_PURGE_KEY`, which win over saved ones), the last check's steps, **Files outside** (how many of how many, their size, the newest, older copies still in their two days), **Files waiting** with each reason, **Copy everything now** (one file a request from the browser, with progress; `POST rrt/v1/offload/step`) and **Delete the outside copies** (a second press; `POST rrt/v1/offload/clear`). A saved secret is never shown again. A reading's page (Foxfire Studio, Readings) says whether it is served from outside storage and since when (`rrt_off_post_line`).
- **The privacy paragraph** in Settings gains a line while offload is on, naming where the readings come from (`rrt_off_privacy_text`).
- **Uninstall:** always removes `rrt_offload`, `rrt_offload_files` and any test folder left by an interrupted check; it never touches the outside storage.
- Tests: `tests/php/offload.php` (68, with AWS's published Signature Version 4 example), `tests/browser/offload.py` (18, with `tests/wp/offload.php`; env `OF_SEED`, `OFF_PORT`), the stand-in storage `tests/wp/storage.py` (S3 with its own signature check, Bunny's API, the public side with ranges and CORS, a pull CDN and the purge API), and the uninstall checks.

No database change. Off by default.

---

## [1.22.0] Sponsor slots (P)

Short messages placed before, in the middle of and after readings in the podcast feed, from a library of messages with dates: the fifth milestone of Phase 2. Designed with the maintainer on 18 November 2026, after how hosts place self-run messages, the disclosure rules in Canada and the US, and cutting an MP3 in the middle without a click were checked (docs/RESEARCH.md, Sponsor slots).

**Why:** a show with a sponsor, a shop of its own or a cause to announce wants a short message in its episodes for a while, older episodes included, and gone again when the arrangement ends, without editing every reading by hand. Hosts such as Buzzsprout do this on their own servers. Doing it on the site, by copying MP3 frames the way collections already join readings, keeps every reading's exact sound and needs nothing from listeners.

**Added**
- **Sponsor messages** (`includes/sponsors.php`, loaded after members.php; post type `rrt_spot`, under Foxfire Studio, Sponsor messages): each message has **Who it is for**, its **Kind** (**Paid or gifted**, or **Your own**), a **Link**, a **Script** (the message's text, so the studio reads it; a paid message starts with "This reading is supported by ..."), **Dates** (from and to, both optional, in the site's time zone; `rrt_spot_today`, filter `rrt_spot_today`), **Where it can go** (Pre-roll, Mid-roll, Post-roll) and **Which readings** (every reading in the feed, older ones too; readings in chosen categories; or chosen readings). Post meta `_rrt_spot`. When two messages want the same place in a reading, the lower **Order** (Post Attributes) wins. The list shows who, kind, dates, whether it runs now and copies still to make.
- **Recording:** Record it in the studio opens the studio on the message (render.php lets `rrt_spot` posts in, with no music bed and no audiobook copy), reading the script with its first line in the place of a title, since the message's own title is for the list (`rrt_spot_studio_blocks`); Use this recording puts the take on the message as on any post. For a paid message, the take's captions are checked for a disclosure word ("sponsored", "supported", "paid", "partner", "gifted", "ad", "promotion") in its first 10 seconds (`rrt_spot_disclosed`, `rrt_spot_disclosure_in`), and the screen says whether one was heard. It is a reminder only.
- **Copies in each reading's sound:** a message is joined by copying frames, so it needs a copy in each reading's exact format (bitrate, rate, channels) and at its measured loudness, shared between readings within a quarter of a unit (`rrt_spot_need_key`, `rrt_spot_lu_bucket`). The message's screen (`assets/js/sponsors.js`, `window.RRTSponsors`, `Copies`) first measures any reading whose loudness the site does not know (as collections do, through `collections/level`), then **Make them now** decodes the message, levels it with sound.js and encodes it once per format with mp3.js in a worker; each copy is an attachment of the message (`_rrt_spot_copy`: need, the recording's file key, loudness). Routes `GET rrt/v1/spots/<id>/needs`, `POST rrt/v1/spots/<id>/copy` (refused in another format or from an older recording; an older copy for the same need goes).
- **The mid-roll** goes in the middle of a pause of at least 0.6 s, only in readings of 10 minutes or more (`s_mid_min`), only in prose and story by default (`s_kinds`; no kind counts as prose), and never in a reading with the music bed. A reading's pauses are found once per file in the browser, in its Reading audio box (`RRTSponsors.findPauses`: 20 ms levels judged against the reading's own loudest and quietest within three seconds either side, so quiet takes, noisy rooms and a level that changes partway all work), and kept with the post (`_rrt_pauses`, with the file's key; route `POST rrt/v1/spots/pauses`). The box offers the three longest pauses between three tenths and seven tenths of the way (`rrt_spot_pause_suggest`), or a typed time snapped to the nearest pause (`rrt_spot_pause_snap`), with **Hear 5 seconds either side**; with no choice the longest of the three is used. A collection's mid-roll goes in the gap between the two readings nearest its middle. **No sponsor messages on this reading** keeps any post clean (`_rrt_spots`: off, mid).
- **The cut:** MP3 frames keep part of their sound in the spare bytes of the frames before them (the bit reservoir; the studio's encoder does this in every frame). After the mid-roll, the frames just before the cut are copied again with their side information zeroed (`rrt_spot_mute`), so they decode as silence while their bytes give the next frames what they read back (`rrt_spot_bridge_count`, from each frame's `main_data_begin`, `rrt_spot_frame_info`, `rrt_spot_frames_at`). In a test with a real encoder, the reading after the cut decodes sample for sample as the original from 50 ms on, and in those 50 ms differs by less than the room; a plain cut left a tick 6 to 9 dB above it.
- **The sponsored copy** (`rrt_spot_sync`, `rrt_spot_make`, `rrt_spot_write`): pre-roll, 0.8 s of silence, the reading (opened at the cut with 0.3 s of silence either side of the mid-roll), 0.8 s, post-roll, in `uploads/rrt-sponsored/<post>/<slug>-sp<code>.mp3`, with a fresh ID3 tag (the reading's chapters moved to their new times and, while **Chapters** is on, a chapter "A word from <who>" for each message, the reading's chapter carrying on after a mid-roll: `rrt_spot_chapters`), a captions file with every cue moved and each message's own captions (or "Sponsor message: <who>"; `rrt_spot_vtt`), and a chapters file. Every change gives a new name (and a copy whose reading's choices changed while it was being made, as when the block editor saves a post's boxes in a second request, is made again at once); the previous files are kept for 2 days for apps still playing them, then deleted (`rrt_spot_trim`). The reading itself is never touched. Made again whenever a message, its dates (checked by the hourly job, `rrt_spot_sync_all`), a reading's audio, captions, choices, pauses, loudness, kind, music bed or access, a collection's join, or the settings change, at the end of that request (`rrt_spot_queue`). Each message's log records when it starts and stops on each reading (`_rrt_spot_log`), and **Where it runs** on its screen lists the episodes it is in now and where it has run, with the downloads of those days when Count downloads is on (`rrt_spot_report`).
- **The feed** (`rrt_spot_episode`, called from `rrt_podcast_render`): the episode's enclosure, length, duration, transcript, chapters and soundbite are the sponsored copy's, its GUID stays, and its notes start with a line for each message ("Sponsored by <who>: <link>" or "Supported by <who>: <link>", editable as **Episode notes**), the link marked `rel="sponsored"` in the HTML notes. With Count downloads on, the short address carries the copy's file name and leads to it, an older copy's name to that copy while it is kept (`rrt_dl_target( $post_id, $name )`, `rrt_spot_dl_target`).
- **Member feeds** carry the readings without messages unless **Members hear sponsor messages too** is on; a reading for members never carries any.
- **The post page's player** keeps the reading as it is (listening numbers, Most replayed and clip suggestions count seconds in the reading). **Thank sponsors under the player** (off) adds a line naming them, paid links marked `rel="sponsored"` (`rrt_spot_thank_html`, `.rrt-sponsor-line` in player.css).
- **Settings** (Foxfire Studio, Podcast feed, **Sponsor messages**, inside `rrt_podcast`): `s_on` (off), `s_mid_min` (10), `s_kinds` (prose, story), `s_chapters` (on), `s_thank` (off), `s_members` (off), `s_line_paid`, `s_line_own`.
- **Uninstall:** with the delete box ticked, the sponsored copies' folder, the messages' copies, the messages and each post's `_rrt_spots`, `_rrt_pauses` and `_rrt_spot_built` go; the messages' own recordings stay in the Media Library. Unticked keeps everything.
- Tests: `tests/php/sponsors.php` (112, with MP3s made in PHP whose frames read back a set number of bytes), `tests/unit/sponsors.test.js` (the pause finder at a normal level, a phone's level and a level that changes partway, and with tight pauses only), `tests/browser/sponsors.py` (24, with `tests/wp/sponsors.php` and `tests/wp/sponsors-mp3.js`; env `SP_SEED`, `SP_MP3`), the two screens in `smoke.py`, and the uninstall checks.

No database change. Nothing about listeners is used, and every listener gets the same file.

---

## [1.21.0] Member feeds (P)

A private podcast feed for each member, and readings that only members can hear, or hear first: the fourth milestone of Phase 2. Designed with the maintainer on 18 November 2026, after how private podcast hosts and the podcast apps handle private feeds was checked (docs/RESEARCH.md, Member feeds).

**Why:** a show with supporters, paid members or a circle of friends wants some readings for them alone, heard in the podcast app they already use. The private podcast hosts (Patreon, Supercast, Castos, Transistor, Podigee, Paid Memberships Pro's Member RSS add-on) all give each member their own feed address with a long random key, so one leaked address can be turned off without touching anyone else; Apple Podcasts, Overcast, Pocket Casts and most other apps take such an address, while a username and password on the feed works less well (Pocket Casts supports it only in part, and Overcast's ping not at all). Spotify takes no private feeds. And while a reading's MP3 sits at its public Media Library address, anyone with the address has it, so a reading for members must leave that address.

**Added**
- **Who can hear this reading** (`includes/members.php`, loaded after audiobook.php; post meta `_rrt_access`: who and days): **Everyone** (the default, nothing stored), **Members first, then everyone** (7 days by default, 1 to 365, counted from when the post is published) or **Members only**, at the end of each post's Reading audio box (a new `rrt_reading_box_after` action) and in a **Who can hear this** box on a collection's screen. A collection is as closed as the most closed reading in it, and its box says which reading makes it so (`rrt_access_state`, with `who`, `open` and `why`).
- **Files kept for members:** while a reading is for members, its MP3, its captions and, for a collection, its matched copies move to `uploads/rrt-members/` (refused to visitors by its `.htaccess`, like readings kept aside), keeping their place below it, and each attachment's `_wp_attached_file` follows, so the Media Library entries stay and every part of the plugin still finds the files on the server. Each is marked with its post (`_rrt_member_of`). WordPress then gives the file's address as `/podcast-member/file/<id>/<name>` (a `wp_get_attachment_url` filter), which answers only a signed-in person who may hear the reading, with byte ranges and no caching. When the reading opens to everyone (the choice changes, or Members first's days run out, checked hourly and whenever the feed or the post is shown), the files go back to their old place and address. A field that holds an address in place of an attachment (the extra field from Settings) follows the file both ways, and so does the reading's pick for the feed. A new reading or captions on a post for members goes straight into the folder. A file another open post also uses is never moved (`rrt_access_sync`, `rrt_access_settle`, `rrt_access_tick`, `rrt_mfile_protect`, `rrt_mfile_release`).
- **The public feed** leaves out readings for members (`rrt_podcast_posts( $all )`, `rrt_podcast_open`), and their chapters files answer 404 (`rrt_feed_has_post`). `rrt_podcast_local_path` finds the file behind a checked address.
- **Member feeds** (Foxfire Studio, Podcast feed, **Member feeds**, settings inside `rrt_podcast`: `m_on`, `m_roles`, `m_via`, `m_title`, `m_join`, `m_activity`, `m_guid`; all off or empty by default): **Give members their own feed**; **Who is a member**: the roles ticked, or the members of an active membership plugin (Paid Memberships Pro, MemberPress, Restrict Content Pro, WooCommerce Memberships or Memberful, each asked through its own call); a **Members feed** choice on each person's profile (user meta `_rrt_member`: Follow their role, Always or Never, set by an administrator); administrators always have a feed. Filters `rrt_member_is( $yes, $user_id )` and `rrt_member_can_hear( $yes, $user_id, $post_id )` have the last word.
- **Each member's feed** at `/podcast-member/<key>/` (also with `feed.xml` after it), a 32-character random key in user meta (`_rrt_mfeed`, made with `_rrt_mfeed_at` the first time it is needed): every reading for everyone plus those the member may hear, with the public feed's tags (transcripts, chapters, soundbites, host, support link), titled "<show> (members)" (editable), with its own `podcast:guid`, `<itunes:block>Yes</itunes:block>` and `<podcast:block>yes</podcast:block>`, locked, no pings, `noindex` and never stored by a page cache (`DONOTCACHEPAGE`, `Cache-Control: private, no-store`). It works whether or not the public feed is published, and `rrt_podcast_feed` now prints through `rrt_podcast_render( $m )` for both. A members reading's audio, captions and chapters come from addresses with the member's key (`/podcast-member/<key>/file/<id>/<name>`, `/podcast-member/<key>/chapters/<post>.json`), and its description starts "For members." or says when it opens to everyone. Nothing in the feed names the member; its description asks for the address to be kept private. Someone no longer a member keeps a feed of the readings for everyone with the Become a member link; a reset or unknown key answers 410 Gone; with member feeds off, every member address answers 404.
- **Where members find it:** **Your members podcast feed** on their profile, with Copy, **Open in Overcast** (Overcast's own add link), short steps for Apple Podcasts and Pocket Casts, the note that Spotify cannot take private feeds, and **Make a new address**; the shortcode `[foxfire_member_feed]` shows the same to a signed-in member and Sign in and Become a member to anyone else.
- **The owner's list,** under the Member feeds settings: each member's name, role and when their address was made, with **Email them their feed address** (one short email through the site's mail, only when clicked), **Reset** and **Turn off** (sets them to Never and removes their key).
- **On the post page,** a reading for members plays as usual for a member (from its checked address) and shows, to everyone else, the player's frame with "This reading is for members." (and when it opens to everyone, for Members first), Sign in for a visitor and the **Become a member** link when set (`rrt_member_locked_html`, styles in player.css).
- **Feed activity** (off by default, `m_activity`): for each member, the last fetch, the app's name (as download counting names it) and how many networks fetched the feed in the last 7 days, counted by a hash of the address with download counting's daily code, so no IP address is kept (user meta `_rrt_mfeed_seen`); more than 5 networks in a week shows as **Maybe shared**. Removed after 30 days, and all of it when switched off.
- **The privacy paragraph** in Settings gains a line while member feeds are on, and another while Feed activity is on.
- **Uninstall:** with the delete box ticked, every reading kept for members is first put back at its public place (and a field holding its checked address gets its public one back), then the folder, `_rrt_access`, `_rrt_member_of` and each person's `_rrt_mfeed`, `_rrt_mfeed_at`, `_rrt_member` and `_rrt_mfeed_seen` go; unticked, everything stays and the files stay protected.
- Tests: `tests/php/members.php` (85), `tests/browser/members.py` (43, with `tests/wp/members.php`; env `MB_SEED`), member addresses in `smoke.py`, and the uninstall checks.

No database change. Nothing new from visitors.

---

## [1.20.0] Audiobook export (P)

A collection made into the files an audiobook store asks for, the third milestone of Phase 2. Designed with the maintainer on 18 November 2026, after ACX's current rules were checked and two real readings were measured in a laptop's Chrome (docs/RESEARCH.md, Audiobook export).

**Why:** Audible (through ACX) and the other audiobook stores take a book as one file per chapter plus opening and closing credits and a retail sample, each with room tone at both ends, at -23 to -18 dB RMS with peaks under -3 dB, a background at or under -60 dB and 192 kbps constant-bitrate MP3. A podcast reading misses those rules in small ways: it is trimmed to a quarter second before the first word and 0.6 s after the last, it peaks near -1 dB, it is 128 kbps (or 96 on an older one), and a reading with the music bed has no room to hear at all (on the real reading measured, the quietest half second was -57 dB, all music). So a chapter is best made from the take itself, finished once with the Audiobook (ACX) preset the studio has had since 0.5.0, and a plain reading can be made from its post's MP3 when there is no take.

**Added**
- **Audiobook copies** (`includes/audiobook.php`, loaded after collections.php): a post's take finished with the Audiobook (ACX) preset, voice only (no music bed), -20 dB RMS, peaks under -3.5 dB, 192 kbps mono, with room tone made up to 1.2 s before the first word and 3 s after the last (`RRT_BOOK_HEAD`, `RRT_BOOK_TAIL`). Kept in `uploads/rrt-audiobook/` (refused to visitors by its `.htaccess`, like readings kept aside), recorded in post meta `_rrt_book` (file, length, size, bitrate, channels, time, and the check's RMS, peaks, background and room tone), played and downloaded through `admin-post.php?action=rrt_book_audio` with a nonce and byte ranges. `POST rrt/v1/audiobook/<post>/copy` keeps one (it must scan as constant bitrate, 192 kbps or more, 44.1 kHz; the old file goes), `DELETE` removes it. A reading's page under Readings shows its copy with a player and Download. Deleting a post for good deletes its copy.
- **Keep an audiobook copy** (Settings, Broadcast sound, `sound.book`, off by default): Use this recording also makes and keeps the take's audiobook copy once the reading is on the post, and says how it went. The review's **Finish for** menu gains **Keep as this post's audiobook chapter** (the Download button becomes **Keep for the audiobook**), which keeps one from the take in the review and leaves the post's reading as it is. master.js `bookCopy` (masterMp3 with the ACX preset and `extra.pad`), review.js `bookKeep`, `rvBookKeep`.
- **Room tone from a reading's own pauses** (sound.js `roomPad`, with `pauses`, `joinXf` and `ROOM`): the middles of pauses of 0.42 s or more (60 ms left out at each side), the quietest first and only those within 6 dB of it, up to 3 s, joined with 15 ms crossfades, repeated with 50 ms crossfades (`loopTo`) and crossfaded onto the reading over 30 ms, so the file's own quiet ends are kept up to the wanted length and the rest is filled; never digital silence. `keep` keeps at most that much of a faded file's own ends and leaves them out of the room's sound. `levelRms` levels to an RMS with peaks held under a ceiling, measured again and corrected once.
- **The Audiobook box** on a collection's screen (`rrt_book_box`, `assets/js/audiobook.js`, `window.RRTAudiobook`, styles in `collections.css`; `GET` and `POST rrt/v1/audiobook/col/<id>`): each reading's source, **Audiobook copy** (used as it is), **From the post's MP3** (made in the browser: decoded, room tone with `keep` 0.2 s, -20 dB RMS, peaks under -3.5 dB, fades of 10 and 50 ms, 192 kbps with mp3.js) or **Needs an audiobook copy** (the music bed, no reading, not on the site, not a constant-bitrate MP3) with a Studio link (`rrt_book_source`); the opening and closing credits; the retail sample; the cover's size; **Make the audiobook** with progress and Cancel.
- **Credits** are posts of their own type, `rrt_credit` (never public, no screen), children of the collection with post meta `_rrt_credit_kind` (open or close), first written as "<title>." then "Written by <author>. Narrated by <author>." and "The end." then "<title> was written and narrated by <author>." (the author being the collection author's display name, as in the MP3 tags). The script's first line is the post's title, which the studio reads first. **Record in the studio** saves the script and opens the teleprompter on the credits in a new tab (render.php now accepts `rrt_credit` posts and gives them no music bed); there **Use for the audiobook** keeps only their audiobook copy, with no reading, captions or Recordings row anywhere. Deleting a collection deletes its credits.
- **The retail sample** (post meta `_rrt_book_col`: reading, start, minutes): one to five minutes (3 by default) from a reading, from the first pause of half a second or more after the first 1.5 s of speech (so the title is left out) unless a time is given, ending at the middle of the pause nearest the length asked for (`sampleSpan`), cut from the chapter as made, with its own room tone and level.
- **The ZIP** (`zip`, stored, CRC-32, `<collection>-audiobook.zip`): `00 Opening credits.mp3`, `01 <title>.mp3` and so on (plain letters, numbers and spaces only, as ACX asks; `rrt_book_file_name`, `fileName`), `<n> Closing credits.mp3`, `Retail sample.mp3`, the featured image as `Cover.jpg` (or `.png`) and `Check report.html`. Each MP3 has an ID3 tag (title, author, collection, track; id3.js gains `track`, TRCK). A reading that needs a copy is left out and named in the report.
- **The check report** (`fileItems`, `bookItems`, `reportHtml`), in the box and in the ZIP: for each file, sound.js's audiobook check of the MP3 as decoded (RMS, sample peak, background, room tone at each end, MP3, loudness range, clipping) with the true peak, the length (120 minutes at most), mono and, for a chapter with captions, whether its first words are its title (`headingCheck`); for the whole book, both credits, every chapter, the sample's length, the levels within 2 dB from file to file and the cover (square, 2400 by 2400 or larger), and what ACX also needs that cannot be checked here (a Kindle edition on Amazon, the cover matching the credits, a listen for extraneous sounds, nothing explicit in the sample).
- **Uninstall:** with the delete box ticked, the copies, their folder, `_rrt_book`, `_rrt_book_col` and the credits go; unticked, they stay.
- Tests: `tests/unit/audiobook.test.js` (15, the ZIP read back by Python's zipfile), `tests/php/audiobook.php` (48), `tests/browser/audiobook.py` (39, with `tests/wp/audiobook-mp3.js` and `tests/wp/audiobook.php`; env `AB_SEED`, `AB_DIR`), new routes in `smoke.py`, and the uninstall checks.

No database change. Nothing new from visitors.

---

## [1.19.0] Collections (P)

Several readings joined into one, with a chapter for each, the second milestone of Phase 2. Designed with the maintainer on 18 November 2026, after a check in the workspace that the plugin's own constant-bitrate MP3s join frame by frame into a file whose every reading decodes sample for sample as before.

**Why:** a series, a season or a themed set is easier to share, listen to and later turn into an audiobook as one file. Joining on the site by copying frames keeps every reading exactly as it sounds on its post (no second round of MP3 loss) and takes a second or two even on shared hosting, so a collection can join itself again whenever one of its readings changes.

**Added**
- **The post type `rrt_collection`** (`includes/collections.php`, loaded after feed.php): public, at `/collections/<name>/` with a list at `/collections/`, edited on the classic screen (title, description, excerpt, featured image), listed as **Foxfire Studio, Collections** (after Readings; the screens' own menu is hidden and `parent_file` keeps the menu open). The rewrite rules are refreshed once (option `rrt_col_rewrite`). The list of collections shows each one's readings and length.
- **The Readings in this collection box** (`rrt_col_box`, `assets/js/collections.js`, `window.RRTCollections`, `assets/css/collections.css`): search posts with a reading (`GET rrt/v1/collections/readings`), add, drag or move up and down, remove; each row's length, format, loudness and any note; the total with the gaps (`POST rrt/v1/collections/plan`); **Gap between readings** (none to 5 s, default 2 s of silence), **In the podcast feed** and **Episode type** (Bonus, the default, or Full), saved in post meta `_rrt_col`. Saving joins the readings (`save_post_rrt_collection`, `rrt_col_build`); a notice says when it could not. The last join's line, a player and **Join again now** (`POST rrt/v1/collections/<id>/build`).
- **The join** (pure functions, tested without WordPress): `rrt_col_scan` walks an MP3's frames (MPEG-1 Layer III, constant bitrate; it passes over ID3v2 tags and padding, an Info frame and an ID3v1, APE or ID3 tag at the end, and refuses variable bitrate, a break in the middle or anything else), `rrt_col_silence` makes silent frames in a format (zero side information, the padding bit as LAME sets it), `rrt_col_write` copies each reading's frames a megabyte at a time with the silence between, and `rrt_col_id3` writes an ID3v2.3 tag (title, artist, album, year, length, encoder, the collection's address, CTOC and CHAP chapters with a title and link, and the artwork last so a player reading only the start finds the chapters). The collection's format is the one most of its readings are in, with Finish for's winning a tie (on a real site an older 96 kbps reading listed first would otherwise have made a newer 128 kbps one need matching), and Finish for's when none can say; its chapters are one per reading, with a reading's own heading chapters carried in after it as "Reading: heading" (`rrt_col_chapters`), end to end; its captions are each reading's cues moved to its place and kept inside it (`rrt_col_vtt_join`). The joined MP3 and captions are attachments of the collection (`_rrt_col_file`) and set as its `rrt_audio` and `rrt_captions` without a Recordings row; the record of the join is post meta `_rrt_col_built` (its key, file, length, size, format, parts, chapters with each reading's picture, readings left out, any error). A join is skipped when nothing has changed, and the old files go when a new one is made.
- **Loudness and matching:** each reading's loudness is measured in the browser (sound.js `lufsMulti`) the first time it is listed and kept with its file (post meta `_rrt_level`, `POST rrt/v1/collections/level`, refused for an older file). A reading more than 1 LU from Finish for's loudness (as the same voice in one channel, so mono and stereo compare fairly; the middle of the readings' own when the preset sets none) is pointed out and can still be joined. A reading in another format must be matched first. **Match it in this browser** decodes that one reading at the collection's rate, mixes it to one channel, levels it (`RRTSound.toLoudness`, 3.01 dB lower for a stereo collection), encodes it with mp3.js in the collection's format and sends it (`POST rrt/v1/collections/<id>/match`, checked for the collection's format and the reading's current file); the copy is an attachment of the collection (`_rrt_col_match`: post, file key, loudness) and stands in for that reading while its file is unchanged.
- **Kept up to date:** a change to a reading's `rrt_audio` or `rrt_captions` (or the extra field) asks every joined collection that has it to join again after the page is sent (`rrt_col_queue`, on `shutdown`). If a reading is no longer available (taken off its post, unpublished, gone), the collection keeps the file it has and says so until it is saved again, which joins the rest and names what was left out.
- **The page:** the list of readings under the description (`rrt_col_list_html`), each with ▶ (a `data-rrt-seek` button: player.js now moves the page's player to any such button's time), its time, length and a link to its post; the player at the top when Listening player, Where is the top of the post. The player's chapter menu comes from the MP3's tag, as for posts.
- **The feed:** a published collection with In the podcast feed ticked is an episode (`rrt_col_feed_posts` in `rrt_podcast_posts`, `rrt_feed_has_post`), with its own id (`host:collection:<id>`), `itunes:episodeType` bonus or full (`etype`), a link to "See the readings in this collection", its captions as the transcript, and its chapters file with each reading's picture (`rrt_col_feed_chapters`; `rrt_chapters_json` writes `img`).
- **Uninstall:** with the delete box ticked, collections go with their joined files and matched copies; unticked, they stay for a reinstall. Each reading's measured loudness and the rewrite marker always go.
- Tests: `tests/unit/collections.test.js` (8), `tests/php/collections.php` (74, with MP3s made in PHP whose spare bytes carry a pattern, so each reading is checked byte for byte in the joined file), `tests/browser/collection.py` (22, with three readings made by the plugin's own encoder in `tests/wp/collections-mp3.js` and `tests/wp/collections.php`; env `CL_SEED`, `CL_MP3`), new screens and routes in `smoke.py`, and the uninstall checks.

No database change. Nothing new from visitors.

---

## [1.18.0] Full-length video (P)

The whole reading as a video, the first milestone of Phase 2. Researched first on a real 14:13 reading in a laptop's Chrome (docs/RESEARCH.md, Full-length video), designed with the maintainer on 18 November 2026.

**Why:** YouTube and Spotify both show podcasts as video, and both take a video only by upload (YouTube's RSS connection makes a still picture from the show art; Spotify takes video through Spotify for Creators or a few hosts' private API). A reading already has everything a simple video needs: the picture, the title, the sound, the captions and the chapters. The research made all of the 14:13 reading in 2:15 at 1080p, so it is quick enough to make in the browser.

**Added**
- **Full-length video,** a section on a reading's page under Suggested clips (`includes/video.php`, `rrt_video_section`, after `rrt_clip_button` in `dashboard.php`): a picture menu (the post's featured image, the show's cover art from the podcast feed's settings, the look's colours, or one chosen from the Media Library), **Captions on the picture** (on when the reading has captions), **Quality** (Standard, 5 Mbps, the default; Smaller file, 2.5 Mbps), the reading's length and the video's largest likely size (the 14:13 reading came to 403 MB against an estimate of 572 MB, because the encoder needs less for a still picture), **Make the video**, progress with the time left, and **Cancel**.
- **`assets/js/video.js`** (`window.RRTVideo`): the clip maker's wide picture (`RRTClip._staticLayer`, `RRTClip._drawFrame` with no caption groups, which also gives the waveform, the progress line and the end card), with the chapter being read above the waveform and the reading's caption cues a phrase at a time (`cues`, `at`, `fitCaption`: the largest of 60, 54, 48 or 44 px that fits two lines). H.264 at 1920 by 1080, 30 frames a second, a keyframe every second, the graphics chip's encoder where the browser has one (`hardwareAcceleration: 'prefer-hardware'`, then any); AAC-LC stereo at 192 kbps, Opus where the browser has no AAC (Linux), with a warning. mp4-muxer's `StreamTarget` writes into the browser's private storage (OPFS, `createWritable`) as the video is made, so it never sits in memory; it downloads as `<post>-video.mp4` when done, with **Download again** while the page is open. Opening the page and starting a new video clear what was left before (`clearStore`). Free space is checked first (`navigator.storage.estimate`).
- **The upload kit:** a description for YouTube or Spotify (`description`: the post's excerpt or first 55 words, the post's address tagged `src=youtube` or `src=spotify` with `utm_campaign=video`, and YouTube chapter times when the reading's chapters allow them, `ytChapters`: the first at 0:00, three or more, each 10 s or longer) with **Copy**; the captions file; and a 1280 by 720 JPEG thumbnail (`thumbnail`).
- **Pacing without timers:** the loop waits on the encoder's `dequeue` event and yields by `MessageChannel`. Chrome slows a page's timers to about once a second when its window is not in front, which in the research dropped the render from 6 times real time to under half. The clip maker's own wait (`clip.js` `render`) changes the same way.
- Tests: `tests/unit/video.test.js` (14), `tests/php/video.php` (16), `tests/browser/video.py` (28, with `tests/wp/video.php`: a 16 s reading with captions and chapters; checks the MP4's tracks, length, frame rate and keyframes from its boxes, a caption on the picture, the private storage, Cancel and a reload; env `VD_SEED`).

No database change. Nothing new from visitors, and nothing kept on the site.

---

## [1.17.0] The faster review (P)

The larger Whisper model on the graphics chip, the last milestone of Phase 1. Researched first on a real 14:13 reading in a laptop's Chrome (docs/RESEARCH.md, Faster review), designed with the maintainer on 18 November 2026.

**Why:** the larger model hears a reading better (about 96% of words matching the post against 93% on a real reading), but on the processor it took nearly twice the reading's length, about 27 minutes for 14 minutes, so in practice it was not used. On the graphics chip the same review took 5.1 minutes on a GeForce GTX 1650 and 7.8 on the Radeon graphics in a Ryzen processor, with the same words (99.4% alike) and word times.

**Added**
- **The graphics chip.** `review.js` `gpuCheck()` asks `navigator.gpu.requestAdapter({ powerPreference: 'high-performance' })` once a page (5 s at most) and accepts an adapter that is not a software fallback (`isFallbackAdapter`, also under `info`) and has `shader-f16`; the answer is `studio.gpuOk`. Never on phones (`LOW_MEM`) or without the larger model in the boot data.
- **Model choice.** `wantModel()` returns `'base'`, `'small'` (the larger model on the processor) or `'gpu'`. The teleprompter's Model menu (`#tpWModel`) gains **Automatic** (`auto`, the new default): `'gpu'` when `gpuOk`, else `'base'`, and always `'base'` for an opening drill (`studio.takeDrill`). Larger gives `'gpu'` where it can, else `'small'`. Settings saved since 1.17.0 carry `wm2: true`; before, `'base'` was saved by every browser as the default, so a saved `'base'` without `wm2` becomes Automatic and a saved `'small'` stays Larger. Messages on choosing Automatic or Larger say where it will run.
- **Fallbacks.** `transcribe()` runs `'gpu'` and on any error records `gpuFailed` (never tried again on that page) and runs on the processor: the larger model for Larger (`smallOnProcessor`, which keeps the old fallback to the standard model), the standard model for Automatic. The result says "the graphics chip could not run it, so the processor was used".
- **The worker** (`whisper.js`): `cfg.gpu` loads the model with `device: 'webgpu'` and `dtype: { encoder_model: 'fp16', decoder_model_merged: 'q4f16' }`, always ORT's asyncify build from the site, from `cfg.host` (the site) and then Hugging Face at `cfg.rev` (the pinned revision), with no fallback model; `from.gpu` says it ran there.
- **The status line:** "Listening on the graphics chip: Whisper is hearing your reading (...)"; `engineFrom` reads "larger model on the graphics chip, served from ...", so the result says "Whisper larger model on the graphics chip, served from ...".
- **Files:** `rrt_whisper_gpu_files()` (`onnx/encoder_model_fp16.onnx`, about 176 MB, and `onnx/decoder_model_merged_q4f16.onnx`, about 146 MB) join the `small` set, with their Hugging Face SHA-256 in `rrt_whisper_hashes()`, at `RRT_WHISPER_SMALL_REV`. `rrt_whisper_small_have()` checks a list of the larger model's files; the boot's `small.host` needs only the seven processor files (so a site that downloaded them before keeps using them) and the new `small.gpuHost` all nine; `small.rev` is the pinned revision.
- **Settings, Whisper on this site, The larger model:** two paragraphs on the graphics chip and on laptops with two graphics chips (Windows' Graphics settings, High performance, then end the browser fully); the hint says about 570 MB. The downloader skips files already present when a set is only partly here (the list items carry `data-size`).
- Tests: `tests/unit/whisper.test.js` (3 new: the graphics chip's files and device from the site, the pinned revision with no fallback model, q8 on wasm otherwise), `tests/php/faster.php` (11), `tests/browser/faster.py` (22, a stand-in `navigator.gpu` and Whisper, env `FR_POST`).

No database change. Nothing new from visitors.

---

## [1.16.0] The stronger cleanup (P)

A second engine for background noise reduction, for rooms RNNoise cannot quiet. Researched first (docs/RESEARCH.md, Stronger noise cleanup) on two real phone takes with a ringing bell and a creaking chair, where the maintainer, listening blind, picked DeepFilterNet 3 at full strength both times; designed with the maintainer on 17 November 2026.

#### Added
- **Settings, Broadcast sound, Background noise reduction: Engine** (`sound.engine`): **Standard** (RNNoise, as before, the default) or **Stronger** (DeepFilterNet 3, MIT or Apache-2.0). The amount (Gentle 12 dB, Medium 20 dB, Strong with no cap) applies to either. Stronger is greyed out if its files are missing from the plugin.
- **`assets/js/deepfilter.js`**, the engine, as a Web Worker, `window.RRTDeepFilter` and in Node: the model's own processing (a 960-point vorbis-window STFT at 48 kHz, 32 ERB band features and the first 96 bins, each normalised by a running mean; the encoder and the two decoders on ONNX Runtime Web; ERB gains above bin 96 and the 5-tap deep filter below; the inverse STFT), ported from libDF and checked against it. A planned mixed-radix FFT for 960 points, two real frames per complex transform. Runs in chunks of 10 s, each with 2 s of the frames before it and the running norms carried over, so memory stays small and the result matches one pass. About 6% of a reading's length in Chrome on one thread.
- **The model ships with the plugin** (`assets/vendor/deepfilternet3/`, about 8.6 MB: `enc.onnx`, `erb_dec.onnx`, `df_dec.onnx` with both licences), copied unchanged from the official `DeepFilterNet3_onnx.tar.gz`, as is **ONNX Runtime Web's loader** (`assets/vendor/onnxruntime-web/ort.wasm.min.js`, 50 KB, MIT). The runtime's WebAssembly is the Whisper review's: the site's copy when Whisper has been downloaded, otherwise jsDelivr at `RRT_ORT_VERSION`. Every one of these files is checked against its SHA-256 in the worker before use (`includes/stronger.php`: `rrt_stronger_models()`, `rrt_stronger_loader_sha()`, `rrt_stronger_boot()`; the runtime against Whisper's own fingerprints).
- **A fallback to Standard.** If the stronger engine cannot load, fails a fingerprint, or the device needs more than 0.6 of the audio's length for the first 10 s (`maxRtf`), the take is cleaned by RNNoise and the Room line says why (`report.strongerFailed`).
- **The Room line** says when the stronger cleanup did the work. On Standard, it suggests the stronger cleanup when the room was under 35 dB below the voice, or when RNNoise's pass found two or more steady tones in the pauses (`report.tones`: narrow peaks 10 dB over the spectrum around them, from 300 Hz to 16 kHz, in quiet non-speech frames), such as a bell or a hum.
- **Hear the polish: Cleanup** (Settings, Standard or Stronger). With the other engine chosen, Before plays the sentence with this take's cleanup and After with the other, both with the polish chosen; the sentence's window is cleaned once and kept while other choices change. Use for this take cleans the untouched take (`lastMp3.heard`) again with it and makes the MP3 again; back to the Settings choice does the same the other way. A take where the stronger cleanup already fell back is not retried by the automatic remake for click lowering.
- Tests: `tests/unit/deepfilter.test.js` (the bands, the FFT against a plain DFT, a pass-through model across chunk seams, caps, the lift, the speed limit, the report, the tone count and the Room line), `tests/php/stronger.php` (fingerprints, the model tracked by git so the release zip has it, `.gitignore` now letting these three `.onnx` files in, the setting, what the studio is told, the site's runtime first), `tests/browser/stronger.py` (the real model on a stand-in voice over a struck bell: the suggestion, Hear the polish both ways, Stronger from Settings, a broken model file falling back).

#### Why
- **Lift before the model.** DeepFilterNet judges noise partly by absolute level: on a raw phone take near -47 LUFS it took the first 13 seconds, title included, as background. The engine lifts the reading so its loud end sits near -20 dBFS, and puts the level back afterwards. RNNoise did not need this and is unchanged.
- **Shipped, not downloaded.** The official model is published only inside a packed archive; shipping its three files avoids unpacking archives on the host and any outside request for the model.
- **Strong is the amount to use with it.** In the blind listening a 20 dB cap let enough back in to be heard as mouth sounds; with no cap the chair and mouth sounds were gone and the voice was unharmed.

No database change; nothing new is stored or sent from visitors. Uninstall has nothing new to remove (the setting goes with the settings).

---

## [1.15.1] Endings and How hard for verse (P)

Two places that still judged verse as if it were prose. Designed with the maintainer on 17 November 2026 (all as proposed).

#### Fixed
- **The read-out's Endings line ignored the kind of reading.** Since 1.11.0 the review only flags a Faded ending in Verse or Ritual and chant when the end is more than 14 dB quieter, but the Endings line kept the prose bands (4 and 8 dB) and told a chant to keep breath for the end of each line. With a kind whose endings may trail (`RRTPractice.KIND_TRAILS`), the line now reads steady under 8 dB, a natural trail from 8 to 14 dB, and over 14 dB suggests checking the Faded ending flags and the Fade meant cue, naming the kind as the Pace line does. `studio.paceRange()` carries `trails`; a range without it is judged as before. Prose, Story and no kind are unchanged.
- **The Endings line could say all was well beside Faded ending flags.** The line judges the typical phrase ending and the flags each sentence, so a live Ritual and chant take read "steady and clear to the last word" with four Faded ending flags. Once the full review is ready, `describeReading()` gets the number of Faded ending flags still standing (a fade meant cue leaves a sentence out) and, in every kind, says how many faded further and points to the flags ("most of your line endings stayed steady and clear. 4 sentences faded further"). The line written when a take stops, before the review, is unchanged.
- **Rushed and Slow flags said "against your usual" pace,** though the figure is this take's typical sentence pace. They now say "against 155 for this take", and the review's pace bar legend says "this take's own pace" in place of "your usual pace".
- **How hard on Readings to make counted verse line capitals as names,** the flaw 1.15.0 fixed in the pronunciation suggestions, so verse posts looked harder than they were. How hard now measures the script with each line of verse as its own block (`rrt_backlog_lines()`, shared with the word index), so a line's first capital is never a name and the end of a line counts as a place to breathe (a stanza with no full stops was one long sentence). Words the word index found written in lower case anywhere, or only inside a title in capitals, are never names (the index keeps them as `notname`; its signature moved on, so it is made again once), nor are the reader's words marked Not needed (`rrt_backlog_not_names()`; `rrt_backlog_measure()` takes them as a third argument). Scoring and levels are unchanged. The word count, and so the time each post would run, is the same as before.

#### Changed
- **Automatic checks on GitHub run once per pull request** into `main` (and again for each new commit on it), with a Run workflow button for a run by hand. Plain branch pushes run nothing, and a newer push to the same pull request stops the run it replaces. **Why:** every check ran twice (on the push and on the pull request) and again for each work-in-progress push, using up a private repository's monthly Actions minutes; the full suite still runs locally before every build. No version change: workflow and docs only.

No database change; nothing new is stored or sent.

---

## [1.15.0] The pronunciation list (P)

Pronunciation notes (1.1.0) and taught words (0.16.0) were kept in five places and seen only one post at a time, in the studio. Now one screen shows them all. Designed with the maintainer on 17 November 2026 (all as proposed, with the suggestions panel).

#### Added
- **Foxfire Studio, Pronunciation** (`includes/pronounce.php`, `assets/js/pronounce.js`, for anyone who can edit posts; a tab after Readings to make): one row per word per place (the reader's own for every post, a post's, or one word only, from the cue marks), with the note, what Whisper hears it as (taught pairs and ignored words), where it is kept and how many published posts have the word. Search covers the word, note, what Whisper heard and the post's title; filters for kind, place and post; every column sorts.
- **Changes on the screen** (`rrt/v1/pronounce`, `do`; Move to's post menu starts on the word's own post, or the first post that has it): `note` (click a note, Enter saves, Escape cancels, an empty note removes it; a note on one word only must still sit on its word, or the screen says to open the post in the studio once), `add` (word, note, every post or one post; asks before replacing a different note), `move` (every post to one post, one post to every post or another post, one word only to every use in its post; the note goes, and taught words are added to the new place as taught now and forgotten at the old one; if both places have different notes, nothing changes until the reader picks which to keep), `forget`, `skip` and `unskip` for the suggestions, and `import`. Each answers with the whole new list.
- **Words that may need a note:** names and words of five syllables or more in the published posts (as Readings to make's How hard counts them) with no note, taught word or Not needed yet. For names, each line of verse counts on its own, so the capital a line starts with is not a name, and a word written in lower case anywhere in the posts is an ordinary word, never a name (on a real site the first build suggested The, Mother and Magic), and a run of three or more words in capitals inside a sentence with an ordinary word in it is a title ("Where Witches Gather", "The Art of Witch"), whose words are names only if they stand as one elsewhere; posts with no reading first, then the most posts. Add a note fills in the word; Not needed keeps it off (user meta `rrt_pron_skip`).
- **The word index** of the published posts (transient `rrt_pron_index`, made again when a post is saved, trashed or deleted): each word as written, its posts, the suggestion candidates and the posts with no reading.
- **Export CSV** (the rows shown, a formula-safe first character) and **Export backup** (`rrt/v1/pronounce/backup`, format `foxfire-studio-pronunciation` version 1: the reader's notes and taught words, and each post's notes, taught words and notes on one word only, by post id and slug). **Import** shows a preview (to add, already here, different notes, posts not on this site) and adds; a different note is kept unless the reader picks the file's. Posts are matched by id on the same site, otherwise by slug. Taught words are merged by time, so one forgotten since the backup stays forgotten.
- **All notes** in the studio's note menu (Cues, click a word, Pronunciation) opens the screen in a new tab, searching for that word (`RRT.notes.list`).

#### Fixed
- **A forgotten taught word came back.** Taught words for every post are kept in each browser as well as on the site, and the studio added the browser's copy back on load, so a word forgotten on the laptop returned when the phone opened the studio. Sets now carry `at` (when each word was taught) and `forgot` (when it was forgotten), in milliseconds; a word stays only if taught after it was last forgotten, and a word with no time counts as taught long ago. Merging follows this rule on both sides (`RRTPractice.mergeLearned`, `rrt_merge_learned`, `rrt_learned_settle`); the site always merges now. A studio tab from before 1.15.0 that forgets with `replace` has each word it left out recorded as forgotten (`rrt_learned_save_over`). Forgotten records older than 400 days, or past the newest 2000, are let go.
- **An open studio tab could undo newer notes and cue marks.** It sent its whole list with each save. It now sends only what changed since the site last answered (`noteDiff`, `cueDiff`, routes `rrt/v1/notes` and `rrt/v1/cues` taking `changes`); a whole list from an older tab still saves as before. Changes that could not reach the site wait in the browser as changes.

#### Changed
- Your progress's taught words list points to the Pronunciation screen for forgetting.
- The personal data exporter and eraser cover `rrt_pron_skip`; uninstall removes it with the delete box ticked, and always removes the word index.
- No database change.

#### Tests
- Unit (`tests/unit/pronounce.test.js`, 8 tests): forgetting and merging either way round, teaching again, the site's empty arrays, note and cue changes, and the screen's filter, sort, place line and CSV. Server (`tests/php/pronounce.php`, 61 checks, also in the oldest-versions job): forgotten words through the routes and an old tab's replace, change-only saves, the rows, suggestions and Not needed, every change from the screen with its clashes, permissions, backup, import with a preview, clashes and another site, and the index made again. Browser (`tests/browser/pronounce.py`, env `PN_POST`): the screen with a studio tab opened beforehand, search and filters, a note changed in place, Add a note from a suggestion, Not needed, Move to with a clash, Forget, both exports, Import with the file's note chosen, then the old tab saving one note without undoing the screen's, dropping the forgotten word from its own copy, and All notes. verse.py now checks the cue change sent.

---

## [1.14.0] Voice following on the device (P)

Follow my voice has used the browser's speech recognition since 0.0.3, which in most browsers sends the audio to an outside service, works only in Chrome and Edge, and matched one word at a time, forward only. Now a small speech model runs on the reader's own device. Researched first on a real 13-minute phone reading and a set of harder takes cut from it (docs/RESEARCH.md, Voice following), then designed with the maintainer on 17 November 2026 (all as recommended).

#### Added
- **The listener** (`assets/js/follow-worker.js`, a Web Worker): the sherpa-onnx 1.12.20 WebAssembly engine (Apache-2.0, `assets/vendor/sherpa-onnx/`, copied unchanged from the official release, fingerprinted) runs a streaming Zipformer of 20M parameters trained on read speech (Apache-2.0, about 45 MB in 8 bits), one thread, no special server headers. The engine's own build packs a demo model of about 190 MB; the worker hands its loader an empty package instead (`getPreloadedPackage`) and loads its own model from the first host that has every file: this site once downloaded in Settings, else Hugging Face, pinned to revision `d42f2d9f` (`includes/follow.php`). It answers each piece of audio with what it has heard and how many seconds it has taken in.
- **The follower** (`assets/js/follow.js`, `window.RRTFollow`, tested in Node): after each new word the last six words heard are lined up with the script from 4 words behind to 40 ahead (a match scores twice its likeness, an extra or missing word costs one); short words must match exactly, longer ones may differ by a quarter of their letters or share a rough sound-alike key, so names spelled the model's own way still count. Four words with no match hold the place (an ad-lib), then the whole script is searched for a strong match of five words or more, which follows a skip ahead or going back. Words are split at hyphens and dashes and small numbers written out, the way the model hears them.
- **The highlight leads a little** (`RRTFollow.leadStep`, `voiced`, `studio.followTick` from studio-core's frame): the model reports a word about half a second to a second after it is said, so on a real reading a highlight that waited for it trailed the reader. While the voice is sounding (each piece of audio 12 dB over the room's floor and within 24 dB of the recent loudest, held 0.25 s), the highlight moves on at the reader's pace, at most 3 words past the last word heard, 1 when nothing has matched for 1.5 s (an ad-lib), never into the next paragraph and never to the end, which only a heard word reaches. A pause stops it at once; the next heard word corrects it. Going back is followed when the heard place moves back more than 2 words.
- **The pace while following:** words per minute over the last 30 s, pauses included (from 15 s of reading, not across a jump), against the post's kind of reading when it has one: "Following your voice, 126 wpm (Verse: 110 to 135)" in the control bar, and "Pace 126 wpm" under the large meter in phone take mode (`#tpPMeterPace`). Not in opening drills.
- **The first look** (`#tpFirstLook`), shown the moment a voiced take stops, before the MP3 and the review: its length, the pace, any paragraph of five words or more between the first and last heard that the follower never heard (a paragraph only one stray word matched does not count as heard), and how far behind the follower fell at most on this device. It goes when the Whisper review is ready.
- **Settings, Voice following on this site:** Download voice following to this site (4 files, about 45 MB, through Whisper's downloader as set `follow`, into `uploads/rrt-whisper/csukuangfj/...`, each file fingerprinted).

#### Changed
- **Follow my voice** (Controls, Reading) uses the listener in place of the Web Speech API, in any browser with workers, WebAssembly and the audio worklet (tested in Chrome; Firefox, Safari and phones should work, and fall back to the timed pace if they cannot). The model loads as soon as the switch is on, with a line showing its progress; Begin waits for it only the first time.
- While a take is recorded, the follower hears the recorder's own copy of the microphone (record.js calls `studio.followFeed` with each piece), so there is one microphone, as phones need; with no take recorded it opens the microphone itself, with the same settings.
- A take starts the follower afresh from the word it begins on (`studio.followFresh`). A click on a word, Page Up or Page Down moves it, and it carries on from there. Going back moves the place back (`jumpWord`); until 1.13.0 the place only moved forward.
- **Fallback, saying why:** if the engine or model cannot load, the microphone is refused, or the device falls more than 1.5 s behind for 3 s, the switch turns off, the timed pace takes over from the current word, and a line says which.
- The message "Listening through Chrome's online speech service" is gone, with the service.
- PRIVACY.md's Follow my voice section is rewritten: the audio goes only to the model inside the page, and the model's download carries no audio.

#### Tests
- Unit (`tests/unit/follow.test.js`, 12 tests, two for the lead and the voice detector): words and likeness; a reading heard with about one word in eight misheard and one in twenty dropped, straight through to the end with no big jumps; an ad-lib held within two words; a skip of a paragraph and a half caught within nine words; going back; a hand jump; nothing new heard; the pace (window, jumps, too soon); and the first look. Server (`tests/php/follow.php`, 16 checks, also in the oldest-versions job): the model's files and fingerprints, the shipped engine's fingerprints, and where the studio loads the model from with no files, every file, one missing, and Whisper set to load from outside. Browser (`tests/browser/follow.py`, env `FV_POST`, a stand-in listener): ready before Begin, followed to the end, held through an ad-lib, a skip followed, one microphone with and without a take recorded, the pace line, the first look naming the paragraph never heard, and both fallbacks.
- Before building, the real model on the real studio in headless Chrome with a real phone reading: ready in about 3 s from the site, the place keeping up with the reading, at most 0.2 s behind.

---

## [1.13.0] Voice polish (P)

The Broadcast sound steps have run at one strength since 0.5.0. This milestone gives each a strength, lets a reader hear what the polish does to one sentence before choosing, and adds what a voice and setup analysis of real readings asked for: a level that drifts over minutes evened out, a note while recording when the voice has dropped, an EQ for each recording setup, and clicks between words. Designed with the maintainer on 17 November 2026 (all eight points as recommended).

#### Added
- **Strengths** (Settings, Broadcast sound, Steps): Off, Gentle, Standard or Strong for pops on P and B, harsh S sounds, breaths and compression (`sound.plosives`, `deess`, `breaths`, `compress`). **Standard is exactly the sound of every earlier version:** the same arithmetic with the same numbers (`RRTSound.STRENGTH`), checked sample for sample against 1.12.1's sound.js. A switch saved before 1.13.0 reads as Standard (on) or Off (off) in `rrt_settings()` and in the studio (`strengthOf`). Gentle and Strong: pops at 15 or 9 dB over the voice's low end, keeping 40% or 6% of the band (Standard 12 dB, 15%); S sounds from a 0.7 or 0.35 share, cut by at most 4 or 12 dB (0.5, 8 dB); breaths lowered 3 or 10 dB (6), never cut; compression 1.5 to 1 from 4 dB under the loudest speech, or 3 to 1 from 9 dB (2 to 1 from 6).
- **Slow leveling** (`sound.slow`, on by default): the voice's level over a minute around each moment, every 5 s, speech only, is brought towards the level of the whole reading, by at most 4 dB, or 2 dB on a Verse or Ritual and chant post (`slowLevel`, `slowMax`). The gain moves over seconds, so a phrase's shape is kept. Readings under 90 s are not touched, and the read-out says so. It runs before compression. The read-out's Energy line still measures the take before any of this, as it always has.
- **Lower clicks between words** (`sound.clicks`, off by default): once the Whisper review knows where the words are, the gaps between them (100 ms or more, `RRTPolish.gaps`) are searched 50 ms in from each word for a burst 15 dB over the gap's own level, under 15 ms long and 20 dB or more under the reading's loud speech (the 95th percentile of its 10 ms stretches), which is lowered by 12 dB with 1 ms ramps (`clicks`). A consonant's burst is louder than that, so it is left alone even when Whisper's word edge is late. A gap with more than six such bursts holds sound Whisper did not hear as a word and is left alone. The take's MP3 is then made again (`studio.polishAfterReview`), with its chapters, and the review line says how many were lowered, or why it could not.
- **EQ for each setup** (`sound.eq`, off by default): a setup is the device and its microphone's name (`RRTPolish.setup`: iPhone, iPad, Android phone or tablet, or Computer, and the name with "Default - " and a USB id taken off). Each take of 30 s or more (not an opening drill) is measured in six octave bands, 250 Hz to 8 kHz, over speech only (`measureBands`, two passes of each band filter so the louder low end does not swamp the top), and the six numbers go to the site for that setup (`POST rrt/v1/eq`, user meta `_rrt_eq`, five takes a setup, twelve setups, a take counted once). From three takes, `eqSuggest` gives an EQ half the way from the setup's average shape towards the long-term shape of speech (ANSI S3.5), at most 3 dB a band, centred so the loudness hardly moves, in half decibels; applied as peaking filters after the low rumble filter (`eqApply`). Phone takes carry their setup to the computer (`setup` and `setup_label` on a sent or phone take), so a phone take reviewed on the laptop gets the phone's EQ. A file opened with Open a recording has no known setup. Settings lists the setups and how many takes each has.
- **The live energy note** (`RRTPolish.energy`): while recording, the voice's level in its first minute (stretches 10 dB over the room) is compared with its last 20 s; at 4 dB or more below, "Your voice has dropped a little since the start" shows for 8 s, at most once a minute, under the large meter in phone take mode (`#tpPMeterEnergy`) and otherwise in the control bar (`#tpEnergy`). Never in an opening drill, never on a Ritual and chant post. It reads the analyser as floating point, like the large meter.
- **Hear the polish** in the review studio's header (`#tpRvPolish`, `assets/js/hearpolish.js`): pick a sentence of the take; it is worked out with 8 s either side (`RRTSound.preview`, also in the worker) and loops, Before (as recorded, brought to the same loudness) and After switching at the same moment. Each step's strength, the EQ (none, this setup's, or any saved setup's), click lowering and slow leveling can be changed for this take only; **Use for this take** makes its MP3 again with them, for Download, Use this recording, the music bed's mix, one-off versions, clips and Share for feedback, and **Back to the Settings choice** undoes it. The panel says what this setup's EQ is, or how many takes it still needs, and **Forget this setup** clears its measurements. Slow leveling works over minutes, so it is not heard in one sentence.
- The read-out's new **Polish** line: strengths other than Standard, what slow leveling did, the EQ and the clicks lowered.
- `assets/js/polish.js` (`window.RRTPolish`, tested in Node): `setup`, `gaps`, `energy`, `energyWanted`, `options` (the Settings choice with a take's one-off over it), `needsWords`. `includes/polish.php`: `rrt_polish_levels`, `rrt_polish_level`, `rrt_polish_settle`, the `rrt/v1/eq` route (GET, POST, DELETE), `rrt_eq_get`, `rrt_eq_add`, `rrt_eq_boot` (`RRT.eq`).

#### Changed
- master.js: `soundOpts` takes the polish from `studio.polishFor` for the voice being finished; `masterMp3` gains `extra` (`{ measure: true }` for a new take's first finish); `toMp3` starts each take with the Settings choice (`studio.polishNewTake`) and hands the bands to `studio.polishLearn`. The page's fallback for the sound worker knows `preview`.
- record.js: the microphone's name is read once it is granted (`studio.micSetup`) and each take keeps its setup (`studio.takeSetup`, the take row's `setup` and `setupLabel`, Send to my computer's `setup`). review.js: a sent or recovered take brings its setup; a file or combined reading has none.
- An edited MP3 is polished with the take's strengths and EQ when it is made; clicks are found on the take's own timeline, so an edited version does not get them.
- Personal data export and erase include the setups (`_rrt_eq`). Uninstall removes them only with the delete box ticked.

#### Tests
- Unit (`tests/unit/polish.test.js`, 19 tests): Standard identical to before, Off, each strength's limits, slow leveling (a 6 dB drop over five minutes, 2 dB in verse, steady and short readings untouched), clicks (a 3 ms click lowered about 12 dB, a 40 ms sound and the words untouched, the margin), the bands and the suggestion (three takes, within 3 dB, centred, a speech-shaped voice flat, only the first five), the EQ filters, the preview, setups, gaps, the energy note and the options. Server (`tests/php/polish.php`, 23 checks, also in the oldest-versions job). Browser (`tests/browser/polish.py`, env `PL_POST`): Settings, a take with a click lowered after the review, Hear the polish (sentences, Before and After, Use for this take, back to Settings), three takes giving a setup its EQ, and Forget this setup. Uninstall keeps or removes `_rrt_eq` with the delete box.

---

## [1.12.1] Phone take tidy-ups, and the Room line after noise reduction (P)

Found while trying Carry on on the live site on 17 November 2026. Carry on itself worked: a take cut off at word 22 was offered at once and carried on from the sentence before.

#### Fixed
- **A finished phone take came back as "An unfinished recording".** After Stop, `finish` writes the browser's copy once more with `ended`, and `endedHere` removed it through `forgetTake`; the two writes could land in the wrong order, leaving the copy behind. Once the computer had used the take, the site no longer held it, so `liveStartup` passed it by and the recovery offer showed it. `endedHere` now removes the copy by its id after the `ended` write (`c.saving`), and `liveStartup` forgets any copy that reached Stop whose take the site no longer holds. A copy that never reached Stop is still kept and offered for recovery.
- **The read-out's Room line called a phone take's room noisy after noise reduction had brought it well down** (32 dB as recorded, 45 dB after). `describeReading` now rates the room by `denoise.snrAfter` when noise reduction lowered the background by 1 dB or more, as Check microphone has since 1.9.0, and says both figures in one sentence (quiet at 45 dB or more, fairly quiet from 35, else noisy). With no noise reduction, or one that found little, the recorded figure decides as before. The fairly quiet advice no longer speaks of a laptop ("A quieter spot would help a little."), since phone takes are reviewed on a computer.

#### Changed
- **Carry on no longer depends on the browser's own copy to mark a take cut off.** A take only counted as cut off once the site had heard nothing for `RRT_LIVE_STALE` (90 s), or once `sendRest` finished it from the browser's copy. A phone that lost its copy and opened the post again within 90 s would get no Carry on. phonetake.js now keeps the ids of the takes this browser started on the site (`rrtLiveMine` in localStorage, the last 20; added when the site accepts the take, removed when it is finished). On the next visit, after any rest is sent, `closeOrphans` finishes each of these takes the site still holds as live, and this browser holds no audio for, as cut (`done`, `cut` 1, the site's own word count), and forgets ids the site no longer holds. A take the browser still holds audio for stays with `sendRest`. The 90 s rule stays for takes started elsewhere.

#### Tests
- Unit: six Room line checks in `tests/unit/readout.test.js`. Browser: `tests/browser/livetake.py` gains a take reopened at once with no browser copy (Carry on within seconds, the take cut on the site, the id forgotten) and a spent copy forgotten while an unfinished one is kept.

---

## [1.12.0] Phone take mode (P)

A take recorded on a phone is now safe on the site as it is made. Before, a phone take lived only in the browser until it was finished, made into an MP3 on the phone (slow for a 13-minute reading) and sent with Send to my computer, so a call, a reload or a closed page could leave it stranded on the phone. Designed and its open details agreed with the maintainer on 17 November 2026: on for phones, the phone does nothing more after Stop, Carry on after an interruption, and pieces always sent, with a line saying how much.

#### Added
- **`includes/live.php`:** `POST rrt/v1/sent/live` starts a waiting take (`live` 1, `rate`, `bits`, `updated`) and writes a 44-byte WAV header with no audio (`rrt_live_header`); `POST rrt/v1/sent/<id>/piece?offset=&words=` takes the raw bytes in the body, appends only what the site does not hold (a piece sent twice is kept once, an overlap adds its new part), refuses an offset past the end with 409 and `have`, an offset or length inside a sample with 400 and a piece over `RRT_LIVE_PIECE_MAX` (4 MB) or a take over `RRT_LIVE_MAX` with 413, rewrites the RIFF and data sizes under a lock after each piece, and keeps `bytes`, `seconds`, `words` and `updated`; `POST rrt/v1/sent/<id>/done` (`words`, `cut`) finishes it, or deletes it when no audio arrived. `rrt_live_state`: done, live, or cut (finished after an interruption, or not heard from for `RRT_LIVE_STALE`, 90 s). Only the account that started a take, able to edit its post, can add to or finish it.
- **`assets/js/live.js`** (`window.RRTLive`, tested in Node): `pieceBytes` (10 s of whole samples), `resumePlan`, `carryStart` (the first word of the sentence before), `state`, `meterPct`, `mb`, and `meter`: the voice's level over the last 6 s from stretches 10 dB over the room (the power average, less `VOICE_OFFSET` 2.5 dB, reads within a dB or two of the take's LUFS on test readings), the room from the quietest tenth of 20 s, the lift needed for the Settings target and `RRTSound.liftLimit` for that room less noise reduction, and the word (wait, good, quiet within 4 dB of the limit, low over it, loud at -1 dBFS or above in the last 1.5 s), changing at most twice a second.
- **`assets/js/phonetake.js`** (a studio part after record.js): the switch (`settings.phoneTake`, on by default, shown only on phones), `studio.live` (begin, push, level, owns, finish) and `studio.liveStartup`. Pieces are sent one at a time, retried after 2, 5, 10, 20 and 30 s and at once on `online`; a hidden page cuts a piece at once and writes the browser copy (`rec.saveNow`). The browser's take row gains `site`, `siteRate` and `ended`. After Stop the result shows the take's place on the site, Listen back and **Make the MP3 here** (`#tpMakeHere`), and forgets the browser copy once the site has it all. On the next visit the rest of any cut-off take is sent from the browser copy and finished as cut (`cut` 1 unless it had reached Stop), then **Carry on** (`#tpCarry`) is offered for the post's newest cut take, once (`rrtCarried` in localStorage), starting at `RRTLive.carryStart` through `studio.beginFrom`.
- **The large meter** (`#tpPMeter`, `body.tp-phone-rec`): reads the analyser as floating point, since a phone's voice near -50 dBFS sits under the byte reading's smallest step.

#### Changed
- record.js: `buildFile` hands a phone take to `studio.live.finish` and the MP3 to the new `makeMp3`; the capture tells `studio.live` of each new block and the take's start. `offerSent` leaves out a take still arriving and says when one was cut off. review.js: a phone take already on the site gets no Send button, and the review line points to the computer. teleprompter.js: `studio.liveStartup` runs before the sent and recovery offers. sent.php: `rrt_sent_out` adds `state`, `live`, `cut`, `updated`, `rate` and `bits`; a live take cut off before any audio arrived is dropped; Recordings marks takes cut off or still arriving.
- On a phone screen, Last take is hidden while a take is under way, and the dock's middle buttons wrap, so Finish recording no longer sits on top of Last take (seen on the first live phone take).
- tests/browser/phone.py switches phone take mode off, so it still covers a phone finishing its own takes and Send to my computer.

#### Tests
- Unit (`tests/unit/live.test.js`), server (`tests/php/live.php`, 26 checks, also in the oldest-versions job), and browser (`tests/browser/livetake.py`, 29 checks, env `LT_POST`).

---

## [1.11.1] The first and last words of a reading (P)

Found on the live site on 17 November 2026: a 13-minute phone reading of a post opening "Hex the Oligarchy" came back with "Hex the" grey (not read) and no flag. Whisper had heard the two words as one, "Hexa" (0.44 to 0.92 s). The alignment lets a reading start or stop partway through the script at no cost, and `similar()` gives nothing for words of three letters or fewer, so "Hexa" never matched "Hex", the reading appeared to start at "Oligarchy", and "Hexa" was dropped as a word before the reading. The captions, built from the same words, would have lost the start of the title.

#### Fixed
- `compareReading` now pairs the words Whisper heard before the first matched word (at most four) with the unread script words just before it (at most four), choosing how many script words they stand for by how alike the joined words are (`similar()` of the joined keys, or each pair related when the counts match), when the likeness is at least 0.4 and the heard words end within 2 s of the reading. The same is done after the last matched word. Paired one for one, each word gets its own status; run together, the first script word carries what was heard (`joined`, how many words) and the others share its time (`joinedTo`), with one problem for them all ("“Hex the” heard as “Hexa”"), not offered for teaching, since a taught word is one script word. Words heard and paired are no longer counted as added. A word between the new first word and the old one is only marked skipped if it was not paired.

#### Tests
- Unit: run-together first words become one misread with times and no extra or skipped words; a first word heard differently is a misread, and one heard as another form is worth a listen; run-together last words; a stray "thank you" before the reading, and an alike word heard four seconds before it, are left alone. The real Whisper output from the live site's reading gives "Misread “Hex the” heard as “Hexa”".

---

## [1.11.0] Review for verse and pace (P)

Designed and its open details agreed with the maintainer on 16 November 2026: four kinds with the ranges below, set per post with a Verse suggestion, a deeper fade threshold in verse and ritual, and the pause map in the script behind a switch. Why: a ritual reading's circle-casting lines, meant to trail off, were flagged Faded ending, and the pace bands suited essays only.

#### Added
- **Kind of reading** (`includes/kinds.php`): `rrt_kinds` (prose, story, verse, ritual, with labels), `rrt_kind_names` (for sentences), `rrt_kind_defaults` and the new setting `kinds` (low and high wpm for each: 140 to 160, 130 to 150, 110 to 135, 95 to 120), cleaned by `rrt_clean_kind_ranges` (60 to 220, at least 5 apart, a bad one left at its default); post meta `_rrt_kind` (`rrt_get_kind`, `rrt_save_kind`); `rrt_kind_guess_html`, which calls a post verse when it has at least six lines (split by line breaks and paragraphs, headings and lists left out), 70% of them 12 words or fewer and the middle one 10 or fewer; `rrt_kind_boot` for the studio; and the REST route `POST rrt/v1/kind` for anyone who can edit the post. Settings, Reading gains Pace by kind of reading. The Reading audio box gains a Kind of reading menu with the Verse hint, and writes it only when changed there (`rrt_was_kind`), as 1.8.0's fields do.
- **The studio:** a Kind menu in the Practice section, with "This post has many short lines, so it may be verse" and Set to Verse while no kind is chosen. With a kind, the Pace slider starts at the middle of its range on the slider's 5 wpm steps (`RRTPractice.kindPace`); moving it is kept for that post in `rrtPace_<post>` (with the kind, so a new kind starts at its own middle), and the pace saved in `rrtTeleprompter` for other posts is left as it was. Not chosen brings it back.
- `RRTPractice.kindRange`, `kindPace`, `paceAgainst` and `kindReview`, which gives the review its options: the Rushed and Slow edges a quarter beyond the range (prose keeps the 200 and 105 used before) and the fade threshold (14 dB in verse and ritual, 8 otherwise).
- **The read-out's Pace line** (`describeReading( m, voiced, fmtTime, range )`) judges against the range: well under (more than 10 under), just under, inside, just over, well over. In the review it now shows in How it sounded whenever the post has a kind, as well as with a Pace focus.
- **Fade meant,** a new cue (`f` in each cue, kept by `rrt_clean_cues`, `cueClean` and `cuePlace`): a tilde after the word in the teleprompter and the review. A sentence with one on any of its words is never flagged Faded ending (`S.fadeMeant = 'cue'`); one excused by the kind's threshold has `S.fadeMeant = 'kind'`, and every measured fade keeps its depth in `S.fadeDb`.
- **The pause map:** `compareReading` gains `R.gaps` (every gap of 1 s or more between read words, with its type: `sentence`, `break` or `inside`, and whether it was cued), `R.pauseMap` (counts by type, cued, longest and total) and `R.cueMissed` (a pause or breath cued after a word, with under 0.3 s before the next). The review's new Pause map section gives the summary, a Show pauses in the script switch (`rrtShowPauses` in the browser), which shows each pause's length in the script (plain between sentences, underlined at a break, boxed inside a sentence, gold where cued), and a line for each cued pause not taken, which plays from just before it. The timeline gains a thin tick for each pause not already marked as long. The missed cues are not flags and do not count.

#### Changed
- `compareReading( script, chunks, frames, learned, cues, opts )`: `opts.rushed`, `opts.slow` and `opts.fadeDb` replace the fixed 200, 105 and 8; without them nothing changes.
- The cue menu's help line mentions letting a line fade.

#### Tests
- Unit: a trail in verse passes, a deep fade in verse and a fade in prose are flagged, a Fade meant cue silences one; the Rushed edge moves for verse; the pause map's types, summary and longest; a cued pause run past is noted and not flagged, one taken is counted as cued; the kinds' ranges, starting pace and review edges; Fade meant in `cueClean` and `cuePlace`; the read-out's five range lines.
- `tests/php/checks.php`: the fade in saved cues, the kinds and their order, range cleaning and saving, the verse guess on verse, prose and a short post, saving and reading a kind, the Reading audio box writing it only when changed, and the studio's boot for a visitor.
- `tests/browser/verse.py` (new, 23 checks): the suggestion, the menu, Set to Verse saved and its starting pace, the other posts' pace untouched, the slider kept per post, Prose and Not chosen, the Fade meant cue drawn, saved, back after a reload and cleared, and the Reading audio box.
- `tests/browser/take.py`: the pause map counts both pauses and the cued one, and Show pauses switches on, is remembered and switches off.
- The uninstall checks seed `_rrt_kind` and check it stays when keeping and goes when deleting.

---

## [1.10.0] The full Podcasting 2.0 feed (P)

Designed on 15 November 2026 and its open details agreed with the maintainer on 16 November 2026: the support words ("Support" and the show's title), podcast:txt as an empty field for directories' codes, and Google's WebSub hub.

#### Added
- **Chapters** (`includes/feed.php`). `rrt_id3_chapters` reads the ID3 chapter frames (CHAP, with their TIT2 title and WXXX link) from the start of each MP3, the PHP twin of `RRTId3.read`: tag versions 2.3 and 2.4, Latin-1, UTF-16 and UTF-8 text, an extended header skipped, at most 8 MB of tag read, stopping at the artwork, unsynchronised tags left alone. Chapters are always given in time order, since a tag's table of contents may list them in another. A chapter that ends where it starts is left out. `rrt_chapters_json` writes them as a Podcasting 2.0 chapters file (version 1.2.0, as `RRTId3.chaptersJson` does, titles as UTF-8, an untitled one numbered). The file is served at `/podcast-chapters/<post id>.json` on `init` (as the short download addresses are), as `application/json+chapters` with `Access-Control-Allow-Origin: *`, only while the feed is published and only for a published post without a password that has chapters; otherwise 404. The address also answers without `.json`, for servers that answer a missing `.json` file themselves (PHP's own test server does).
- **What the feed keeps about each reading** (`rrt_feed_facts`): its chapters and its top suggestion, worked out once and kept in post meta `_rrt_feed` under a key of the plugin version, the reading's address, its file's size and time, the captions' address, size and time, and the post's last edit, so a new file, new captions or an edit to the post works them out again. Always removed on uninstall.
- **The soundbite** (`rrt_feed_soundbite`): the pick from Use for the feed (`rrt_soundbite_pick`), else the top suggestion (`rrt_suggestions()[1]`), else the opening; none without captions. Its title is its first words, at most 60 characters, cut at a word (`rrt_feed_short`). The suggestions are those from the captions alone, without a listening curve, so the feed does not depend on a period of listening numbers.
- **The feed** gains `podcast:medium` (podcast); `podcast:person role="host"` with the host's name (the author by default), link (the site by default, printed only when https) and picture (only a square JPEG or PNG at an https address, `rrt_podcast_person_img`); `podcast:funding` with its words (128 characters at most; "Support" and the show's title by default), only for an https link (`rrt_podcast_funding`); an optional `podcast:location` (a place name, 128 characters at most); `podcast:txt` lines from a text box, where a line that starts with a single word, a colon and a space gives that word as its purpose (`rrt_feed_txt_lines`); `atom:link rel="hub"` while pings are on; and for each episode `podcast:chapters` (when its MP3 has chapters), `podcast:soundbite` (start and length to 0.01 s) and the transcript's `language` (the feed's own). New settings in `rrt_podcast`: `person`, `person_link`, `person_img`, `funding`, `funding_txt`, `location`, `txt`, `pings`, `hub`.
- **Pings**, off until switched on: the switch is greyed out until the feed is published, and saving with the feed off switches it off. When a reading field (`rrt_audio`, or the extra field from Settings) is added to or changed on a published post (`added_post_meta`, `updated_post_meta`), or a post with a reading is published (`transition_post_status`), `rrt_ping_due` asks for a ping. It goes out after the page has been sent (`shutdown`, after `fastcgi_finish_request` or `litespeed_finish_request` where there is one); within 10 minutes of the last one, a single scheduled job `rrt_podcast_ping` sends it once the 10 minutes are up, however many changes come meanwhile. `rrt_ping_send` sends a GET to Podcast Index's `hub/pubnotify?url=<feed>` and a POST of `hub.mode=publish` and `hub.url=<feed>` to the hub (`https://pubsubhubbub.appspot.com/` unless another https hub is set), with a 5 s timeout and the user agent `FoxfireStudio/<version>`, so the site's own address is not named beyond the feed's. The time and both answers are kept in `rrt_podcast_ping` and shown on the screen. A reading taken off the post purges the feed's cache but sends no ping.
- **The Podcast feed screen** lists each episode with its chapters, transcript and soundbite (and whether it is the pick or the top suggestion), and has a new section, More for podcast apps, with the host, support link, place, text lines and pings. Its checks say when the host link, support link or hub is not https (left out, or the usual hub used) and when the host picture is not square, not a JPEG or PNG, or gone.
- The privacy paragraph gains a line while pings are on, naming the hub when it is not Google's, and is then offered in WordPress's privacy policy guide; PRIVACY.md and readme.txt describe the chapters file, the kept facts and the pings.

#### Changed
- `rrt_podcast_purge( $post_id )` also purges the post's chapters file from LiteSpeed's cache.

#### Tests
- `tests/php/feed.php` (76 checks) with the seed `tests/wp/feed.php` (the clip suggestions test's three-minute reading and captions, its reading swapped for an MP3 with three chapters written out of time order, one with a link, artwork after them; and a plain MP3 with no tag or captions); pings go to a stand-in through `pre_http_request`, never to the real services. Also in `tests/run.sh oldest`.
- `tests/browser/feed.py` (33 checks): the episode list, Pings greyed out until the feed is published, the new fields saved, every new tag in the feed read signed out with ElementTree, the chapters file's type, CORS header and order, and the feed, the chapters file and the pings gone once the feed is switched off.
- The uninstall checks seed `_rrt_feed`, `rrt_podcast_ping` and a waiting ping, and check all three go both ways.

---

## [1.9.1] Whisper hears a long reading in pieces (P)

#### Fixed
- **The Whisper review could lose the start of a reading longer than 30 s.** The worker asked transformers.js to hear the whole recording with `chunk_length_s: 30, stride_length_s: 5`: the library cut it into 30 s windows overlapping by 5 s and joined the windows' words where they overlapped (`_decode_asr`, `findLongestCommonSequence`). On a real 72 s phone reading with the larger model, the join lost the first window: the review marked the title, the opening paragraph and the first half of the next line as not read, and put a stray "Nature" at the join, while the same opening, heard alone in a 23 s file, was 100% right. Raising the recording by 29 dB changed nothing, so it was the join and not the level.
- The worker now cuts a recording of over 28 s into pieces itself (`RRTWhisper.pieces`): each cut is at the quietest 300 ms between 15 and 28 s into what is left, and the last piece is never under 5 s. Each piece is heard on its own with `return_timestamps: "word"` and no windows of the library's own, and its word times are moved by where it starts. Pieces meet with no gap and no overlap, so there is nothing to join. A recording of up to 28 s is one piece, as before. The clip maker uses the same worker.
- **Whisper could stop early inside a piece.** Tried on the site, the same reading came back right from "I am the sun" on, but the first piece (0 to 22.4 s: the title, a 1.4 s pause and an 18 s paragraph) gave only "The Declaration of Nature", timed at 26 to 29 s, past the piece's end: the model heard the title, then treated the rest as silence. The same audio heard as a 23 s file the day before had come through whole, so this failure comes and goes. Each piece's answer is now checked (`RRTWhisper.hear`): the speech in it is found from its own level (`speechSpans`: 50 ms frames over the louder of 12 dB above its room and 35 dB under its loud parts), and when a stretch of over 2.5 s of speech has no word within 0.6 s, or a word is timed past the piece's end (`missedSpeech`), the piece is cut at its quietest pause at least 2 s from either end (`quietPoint`) and each half heard on its own, two levels deep at most. Whichever answer leaves less speech without words is kept, and word times are kept inside their piece. On the real reading, with a stand-in for the model that fails the same way, the first piece was heard again as the title alone and the paragraph in two halves, and all 165 words came back in order; the real model's answers for the other three pieces left at most 0.4 s of speech without a word, so they are not asked for again.

#### Tests
- `tests/unit/whisper.test.js` +5: where the pieces fall (up to 28 s, cut inside pauses, meeting with no gap or overlap, no sliver at the end, a single quiet moment chosen), and a 72 s recording heard piece by piece with its words in order and their times in the whole recording; the options asked of the library are now `{ return_timestamps: "word" }`; the speech found in a stretch and the speech left without words (a skipped paragraph, a word past the end, breaths that stay under the limit); a piece the stand-in stops short on heard again into the title and the paragraph, in order and inside the piece, while a good piece is heard once; and a piece that cannot be heard better keeping its first answer, two levels deep at most.
- The browser tests' stand-in transformers.js answers piece by piece (`tests/browser/whisper_stub.py`), so take.py, combine.py, finish.py and suggest.py go through the new path.
- To be tried on the site with the 72 s phone reading that showed both faults.

---

## [1.9.0] Phone level (P)

Agreed with the maintainer on 16 November 2026, after a test on a phone. A phone recorded through the browser came through far quieter than the same phone's own recorder, and its takes needed about 35 dB of lift, more than the 30 dB allowed since 1.6.0. Laptop takes are raised as before.

#### Research first (16 November 2026)
- Three 20 s takes of the same passage on an iPhone in a quiet room, captured raw at 48 kHz in a throwaway test page. **The browser's processing off, as the studio records:** -53.6 LUFS, peaks at -34.7 dBFS, the room about 39 dB under the voice, the full frequency range. **Automatic gain alone switched on:** -52.1 LUFS, so the phone's browser barely reacts to it. **Echo cancellation, noise suppression and automatic gain all on:** -25.1 LUFS, but everything above about 11 kHz was cut and 15% of the 50 ms frames were digital silence, the pauses gated to zero. That is phone-call sound, and the studio's room tone, cleanup and noise reduction all need the room in the pauses.
- So the browser's voice processing is left off on every device, and a quiet phone take is lifted further in the finishing instead, where the room allows.

#### Added
- **A lift limit that follows the room** (`RRTSound.roomLevel`, `RRTSound.liftLimit`). Broadcast sound measures the room under the voice after cleanup and noise reduction, before the music bed is mixed in: the 10th percentile of 50 ms frames, leaving out digital silence (frames at -150 dBFS or below, such as padding). A recording may be raised as far as keeps that room at -60 dBFS or below for a -19 LUFS voice (`ROOM_CEIL`), so ACX's background rule holds too, up to 45 dB (`MAX_LIFT`). A noisy room, or one that cannot be measured, keeps the 30 dB of 1.6.0 (`MAX_GAIN`). A louder preset than -19 still gets the difference on top. The report gains `roomDb`, `liftByRoom` and `usualGainDb`.
- **The read-out says which limit held a take back:** "the most the studio allows even in a quiet room", or "raising it further would bring the background up too". When a quiet room let a take be raised past the usual limit, it says so: "the room was quiet enough to raise it by 35 dB (more than the usual 30)".
- **Check microphone reports the lift a take will need.** The voice part of the check is measured as a finished reading is (BS.1770), and the room is taken down by what noise reduction is set to remove (Gentle 12 dB, Medium 20, Strong 30). The check says how many decibels a take will need to reach the Settings preset's loudness and how far this room allows (`RRTPractice.lift`). Over the limit, it says how quiet the take would come out and to move closer (halving the distance makes the voice about 6 dB louder), with a tip for holding a phone, and the control bar line reads "too quiet: move closer" until the next check. The lift line takes the place of the older quiet-voice line. It is left out for the audiobook preset and the simple leveling, which level another way. The saved check (`rrtMicCheck`) gains `voiceLufs`, `liftNeed`, `liftMax` and `liftOver`.
- **Phone takes in 24 bits.** On a phone or tablet (`studio.PHONE`: iPhone, iPad, Android and other mobile browsers), the studio keeps each take as 24-bit samples (`studio.CAPTURE_BITS`, `toInt24`): in the browser while recording (IndexedDB chunks, with `bits` on the take), in Original, in a recovered take, and in a take sent to the computer. A phone's quietest detail sits near the bottom of 16 bits, where a 35 dB lift would bring it up to be heard. The files are 1.5 times the size, about 8.6 MB a minute at 48 kHz. A computer still records 16 bits. Older unfinished takes, without `bits`, are read as 16-bit.

#### Fixed
- **A quiet recording was cut short when opened.** Before cleanup, the studio trims the silence from both ends of a take, and it took anything under a fixed 0.012 (-38 dBFS) as silence. A phone's voice peaks around -35 dBFS, so the first real phone reading under 1.9.0's first build (72 s, the whole poem in its Original) kept only its first 24 s: everything after its last louder word was trimmed away before the MP3 and the review. The trim (`RRTSound.speechBounds`, moved out of master.js so it can be tested) now sets its threshold 20 dB under the recording's own peaks (the 99th percentile of 20 ms windows, so a click does not count), at least 12 dB over its room, and never above 0.012. It never trims more than before. On that reading it keeps 0.7 s to 72.3 s, with the threshold 15 dB over the room's peaks. A laptop take's ends may now keep a quieter last syllable or breath that the fixed threshold cut.
- **The simple leveling** (Broadcast sound off) only counted 50 ms blocks over -40 dB as the voice, so a phone take had none and was not raised at all. A recording quieter than that now counts the blocks within 20 dB of its loudest.
- **The warm-up's voice bar** lights from -60 dB (was -45), so a phone's voice shows.

#### Changed
- **Check microphone's tips fit the device.** On a phone or tablet, the tips are about the phone (rest it on something soft or hold it still, keep away from fans and the fridge, a small space full of soft things), and the tip for holding it is only shown there. A computer keeps the laptop tips.
- **A room that noise reduction will clear is no longer called noisy.** When the background sits under 35 dB below the voice but noise reduction is set to remove enough to bring it to 45 dB or more, the check says how far it is below the voice and that noise reduction lowers it by up to that much more, so it should be well down in the finished reading.

#### Not changed
- Recording still asks the browser for echo cancellation, noise suppression and automatic gain off, on every device.
- A laptop take at its usual level (23 to 27 dB of lift) is raised exactly as before.

#### Privacy
- Nothing new is collected from visitors, and nothing new is sent anywhere. The microphone check's saved result in the browser has four more numbers. PRIVACY.md says so.

#### Tests
- `tests/unit/phone.test.js` (14): the room measure and digital silence; the limit at 30, 37 and 45 dB and for louder presets and the audiobook mode; a phone-level take (-53.6 LUFS, room 59 dB down after noise reduction) reaching -19 with its background under -60; the same take without noise reduction held back where its room would pass -60; a laptop-level take raised as before; a quiet voice in a noisy room kept at 30; the music bed not counted as the room; the check's lift line within and over the limit, the control bar's warning, a stereo preset's loudness and a loud voice; the trim keeping a phone reading's quieter words after a few loud ones (cut at under 12 s of 73 before), a laptop reading never trimmed more than before and a loud one exactly as before, a click left out of the level; and the check's tips and room line by device and noise reduction.
- `tests/browser/phone.py` (26) with a stand-in microphone at a phone's level (about -54 LUFS, its room 39 dB down, written as floating point so it has detail below 16 bits): on an Android phone, 24 bits chosen, the microphone check's lift line and saved result, a take whose Original is 24-bit with that detail kept, its MP3 at -19 LUFS with the read-out's line about the quiet room, and a recovered take still 24-bit; on an iPhone, the take sent to the computer, arriving there in 24 bits and finishing at -19; on a computer, 16 bits as before; and a take whose first words are louder than the rest kept whole (the code before this fix gave a 1.9 s MP3 from a 7 s take). `tests/run.sh` checks the sent take is gone from the site once downloaded.
- `tests/upgrade.py` also upgrades from 1.8.0.

---

## [1.8.0] Keep a reading aside (P)

Asked for by the maintainer on 16 November 2026 and agreed the same day: a way to take a reading off its post without losing it, and to put it back later. Numbered 1.8.0, so the full Podcasting 2.0 feed moves to 1.9.0.

#### Added
- **Take off the post** on a reading's page (Foxfire Studio, Readings, a reading), in a line under the page's links, and a link to it in the post's Reading audio box. It asks first. The reading's audio file and its captions leave the Media Library for `uploads/rrt-kept/` (`rrt_kept_stash()`), each under a random name, in a folder that refuses direct requests like the folder for sent takes. The field the reading came from, the captions field, the music bed's credit (`_rrt_music_used`) and the pick for the feed (`_rrt_soundbite`) leave the post, so the post loses its player and the podcast feed loses the episode. The post's Recordings row stops being current, as for any earlier reading, so its listening history and curve stay on the reading's page (its recording labelled "kept aside"). The post's list (post meta `_rrt_kept`, at most 10 a post, `RRT_KEPT_MAX`) records everything Put back needs: the file's old place in the Media Library, title, dates and details, the field and the form its value had (an id or an address), and who took it off and when.
- **Readings to make** lists the post again, marked **Reading kept aside**, linking to its page.
- **Kept aside** on Foxfire Studio, Recordings: every reading kept aside with a player and **Download** that only people who can edit the post can open (`admin_post_rrt_kept_audio`, which answers byte ranges so the player can seek, and sends no-store headers), **Put back** and **Delete for good** (which asks first). The Recordings table marks a kept reading's row.
- **Put back** (`rrt_kept_put_back()`) makes the Media Library entries again at the same relative path (`rrt_kept_unstash()`), so the address and the feed's episode are the same. The field is filled in again in the form it had, with the captions, the music bed's credit and the pick for the feed, and the same Recordings row becomes current, so the listening history carries on with no new row. If another file has taken the old address in the meantime, the reading gets a new one and the message says so. If the post has a newer reading by then, that one is kept aside in its place (a swap).
- **A reading from the extra field in Settings** (a theme's own field) is handled the same way when the field holds an attachment id or the address of a file in the Media Library. When it points anywhere else, or the file is also another post's reading (`rrt_kept_shared()`), the file is never moved: only this post's field is cleared and remembered, and Put back writes it back.
- **Delete for good** removes the files and the record; the Recordings row stays, without an address. Deleting a post for good deletes its readings kept aside.
- `rrt_reading_kept` action after a reading is taken off or put back. Page caches are asked to drop the post (`clean_post_cache`, `litespeed_purge_post`), and the feed's cache is purged.

#### Changed
- **The Reading audio box only writes a field you changed in it.** Each field now carries the value it had when the screen opened (`rrt_was`), and saving leaves a field alone when it is the same. Before, an edit screen left open while a reading was put on the post another way (Use this recording, or Take off and Put back) wrote its old value back when the post was saved.

#### Privacy
- Nothing new is collected from visitors. A reading kept aside is the site owner's own audio, in a folder visitors cannot open. PRIVACY.md has a new section, Readings kept aside, and the retention table a new row.
- Deleting the plugin with the delete box ticked deletes `uploads/rrt-kept/` and every post's list; unticked, both stay for a reinstall. The delete box's wording names readings kept aside.

#### Tests
- `tests/php/keep.php` (55): take off and put back with the same address, captions, music credit, pick and Recordings row, the feed and Readings to make, the Reading audio box, an edit screen left open from before, a swap, an address taken in the meantime, Delete for good, the extra field by address, a reading outside the Media Library and one shared with another post, and a deleted post's kept files.
- `tests/browser/keep.py` (33) on a post with a 3 s WAV and captions from `tests/wp/keep.php`: Take off with its question answered no and then yes, the public address and the player gone, Readings to make marked, the Kept aside table with its private player (byte ranges from the start and from the end, refused when signed out or with a wrong code) and Download, Put back at the same address, and Delete for good with its question.
- Uninstall: a reading kept aside and the `rrt-kept` folder are seeded and checked both ways. `tests/upgrade.py` also upgrades from 1.7.0.

---

## [1.7.0] Clip suggestions (P)

Agreed with the maintainer on 15 November 2026, the third of the four small milestones that start Phase 1 of the plan after 1.4.0. For finding the moments worth a clip the way Riverside's Magic Clips and Descript do, from what the site already knows: the reading's captions and its listening curve. The full Podcasting 2.0 feed (1.8.0) takes its soundbites from here.

#### Added
- **Suggested clips** on a reading's page (Foxfire Studio, Readings, a reading), under Make a clip. Worked out on the site (`includes/suggest.php`): the reading's caption cues (`rrt_dash_cues()`) are lined up with the post's words (`rrt_blocks_from_html()`, shortcodes stripped) to give sentences that know where paragraphs and headings start and end (`rrt_suggest_sentences()`). A cue ending in a full stop, question or exclamation mark, or at a block's end, ends a sentence, so a long sentence split over two caption cues stays one.
- **Every stretch of 30 to 60 s** from a sentence start to a sentence end is scored (`rrt_suggest_candidates()`): starting a paragraph +2, a section +2.5, ending a paragraph +2; over 45 s counts against it, up to -3 at 60 s, so a paragraph's end is worth reaching for up to about 55 s; starting on a word that points back (`rrt_suggest_backrefs()`, filterable: And, But, This, It, They and the like) -2.5; each second of pause over 2 s -0.75 (at most -3). A stretch never ends on a title or heading, or on words that lead into what follows (a sentence ending in a colon, semicolon, comma or dash, such as "Declare:" before a chant). Zero-width spaces some editors leave in a post's text are dropped before matching. **With 10 or more plays** (`RRT_SUGGEST_PLAYS`), replays per hearing against the reading's own rate count up to +3 or down to -1.5, and the share of listeners still there at the stretch's end, against the median stretch, up to ±1.5.
- **The opening plus the best three** (`rrt_suggest_clips()`), each overlapping the ones before it by at most a quarter. Among stretches that score the same, the one furthest from those already chosen wins, so equal choices spread across the reading. Each shows its time, its first and last words, why it was chosen (and any caution, such as a start that points back), ▶ (plays it and stops at its end), **Make this clip** with its exact length and **Use for the feed**. With no captions, or a reading too short for 30 s, the page says so and the clip maker keeps the suggestions it had before.
- **The clip maker** shows the same suggestions in its row when opened from a reading's page; choosing one sets its start and its exact length, which joins the length menu ("38.4 seconds, as suggested"). A suggestion's clip keeps a breath before its first word and the tail of its last, inside the pauses either side: at most 0.15 s before and 0.25 s after, never more than 40% of the pause (`rrt_suggest_bounds()`, `RRTClip.span`), since a natural reading often leaves only 0.2 to 0.5 s between sentences. The clip maker then settles both edges on the quietest 20 ms of those pauses in the audio itself (`RRTClip.quiet`, `RRTClip.settle`), as word timings can be a little early or late. A clip made at a suggestion's exact length ends its sound with a 40 ms fade at the last word, not 1.2 s later under the end card. **After a take in the studio**, the opening and the most lively moment move to sentence edges from the review's words (`RRTClip.sentences`, `RRTClip.edge`, `RRTClip.span`): the review now passes each word's sentence, block and kind.
- **Use for the feed** keeps a stretch as the post's pick (post meta `_rrt_soundbite`: start, length, text and the reading it was made for), through `admin_post_rrt_soundbite`, which only keeps a stretch that starts and ends with a sentence of the reading. **Stop using** clears it. A pick made for an earlier reading is forgotten as soon as it is asked for (`rrt_soundbite_pick()`), so a new reading on the post clears it. 1.8.0 uses the pick, else the top suggestion.
- On a reading's page, ▶ on any moment now waits for the audio's length to be known before seeking, so the first click starts at the right place. A suggestion's ▶, and the clip maker's Play this moment, stop on time: they check every frame, where `timeupdate` comes only about four times a second.

#### Tried on a real reading before release
- A 14-minute real reading with a chant in it: suggestions lined up with its paragraphs. Three fixes came from it, all above: a suggestion had ended on "Declare:", the line before the chant; three equal suggestions had bunched in the first half; and the start of the next sentence could be heard at the end of a suggestion (its tail was a fixed 0.3 s, ▶ stopped up to a quarter of a second late, and a made clip's sound ran on under the end card).

#### Privacy
- Nothing new is collected from visitors. Suggestions use the reading's own captions and the listening curve the dashboard already shows. The pick for the feed is a choice about the post, kept with it; uninstalling always removes it.

#### Tests
- `tests/unit/clip.test.js` (+6): spans inside tight pauses, the edges settled on a pause heard in the audio, the review's words as sentences, moving a moment to sentence edges (a paragraph's end near 30 s winning), never ending on a heading or a lead-in, and a span's breath and tail.
- `tests/php/checks.php` (+30): clips inside 0.26 s pauses, long sentences over two cues, paragraph edges, the opening and three more within 30 to 60 s, overlaps, sections, headings, a lead-in ending in a colon, equal choices spread out, zero-width spaces, the 30 to 45 s preference, words that point back, long pauses, replays and listeners staying (and nothing from the curve under 10 plays), short readings and no captions, and the pick kept and cleared.
- `tests/browser/suggest.py` (32) on two posts run.sh makes from `tests/wp/suggest.php`: the same three-minute reading with captions, one at -19 LUFS (a finished reading) and one at -26 (a quieter reading put on by hand). The page's four suggestions, ▶ stopping at the end before the next sentence, Make this clip with its exact length, a clip made and checked for its length and a silent end card, the clip's level reaching -14 LUFS from both readings, Use for the feed, Stop using, a forged stretch refused, and the clip maker as the studio opens it. run.sh then checks the pick in the database and that a new reading clears it.
- Uninstall: the pick is seeded and checked gone with the delete box unticked. `tests/upgrade.py` also upgrades from 1.6.0.

---

## [1.6.0] Finishing presets (P)

Agreed with the maintainer on 15 November 2026, the second of the four small milestones that start Phase 1 of the plan after 1.4.0. For finishing a reading for wherever it is going, the way Auphonic does, on the reader's own computer.

#### Added
- **Finish for** (Settings, Broadcast sound): one menu replaces the old mode radio buttons and the Podcast loudness menu. Podcast, mono (-19 LUFS, -1 dBTP, mono MP3 at 128 kbps, the default and exactly what came before); Podcast, stereo (-16, -1, stereo MP3 at 128 kbps); Spotify and YouTube (-14, -1, stereo MP3 at 192 kbps); Broadcast, EBU R128 (-23, -1, a stereo WAV at 48 kHz and 24 bits); Audiobook (ACX, unchanged); Custom (-24 to -12 LUFS, a true peak limit of -3 to -0.5 dBTP, mono or stereo, MP3 at 128 kbps); and the simple leveling from before 0.5.0. The presets live in `includes/finish.php` (`rrt_finish_presets()`, `rrt_finish_spec()`); the studio is given the chosen spec and every preset as `RRT.sound.spec` and `RRT.sound.specs`.
- **True peak limit**, filled in by each preset and set by hand under Custom. The chain still aims a little lower for an MP3 (0.8 dB, so -1.8 for -1, as since 0.5.0), since encoding lifts peaks; a WAV is kept exactly, so 0.2 dB covers the change of sample rate.
- **Stereo:** the voice in both channels. The voice is processed in mono, 3 LU (10·log10 2) under the preset's number, so that both channels added together measure the preset's loudness, as BS.1770 and every loudness meter count them. **Why:** Podcast, stereo at -16 is then the same sound as Podcast, mono at -19, in the file shape some hosts ask for. `mp3.js` takes one or two channels (`encode(..., channels)`); LAME writes joint stereo, so the second channel costs almost nothing. The check after a stereo finish decodes both channels and measures them together (`RRTSound.lufsMulti`).
- **WAV** for Broadcast: resampled to 48 kHz by the browser, written as 24-bit PCM (`RRTSound.wav`), measured as written. A WAV gets no ID3 tag (it would stop being a WAV); Use this recording accepts a WAV, checked by its RIFF and WAVE header, as well as an MP3.
- **Loudness range** (EBU Tech 3342, `RRTSound.loudnessRange`): 3 s short-term loudness every 100 ms, gated at -70 LUFS and 20 LU under, 95th less 10th percentile. In the studio check after every finish, with a note over 10 LU that the quiet parts may be hard to hear in a car or on a bus. Not stored.
- **Finish for in the review:** a menu beside Download for a one-off version for another destination, made from the same voice (the take or its edited version) and the music bed when it is on, named after its preset (`...-broadcast.wav`). **Use this recording always uses the Settings choice**, and the download behind the review is unchanged. `studio.finishOneOff` in master.js.
- **Clips at -14 LUFS** (`RRTSound.toLoudness`, `RRTClip.clipLevel`): the level Instagram, TikTok, Facebook and YouTube play everything at, with peaks under -1.5 dBTP for AAC and Opus, raised by 12 dB at most. The readings page now loads sound.js for this.

#### Changed
- The chain corrects its level up to six times (each step scaled by what the last one achieved) instead of once, since loud presets with a low peak limit lose more to the limiter as the level rises.
- **A very quiet recording can be raised by 30 dB** (24 before), and the limit follows the preset: a louder target gets the difference on top (32 dB for Spotify and YouTube's mono equivalent). The corrections may go 6 dB further to win back what the limiter takes, and the read-out names the limit and suggests moving closer to the microphone. **Why:** on the live site, laptop recordings needed 25 to 27 dB with the background still 66 dB down after noise reduction, so one finished at -20.9 instead of -19 and its Spotify and YouTube version at -17 instead of -14. Recordings that already reached their target come out the same.
- `sound.mode` and `sound.target` are kept in step with the preset, so anything reading them sees what it saw before.
- The read-out names the preset and the file for anything other than Podcast, mono ("Finished for Spotify and YouTube: -14.0 LUFS, peaks at -1.4 dBTP, stereo MP3."), and the studio check's true peak rule follows the preset's limit. The download buttons say WAV for a WAV.

#### Upgrade
- A podcast loudness of -19 saved before 1.6.0 reads as Podcast, mono; any other becomes Custom with the same number, mono, -1 dBTP; audiobooks become Audiobook (ACX); the simple leveling stays. Read when the settings are read, so nothing is rewritten until Settings is next saved. No database change.

#### Tests
- `finish.test.js`: stereo loudness 3 LU above mono, quiet recordings needing 23 and 28 dB reaching -19, -16 and -14 (near silence still held back), EBU Tech 3342's first loudness range case (10 LU), each preset's plan, the chain landing six presets on their loudness and under their peaks, the check's loudness range and WAV items, the true peak rule following the limit, the WAV writer byte by byte, clip leveling, and the real encoder making mono, stereo from one channel and stereo from two.
- `checks.php`: every older choice read as its preset, saving keeping mode and target in step, Custom numbers kept in range, unknown presets refused, the preset list, and Use this recording refusing a file that is neither an MP3 nor a WAV.
- `finish.py` (on a post run.sh makes): the Settings menu filling in and locking each preset's numbers and keeping Custom's; a take as a stereo MP3 at -16 LUFS with the preset and loudness range in the read-out; a one-off Broadcast WAV from the review at 48 kHz, 24 bits and -23 LUFS; and, with Broadcast in Settings, Use this recording putting a WAV on the post.
- `upgrade.py` also upgrades from 1.5.0, and every older release is seeded with a podcast loudness of -16, checked to become Custom at -16.

---

## [1.5.0] Prompter view for glass rigs (P)

Agreed with the maintainer on 15 November 2026, the first of the four small milestones that start Phase 1 of the plan after 1.4.0. For reading through a beam-splitter teleprompter rig, where the screen is seen in a sheet of glass in front of the camera, or from a screen across the room.

#### Added
- **Prompter view** (`assets/js/prompter.js`, loaded after takes.js): a section of its own in the Controls panel and the **P** key. The studio goes full screen (the Fullscreen API, where the browser has it; on an iPhone only the bars are hidden) and shows only the script and a thin strip with the recording time, the time left and the studio's messages. The dock appears when the mouse moves, with **Controls** and **Leave prompter view**. **Why:** in a rig, anything other than the script is reflected into the reader's eyes.
- **Mirror:** None, Left to right (what most rigs need), Upside down, or Both. The stage, the countdown, the strip and the blank screen are mirrored with one CSS transform (`--pvMirror`); the dock and the Controls panel, which only the operator reads, never are. Upside down moves the strip to the screen's bottom, which is the top for the reader. Clicking a word still works through the mirror.
- **Prompter view keeps its own Size, Width and Line** (local storage `rrtPrompter`), because a rig screen is read from further away. The same sliders change whichever view is open: `studio.pvKeep` in `saveSettings()` keeps the prompter values and writes the normal view's. Size now goes up to 96 px in either view.
- **Shift** (left or right by up to 30%, to sit the script over the lens), **Fade above and below** (dims the script beyond a band around the reading line, through the stage's mask), and **Bright text on black** (white on pure black, which reflects most clearly).
- **Keys for clickers and foot pedals:** Page Down starts and pauses and Page Up goes back to the start of the sentence, or of the one before (`studio.jumpSentence`), in both views; in prompter view B or the full stop blanks the screen, plus and minus change the size by 4 px, F5 is caught so a clicker's "start show" button cannot reload the page, and Escape leaves.
- Prompter view keeps the screen awake while it is open, and steps aside by itself when a result or the review opens, since those are for the operator at the computer.
- `window.RRTPrompter`: `clean`, `transform`, `mask` and `keyAction`, pure and tested.

#### Tests
- `prompter.test.js`: stored settings checked and filled in, each mirror's transform, the fade's band around the reading line, and which keys belong to prompter view.
- `prompter.py` (on a post run.sh makes): opening and leaving by key and by button, the script, countdown and strip mirrored and the dock not, the menus hidden, both views' sizes kept apart and remembered, plus, Shift, Fade and bright text, Page Up and Page Down, blanking, F5 not reloading, stepping aside for a result, Escape, and upside down.

---

## [1.4.0] The backlog: readings to make, and a long post read in parts (P)

Agreed with the maintainer on 15 November 2026. Two ways to get more readings made: a list of what is still to read, and a way to read a long post over several sittings.

#### Added
- **Readings to make**, a new Foxfire Studio screen after Readings (listener screens' permission, `rrt_dash_cap()`). Every published post with no reading, newest first, sortable: its words, how long it **would run** at the reader's own pace (the median of their latest five full takes of 20 seconds or more, or the default pace until there are some), **How hard**, and the reader's takes of it so far. **Start** opens the studio on the post in a new tab; **Hide** takes it off the list (Show hidden, then Bring back). Tiles give the count, how long all of them would run and the pace used. **Why:** a backlog nobody can see is never worked through, and the estimate at one's own pace says whether a post fits an evening.
- **How hard** is Easy, Some care or Demanding, worked out per 100 words (a shorter post counts as 100) so a long post is not called hard for being long, nor a short one for a single name: each word that tripped the reader or Whisper before (a pronunciation note or a word taught to Whisper, for every post or that post) counts 2, each sentence over 30 words 2, each name (a capital inside a sentence, leaving out sentence starts, quotes, words after a colon, I, days, months and words already counted as known) 1, and each word of five syllables or more 1. Under 2 is Easy, under 5 Some care. The reasons show on hover, the first two under the label. **Why:** the words that caused trouble before are the surest sign of trouble again; the rest stand in for what the reader has not met yet.
- Posts are measured from their stored content (`rrt_blocks_from_html()`, split out of `rrt_post_blocks()`, with shortcodes taken out), so the list stays quick with thousands of posts: 800 posts took half a second in the test site.
- **Continue** in the studio, beside Combine takes, once a post's shelf has a take and a paragraph no take has read in full. It starts a new take one sentence before the first such paragraph, a run-up that Best of each can use from either take. Refused while a take is under way, and while Opening drill is on (it says why). The review's line after a take says how many paragraphs are still to read, or points to Combine takes once every one is read. **Why:** a ten-minute piece is easier to read well in three sittings, and the shelf already combines partial takes.
- **The coverage bar** across the top of the Combine takes screen: a cell for each paragraph or sentence, as wide as its words, in the colour of the take that reads it and outlined where none does. A cell scrolls to its paragraph.
- `RRTCombine.coverage()` (pure, tested), `studio.beginFrom()` and `studio.jumpWord()`.
- The option `rrt_backlog_hidden` (the hidden posts), removed with the settings when the plugin is deleted and tidied when a post is deleted.

#### Changed
- The roadmap's table marks every released version.

#### Tests
- `combine.test.js`: coverage with no takes, a take of the first paragraph (Continue one sentence back), a take that stops inside a paragraph, two partial takes that cover the post, and a gap before a paragraph that is read.
- `checks.php`: names, known words and very long words, a long sentence, How hard per 100 words, a plain script, posts with a reading left off the list, hiding, a deleted post leaving the hidden list, and the default pace.
- `combine.py`: a take of the first part, Continue (its label, where it starts, refusing a second take), the rest, the line pointing to Combine takes, and the coverage bar. The stand-in Whisper now tells takes apart by where their last word ends, since the studio trims the silence after it.
- `smoke.py` opens Readings to make and hides a post and brings it back; `translated.py` shows the screen in the made-up translation; the uninstall check covers `rrt_backlog_hidden`.

---

## [1.3.0] One reading from several takes (P)

From the roadmap's list of studio upgrades, agreed in outline on 14 November 2026 and in detail on 15 November. After two or more takes of a post, the reader chooses which take reads each paragraph or sentence, and the studio builds one reading from them.

#### Added
- **The take shelf.** Every take that has its Whisper review goes on its post's shelf in the reader's browser (IndexedDB `rrt-shelf`): takes recorded in the studio, opened with Open a recording, sent from a phone or recovered, whole or partial. Kept with it: the recording as Whisper heard it (16-bit), Whisper's words, the review's word times and flags, its speech level and a fingerprint. The newest six a post, for 30 days; the same take opened again only brings its review up to date. Opening drills, combined readings and phones are left out (a drill's review says so, since on a short post a drill looks just like a take), and nothing on the shelf goes to the site. The review's status line says which take number it became. **Why:** a take kept only when the reader remembers to mark it is the good take lost on a tired evening.
- **Combine takes**, a button in the review studio's header and beside Open a recording, shown once a post has two takes on its shelf. The screen lists the takes (with Remove) and every paragraph with a button for each take that read it, showing its flags there (details on hover) and ▶ to hear that take's reading. **By sentence** splits a paragraph. **Best of each** chooses the take with the fewest flags for each piece, flags weighed by kind and each join counted as 0.75 of a flag, so takes switch only for a clear gain; the screen starts from it. A take that starts partway supplies what it covers, and a piece no take reads is marked and stops the build. **Why:** choosing by flags is quick, and choosing by ear needs each take's version one click away.
- **Joins** between takes go in the pause before a sentence: the earlier take stops 20 ms after its voice and the later one starts in its own pause, so the pause there is the later take's. When the later take starts right at the word, the earlier take's pause is used and the room's background fills it. Takes are leveled to the one that reads the most (12 dB at most) and joined with 30 ms crossfades. **Hear the join** plays it, and **End**, **Start** (10 ms) and **Space** (50 ms) move it, as for a retake.
- **Build the reading** puts it together, then opens it like any recording: cleanup, the MP3 and the read-out run once over the whole, and the review uses the words Whisper heard in each take, moved to their new places, so Whisper does not listen again. Everything that works on a take works on it. Its Original downloads as one WAV (`-combined.wav`).
- **The plan is kept** for each post (which take reads each piece, the split paragraphs and each join's nudges), so Combine takes opens where it was left. After a combined reading goes on the post with Use this recording, the studio offers to clear the shelf; **Clear the shelf** is also on the screen.
- New `assets/js/combine.js` (`window.RRTCombine`, pure and tested in Node) and `assets/js/takes.js` (the shelf and the screen).

#### Changed
- The combined reading is not added to the take history, goals or charts, and Use this recording sends no take with it. **Why:** its numbers describe how it was put together, and insights would credit its delivery to one take.
- The review's run takes given Whisper words (`studio.presetChunks`) and puts each reviewed take on the shelf (`studio.shelfAdd`). The MP3 step reports where its trimmed stretch starts (`start`).

#### Tests
- New `combine.test.js`: pieces with and without a split paragraph, which takes cover a piece, flag weights, best of each (a clear gain switches, a small one does not, ties, partial takes, a piece no take reads), runs, a single take kept as recorded, the later take's pause at a join and every word where its moved time says, a quiet join, room tone for a take that starts at the word, leveling, nudges, the fingerprint, the speech level and cleaning a saved plan.
- New `tests/browser/combine.py`, run by `run.sh` on a post it makes: three made-up takes reviewed with a stand-in Whisper and shelved (the same take opened twice is kept once), best of each, flags side by side, a paragraph by sentence, a join's space, the plan after a reload, building, the built reading's review without the slips left out, the take history unchanged, and clearing the shelf.
- The MP3 encoder and noise model for the browser takes moved to `tests/browser/take_assets.py`, shared by take.py and combine.py.

---

## [1.2.0] Wider listening numbers (P)

The roadmap's 1.2.0, outlined on 14 November 2026 and agreed in detail on 15 November. It answers listening questions the dashboard could not answer yet, keeping the privacy promise: measured on the site, no cookies, nothing that follows a person from one day to the next. Every new option is off until a site owner switches it on, so a site's privacy policy stays true until a setting changes.

#### Added
- **Read or listen** (Overview and each reading, and the report email). Each visit (one listener, reading and day) falls in one of four groups: listened only, read and listened, read only, or passed through. A real listen is at least 30 seconds heard, or the whole of a shorter reading. Reading means scrolling at least 75% of the way through the post's text with the page on screen for at least a third of the time an average silent reader needs for its words (238 words a minute; at least 5 seconds). Visits with several page views add their time on screen and keep their furthest scroll. Kept as four counts in `rrt_daily` (`v_listen`, `v_both`, `v_read`, `v_pass`). **Why:** the player measured scroll depth and time on the page from 0.10.0, but no screen used them, so there was no way to tell whether visitors listened instead of reading. The time check stops a quick scroll to the comments counting as reading; 30 seconds is the bar most podcast and music statistics use, and a quarter of a long reading would hide people who chose to listen and then stopped.
- **Time to press play** (Overview and each reading, and the report email): the median wait and four bands (under 10 s, 10 to 30 s, 30 s to 2 min, over 2 min), for phones and tablets, for computers, and for both. Kept as a small histogram per device group in `rrt_daily.first_hist` (whole seconds under a minute, 5 seconds under 5 minutes, 30 seconds under half an hour). **Why:** long waits show the player is not where visitors notice it, and the answer differs between a phone screen and a computer's; a median of daily medians is wrong, so the histogram keeps an honest one for any period.
- **New and returning listeners** (Settings, Returning listeners, off by default). After a real listen, the player keeps the month in the visitor's own browser (`rrt_listened_month`); on a later visit it sends only a yes or no, whether that month is within 1, 3, 6 or 12 months (12 by default, matching how long visits are kept). Stored as `came_back` on the visit and as `l_new` and `l_back` in the daily totals. With the switch off the player removes the month. **Why:** the listener id changes every day by design, so returning listeners could not be told apart; a yes or no kept in the browser answers that without an id that lasts.
- **Downloads by podcast apps** (Foxfire Studio, Podcast feed, Count downloads, off by default). Each episode's enclosure points to `/podcast-file/<post id>/<file name>` on the site, which counts the download and redirects to the audio, never cached. Counted as the IAB Tech Lab's podcast measurement guidelines describe: once per listener, episode and day (a keyed hash of address, user agent and episode, with a daily salt of its own, `rrt_dl_salt`; hashes kept two days in `rrt_dl_seen`), only for a GET of the whole file or a minute's worth from the start (so two-byte probes and HEAD requests do not count), known bots and feed checkers left out, at most 30 an hour from one address. The app is named from its user agent (Apple Podcasts, Spotify, Overcast, Pocket Casts and others, a web browser, or Other). Counts in `rrt_downloads`; shown on the Overview under Podcast apps, by app and by episode, and in the report email. The short address keeps answering whether or not counting is on. **Why:** apps fetch the MP3 straight from the site, so feed listening was invisible; a short address that keeps working means episodes already saved in apps never break if counting is switched off again.
- **The Google Analytics link** (Settings, Google Analytics, off by default). New `assets/js/ga.js` passes `audio_start`, `audio_progress` (with `audio_percent` 50) and `audio_complete`, each with `audio_title`, to the page's own `gtag`, leaving out the same visitors as Foxfire Studio's measuring (Do Not Track and Global Privacy Control when respected, and the chosen logged-in people). Adds no tag of its own. **Why:** for sites that already use Google Analytics and want listening beside their other numbers, with no Google Cloud project or API key.

#### Changed
- Database version 7. Upgrading marks every visit still kept to be summarised again, so the new counts cover them; days whose visits are gone keep their old totals, and the Read or listen card says how many visits it counts.
- The privacy paragraph (Settings and WordPress's policy guide) gains a line for each new option while it is on, and is offered when any of listener analytics, the Google Analytics link or download counting is on.
- The visits CSV includes `came_back`.
- Uninstall: the day's download hashes and their salt are always removed; download counts go with the stats tables when the delete box is ticked.

#### Tests
- `rollups.php`: seven made-up visits on a real post of 476 words, sorted into the four groups by hand (a fast scroll, a short listen, a whole short reading, two page views adding up), returning listeners counted only for real listens, the histograms by device, the median and bands, older summaries left out of the groups, the histogram bins, and the privacy paragraph with each option.
- New `downloads.php`: the short address with and without a folder, which requests count (probes, ranges, HEAD, unknown sizes), app names and bots (okhttp is an app), once per listener, episode and day, the hourly limit, trimming, the feed's enclosure with counting on and off, and unpublished posts.
- The smoke test asks for a short address: a redirect to the reading that is never cached, and 404 for a post without a reading.
- The upgrade check confirms every kept visit waits to be summarised again; the uninstall check seeds and checks the new tables, option and counter.

---

## [1.1.0] Pronunciation notes and flags after edits (P)

Two studio upgrades, agreed on 15 November 2026. Pronunciation notes came from the roadmap's list of studio upgrades; flags after edits came from a request: after a retake, the flagged moments still described the take as recorded. The number 1.1.0 was held for listener reactions, which now takes the next free number when it is built.

#### Added
- **Pronunciation notes.** With Cues on, clicking a word offers **Pronunciation**: a short note such as "SOW-in" (40 characters at most) shown small above the word in the teleprompter. A note can be kept on that one word (**Only here**, for words such as "tear" said differently elsewhere), every time the word appears in the post (**Just this post**), or every time it appears in any post (**In every post**, the default). The nearest one wins. A word with a note offers **Edit note** and **Remove note**; **Clear** takes everything off the word, its note included. One-word notes are kept with the cue marks (`n` on a cue in `_rrt_cues`); a post's notes in the post meta `_rrt_notes`; a reader's own in the user meta `rrt_notes`, through the new `rrt/v1/notes` route. **Why:** names of people, places, herbs and deities are easy to say wrong, and a note is quicker to glance at than a memory. The note is drawn by the style sheet from a `data-note` attribute, so the word's text, its timing and the post itself never change, and the line spacing stays as it was.
- **Notes in the review.** Hovering a word in the review's script shows its note; a flagged word with a note shows it under the flag ("said as SOW-in"). When Whisper misheard words that have notes, one line offers to teach them all at once, for every post or this post. Teaching a word that has no note offers a box to add one. **Why:** a hard name is exactly the word Whisper mishears, and the note is what tells you whether you said it right.
- **Flags after edits.** As soon as a cut or retake in Edit audio covers a flagged moment, the flag is marked **Cut**, **Shortened** (a long pause Tidy shortened) or **Retaken**, a line says how many flags the edits cover, the tiles count what is left ("1 before your edits"), and the timeline dims the covered flags. Undoing an edit brings its flags back. `RRTEdit.flagsCovered` works out which flags edits cover, from each flag's stretch of the take (a skipped word: the gap where it belonged). **Why:** after fixing a line, the review still listed it as a problem, with no way to tell what was left to fix.
- **Check the edited version** (Edit audio). Whisper listens to the edited recording and it is compared with the script again. The flagged moments, tiles and timeline then show the edited version, with a switch between **Edited version** and **As recorded** and a button to play the edited version; clicking a flag plays the edited recording from there, and "I said it right" works as usual. Any further edit offers **Check again**. **Why:** marks from step one can only say an edit covers a flag; a slip inside a retake, or a click at a join, needs Whisper to hear the result. The take's own numbers in the history, goals and charts stay those of the reading itself, since they measure how it was read; the edited version's numbers show only in the review.

#### Changed
- Settings, Reader stats: the delete box now lists pronunciation notes among what it deletes. With it ticked, uninstall removes `_rrt_notes` and `rrt_notes`.
- A reader's own pronunciation notes are part of WordPress's personal data export and erase.

#### Tests
- Unit tests for the notes' cleaning and which note a word shows, and for which flags edits cover (a retake, a cut, a shortened pause, a skipped word, an edit elsewhere, a flag only partly inside an edit).
- `checks.php`: notes are cleaned (plain text, one per word, the first wins, cut to 40 characters), a cue with only a note is kept, a cue saved before 1.1.0 comes back with an empty note, the route saves and refuses as it should, and the export includes a reader's notes.
- `take.py`: notes are set through the cue menu (a space typed in the note, Enter, Escape, Only here, Edit and Remove), saved and loaded again; the review shows them, teaches a noted misheard word and offers a note after teaching another. The stand-in Whisper now mishears two words, and hears the edited version without the filler, so the take also checks the marks after Tidy, the tile counts, Check the edited version, the switch, playing it, and Check again after one more edit.
- The uninstall check seeds and checks both new fields; the smoke test checks visitors cannot save notes.

---

## [1.0.0] Stable (P)

The first stable release, released 15 November 2026: the 0.27 release candidate after its run on a live site, with one small change on the Settings screen and more automatic checks. The roadmap's finish line for stable is met (ROADMAP.md, 1.0.0).

#### Changed
- **Under Measure listening, "Off until you switch it on" shows only while listening is not being measured.** **Why:** the line was there on every site, so a site that measures listening was told it was off. The wording is unchanged, so translations are unaffected. `tests/php/checks.php` renders the Settings screen both ways.

#### Added
- **The checks also run on the oldest WordPress and PHP the plugin claims.** A second job on GitHub runs `tests/run.sh oldest` on PHP 7.4 and WordPress 6.0: PHP syntax, the PHP checks, the rollups, the browser smoke test and a scan of the debug log. `tests/wp/setup.sh` takes `SQLITE_REF` (WordPress 6.0 needs the SQLite plugin's v2.1.3) and `WP_DEBUG_DISPLAY`. **Why:** the plugin header says it works there, and until now that was checked once by hand, on a newer PHP. The plugin itself is unchanged.
- **A test of the scheduled jobs, as if days and weeks had passed** (`tests/php/time.php`): data is made with old dates and each hourly and daily job runs, and the report's clock is moved to 7:59 and 8:00 on the 1st and on to the next month. **Why:** these jobs only show a mistake after days on a real site, and a release candidate was waiting on exactly that. Every check passed with the plugin unchanged.
- **The browser tests sign in through one shared helper,** `tests/browser/signin.py`, which waits for the login page to settle, tries twice, and prints WordPress's own message if both tries fail. **Why:** on GitHub the sign-in once landed back on the login page for no reason the log could show; the same code passed every other run and 40 times in a row locally.

---

## [0.27.1] A plain name for the Dark preset (P)

#### Changed
- **The Dark preset's internal key is now `dark`.** It used to be named after the site the preset was first made for. **Why:** the key is saved in each site's settings and appears in the code, which may be published, and CONTRIBUTING keeps the names of sites out of the repository. The label on screen, "Dark (void, plum and gold)", is unchanged.
- **No automatic move of the old name.** Writing one would have put the old name back in the code. A site whose saved settings still name the old key keeps every colour, font and wording it saved, since saved values always win over a preset's defaults; Settings shows its look as Custom. Applying the Dark preset again gives it the new key, along with the preset's own wording, colours and fonts.

#### Tests
- The upgrade test seeds a Custom look and checks it is kept, in place of the old key.

---

## [0.27.0] Docs pass and the 1.0 release candidate (P)

The release candidate for 1.0.0. Nothing a reader or visitor sees changes in English.

#### Changed
- **readme.txt in WordPress.org's shape:** a short description under 150 characters, `Tested up to: 7.1`, Description, Installation, Frequently Asked Questions, External services (each outside service, what it is used for, when, and links to its privacy policy and terms), Changelog and Upgrade Notice. **Why:** these are the sections the plugin directory reads and its reviewers ask for, and a site owner deciding whether to install the plugin looks for the same answers.
- **Every document checked against the code.** README: the status, the menu, the music bed and Send to my computer, the repository layout, updating and deleting, the Edit audio and retake keys, what is stored on the site, the larger Whisper model, and mp4-muxer as the one bundled outside file. ARCHITECTURE: `podcast.php` and `sent.php`, which were missing, the uninstall routine as it is since 0.26.0, the boot keys added since 0.13.0, every browser storage key, the music bed in the data flow, and a new section listing the test files. CONTRIBUTING: where a version number goes (six places), building the zip, checking the release asset, adding a release to the upgrade test, and testing against another WordPress version with `WP_REF`. SETUP: coming from Reading Room Teleprompter, Send to my computer, the podcast feed, the music bed, the intro video and deleting the plugin. PRIVACY: the version, a broken link, the dashboard screens each role sees, the browser storage keys, the new wording of the delete box, the show name's last fallback, the MP3's genre tag, and the order of its sections.
- **The last split sentences joined for translators.** Six sentences on the settings and Sources screens were three or more strings around a code name (`?t=1:45`, the shortcode and `rrt_player()`, `.entry-content`, the privacy paragraph's heading, the tagged link and the `utm_` tags, `utm_campaign`). Each is now one string with numbered placeholders and a note for translators. **Why:** a translator can only put a sentence in their language's order when it is one string. Code names are no longer offered for translation.

#### Fixed
- **A made-up name in two examples.** The Read by box's placeholder and the help under the credit field now say "read by Robin". **Why:** CONTRIBUTING asks for no real names in the repository, and the example used a real name.
- **Python's bytecode cache is no longer in the repository,** and `.gitignore` keeps it out.
- **PRIVACY.md now mentions the share page's fonts.** A friend opening a shared take loads the studio's fonts from Google Fonts when they have not been downloaded to the site; the privacy notes said only the teleprompter did.

#### Tests
- `tests/upgrade.py` signs in more sturdily on each fresh test site: it waits only for the answer to the sign-in form, not for the dashboard to finish loading, and tries once more if no answer comes. **Why:** WordPress runs its first scheduled jobs on that first request, and the sign-in timed out now and then, in the workspace and on GitHub.
- Checked by hand for this release: the PHP checks, the listener rollups and the browser smoke test pass on WordPress 6.0 (the oldest version the plugin claims) and 7.1.4, as well as on the newest WordPress the automatic checks use.

---

## [0.26.0] Upgrade and uninstall checks (P)

#### Changed
- **Deleting the plugin with the box ticked now leaves nothing of its own behind.** The box under Reader stats, now worded "When the plugin is deleted, also delete everything else it keeps" with the full list, also removes each post's reading, captions and Read by links (`rrt_audio`, `rrt_captions`, `rrt_credit`) and the self-hosted Whisper files in `uploads/rrt-whisper`. **Why:** a full delete should be full. The MP3s and captions themselves are Media Library files and are never deleted. The Whisper files used to stay in case a second copy under the old name used them; that copy is long gone.
- **Always removed on delete, as well:** the dashboard's freshness marker and the comment limit's counters (`rrt_dash_fresh`, `rrt_shn_*`), and the podcast feed's address in WordPress's saved rewrite rules, which WordPress rebuilds on the next visit.

#### Fixed
- **Your progress no longer shows PHP warnings for goals saved in another shape** or for a measure that no longer exists. Goals are cleaned when read, the same way the form cleans them when saved.

#### Tests
- **`tests/upgrade.py`:** each release that changed the database or the folder (0.3.0, 0.3.2, 0.9.0, 0.10.0, 0.11.0, 0.11.1, 0.12.0, 0.14.0, 0.17.0) is installed on a test site of its own, with settings saved as a site owner would, a made-up row in every table and every post and person field the plugin keeps, then upgraded the way a site owner would: the Reading Room hand-over and deleting the old copy, or replacing the folder. Checked after each: the version and database version, every table and column against a fresh install, the rows, every saved setting, the fields, every screen with no PHP errors, and the studio opening a post. 376 checks. The zips come from the releases of the repository the test runs in, fetched with the GitHub CLI and never committed; where there are none, it skips.
- **`tests/wp/uninstall.sh`:** the real uninstall on a copy of the plugin, keeping data and deleting it, with something of every kind stored first (`tests/php/uninstall-seed.php`, `tests/php/uninstall-check.php`). Against 0.25.3's uninstall it finds the gaps above.
- Both run in `tests/run.sh` and on GitHub.

---

## [0.25.3] Download in the review studio's header (P)

#### Added
- **Download in the review studio's header**, beside Use this recording. It gives the same file as the download button in the "Your recording" panel behind the review: the take, the edited MP3 once Make edited MP3 has run, and the music bed's mix when the switch is on. Its label follows that button's, so it reads "Download edited MP3 with music" when that is what it gives. **Why:** after editing, the edited MP3 could only be reached by leaving the review with Back to teleprompter, which was easy to miss. The new button clicks the one behind the review, so the file's name, the mix and forgetting the unfinished take stay in one place.

#### Tests
- `tests/browser/take.py` checks the header Download shows with the take, matches the other button's label, and gives the same file with music on and off, before and after the edited MP3 is made.

---

## [0.25.2] The studio's page areas in their own files (P)

#### Changed
- **The rest of `teleprompter.js` moved into seven files**, loaded in this order before it: `studio-core.js` (window.RRT and the page's controls, settings, choosing a post, the intro, the word spans, timing, highlighting, the animation loop, wake lock, play and countdown, the opening drill, the keys, the practice buttons), `record.js` (unfinished takes and their recovery, Send to my computer, the microphone and its capture, finishing a take with its read-out, cleanup, file and downloads), `cues.js` (cue marks), `voice.js` (voice following), `master.js` (the encoder and sound workers, tags, artwork, chapters, the music bed and mastering), `review.js` (Whisper, takes and reader stats, learned words, the review screen, Use this recording, captions, clips, sharing, opening a recording) and `editor.js` (Edit audio and retakes). `teleprompter.js` is now a 42-line starter that measures and paints the script, starts the loop and offers a sent or unfinished take.
- **The files share one state object, `window.RRTStudio`**, made by studio-core.js. A name another file needs is put on it once and borrowed at the top of each file that uses it (`var settings = studio.settings;`), the way 0.25.1's files borrow the engines. A name that changes after it is set (whether a reading is playing, the current word, the take's mode and about thirty more) lives on it in every file, as `studio.playing` and so on, so all the files see the same value.
- **Moved without rewriting.** A script did the moving: it checked that nothing runs while a file loads before the file it needs has loaded, and the result was checked line for line against 0.25.1 (every line is there once, with only `studio.` added in front of shared names that change). The one order change it found, the two click handlers on Download MP3, keeps its behaviour, since the music handler listens in the capture phase and runs first either way. With no post chosen, every file stops at the same point the one file used to.
- Each file is its own script with its own translations (`rrt-studio-core`, `rrt-record`, `rrt-cues`, `rrt-voice`, `rrt-master`, `rrt-review`, `rrt-editor`, each needing the one before it). The .pot has the same strings, with new references.

#### Tests
- **`tests/browser/take.py` reaches every part of the studio:** a take recorded with Chrome's stand-in microphone (the Original, the MP3, and recovery after a reload), the voice-only and music downloads with their lengths and the music credit in the tags, Tidy, a cut and Undo, and the edited MP3 with and without music. `tests/wp/music.php` gives the test site a music bed for the run.
- `tests/browser/translated.py` checks each new file brings its own translations.
- **The browser tests could lose their sign-in now and then**: they clicked Log In and waited for a page load the login page had already reached, so the next page could cancel the sign-in, and smoke.py would go on checking the login page. They now wait for the sign-in itself and stop if it fails. The test site is also served by four PHP workers, and smoke.py waits for the studio's words before counting them.

---

## [0.25.1] The studio's engines in their own files, with tests (P)

#### Added
- **Retake start and Retake end** in a retake's box: trim the new recording's own beginning or end by ear, 10 ms a step, for a false start or a breath that Whisper did not count as a word. The readout says how much more the retake trims or keeps than as fitted; Reset puts them back too. The retake keeps its whole recording, so its ends can be changed again after it is opened again. Start and End still decide how much of the old reading is cut; Space still only adds room tone.

#### Changed
- **Four parts of `teleprompter.js` moved into files of their own**, each loaded before it and usable in Node: `clean.js` (`window.RRTClean`: cleanup before the MP3), `readout.js` (`window.RRTReadout`: the read-out after a take), `compare.js` (`window.RRTCompare`: the comparison of a take with the script, and the word helpers `clean`, `norm`, `similar` and `STOP` that voice following and the editor share) and `whisper.js` (`window.RRTWhisper`: the Whisper worker). The code moved unchanged, checked line for line against 0.25.0; the one change inside is that `compareReading` takes the cue marks as an argument instead of reading the studio's own list. `teleprompter.js` is about 1,250 lines shorter.
- **The clip maker uses the studio's Whisper worker** in place of its own older copy, so both run the same code, and clips gain the phone settings (one thread and the smaller build). In WP-admin, `whisper.js` loads before `clip.js`.

#### Fixed
- **A false start at the beginning of a retake stayed in** ("Trip, TRip"). When Whisper heard the first selected word twice, the fit started at the first copy, and when it heard nothing but the selected words it trimmed nothing. The fit now finds the selected words as a run and starts at the copy the rest follow (the later one on a tie, since a reader who stumbles starts again), with `RRTEdit.fitRun`.
- **A retake right after another one was dropped.** When one line was retaken and then the line straight after it, the two retakes met at the same pause, and the editor kept only the first: Hear it in place, Use this take and the edited MP3 all played the second line as first read. Retakes that only meet now both stay, a crossfade apart; two retakes over the same words still keep one.
- The retake box's help text, two Space titles and "none" are now translatable.
- **Load from URL** only matched an address written exactly as the site writes it. `teleprompter.js` declared a function called `norm` twice: one tidies a web address (drops https, www, a trailing slash), the other simplifies a word for the comparison, and the second silently replaced the first. The address one is now `normUrl`.

#### Why
- The comparison had no tests because it lived inside the studio's one big file, which only runs in a browser with a page around it. In its own file it is tested on its own, which was the last part of the 1.0 test suite still missing, with the listener rollups.
- These four parts hardly touch the page, so they could move with almost no change. The page code (recording, the review studio, the editor) shares much more and moves in 0.25.2.

#### Tested
- Unit tests in Node: cleanup (7: the FFT, length and speech kept, the hush lowered only when asked, a click before a phrase, a bump in a pause), the read-out (9: pace, rests, shrinking pauses, faded endings, pitch movement, the frames, the pace lines), the comparison (25: skips, misreads, other forms, fillers, repeats, added and ignored words, numbers, sound-alikes, contractions, hyphens, taught words, marked and unmarked pauses, unfinished, rushed, flat and faded sentences, paragraph scores, ordering, Whisper's chunks, the word helpers) and the Whisper worker (7, its own code run with a stand-in transformers.js: the site's copy, jsDelivr, phones, falling back, errors, one load reused). Made-up audio for them in `tests/unit/synth.js`.
- `tests/php/rollups.php` (29): a day of made-up listeners rolled up and every number compared with a hand count, rolling up twice, the curve over two days, summing timelines, drop-off points, and the hourly job leaving live sessions for later.
- Retakes: 15 unit tests (`retake.test.js`: the fit with false starts, restarts, run-ups, repeats and words never heard; trimming, its limits and readout; the recording and trim carried through the edit list and the nudges; two retakes back to back both kept and both heard, in order, with none of the old reading between them), and 11 more steps in `take.py`: a retake with a stand-in microphone whose Whisper hears a false start, trimmed; Retake start and end, Reset, Use this take, and the trim kept when the retake is opened again.
- `tests/browser/take.py` (16 at first, 27 with the retake): a recording through cleanup, the MP3 (the real encoder and noise model from npm, checked against their fingerprints), a stand-in Whisper and the review: the skipped word, the filler and the one unmarked long pause shown (a marked one is not), an accuracy, the read-out, an MP3 to download, Use this recording, and the reading's page offering a clip with the shared worker. Run against 0.25.0 it passes everything except Load from URL, so the review behaves exactly as before.

---

## [0.25.0] Groundwork for 1.0: translation-ready, and automatic checks (P)

#### Added
- **Translation-ready text.** Every word the plugin shows goes through WordPress's translation functions, text domain `foxfire-studio`: the WP-admin screens, the settings, the report email, the podcast feed's episode notes, the studio, the share page, the clip maker and the public player. Sentences built from pieces became whole sentences with placeholders (`sprintf`, `_n` for plurals), each with a translator comment saying what fills it. The plugin header has `Domain Path: /languages`, the text domain loads on `init`, and `languages/foxfire-studio.pot` lists all 1,600 or so strings.
- **Translations for scripts.** Each script takes `__`, `_n` and `sprintf` from `wp.i18n` when the page has it and keeps its English text otherwise (unit tests in Node, workers). New `includes/i18n.php`: `rrt_register_script()` registers a script with `wp-i18n` and `wp_set_script_translations()`; the studio and share pages now print their scripts with `wp_print_scripts()`, which puts `wp.i18n` and the translations in front of them; `rrt_script_translations_inline()` hands the clip maker, the MP3 encoder and the noise model their translations before the studio loads them. The dashboard and clip scripts in WP-admin get `wp-i18n` and their translations the usual way. Script translations are the JSON files `wp i18n make-json` makes.
- **The public player** takes its few words (play, pause, speed, resume, "of", chapters) from the server in its `data-rrt` settings (`text`), so visitors' pages load no translation script.
- **Inline scripts** on the settings and podcast screens print their words already translated.
- **Automatic checks on every change.** `tests/run.sh` runs: PHP and JavaScript syntax; `tests/php/placeholders.php`, which checks that every translated format has the right number of arguments (a mismatch stops a page on PHP 8); unit tests for the pure helpers in `tests/unit/` (Node's own test runner); PHP checks inside a throwaway WordPress with SQLite (`tests/wp/setup.sh`, `tests/php/checks.php`); a browser smoke test of every screen, the studio and a public post, including that the private REST routes refuse visitors (`tests/browser/smoke.py`); a check that the `.pot` is up to date; and a test translation (`tests/wp/pseudo.py` wraps every string in ⟦ ⟧) shown through every screen, the studio's scripts, the scripts it loads later and the public player (`tests/browser/translated.py`), which also lists any English left showing. GitHub Actions runs the same script on every push and pull request (`.github/workflows/tests.yml`).
- `tools/make-pot.sh` rebuilds the `.pot` after any change of wording.

#### Changed
- Comparisons that used English text now compare with the translated text, so they still match in another language (the theme palette's "WordPress defaults" group, the report's "another report is being sent" retry check, and button labels the studio reads back).

#### Why
- **One switch for every string.** Wrapping text where it is written keeps the English in the code, where it can be read and changed, and lets WordPress's own tools find it for translators.
- **The player stays light.** Visitors are the one audience who never asked for the plugin, so their pages carry only a few hundred bytes of words in the player's settings, and no extra scripts.
- **Checks that run themselves.** The most common mistakes in this kind of change are a placeholder count that does not match and a string that never reaches the screen. Both are now caught before anything is installed.

#### Tested
- In English, the visible text of every screen, the studio and the share list was captured with 0.24.0 and with 0.25.0 and compared: the same words, line for line (only whitespace between inline tags differs).
- The full run: 202 translated formats, 26 unit tests, 16 PHP checks, 59 smoke checks and 38 translation checks pass, locally and on GitHub.

---

## [0.24.0] A music bed (P)

#### Added
- **A music bed.** Settings has a new **Music bed** section: tracks from the media library (Add tracks, several at once; audio files only), each with an optional credit, a **Default** for the show (or "No music bed unless a post chooses one"), **Under the voice** (Softer 24 dB, Standard 20 dB, Fuller 16 dB) and **Long pauses** (let the music rise 6 dB in pauses of over 2 seconds; off by default). Setting `music` {beds [{id, credit}] (up to 20), default, depth, swell}, kept when a preset is applied (`rrt_sanitize_music()`).
- **Each post can choose** the show's default, none, or any of the tracks, in its Reading audio box (post meta `_rrt_music`: empty for the default, `none`, or a track's id). `rrt_music_for( $post_id )` resolves it; boot key `RRT.music` {id, url, title, credit, depth, swell, own}, null for none.
- **With each take:** a **Music bed** switch under the finished take and in the review's header (the two stay in step), switched on for every new take, and **Listen with music**, which makes the mix and plays it. With the switch on, the download button reads "Download MP3 with music", and **Download MP3** and **Use this recording** give the mix, under the same file name. Switched off, they give the voice-only MP3 as before.
- **The mix** (`sound.js`: `mixBed`, `BED`): 4 seconds of music alone, a dip that is complete 0.15 s before the first word (0.6 s long, a cosine curve), the music low under the voice until 0.4 s after the last word, a 1.2 s rise, and a 5 second tail ending in a 3 second fade. Levels come from loudness (BS.1770): the music alone sits 6 dB under the voice and, under it, the chosen depth. A track shorter than the reading loops with a 3 second equal-power crossfade; a longer one is cut and faded. The music starts from silence over 1 second. In the broadcast sound chain it joins after compression and before loudness, so the mix is leveled to the target and true-peak limited as one; with the broadcast sound off it is mixed before the simple leveling.
- **Chapters and captions move with the music:** chapters after the first, and every caption, start 4 seconds later in the mix. The first chapter still starts at zero.
- **The credit:** written into the MP3 as a comment (`COMM`, "Music: ..."; `RRTId3.build` takes `comment`), and in the podcast feed's episode description and notes. **Use this recording** sends the track's id (`music`), and the post remembers it in `_rrt_music_used` {audio, bed, credit}; `rrt_music_credit_used()` gives the credit only while that same MP3 is still the post's reading.
- **The intro's cover.** When the intro is going to play, a small script at the top of the page (the same checks the intro makes: a video chosen, not yet seen in this tab, not switched off, no reduced motion, no sent take being opened) blacks the page out from the first paint (`html.rrt-intro-wait`). The cover lifts once the intro's own overlay shows, at once when the intro will not play, and after 4 seconds whatever happens. A video that has not started playing within 4 seconds is now let go too, so the studio is never kept waiting. The script has no double ampersands or line comments and carries `data-no-optimize`, `data-no-defer` and `data-no-minify` for caching plugins.

#### Fixed
- The **Choose** button for the intro video, and Add tracks, look for the media library's scripts when pressed. The settings page prints its own script before those scripts load, so the intro's button could do nothing.

#### Why
- **Mixed when the take leaves the studio.** The review plays and edits the voice-only MP3, so Whisper's word timings, the edit tools and the retake splices all work exactly as before, and the Original download stays voice only. Mixing last also means the music never jumps at an edit, and changing or dropping it needs no new recording.
- **Before loudness, after the voice chain.** Compression, de-essing and breath control are for the voice, so they run on it alone; loudness and the limiter run on what listeners hear.
- **Steady through pauses by default.** A rise in every pause can pull attention in a quiet reading; it is there for readers who want it.

#### Tested
- 28 checks of the mix in Node (`music_unit.js`): loop length and level at the joins, a long track cut, the first and last word and long pauses found, the music 6 dB under the voice alone and 20 dB under it through a pause, the dip done before the first word, the rise after the last, silence at both ends, the swell rising 6 dB and back down before the next word, a too-short pause skipped, each depth, an unknown depth, and the whole chain landing on -19 LUFS under the true-peak ceiling with the bed reported.
- 41 browser checks (`music24.py`): the boot data, the switch and the label, Listen with music and Stop, the voice-only file kept behind the button, the downloaded mix 9 seconds longer at -19 LUFS with its credit, the music a few dB under the voice alone and about 20 dB under it between words, switched off giving the voice only, Use this recording putting the mix on the post with its length and the post remembering the track, the credit for the feed and its end once another reading is in place, a post set to none, the swell and depth settings; and for the intro, the studio hidden from the first frame and never seen before the intro, the cover lifting when the intro shows, nothing the second time in a tab, nothing when switched off or with reduced motion, a stalled video let go in about 4 seconds, and no cover script when no intro is chosen.
- 13 admin checks (`admin24.py`): the section, saving credit, depth and swell, removing the default track, refusing a video or a repeated track, the Reading audio box options, and saving none, a track, and the default.

---

## [0.23.0] An intro for the studio (P)

#### Added
- **An intro video.** Settings, Teleprompter look has **Intro video**: choose a video from the media library (Choose, Remove, with a preview). The teleprompter then opens with it, full screen over a black background, once in each new tab (`sessionStorage` `rrtIntroSeen`). It starts with sound when the browser allows; when the browser refuses, it plays muted and offers **Sound on**. **Skip** (with the focus, so Space or Enter skips, as does Escape) ends it with a short fade; it also closes by itself when the video ends or cannot play. **Skip it from now on** switches it off in that browser (`localStorage` `rrtIntro`), and **Play the intro** in the Controls panel (Reading) switches it off and on. While it plays the studio's keys wait, so Space cannot start a reading behind it.
- It never plays when the browser asks for reduced motion, or when the page opens a take sent from a phone (`?rrt_sent=`).
- Setting `intro` (an attachment id, only a `video/` type is accepted; kept when a preset is applied); boot key `RRT.intro` {url, type}, null when none is chosen or the file is gone.

#### Why
- **The video lives in the media library,** so a site's own intro stays out of the plugin and the repo, and other sites can use theirs or none.
- **Once in each new tab** rather than on every page load, since the studio reloads for posts and hard refreshes.

#### Tested
- 30 browser checks: nothing without a chosen video, the Settings picker, an image refused, the video saved and previewed, the intro playing with Skip focused, another key not ending it, Space skipping it without starting the studio, not again in the same tab, the Controls switch, playing in a new tab and closing by itself at the end, Skip it from now on switching it off and the switch following, a new tab going straight to the studio, the switch turning it back on, Escape, no intro when opening a sent take, muted with Sound on when sound is not allowed and Sound on unmuting, none for reduced motion, and the controls fitting a phone.

---

## [0.22.0] The podcast feed (P)

#### Added
- **A podcast feed** (`includes/podcast.php`). Switched on in the new **Foxfire Studio, Podcast feed** screen, every published post with a reading becomes an episode, newest first, up to 300. The feed is RSS 2.0 with the iTunes tags Apple Podcasts and Spotify require (title, link, language, description, author, owner name and email, square cover art, category and subcategory, clean or explicit, episodic) and the Podcasting 2.0 tags `podcast:guid` (a version 5 UUID of the feed's address, made once and kept), `podcast:locked` (on by default, with the owner email) and, for each episode with captions, `podcast:transcript` (`text/vtt`, `rel="captions"`). Each episode has the reading's MP3 with its size in bytes and its type, its length, a guid that never changes for that post (`host:reading:ID`, so recording the reading again updates the episode), the post's date, its excerpt (or its first 55 words) and its credit. Address: the site's `podcast` feed (`/feed/podcast/`, or `?feed=podcast` with plain permalinks).
- **The Podcast feed screen** checks what directories ask for before submitting (cover art a square JPEG or PNG between 1400 and 3000 pixels, a description, a valid owner email), counts the episodes and notes any whose size could not be measured, shows the feed's address with Open it, and links to a validator, Apple Podcasts Connect and Spotify for Creators. Settings live in their own option, `rrt_podcast`, removed on uninstall.
- **A "read by" credit** for each reading: a **Read by** field in the post's Reading audio box (`rrt_credit`), or another custom field named in Settings, Listening player (`player.credit_key`; the box wins). Shown under the player's label (`.rrt-credit`) and in the feed's episode description.

#### Why
- **Episode guids never change** once a feed is out; an app that sees a new guid treats it as a new episode. Keying it to the post means a re-recorded reading replaces its episode.
- **Off until switched on**, and the screen says plainly that the owner email is public.
- **Podcast app plays are not in the listener numbers:** apps download the MP3 directly, which the player's analytics never see.

#### Tested
- 53 checks: the feed off by default (404) and off again, the screen's checks for missing art, description and email, a wide image flagged as not square, a square one passing, the category saved, the feed's address and next steps; the credit under the player logged out, from the extra field, the box winning, and no line without a credit; the feed served as RSS with nothing before the XML declaration, every channel tag, escaping, category and subcategory, art, clean and explicit, owner email, the lock with and without, `podcast:guid` equal to Python's UUIDv5 of the feed's address, the enclosure's type and exact size, the duration, a stable guid, the credit, the transcript, the date, and password-protected and draft posts left out. Saving the Read by field checked directly. The UUID maker also matches the namespace's own example (`podnews.net/rss`).

---

## [0.21.0] Send to my computer (P)

#### Added
- **Send to my computer.** On a phone, the waiting Whisper review now offers **Send to my computer** beside Try it here anyway. It uploads the take's original recording (the WAV from lossless recording, or what the phone's recorder made), with the post, how many words the reading reached, its length, and any opening drill, its length and focus, showing how far the upload has got. Once it arrives, the phone no longer keeps the take for recovery.
- **Waiting on the computer.** When the teleprompter opens on a computer, on any page, it offers the newest waiting take ("A 4:32 take of ... sent from your phone ..., is waiting"), with Open it (or Open that post), Delete (asked twice) and Not now, and says how many more are waiting. Opening it fetches the take and opens it the way an unfinished take is recovered: cleaned up, made into an MP3, read out and given the full Whisper review, with editing, retakes, sharing and Use this recording as usual. The offer never appears on a phone, and it comes before the unfinished-take offer instead of on top of it.
- **Foxfire Studio, Recordings** lists waiting takes under "Sent from your phone", each with Open in the teleprompter (straight into the review) and Delete.
- `includes/sent.php`: folder `uploads/rrt-sent/` with an `.htaccess` that refuses direct requests; each person's list in user meta `_rrt_sent`; routes `GET rrt/v1/sent`, `POST rrt/v1/sent` (edit_post on the post), `GET rrt/v1/sent/<id>/audio` and `DELETE rrt/v1/sent/<id>`, all for the sender only; boot key `RRT.sent`; the hourly job shared with shared takes deletes takes past 14 days; deleting a user deletes theirs; uninstall with the stats box ticked removes the folder and the lists.

#### Changed
- A take reviewed from a phone is saved to Your progress with the mode `phone` and the time it was sent, so opening it twice updates one take.

#### Why
- **The original, not the MP3**, so cuts and retakes on the computer start from the full-quality recording (about 5 MB a minute).
- **Only when asked**, so drills and false starts stay on the phone.
- **Deleted once safe on the computer**: downloading the MP3 or the original, or Use this recording, deletes the site's copy; just opening it does not, so a closed tab loses nothing. 14 days at most, as for shared takes.

#### Tested
- 35 browser checks with a stand-in microphone and Whisper, an iPhone-sized phone and a computer: the offer and its wording, the upload and the phone's message, one WAV saved with its details and 14-day limit, the phone's recovery copy removed, no offer on the phone, logged-out visitors (401) and another editor (403) refused, the computer's offer with Open that post, Delete asked twice and the file gone, the Recordings list and its link opening straight into the review, the full review running, the take still waiting until used, downloading deleting it, one take in Your progress after two reviews, five waiting at most, a file that is not audio refused, and the hourly job removing expired takes.

---

## [0.20.0] Retake edge nudge (P)

#### Added
- **Move a retake's edges by ear.** In Edit audio, clicking a retaken word now opens its retake again. **Start** and **End** move each side of the splice 10 ms at a time: Start earlier or End later takes more of the old reading away, the other way keeps more of it. The [ and ] keys do the same for the start, with Shift for the end. Each step plays 1.5 s either side of that join, as it will sound, so the change is heard at once. The readout says how much more or less of the old reading each side now cuts ("cuts 20 ms more"), **Reset** puts everything back, and **Keep these edges** applies them (Cancel leaves the retake as it was). The same controls appear as soon as a new retake is ready, before it is used.
- **Space at a join.** **Space before** and **Space after** add a pause between the old reading and the retake, 50 ms at a time up to 2 s, for two words that run into each other. The pause is filled with the room's own background, taken once from the quietest stretch of the take within 4 s of the retake (the take's quietest stretch anywhere when there is none nearby), repeated as needed, with a 5 ms fade where it meets the new recording. Digital silence would sound like a dropout against the room.
- **Bring back the original** in the retake's box takes the retake out, since clicking a retaken word now opens it instead of removing it. Clicking a cut word still brings it back as before.

#### Changed
- A retake remembers the edges first found for it (`o` beside `s`, `e` and `ins` in its edit), so nudges can be reset, and any space (`ps`, `pe` in seconds, with the room tone `tone`); `RRTEdit.normalize` keeps them and `render` and `added` count the space (`RRTEdit.spaced`, `spaceEdge`, `roomTone`). Edges move at most 500 ms from there (`RRTEdit.NUDGE_MAX`), stay two crossfades clear of any other edit, never pass the take's ends and always leave at least 50 ms of old words to replace (`RRTEdit.nudgeEdge`, `RRTEdit.nudged`).

#### Why
- **Found on the live site:** a retake of "this picture" played as "this picture-icture": the end of the old "picture" was still there after the new one. End later takes it out; Space after then gives the next word room if they now run together.
- **The old side of the splice.** The edges found from the sound in 0.18.1 are right almost every time, but a soft consonant or a breath can still leave a sliver of the old word, or clip the word beside it. Moving the cut on the old reading fixes both; the new recording's own edges were already trimmed by Whisper and padded for the crossfade.
- **[ and ] for keys.** Alt with an arrow is the browser's Back on Windows and could leave the page with an unsaved take.

#### Tested
- 28 Node checks of `nudgeEdge` and the space: steps without drift, the 500 ms limit, other edits on either side, the take's ends, the 50 ms minimum, retakes from before 0.20.0, `normalize` keeping found edges, the rendered length and later times following a nudge; space up to 2 s and back to none, filled with room tone, counted by `added`, `render` and `mapTime`, kept by `normalize` and a nudge, and room tone found in a pause.
- 40 browser checks with a stand-in microphone and Whisper: the controls appearing once a retake is ready, buttons and keys, each step playing about 3 s around the join at either side, the readout, space before and after (its readout, its note at none, the take's length following it, kept on reopening), Keep, Cancel, Reset, the limit and its note, undo, Make edited MP3 with a nudge in place, Bring back the original, and cut words still coming back with a click.

---

## [0.19.1] Fixes for phones (P)

#### Fixed
- **Read along stayed on the caption list.** The teleprompter's captions start with the spoken title, which is not in the post text. The player matched each caption word to the first copy within 25 words, so the title's "of" or "the" pulled it ahead of the real place and most of the reading never matched. Below 60% it falls back to the caption list under the player, so the post never lit up. The matcher now takes the very next post word at once, and a word further ahead only when the two caption words after it follow it in the post too; a word with no place in the post (the title, a stumble, an aside) is skipped without moving the place. Past 40 words it trusts a word when five of the six after it follow on (a skipped paragraph). A first caption that is exactly the post's title is left out of the match altogether.
- **The caption list cut off the line being read** on phones. It scrolled by the line's position on the page; it now measures from the list itself and keeps the whole line in view.
- **Whisper closed the page on an iPhone.** Phone browsers close a page that uses more memory than they allow, and the Whisper model comes close. On iPhones, iPads and phones reporting under 4 GB of memory, the review now waits after a take ("Try it here anyway"), with a note to download the MP3 and review it on a computer. When it runs there, it uses the standard model, the plainer engine build (without asyncify) and one thread. While a review runs, a mark in the browser's storage records it; if the page closes under it, the next visit says so once. The model menu is hidden on these devices.

#### Why
- **Ask first on phones.** The take and its MP3 are ready before Whisper starts, so nothing is lost if the page closes, but a review that closes the page every time looks like the recording failed. Waiting to be asked keeps the phone useful for recording, which works well.
- **Sending a take to the computer for review** is planned as its own version, so a phone take reaches the laptop's teleprompter without moving files by hand.

#### Tested
- 10 Node checks of the matcher: the spoken title first, exact text, a skipped word and an aside, a repeated phrase, unrelated captions staying below the fallback line, and a skipped paragraph.
- 6 browser checks on a phone-sized page with a title-first captions file (the old player matched 13% and fell back to the list; now 100%, the post lights up and the lit word stays on screen), and 5 on a post whose text differs from its captions (the list's current line shown whole each time; the old player cut it off every time).
- 13 browser checks of the review on an iPhone and a computer with a stand-in Whisper: the offer and its wording, no automatic start, the model menu hidden, the running mark set when asked, the note after a closed page shown once, and the computer starting at once with the menu shown.

---

## [0.19.0] What works: insights (P)

The numbers turned into sentences that help plan the next reading. Each one says what it rests on and stays quiet until there is enough to go on, so a site with one reading sees what each observation still needs, and the observations turn on by themselves as readings, plays and takes arrive.

#### Added
- **An Insights screen** (Foxfire Studio, Insights), for the last 12 months by default, with the usual period menu.
- **What your listeners tell you** (administrators): how often readings were heard to the end, compared by length (shorter half against longer half), tempo (slower half against quicker), tag or category (the one with the clearest difference), the day a reading went out (weekend against weekday) and the time (before noon, noon to 5 pm, after 5 pm), where listeners came from, and delivery from the take each recording was made from: slips, pitch movement and phrase-ending fade. Each reading counts once in an average, so one busy reading cannot outweigh the rest.
- **Your reading** (everyone who records): pace, accuracy, fillers a minute and phrase-ending fade over your own full takes. From 5 takes it says where you stand; from 10 it compares your latest 5 with the 5 before and says which way each is moving. Opening drills are left out.
- **Minimums:** a reading counts once it has 30 plays in the period (the dashboard's minimum); a comparison needs 6 such readings, 3 on each side, and a gap of 10 points before it reports a difference. Below that, a card says what it still needs, with a bar; with enough data and no clear gap, it says so with both numbers. All filterable through `rrt_insight_rules`.
- **On the Overview:** the two clearest observations, with a link to the rest.
- **In the report email:** "Worth noticing" now uses the same rules as the screen (the two clearest listener observations for the period), so the email and the screen never disagree. The two observations it had before (length and source) are among them.
- **In the teleprompter:** when listeners have stayed with readings up to a certain length, and the loaded script at the chosen pace runs past it, a quiet line beside the reading's state says so ("This script runs about 6:20 at 140 wpm. Readings up to 4:10 were heard to the end more often (61% against 38%, 14 readings)."). The same for tempo, when slower readings held better and the pace is set quicker. It updates as the pace changes, and is shown only to people who may see listener numbers.
- Insights in the teleprompter's top menu.

#### Why
- **Sample sizes in every sentence.** "Readings under 4 minutes were finished more often" from 3 readings is noise; the same from 14 is worth acting on. Saying what each claim rests on lets the reader judge it, and staying quiet below a minimum avoids sending anyone chasing chance.
- **Halves.** Splitting at the middle keeps both sides the same size whatever the site's readings look like, so a site of short readings and a site of long ones both get a fair comparison.
- **Expect the minimums and wording to be tuned** against real data once a site has 20 readings with listeners, as a patch.

#### Tested
- 40 checks of the comparisons on hand-made readings: waiting below 6 readings, a 9 point gap reported as even and 10 as found, odd counts, plays not weighting the average, missing tempo and dates left out, a fade of 0 dB still counted, tags and categories, weekend and time of day, the three delivery splits, the rules filter, ordering, and the reading observations from 4, 5, 10 and 15 takes with a drill left out.
- 25 browser checks against 13 months of made-up data (24 readings, sources, linked takes): the screen, the period menu, the Overview pair, the teleprompter line appearing for a long script and going at a quicker pace, phone width, and an editor seeing only their own reading with no listener line. 18 more on an empty site, where every card waits and the Overview says nothing is clear yet. The report email with insights, and every Foxfire Studio screen free of PHP errors.

---

## [0.18.1] Cleaner retakes (P)

A retake left a stutter: a sliver of the old word beside the new recording. Fixed, and selecting words to edit is easier.

#### Fixed
- **No stutter after a retake.** The old words were cut at Whisper's word times, give or take 3 hundredths of a second, but Whisper's times are often a tenth of a second or more out and usually end a word early, so the end of the old word stayed in. The edges are now found from the sound: the cut starts in the pause just before the voice begins and ends in the pause just after it stops, searching well past Whisper's guess but never into the middle of a neighbouring word. A short silent catch inside a word (before a "t" or "k") is not mistaken for its end. Where words run together with no pause, the cut lands at the quietest point where they meet (`RRTEdit.speechEdges`, `quietRun`, `floorDb`).
- **A word lit up out of place.** Whisper listens in 30 second pieces that overlap where they join, and near a join it sometimes gives a word a time from elsewhere in the take, so during playback a word could light up again after later ones (in a 43 second take, "call" lit up after "sun", near the 30 second join), and the same wrong time could place a cut or retake in the wrong spot. The review now keeps only the words whose times rise in script order; any other word is shown with a dotted underline and a note, is never lit up by its own time, plays from where it must be when clicked, and cannot be edited on its own (select the words either side, and the edit covers it).

#### Added
- **The retake is fitted to the words.** Whisper listens to the retake, and anything said before the first selected word or after the last (a run-up to find the rhythm, or carrying on) is trimmed away at the real edges of the voice. The note says what was trimmed, or what Whisper heard if it does not match the selection. Without Whisper, or if it takes longer than 45 seconds, the retake is used as trimmed to its speech. Checking a retake does not change what the review says about the take.
- **More ways to select** with Edit audio on: drag across words, triple-click for the whole paragraph, **Whole sentence** and **Whole paragraph** buttons that widen the selection, and Shift with the left or right arrow to make it a word shorter or longer. Click, shift-click and double-click work as before. The status line counts the selected words.

#### Tested
- 10 Node checks of the new edges on made-up audio with Whisper times 0.08 s late and 0.15 s early, a word ending in a stop, and words with no pause between them. 21 browser checks of selection and a retake with a run-up word either side (trimmed, used, edited MP3 made), 7 with a word Whisper timed out of step (set aside, never lit up during playback, an edit across it), and the 90 layout and 15 look checks of 0.18.0 again.

---

## [0.18.0] The studio look (P)

The teleprompter gets a studio around it: the script framed in the middle, the controls grouped and out of the way, and the rest of Foxfire Studio a click away. Every control works exactly as before.

#### Added
- **A menu along the top:** the Foxfire Studio name and mark, and links to Overview, Readings, Your progress, Recordings, Shared takes and Reports (only those the person may open), plus Settings for administrators. Each opens in a new tab, so a reading in progress is never left.
- **The post bar:** the post menu, the address box, Open a recording, and the reading's state beside them: Ready to read, Reading, Following your voice, Recording, Recording paused, Paused or Finished.
- **A fold-away Controls panel** on the right, grouped into Reading (Follow my voice, Pace, Size, Width, Line, Font, Breathe between paragraphs), Recording (Record my voice, Clean up audio, Whisper review and its model) and Practice (Warm up, Check microphone and its last result, Cues, Opening drill, Focus). Checkboxes are drawn as switches and sliders show their values. It fades while reading, as the old bar did, and each browser remembers whether it is open. On a phone it starts folded and slides over the script.
- **The dock along the bottom:** where you are and the time left, Top, a larger Begin that shows play or pause, Finish recording, the recording timer and level, and level waves either side of Begin. The waves move with the microphone while recording and lie flat otherwise.
- **Your last take:** a button in the dock opens this post's latest full take (pace, accuracy, fillers, long pauses, ending fade), each compared with the take before, and your goals met or missed, with a link to Your progress. Opening drills are left out, as in Your progress. It refreshes after each take.
- **The artwork** in `assets/img/`: a fox drawn in gold light beside the script, a plum and gold night sky, and the fox mark beside the name and in the browser tab. The fox steps back behind a script and further while reading, and the sky keeps a dark pool where the words sit. On a phone the fox shows only on the welcome page.
- **Two looks,** chosen in Settings, Teleprompter look: **Foxfire Studio** (the night sky, the fox and gold ornaments, in the colours under Colours; the default) or **My WordPress theme** (no artwork, ornaments or glow, in the theme's colours). For the theme look, Background, Text, Accent and Second accent each pick one of the theme's palette colours, or Automatic, with a swatch and a live preview. Each choice is kept as the colour's name in the theme, so changing that colour in the theme changes the teleprompter. Themes that list their palette as CSS variables (Astra's global colours) are read from the theme's saved setting. Panels and softer text are mixed from the four, light themes get their own shadows and flag colours, and text that would be hard to read on the background is swapped for black or white. Automatic picks the theme's background and text, and an accent that stands out against the background.
- **Settings, Wording:** a short line for the corner of the dock (empty by default).

#### Changed
- The script sits between the menus and the dock and fades softly at both edges, framed by gold lines with an ornament under the title and sparks on the reading line.
- The finished-reading panel, the warm-up, the microphone check, the cue menu, the sharing panel and the review studio are restyled to match: frosted panels with a gold edge, and cards for the review's numbers.
- Messages now float just above the dock.

#### Why this way
- **The layout changed, the controls did not.** Every control keeps the id the teleprompter looks for, so reading, recording, the Whisper review, cues, clips and sharing run unchanged; the new look lives in the page, the stylesheet and a small separate script (`skin.js`) that only watches the page.
- **Links open in a new tab,** because leaving the teleprompter mid-reading would lose the take.
- **Only real numbers.** Your last take shows what the review already measured and saved; nothing is scored or graded that the review does not measure.
- **The theme look is quiet on purpose.** Gold frames, sparks and glow belong to the Foxfire look; with another theme's colours, especially a light one, a clean page sits better.
- **A theme's palette, not its stylesheet.** The palette is what WordPress and the block editor agree a theme's colours are, so it works for any theme; a theme whose real colours live only in its own stylesheet (as a child theme's often do) can add them to its palette, or the Foxfire look's own colours can be used.
- **The words come first.** The artwork dims behind the script and while reading, the script sits a little left of centre beside the fox, and on narrow screens the fox steps aside.

#### Tested
- A local site on WordPress 7.1.4 with a stand-in microphone, a stand-in for Whisper and the pinned encoder and noise model: 90 checks of the new layout, run in both looks. 15 more for Settings, Teleprompter look: the palette listed (Twenty Twenty-Five, and a stand-in for Astra's global colours), swatch and preview following the menus, the choice saved, the teleprompter in the theme's colours with no artwork and the mark drawn in the accent, and back to the Foxfire look. Every control is reachable and works at 1150 by 687 and at phone width (390 by 844): the panel folds and is remembered, the sliders, font, switches and arrow keys, reading and pausing, cue marks saved to the site, the warm-up, the microphone check, a recording with the Whisper review, the review studio with Edit audio and Share for feedback, Your last take after the review, and the welcome page. No script errors.
- A bug found on the way: on sites with plain permalinks, Your last take built its address wrongly; it now adds the post to the address the same way the rest of the teleprompter does.


## [0.17.0] Share a take for feedback (P)

Other ears catch things yours do not. Share one take with a friend who has an account on the site, and get their comments pinned to the moment or the word.

#### Added
- **Share for feedback** in the review studio, beside Make a clip. Choose people who have an account on the site (a friend is added under Users as a Subscriber), and leave **Email them the link** ticked or copy the link. The take's audio and the script with its word timings go to the site.
- **The share page** (`?rrt_share=…`). After signing in, the friend hears the take with the script lighting up as it plays. Clicking a word plays it and pins the next comment to it; **Use this moment** pins it to the time instead. Comments can be removed until they are sent. **Done** sends them to the reader in one email; if the friend never presses it, the email goes an hour after their last comment. Friends see only their own comments. Works on a phone.
- **For the reader:** the same page shows every comment with the friend's name, and **End this share now**. **Foxfire Studio, Shared takes** lists every share (who, when, ends, comments) with **End now**. A list of shares at `?rrt_share=list` shows a friend everything shared with them.
- One email to the reader per visit: "Jamie left 4 comments on your take of …", listing them with their times and a link.

#### Changed
- Database version 6: tables `rrt_shares` and `rrt_share_notes`, created on update.
- Settings, Reader stats: the uninstall box now also names shared takes with their comments, which go with it (and the `rrt-shares` folder with them).

#### Fixed
- The review's "It listened for" line (0.16.0) is now shown whenever Whisper ran, even when it finished within the same millisecond it started (seen only with a stand-in for Whisper in tests).

#### Why this way
- **A login, not an open link.** The take is the reader's voice, often of a post not yet published, so only people with an account it was shared with can hear it. The audio sits in a folder that refuses direct requests and is handed over only through a route that checks who is asking, never from a public address.
- **14 days, then the audio goes.** Feedback is wanted soon after a take, and a recording should not linger on the server. The comments stay, since they are the point.
- **One email per visit** rather than one per comment, so the reader's inbox gets a summary, not a stream.
- **Friends see only their own comments,** so each person's ears stay their own.

#### Tested
- In Chrome on a local site with two Subscriber accounts, a stand-in microphone, a stand-in for Whisper and a stand-in mailbox: a take shared with one friend and the invitation email; the share saved with a random name in the refused folder; a logged-out request for the audio refused, and the friend not chosen seeing "Not available", unable to fetch the audio, with an empty list; the chosen friend sent to sign in and back to the share; the take playing with the script lit; comments pinned to a word and to a moment, one removed; Done sending one email to the reader with both comments and the link, and pressing it again sending nothing; the page at phone width; the reader seeing every comment with the name, and Shared takes with End now; a comment left without Done emailed by the hourly job only after an hour; End now deleting the audio and closing the share to the friend while the reader keeps the comments. In PHP: a share past its 14 days closed to the friend and its audio deleted by the hourly job, a comment already sent refused removal, an empty comment refused, and a user listed only by a similar number given no access.

---

## [0.16.0] Better listening: learned words per post, larger Whisper model (P)

Fewer of Whisper's own mistakes in the review, so more of what it flags is real. Sharing a take for feedback is next, as 0.17.0, and insights moves to 0.18.0.

#### Added
- **Learned words per post.** "I said it right" (and "I didn't say this") now asks where to remember it: **In every post**, as before, or **Just this post**, for a name or an invented word that belongs to one piece. Words for one post are saved with the post on the site, so they work on any computer and for anyone who can edit the post, and they never hide a real misreading in another post. *Words Whisper has learned* in the review lists the two apart, each with Forget.
- **Larger Whisper model.** A menu beside Whisper review: *Standard model* (Whisper base, about 80 MB) or *Larger model* (Whisper small, about 250 MB), remembered in the browser. The larger model hears names, unusual words and quiet endings more accurately and takes about two to three times as long. It downloads from Hugging Face the first time, or from the site once an administrator presses **Download the larger model to this site** in Settings (a second button under Whisper, sharing the standard set's code). Every file is checked against its fingerprint, pinned to one revision of the model. The review status names the larger model, and each take records which model reviewed it.
- **How long Whisper took.** The review status now ends with how long Whisper listened, and how long it spent loading the model when that took a while ("It listened for 0:38, after 0:12 loading the model"). Both are kept with the take (`whisperS`, `whisperLoadS` in its summary), so the two models can be compared on the same computer. Added after the first try with the larger model on the live site, which felt faster than the standard one. Timed on the live site afterwards: on a 43 second drill the standard model took 5 s to load and 31 s to listen, the larger 7 s and 97 s, about three times as long; both read 100%.

#### Changed
- If the larger model cannot load or run on a computer (too little memory, say), the review runs again with the standard model and says so.
- Settings, Reader stats: the uninstall box now reads "Also delete all reader stats, cue marks and words taught for one post".

#### Fixed
- **A reader with no taught words could not teach the first one in every post.** The site sends an empty set as `[]`, and words added to it were lost when saved. Empty sets are now read as empty lists of words. (This had affected new readers since 0.9.0; readers who already had taught words were not affected.)

#### Why this way
- **Per post, kept with the post,** like cue marks: the word belongs to the piece, so the piece carries it. Words for every post stay with the reader, as before.
- **The model is the reader's choice, per computer,** because speed and memory depend on the computer, and the standard model stays the default.

#### Tested
- In Chrome on a local site with a stand-in microphone and a stand-in for Whisper that reports which model it was asked for: the model menu and remembering it; a take reviewed with the larger model and saved with its name; two misheard words, one taught for this post only (saved with the post, not with the reader, and absent from another post) and one for every post (saved with the reader); both lists under Words Whisper has learned; forgetting the post's last word removing it from the site; the larger model failing to load and the review falling back to the standard model, saved as such; the review status giving Whisper's time and the take keeping it. The settings screen with both download blocks, the request for the larger set, and a failed download reported plainly. In PHP: every file of both sets has a fingerprint, the larger model's files come from one pinned revision, and the site serves the larger model only once all seven files are there. A logged-out request to save a post's words is refused. The 0.15.0 checks all pass again.

---

## [0.15.0] Studio upgrades: font switch, warm-up, cue marks (P)

Three more small tools in the teleprompter, for comfort and control while reading. Built before insights, which moves to 0.16.0.

#### Added
- **Font:** a menu in the control bar to read the script in Serif (the reading font), Sans (the interface font, which the page already loads, so nothing new is downloaded) or System (the computer's own sans serif). It changes the teleprompter, the review script and the warm-up, and is remembered in the browser. The words are measured again after a switch, so the reading line stays on the right word.
- **Warm up:** a button that opens a panel of about three minutes, one step at a time, with Next and Back (or the Left and Right keys) and Escape to close. A breathing circle (in for four seconds, out on a long "sss" for six, three rounds); humming and lip trills; four tongue twisters picked fresh from twenty each time, read slowly, then quicker, then quick; and a pacing passage that lights up word by word at 110 words a minute, then 170, then the pace set in the control bar. Again replays the breathing and pacing steps. A **Your voice** level bar along the bottom moves as you speak, so you can see the microphone hears you; it records and keeps nothing, and lets the microphone go when the panel closes. If the microphone is not available the bar is replaced by a short note and the warm-up runs as usual.
- **Cues:** a switch in the control bar. While it is on (and the reading is paused), clicking a word opens a small menu: Pause after, Breath after, Emphasis or Clear. A pause shows as a slash after the word, a breath as a tick, and an emphasised word has a gold line under it. In the timed scroll a pause holds for 0.7 seconds and a breath for 1.1, and an emphasised word gets a fifth more time; the time left counts them. The review script shows the same marks, so a marked pause can be checked by ear.

#### Changed
- **A marked pause is never treated as a fault.** In the review it does not make its sentence Hesitant, is not listed as a Long pause or counted in the Long pauses tile, and Tidy never offers to cut it.
- With the cue menu, the warm-up or the microphone check open, the teleprompter's own keys (Space to begin, arrows for pace and paragraphs) are left alone, so pressing Space there can no longer start a reading behind the panel. In the cue menu, Space just closes it, so a habitual Space never sets a mark; Enter picks a button.
- Settings, Reader stats: "Also delete all reader stats" now reads "Also delete all reader stats and cue marks", since uninstalling with it ticked removes every post's marks too.

#### Why this way
- **Cue marks are saved on the site, with the post,** in hidden post meta (`_rrt_cues`), so they follow the reader to any computer, and anyone who can edit the post sees them. The post's text is never changed. Each mark keeps the word it was on as well as its place, so when the post is edited it finds its word again within 40 words, or is left off with a note saying how many. If a save fails, the marks wait in the browser and are sent with the next change or visit.
- **Sans uses the interface font** the look already loads, so no new font files are needed and self-hosted fonts keep working with no outside requests.
- **The warm-up stays simple:** a level bar and no scoring, so it can be done anywhere, and a few minutes of breathing and articulation before reading is the routine voice coaches recommend. The level bar was added after the first try on the live site, where the warm-up gave no sign that it heard the reader.

#### Tested
- 27 checks of the new helpers in Node: cleaning cue lists, placing cues back after words are added or removed (including repeated words and the 40-word limit), cue timings, picking tongue twisters, and the pacing passage's word timings at three paces. 3 checks of Tidy with and without a marked pause.
- In Chrome on a local site: the three fonts and remembering the choice; setting, changing and clearing every kind of mark; Space and Escape with the menu open; marks saved on the site and shown in a second browser; the timed scroll holding on a marked pause and the time left growing; marks following their words after the post was edited, with the note for one that could not; a failed save kept in the browser and sent on the next visit; clearing every mark removing the meta; a logged-out save refused; the full warm-up with the keys, at computer and phone width; with a stand-in microphone, the level bar moving on every step and the microphone released on close, and with the microphone refused, a plain note and the warm-up still running. With a stand-in microphone and a stand-in for Whisper: a recorded take whose review shows the marks, does not flag the marked pause, and whose Tidy offers only the unmarked one.

---

## [0.14.0] Studio upgrades: microphone check, opening drill, practice focus (P)

Three small tools in the teleprompter's control bar that make each practice session count. Built before insights (then numbered 0.15.0, now 0.16.0), which needs a few months of data first.

#### Added
- **Check microphone:** a 10 second test. Four seconds of quiet measure the room, then six seconds reading the script's first line measure your voice. It says how far the room sits below your voice (quiet at 45 dB or more, fair from 35, noisy below that) and whether your level is about right, too quiet, too hot or clipping, with tips for a quieter room when it needs them. The last result shows beside the button.
- **Opening drill:** read just the opening for 20, 30, 45 or 60 seconds (30 by default). When the time is up the teleprompter finishes the sentence it is on, then stops and makes the review as usual. Each drill is compared with the last drill only. Drills are kept out of the goals, charts, bests and take comparisons on Your progress, which follow full readings; they have their own Opening drills section there, from the first drill to the latest. The report email counts them on the practice line.
- **Focus:** pick one habit before a take (pace, endings, fillers, pauses, pitch, energy or accuracy). The review opens with a Your focus tile compared with your last take on the same focus, shows that habit's flags first, and folds the other read-out notes under Everything else. Your progress shows each take's focus.

#### Changed
- Database version 5: takes gain `drill`, `drill_s` and `focus` columns, added on update.

#### Fixed
- A take's word count now leaves out the part of the script that was never reached, so a reading stopped early no longer counts the whole post as read.

#### Why this way
- **The microphone check stays in the browser.** It measures levels from the raw microphone (no echo cancelling, noise suppression or automatic gain), so it hears the room as a recording will. Nothing is recorded or sent.
- **Drills apart from full readings.** A 30 second drill read three times would otherwise pull every average, best and goal towards the opening.
- **One focus at a time,** as speaking coaches advise: the other numbers are still there, a click away.

#### Tested
- 21 checks of the helpers in Node: room and voice levels, the verdicts and tips, ordering notes by focus, finding the previous take with the same drill and focus, where a drill stops, and the line chosen for the microphone check.
- In Chrome on a local site with a stand-in microphone (four seconds of quiet, then a steady voice) and a stand-in for Whisper: the microphone check result and the line beside the button, two 20 second drills with the fillers focus, each stopping at a sentence end after about 22 seconds, the drill review heading, focus tile, filter and drills-only comparison, the three new columns saved on the site, and the Opening drills section on Your progress.

---

## [0.13.0] Clips for social media (P)

Turn a moment of a reading into a short captioned video for Instagram, Facebook, TikTok or YouTube, and see which clip brings listeners back.

#### Added
- **Make a clip** on each reading's page (Foxfire Studio, Readings, a reading) and in the review studio after a take.
- **Choosing the moment:** the whole reading as a waveform, with the listening curve over it when there are listens; click to place the clip, nudge by 5 seconds, set the length (15 to 60 seconds, 30 by default), play it, and see its words. Suggested moments: the opening, where listeners replayed most (once there are enough plays), and where the voice is most lively. Starts snap to the nearest quiet point, so a clip begins between words.
- **The video:** the post's featured image softened and darkened behind a sharp copy of it (or the site's colours when there is none), the show name, the title, a moving waveform, captions two or three words at a time with the spoken word lit, a progress line, the site's address, and a 3 second end card ("Hear the whole reading at" the site). The sound fades in and out. Fonts and colours come from the look settings.
- **Shapes:** square (1080 × 1080) for feeds, vertical (1080 × 1920) for Stories, Reels and TikTok, wide (1920 × 1080) for YouTube. One or all three at once.
- **Captions spelled from the post:** in the studio, from the review's word timings; on a reading's page, Whisper (the same model and files as the review) listens to just the chosen stretch on the reader's computer, and its words are lined up with the post's text so captions use the post's spelling and punctuation. Square and curly brackets, an editor's marks in a post, are left out of captions.
- **A tagged link for each clip:** the post's address with the channel (`src`) and the clip's name (`utm_campaign=clip-1m05s`), with a copy button. Visits through it show under Campaigns in Sources.
- **Download**, or **Save to Media Library** (for users who can upload files), which gives the clip an address to open on a phone.

#### Why this way
- **In the browser.** The video is drawn on a canvas and encoded with WebCodecs (H.264 video; AAC sound where the browser has it, Opus otherwise), then packed into MP4 by mp4-muxer 5.2.2 (MIT), served from the plugin's own folder. Nothing is uploaded unless Save to Media Library is pressed, and no outside service is used beyond Whisper's own files when they are not self-hosted.
- **Chrome or Edge.** Firefox and Safari cannot yet encode this reliably; the clip maker says so and makes nothing. AAC comes from the operating system, so Windows and Mac make the sound Instagram expects; elsewhere the clip maker warns when it falls back to Opus.
- **Captions from the post's text,** so a mishearing never appears on screen. When Whisper's words do not match the post (a different text), its own words are used.
- **Links Instagram can use.** Captions there are not clickable, so the clip shows the address on screen and the tagged link is ready for a bio or a Story link sticker.

#### Tested
- 22 checks of the helpers in Node: lining Whisper's words up with the post (a mishearing, a skipped word, unrelated speech, nothing heard), caption groups (size, breaks at punctuation and pauses, words outside the clip, brackets dropped), tagged links, the replay and liveliness suggestions, snapping to a quiet point, and the waveform.
- In Chrome on a local site with a 95 second reading and a featured image: the clip maker from a reading's page and from the teleprompter page, all three shapes made (a 30 second square clip in 7 seconds; all three in 32), each a valid MP4 at the right size, 30 frames a second, with sound, 33 seconds long; frames checked for each shape and for the end card; Save to Media Library. Whisper could not download its model on the test machine, so the Whisper step was tested with word timings given directly locally, then on the live site: a 30 second square clip of a real reading, with Whisper served from the site, captions in the post's spelling, AAC sound, made in 30 seconds, then posted to Instagram, where it played correctly with sound.

---

## [0.12.0] The report email (P)

A **report by email** that says how your readings did, so you do not have to remember to check the dashboard. A new **Reports** screen in the Foxfire Studio menu sets it up.

#### Added
- **The email:** the headline (plays and estimated listeners, still listening at one minute, heard to the end, listening time, each with the change from the period before), top readings, the reading that held people longest and the one that lost them soonest with the moment and the sentence where most left, where listeners came from, how they listened (speed mix, background listening, read along), new readings, each reader's recording progress with their goals, and up to two observations. A button opens the dashboard for the same dates. HTML built from tables with inline styles, so it reads well in Gmail and on a phone, with a plain-text copy for mail apps that prefer it.
- **When:** every calendar month by default, sent on the 1st at 8 am in the site's time zone and covering the month before. Weekly (Monday to Sunday), every two weeks and quarterly also work. The time of day can be changed.
- **Who:** up to 10 addresses, or the site's admin email when none are set. The sender is the site's own mail setup.
- **Reports screen:** on and off, how often, time of day, recipients, which sections to include, a preview at computer and phone width of the last full period or the current period so far, **Send this report now**, and the list of past reports, each viewable as sent.
- **CSV attachments** (off by default): the period's readings and sources, with formula-looking cells written as plain text, as in the dashboard's downloads.
- **Cron health check:** the Reports screen notices when scheduled jobs run more than 30 minutes late and shows the fix, a server cron job first and `DISABLE_WP_CRON` second, in that order.
- **Archive:** one row per email in the new `rrt_reports` table (database version 4): dates, how it was sent, recipients, status and any error, the headline numbers, and the email itself.

#### Why this way
- **Calendar periods.** "October" is easier to compare and remember than "Oct 10 to Nov 9", so the default follows the calendar. Two-week periods are counted from a fixed Monday, so they fall on the same weeks whatever day the setting was chosen.
- **Same numbers as the dashboard.** The email calls the dashboard's own functions after bringing the daily summaries up to date, so a number in the email matches the dashboard for the same dates. Changes from the period before are hidden below 30 plays in either period, as in the dashboard.
- **Never old, never twice.** Switching reports on, or changing how often, starts with the next period, so a backlog is never sent. The hourly check sends each period once; if cron ran late it sends at the next run, and a failed send is tried at the next three runs and then left as failed in the archive.
- **Observations stay cautious.** Fuller insights are 0.14.0. The two here (shorter against longer readings, and which source's listeners stay) appear only with enough data behind them and always say how much.
- **Settings in their own option** (`rrt_report`), apart from `rrt_settings`, which the main Settings screen saves whole.

#### Tested
- A local site with 13 months of made-up listens (16 readings, 7,285 page views) run through the plugin's own rollup, and 392 takes. 57 checks: period dates for every interval and in the site's time zone; plays, views and completions against independent counts from the visit records; the same totals and listeners as the dashboard; three monthly reports in a row with the clock moved, including one caught up two days late, with no duplicates; four tries after a failed send, then the next month sent normally; CSV attachments and temporary files removed; a formula-looking title neutralised; switching interval and switching off; the form's address cleaning; analytics off; an empty period.
- The email at 390 px and 1000 px wide in Chrome, with no sideways scrolling. The Reports screen in a browser: saving, a rejected address, Send now, the archive and viewing a past report, with no PHP warnings. Upgrading from database version 3 creates the archive table; deactivating clears the schedule.
- PHPCS (WordPress-Extra) on the new files: clean. The rest of the plugin is unchanged from the 0.11.x baseline.
- On the live site: installed over 0.11.1 with every screen loading and no PHP errors, reports switched on, and a report for the month so far sent with Send now.

---

## [0.11.1] Now Foxfire Studio (P)

Reading Room Teleprompter is renamed **Foxfire Studio**. Entries below this one use the old name, which was right at the time.

#### Changed
- **New name and folder:** the plugin is *Foxfire Studio* in `foxfire-studio/foxfire-studio.php`, with the text domain `foxfire-studio`. The WP-admin menu and the settings page are called Foxfire Studio; the teleprompter, dashboard and listening player keep their plain names.
- **Upgrading from Reading Room Teleprompter:** WordPress sees the new folder as a separate plugin. Activating Foxfire Studio switches the old copy off, and from then on Foxfire Studio uses the same settings, tables, reader data and self-hosted files. A note explains this on the Plugins and settings screens until the old copy is deleted.
- **Deleting the old copy keeps your data.** While Foxfire Studio is active, deleting Reading Room Teleprompter from the Plugins screen skips its uninstall routine, which would otherwise remove the shared settings, the self-hosted fonts, encoder and noise model, and the country database. WordPress's confirmation still says "and its data"; that is its standard wording.
- If the old copy is switched on again, Foxfire Studio switches it off on the next page load. Deleting Foxfire Studio while the old copy is still installed keeps the shared data too.

#### Why this way
- **Internal names stay** (`rrt_` functions, options and tables, `rrt-` handles and pages), so every setting, reading, take and listen carries over without a migration, and links into the settings and dashboard screens keep working.
- **The old and new copies never run together.** Both define the same functions. The new main file stays idle when the old copy is already loaded, which is the case while it is being activated, so activation cannot fail.

#### Tested
- On a local site running Reading Room Teleprompter 0.11.0 with saved settings, visits, a self-hosted encoder and a country database: Foxfire Studio installed beside it, activated from the Plugins screen in a browser (old copy switched off, no errors, the note shown, menus renamed), and the old copy deleted from the Plugins screen. Settings, preset, show name, analytics, visits, salt and self-hosted files were all kept.
- Forcing both copies active: the old one is switched off within the request. A fresh install starts with analytics off, and uninstalling a fresh install removes its settings as before.

---

## [0.11.0] The dashboard (P)

A **Reading Room** menu in WP-admin shows how your readings are heard and how your reading is coming along, any time.

#### Added
- **Overview:** plays, the share who pressed play, the share still listening at one minute, the share who heard to the end, average speed and listening time, each against the previous period; plays each day with the previous period behind it; top readings and top sources; and a "Worth a look" line naming the biggest drop-off.
- **Readings:** every reading with its publishing date, length, tempo, visitors, plays, average heard, completion, speed, biggest drop-off and top source. Sort by any column, filter by tag, download as CSV.
- **Reading detail:** the listening curve (who is still listening at each 5 second stretch) with the previous period as a faint line and drop-off points marked. Hover to see the sentence playing there, from the reading's captions; click to hear it. Below it: where listeners leave, with the sentence; heard again, jumped over, faster and slower along the reading; quarters reached, background listening and read along; devices and countries; where this reading's listeners came from; and the take it came from.
- **Sources:** channels and campaigns with visitors, plays, the share who pressed play, average heard and completion; plays each day by channel; and a **link builder** that makes a tagged link for any post, channel and campaign, with an optional start time.
- **Your progress:** each reader's pace, accuracy, fillers, retakes, phrase endings, pitch, energy and room over time, personal bests, every take in the period, and the words taught to Whisper. Administrators can look at any reader.
- **Goals:** one to three targets ("pace 135 to 150", "fillers at most 3"), shown as a band on the charts, checked for every take, and shown as a tile in the teleprompter's review studio.
- **Practice log:** one line per day with takes, with time recorded, posts, best accuracy, goals met and your own note.
- **Change markers:** mark a date (for the site or one reading) with what changed, and it appears as a flag on the charts.
- **Recordings:** every MP3 that has been a post's reading.
- **Raw data:** the visits behind every listener number, and their events for the last 30 days, download as CSV. Every table downloads too.
- Date ranges (7, 28, 90 days, 12 months, or chosen dates), the site's time zone printed with each range, and "What these numbers mean" under every screen.

#### Changed
- The daily summaries also count stretches jumped over. Every past day is rebuilt once after updating.
- Raw events now keep the visible time on the page and the "reached the end" flag, so every visit can be rebuilt from its events alone.

#### Why this way
- **Fast with a year of data.** Every listener number comes from the daily summary tables, except listener and device counts, which read the indexed visits table. Today's numbers are brought up to date when the dashboard opens, at most every five minutes.
- **Small numbers are labelled.** Every share shows the count behind it. Changes against the previous period appear only when both periods have at least 30 plays, and drop-off suggestions only for readings with 30 plays.
- **Charts drawn on the page,** with no chart library and nothing loaded from outside. One y axis, a legend for two or more lines, a crosshair tooltip listing every line, a table view of the same numbers under every chart, and a colour order checked for colour blindness. Periods over 70 days show a daily average for each week, so a week still in progress does not look like a fall.
- **Listener numbers for administrators, progress for every reader.** The listener screens need `manage_options` (the `rrt_dashboard_cap` filter changes that). Editors see only their own progress.
- Goals and practice notes live in each reader's user data, and are included in WordPress's personal data export and erase.
- **CSV downloads cannot carry spreadsheet formulas.** Campaign tags and referrers come from visitors' links, so any cell starting with `=`, `@`, a tab, a carriage return, or `+` or `-` not followed by a digit gets a leading quote, in the raw data downloads and in every table's download. Signed numbers such as -12% are left as they are.
- The email report screen arrives with the monthly report in 0.12.0. Settings stay at Settings, Reading Room, with a link in the new menu.

#### How it was checked
- A year of made-up listening (8,031 visits across five readings, with a planted drop-off at one minute, skips, replays and speed changes) rolled up in 5 seconds. Every screen loaded in under 0.7 seconds on the local test server, and the planted patterns appeared where they were put, including "Worth a look" naming the reading with the drop.
- Real browser listens were rebuilt from their raw events alone: all 5 visits matched their stored records exactly, and the dashboard's visitors, plays and completions matched a count made straight from the visits.
- Goals, the practice note, change markers, both CSV downloads, sorting, the tag filter, the link builder and the goals tile in the teleprompter were checked in Chrome. An editor saw only Your progress and was refused the listener screens.

---

## [0.10.1] Security fix and privacy review (P)

Not released on its own: these changes ship in 0.11.0.

### Security: analytics addresses and rate limits

#### Fixed
- **Visitors can no longer choose the address the analytics route sees.** The route trusted a `CF-Connecting-IP` header from anyone, so a script could send a different address with every request, get a new listener id each time, and add rows to the analytics tables without limit. The address now comes from the connection itself (`REMOTE_ADDR`).
- **Country headers** (`CF-IPCountry`, `X-Country-Code`, `X-Geo-Country`) are no longer trusted from anyone. A country set by the web server itself still is.

#### Added
- **Trusted proxies** in Settings, Reading Room, Listener analytics: list a CDN's or proxy's addresses or ranges and choose its header (`X-Forwarded-For`, `CF-Connecting-IP`, `X-Real-IP` or `True-Client-IP`). Forwarding and country headers are read only on requests from those addresses. `X-Forwarded-For` is read from the right, skipping trusted proxies, so addresses a visitor adds on the left are ignored. Also the `rrt_trusted_proxies` filter.
- **Two more hourly limits on new page views:** 300 per address (the listener-id limit could be dodged by changing the browser name) and 5,000 for the whole site. Filters `rrt_listen_address_hourly_limit` and `rrt_listen_hourly_session_limit`; 0 turns one off.
- Uninstalling also removes any rate-limit counters still waiting to expire.
- The message after a recording pointed to a "Post audio" box, a field name from the site the plugin started on. It now names the plugin's own **Reading audio** box, and **Use this recording** when reader stats are on.
- **Your visit right now** says whether your visit came through a trusted proxy, and tells you to add the proxy when a forwarded address arrives from one that is not listed.

#### Changed
- **Listener analytics is off on new installs** until a site owner switches it on, with a note beside the switch to add the privacy paragraph to the site's privacy policy first. Sites that already ran 0.10.0, where it was on by default, keep it on: on their first load of 0.10.1 the old default is written into their saved settings, whether they update by upload or by reactivating. A choice already saved, on or off, is never changed.

#### Why this way
- **Nothing new is stored.** The limits are counters in transients that expire within the hour, and the per-address one is named by a salted hash of the address and hour. Existing installs behave as before unless a proxy is listed: the setting starts empty.
- **Right to left, stopping at anything malformed,** is how a forwarding chain is read safely: each proxy appends what it received, so only the entries added by proxies you trust can be believed.

#### Tested
- The new default on every starting point: a new install (off), a 0.10.0 site with saved settings but no analytics choice, one with no saved settings at all, one saved on and one saved off, each updated both by upload and by deactivating and reactivating. Only the new install starts off; every choice already made is kept.
- 60 unit checks of address parsing and normalisation (IPv4, IPv6, brackets, ports, zone ids, IPv4 written as IPv6, malformed and oversized values), CIDR matching, forwarded chains, header choice, country headers and both limits.
- End to end on a local WordPress: spoofed headers ignored with no proxy listed, a listed proxy's header honoured, an unlisted address's header ignored, the per-address and site-wide limits stopping new page views, and existing page views still recorded.

### Documentation and privacy review

- **New [PRIVACY.md](PRIVACY.md):** exactly what the plugin stores, sends and shows, for visitors and for readers, taken from the code: listener analytics fields, browser storage, requests to other sites, what MP3 tags reveal, retention and what uninstalling removes.
- **SECURITY.md** now lists supported versions, a clear reporting route, how uploads, REST routes and queries are protected, and known limits of the public analytics route.
- **README, SETUP, CONTRIBUTING and readme.txt** describe the plugin in general terms instead of one site's setup, and correct two claims: visitors' browsers do keep the player's speed and resume point, and a recording can reach the site through "Use this recording".
- **CONTRIBUTING** sets out what stays out of the repository (credentials, personal details, site-specific setup) and how to commit with a GitHub noreply identity.
- The retired theme template (`template/page-teleprompter.php`, frozen at 0.0.10) is removed from the repository; every feature it had is in the plugin. It stays in the history and in the v0.0.x releases.
- The dark preset is labelled *Dark (void, plum and gold)* in Settings. Its internal key is unchanged, so saved settings keep working.
- **Licence file:** the GPL-2.0 text as `LICENSE` in the repository and `license.txt` in the plugin, which already declared GPL-2.0-or-later, with a copyright notice and the licence address in the plugin header and readme.txt.
- The plugin header credits the GitHub account. `tools/gw-whisper-setup.sh` is now `tools/whisper-setup.sh` and defaults to the plugin's own `rrt-whisper/` folder.

---

## [0.10.0] Listener analytics (P)

See how visitors listen to each reading, measured on your own site with no outside services and nothing that identifies anyone.

#### Added
- **The player reports what listeners do** to the site: the page view, play and pause, each 5 second stretch heard and at what speed, jumps forward and back (and whether by the bar, the skip buttons, a chapter or the keyboard), speed and volume changes, reaching the end, the tab going to the background and back, leaving the page, read along on or off, downloads, playback errors, how far down the post they scrolled, and how long the page was open.
- **Where listeners came from:** the referring site's name, grouped into channels (Instagram, Facebook, Threads, Bluesky, TikTok, X, Reddit, YouTube, Google, other search, email, your own site, direct, other), and campaign tags on links. `?src=instagram` is a short tag of its own, and the usual `utm_` tags work too. Instagram and Facebook's in-app browsers are recognised even when they send no referrer.
- **Device and country:** phone, tablet or computer, and the two-letter country code.
- **A country database on your site:** Settings, Reading Room, Listener countries downloads DB-IP's free IP to Country Lite database and turns it into a 5 MB file that answers in a fraction of a millisecond. It refreshes itself each month. A country sent by the host or a CDN (such as Cloudflare's) is used first.
- **Daily summaries** per reading, and per reading and source, built once an hour: visitors, plays, listening time, completions, how many reached each quarter, how many were still listening at one minute, average speed, time to first play, background listening, read along use, and for every 5 second stretch how many heard it, heard it again, sped up or slowed down. These are what the dashboard in 0.11.0 will read.
- **"Your visit right now"** under Listener countries: the country your own visit would be counted under, where it came from, and whether a forwarded address suggests a proxy in front of the site, which would put every visitor in the wrong place. Shown to you only and never stored.
- **Recent listens** in Settings, a plain list of the latest page views with their source, device, country, how much was heard, the furthest point, speed, and how the visit ended, for checking that listens arrive.
- **A privacy policy paragraph,** ready to paste, in Settings and in WordPress's own privacy policy guide.
- **Settings, Listener analytics:** switch it off, choose who is left out (editors and admins by default, everyone logged in, or nobody), respect Do Not Track and Global Privacy Control (on by default), rename the short campaign tag, and how long to keep each visit's events (30 days) and summary (395 days).

#### Why this way
- **Nothing that identifies a person is stored.** The visitor's IP address, browser name and the site's name are hashed with a random salt that is replaced every day and never kept, so the day's listener id cannot be traced back or linked to another day (Plausible's method). The IP address is used only for that hash and the country lookup, while the request is handled.
- **No cookies and no browser storage.** The session id is a random number in the page's memory.
- **Refreshing does not add plays.** Every page view and play of one listener id on one reading in one day counts once, the IAB's 24 hour rule within the day the id lasts. Listening time adds up across those page views, and a stretch heard in any of them counts as heard.
- **A stretch counts as heard after 2 seconds of it played** (or half of a short last stretch), measured from the audio's own clock, so dragging through or skipping ahead never counts as listening.
- **Bots are dropped:** crawlers, link previews, monitors and scripts by their names, and anything that reports more audio than the time the page was open allows, even at 2.5 times speed.
- **Your own listens do not count.** The beacon carries the browser's cookies but no WordPress security token, so the plugin checks the login cookie itself.
- **Small and cache-safe.** Events go in batches with `navigator.sendBeacon`, every 15 seconds and on pause, end, the tab hiding and the page closing, so the last moments of a visit arrive too. The page itself stays the same for every visitor, so page caches keep working. The new `listen.js` follows the player's rules for strict script optimisers.
- **The REST route always answers "204 No Content",** so it never tells anyone why a batch was or was not kept.

#### Changed
- The player's 5 second progress events now count only time actually played, add a final one at the end, and are joined by `ready` (with the length), `download` and `error` events.

#### How it was checked
- Scripted listens in a real Chrome against a local site: a listener from an Instagram link with a campaign tag who speeds up, goes back 15 seconds and closes the tab while playing; the same person refreshing three times and listening to the end at double speed; a phone that never presses play. Every session, stretch, replay, speed, source, device, country and ending was stored as it happened, and the daily summary counted 3 visitors, 2 plays, 1 completion and 44 seconds heard from 5 page loads, and running it twice changed nothing.
- A search crawler, a browser sending Global Privacy Control, a logged-in editor, a made-up post and a forged batch claiming 25 seconds heard in half a second: none counted.
- The database file was searched for the visitors' IP addresses and referring pages. None were there.
- The country lookup agreed with DB-IP's own data for 3,000 random addresses, apart from one IPv6 address inside a network smaller than a /64.
- The player, chapters and read along behave as in 0.9.0, and with analytics off the page loads no extra script.

#### On a live site (10 November 2026)
- Installing created the tables, and the country database downloaded and converted on the host in a few seconds (November edition, 732,474 ranges, 5.6 MB). The cached post pages carried the analytics settings at once.
- A listen from a phone on mobile data, opened from a link tagged `?src=instagram` while logged out, appeared in Recent listens within a minute with its source, device, speed changes, how much was heard and the furthest point, ending with the page closed while playing. Its country showed as another country, because the phone's carrier routes its data through a different country.
- A listen from a computer on a home connection, in a private window, appeared the same way, placed in the right country, which confirmed that the site sees visitors' own addresses with no proxy in front.

---

## [0.9.0] Reader stats on the server (P)

Your reading history now lives on your site, so it follows you to any computer.

#### Added
- **Every take is kept on the site:** pace, accuracy, every kind of slip, pauses, endings, pitch, energy, room and cleanup numbers, the reading mode and settings, and the full list of flagged moments, for each reader. The take comparison in the review studio reads from it, so a take on one computer is compared with one made on another.
- **A Recordings record** for every MP3 that becomes a post's reading: file, length, size, bitrate, average tempo, when it went live, whether it is the current reading, whether it was edited, and the take it came from. Readings chosen by hand in the Reading audio box are recorded too, and readings already on posts were recorded when 0.9.0 was installed.
- **"Use this recording"**, with the result and in the review studio: the MP3 behind the download button (edited or not) and its captions go to the media library and become the post's reading, in one click. It is the only way a recording reaches the site.
- **Taught words follow you:** what you teach Whisper is kept with your user, so teaching once works on every computer.
- **Energy** in the read-out and in every take: how much your loudness moves within a phrase (the spread of its voiced moments, in dB, leaving out the phrase ending). Below about 3.5 dB reads as even, 3.5 to 6.5 dB as a natural rise and fall, above that as lively. These bands are starting points to be checked against real takes.
- **Your old history comes along:** on first visit after updating, each browser's takes and taught words from before 0.9.0 are copied to the site, once.
- **Settings, Reader stats:** switch server storage off to go back to each browser keeping its own history; choose whether deleting the plugin deletes the stats too (off by default, so a reinstall loses nothing).
- **WordPress's personal data tools** can export or erase a reader's stats.

#### Why this way
- **Two tables and user data, nothing more.** Takes and recordings are tables (`rrt_takes`, `rrt_recordings`) so later versions can chart and join them quickly. Taught words are one small list per reader, so they live in that reader's user data and leave with them.
- **The browser keeps a copy.** The teleprompter still answers from the browser at once and syncs with the site in the background, so a slow connection never holds up a review, and a take made while the site could not be reached is sent next time.
- **Numbers, not recordings.** Only what the review measured is kept. Audio reaches the site only on "Use this recording", and the upload is checked to be an MP3 (and a WEBVTT captions file) by its content, not just its name.
- **Each reader sees only their own takes.** The routes need a logged-in user who can edit posts; uploading also needs permission to edit that post and to upload files.

#### How it was checked
- Two separate browser profiles for one reader, standing in for two computers. The first had a take and a taught word from before 0.9.0 in its browser; after its first visit both were on the site. A new take there was saved with its numbers, energy and flag list. The second profile, opened fresh, showed both takes and the taught word before recording anything, and its own new take joined the same history.
- "Use this recording" put the MP3 and captions on the post, and the Recordings record was marked as an upload, linked to its take, with the earlier reading marked as no longer current.
- The reading already on the test post got its record on installation, with its length and an estimated tempo.
- With server storage off, the teleprompter made no requests to the site, kept its history in the browser, and showed no upload button. Visitors get a "not allowed" answer from every stats route.
- Editing, retakes and the player were checked again and behave as in 0.8.0.

#### On a live site (10 November 2026)
- Installing created the tables and gave the existing reading its Recordings record. The first visit in one browser brought over its existing takes across several posts and its taught words.
- A take opened in Firefox, which had never made one for that post, showed changes "since last take" against Chrome's history, and Chrome's copy then showed the Firefox take with its numbers (energy 6.5 dB, pitch 1.8 semitones, mode "file").

---

## [0.8.0] Text-based editing (P)

Fix a reading by editing its words, without recording the whole post again.

#### Added
- **Edit audio** in the review studio. With it on, clicking a word or a filler selects it (shift-click selects more, double-click a whole sentence), and **Cut** or the Delete key takes it out of the audio. The cut words stay in view with a gold double strike; clicking one brings it back.
- **Retake:** record the selected words again and splice them in. The new recording is trimmed to the speech, cleaned up the same way as the take (noise reduction included), matched to the take's speaking level, and joined with a crossfade at each side. **Hear it in place** plays it with a few seconds either side before you choose **Use this take**. Retaken words get a gold bar, and their captions stay.
- **Tidy** suggests a cut for every filler and repeat the review found, and shortens pauses over 1.5 seconds (to 0.8 s within a paragraph, 1.2 s between paragraphs). Each suggestion can be heard first and unticked; nothing changes until **Apply**.
- **Preview** plays a few seconds around the selection or the last edit, as it will sound.
- **Undo** (or Ctrl+Z) steps back one change at a time, and a shortened pause shows its old and new length.
- **Make edited MP3** builds a new file: the edited audio goes through the whole voice chain, loudness and studio check again, gets its tags, and its chapters and captions are moved to match. The download button, the captions and the chapters file then give the edited versions. The original recording, and its WAV, are never changed.

#### Changed
- Mastering (the voice chain, loudness, MP3 and studio check) is now one function, `masterMp3()`, used for new readings and edited ones alike.

#### Why this way
- **Cuts are placed by the audio, not by Whisper.** Whisper's word times can be off by a few hundredths of a second. Each edge of a cut moves to the quietest moment nearby, measured in 10 ms steps, which is nearly always the gap between words. A 12 ms equal-power crossfade hides the join.
- **A cut takes half of each surrounding gap**, so the pause left behind is about the length of the two halves together and the reading keeps its rhythm. **A retake replaces only the words**, so the pauses around a re-recorded sentence stay as they were.
- **Edits are a list of time ranges**, applied only when audio is rendered. That makes undo simple, keeps the original untouched, and lets one function shift every caption and chapter time.
- **The whole sound chain runs again on the edited audio**, so loudness is measured on exactly what listeners hear, and compression never reacts to audio that was cut.
- **Fillers come from Whisper**, which often tidies away "um" and "uh" when it transcribes. Tidy says so, and any filler can be struck by hand.

#### How it was checked
- On a synthetic reading with Whisper-like timing errors of up to 60 ms, every planned cut landed in the gaps without touching a kept word, including speech with gaps of only 20 to 70 ms. Edited audio is exactly the expected length, no join produced a sample step larger than the original's own, and every kept word sits exactly where the time map says.
- Replacement audio: exact length and time mapping, the replacement sits where the map says, and a plain cut overlapping a retake gives way to it.
- End to end on a local site, with a spoken test take carrying a planted "um", a repeated "the" and a 2.4-second pause, and a stand-in for Whisper reporting their times: Tidy found all three; the edited MP3 was 2.0 seconds shorter at -19.2 LUFS with three chapters, each moved by exactly the right amount, and captions shifted to match.
- A retake through a fake microphone, recorded about 8 dB quieter on purpose, was level-matched by +9 dB, replaced only the sentence's words (4.8 s against a true 4.62 s), kept its captions, and could be brought back with one click.

#### On a real voice (10 November 2026)
- The maintainer edited a real take on the live site: the editing felt fast and the joins sounded natural on headphones.

---

## [0.7.0] Podcast-ready files (P)

Each MP3 now carries everything a podcast app or phone shows, and is made by a current, much faster encoder.

#### Added
- **Tags in every MP3:** the post's title, its author, the show name, the year, a link back to the post, the genre Podcast, and the featured image as artwork (scaled to at most 1400 pixels a side). These are the same details the listening player already gives a phone's lock screen.
- **Chapters at the post's headings.** Once the Whisper review knows when each heading was read, the MP3 behind the download button is re-tagged with a chapter for each one, plus an opening chapter named after the post. The status line says so, and that a file downloaded earlier needs downloading again to have them. Headings not reached in a take are left out, and a post without headings gets no chapters.
- **A chapters file** (Podcasting 2.0 JSON) next to the captions in the review studio, for podcast hosts that take one.
- **A Show name setting** (Settings, Reading Room, Listening player), used in the MP3's tags and on phone lock screens. Left empty, the site title is used, then the site's address, so the show is never blank (a site with no title used to show nothing on the lock screen).
- **Chapters in the listening player:** notches on the progress bar, a Chapters button with a list to jump from, and the current chapter's name beside the label. The player reads the chapters from the uploaded MP3 itself, so the Reading audio box needs nothing new.

#### Changed
- **MP3s are made by LAME compiled to WebAssembly** (`mp3.wasm` from `wasm-media-encoders` 0.7.0) in place of lamejs. A four-minute reading encodes in about 1.4 seconds instead of 14, in a background worker so the page stays responsive. The audio is handed over without rounding to 16 bits first.
- The encoder's fingerprint is checked in the browser every time it loads, from your site or from jsDelivr, as the noise model's is. **Download to this site** saves the new encoder and removes the old `lame.min.js`.

#### Why this way
- **Our own small tag writer** (`id3.js`, about 250 lines) in place of a library. It writes ID3 version 2.3, the version every podcast app and phone reads, and reads chapters back for the player. One less outside file to pin and trust.
- **Chapters come from the review**, since Whisper's word timings are the only reliable record of when each heading was said. The review hears the same trimmed recording the MP3 is made from, so its times line up with the MP3. Each chapter starts 0.3 seconds before its heading's first word, so it never begins mid-syllable.
- **The tag sits at the very start of the file**, so adding chapters swaps that block and never touches the audio.
- **Constant bitrate only.** LAME can make smaller variable-bitrate files, but this build cannot go back and write the header that tells players a variable file's length: a four-minute test file showed as 6:40. Constant bitrate needs no such header and seeks exactly, which chapters rely on.
- **The player fetches only the start of the MP3** (64 KB) to find the chapters, after the page has settled, and stops reading once it has enough even if a server ignores the byte range. Chapters are written before the artwork in the tag for this reason.

#### How it was checked
- Tags were read back by three independent readers (our own, ffprobe and mutagen): title with accented letters and a moon symbol, author, show, year, genre, link, length, encoder, artwork and three chapters with their table of contents.
- Chapter timing was tested on simulated reviews: a full take, a heading Whisper missed (the next heard word is used), a take stopped before the last heading, and a title that was not read aloud.
- On a local site, a noisy test recording went through cleanup, broadcast sound and the new encoder in 2.2 seconds (4.1 with lamejs), landing at -19.2 LUFS. The new encoder loses about 0.3 dB at 128 kbps and 0.2 dB at 192 (lamejs lost about 0.5); the existing measure-and-nudge step covers it.
- In the player, chapters loaded from the MP3, the notches sat at the right places, and choosing a chapter jumped to it and highlighted it.

#### On a live site (10 November 2026)
- The encoder loaded from the site's own copy with its fingerprint matched. A minute of audio encoded in 0.9 seconds, against 3.7 seconds for lamejs in the same browser.
- After the caching plugin combined its scripts, the player still worked for visitors and `id3.js` loaded with it.
- The site had no WordPress site title, so the show name was blank, as it had been on lock screens all along. The Show name setting fixed both.
- The site's posts had no headings yet, so chapters were not tried on a live take; they rest on the local tests above.

---

## [0.6.0] Speech-model noise reduction (P)

Cleanup now uses a small speech model to lower background noise, so a reading made in an ordinary room sounds closer to one made in a quiet one.

#### Added
- **RNNoise**, a small neural network trained to tell speech from noise, runs over each finished reading as the first step of cleanup. It handles noise the learned noise profile could not: a fan that changes speed, traffic going past, a chair. It runs after the reading ends, like Whisper, so the computer stays quiet while you read.
- **A strength setting** in Settings, Reading Room, Broadcast sound: Gentle (the background drops by up to 12 dB), Medium (up to 20 dB, the default) or Strong (as far as the model goes), or Off for the reduction used before. Strength crossfades a little of the original back in, which caps how far the background can drop. That keeps word endings and soft consonants from being swallowed, which is where speech models go wrong, and leaves the voice at its own level.
- **The Room line reports before and after:** the room as recorded, how far the background was lowered, and where it sits now. If the model could not run, the line says why.
- **The noise model can be served from your own site:** Settings, Reading Room, then Download to this site under *Fonts, MP3 encoder and noise model on this site* now saves it too (110 KB).

#### Changed
- When the model has run, the older steady-hush reduction in cleanup is skipped, so the background is never processed twice. Clicks, stray sounds and whistles are still handled as before.

#### Why this way
- **Only the model file is used.** The model is `rnnoise.wasm` from the official `@jitsi/rnnoise-wasm` 0.2.1 package (RNNoise is BSD-licensed, the packaging Apache-2.0). The package's own JavaScript loader is replaced by about forty lines in `denoise.js`, so nothing else from the package runs.
- **The fingerprint is checked every time the model loads**, in the browser, before it is compiled, whether it comes from your site or from jsDelivr. A file that does not match is never run, and the MP3 is made with the older reduction instead. The settings screen checks it again before saving a copy.
- **It runs at 48 kHz in a background worker.** RNNoise is built for 48 kHz in 10 ms frames, so the reading is converted with the browser's own resampler and back. A ten-minute reading takes about 16 seconds and the page stays responsive.
- **Whisper still hears the untouched recording**, so its results cannot change.

#### How it was checked
- A test reading with a fan that changes speed and traffic passing, 20 dB under the voice: the background dropped by 10 dB on Gentle, 17 dB on Medium and 28 dB on Strong, and the voice level moved by less than 0.2 dB. In the finished MP3 the quietest half second went from -48.0 dB (the older reduction) to -50.8, -58.3 and -77.0 dB.
- The model's one-frame delay (480 samples) is measured from its output and taken back out, so the cleaned audio lines up with the original sample for sample.
- An altered model (one extra byte) is refused in the browser and by the settings screen. With the model missing and jsDelivr blocked, the MP3 is still made and the Room line says noise reduction could not run.

#### Tested on a real voice (10 November 2026, a 4:27 take)
- The room measured 37 dB between voice and background. Medium lowered the background by another 10 dB, to 47 dB below the voice, and the finished MP3's background was -76.2 dB. Loudness -19.1 LUFS, true peak -2.1 dBTP, Whisper 98.9%.
- On the take, the model's delay measured exactly 480 samples, so the cleaned voice and the original line up.
- The maintainer heard no warbling or cut-off word endings on headphones. In a side-by-side of the same passage, Gentle (9 dB lower) and Medium (14 dB lower) were hard to tell apart.

---

## [0.5.0] Broadcast sound (P)

Readings now get the processing a podcast editor would give them, and are leveled to a published loudness standard.

#### Added
- **A voice chain** after cleanup, each step switchable in Settings, then Reading Room, Broadcast sound:
  - **Pops** on P and B: a burst of low rumble far above the voice's own low end is pulled down, there only.
  - **Low rumble filter** below 80 Hz, for desk bumps, traffic and handling.
  - **Breaths** lowered by 6 dB and kept in, so the reading still sounds natural. A breath is found by being noisy and unvoiced, well below the words, and leading straight into a phrase.
  - **De-esser** for harsh S, Sh and Ch sounds, by up to 8 dB in the top band only. It skips breaths, which are a hiss too.
  - **Gentle compression**, 2 to 1 with a soft knee, following whole words, so loud words sit closer to the rest.
- **Loudness to a standard** (ITU-R BS.1770-4, gated): **-19 LUFS** by default for podcasts. A mono file measured as one channel plays about 3 dB louder on phones and headphones, so -19 sounds like the -16 that stereo shows aim for. -16, -14 and -23 can be chosen instead.
- **A true-peak limiter**, so peaks between samples stay under -1.8 dBTP before encoding and under -1 dBTP in the finished MP3.
- **Loudness checked on the MP3 itself.** The lamejs encoder loses about half a decibel of loudness on a real voice, so the finished file is measured and, if it is more than 0.3 dB off, nudged and encoded once more.
- **An audiobook preset** that follows Audible's ACX rules: -20 dB RMS, peaks under -3 dB, 1.5 s of room tone kept at the start and 2.5 s at the end when the recording has it, and a 192 kbps constant-bitrate MP3.
- **A Sound line and a Studio check line** in the read-out. The check measures the finished MP3, as a listener or Audible receives it: loudness, true peak, peaks, background, room tone and clipping, each against its rule. Podcast specs set no rule for background noise, so that one is shown as information.

#### How it was checked
- Loudness was tested against the EBU's own test signals (Tech 3341): a 1 kHz tone and the two gating cases measure within 0.02 dB of the published values, at 44.1 and 48 kHz. The K-weighting filters reproduce the coefficients printed in BS.1770 exactly. True peak is within the standard's tolerance on its test signal.
- Each detector was checked on a test reading with planted pops, S sounds and breaths, and found them at the right moments.
- Everything runs in a background worker so a long reading never freezes the page. If the worker or any step fails, the MP3 is made with the simple leveling instead.

#### Tuned on a real voice (10 November 2026, a 1:54 take)
- First run: compression reached 12 dB on the loudest words and the MP3 landed at -20.0 LUFS. The compressor was slowed to follow whole words (2 to 1, about 30 ms detector), loudness is re-measured after limiting, and the MP3 is checked as above.
- Final run: -19.2 LUFS, true peak -1.9 dBTP, background -56 dB, about 2 dB of compression on a typical word (7 at most), 4 pops and 37 harsh S sounds softened, no breaths lowered. Whisper accuracy 99%, unchanged by the processing.
- The maintainer compared it with the 0.4.0 sound of the same take, matched within half a decibel, and judged the 0.5.0 version fine. A second listener is still to come.

#### Fixed
- A recovered take now remembers how far the reading got, so the read-out's pace is right. Before, it assumed the whole post had been read.

#### Unchanged
- The Whisper review still listens to the untouched recording.
- Choosing **Neither** in Settings gives exactly the 0.4.0 result.

---

## [0.4.0] Lossless recording (P)

The first of the podcast audio milestones (see the renumbered [ROADMAP.md](ROADMAP.md)). Prompted by a look at what podcast tools do that the teleprompter does not: the biggest gap was at the very start, where the browser compressed every recording before cleanup had a chance to work on it.

#### Added
- **Lossless recording.** The microphone is captured as raw audio by a small AudioWorklet, only while the reading runs, so it still pauses with the reading. Cleanup, the read-out, the MP3 and the Whisper review now all work from the untouched signal. Before, the browser recorded to Opus (WebM) at 96 kbps and the MP3 step compressed it a second time.
- **The Original download is a 16-bit WAV,** the true master of each take.
- **Crash-safe takes.** About once a second the recording is saved in the browser's own storage (IndexedDB). If the page closes, freezes or crashes before the take is downloaded, the teleprompter offers to recover it the next time it opens, with everything but the last second or so. Recovery runs the same cleanup, MP3, read-out and review as a fresh take.
- The recovery offer appears on the post the take belongs to, or on the start page with a button to open that post. **Discard** asks once more before deleting.

#### Changed
- **Whisper now listens to the untouched recording.** The cleanup is for the listener's MP3 only. Found on the first real-voice take with lossless recording: the same 26 second take scored 96% raw and 67% after cleanup. Step by step, the background-noise reduction was the cause, although it changed the voice by only about 0.3 dB. Speech recognition is trained on ordinary, unprocessed audio, and noise reduction made for human ears can tip it. The old compressed recordings had hidden this by giving the noise reduction less to do. With the fix, the same take scored 96% with cleanup on.

#### Kept small
- A take is removed from the browser as soon as its MP3 or WAV is downloaded, and at most three unfinished takes are kept. A ten-minute take is about 55 MB at 48 kHz.

#### Fallbacks
- Where AudioWorklet is missing or the browser's audio does not start, recording falls back to the browser's compressed recorder exactly as before. Those takes are not kept for recovery.

#### Also
- Tested on a real voice on 10 November 2026, including recovering a take that was never downloaded.
- The roadmap was renumbered so versions rise in build order. The podcast audio work is 0.4.0 to 0.8.0; the stats work now starts at 0.9.0.

---

## [0.3.2] No outside requests (P)

Finishes the self-hosting left over from 0.1.0. With the new download in Settings, the teleprompter page loads its fonts and its MP3 encoder from the site itself, so a reading session makes no outside requests apart from Whisper (which has had its own download since 0.1.0) and, in Chrome and Edge, voice following.

#### Added
- **Fonts and MP3 encoder on this site,** a new section in Settings, then Reading Room, with one **Download to this site** button. It saves both into `wp-content/uploads/rrt-assets/`, one small request per file so nothing times out on shared hosting, and shows progress as it goes.
- **Fonts come from whatever font link is set in Settings.** The server asks Google Fonts for the stylesheet as a current browser would, downloads each font file, and writes its own stylesheet pointing at the copies. Change the font link and the new fonts load from Google Fonts straight away; download again to bring them onto the site.
- **The MP3 encoder (lamejs 1.2.1) is checked against its SHA-256 fingerprint** before it is saved, and still loads with its integrity check in the browser.

#### Safety
- Only font files from Google's font host are accepted, and each must be a real woff2 font. The plugin writes the stylesheet itself, from a short list of safe properties in Google's answer.
- A failed or altered download is thrown away and the earlier copy stays in use.
- Only administrators can run the download, with a security token on every step.

#### Fallbacks
- The page uses a local copy only when it is present and, for fonts, made from the current font link. Otherwise it loads from Google Fonts and jsDelivr exactly as before.
- If the local encoder fails to load in the browser (missing, blocked or altered), the page loads it from jsDelivr once, just before making the MP3.

#### Also
- Deleting the plugin now also removes `uploads/rrt-assets/`.
- README and ARCHITECTURE brought up to date with 0.3.x.

#### Decided
- Splitting `teleprompter.js` into one file per area waits until someone needs to work inside it. It changes nothing a reader sees, and it is the riskiest of the 0.1.0 leftovers.

---

## [0.3.1] Security hardening (P)

A line-by-line review of every place the plugin takes input, prints output or checks permissions, done before anyone else sees the code. Most of it was already sound: every form and download checks both permission and a security token, everything printed is escaped, and the Whisper download can only fetch its own fixed list of files. These gaps were closed:

#### Fixed
- **Hidden posts stay hidden.** The player never shows the reading of a draft, private post or password-protected post (before the password is given) to someone who cannot read that post. Before, a `[reading_room_player post="…"]` shortcode could have shown the reading of a post that was not public.
- **The teleprompter's post menu lists only posts the person may edit.** Before, anyone with the teleprompter open could see the titles of every draft and private post, including other people's.
- **The Reading audio box** only accepts media the person is allowed to read.

#### Hardened
- **The MP3 encoder loads with an integrity check** (subresource integrity). If the CDN ever served a different file, the browser refuses to run it.
- **Every self-hosted Whisper file is checked against its known SHA-256 fingerprint** before it is saved. The model fingerprints match Hugging Face's published ones and the library fingerprints match the official npm packages. A tampered or broken download is thrown away with a message.

#### Also
- Comments and docs describe caching plugins, hosts and themes in general terms rather than naming the ones one site uses.

---

## [0.3.0] The listening player (P)

Live on the site since 9 November 2026, in the post header where the theme's own player used to be.

Built before 0.2.0 because everything about listeners (stats, the dashboard, the monthly email, social clips) needs a player the plugin controls. 0.2.0 is still next on the roadmap.

#### Added
- **A player on posts with a reading:** play and pause, a progress bar you can drag or click, time, skip back 15 seconds and ahead 30, and speed (0.75× to 2×, remembered in the listener's browser). Styled from the same colour and font settings as the teleprompter; the live site look matches the redesign's own player.
- **Read along:** with a captions file on the post, the post text itself lights up as it is read, word by word. Clicking a word jumps the reading there. If the post and the captions differ too much to line up (under 60% of words), a transcript list is shown under the player instead.
- **Lock screen and headphone controls** through the Media Session API: title, author, site, the featured image as artwork, play, pause, skip and seek.
- **Resume where you left off,** offered when a listener comes back (kept in their own browser only, for up to 60 days).
- **Links that start at a moment:** add `?t=1:45` (or `#t=1:45`, `?t=105`, `?t=1m45s`) to a post's address.
- **Without JavaScript** the reading is a plain audio player, so it always plays.
- **A Reading audio box** on the post edit screen to choose the MP3 and the captions (.vtt) from the media library. Captions files can now be uploaded.
- **Settings, then Reading Room, Listening player:** show it to nobody, only editors (a private preview) or everyone; at the top of the post or only where placed (`[reading_room_player]` shortcode, or `rrt_player()` in a theme); another custom field to read the reading from; skip lengths; speeds; read along, resume and download on or off; where the post text lives for read along.
- Every listener action is announced as a browser event, `rrt:player`, ready for 0.4.0's analytics. Nothing is stored or sent yet.

#### Notes
- The player script and styles avoid two things some caching plugins' optimisers are known to break: a double ampersand in scripts, and slashes inside CSS values.
- The player starts as **editors only**, so installing this version changes nothing for visitors until it is switched on.

#### Fixed during the live-site preview
- Themes that style every button (some make them a solid colour with capital letters) were overriding the player's buttons. A guard block with stronger selectors keeps the player's own look.
- Centred page headers centred the player's label. The player now sets its own text alignment, and adds space above itself when it follows other content.
- The player's style and script addresses now include the file's change time, so a cache purge always picks up a new version.
- Speeds are sorted as numbers.

#### Moved to a later release
- Chapter marks at headings, and the transcript accuracy check (it needs the review studio to save its results, which arrives with 0.2.0).

---

## [0.1.0] One codebase (P)

The plugin now has every feature of the theme template, so features are built once and friends get the same tool. Built from template 0.0.10 and plugin 0.0.4, and tested in a real WordPress 6.5 install in Chrome.

Installed on the live site alongside the template and checked with the same early test recording through both: accuracy, pace, all 34 flags, the marked-up script, read-out, cleanup and take history came out identical, and the 12 Whisper files already in `gw-whisper/` were found and used.

### Added
- **Settings, then Reading Room** in WP-admin replaces the MAKE IT YOURS block: wording, nine labelled colours, fonts and the Google Fonts link, starting pace, size, width and reading line, which options start switched on, which content types can be read, and where Whisper loads from.
- **Presets:** *Neutral* (charcoal and amber) and a dark preset (void, plum and gold). Applying one replaces wording, colours and fonts and keeps everything else.
- **Download Whisper to this site** button: fetches the model and runtime (about 116 MB) into `wp-content/uploads/rrt-whisper/`, one file per request so nothing times out, with progress per file. No SSH needed.
- A *Teleprompter* link on Pages as well as Posts, and Settings and Open links on the Plugins screen.
- `uninstall.php` removes the saved settings when the plugin is deleted.

### Changed
- One file became a proper plugin: `includes/` (settings, render, Whisper, admin), `views/teleprompter.php`, `assets/css/teleprompter.css`, `assets/js/teleprompter.js`. Styles and script are cached by the browser and versioned.
- Every colour and font in the stylesheet now comes from tokens, so any look works without touching CSS.
- Browser storage moved to `rrt` names. Anything saved under the old `gw` names (settings, takes, taught words) is copied over once on first load, so nothing is lost.
- Whisper files already in `uploads/gw-whisper/` are still found and used.
- The page lives at `yoursite.com/?teleprompter=1&tp_post=ID`, the same address scheme as the 0.0.4 plugin.

### Not done yet (still on the 0.1.0 list)
- Self-hosting Google Fonts and the MP3 encoder.
- Splitting the script into one file per area. It is one file for now, with its sections marked.
- Retiring the old template page on the live site (the plugin is installed and verified; steps in SETUP.md), then removing the template.

---

## [0.0.10] Audio cleanup and room check (T)

Prompted by a friend's listen-back of an early test reading, which picked out fan noise, ticking sounds before sentences, and "bird whistles" that would be hard to bear on headphones. An analysis of that recording found: background about 44 dB below the voice with no tonal fan whine; sharp clicks in the half second before 57 of 86 phrases; a thin whistle near 4.7 kHz inside roughly 1 in 7 S sounds; flickering "musical noise" in the pauses about four times higher than a plain recording would have; and a probable chair creak at 1:51.

### Added
- **Clean up audio** checkbox (on by default). Before the MP3 is made, the cleanup:
  - **De-clicks:** finds short, sharp sounds in the 0.6 seconds before each phrase begins and quiets them. Because they happen before speech starts, this cannot touch the voice.
  - **Quiets stray sounds:** bumps and creaks in the middle of pauses.
  - **Lowers steady background:** learns the noise from the quietest moments and reduces it smoothly, so it never flickers.
  - **Tames whistles:** dips only the narrow whistle band, only during S sounds.
  - Settles long pauses slightly lower, leaving short gaps, word tails and the breath before each phrase alone so nothing sounds chopped.
- **Room** line in the read-out and review: background level, clicks found and fixed, share of S sounds that whistled. Comparing this between takes shows whether a setup change helped.
- **Review a recording** became **Open a recording**: it now cleans an existing file, makes an MP3 from it, and reviews it. It appears once a post is chosen, since it needs the script to compare against.

### Changed
- Turned off the browser's own noise suppression and automatic gain on the microphone. The browser's suppression was the main source of the "birdies" (warbly artifacts left behind when a noise filter fights a steady sound).
- MP3 quality raised from 96 to 128 kbps, which reduces artifacts in quiet passages.
- Leveling allows more gain (up to 16x) now that the microphone's automatic gain is off.

### Learned
- The first diagnosis of the clicks guessed at the wrong cause. They are ordinary mouth clicks from saliva and the lips parting on an in-breath. The fix in software was the same, but the advice changed. Lesson recorded in DESIGN.md: measure, then ask, before explaining a cause.

---

## [0.0.9] Smarter comparison and teachable Whisper (T)

The first real Whisper review (test reading: 95.9% accuracy, 18 misreads, 17 passages to retake) showed that most "misreads" came from Whisper mishearing the maintainer. The maintainer checked three by ear and all three were said correctly. He also judged the four sentences flagged as rushed to sound fine.

### Added
- **Sound-alikes** treated as the same word (too/two, there/their, and around 130 common pairs).
- **Numbers**: digits match their spoken form ("20" and "twenty").
- **Contractions** both ways ("it's" heard for "it has", "it is" heard for "it's").
- **Compounds**: "blue collar" heard for "blue-collar", "jack o lanterns" for "jack-o-lanterns".
- **Grouped mishearings**: extra words Whisper adds right before a misheard word are folded into that one mishearing ("Jacquelyn terms" for "jack-o-lanterns" now counts as a single misread).
- **"I said it right"** button on each misread, and **"I didn't say this"** on imagined extra words. Taught pairings apply to every future review of every post.
- **Words Whisper has learned** list in the review, each with **Forget**.
- **Worth a listen**: a gentler category for near-forms like "coming" heard for "come". These often come from Whisper, so they do not count against accuracy or the retake list.
- Teaching re-runs the comparison instantly without counting as a new take.

### Changed
- Pace flags loosened: a sentence must have at least 8 words, last over 2 seconds, and run over 200 wpm and 32% above your typical sentence pace to be called rushed (slow: under 105 wpm and 32% below).

In testing with the real problem words, accuracy went from 89% to 98% after five words were taught.

---

## [0.0.8] Whisper served from your own site (T)

### Added
- The Whisper code library (transformers.js and the ONNX Runtime WebAssembly files) can now load from `wp-content/uploads/gw-whisper/lib/`, in addition to the model from `wp-content/uploads/gw-whisper/`.
- `tools/whisper-setup.sh` (then named `whisper-setup.sh`): one script that downloads the model and runtime onto the server (about 116 MB). Written as a script file because a one-line SSH command's quoting was mangled by the local shell.
- The status line names where Whisper came from ("served from" and the site's name).

### Changed
- Each source falls back independently. If a self-hosted file is missing or fails, that piece loads from Hugging Face or jsDelivr and the review still works.
- Older Safari gets the non-asyncify runtime build.

---

## [0.0.7] Whisper review studio (T)

The maintainer asked for the teleprompter to compare a reading against the script, running from the WordPress page with nothing installed. Two routes were considered: send recordings to Groq's hosted Whisper (cheap, fast, but recordings leave the computer), or load Whisper into the page. The maintainer chose the page.

### Added
- **Whisper review** checkbox (on by default). After recording, the page loads Whisper (`onnx-community/whisper-base.en_timestamped`, 8-bit, about 80 MB, cached by the browser after the first load) into a background worker and transcribes with a time for every word.
- **Script comparison**: aligns heard words to script words, finding skipped, misread, added and repeated words and fillers; unfinished and paraphrased sentences; per-paragraph accuracy; sentence pace against your own typical pace; long pauses and mid-sentence hesitations; flat sentences and faded endings.
- **Review studio**: dashboard tiles that filter the flagged list; pace heatmap timeline with pause and problem markers; the marked-up script with click-to-play and karaoke playback; take comparison per post; captions (.vtt) download.
- **Review a recording** button for any existing audio file.
- The current sentence is softly underlined while reading live.

### Fixed during build
- Alignment of a 2,800 word post took 7 seconds. Distinct word pairs are now scored once and cached, which makes it fast for any realistic post.
- Sentence shading was choppy because it stopped at each space. Whole sentences are now wrapped and shaded.

---

## [0.0.6] "How it went" read-out (T)

### Added
- After the MP3 is made, a short plain-language read-out:
  - **Pace**: actual words per minute against the pace that was set.
  - **Pauses**: share of time spent resting, and whether pauses shrank in the second half.
  - **Endings**: whether volume dropped at the ends of phrases.
  - **Pitch**: how much the voice moved within phrases, and where the flattest stretch was.
- A reminder under it that these are measurements, and the ears are the judge.

Written as sentences on purpose, with no scores or charts, so the focus stays on the reading itself.

---

## [0.0.5] MP3 export (T, P)

The first real recording was saved as WebM, which the site's audio field rejected and which many phones will not play.

### Added
- The recording is converted to MP3 in the browser (lamejs): mono, 44.1 kHz, 96 kbps, silence trimmed from both ends, leveled by the loudness of the speech itself, peaks held below clipping, 0.1 s fade in and 0.4 s fade out. Progress is shown as a percentage.
- **Original** download button for the untouched recording.
- If the conversion fails, the original is still offered.

A four minute reading converted in about 7 seconds in Chrome.

The plugin edition received the same MP3 export at the time, but that copy of the plugin was not kept. The plugin in this repository stays at 0.0.4 until the editions are merged in 0.1.0, which brings MP3 export back to it.

---

## [0.0.4] Plugin edition (P)

A friend wanted their own copy.

### Added
- `reading-room-teleprompter` plugin with the same features as 0.0.3, packaged so it works with any theme and survives theme updates.
- Opens from Tools, then Teleprompter, or a *Teleprompter* link on each row of the Posts list. No page or template needed.
- **MAKE IT YOURS** block at the top of the file: on-screen wording, content types, eight labelled colours, fonts and the Google Fonts link, starting pace, size, width and reading line.
- `rrt_config` filter for changing settings without editing the file.
- Neutral defaults (charcoal, warm amber, Lora and Inter). Nothing from the live site.
- Tells caching plugins not to cache it.
- `readme.txt` covering install, use and customising.

Note: this file's plugin header says `Version: 1.0.0`. It was the first release to a friend, before this numbering scheme existed. It is kept unchanged here so the history is faithful; the header will follow the repo's numbers from 0.1.0.

---

## [0.0.3] Voice following and reading comfort (T)

Based on research into commercial prompters (PromptSmart's VoiceTrack), open-source browser prompters, and professional prompter setup guides.

### Added
- **Follow my voice** (Chrome and Edge): browser speech recognition streams a rough transcript; each heard word is fuzzy-matched against the next several script words; the highlight only moves forward, and finds its place again a few words after a skip. Uses on-device recognition where the browser offers it. Falls back to timed pace if recognition is blocked or drops.
- **Line** slider: where the current line sits on screen.
- **Width** slider: column width.
- Timing by **syllables** (0.0.2 counted letters).
- **Screen wake lock** while reading.
- **Shift + Left/Right** to step 5 words.

---

## [0.0.2] Karaoke highlighting (T)

The maintainer's feedback on 0.0.1: the whole paragraph lit up at once, which does not tell you when to say what.

### Changed
- Highlighting is now word by word: a gold sweep on the current word, words already said turn bright, words ahead wait softer.
- Timing behaves like speech: longer words take longer, a short beat at commas, a longer one at full stops, an optional breath between paragraphs.

---

## [0.0.1] The reading room (T)

### Added
- `page-teleprompter.php` page template for a child theme, for a private page.
- Only logged-in editors can open it; everyone else is sent to log in.
- Pick a post from a menu or paste its address.
- The post is cleaned down to title, headings and paragraphs, shown large.
- Paragraph-by-paragraph highlighting at a set pace, with keyboard control (Space, arrows, Home) and click a paragraph to start there.
- Optional microphone recording, downloaded at the end (WebM in Chrome).

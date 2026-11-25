# Design notes

This is the "why" document. Each section explains a decision: the problem it answered, what was chosen, what else was considered, and what to watch out for when changing it. If you are about to change something, read its section first. If you make a decision worth remembering, add a section.

---

## Contents

1. [Principles](#1-principles)
2. [Where it lives](#2-where-it-lives)
3. [Turning a post into a script](#3-turning-a-post-into-a-script)
4. [Karaoke highlighting](#4-karaoke-highlighting)
5. [Timing that sounds like speech](#5-timing-that-sounds-like-speech)
6. [Following your voice](#6-following-your-voice)
7. [Reading comfort](#7-reading-comfort)
8. [Recording](#8-recording)
9. [Making the MP3](#9-making-the-mp3)
10. [The read-out](#10-the-read-out)
11. [Whisper in the page](#11-whisper-in-the-page)
12. [Comparing the reading to the script](#12-comparing-the-reading-to-the-script)
13. [When Whisper is wrong](#13-when-whisper-is-wrong)
14. [The review studio](#14-the-review-studio)
15. [Audio cleanup](#15-audio-cleanup)
16. [Self-hosting](#16-self-hosting)
17. [Two editions, then one](#17-two-editions-then-one)
    - [The listening player](#17b-the-listening-player)
18. [Lessons from real recordings](#18-lessons-from-real-recordings)
19. [Open questions](#19-open-questions)

---

## 1. Principles

These have guided every version. When two options seem equal, these break the tie.

1. **Private by default.** The reading room is for the person reading. It is behind a login, never cached, and recordings stay on that person's computer until they decide to upload. Anything that sends data elsewhere has to justify itself.
2. **Runs in the browser.** Many WordPress sites are on shared hosting, which cannot do heavy processing. The reader's computer can. Speech recognition, audio cleanup, MP3 encoding and analysis all happen in the page. This also keeps the server simple and free of extra cost.
3. **Never lose a take.** Every processing step can fail on some browser or some file. When one does, the untouched original is still there to download.
4. **Fail soft.** If an outside file does not load, use a fallback. If voice following drops, timed pace takes over. If Whisper cannot run, the MP3 and read-out still work.
5. **Plain language over scores.** Feedback is written as sentences a person would say. A number is shown only when it helps you compare one take with another.
6. **Your ears are the judge.** Measurements are a mirror. Whisper mishears, thresholds are guesses until tuned on a real voice, and whether a line landed is something no measurement can tell. The interface says so, and lets you overrule it.
7. **One file to deploy.** Each edition is a single PHP file (plus a readme for the plugin). Updating is copying one file. This has made iteration fast, and it is also the reason the file is now large. See the [roadmap](../ROADMAP.md) for when to split it.

---

## 2. Where it lives

**Decision:** a WordPress page template (the live site) and a plugin (for friends), both opening a full-screen page with no theme chrome.

**Why:** the posts are already in WordPress, so the teleprompter can read them directly instead of copying text into another tool. Being inside WordPress also gives login and permissions for free.

**Access:** the page checks `is_user_logged_in()` and sends anyone else to log in, then requires the ability to edit posts. The template is meant to sit on a *Private* page. It sends no-cache headers and tells LiteSpeed and other caching plugins to skip it, because a cached copy of a private page is a leak and a stale copy breaks updates.

**Considered:** a standalone web app (loses direct access to posts and login), a desktop app (needs installing, which the maintainer specifically did not want).

---

## 3. Turning a post into a script

**Decision:** render the post content through WordPress's content filters, then keep only the title, headings and paragraphs. Images, embeds, captions and shortcode output are dropped.

**Why:** a teleprompter shows what you say aloud. Anything you would not read out is noise on the screen.

**Watch out for:** posts that put readable text inside blocks this filter drops (for example a pull quote you do want to read). If that comes up, add that one block type to the keep list and leave the rest of the filter as it is.

Each word becomes a `<span>` with its paragraph, sentence and a "key" (lower case, punctuation removed) used for matching. Sentences end at `.`, `!`, `?`, an ellipsis, or the end of a paragraph.

---

## 4. Karaoke highlighting

**Problem (0.0.1):** the first version lit a whole paragraph at once. The maintainer's feedback: that does not tell you *when to say what*.

**Decision (0.0.2):** three states for every word. Already said: bright. Current: a gold sweep across the word. Ahead: softer. The current sentence also gets a faint underline (0.0.7) so you can see the shape of the thought you are in.

**Why three states:** the bright trail behind gives a sense of progress, the soft words ahead let you read ahead (which is how fluent reading aloud works), and the sweep tells you the pace at a glance.

---

## 5. Timing that sounds like speech

**Problem:** equal time per word sounds robotic. "I" and "autumnal" do not take the same time to say.

**Decision:** each word's share of time comes from its **syllable count** (0.0.3, replacing letter count from 0.0.2), with extra beats for punctuation: short at commas, longer at full stops, and an optional breath between paragraphs. The words-per-minute setting scales the whole thing.

**Why syllables:** letters overcount silent letters and undercount short dense words. A syllable estimate is closer to how long a word takes to say and is cheap to compute.

**Defaults:** 140 wpm. Comfortable listening pace for narration is roughly 130 to 160.

---

## 6. Following your voice

**Problem:** a timer never matches a real reading. You either chase it or wait for it.

**Decision (0.0.3):** use the browser's built-in speech recognition (Web Speech API) to stream a rough live transcript, and move the highlight when the transcript matches the next words.

**Decision (1.14.0): a speech model on the device instead.** The Web Speech API sends the audio to an outside service in most browsers, works only in Chrome and Edge, and matched one word at a time, forward only. Voice following now runs a small streaming model (a 20M-parameter Zipformer trained on read speech, Apache-2.0) with the sherpa-onnx engine (Apache-2.0) in a Web Worker. Research before building (docs/RESEARCH.md, Voice following) compared it with a larger Zipformer, NVIDIA's streaming FastConformer and Moonshine: on a real 13-minute phone reading it used about a tenth of one processor core, settled each sentence's last word about 0.4 s after it was spoken, and kept the follower within 3 words of the reader 97% of the time. The larger model was slower to settle and no better for following; the FastConformer could not keep up live.

**How matching works since 1.14.0 (follow.js):**
- After each new word, the **last six words heard** are lined up with the script near the current place (from 4 words behind to 40 ahead), allowing for misheard, extra and missing words. A match scores twice its likeness; an extra heard word or a script word not heard costs one. The best line-up moves the place.
- Likeness is **fuzzy**: short words must match exactly, longer ones may differ by a quarter of their letters, or share a rough sound-alike key, which is how names the model spells its own way still count.
- If nothing lines up for four words (an ad-lib, a long aside), the place **holds**. Then the whole script is searched for a strong match (five words or more), which follows a **skip ahead** or **going back** to read something again.
- The script's words are split at hyphens and dashes, and small numbers are written out, the way the model hears them.
- **Fail soft, and say why:** if the engine or model cannot load, or the device falls more than 1.5 s behind for 3 s, timed pace takes over from the current word, with a line saying which.
- The engine ships with the plugin (assets/vendor/sherpa-onnx), because its official WebAssembly build is published only inside a 175 MB archive that also packs a demo model. The worker hands its loader an empty package in place of that model and loads its own. The model loads from the site or Hugging Face, pinned to one revision and fingerprinted.
- While a take is recorded, the follower hears the recorder's own copy of the microphone, so there is only ever one microphone, which phones need.

**What it adds:** the pace over the last 30 s while following (pauses included, from 15 s of reading; a 20 s window from 8 s swung from 188 to 87 wpm on a real one-minute reading) (against the post's kind of reading, when it has one), and a **first look** the moment a take stops: how long, the pace, any paragraph never heard (one that only a stray word matched does not count as heard), and how well the follower kept up on this device. The Whisper review still makes the full judgement: the live model hears about 92 to 94% of a script's words exactly, against Whisper's 97%.

**Trade-off found earlier, still true:** following a voice makes the computer work, if far less than before. On a laptop with a loud fan, timed pace stays the quietest choice.

**Inspired by:** PromptSmart's VoiceTrack and Riverside's voice-following teleprompter.

---

## 7. Reading comfort

From professional prompter setup guides (0.0.3):

- **Reading line:** the current line sits at a fixed height on screen (default 30% from the top) so your eyes stay in one place. Near the top keeps your gaze close to the camera if you film yourself.
- **Column width:** long lines make your eyes travel and lose their place. Narrower is easier when reading aloud.
- **Text size:** large by default.
- **Screen wake lock:** a sleeping screen mid-reading ruins a take.
- All of these are remembered in the browser.

---

## 8. Recording

**Decision:** MediaRecorder on the microphone, paused and resumed with the reading.

**Microphone settings (changed in 0.0.10):** echo cancellation, noise suppression and automatic gain are all **off**. The browser's noise suppression is built for calls. On a narrated recording with a steady fan it produced warbly artifacts ("birdies"), and automatic gain pumps the background up in pauses. Cleanup is now done afterwards, with the whole recording available, which is better at it than a real-time filter.

**Format (0.0.1 to 0.3.2):** Chrome recorded WebM/Opus, Safari M4A. Neither was the final file; see the next section.

**Lossless capture (0.4.0):** a small AudioWorklet copies the microphone's raw samples instead. Opus is built to sound fine to a listener at low bitrates, but it throws detail away, and every later step (cleanup, leveling, the MP3) then worked on that loss and added its own. Keeping the raw signal until the one compression that matters is what podcast editors do. Choices:

- **Same controls as MediaRecorder.** The capture object has `start`, `pause`, `resume`, `stop`, `state` and `onstop`, so the reading, countdown and finish code did not change, and MediaRecorder remains the fallback.
- **Paused inside the worklet.** The reading tells the worklet to hold or record, so pauses are left out at the sample level, exactly as MediaRecorder's pause did.
- **16-bit WAV for the master.** It decodes in every browser and is as good as the microphone. Float WAV would double the size for nothing audible.
- **Only when the audio really runs.** If the browser's audio is still suspended after the microphone opens (some browsers want a click first), the worklet would capture silence, so the teleprompter checks and falls back to MediaRecorder instead.
- **Crash-safe in IndexedDB.** About once a second the new audio is written to the browser's database. A crash loses at most that last second. Each take is removed once its MP3 or WAV is downloaded, and only three unfinished takes are kept, because a ten-minute take is about 55 MB.
- **Recovery reuses Open a recording.** A recovered take is rebuilt as a WAV and goes through the same path as opening a file, so there is one way audio enters the studio after recording.

---

## 9. Making the MP3

**Problem (0.0.5):** the first real recording was WebM. The site's audio field rejected it, and many phones will not play it.

**Decision:** decode the recording in the browser and encode MP3 with lamejs. (Since 0.7.0, LAME compiled to WebAssembly; see section 9b.)

**Steps, in order:**
1. Decode to mono samples at 44.1 kHz.
2. Trim silence from both ends.
3. Clean up (0.0.10, see section 15).
4. **Level by speech loudness**, measured on the loud parts of the speech. Leveling by peak lets one loud plosive decide the volume of the whole reading. Gain is capped, and peaks are held below clipping.
5. Fade in 0.1 s, fade out 0.4 s, so the file does not start or stop with a click.
6. Encode mono MP3 at 128 kbps (96 before 0.0.10). Encoding runs in small chunks with progress so the page stays responsive.

**Why mono:** it is one voice. Stereo doubles the size with nothing gained.

**Why 128 kbps:** at 96 kbps, quiet passages with background noise picked up audible artifacts. 128 removes most of that for about 30% more size (a 4 minute reading is about 4 MB).

**Safety net:** if anything fails, the original recording is offered for download.

**Broadcast sound (0.5.0):** step 4 became a chain, in `sound.js`, when broadcast sound is on (the default). The order is the one podcast editors use, with two changes found in testing:

1. **Pops** first. A P or B puffs air at the microphone: a short burst under 150 Hz at least 12 dB above that band's usual level in speech, carrying most of the energy, for no more than 80 ms. That band is pulled down there only. It runs before the high-pass filter because the filter removes part of each pop, which hid them from the count.
2. **High-pass at 80 Hz** (fourth order). Below a speaking voice; above rumble.
3. **Breaths**, lowered 6 dB and kept in. The speech detector counts breaths as speech (they are within 28 dB of the words), so breaths have their own test: noisy and unvoiced (many zero crossings), at least 12 dB under the words, 0.12 to 0.9 s long, and leading straight into a voiced phrase. The last 30 ms before each phrase is untouched.
4. **De-esser**, by up to 8 dB on the band above 4.5 kHz when that band is most of the sound. It skips breaths, which are a hiss too and were first counted as S sounds.
5. **Compression**, 2 to 1, soft knee, threshold 6 dB under the loudest speech. The level it listens to is averaged over about 30 ms, so it follows words and leaves brief peaks to the limiter. The first version (2.5 to 1, a 5 ms detector) cut up to 12 dB on a real voice. No make-up gain; the next step sets the level.
6. **Loudness** to the chosen standard (see below), then a **true-peak limiter** at -1.8 dBTP, measured at four times the sample rate as BS.1770 describes. MP3 encoding raises peaks a little, so the margin leaves the finished file under -1. The limiter takes some loudness with it, so loudness is measured again and corrected once.
7. **The MP3 is measured.** lamejs lost about 0.5 dB of loudness per encode on a real voice (tested by encoding the same file twice; filtering above 16 kHz changed nothing, so it is the encoder). The WebAssembly LAME used since 0.7.0 loses about 0.3 dB at 128 kbps and 0.2 dB at 192. If the finished file is more than 0.3 dB off target, the level is nudged and it is encoded once more.

**Why -19 LUFS by default:** podcast guidance (Apple, most hosts) says -16 LUFS, for stereo. A mono file is measured as one channel, but phones and headphones play it on both sides, about 3 dB louder. -19 measured is -16 heard on most devices. Some devices compensate, and the difference is small either way, so -16 is one click away.

**Why measure the finished MP3:** the studio check decodes the MP3 and measures it, because the MP3 is what a listener or Audible receives.

**Why these numbers for audiobooks:** they are ACX's published requirements, checked in November 2026: RMS -23 to -18 dB, peaks at or below -3 dB, a noise floor at or below -60 dB RMS, 1 to 5 seconds of room tone at each end, 192 kbps or more constant-bitrate MP3 at 44.1 kHz. Room tone needs silence the reader actually recorded: the audiobook preset keeps up to 1.5 s before the first word and 2.5 s after the last.

**Testing:** loudness was checked against the EBU Tech 3341 test signals and the BS.1770 coefficient table, and true peak against its test signal (see `docs/RESEARCH.md`). The detectors were checked on a test reading with planted pops, S sounds and breaths. Speed: the detectors and compressor work out their gain once every 16 samples and smooth between, and the true-peak meter only rebuilds the waveform near peaks, so ten minutes processes in seconds in a background worker.

---

## 10. The read-out

**Question asked first (0.0.6):** would it make the readings better? Honest answer given at the time: somewhat. The biggest gains come from warming up, practice and listening back. The read-out helps most in the first dozen recordings while habits form.

**Decision:** four short sentences: pace, pauses, endings, pitch. No charts, no score.

**What each measures:**
- **Pace:** words read divided by duration, against the set pace.
- **Pauses:** the share of time below a speech threshold (gaps under 0.16 s are treated as part of speech), and whether pauses in the second half got shorter, which usually means speeding up.
- **Endings:** for each phrase, loudness of the last 20% against the rest. A big drop means last words get lost. Reported as a perceived loudness change.
- **Pitch:** pitch tracked with the YIN method; the spread within each phrase in semitones. Low spread reads as flat. The flattest 20 second stretch is pointed out so you can find it.
- **Room** (0.0.10): background level, clicks, whistles.

**Why sentences:** numbers invite chasing a score. The goal is a better reading, and a sentence like "your pauses got shorter in the second half" is something you can act on in the next take.

**Caveat in the interface:** thresholds were set on synthetic voices first and should be tuned on real recordings.

---

## 11. Whisper in the page

**Request (0.0.7):** compare what was said with the script, run from the WordPress page, nothing installed.

**Options considered:**
| Option | For | Against |
|---|---|---|
| Groq's hosted Whisper | Large model, fast, very cheap | Recordings leave the computer; needs an API key on the server |
| OpenAI's hosted Whisper | Familiar | Word timestamps only on an older model due to be retired |
| Run Whisper on the server | Private | Shared hosting does not have the processing power |
| **Run Whisper in the page** | Free, private, nothing installed | First load is about 80 MB; slower than hosted |

The maintainer chose the page.

**How:** transformers.js loads `whisper-base.en_timestamped` (8-bit quantised) in a **Web Worker**, so the screen stays responsive while it works. The browser caches the model after the first load. Audio is resampled to 16 kHz first, which is what Whisper expects. Whisper returns each word with a start and end time.

**Why base.en:** small enough to load and run on a laptop in a minute or two for a four minute reading. A larger model (roughly three times the size) mishears less and is on the roadmap as an option.

---

## 12. Comparing the reading to the script

This is the heart of the review.

**Alignment:** the script words and heard words are aligned with a dynamic-programming method (in the family of Needleman-Wunsch). Matches score positively, near-matches a little less, mismatches and gaps negatively. **Gaps at either end of the script are free**, so a reading that starts or stops partway through is not punished for the unread parts.

**From the alignment come:**
- matched words (correct), substitutions (misread), script words with no heard word (skipped), heard words with no script word (added, repeated, or filler)
- fillers from a fixed list (um, uh, er, hmm and so on); repeats when the extra word equals a neighbour
- every script word gets a time, even skipped ones (borrowed from neighbours), so every word is clickable

**Sentence flags:**
- **Unfinished:** the last two or more words were skipped but most of the rest was read.
- **Paraphrased:** under 60% read as written.
- **Rushed / slow:** compared with *your own typical sentence pace* (the median across the reading). A naturally quick reader should not be told every sentence is rushed.
- **Hesitant:** a long pause inside the sentence.
- **Flat:** little pitch movement over the sentence.
- **Faded:** the end of the sentence much quieter than the body.

**Speed:** scoring every pair of words is slow on long posts (7 seconds for 2,800 words). Distinct word pairs are scored once and cached, and pairs whose lengths differ a lot are ruled out without a full comparison.

**"To retake":** the number of sentences with a real slip, to give a sense of how much re-recording would be needed.

---

## 13. When Whisper is wrong

**What happened (0.0.9):** on the first real review, most "misreads" were Whisper's. It had rarely heard uncommon names and seasonal terms, and wrote down guesses. It also writes "too" as "two", "it has" as "it's", and "blue-collar" as two words. The maintainer confirmed by ear that three flagged words were said correctly.

**Decisions:**
1. **Normalise before comparing:** sound-alikes, numbers, contractions in both directions, and compound words heard as separate words are treated as matches.
2. **Group mishearings:** extra words Whisper adds right before a misheard word usually belong to the same mishearing, so they are reported as one.
3. **Let the reader teach it.** "I said it right" stores the pairing (script word, heard word) and treats it as correct from then on, on every post. "I didn't say this" ignores an imagined word. A list of learned words, each with Forget, keeps this visible and reversible. This puts the reader's ears above the model, which is principle 6 made concrete.
4. **"Worth a listen":** near-forms (same stem, different ending, like come/coming) are shown but not counted as errors, because they are often Whisper's.
5. **Loosen pace flags** after the maintainer judged the four "rushed" sentences fine. Natural reading speeds up and slows down.

**Watch out for:** learned pairings are global. Teaching "left" heard as "loved" means a real misreading of "left" as "loved" on another post would be missed. That is an acceptable trade for a reader's own vocabulary, but it is why the list is visible and every entry can be forgotten.

**Later (0.16.0):** "I said it right" now asks whether to remember the word in every post or just this post. Words for one post are kept with the post (post meta `_rrt_learned`), so a name or an invented word in one piece no longer hides real misreadings everywhere else. The list shows the two kinds apart. The larger Whisper model (small.en) is offered beside Whisper review for readers whose computers can take it; it makes fewer of these mistakes to begin with.

---

## 14. The review studio

**Layout:** player and pace timeline across the top; summary tiles, flagged moments, learned words and takes on the left; the marked-up script on the right.

**Choices:**
- **Every flag and word is clickable** and plays from just before that moment. A flag you cannot hear is a flag you cannot judge.
- **Tiles filter the list.** Click "Skipped" to see only skipped words.
- **Karaoke playback:** words light up as the recording plays, so you can follow along.
- **Pace heatmap:** each sentence coloured from slower to faster than your usual pace, with pauses and problem dots. Shows the shape of a reading at a glance.
- **Takes:** a small summary of each take is stored per post (last 12), so the table shows whether things are improving.
- **Captions (.vtt):** built from the *script* text with Whisper's timings, so the captions are spelled correctly even where Whisper misheard.
- Shown with an honest note: Whisper tidies speech and can miss ums and stumbles; treat counts as a guide.

---

## 15. Audio cleanup

**Problem (0.0.10):** a friend's listen-back flagged a fan, ticks before sentences, and whistling. Analysis of the actual file confirmed and located each one (see the changelog for numbers).

**Decision:** a cleanup pass in the browser before leveling and encoding. Each part is narrow on purpose, so it cannot damage the voice:

- **De-clicker:** only looks in the 0.6 seconds *before* each phrase begins. Clicks there come from the mouth opening to breathe. Since the voice has not started, quieting them is safe.
- **Stray sounds:** bumps and creaks in the middle of longer pauses are lowered.
- **Background reduction:** the noise profile is learned from the quietest frames of the recording itself, and reduced with smoothing over time and frequency, which avoids the flicker that real-time suppression causes.
- **Whistle tamer:** a narrow dip at the whistle frequency, applied only during S sounds and only where a whistle is detected.
- **Pause settling:** longer pauses are lowered slightly. Short gaps inside sentences, the tail of each word, and the breath before the next phrase are left alone, so it never sounds gated or chopped.

**Speech-model noise reduction (0.6.0):** the learned noise profile only knows steady noise, and only lowers it by about 10 dB before the voice starts to suffer. RNNoise, a small neural network built for calls, now runs first. Choices:

- **After the reading, not during it.** Running the model live would keep the computer busy (and its fan loud) while reading. On the finished recording it takes about a second and a half per minute of audio, in a background worker.
- **A crossfade toward the original as the strength.** Speech models fail by swallowing quiet word endings and making the voice watery. Mixing a little of the original back in caps how far the background can drop (12 or 20 dB) and keeps those edges. Strong lets the model do everything it wants, for rooms that need it.
- **Just the model file, checked every time.** The package's loader would have been a second outside file to trust. Instead the plugin loads the 110 KB WebAssembly file itself and checks its SHA-256 fingerprint before compiling it, wherever it came from. The browser checks the bytes it fetched itself, so the jsDelivr fallback is as safe as the local copy.
- **The older reduction steps aside.** When the model has run, the steady-hush reduction is skipped, so the background is never processed twice. If the model cannot run, the older reduction does the job as before, and the Room line says so.
- **For the listener only.** Like the rest of cleanup, the model shapes the MP3. Whisper hears the untouched recording (section 18).

**Report:** the cleanup also measures what it found, shown as the Room line. The point is to let a setup change (a different spot, a raised microphone, a softer room) be compared take to take.

**Recording advice that went with it:** sip water, nose breathing between sentences, raise the laptop to mouth height at arm's length, turn the head slightly off the mic, let the laptop cool, use timed pace while recording, and soft furnishings for room echo. A USB microphone away from the computer is the single biggest upgrade.

---

## 16. Self-hosting

**Decision (0.0.8):** the model and the code that runs it can be served from `wp-content/uploads/gw-whisper/`. PHP checks whether the files exist and tells the page; the page tries the site first and falls back to Hugging Face and jsDelivr per file.

**Why:** fewer outside dependencies, no reliance on Hugging Face staying up or keeping the model at the same address, and a cleaner privacy story (nothing about a reading session touches a third party).

**Why a script:** the one-line SSH command broke because the local shell rewrote the quotes. A script file copied with `scp` and run with `ssh ... bash script.sh` avoids quoting entirely.

**Fonts and the encoder (0.3.2):** the last two outside files can now be served from `uploads/rrt-assets/` too, with one button in Settings. Choices:

- **Downloaded by each site.** The plugin could have carried lamejs and a set of fonts in its own folder. Downloading them keeps every outside file's licence with its official source (LAME is LGPL, the fonts mostly OFL), leaves the plugin's own licence decision simple, and works for any font a site chooses.
- **Fonts follow the link in Settings.** A site can choose any Google Fonts link, so there is no fixed set to ship. The server asks Google for the stylesheet the way a current browser would, which gets woff2 files, then saves each font and writes its own stylesheet.
- **Written by the plugin.** The saved stylesheet is built from a short list of properties (family, style, weight, stretch, display, unicode range) taken from Google's answer. A font must come from fonts.gstatic.com and start with the woff2 signature. Nothing else Google's answer might contain reaches the page.
- **Matched to the link.** The local fonts are used only when they were made from the link currently saved. Changing fonts in Settings therefore works at once (from Google), and the status line says to download again.
- **Two fallbacks for the encoder.** PHP uses the local copy only when the file is there. If the browser still cannot load it, the script fetches jsDelivr's copy once, just before making the MP3, with the same integrity hash. A recording is never lost to a missing file.
- **Steps, as with Whisper.** A font link can mean twenty or more files. Each is its own short request, so a slow host never times out.

---

## 17. Two editions, then one

**How it happened:** the theme template came first. When a friend wanted a copy, the plugin was made from it with the site-specific parts stripped and a customisation block added. Since then, new features have gone into the template first, and the plugin has fallen behind (the copy in this repository is 0.0.4).

**Why that is now a problem:** every feature has to be built twice or the editions drift further. The upcoming analytics also need admin pages, database tables and scheduled jobs, which belong in a plugin.

**What 0.1.0 did:** the plugin became the only codebase, built from template 0.0.10 (features) and plugin 0.0.4 (how it plugs into WordPress). Choices worth knowing:

- **Settings in the database, looks as presets.** Editing PHP to change a colour was the main reason friends could not customise safely. A preset is just a starting set of wording, colours and fonts; once saved, every value is the site's own, so a later change to a preset never alters a site that has already been set up.
- **Tokens for every colour and font.** The 0.0.10 stylesheet had the live site's palette written directly into dozens of rules. Each was converted to a token (`--accent`, `--panel`, and so on), with transparent shades made by `color-mix()`, so any palette works without touching CSS. Flag colours in the review (skipped, misread, flat) stay fixed, since they carry meaning.
- **A page of its own.** The teleprompter replaces the normal page output at `?teleprompter=1`, so no theme template is needed and theme updates cannot break it. Styles and script are separate files the browser can cache.
- **Storage migration, once.** Browser data moved from `gw` names to `rrt` names. The script copies anything under the old names the first time it runs and marks it done, so nothing is lost and nothing is copied twice.
- **One script file for now.** The script relies on shared state across its sections. Splitting it safely means giving those sections a shared namespace, which is a refactor of its own; it is kept whole, with section headings, until that is done.
- **Download button, one file per request.** Shared hosting often stops a request after 30 to 300 seconds. Fetching each Whisper file in its own request keeps every request short, and shows progress.

---

## 9b. Podcast-ready files (0.7.0)

**Problem:** a downloaded reading was a bare MP3. A podcast app or phone showed the file name and no picture, and a long reading had no way in except scrubbing.

**Decisions:**
- **LAME as WebAssembly.** lamejs is a hand translation of an old LAME into JavaScript. The `wasm-media-encoders` build is the real thing compiled for the browser: ten times faster, current, and fed floating-point audio directly. As with RNNoise, only its `.wasm` file is used, with a short loader of our own, and its fingerprint is checked before every compile.
- **Constant bitrate, kept.** Variable bitrate would halve the file, but this build streams its output and cannot write the Xing header at the front that tells players a variable file's length, so a 4:00 file showed as 6:40. Writing that header ourselves is possible later; constant bitrate seeks exactly and needs nothing.
- **Our own ID3 writer.** `browser-id3-writer` (MIT) can write chapters since 6.4.0, but it ships only as a module and the plugin also needs a reader for the player. About 250 lines cover both, with no outside file to pin.
- **Version 2.3 tags.** Version 2.4 is newer, but 2.3 is what Apple's apps, Android and most podcast apps are known to read. Text that fits Latin-1 is stored that way, anything else as UTF-16, both allowed by 2.3.
- **Chapters from the review.** The teleprompter's scroll position is an estimate, and an opened file has none. Whisper's word times are measured from the audio, on the same trimmed timeline as the MP3. The MP3 is tagged without chapters as soon as it is made, then re-tagged when the review finishes; the tag is the first block of the file, so the audio is never rewritten.
- **Chapters live in the MP3.** The player reads them from the file it already plays, so there is no chapters field to fill in and nothing to fall out of step. Only the first 64 KB is fetched, after the page settles, and chapters come before the artwork in the tag so that is enough.
- **A show name of its own.** The lock screen and the first tags used the WordPress site title, which a site can leave empty while an SEO plugin supplies the title visitors see. A Show name setting, falling back to the site title and then the domain, means the show is never blank.
- **Artwork at 1400 pixels.** Apple asks for episode art between 1400 and 3000 pixels; 1400 keeps the MP3 small (about 150 KB for a photo).

---

## 9c. Text-based editing (0.8.0)

**Problem:** a stumble, an "um" or one flat sentence meant recording the whole post again.

**Decisions:**
- **Edit the script, not a waveform.** The review already shows every word with its time. Selecting words there is how people already think about a reading, and it needs no new view. A waveform editor would be a second, harder interface for the same job.
- **The audio decides the cut points.** Whisper's word times are close, not exact. Each cut edge moves to the quietest 10 ms within reach (a few hundredths of a second past the word, or into the gap), and joins get a 12 ms equal-power crossfade. Testing with deliberately wrong times (up to 60 ms off) still put every edge in a gap.
- **Half-gaps for cuts, tight edges for retakes.** A cut removes half of the gap on each side of the words, so the remaining pause is natural. A retake replaces only the words, because the pauses around a sentence are part of the reading's rhythm and the retake brings its own speech, not its own pauses.
- **Edits are data.** A list of { start, end } ranges, with replacement audio for retakes. The recording is never changed; the list is applied when previewing or making the MP3, undo restores the previous list, and one `mapTime()` moves every caption and chapter. Inside a retake, times spread evenly over the new recording, so its captions stay.
- **Master after editing.** The edited audio goes through the voice chain, loudness and studio check from the start. Compressing first and cutting afterwards would leave level jumps at the joins, and loudness measured before cutting would no longer be true.
- **Retakes join the take's sound.** A retake is trimmed to its speech with 80 ms of room either side for the crossfades, cleaned the same way as the take (noise reduction included), and set to the take's speaking level, measured from the 50 ms blocks within 10 dB of the loudest, so pauses do not skew it.
- **Honest about fillers.** Whisper often leaves out "um" and "uh". Tidy offers what the review found and says that more may be there, rather than promising a clean sweep.
- **Edits last for the session.** They live with the take in memory, like the review itself. Saving edits with a take is a possible later step.

---

## 9d. Reader stats on the server (0.9.0)

**Problem:** each browser kept its own last twelve takes per post and its own taught words. A new computer started from nothing, and nothing recorded which MP3 went live where.

**Decisions:**
- **The site is the record, the browser a copy.** Reviews still read their history from the browser at once; the site is fetched at page load and written in the background. A failed request never blocks a review, and takes made offline are sent on the next visit.
- **Tables for takes and recordings, user data for taught words.** Takes and recordings will be charted and joined with listener data later, so they get indexed tables. Taught words are a small per-reader list that is read whole, so they sit in user meta and are removed with the user.
- **Upload only on request.** "Use this recording" is the single path for audio to reach the site, as before 0.9.0 the reader's own upload was. It checks content (an MP3 frame or ID3 header, a WEBVTT header), attaches the files to the post, sets the Reading audio fields, and writes a Recordings row linked to the take.
- **Every reading gets a row,** however it arrived: uploads write a full row; choosing a file by hand in the Reading audio box (or the theme's custom field) writes one from WordPress's own reading of the file; readings already on posts got one at installation, with a tempo estimated from the post's word count.
- **Energy as loudness spread within phrases.** The standard deviation of voiced 20 ms frames, leaving out the last fifth of each phrase (the endings measure covers that), typical phrase reported. The bands in the read-out are starting points; the stored number matters more, for trends.
- **Goals wait for the dashboard.** Targets mean little without the trend view that 0.11.0 brings, so they move there.
- **Keep data on uninstall by default.** Deleting a plugin is often a step in reinstalling it.

---

## 9e. Listener analytics (0.10.0)

**Problem:** the player already announced every action as a browser event, but nothing was kept, so there was no way to know who listens, for how long, or which links bring people who stay.

**Decisions:**
- **Anonymous by construction.** The day's listener id is a keyed hash of IP address, browser name and site name with a salt that is replaced each day and never kept. It supports unique counts and rate limits for one day and is useless after that. The IP address is used for the hash and the country lookup and then dropped. The database was searched after testing to confirm no address was there.
- **Count people, keep visits.** Sessions (page views) are stored as they happened. The rollup groups them by listener id, reading and day, so refreshes add page loads but not visitors or plays, while listening time and stretches heard combine across the visits.
- **Heard means played.** A 5 second bucket counts once 2 seconds of it were played, measured by the audio's own position between time updates, so a jump is one large step and is ignored. The previous event fired on every bucket change, seeks included.
- **The server decides what it can.** Channel, device class, country, recording and day are worked out on the server from small hints. A modified page cannot pick its own recording or country, and the grouping can improve later without changing the player.
- **Editors are recognised by their login cookie.** The beacon carries cookies but no REST nonce, so WordPress treats it as a visitor; `wp_validate_auth_cookie()` finds the user without trusting anything the page says.
- **Country from a local file.** Most hosts send no country, and a lookup service would mean sending visitors' addresses to someone else. DB-IP's Lite edition is free under CC BY 4.0. The CSV (about 700,000 ranges) is converted while it streams into a sorted binary list of range starts (IPv4 as 4 bytes, IPv6 as its /64 network in 8 bytes, each with a 2 letter code, gaps marked unknown) and searched by halving with `fseek`, about 20 reads per lookup. A header from the host or CDN wins when present.
- **Whole days are rebuilt.** Each rollup replaces a whole day's summaries from that day's sessions. A late event, a second cron run or a crash halfway cannot double anything.
- **A plain list before charts.** The finish line asks whether a listen arrives correctly, so 0.10.0 shows recent visits as rows; the dashboard (0.11.0) reads the daily tables.
- **Plays deduplicated per site-local day.** A rolling 24 hour window would need an id that outlives the day, which the salt rotation exists to prevent.

---

## 9f. The dashboard (0.11.0)

**Problem:** 0.9.0 and 0.10.0 collect takes and listens, but the only place to see them was a list of recent visits.

**Decisions:**
- **Summaries, never raw events.** Screens read the daily tables the hourly rollup builds, plus the indexed visits table for listener, device and country counts. With a year of made-up data every screen loaded in well under a second locally. The rollup runs for today when the dashboard opens, at most every five minutes, so a new listen appears without waiting for the hour.
- **Traceable numbers.** Raw events now keep everything needed to rebuild a visit (visible time, the end flag), and a test rebuilds each visit from its events and compares. The visits and events download as CSV from the Overview.
- **Honest small numbers.** Every percentage carries its count. Period-over-period changes and drop-off suggestions wait for 30 plays. Ten listens is an anecdote.
- **Our own SVG charts.** A library would mean loading code from outside or bundling a large file for a handful of line charts. The charts follow one set of rules: one axis, 2px lines, a legend for two or more series, a crosshair tooltip with every series, a table view, the categorical colour order checked with a colour blindness validator (worst adjacent pair ΔE 9.1). Long periods average by week.
- **Drop-offs by comparison with the reading itself.** A fall counts when it is at least 5 points and two and a half times the reading's typical fall, leaving out the last 10 seconds. That finds the sentence that loses people, whatever the reading's overall shape.
- **Skips from the gaps.** A stretch counts as jumped over for a listener who heard something before and after it but not it. The rollup adds this per stretch, so it needs no raw events.
- **Goals with a direction.** A goal is a minimum, a maximum, or both, on a number the take already has, so checking it is exact and works on past takes too. They show as bands on the trend charts and as a tile in the review studio. No streaks or badges.
- **Practice log from the takes.** A day's line is worked out from that day's takes; only the note is stored.
- **Change markers.** Before and after comparisons are only honest when the change is written down. Markers belong to the site or to one reading and show on every chart that covers their date.

---

## 9g. One reading from several takes (1.3.0)

**Problem:** a reader often makes two or three takes of a post, each with different slips. Retakes fix one line at a time; there was no way to keep the good opening of one take and the good ending of another.

**Decisions:**
- **Takes go on the shelf by themselves.** Asking the reader to mark a take "keep for combining" would lose the good take on the day it was forgotten. The shelf keeps the newest six a post for 30 days, in the reader's own browser, and the same take opened twice (a take reviewed, then its Original opened) is kept once by its fingerprint. Phones are left out: combining needs memory a phone page does not get, and a phone take reaches the shelf through Send to my computer.
- **The recording Whisper heard, before cleanup.** Whisper's word times are on that recording's timeline (cleanup keeps the length, and the MP3 step's trim is reported as `start`), so the joins can be found from the words, and the combined reading is cleaned, leveled and mastered once, like any recording, so it sounds like one reading.
- **Whisper does not listen again.** Each take's own words are moved to where its slices land. A slice keeps a word when the middle of the word falls inside it, and the slices end and start in pauses, so no word is lost or doubled. The review, captions and chapters then come from those words.
- **Joins in the pause before a sentence, with the later take's own pause.** A pause is part of how the next sentence is said (a breath, a beat before an important line), so the later take's pause before its word is kept, and the earlier take stops 20 ms after its voice. When the later take started right at the word, its pause is unknown: the earlier take's pause after its last word is used, and the room's background from the later take fills the gap. 30 ms crossfades sit in quiet on both sides, so they can be longer than a cut's 12 ms.
- **Leveled to the take that reads the most.** Each take's speech level (the average power of its 20 ms blocks 15 dB or more above its background) is measured once when it goes on the shelf; the others are raised or lowered to match, by 12 dB at most.
- **Best of each counts flags by weight, and joins as cost.** Skipped and misread words weigh 3, a paraphrased sentence 2, a repeat 1.5, fillers, added words, long pauses and pace or delivery flags 1, worth a listen and flat 0.5. A join costs 0.75, so the reading only switches takes for a real flag fewer; a dynamic programme finds the cheapest choice over the whole post. Ties stay in the take already in use, and the reading starts in the take that reads the most.
- **Paragraphs and sentences as the pieces.** Those are where readers pause, so the joins land in pauses. Anything shorter than a sentence is a retake's job.
- **Kept out of the take history.** The combined reading's numbers would describe how it was put together, so it never enters the take history, goals or charts, as with the edited version (1.1.0). Use this recording sends no take with it, so insights do not credit its delivery to one take.

---

## 9h. Review for verse and pace (1.11.0)

**Problem:** the review judged every post as if it were an essay. A ritual reading's circle-casting lines, which are meant to trail off, came back flagged Faded ending, and the Pace line's bands (comfortable from 115 to 165) told a slow chant it was dragging.

**Decisions:**
- **A kind for each post, chosen by the reader.** Four kinds (prose, story, verse, ritual and chant) are enough to move the pace and the fade check without asking the reader to describe their writing. The site suggests Verse for a post of short lines, but never sets a kind itself: a short-lined post can be a list of correspondences read briskly, and a wrong guess would quietly change the review.
- **Ranges in Settings.** Readers differ more than kinds do; the ranges start where listening research and the maintainer's own readings sit, and can be moved.
- **Rushed and Slow move a quarter beyond the range.** For prose that is exactly the 200 and 105 used before, so posts with no kind and prose posts are reviewed the same. The comparison with the reader's own sentence pace stays, because one line out of step with the rest of a reading is worth hearing whatever the kind.
- **A deeper fade threshold in verse, and a cue for the rest.** Turning the check off for verse would miss a swallowed last word, which is the fault the check exists for. At 14 dB a gentle trail passes and a dropped ending is still caught. Where even that is too strict, the Fade meant cue silences one sentence, the way a cued pause silences a Long pause.
- **The pause map counts from a second.** The Long pause list (2 s between sentences, 1.1 s inside) catches faults. The map shows the shape of a reading, so it starts lower and labels each pause by where it falls. A paragraph break is the stanza break in verse. In the script the lengths are behind a switch, since on a long essay they would crowd the text.
- **A missed cue is only a note.** Running past a cued pause can be the better reading on the day; it is shown so the reader can decide, and it does not count against the take.
- **The pace on a post with a kind is that post's.** Writing it into the pace saved for every post would leave a prose post starting at a chant's pace after a ritual reading.

---

## 9i. Phone take mode (1.12.0)

**Problem:** a phone take lived only in the phone's browser until it was finished, then the phone made the MP3 (minutes of work for a long reading, on the device least able to do it) before Send to my computer could carry it to the review. A call, a reload, a full phone or a closed page in that time left the take stranded, and the 13-minute readings that matter most were the ones most at risk.

**Decisions:**
- **Pieces of ten seconds, sent while recording.** Short enough that an interruption costs only seconds, long enough that a 13-minute take is about 80 requests rather than thousands. At 1.4 MB a piece fits every host's upload limit.
- **The site's file is a WAV from the first byte.** Writing the header at the start and its two sizes after each piece means there is no assembly step that could be missed: whatever the site holds at any moment opens like any other recording. The sizes are rewritten under a file lock, so a retried piece arriving beside the next one cannot leave them wrong.
- **Each piece says where it belongs.** A piece whose answer was lost is sent again and kept once; one that arrives early is refused with how much the site holds. The phone never guesses what the site has.
- **The browser keeps its copy until the site has everything.** The once-a-second save from 0.4.0 stays exactly as it was, so phone take mode adds a second place for the take and takes nothing away. The next visit compares the two and sends only the difference.
- **Nothing more on the phone after Stop.** The maintainer chose this: the review, editing and Use this recording all happen on the computer, so making the MP3 on the phone was work done twice. Make the MP3 here stays for the day a file is wanted on the phone itself.
- **Carry on rather than start over.** A take cut off at minute nine is nine good minutes. Starting the next take a sentence before where the prompter was gives Combine takes a clean overlap to join in, which is how Continue (1.4.0) already works on the computer.
- **The meter's words follow the lift, not a fixed level.** A phone's voice through the browser sits near -54 LUFS whatever the room, so a fixed "too quiet" line would always be lit. The meter works out the lift a take at this level would need and the most this room allows (as Check microphone does, with the same `liftLimit`), so the word changes when the take would really fall short. Its 6-second window is long enough not to flicker over a reader's natural swell and short enough to catch a phone that has drifted away. It reads the samples as numbers, since the byte reading behind the small meter cannot see a voice at -50 dBFS.
- **Always sent, with a data line.** An iPhone page cannot tell Wi-Fi from mobile data, so the choice is left to the reader, with the megabytes in view and the switch one tap away.
- **On by default, an exception to the first guiding rule.** It keeps only the signed-in reader's own audio, in the folder, list and limits Send to my computer already uses, and that reader switched recording on; visitors are untouched. The maintainer agreed on 17 November 2026, and PRIVACY.md says so.
- **Opening drills stay on the phone.** They are thirty seconds of practice, and five waiting takes would soon fill with them.

## 9j. Voice polish (1.13.0)

**Problem:** the steps of Broadcast sound (0.5.0) had one strength each, chosen for a laptop in a quiet room. A voice and setup analysis of real readings found the steps doing the right things, but no way to hear what they did or to ask for more or less, a level that sagged over a 14-minute reading, a phone and a laptop that sound different, and small clicks in the quiet between words that cleanup (which looks before phrases) did not reach.

**Decisions:**
- **Standard is today's sound, exactly.** Every strength is a table of the same numbers the code always used, so nothing changes on any site until a setting moves, and a unit test compares Standard with true and with nothing set, sample for sample. Off is the old switch's off.
- **Hear one sentence.** A sentence with 8 s either side is enough for the steps (the compressor's threshold and the speech map come from what is around it) and quick to work out on a laptop. Before is the same sentence as recorded at the same loudness, so the ear judges the polish alone. Two loops started together switch at the same moment.
- **A one-off for this take only,** like the one-off Finish for menu, but it does go on the post: Use for this take makes the take's MP3 again, so Download, Use this recording, clips and Share for feedback all agree. Settings stays the reader's standing choice.
- **Slow leveling over a minute.** A voice that tires drops a few decibels over minutes; compression cannot see that, and a fast leveller would flatten the reading. A minute's window moved every 5 s follows the drift and leaves stanzas and phrases alone; 4 dB at most (2 in verse and ritual, where loud and soft are meant) means it can never remake a reading. On by default: on a steady reading it does nothing.
- **The energy note says it once and gently.** It compares the voice with the reader's own first minute, says it at most once a minute and goes away, and stays out of opening drills and ritual, where a hush is meant.
- **The EQ is learned, small and per setup.** Corrections come from the reader's own first takes on that device and microphone, half the way towards the long-term shape of speech, 3 dB at most, centred, so it can only ever be a gentle tilt. It is saved on the site because the phone records and the laptop reviews. Off by default until the reader has tried it in Hear the polish.
- **Clicks from Whisper's word times, lowered, never cut.** Only the quiet inside a gap, 50 ms in from each word, under 15 ms and 20 dB under the reading's loud speech, so a real k or t at a word's edge is never touched, even when Whisper places the edge a little late; a gap busy with bursts is left alone, since it holds sound Whisper did not hear as a word. Because the word times come with the review, the MP3 is made again after it. Off by default.

## 9k. The pronunciation list (1.15.0)

**Problem:** pronunciation notes and taught words live in five places (the reader's own notes and taught words, each post's notes and taught words, and notes on one word inside the cue marks) and could only be seen one post at a time, in the studio. Designing a screen for them showed two older faults: a taught word forgotten in one browser came back from another browser's copy, and a studio tab opened earlier saved its whole list of notes or cue marks over newer changes.

**Decisions:**
- **One row per word per place.** A word's note and its taught words share a row when they are kept in the same place, since that is how the reader thinks of them ("Brigid: say BRIDJ-id, Whisper writes bridget"). Where it is kept is a column, not a grouping, so search and sort work across all of them.
- **Moving takes everything with it.** A word moved from every post to one post takes its note and its taught words; when both places already have different notes, nothing happens until the reader picks one, because either could be the newer thought.
- **Forgetting needs a time, not a list.** Two copies of a set cannot tell "forgotten here" from "never taught there". Each word carries when it was taught and when it was forgotten, and the later wins, the same in the browser and on the site, so the merge gives one answer whichever copy arrives first. Words from before 1.15.0 have no time and count as taught long ago, so a forgotten record always beats them. Forgotten records are let go after 400 days, by which time every browser that held an old copy has synced.
- **The studio sends changes, not lists.** It keeps the site's list as it last saw it and sends only the difference, so a tab opened before a change on the screen leaves that change alone. The site still accepts a whole list from an older tab.
- **Suggestions from what How hard already counts.** Names and long words with no note, posts still to read first: the moment a note helps most is before the first reading. Not needed is kept per reader, so the list can be cleared without adding notes nobody needs.
- **Import only adds.** A backup brought back shows what it would do first, and a different note is kept unless the reader picks the file's, so importing an old backup can never quietly undo newer work.

## 9l. The stronger cleanup (1.16.0)

**Problem:** RNNoise (0.6.0) lowers broad noise well but leaves sounds with a shape: a ringing bell, a creaking chair, mouth sounds. Phone takes in ordinary rooms brought exactly these. Cloud enhancers rebuild the voice, which the plugin will not do.

**Decisions:**
- **DeepFilterNet 3 beside RNNoise, not in place of it.** Free licence, 8.6 MB, and in the research it lowered a bell 5 to 6 dB further than RNNoise with the words reading back more truly. It costs about twice RNNoise's time, so Standard stays the default and Stronger is a choice.
- **Shipped with the plugin.** The official model is published only inside an archive; unpacking archives on shared hosts is fragile, and shipping it means no outside request for the model and nothing to download before it works. The runtime is shared with Whisper, so only its 50 KB loader is new.
- **Everything checked before use.** The model, the loader's runtime glue and the WebAssembly are hashed in the worker and refused on any mismatch, the same as RNNoise and the MP3 encoder.
- **Lift first.** The model reads absolute level, and a raw phone take at about -47 LUFS lost its opening seconds to it. The engine lifts the reading so its loud end is near -20 dBFS and puts the level back, so the rest of the chain sees the same levels as before.
- **Chunks, not one pass.** Ten-second chunks with two seconds of warm-up and the running norms carried across give the same result as one pass, with memory that does not grow with the reading, which matters on a phone.
- **Fall back, and say so.** A device that needs more than 0.6 of the audio's length for the first chunk, or any load failure, hands the take to RNNoise, and the Room line names the reason. A take never goes without noise reduction because the stronger one could not run.
- **Suggest it only when it is likely to help.** On Standard, a room under 35 dB below the voice, or two or more steady tones in the pauses (what a bell or hum leaves and broad noise does not), brings a one-line suggestion. Hear the polish lets the reader hear the difference on one sentence before choosing.
- **Strong is the amount for it.** The cap mixes the original back in. With RNNoise that protects word endings; with DeepFilterNet the blind listening found a 20 dB cap letting mouth sounds back, and no cap leaving the voice unharmed.

## 9zf. Fuller help (1.36.0)

**Problem:** 1.32.0's guides were accurate but short: about 3,900 words for 35 milestones, one line for most features, nothing for the largest screen (Settings), and two screens (Settings, Recordings) whose Help link opened a guide about something else. A reader could learn that a feature existed and not how to use it.

**Decisions** (agreed 22 November 2026, every recommendation):
- **One milestone,** so the grouping, Start here and the screen links arrive with the guides they point at, and one pull request runs the checks once.
- **Two readers, two paths.** The person who looks after the site and the person who reads aloud need different first guides; Start here gives each an ordered list, and a short list of the words every guide uses.
- **Groups in the order of the work:** getting started, recording, finishing and publishing, video, podcast, numbers, the site's setup, trouble. Search keeps working across all of them and hides empty groups.
- **Parts inside guides,** each with an anchor, so a screen (or a section of one) opens the exact part that fits it, and a long guide can still be read as one page.
- **Help with this section, from the screens' own headings.** The list matches each heading's translated string, so it follows a translation, and a test reads the real screens so a heading added later without a guide fails the build.
- **Written from the screens.** Every guide was written beside the test site's screens and the studio's own labels, and the names they use are checked against the plugin's strings; where a guide describes behaviour (captions use the post's words, uninstall's two lists, who can do what), it was read from the code.
- **No pictures yet.** Screenshots belong with the public docs site and the short videos; in the plugin they would grow the zip and go stale with every look change. The guides keep naming where each control lives.

---

## 9ze. YouTube and SoundCloud players that load on play (1.35.0)

**Problem:** a YouTube or SoundCloud player in a post loads all of its files, about half a megabyte for YouTube's, and sets its cookies as soon as a visitor scrolls near it, whether or not anyone presses play. WordPress's own lazy loading (and a page optimizer's) only waits for the scroll; it never saves the load, and it never keeps a visitor's browser from contacting the service.

**Decisions** (agreed 22 November 2026, every recommendation):
- **Load on play, a panel in the player's place.** A picture with a play button is the pattern Google recommends for YouTube embeds. The panel takes the player's exact box (16 by 9, 9 by 16 for a Short, SoundCloud's own heights), and the player is put inside that same box, so nothing on the page moves when it arrives.
- **One switch for each service, both off.** SoundCloud's player is light and some sites may want it as it is; off by default means an update changes nothing anyone sees.
- **Made as the page is shown, never written into posts.** The filter runs on the finished content (after embeds, blocks and shortcodes) and on block widgets, so every way of adding a player is covered by one rule, and switching off brings the ordinary players straight back. The editor, feeds and the REST API always get the original: the editor shows what was placed, and feeds and other sites should not get a panel that needs this site's script. Players added by themes or other plugins outside the content are left alone, since their makers chose how they load.
- **The picture is the site's own.** A picture fetched from YouTube when the page opens would tell YouTube about the visit and set no fewer connections. The site asks each service's oEmbed address once, as WordPress does for embed code, keeps a small copy in uploads (WebP where the server can make one), and serves that. A few look-ups at most a page view, a day before a failed one is tried again, and saving a post fetches its pictures before anyone visits. A picture not shown for 90 days goes, and Clear saved pictures empties the folder; both come back by themselves.
- **One press plays.** The real player is loaded started (`autoplay=1`, `auto_play=true`) with the settings of the original that still apply (start time, playlist, captions, colour). YouTube's comes from its privacy-enhanced address, and the player keeps the `strict-origin-when-cross-origin` referrer YouTube now needs (Error 153 otherwise), set on the frame itself so a site's stricter policy cannot break it. Safari on an iPhone may still wait for a second tap; that is Safari's rule for sound from another site, so the panel says nothing different.
- **A real button that says what it plays.** Without JavaScript the panel is a plain link to the video or track. With it, the link becomes a button whose name is its own words, "Play" and the title first (axe-core's check that what is seen is what is said, WCAG 2.5.3, ruled out a separate label). Focus moves into the player once it loads.
- **One thing plays at a time, through the players' own messages.** Both services' players accept pause messages from their page and report when they play; embeds.js uses those and the reading player's `rrt:player` event, so no script from either service is loaded before play, and nothing in player.js changes.
- **Only two addresses are ever loaded.** The server builds the player address from a checked id, and embeds.js loads nothing that does not start with the two players' own addresses.

---

## 9zd. The take shelf across computers (1.34.0)

**Problem:** the take shelf (1.3.0) lived only in the browser that reviewed each take, so Combine takes and Continue worked only where every take had been read. A take reviewed in another browser on the same computer, on another computer or on a phone was invisible, and clearing a browser's site data lost its shelf for good.

**Decisions** (agreed 22 November 2026, every recommendation):
- **Off by default, one switch for the site.** It keeps recordings of voices on the server, so an administrator turns it on knowingly; once on, it works for everyone who can use the studio, with nothing for each of them to set.
- **Each person's shelf is their own,** listed in their user meta like sent takes, and served only to them. An administrator sees the space used and can delete every shelf, but cannot open anyone's takes.
- **What goes up is what the browser keeps:** the recording as Whisper heard it, its words and flags, its fingerprint and level, and the plan. Every computer works from the same samples, so Combine takes gives the same reading anywhere.
- **FLAC, written by the plugin.** Plain WAV would double the space; a lossy format would make a combined reading a second generation of loss. Fixed prediction with partitioned Rice codes is the part of FLAC that is simple, quick and gives most of its gain on a voice (58% of WAV on a made-up 14-minute reading, against 55% for ffmpeg's encoder with LPC), with no outside library. The decoder is the plugin's own too, so no browser's decoding (or resampling) can change a sample, and a check of the samples kept with the take refuses a damaged copy. The same samples always give the same bytes, so an interrupted send carries on from where the site stopped.
- **In pieces, in the background.** The site's upload limit never matters, the review is never held up, and a send cut off by a closed page goes on the next time the post is opened in that browser. A send not heard from for two days is given up on the site.
- **The list at once, the recordings when needed.** Continue and the Combine takes count need only the words, so a computer that has never seen a take lists it the moment the studio opens; its recording is fetched only when Combine takes needs it there, once.
- **The site numbers the takes,** so Take 3 is Take 3 on every computer, and applies the same six-a-post, 30-day rules from when the take was recorded, so the site and each browser agree on what is kept. The same take reviewed in two browsers is kept once, by the fingerprint the shelf already used.
- **Phones send, and never fetch.** A phone's short takes become usable on a computer, and Continue on the phone knows what a computer read; Combine takes stays a computer tool, so a phone never downloads recordings.
- **The practice reading stays in the browser,** since its takes are for trying things out.
- **A space limit, and a refusal that keeps the take.** When the site's space is used up, the take stays in its browser and the studio says so; nothing is lost and nothing else stops working.
- **Removing is everywhere.** Remove and Clear the shelf (and Delete on Recordings) remove the take on the site, and other computers drop it the next time they open the post; their confirmations say so. A plan's later save wins, which is the only way two computers can disagree.

---

## 9zc. Other languages (1.33.0)

**Problem:** both Whisper models heard English only, and every step after them assumed English: word matching kept Latin letters alone, numbers and fillers were English words, the pace bands were English figures, How hard counted English syllables and took German nouns for names, and voice following's model is English.

**Decisions** (agreed 21 November 2026, every recommendation):
- **Languages with spaces only, for now.** The review lines words up, and Chinese, Japanese, Thai, Lao, Khmer, Burmese and Tibetan are written without spaces; doing them well needs a segmenter and character-level matching, a milestone of its own. The box names them so nobody wonders where they went.
- **The site's language by default, a post's own when chosen.** An English site that never touches the menu gets no change at all, which every check confirms.
- **English keeps the English models.** The `.en` models are more accurate in English than the multilingual ones of the same size. Other languages use the multilingual models with the language set and the task fixed to "transcribe": left to itself, Whisper can guess the language wrongly or translate into English, and either would make a review nonsense.
- **Pinned on Hugging Face, and a separate download.** The multilingual models are fetched by revision even before they are downloaded to the site (the English standard model never was), and there is no unpinned fallback for them. The download is its own button, because an English-only site should not spend 650 MB of hosting on it.
- **Every rule that is really an English rule stays English.** Sound-alikes, contractions, number words and word endings are switched off elsewhere, never translated by guesswork. In their place two soft rules: a number heard as digits against one in words is Check, and two words sharing most of their start are Check, so a different ending is worth a listen and never a miss.
- **Fillers only where they cannot be words.** Each language's list holds sounds that are never words in it ("euh", "äh", "ээ"), plus the hums every language shares. A language without a list gets the hums alone, which can miss a filler and can never call a word one.
- **Pace against the reader's own.** There is no good table of comfortable listening paces per language, and languages differ by a third in words a minute. So the number stands alone until three reviews in the language, then is judged against the reader's own average. Kinds of reading keep their ranges, which the site owner sets.
- **Follow my voice English only, and kept.** Its model is English; on another language it shows off with the reason, and the reader's choice stays for English posts.
- **Taught words per language,** so teaching Whisper a French word never changes an English review. Pronunciation notes are already per post.
- **Word keys unchanged in English.** `rrt_note_key()` keeps other scripts' letters only for a word that has them, so no English note or taught word changes its key.
- **The end of the script waits for Finish when the voice is not followed.** The live check of a Spanish reading showed the old rule's trap: scrolling at the pace, the script reached its last word while the reader was still in the middle of the paragraph, and the recording ended two seconds later. With Follow my voice always off outside English, every slower reader would lose the end of their take. Only the reader knows when they have said the last word, so the recording keeps going and the main button becomes Finish. Following the voice, the follower already knows, so that path is unchanged.
- **An accent is spelling, not a misread.** In the same check Whisper wrote "rio" for the last word "río". Long words already pass with one letter changed, so treating an accent-only difference as the same word gives short words the same allowance; the alternative, a Check on every dropped accent, would flag Whisper's spelling rather than the reader. Only combining accents on Latin, Greek and Cyrillic letters are set aside, because other scripts' vowel signs are letters.
- **At the end, the reader sees what is left.** When the script waits for Finish it is ahead of the reader by definition, so the view moves back to the last paragraph rather than keeping the reading line where it was; on a phone the line sits near the top and the words still to read had gone off the screen.
- **A phone page never pans.** Safari pans the whole page as far as its widest piece, which feels like the page is loose. Tables are the plugin's widest pieces, so on narrow screens each scrolls in its own box, which keeps every column and leaves the page still. Scripts carry their file's time for the same phones: Safari keeps a script for a year at an unchanged address.

---

## 9zb. Help and docs (1.32.0)

**Problem:** the plugin's help explained single controls, and its docs were written for developers. A new reader had nowhere to ask "how do I do this?", and README's browser table had fallen behind.

**Decisions** (agreed 21 November 2026, every recommendation):
- **Short task guides inside the plugin,** each saying where a control lives (screen, section, control), so they work offline, follow the plugin's version and can be translated like everything else.
- **The words written once.** The guides live in `help-text.php` with every line translatable; a tool writes them into `docs/guide/` and a test fails when the two differ. Markdown read by the plugin would have left the help untranslatable.
- **Help where people are:** a Help link beside each screen's heading (visible, unlike the Help tab, which many never open) and the Help tab too; in the studio a link in a new tab, so an open take is never at risk. No guided tour: New here and the setup wizard cover a first visit.
- **A search that never leaves the page,** and What's new at the top of the Help screen in place of a notice after updates (the directory's guidelines discourage dashboard nags).
- **A guard on the names.** A guide that names a renamed button is worse than no guide, so the PHP test checks that every control a guide names still exists under that name in the code.
- **The docs site and videos wait for going public.** A site now would be a public page for a private project, or cost money to host; videos made now would need redoing as screens change. Both go with the clean repository's launch, the videos silent and captioned, made from the test site, and kept out of the zip.

---

## 9za. Every phone (1.31.0)

**Problem:** a guess from the browser's name decided everything a phone could do, wrongly for big iPads and Android phones in Firefox; every phone review waited to be asked; and the timed pace never said it waits for nobody.

**Decisions** (agreed 19 November 2026 in outline and 20 November in detail, every recommendation):
- **Touch-first is a phone, in any browser,** whatever memory it reports: an 8 GB Android phone still closes a page under load, and a touch laptop used with a mouse is a computer. Small memory (a hint under 4 GB, or no hint on a touch device) only picks Whisper's lighter engine build.
- **The Model menu everywhere,** with Automatic meaning the standard model on a phone and saying so. Larger is the reader's own risk on a phone, and a page that closed puts it back to Automatic.
- **Five minutes reviews by itself.** A 1:53 take finished on the iPhone with room to spare. Longer takes still ask first, because the take is safe before Whisper starts and a closed page costs only the wait.
- **Let go of the untrimmed copy, keep the cleaned one,** on a phone and only for takes too long for the shelf. The untrimmed copy serves Whisper, the shelf and Hear the polish's Cleanup switch (which cleans it again); the cleaned copy is what editing works on, so it stays. A long phone take goes without the Cleanup switch, which is a computer's job anyway. A phone take mode take already waits on the site for a computer, so it never starts Whisper on the phone by itself.
- **The shelf on phones for short takes only.** Continue needs it; Combine needs every take in memory at once, so it stays a computer tool, and the shelf line says so.
- **A line, not a default.** Follow my voice would load about 45 MB on every new phone, so the pace line explains the timed pace and New here offers the tick.
- **No player for the full video on a phone.** Safari would not play a 76 MB video inside the page; the file was fine. Clips are a few MB and previewed well.
- **One pace number in the review.** The read-out's quick estimate divides every script word by the whole take; the review's divides the words heard by the time from first to last. Once the review is in, the review's number is the one shown.

---

## 9z. Every browser (1.30.0)

**Problem:** the research of 19 November 2026 (docs/RESEARCH.md, Every browser and phone) found Firefox's clips and videos losing frames (fixed in 1.29.1 by falling back to Baseline), Firefox with no AAC encoder on any system, messages that named Chrome and Edge, and Automatic choosing a graphics chip that was slower than the take.

**Decisions** (agreed 19 November 2026, the proposal's twelve choices as recommended; the phone half is 1.31.0):
- **Mediabunny with the AAC add-on,** in place of mp4-muxer. Mediabunny stores frames that arrive out of display order, so every browser keeps High profile, and its AAC add-on gives Firefox and Chrome on Linux AAC sound. Giving mp4-muxer decoding times by hand also kept every frame, but started the picture 67 ms late, over Spotify's 50 ms.
- **The page keeps its own video encoder.** Its settings (the graphics chip first) and its pacing on the encoder's queue were learned the hard way (1.18.0); only the writing moved to Mediabunny. The sound goes to Mediabunny as samples, because the add-on works through Mediabunny's own encoding.
- **The add-on only where it is needed.** Chrome, Edge and Safari keep their own AAC encoder, on the graphics chip where they have one; the add-on (about 1.1 MB) loads only when the browser says it has no AAC.
- **No edit list.** Spotify's and Instagram's specs refuse edit lists. Handing the samples over by the encoder's own start-up delay (found once a page by encoding silence) puts the first piece at 0, so none is written, and the sound is at most about 21 ms behind the picture, inside Spotify's 50 ms. That avoids a real upload test for each release. The picture gets the same care: Firefox's encoder on Windows hands every piece back one frame late, so the writer moves the pieces back to start at 0 rather than let Mediabunny add an edit list (found in the live check, before release).
- **Browsers are asked for tools, never names.** Safari on iOS 26 reports a frozen older version, and a name in a message goes stale. One shared check (can.js) decides, and its messages name the missing tool and the browsers that have it.
- **Automatic is judged by speed.** The second piece is timed (the first also warms the chip up), and a chip slower than the take hands the rest to the standard model and is remembered per browser. Naming Firefox would be wrong twice over: Firefox on a strong chip may be fast, and another browser on a weak one may not.
- **The practice reading records,** for its own page only: a practice with nothing recorded ends with no review and no sign why, and the browser's own choice is still the reader's.
- **Firefox tested locally only.** A Firefox job on GitHub would cost about ten more Actions minutes a run and its setup there is untried; `tests/run.sh firefox` runs before a release that touches clips, videos or the vendor files.
- **A tiny loss is allowed, a large one never.** Safari on iPhone stopped a full video for one frame of 7,505 (found in the live check): either its flush finished before the last piece arrived or its encoder skipped a frame. The writer waits up to 2 s after the flush, then accepts at most 1 frame in 1,000 when the file starts with its key frame, because a frame held a little longer is invisible while a refused 4-minute video is lost work; the card says so. Firefox's old loss of two thirds is still refused.

---

## 9m. The faster review (1.17.0)

**Problem:** the larger Whisper model hears names and quiet endings better, but on a laptop's processor it took nearly twice a reading's length (about 27 minutes for 14), so it was almost never used, and the standard model's review of a long reading still took minutes.

**Decisions:**
- **The whole larger model on the graphics chip.** Measured on a real laptop: the half-precision encoder with the 4-bit half-precision decoder took 0.35 of the audio's length on a GeForce GTX 1650 and 0.57 on the Radeon graphics inside the processor. Splitting the decoder onto the processor was a little faster on the weaker chip (0.53) and much slower on the stronger one, so one setup serves both.
- **Automatic is the default,** because the larger model is both more accurate and fast enough there. Where there is no usable graphics chip, Automatic is the standard model, which is what every reader had before. Opening drills keep the standard model: they are short practice, and loading the larger model would cost more than it saves.
- **The old saved default does not count as a choice.** Every browser saved `'base'` because it was the default, so only a saved Larger is kept; a marker (`wm2`) tells choices made since 1.17.0 apart.
- **No software graphics chip, no half precision.** A fallback adapter would be slower than the processor, and without shader-f16 the files would not run.
- **Fall back to what the reader had.** A graphics chip that fails is not tried again on that page, and the review runs on the processor as before: Larger stays larger, Automatic becomes Standard, since the larger model on the processor would take too long. The result line says what happened.
- **The standard model stays on the processor.** It gained only 1.4 times on the graphics chip, about 2 minutes on a 14-minute reading, for two more files.
- **Files pinned like the rest.** The two graphics chip files join the larger model's Download with Hugging Face's fingerprints at the same revision. A site that already has the seven processor files keeps serving them while the graphics chip loads from Hugging Face, and the Download fetches only what is missing.
- **Skipping silences was left out:** pauses of a second or more were 13.5% of the reading, and the review already cuts at pauses, so it would save at most about a tenth.

## 9n. Full-length video (1.18.0)

**Problem:** YouTube and Spotify show podcasts as video, and a reading has nowhere to go there but a still picture made from the show art.

**Decisions:**
- **Made in the browser, like clips.** The clip maker already draws the picture and makes MP4s with WebCodecs; a 14:13 reading took 2:15 on a laptop, on the graphics chip's encoder or the processor's alike, so no server work and no outside service is needed.
- **Downloaded, never kept on the site.** Both YouTube and Spotify take video only by upload, and keeping a video on the site would add hundreds of MB a reading with nothing yet to use it. The video goes into the browser's private storage as it is made (so it never sits in memory) and downloads from there.
- **The feed's video waits.** Apple Podcasts ignores `podcast:alternateEnclosure`, the apps that read it want HLS, and a feed that is not published cannot be checked in any of them. It becomes its own milestone once a site's feed is live.
- **A phrase at a time.** Clips light each spoken word from Whisper's timings, which a published reading does not keep; its captions are phrases, so the video shows a phrase at a time, at most two lines.
- **5 Mbps by default.** At 2.5 Mbps a soft dark background showed faint steps when brightened; YouTube and Spotify re-encode what they are given, so a cleaner source survives their pass better. Smaller file keeps 2.5 for slow uploads.
- **One wide size.** YouTube, Spotify and Apple show video at 16:9; Shorts and Reels cap length, and clips already cover them.
- **No timers in the loop.** Chrome slows a page's timers to about once a second when its window is not in front; the loop waits on the encoder and yields by message, so it keeps full speed behind other windows.
- **Chapters only when YouTube would take them.** YouTube needs the first at 0:00, three or more, each 10 s or longer; otherwise the times would be ignored or rejected, so the description leaves them out and says why.

## 9o. Collections (1.19.0)

**Problem:** a series or a themed set of readings is easier to share and listen to as one file, and an audiobook needs a set of readings in order, but joining audio usually means decoding and encoding it again.

**Decisions:**
- **Joined on the site by copying frames.** Every MP3 the plugin makes is constant bitrate (mp3.js), and an MPEG-1 Layer III frame starts with no reference to the frame before it at a file's start, so the frames of several files can follow one another. A check in the workspace joined three LAME files this way and every reading decoded sample for sample as on its own. Nothing is encoded again, the join is plain file copying that a shared host does in a second or two, and the collection can join itself again whenever a reading changes, with no one at the browser.
- **Silence made of frames.** A frame whose side information is all zero has no sound data, so it decodes to zero; the padding bit is set as LAME sets it, so the file's byte rate stays exact and players work out its length and seek correctly without a Xing header.
- **One format a collection.** Players take a constant-bitrate file's length from its first frame, so a collection keeps one bitrate, rate and channel count: the one most of its readings are in, and Finish for's on a tie, since that is the format new readings are made in and an older reading is the one that should be matched. A reading in another format is matched in the browser, and only that one goes through MP3 again.
- **Loudness compared as one channel.** BS.1770 counts both channels, so the same voice in a stereo file measures about 3 dB louder than in a mono one; levels are compared as the voice would measure in one channel, so a mono and a stereo reading at the same playing level are not flagged. A reading more than 1 LU away is pointed out and can still be joined: being a little off is the reader's call.
- **Measured in the browser, once.** PHP cannot decode MP3; the box decodes each reading with the browser's own decoder and measures it with sound.js, the same loudness the finishing presets use, and the site keeps the number with that file.
- **Never dropped quietly.** When a reading is taken off its post or unpublished, joining again by itself would change the episode a lot, so the collection keeps what it has until it is saved, and says which reading is missing.
- **A post type, classic screen.** Collections need a page, an address, drafts, scheduling and a featured image, which a post type gives for free; the box saves with the page on the classic screen, so its last join shows after every save without the block editor's separate meta box request.
- **The chapters in the tag, the pictures in the chapters file.** A picture in every chapter frame would push the chapters past the first 64 KB the player reads, so chapter pictures go only in the Podcasting 2.0 chapters file.
- **A Bonus episode.** Apple and others label a bonus episode as extra to the main ones, which suits readings already in the feed on their own; Full is a choice for a set new to the feed.

## 9p. Audiobook export (1.20.0)

**Problem:** the audiobook stores want a book as separate files (credits, one chapter each, a sample), each with the room heard at both ends, at a set level, at 192 kbps. A podcast reading misses those rules in small ways, and one with the music bed misses them completely.

**Decisions:**
- **The chapter comes from the take.** Finishing the take once more with the Audiobook (ACX) preset gives the best file: one round of MP3, the audiobook level, and the voice alone. So Use this recording can keep that copy as well (a switch, off by default, since it takes about 1.4 MB a minute), and the review can keep one for a reading already on its post without changing the post.
- **The post's MP3 when there is no take.** A plain reading decoded, leveled and encoded again at 192 kbps passes the rules (a real 4-minute reading, measured: its background stays near -66 dB at the audiobook level); it costs one more round of MP3, and the box says so. A reading with the music bed cannot be used: the music fills every pause, so the room is never heard (a real 14-minute reading, measured: the quietest half second -57 dB, all music).
- **Room tone made from the reading's own pauses.** Every take is trimmed for podcasts before it is finished (a quarter second before, 0.6 s after). The middles of the reading's quietest pauses are the room as it really was, after the same cleanup as the voice; only pauses within 6 dB of the quietest are used, so a pause holding a breath or a house sound is left out. ACX's current page asks for no more than 5 s and recommends 1 to 5 at each end (the older 0.5 to 1 s at the start is retired); 1.2 s before and 3 s after sit inside every store's range with room to spare for how a checker finds the first word.
- **Made in the browser, kept nowhere.** As with full-length video, the ZIP is made where the decoder and encoder already are and downloads; only the copies and the credits, which are the reader's own recordings, stay on the site, in a folder visitors cannot open.
- **Credits are readings.** Recording them in the studio gives them the same cleanup, level and room as the chapters, which ACX's consistency rule asks for. A post type of their own, a child of the collection, keeps them out of every list and lets the studio open them like any post. The studio reads a post's title first, so a credit's first line is its title.
- **Checked as delivered.** Every file is decoded again and measured with the same audiobook check the studio uses, plus the true peak, the length, mono and the heading. A file that misses a rule still goes in the ZIP, with the reason in the report; a reading that needs a copy is left out and named.
- **Plain names and a stored ZIP.** ACX asks for plain letters and numbers in file names; MP3 and JPEG do not compress, so the ZIP stores them as they are and is written in a few lines with no library.

## 9q. Member feeds (1.21.0)

**Problem:** some readings are for supporters, members or friends alone, and they should reach them in the podcast app they already use. A podcast feed is public by nature, and so is every file in the Media Library.

**Decisions:**
- **A key in the address, one per member.** It is what Patreon, Supercast, Castos and the rest do, because it works in nearly every app: a username and password on the feed is supported only in part by Pocket Casts and not by Overcast's ping. One leaked address is reset alone. The key is kept plainly in user meta so the member can see their address again on their profile, as Paid Memberships Pro's add-on does; a person who could read the database could read the readings anyway.
- **The files move.** A feed that hides an episode is no use while its MP3 sits at a public address. Moving the file under `uploads/rrt-members/` and changing only `_wp_attached_file` keeps the attachment, its id and its metadata, so collections, audiobooks, videos, clips and the review all find the file as before; WordPress gives a checked address in place of the public one through `wp_get_attachment_url`, so every page that shows the reading shows the checked one. The old relative path is the part after the folder's name, so moving back needs nothing remembered. The folder's `.htaccess` refuses direct requests on Apache and LiteSpeed; on a server that ignores it, the file's address is never shown anywhere, as with readings kept aside.
- **A file another open post uses stays.** Moving it would close that post's reading too.
- **Settle where it matters.** Members first opens on a date, so whatever shows a reading (the player, the public feed, a member's feed) checks that its files are where its access says, and the hourly job does the rest. A reading never appears in the public feed while its file is still refused.
- **Three choices, Everyone by default.** Members only and early access (Members first) are the two ways the hosts use; counting the days from publishing means a draft can be prepared ahead.
- **A collection follows its most closed reading,** since its joined MP3 holds every reading's audio.
- **The member feed works on its own.** It does not wait for the public feed to be published, so a site can have only a private podcast.
- **Nothing personal in the feed.** Some apps read feeds on their own servers, so the feed carries no name or email, and its own `podcast:guid` and block tags keep it out of directories and apart from the public show.
- **Lapsed is not shut out.** Someone no longer a member keeps the readings for everyone and a way back; only a reset or unknown key ends with 410, which tells apps to stop asking.
- **Roles first, plugins when present.** A site without a membership plugin decides by roles and a per-person choice; with one, its own calls answer who is a member and which post they may see, so its rules are followed without being copied. Two filters cover anything else.
- **Watching for sharing is a choice.** It is data about members, so it is off, keeps no IP address (a daily hash, as download counting does) and goes after 30 days. App servers count as networks too, so it only marks an address; it never turns one off.

## 9r. Sponsor slots (1.22.0)

**Problem:** a short message in a podcast episode for a while, older episodes too, and gone again when its time is over, without editing readings by hand, with the message clearly a message, and with nothing about listeners involved.

**Decisions:**
- **Joined on the site by copying frames.** Collections already join MP3s this way, so a message keeps its sound and the reading keeps its exact sound; nothing is encoded on the server, which cannot encode anyway. The price is a copy of each message in each reading's format and loudness, made once in the browser on the message's screen, as collections match a reading.
- **The mid-roll cut needs care.** LAME keeps part of each frame's sound in the spare bytes of the frames before it, in every frame, pauses included. Cutting there and going on after the message makes the first frames read the message's last bytes as sound: a tick 6 to 9 dB above the room in a test. Copying the frames just before the cut again after the message, with their side information zeroed, gives those frames back their bytes while they decode as silence; the reading then decodes exactly as before from about 50 ms on, and in those first moments (where the decoder's overlap meets a muted frame) differs by less than the room. The copied frames last 26 to 78 ms and sit inside the pause, so the cut goes in the middle of a pause of at least 0.6 s. Nothing is encoded again.
- **Pauses found in the browser.** Only the browser can decode a reading; it does so once per file and keeps the list with the post. A pause is judged against the reading's own loudest and quietest within three seconds either side, so a quiet phone take, a noisy room and a take whose level changes partway all work, and long pauses (paragraph ends) are preferred near the middle.
- **Not everywhere.** Short readings take pre-rolls and post-rolls only (Buzzsprout offers mid-rolls only from 23 minutes); ritual and verse are not broken by a message by default; a reading with the music bed has music in every pause, so a cut would stop it.
- **A new file name for every change, the GUID kept.** Apps notice a new enclosure address (Spotify fetches the new audio) and Apple's app remembers a file's length while playing it, so the bytes at an address never change; the old file stays two days for anyone halfway through.
- **The page's player keeps the reading.** Listening numbers, Most replayed, clip suggestions and read along all count seconds in the reading. A message there would shift them all, and the page has its own way to thank a sponsor (an optional line, its link marked sponsored).
- **Disclosure in the message itself.** The law in Canada and the US asks for a spoken endorsement to be disclosed in the audio, near the start, and in writing nearby. The script starts with a suggested line, the recording's captions are checked for it as a reminder, and the episode notes say who sponsored it. The words stay the owner's.
- **Members hear the readings clean by default,** a common perk of membership; a switch puts the messages in their feeds too, which is also how an owner hears a sponsored copy in a real app before the public feed is published.
- **One mid-roll, the order decides.** One message a slot keeps the plan easy to read; the lower Order wins when two want the same place.

## 9s. Storage offload (1.23.0)

**Problem:** podcast apps download whole readings, many at once, and shared hosting is not built for that; but most of the plugin reads its readings from the site's own disk, and a reading for members must never stay reachable at a public address.

**Decisions:**
- **Copies, never moves.** Other offload plugins can delete the site's files once uploaded, and say themselves that this breaks plugins that read them. Collections, sponsored copies, loudness measuring, audiobooks and the feed's sizes all read files on the site, so offload adds copies and every address falls back to the site's own file. Switching off is then instant, and nothing is lost if the storage company goes away.
- **Only the listening files.** The heavy traffic is the readings and their sponsored copies and collections; captions and chapters files ride along. Everything private (members, kept aside, audiobooks, takes, the studio's files) stays, so the storage never holds anything that needs a sign-in.
- **No code library.** Amazon's PHP library is many megabytes; Signature Version 4 is about eighty lines and is checked against AWS's own published example. Bunny Storage's own API is a plain PUT, and works for every storage zone (its S3 API is a preview, only for zones made with it).
- **Named by content.** A key built from the file's SHA-256 can never point at different sound, so no cache can serve a stale reading and nothing needs clearing when a reading changes; a changed file simply gets a new key. Through a CDN the address keeps its path and gains `?v=` and the same code.
- **Small files the storage misnames stay home.** Bunny Storage takes a file's type from its extension and serves `.vtt` as a plain download; browsers and apps go by the type, and captions are tiny, so such a file stays on the site and the check moves on instead of stopping.
- **Checked before use, and watched.** A copy is used only after it answers at the public address with the right size and type, and only while the file on the site is unchanged. One copy is checked every hour; three failures in a row bring every address home, so a lapsed account or a deleted bucket never leaves listeners with errors.
- **Withdrawn at once, replaced gently.** A reading that goes to members, is kept aside or is unpublished loses its copy straight away, and a bunny.net API key clears it from the CDN too; without one, the copies' one-day cache time bounds how long a CDN can keep handing it out. A reading recorded again keeps its old copy two days, because apps may be halfway through it (as sponsored copies do).
- **The short address stays home.** Download counting has to see every request, so the feed's enclosure stays the site's short address and only its redirect goes outside; the IAB counting milestone builds on that.
- **The player, by choice; its captions and Download, never.** A captions track from another site needs CORS on the audio element itself, which would make playback depend on CORS; captions are small, so they stay. A `download` link is ignored for another site's file. The chapters the player reads from the MP3's first bytes do need CORS, so Check this setup reads the test file from the browser too.
- **Sync after the response.** Uploads run after the page has gone to the owner's browser and never on a visitor's request, plus within about twenty seconds an hour, so a slow storage never slows the site; Copy everything now does one file a request with progress for a back catalogue.
- **Keys.** `wp-config.php` constants are the safer place and win over saved keys; a saved secret is never printed back into the page.
- **Uninstall leaves the bucket alone.** Deleting a bucket's contents by accident is worse than leaving copies; a button deletes them on request.


**Why before reader stats:** everything the maintainer wants to know about listeners (where they stop, when they speed up, which socials send people who stay) needs a player the plugin controls. Building it first also gives listeners something better straight away.

**Choices:**
- **Same look as the theme's earlier player.** The theme on the first site to use it already had a pill-shaped player with a gold-ringed play button and a rose-to-gold progress line. The plugin's player copies that shape and takes its colours and fonts from Settings, so it suits any look and replacing the old one is invisible to readers.
- **Preview before anyone else sees it.** The player starts as editors only. Nothing changes for visitors until someone chooses *Everyone*.
- **Works without JavaScript.** It is a real `<audio controls>` element first; the script hides the native controls only once it has taken over.
- **Read along in the post itself.** The W3C's interactive-transcript pattern, placed where the reader already is. The captions file the review studio makes is spelled from the script, so it lines up with the post text almost word for word (98% in testing). The words are only wrapped in spans when read along is first turned on, so the post's markup is untouched for everyone else. When the text and captions differ too much, a transcript list takes over.
- **Gentle scrolling.** Read along follows the reading down the page, but waits four seconds after the listener scrolls on their own.
- **Remembered only in the browser.** Speed and resume position live in the listener's own browser storage and are never sent. The listener analytics (0.10.0, section 9e) sends what listeners do, never these.
- **Written for strict optimisers.** Some caching plugins' optimisers break a double ampersand in scripts and drop CSS rules with slashes, so the player avoids both, and keeps its settings in a data attribute instead of an inline script.
- **Events now, analytics later.** The player announces every action as an `rrt:player` browser event. The listener analytics (0.10.0) only has to listen.
- **Testing lesson:** PHP's built-in development server cannot answer partial (range) requests, so audio could not seek there; a small range-capable test server was needed. Real hosts handle this.
- **Chapters (0.7.0)** come from the MP3's own tag (section 9b): notches on the bar, a Chapters list, and the current chapter beside the label. `id3.js` loads with the player and follows the same strict-optimiser rules.

## 9t. Download counting to the IAB guidelines (1.24.0)

**Problem:** downloads only mean something when they are counted the same way everywhere, and the IAB Tech Lab guidelines are that way. 1.2.0 followed them loosely, and its redirect let part of Apple's streaming go past the counter.

**Decisions:**
- **Send the file, after counting.** A redirect tells an app where the file really is, and Apple's player then fetches the rest of a stream from there. Having the web server send the file itself (LiteSpeed's internal redirect, X-Sendfile, X-Accel-Redirect) brings every piece back through the counter, while PHP is free as soon as it has counted: the server sends the bytes, ranges and all. It is used only after Check counting has proved it on the site itself, since a server that ignores the header would answer apps with an empty file.
- **Join the pieces, measure the tag.** Apps ask for files in pieces of every size; the guidelines ask for the tag plus a minute, "reassembling" pieces where needed. Joined pieces are counted once however often they are asked for, and the tag is read from each file, because artwork makes it anything from a few kilobytes to megabytes.
- **The probe stays out, even when redirecting.** OP3 now counts a lone two-byte probe, because behind a redirect it can be all a prefix sees; the guidelines say not to, and with the hand-off this site sees the rest anyway.
- **Open lists, shipped and refreshed.** OPAWG's list is what OP3 and Podnews use, and is MIT; X4BNet's data centre list is MIT with IPLocate's data (CC BY-SA 4.0, credited). The agent list changes with apps, so it ships with each release; data centre ranges move, so the site fetches them monthly, with a copy in the plugin so counting is right from the first day.
- **A calendar day, in the site's time zone.** The guidelines allow a calendar day or a rolling window if it is stated. A rolling window would need each code kept beyond its day, which the daily code exists to prevent.
- **Members by member.** A member's feed address names the member, which the guidelines allow as additional data; it counts a member once whatever their phone's address does. It is used only inside the code and never stored with the counts.
- **Delivered by bytes.** A sponsored copy is built on the site, so where each message ends in the file is known exactly; a message counts as delivered once those bytes were asked for, which is stricter than the guidelines' quarter-of-the-file example.
- **Check what PHP really sees.** A web server can answer ranges itself and never hand the Range header to PHP (LiteSpeed does), so the first build counted two-byte probes on a real site while every test passed. Check counting now asks with a range and checks that counting saw it; where it did not, a marked `.htaccess` line hands the header on under another name, only for the short addresses, and goes again when counting is switched off.
- **The check runs from the browser.** A site's request to itself can be turned away by the host's firewall (one shared host's answered 412) or take a different path from an app's, so the owner's browser makes the test requests, the way an app would, and the site only judges what reached it.
- **A CDN can make counting impossible.** A host's own CDN can keep copies of addresses ending in `.mp3` and fetches whole files without the Range header; Apple needs that ending, so behind such a CDN the IAB rule cannot be followed. The check names the CDN and says so, and the site owner decides.
- **Say it, never claim it.** The method page writes itself from the settings, so it cannot drift from what the code does; it says "follows" and that no audit was made.

## 9u. The security review (1.25.0)

**Problem:** by 1.24.0 the plugin had 44 REST routes in 16 files, uploads from phones and the studio, private feeds with keys, saved storage passwords and code that writes the site's `.htaccess`, and the last full review was 0.10.1's. Before anyone else installs it, every one of those needed looking at again.

**What was found** (18 November 2026, every route, upload, file write and saved secret read, WordPress Coding Standards 3.1's security sniffs run; the two serious findings confirmed on the test site with a throwaway Author account):
1. A reading's field can hold a plain address, and anyone who can edit a post can type one in the Custom Fields panel. The short address handed the file to the web server, which ignores the folder's `.htaccess` (confirmed with X-Sendfile); collections and sponsored copies read the same addresses; storage offload left out the plugin's own private folders but not other plugins' closed ones.
2. Sponsor messages had ordinary posts' permissions, so an Author could publish one into every reading.
3. Members' files kept their old names under `rrt-members`, protected only by `.htaccess`, which nginx ignores.
4. A reading set to Members only kept its sponsored copy public for two days.
5. Share for feedback listed every account, with roles, to anyone who can edit posts, and could email any of them.
6. 56 sniff reports, none a real hole.
Nothing a visitor or a Subscriber could do was found.

**Decisions:**
- **One rule, in one place, used everywhere a reading becomes a file.** A list of allowed folders would break older posts and theme fields that hold plain addresses, so the rule allows any listening file in uploads and refuses the plugin's private folders and anything a folder's `.htaccess` closes. Only a deny at the top level of an `.htaccess`, or inside a `<Files>`/`<FilesMatch>` section that matches the file's name, counts: security plugins close PHP files in uploads that way, and those rules must not close readings. The kind is checked by extension and first bytes, so a renamed file is refused.
- **A members' file belongs to its own post.** Its checked address and its attachment number could otherwise be typed into another, public post.
- **Fields the box sets are protected.** The Reading audio box was always the intended way to set them; keeping them out of the Custom Fields panel closes the easy path, and the rule covers the rest (a theme's own field).
- **The hand-off only while counting.** It exists for counting; without it, a redirect is what 1.23.0 and earlier did.
- **Sponsor messages are the owner's.** A message reaches every reading on the site, so it needs more than the right to write one's own posts. Administrators by default, with a filter, because some sites have an editor who manages sponsors.
- **Random folders, and a check that asks.** A random folder per file makes a guess useless on any server; the check asks for a test file the way a visitor would, so it finds out what the server really does instead of guessing from its name, and shows nginx's lines only where they are needed.
- **Private means at once.** The two days older sponsored copies are kept are for apps that read the feed before a copy was remade; when a reading becomes private, that kindness would keep it open.
- **Share with the people you work with.** Editors and Administrators already see every account; Authors and Contributors share with them and with anyone who shared with them, which covers feedback between writers without showing the whole member list.

## 9v. Accessibility (1.26.0, the player and the admin screens)

**Problem:** nothing had been checked against WCAG. Before others install the plugin, the player their listeners use and the screens they work in had to be usable by keyboard, screen reader and voice control, and readable on any theme.

**What was found** (19 November 2026: the code read; axe-core run at WCAG 2.2 A and AA on 13 screens of the test site, whose default theme is light; keyboard probes in Chrome; contrast worked out for both presets on white, black and the studio's own background):
1. The player's see-through panel turned mid-grey on a light theme: label 3.7 to 1, times 1.8, skip buttons 3.1. On a dark theme everything passed.
2. "−15s", "+30s" and "1×" were named "Back 15 seconds", "Ahead 30 seconds" and "Playback speed, currently normal" (WCAG 2.5.3, Label in Name).
3. Read along's fallback list could only be clicked.
4. Focus was a 14% tint (8% in the chapter list) with outlines off.
5. Read along dimmed the post to 55% and turned the current word gold: 3.8 to 1 and about 2 to 1 on white.
6. The progress bar's unplayed part was 1.4 to 1.6 to 1 against the panel, and 22 px to touch.
7. A collection, or a reading with words added, had no text version on the page.
8. Six admin fields without a label; the Reading audio box's two Choose buttons did not say which file.
The studio's findings (its page keys taking over every control, invisible focus while reading, dialogs, click-only words, help in tooltips) are 1.27.0's (section 9w).

**Decisions:**
- **A solid panel, and the owner's colours checked.** A see-through panel takes its real background from whatever theme is behind it, which no setting can check. Solid, the ratios in Settings are the ratios listeners see. Matching the theme's palette, as the studio's theme look does, was considered and left for later: the check makes any colours readable, which matters more.
- **Make it readable moves only what fails, by the least.** The colours are the owner's look; the smallest step towards white or black that is enough keeps the hue and changes as little as possible. Backgrounds never move, because everything else is measured against them. Saving the form first means a colour just changed is checked too, with one algorithm (in PHP, tested) instead of a second copy in the browser.
- **Words on the buttons.** "Back 15s" names itself; the symbols needed a second, hidden name that voice control users cannot see. The words are also plainer for everyone.
- **A Transcript button, in the player.** A separate public page per reading would add addresses, a template and something more to keep in step; the player already had the lines for read along's fallback, and the feed already gives apps the transcript. Each line is a button, so the list plays from the keyboard.
- **Read along without dimming.** Dimming the unread words lowers the post's own contrast by an amount no setting can see. A tint marks the sentence and an underline the word, so the place shows without colour alone, and the post's text keeps whatever contrast its theme gives it.
- **The hint colour for the bar.** The check already holds it at 4.5 to 1, more than the 3 to 1 a control needs, and the always-shown position dot marks where playback is.
- **axe-core as a test tool only.** It is the checker most browsers' tools use; fetched from npm and pinned by its fingerprint like the other test downloads, never shipped. It finds only part of what people meet, so the keyboard checks that found the worst problems are tests too.

## 9w. Accessibility (1.27.0, the studio)

**Problem:** the studio passed axe-core with nothing reported, and still failed a keyboard user at the first switch.

**What was found** (19 November 2026, the same audit as 9v):
1. S1: the page's keydown blurred any focused switch, slider or button and acted as the teleprompter. Space on a switch started the countdown; the right arrow on Size raised the pace; Space on Check microphone did nothing.
2. S2: while reading, the bars faded to 8% even around a focused control.
3. S3: P, B, the full stop, plus, minus, [ and ] acted on one key press anywhere (WCAG 2.1.4).
4. S4: no dialog took focus; Tab went behind them; the cue menu called itself a menu without menu keys.
5. S5: starting from a word, cues and everything in the review's script needed a mouse.
6. S6: over forty controls explained themselves only in hover tooltips.
7. S7 to S10: status not announced, rushed and slow sentences told apart by colour alone, no main heading, words ahead at 2.6 to 1 with the theme look on a light theme, the intro's Enter skipping instead of pressing, new-tab links unannounced.

**Decisions:**
- **Keys act where focus is.** The teleprompter's keys need a place to live that is not a control: the script, made one Tab stop, and the page itself, which is where focus rests after a click on the script. Controls keep their own keys, so nothing has to be blurred. Escape is the way back, because it is the key people already press to leave something. Page Up and Page Down stay global, since a clicker cannot choose where focus is; on a slider they lose their large step, which the arrows and Home and End cover.
- **A switch for single-key shortcuts, on by default.** The keys are useful at a rig; WCAG asks for a way to turn them off, and limiting them to the script already stops most stray presses.
- **One helper, watching, for every dialog.** The dialogs are opened in many files by adding a class or a box. Watching for that (a MutationObserver on the four page dialogs and on the page for the rest) gives every one the same focus, Tab and Escape rules without touching each opener. Escape presses the dialog's own Not now or Close, so it can never do something the dialog's buttons cannot, and it does nothing while a take is recording.
- **A word cursor that is the reading position.** In the teleprompter, moving a word with Cues on moves where the reading starts, which keeps the word on screen the way the teleprompter already scrolls; the cue menu opens at it. The review's cursor is its own, since playing and selecting there must not move anything else.
- **A ? button, built from the tooltip.** The tooltips already held the right words; the help is made from them when the page loads, so text and tooltip never drift apart, and a control whose tooltip changes keeps its help in step. The explanation opens under the control, keeping the panel compact on a phone, and describes the control for screen readers whether it is open or not.
- **Words ahead held, never the owner's colour moved.** Words ahead are a mix of the text colour and the page; the mix is raised only as far as 3 to 1 needs, and the Readability check lists the result so the owner can see it.
- **Text tags in the review.** A tag says fast or slow in words for everyone, screen readers included, and the dotted edge tells slow from the rest in shape too.

## 9x. The player block (1.28.0)

**Problem:** the player could be placed by hand only with a shortcode under the plugin's first name or with `rrt_player()` in theme files, which block themes do not have; and placing it by hand while the top of the post was on gave two players.

**Decisions** (agreed 19 November 2026; the proposal's nine choices, all as recommended):
- **A dynamic block.** The post stores the block's name and the reading chosen, nothing else, so later releases can change the player without any saved post going out of date ("This block contains unexpected content").
- **Placing it by hand wins.** When a post or its template places the post's own reading, the top of the post gives way. A block for another reading does not count, so a post can play its own reading at the top and point to another below.
- **Once per page, per place.** A reading drawn from the content, a widget area or the template is not drawn again from another of them. The same place may draw it again: some themes and plugins run the content through `the_content` twice (for a reading time or a description), and a rule of "once" would leave the real one empty.
- **Another reading** by search, of posts and collections that have a reading, for pages such as Listen or a home page.
- **No per-block options** beyond the reading and alignment: the player looks and works the same everywhere, and Settings stays the one place to change it.
- **Read along only on the reading's own page.** Elsewhere the post's text is not there to follow, and Transcript gives the same words.
- **One playing at a time,** with the lock screen following it.
- **`[foxfire_player]`** beside `[reading_room_player]`, which stays for good (removing a shortcode is a major version's change), and both turn into the block when pasted.
- **The preview is the real player** from WordPress's block renderer, made inert, so what the editor shows is what visitors get; a card for a post without a reading points to the studio.
- **API version 3 and no build step.** WordPress 7.1 always shows the post editor in a frame; version 3 says the block is ready for it, and WordPress 6.0 still reads it. The editor side is plain JavaScript on WordPress's own packages, like the rest of the plugin, so there is nothing to compile.

## 9y. The setup wizard (1.29.0)

**Problem:** a new site owner met one long Settings page and no starting point; a new reader's first take was usually made with an unchecked microphone, on a real post; the choice to keep the studio's files on the site was buried.

**Decisions** (agreed 19 November 2026; the proposal's eleven choices, all as recommended):
- **A notice, never a redirect.** One notice on Plugins and the plugin's own screens, with Set up and Not now. A redirect on activation takes over the dashboard (which WordPress.org's guidelines frown on) and also fires on bulk activation and from WP-CLI.
- **New installs only.** Whether a site is new is decided once, before the install makes anything, by the same test listener analytics used in 0.10.1. Sites that had the plugin are never offered it; Run setup again is there for everyone.
- **One step per screen, plain forms.** Each step is saved on Next, so a half-finished setup keeps what was done. Plain forms work without scripts, which also makes them easy to reach by keyboard and screen reader.
- **Only what a step shows is written.** Settings' sanitizer reads a missing box as off, so a step sending its few fields through it would switch the rest off. The wizard lays its fields over the current settings, checks them with the same sanitizer, and copies only the step's own settings into the saved option, and only when they differ, so nothing else moves and a site set up already is left exactly as it was.
- **The microphone check and practice happen in the studio**, opened from the last step. There is one microphone check in the code, and it belongs to the computer and microphone that will record.
- **A built-in practice script, with no post.** About a minute aloud: plain sentences, a long one with a comma pause, a question, a name and numbers, so the review has something to say. Nothing is made on the site but the take.
- **Practice takes kept as post 0**, with the reader's other takes, so Your progress has a starting point. They are never on a post's shelf, and no recording can be put on post 0.
- **Visitor-facing switches are listed, not switched.** Each needs a privacy policy line first, which the wizard cannot write; they are shown as off, with what each does and where it lives.
- **The studio's files in one button** (Whisper, the encoder, the noise model, the fonts), with voice following and the larger model as unticked extras, since they cost 45 and 570 MB.
- **New here for every reader:** offered once in a browser with no microphone check saved, inline on the studio's first screen and beside Check microphone, so it never covers anything.
- **The player's audience** is shown with the editors' preview chosen, and a line saying to switch to Everyone once a post has been checked.

## 9z. Readings of deleted posts (1.36.2)

**Problem:** WordPress never deletes a post's attachments with it, so a deleted post left its MP3s and captions in the Media Library and its rows on Recordings, with nothing on the screen to say so or remove them. Found on a live site after six test posts were deleted.

**Choices:**
- **Say what became of each reading first,** then offer Delete where it is safe. The reading on a live post never gets Delete (replace it in the studio instead), and nor does a kept reading.
- **Ask on the page, in a `details`,** never in a browser dialog, so the keyboard, a screen reader and the Chrome extension all work, and nothing is deleted by one press.
- **Never delete a file something else uses.** Another post's reading or captions counts; a post in the Trash does not, since emptying the Trash would delete it anyway. WordPress's cover picture for the MP3 goes with it on the same rule.
- **The Trash deletes nothing.** Only deleting for good takes the readings and takes with it, so restoring a post restores everything. On by default because a site owner expects deleted to mean deleted; a switch in Settings, Reader stats, for anyone who keeps media on purpose.

## 18. Lessons from real recordings

Things learned by running it on a real voice that synthetic tests did not show:

- **Tune on a real voice.** Thresholds set on synthetic speech flagged natural variation as rushed.
- **The model is often the weak link.** Before telling a reader they misread, check whether the model misheard.
- **Measure, then ask, before explaining a cause.** The first explanation for the clicks was a guess that turned out wrong. The measurement was right, the story was wrong. Ask the person before attaching a cause to a measurement.
- **Built-in microphones have their own problems.** A computer's built-in mic sits next to its fan. Heavier processing (voice following, Whisper) makes the fan louder, so heavy work is best saved for after the recording.
- **Machines and ears want different audio.** With lossless recording, the cleanup's noise reduction dropped Whisper from 96% to 67% on the same take while changing the voice by about 0.3 dB. Whisper is trained on everyday audio, and enhancement made for listeners can confuse it. Since 0.4.0 Whisper always hears the untouched recording, and cleanup only shapes the MP3. Test any new processing (0.5.0 onward) against Whisper as well as by ear.
- **A friend's ears catch what yours don't.** The fan and the whistles were obvious to someone else and inaudible to the reader. Build ways to share a take for feedback (on the roadmap).

---

## 19. Open questions

- Should learned words be per post as well as global, to avoid hiding real misreads?
- What is the right default for "Clean up audio" on a good USB microphone, where cleanup has less to do and more chance of doing harm?
- Is base.en accurate enough once a vocabulary is taught, or should the larger model be the default for people with fast computers?
- How much listener data is useful before it becomes noise? See [ANALYTICS-SPEC.md](ANALYTICS-SPEC.md#what-is-worth-knowing).

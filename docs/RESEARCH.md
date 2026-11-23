# Research notes

Best practices gathered while planning the roadmap (November 2026), and what each one means for Foxfire Studio. Each section ends with the roadmap items it produced. When a practice changes or a source goes stale, update this file and say what changed.

---

## Loudness for spoken word

**What the field does**
- The podcast world has largely settled on **-16 LUFS** integrated loudness, which is what Apple Podcasts asks for, with a tolerance of about 1 dB either way. Spotify and YouTube aim for -14 LUFS, but most podcasters still deliver at -16.
- Apple also asks that **true peak** stays at or below **-1 dBFS**.
- A mono file measures quieter than the same audio in stereo. **-19 LUFS mono is roughly equivalent to -16 LUFS stereo**, and some analysers will report a correct mono file as -19.
- Audiobook platforms are stricter. ACX (Audible) requires each file to measure **-23 to -18 dB RMS**, peaks below **-3 dB**, a noise floor no higher than **-60 dB RMS**, **1 to 5 seconds of room tone** at the head and tail, and **192 kbps or higher CBR MP3 at 44.1 kHz**.

- Mono, checked November 2026: BS.1770 measures a mono file as one channel, but most devices play it on both speakers, about 3 dB louder. A common choice is -19 LUFS for mono when -16 is the stereo target; some devices do compensate, and the difference is small. Google Assistant asks for -19 mono and -16 stereo.
- ACX, checked November 2026: RMS -23 to -18 dB, peaks no higher than -3 dB, noise floor no higher than -60 dB RMS, 1 to 5 seconds of room tone at the start and end, MP3 at 192 kbps or more CBR, 44.1 kHz, mono or stereo.
- Lossy encoding raises peaks; -1 dBTP is a common ceiling that encodes cleanly, and a little more margin protects lower bitrates.

**What this means here (before 0.5.0)**
Today's MP3 step levels by a home-made speech loudness measure (RMS of the voiced parts, aimed at 0.13). It works, but it is not a number anyone else uses. Measuring in LUFS (ITU-R BS.1770) would let the teleprompter hit the same target as every podcast app, report a figure the maintainer can compare with other shows, and check true peak properly.

**Roadmap:** loudness to the podcast standard, and a "studio-ready" check with an audiobook preset ([0.5.0](../ROADMAP.md#050-broadcast-sound)).

**Done in 0.5.0:** BS.1770 loudness with gating, -19 LUFS by default for mono (-16 one click away), a true-peak limiter at -1.5 dBTP before encoding, and an ACX audiobook preset. Tested against the EBU Tech 3341 test signals.

Sources: [Podnews: LUFS and LKFS for podcasters](https://podnews.net/article/lufs-lkfs-for-podcasters), [ACX audio submission requirements](https://help.acx.com/s/article/acx-audio-submission-requirements), [Auphonic: -16 or -19 LUFS for mono podcasts](https://auphonic.com/blog/2020/06/09/loudness-normalization-mono-productions/), [Production Advice: true peak before encoding](https://productionadvice.co.uk/spotify-upload-true-peak/)

---

## Counting listeners honestly

**What the field does**
- The IAB's podcast measurement guidelines build counts from file requests, remove known non-human traffic, and **filter duplicates within a 24 hour window**.
- A "listener" in that world is only an **estimate**, built from IP address and user agent within a stated period. Changing and shared IP addresses push it up or down, and longer periods make it worse.
- Good reporting **labels every metric by its exact definition**, shows the **time frame and time zone** on every chart, never treats a download as a listen, and uses **in-player playback data** for questions about how people listened, because server logs cannot see playback.

**What this means here**
Our player sees real playback, which is better data than downloads. The spec should still follow the same honesty rules: a plays count deduplicated within 24 hours, listener counts labelled as estimates, definitions one click away on every chart, and the site's time zone printed on every report.

**Roadmap:** metric definitions panel, 24 hour play deduplication, estimated unique listeners ([0.10.0](../ROADMAP.md#0100-listener-analytics), [0.11.0](../ROADMAP.md#0110-the-dashboard)).

Source: [Podder: IAB podcast measurement explained](https://www.podderapp.com/post/iab-podcast-measurement-explained)

---

## Counting without cookies

**What the field does**
Plausible counts unique visitors with no cookies and nothing stored in the browser. For each request it hashes the visitor's IP address and user agent together with the site's domain and a **salt that is rotated and deleted every 24 hours**. The result identifies a visitor for one day on one site and cannot be linked across days, devices or sites. Raw IPs and user agents are never stored; only derived values (browser, device type, country) are kept. Plausible says this removes the need for a consent banner. That is the vendor's own position, and this note is no substitute for legal advice.

**What this means here**
The first draft of the analytics spec only had per-page-view sessions, which cannot tell one listener coming back three times in a day from three listeners. The daily salted hash gives **daily unique listeners** without cookies, and fits the privacy promises already made.

**Roadmap:** daily salted listener id ([0.10.0](../ROADMAP.md#0100-listener-analytics)), privacy section of [ANALYTICS-SPEC.md](ANALYTICS-SPEC.md#privacy) updated.

Source: [Plausible data policy](https://plausible.io/data-policy)

---

## Reading a retention curve

**What the field does**
- A healthy curve holds fairly flat for long stretches. The red flags are **steep drops at specific moments**; ask what happens at that point.
- **Early drop-off points at the opening.** If people leave in the first stretch, the intro or hook is the likely problem.
- When drop-off clusters at a similar point across episodes, try shorter pieces or move the strongest material earlier.
- Track the 25%, 50% and 75% marks and **compare your own pieces over time**, since outside benchmarks vary too much to be useful.

**What this means here**
The dashboard already planned the retention curve. Two additions follow: a headline **"held through the opening"** measure (share of plays still listening at 60 seconds), and the ability to **mark a change on the timeline** ("tried a shorter intro") so the before and after can be compared.

**Roadmap:** opening hold rate, change markers, an "opening drill" in the studio ([0.11.0](../ROADMAP.md#0110-the-dashboard), [0.14.0](../ROADMAP.md#0140-studio-upgrades-microphone-check-opening-drill-practice-focus)).

Source: [School of Podcasting: decoding retention graphs](https://www.schoolofpodcasting.com/blog/podcast-retention-graphs-audience-drop-off/)

---

## Transcripts and accessibility

**What the field does**
- Under WCAG, **a transcript is required at Level A for prerecorded audio-only content** such as a podcast.
- The transcript should be easy to find, ideally **on the same page, near the player**.
- **Interactive transcripts** highlight the words as they are spoken and let people click text to jump there. They are driven by the captions file.

**What this means here**
Every reading on the live site already sits on a page with its text, which is close to a transcript. Two gaps: the post text and the spoken reading can differ (the Whisper review knows exactly where), and nothing ties the two together. The planned "follow along" is in fact an interactive transcript, so it should be built to the W3C pattern, and the review should offer to note any spoken differences so the page stays an accurate transcript.

**Roadmap:** follow along built as an accessible interactive transcript; transcript accuracy check ([0.3.0](../ROADMAP.md#030-the-listening-player)).

Source: [W3C WAI: Transcripts](https://w3c.github.io/wai-website/media/av/transcripts/)

---

## Lock screen and phone controls

**What the field does**
The Media Session API lets a web audio player put its **title, artist and artwork on the phone's lock screen and notification**, and respond to play, pause, **skip back, skip forward and seek** from there and from headphones or car controls. It also reports position and speed so the system scrubber is correct. Supported in Chrome, Edge, Firefox and Safari.

**What this means here**
Many listeners lock their phone and keep listening. Without this, they see a blank or generic control and lose the skip buttons. It is a small amount of code.

**Roadmap:** lock screen controls ([0.3.0](../ROADMAP.md#030-the-listening-player)).

Source: [web.dev: Media Session API](https://web.dev/articles/media-session)

---

## Clips for social media

**What the field does**
- Audiograms (a short clip with a waveform or image and captions) stand out in feeds and play without leaving the app. Buzzsprout cites WNYC finding more engagement on posts with audiograms, and Facebook data showing them outperforming images and links.
- Recommended length: **about 30 seconds**, and no more than 60.
- **Square** suits Instagram and Facebook feeds; **16:9** suits YouTube and websites; taller formats suit Stories.
- **Captions matter**, since a large share of people watch with the sound off.
- Choose a moment with enough substance to hook, without giving away the best part, and **always include the link** to the full piece.

**What this means here**
This connects the two halves of the project. Listener data can point at the moments people replay, the take's word timings can caption them exactly, and campaign links can measure which clip brought people in.

**Roadmap:** clip maker ([0.12.0 or later](../ROADMAP.md#0130-clips-for-social-media)).

Source: [Buzzsprout: podcast audiogram tips](https://www.buzzsprout.com/blog/podcast-audiograms-tips)

---

## Practising well

**What the field does**
- Speech coaching apps (Orai, Speeko, Yoodli) converge on the same core: **filler words, pace, and progress over time**, plus some form of **energy or monotone** tracking, short **exercises and warm-ups**, and in Orai's case a **curriculum built from the user's goals**.
- Practice advice from speaking coaches: **work on one or two things per session** and decide how you will judge success; **record the opening many times** and refine it; act on feedback right away with a short focused session; prefer **several short sessions** over long ones.

**What this means here**
The review studio shows everything at once, which is good for a full picture and poor for practice. A "focus" mode that picks one habit per session, an "opening drill" for the first 30 seconds, and goals with a light practice log follow directly from this. Energy (loudness variation) is the one common coaching metric not yet measured.

**Roadmap:** practice focus, opening drill, goals, energy measure ([0.14.0](../ROADMAP.md#0140-studio-upgrades-microphone-check-opening-drill-practice-focus), [0.9.0](../ROADMAP.md#090-reader-stats-on-the-server)).

Sources: [Yoodli: Orai vs Speeko](https://yoodli.ai/blog/orai-vs-speeko-what-to-know) (published by a competitor, so read its comparisons with that in mind), [Moxie Institute: how to practise public speaking](https://www.moxieinstitute.com/how-to-practice-public-speaking/)

---

## Scheduled jobs on WordPress

**What the field does**
WP-Cron only runs when someone loads a page, so on a quiet site scheduled jobs can wait hours. The standard fix: create a **server cron job that calls `wp-cron.php` every 5 to 15 minutes**, confirm it works, then add `define('DISABLE_WP_CRON', true);` to `wp-config.php` so visits no longer trigger it. Do it in that order, or every scheduled task stops.

**What this means here**
The monthly email and the nightly rollups depend on this. The plugin should detect when WP-Cron is late and say so on the Reports screen, with the exact steps.

**Roadmap:** cron health check ([0.12.0](../ROADMAP.md#0120-the-monthly-report)).

Source: [CyberOptik: WP-Cron not running](https://www.cyberoptik.net/glossary/wp-cron-not-running/)

---

## Chapters and transcripts in podcast feeds

The Podcasting 2.0 namespace has formalised `podcast:transcript` and `podcast:chapters` tags for RSS feeds. Since every take already has word timings and the post has headings, the plugin could produce both files for free and publish a feed later.

**Roadmap:** chapter marks in the MP3 and as a chapters file ([0.7.0](../ROADMAP.md#070-podcast-ready-files)). The feed itself stays in the parking lot until one is wanted.

Source: [Podcast Index: podcast namespace](https://github.com/Podcastindex-org/podcast-namespace)

---

## Recording and processing for podcasts

Researched in November 2026, after a test take on a laptop measured background noise only 19 dB below the voice (an earlier recording in the same setup measured about 44 dB).

**Where the teleprompter stands**
- It already does much of what podcast editors do by hand: it removes mouth clicks before phrases, stray sounds in pauses, steady background noise and whistling S sounds, then trims, levels, limits and fades.
- The weak link is capture. The browser's recorder saves compressed audio (WebM with Opus), and the MP3 step compresses it a second time. A raw capture with an AudioWorklet keeps the master lossless until the one compression that matters, and saving it in chunks protects a take from a crash.

**Noise reduction with a speech model**
- **RNNoise** is small (about 110 KB as WebAssembly) and BSD-licensed, with maintained browser packages such as `@jitsi/rnnoise-wasm` and `@shiguredo/rnnoise-wasm`. It works on 48 kHz audio in 10 ms frames. It is good on steady noise and weaker on overlapping voices or sudden clatter.
- **DeepFilterNet3** sounds noticeably better but is much heavier. In the browser it usually runs on ONNX Runtime Web, whose core file is about 14 MB before the model. A WebAssembly packaging exists (`deepfilternet3-noise-filter`, MIT or Apache). The teleprompter already loads ONNX Runtime for Whisper, which lowers the cost.
- Both are built for live calls. Here they would run on the finished recording, like Whisper, so the laptop's fan stays quiet while reading.

**The processing chain**
- What podcast editors usually apply to a voice, in order: a high-pass filter (about 80 Hz) for rumble and bumps, gentle compression for even loudness within sentences, a de-esser, plosive and breath control, then loudness normalisation with a true peak limit. Browsers include the filters and a compressor (Web Audio's BiquadFilterNode and DynamicsCompressorNode), which work in an OfflineAudioContext on a finished recording.
- **Measuring loudness:** Needles (MIT) measures momentary, short-term and integrated LUFS on a finished file in the browser, but does not yet measure true peak. The BS.1770 filters are published and short, so measuring both in the teleprompter's own code is also reasonable.

**The file itself**
- Podcast apps read ID3 tags for the title, artwork and chapters. `browser-id3-writer` writes ID3 in the browser and gained chapter frames (CHAP) in version 6.4.0. Its licence needs checking before it is used.
- `wasm-media-encoders` packages the LAME encoder as WebAssembly (about 130 KB, 66 KB gzipped) with both constant and variable bitrate. LAME itself is LGPL, as lamejs is, so the licence situation stays the same.

**Editing**
- Editing audio by editing a transcript is now common in podcast tools. The Whisper review already has a time for every word, which is what such editing needs.

**What was left out**
- Removing room echo. Browser-ready open-source options are weak, and soft furnishings near the microphone do more.

**Roadmap:** lossless recording ([0.4.0](../ROADMAP.md#040-lossless-recording)), broadcast sound ([0.5.0](../ROADMAP.md#050-broadcast-sound)), speech-model noise reduction ([0.6.0](../ROADMAP.md#060-speech-model-noise-reduction)), podcast-ready files ([0.7.0](../ROADMAP.md#070-podcast-ready-files)), text-based editing ([0.8.0](../ROADMAP.md#080-text-based-editing)).

Sources: [Fora Soft: RNNoise and DeepFilterNet compared](https://www.forasoft.com/learn/ai-for-video-engineering/articles-ai/real-time-noise-suppression-krisp-rnnoise-deepfilternet-integration), [@jitsi/rnnoise-wasm](https://www.npmjs.com/package/@jitsi/rnnoise-wasm), [@shiguredo/rnnoise-wasm](https://cdn.jsdelivr.net/npm/@shiguredo/rnnoise-wasm@2025.1.5/README.md), [DeepFilterNet3 noise filter](https://cdn.jsdelivr.net/npm/deepfilternet3-noise-filter@1.3.0/README.md), [Needles](https://github.com/domchristie/needles), [browser-id3-writer chapters](https://www.github.com/egoroof/browser-id3-writer/issues/45), [wasm-media-encoders](https://www.npmjs.com/package/wasm-media-encoders)

## Voice following

Researched before 1.14.0, because the browser's own speech recognition sends the audio to an outside service in most browsers. The question was narrower than transcription: can a small model running live in the page keep a prompter's place in a script the reader already has?

**The test bench**
- One real 13-minute reading recorded on a phone (about -55 LUFS as recorded), its script of 1,595 words, and a 20 s second reading, fed to each model 100 ms at a time as a live microphone would, on one processor thread of the same WebAssembly build that runs in a browser.
- Harder versions cut from the real reading at real pauses: other speech dropped in twice for 19 s (ad-libs), 191 words left out (a skip), 57 words read twice (going back), a 12 s silence, and the whole reading 12 dB quieter. The true place at every moment came from the largest model's transcript lined up with the script.

**What was found**
- A **small streaming Zipformer** (20M parameters, trained on read audiobooks, about 44 MB in 8 bits, Apache-2.0) with **sherpa-onnx** (Apache-2.0) used about 11% of one core and kept the follower within 3 words of the reader 97% of the time on the straight reading, held its place through both ad-libs, caught up 3 s after the skip and 7 s after going back, held still through the silence, and did as well at the lower level and at the phone's raw level. It settled a sentence's last word a median 0.42 s after it was spoken.
- A **larger streaming Zipformer** (about 71 MB) used twice the processor and settled words later (median 0.9 s), with no better following. **NVIDIA's streaming FastConformer** (457 MB) needed 1.4 times real time on one thread, too slow for live use.
- In headless Chrome the small model ran live at 11% of a core with no cross-origin isolation, so a site needs no special headers.
- Names and unusual words (classical names, rare nouns) were often misheard, but the follower carried on past them, because it lines up several words at a time.
- The live transcript matched 92 to 94% of the script's words exactly, against Whisper's 97% on the same reading: good for a quick first look, not for the review.

**A faster review, looked at beside it**
- **Moonshine v2** (MIT licence for English), run offline in pieces, took 0.10 (tiny) and 0.21 (base) of the reading's length to transcribe, with 97 to 98% of its words matching the script, several times faster than Whisper base here. It gives no word timings, which the review needs for flags, editing, captions and clips, so it is not a drop-in for Whisper; pairing it with an aligner would be its own research.
- **WebGPU** was available in a current Chrome on an ordinary laptop with integrated graphics, with half-precision support, so Whisper on the graphics chip is possible there; it can only be measured on the reader's own computer. Safari has no WebGPU.
- The quickest win is the **first look**: what the follower already heard, shown the moment a take stops, with the Whisper review following as before.

**Roadmap:** the voice-following prompter ([1.14.0](../ROADMAP.md#phase-1-shine)); Whisper on the graphics chip stays a later step.

---

## Faster review

Run on 17 and 18 November 2026 in a reader's own laptop Chrome (Windows, 8 threads, 16 GB; two graphics chips, AMD Radeon RX Vega 10 inside the Ryzen processor and NVIDIA GeForce GTX 1650), with the site's own transformers.js 4.3.2 and the plugin's own way of cutting a reading into pieces, on a real 14:13 reading.

- **Chrome is given one graphics chip** for both power preferences: the integrated one unless Windows' Graphics settings set Chrome to High performance, and the change only takes effect once Chrome is ended fully.
- **The larger model on the processor** took 1.88 of the audio's length; the standard one 0.29.
- **The larger model on the graphics chip** (half-precision encoder, 4-bit half-precision decoder): 0.35 on the GTX 1650 and 0.57 on the Radeon. The whole reading took 5.1 minutes on the GTX 1650, with 97.9% of the post's words heard in order, every word timed and none running backwards. With the decoder on the processor it was 0.53 on both chips.
- **Word times** work on WebGPU, and the larger model's times agree within 0.04 s at the 95th percentile whichever setup ran them.
- **The larger model hears better:** about 96.4% of words matching the post on a 3-minute stretch, against 92.7% for the standard one.
- **The standard model** gained only 1.4 times on the graphics chip. **Skipping long silences** would save at most about a tenth (pauses of 1 s or more were 13.5% of the reading).

**Roadmap:** [1.17.0 The faster review](../ROADMAP.md#1170-the-faster-review).

## Full-length video

Run on 18 November 2026 in a reader's own laptop Chrome (Windows, GeForce GTX 1650) with the plugin's own clip drawing and mp4-muxer 5.2.2 writing into the browser's private storage, on a real 14:13 reading with captions; and a look at where a video can go.

- **Where video goes (November 2026):** YouTube takes it by upload; its RSS connection makes a still-picture video from the show art. Spotify takes it by upload in Spotify for Creators or through a few hosts' private API, and asks for MP4, H.264, 16:9, 1080p, AAC-LC at 128 kbps or more and about one keyframe a second. Apple Podcasts' new HLS video comes only through partner hosts, and Apple does not read `podcast:alternateEnclosure`. The open feed path is that tag carrying HLS, read by Pocket Casts, Fountain, TrueFans and (in part) iHeart and Amazon Music.
- **Speed:** all of the reading (14:16 with a 3 s end card) at 1920 by 1080, 30 frames a second, took 2:15, 6.3 times real time, on the graphics chip's encoder; the processor's encoder took 13 s a minute against 10.
- **Size:** about 19 MB a minute at 2.5 Mbps (the reading came to 275 MB; the picture averaged about 2 Mbps) and about 37 MB a minute at 5 Mbps.
- **Memory:** the page's memory peaked near 310 MB, about half of it the decoded sound; the video itself went straight to storage.
- **Timers:** with a `setTimeout` wait the render dropped to under half real time after a minute or two whenever the window was not in front; waiting on the encoder's `dequeue` and yielding by `MessageChannel` kept it at full speed.
- **Look:** at 2.5 Mbps a soft dark glow showed faint steps when a frame was brightened, hard to see at normal brightness.

**Roadmap:** [1.18.0 Full-length video](../ROADMAP.md#1180-full-length-video).

## Download counting to the IAB guidelines

Checked on 18 November 2026 before 1.24.0: the guidelines' current text, how redirects and Apple's player meet, the open lists, and four tests in the workspace.

- **The guidelines:** IAB Tech Lab's Podcast Technical Measurement Guidelines v2.3 (final in 2026, after v2.2 in May 2024), read in full from its GitHub repository. A download is a request that delivered the tag plus one minute (the whole file under a minute), once per IP address and user agent in a stated window (calendar day, first-touch or last-touch rolling 24 hours); IPv6 cut to 64 bits; HEAD, 304, two-byte probes, Apple Watch duplicates (`atc/` with `watchOS`, `(null)/(null) watchOS`), self-declared bots, malformed user agents, data centres, VPNs and unrealistically busy addresses filtered out; shared-network allow lists re-validated every 90 days; the method published, divergences disclosed, and a self-audit at least twice a year. v2.3 also covers video and describes prefix redirects.
- **Redirects and Apple:** a study on Livewire's blog (with OP3's and Transistor's data) found AppleCoreMedia sends its two-byte probe to the episode's address and, on fast connections, its later ranges straight to where the redirect points. OP3 now counts a lone probe (about 6 to 7% of its downloads). OP3's own code counts any range over two bytes, per UTC day.
- **The hand-off:** a 3-minute MP3 with artwork behind a PHP counter, through nginx with X-Accel-Redirect and Apache 2.4 with mod_xsendfile: probes, middle ranges, open ranges and the end all came back as 206 with the right bytes, HEAD and whole GETs as 200, and PHP saw every request. PHP's own `text/html` type leaked through nginx unless the counter sends `audio/mpeg`. LiteSpeed documents the same as `X-LiteSpeed-Location`.
- **The rule on app patterns** (14:13 at 128 kbps, a 250 KB tag: the bar 1,210,000 bytes where 1.2.0 used 977,585): whole files, Apple's probe then download, and a 3-minute stream counted under both; a stream stopped near 30 s counted under 1.2.0 only; 512 KB pieces and start-then-skip counted under the new rule only; Apple Watch counted under 1.2.0 only.
- **Bots and names:** against OPAWG user-agents-v2's own examples, 1.2.0's pattern missed 262 of 364 bot examples and 36 of 40 bot libraries, called 3 apps bots and named 855 of 991 app examples "Other". OPAWG's 956 patterns all compile in PHP, every one of 1,466 examples matched its own entry first, at 0.04 ms a user agent.
- **Data centres:** X4BNet lists_vpn (updated daily, from IPLocate's IP to ASN data): 45,937 IPv4 and 8,687 IPv6 ranges, merged to 34,085 and 6,927 (384 KB). It caught Google Cloud, AWS, Hetzner and DigitalOcean addresses and none of five home and mobile ones.

**Roadmap:** [1.24.0 Download counting to the IAB guidelines](../ROADMAP.md#1240-download-counting-to-the-iab-guidelines).

Sources: [IAB Tech Lab: Podcast Technical Measurement Guidelines v2.3](https://github.com/IABTechLab/Podcast-Technical-Measurement), [Livewire: A Tale of Two Bytes](https://livewire.io/a-tale-of-two-bytes-prefix-vs-host-based-analytics/), [OP3](https://github.com/skymethod/op3), [OPAWG user-agents-v2](https://github.com/opawg/user-agents-v2), [X4BNet lists_vpn](https://github.com/X4BNet/lists_vpn), [LiteSpeed: Internal Redirect](https://docs.litespeedtech.com/lsws/redirect/).

## Storage offload

Checked on 18 November 2026 before 1.23.0: what podcast apps need from a file host, the storage services, what browsers need to read a file from elsewhere, how other offload plugins work, and S3 with no code library.

- **Apple's requirement:** the server holding the episodes must answer HTTP HEAD requests and byte-range requests, so apps can check a file and play from the middle. Apple's delivery notes for media in browsers add `Access-Control-Allow-Origin` (or `*` for public media) and exposing `Content-Range` and `Content-Length`.
- **Bunny:** Storage at $0.01 per GB a month in one region, the CDN at $0.01 per GB in Europe and North America, storage to CDN traffic free, a $1 monthly minimum; a 14-day trial with $20 of credit and no card. Its HTTP API takes a PUT with the storage zone's password in `AccessKey` and an optional uppercase SHA-256 `Checksum`. Its S3-compatible API is a public preview since 9 July 2026, only for storage zones made with it switched on, in a few regions. Pull zones add CORS headers by file extension. Logs anonymise IP addresses by default and are kept 3 days.
- **Cloudflare R2:** 10 GB a month free, free egress; public files in production need a custom domain on Cloudflare (r2.dev is rate-limited, for testing). Supports PutBucketCors, multipart uploads and byte ranges.
- **Backblaze B2:** the first 10 GB free, $6.95 per TB a month, egress free only up to three times the data stored (then $0.01 per GB), unlimited to partner CDNs: a poor fit alone for a podcast, whose downloads run many times its size.
- **Browsers:** a single byte range (`bytes=0-499`) is a CORS-safelisted request header, so reading the start of an MP3 from elsewhere needs no preflight, only `Access-Control-Allow-Origin` on the answer.
- **Other offload plugins:** WP Offload Media and Advanced Media Offloader rewrite Media Library addresses with `wp_get_attachment_url` and can remove local files; WP Offload Media's own notes say most compatibility problems come from removing local files, since other plugins need them.
- **The test:** a hand-written PHP client (about 80 lines) against a local S3 server with signature checking: a 12 MB upload with the payload hash signed and with UNSIGNED-PAYLOAD (200), HEAD with size, type and cache header correct, a ranged read (206, right bytes), a 5 MB-part multipart upload (200, full size), PutBucketCors (200), delete (204, then 404), and a wrong secret (403). Without its own Content-Type the body went as a web form and the server read it as empty.

**Roadmap:** [1.23.0 Storage offload](../ROADMAP.md#1230-storage-offload).

Sources: [Apple Podcasts: podcast RSS feed requirements](https://podcasters.apple.com/support/823-podcast-requirements), [Apple Developer: configuring delivery](https://developer.apple.com/documentation/http-live-streaming/configuring-delivery), [Bunny Storage pricing](https://bunny.net/pricing/storage/), [Bunny CDN pricing](https://bunny.net/pricing/cdn/), [Bunny Storage HTTP API](https://docs.bunny.net/storage/http), [Bunny Storage S3 compatibility](https://docs.bunny.net/storage/s3), [Bunny CDN logging](https://bunny.net/docs/cdn/logging), [Bunny free trial](https://docs.bunny.net/quickstart), [Cloudflare R2 pricing](https://developers.cloudflare.com/r2/pricing/), [Cloudflare R2 S3 API compatibility](https://developers.cloudflare.com/r2/platform/s3-compatibility/api/), [Backblaze B2 pricing](https://www.backblaze.com/cloud-storage/pricing), [MDN: Range](https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Range), [WP Offload Media: compatibility with other plugins](https://deliciousbrains.com/wp-offload-s3/doc/compatibility-with-other-plugins/), [Advanced Media Offloader](https://wordpress.org/plugins/advanced-media-offloader)

## Sponsor slots

Checked on 18 November 2026 before 1.22.0: how hosts place self-run messages, the disclosure rules, how apps handle a changed episode file, and cutting an MP3 in the middle.

- **Self-run messages:** Buzzsprout's Dynamic Content takes a short audio file as a pre-roll, mid-roll or post-roll, up to three mid-rolls at once, suggests insertion points and lets the owner move, remove or add them; mid-rolls only on episodes of 23 minutes or longer; a replaced pre-roll or post-roll is kept in a library. One message can go on the whole back catalogue for a sponsor's period.
- **A changed file:** a Podnews test found that adding a post-roll on Buzzsprout changed the enclosure address while the GUID stayed, and Spotify fetched the new audio for listeners who had downloaded it. Apple asks for a GUID that never changes, even when the enclosure address does. A published test of the Apple Podcasts app found it remembers a file's length from its first range request, so new bytes at the same address can break playback.
- **Disclosure:** in Canada the Competition Act (the Competition Bureau) and Ad Standards' guidelines ask for any material connection to be disclosed clearly and close to the content, for each sponsored piece. Under the FTC's Endorsement Guides, a spoken endorsement needs a spoken disclosure; podcast guides add a written one in the notes. Google asks for paid links to carry `rel="sponsored"` (or `nofollow`). YouTube asks for its paid promotion declaration on a video with branded content.
- **The cut** (a test in the workspace with ffmpeg's LAME, speech-like phrases over a room near -50 dBFS, cut in the middle of a pause, a 3 s message put in): LAME used the bit reservoir in every frame, pauses included, reaching back up to 511 bytes (about two frames at 96 and 128 kbps, three at 64). The loudest sample in the 80 ms after the message: a plain cut -41 to -47 dBFS; with the frames before the cut copied again, muted, -49 to -52, the same as the original pause (-48 to -51). Decoded with ffmpeg, the reading after the cut was then identical to the original from one second on; Chrome's decoder (tests/browser/sponsors.py) gives identical samples from 50 ms on, the first moments differing by less than the room.

**Roadmap:** [1.22.0 Sponsor slots](../ROADMAP.md#1220-sponsor-slots).

Sources: [Buzzsprout: Dynamic Content](https://www.buzzsprout.com/help/154-dynamic-content), [Buzzsprout: mid-roll placements](https://www.buzzsprout.com/help/201-mid-roll-placements), [Podnews: does Spotify deliver downloaded episodes again if they change?](https://podnews.net/article/mythbusting-spotify-downloads), [Apple Podcasts: changing hosting providers](https://podcasters.apple.com/support/3965-how-to-change-hosting-providers), [Ad Standards: influencer marketing disclosure guidelines](https://adstandards.ca/programs/influencer-marketing/), [Google: qualify outbound links](https://developers.google.com/search/docs/crawling-indexing/qualify-outbound-links), [YouTube: paid product placements, sponsorships and endorsements](https://support.google.com/youtube/answer/154235)

## Member feeds

Checked on 18 November 2026 before 1.21.0: how the private podcast hosts and membership plugins give members a feed, and which podcast apps take one.

- **One address per listener, with a random key:** Patreon, Supercast, Castos, Transistor, Podigee and Paid Memberships Pro's Member RSS add-on each give every member a feed address with a long key, so one leaked address is turned off alone.
- **Apps:** Apple Podcasts (Follow a Show by URL), Overcast (Add URL), Pocket Casts (paste into search), Castro, Downcast, Podcast Addict, Podcast Republic and most others take such an address. Spotify takes no private feeds except through its own partner link-ups; Patreon lists Castbox, TuneIn and Amazon Music as not supporting authentication.
- **A username and password on the feed:** Pocket Casts does not formally support it and has limited support for basic authentication; Overcast's ping does not support feeds with basic authentication.
- **Keeping it private:** Overcast treats a feed with `itunes:block` (or credentials in its address) as private: out of search and recommendations, with no Share button. Pocket Casts reads the same tag; a feed added there without it once turned up in other people's searches. Overcast and Pocket Casts fetch feeds on their own servers.
- **Membership plugins' own calls:** Paid Memberships Pro `pmpro_hasMembershipLevel( null, $user_id )` and `pmpro_has_membership_access( $post_id, $user_id )`; Restrict Content Pro `rcp_user_has_active_membership( $user_id )` and `rcp_user_can_access( $user_id, $post_id )`; Memberful `memberful_wp_user_plans_subscribed_to( $user_id )` and `memberful_can_user_access_post( $user_id, $post_id )` (all three read in their published source); WooCommerce Memberships `wc_memberships_get_user_active_memberships( $user_id )` and `wc_memberships_user_can( $user_id, 'view', array( 'post' => $id ) )` (its function reference); MemberPress `MeprUser` (`is_active()` or `active_product_subscriptions()`), with `MeprRule::is_locked_for_user()` used only where it exists.

**Roadmap:** [1.21.0 Member feeds](../ROADMAP.md#1210-member-feeds).

Sources: [Pocket Casts: password protected podcast feeds](https://support.pocketcasts.com/knowledge-base/password-protected-podcasts/), [Pocket Casts: private, paid or members-only feeds](https://support.pocketcasts.com/knowledge-base/private-or-members-only-feeds/), [Overcast: podcaster info](https://overcast.fm/podcasterinfo), [Patreon: which podcast apps work with the RSS link](https://support.patreon.com/hc/en-us/articles/115005429546), [Podigee: which apps support protected podcasts](https://help.podigee.com/article/452-which-podcast-apps-support-protected-podcasts), [Paid Memberships Pro: Member RSS](https://paidmembershipspro.com/add-ons/pmpro-member-rss), [Restrict Content Pro: rcp_user_can_access](https://restrictcontentpro.com/?p=12253), [WooCommerce Memberships function reference](https://woocommerce.com/document/woocommerce-memberships-function-reference/)

## Audiobook export

Checked on 18 November 2026 before 1.20.0: ACX's and Spotify for Authors' published rules, and the two real readings on a site, decoded and measured in a laptop's Chrome.

- **ACX (Audible, Amazon, Apple Books):** one chapter or section per file with its heading read aloud, no file over 120 minutes; opening credits (title, author, narrator) and closing credits ("The end" at least) as separate files; a retail sample of 1 to 5 minutes that starts with narration and holds nothing explicit; -23 to -18 dB RMS, peaks at or under -3 dB, a background at or under -60 dB RMS; MP3 at 192 kbps or more, constant bitrate, 44.1 kHz; all mono or all stereo; plain letters and numbers in file names; a square cover of 2400 by 2400 or more. Room tone: no more than 5 s, 1 to 5 s recommended at each end; the older rule of 0.5 to 1 s at the start is no longer on the page. Recorded room sound, not digital silence.
- **Who ACX takes:** rights holders in the US, UK, Canada and Ireland, for a book already on Amazon as a Kindle ebook. Spotify for Authors is free, takes MP3, WAV or FLAC and leaves the book free to go elsewhere; Authors Republic and Findaway use ACX's numbers.
- **Two real readings:** a 4:07 reading at 96 kbps had 0.28 s before its first word and 0.66 s after its last, -18.0 dB RMS, a highest sample of -1.1 dB, a quietest half second of -63.8 dB and 70 pauses of 0.3 s or more (median -55 dB, quietest -62). A 14:13 reading with the music bed had 0.10 s and 0.48 s, -19.4 dB RMS, -2.0 dB peaks, a quietest half second of -56.9 dB and no pause at all under the music.

**Roadmap:** [1.20.0 Audiobook export](../ROADMAP.md#1200-audiobook-export).

Sources: [ACX audio submission requirements](https://help.acx.com/s/article/acx-audio-submission-requirements), [What is ACX](https://help.acx.com/s/article/what-is-acx), [Spotify for Authors: uploading audiobooks](https://support.spotify.com/us/authors/article/uploading-audiobooks/), [Authors Republic: understanding audio requirements](https://www.authorsrepublic.com/learn/blog/57/understanding-audio-requirements)

## Stronger noise cleanup

Researched before 1.16.0, on two real phone takes that RNNoise did not quiet: one with a bell ringing in the background (steady tones at about 1.6, 4.7, 5.2, 5.9, 8.9 and 11 kHz, about 35 dB under the voice), and one with a creaking chair (mostly rumble under 100 Hz, plus a broad hiss, about 30 dB under the voice). Both were about -47 to -50 LUFS as recorded.

**The test bench**
- RNNoise exactly as the studio runs it, and DeepFilterNet 3 (MIT or Apache-2.0) from the official ONNX files, ported to Python and to browser JavaScript from libDF and checked against each other. Both takes leveled to about -23 LUFS first, and every version passed through the 80 Hz high-pass before measuring.
- Measures: the voice's level, soft speech, the gaps between words, the bell's tones in the gaps and under the voice, and Whisper base.en's words for each version. Then 30-second clips of each version, matched in loudness and lettered, for a blind listening.

**What was found**
- **Size and speed:** 8.6 MB of model, no new runtime (ONNX Runtime Web, already used by Whisper). In headless Chrome on one thread, 8% of the audio's length with a plain FFT, 6% with a planned one; RNNoise took 3.5%.
- **The bell:** DeepFilterNet lowered it 21 dB in the gaps and 14 dB under the voice, against RNNoise's 16 and 8. Whisper heard RNNoise's version as "the call of the scene" and "the waves of the scenes"; after DeepFilterNet it heard "sea" both times.
- **The chair:** the two measured alike (about 9 to 10 dB off the gaps, the voice unchanged). In the blind listening, the chair and mouth sounds were plain after RNNoise and gone after DeepFilterNet at full strength; with a 20 dB cap some came back as "subtle wet mouth sounds". The measures miss mouth noise and short creaks; the ears did not.
- **Soft edges:** DeepFilterNet lowered the soft edges of words a little more (1.6 s against 0.7 s of 48 s of speech by more than 10 dB). The listening heard no harm ("great voice" both times).
- **Level matters to it:** on the raw take at about -47 LUFS it treated the first 13 seconds, title included, as background. On the leveled take it did not. RNNoise behaved the same at either level.
- **Chunks:** 10-second chunks with 2 seconds of warm-up matched one pass over the whole take, apart from differences far below the noise in near-silent stretches.

**Roadmap:** the stronger cleanup ([1.16.0](../ROADMAP.md#1160-the-stronger-cleanup)), with the engine shipped in the plugin, a lift before the model, and Strong as the amount to use with it.

Sources: [sherpa-onnx](https://github.com/k2-fsa/sherpa-onnx), [the streaming Zipformer 20M model](https://huggingface.co/csukuangfj/sherpa-onnx-streaming-zipformer-en-20M-2023-02-17), [Moonshine](https://github.com/moonshine-ai/moonshine), [Vosk browser](https://github.com/ccoreilly/vosk-browser)

## Every browser and phone

Researched on 19 November 2026 before 1.29.1, 1.30.0 and 1.31.0: the plugin at 1.29.0 in Chrome 153, Firefox 160 and WebKitGTK 2.52 on Linux, and a check page run on a reader's iPhone (Safari 26.6.1) and a Windows laptop (Chrome 157 and Firefox 160).

- **Firefox's H.264 encoder** sends frames out of display order (B-frames) in High and Main profile on Linux and Windows (42 of 90 frames out of order on the laptop); Baseline comes out in order. mp4-muxer 5.2.2 refused each reordered piece inside the encoder's callback, so files held about a third of their frames while the page said they were ready (fixed in 1.29.1).
- **AAC:** no AAC encoder in Firefox on any system, nor in any browser on desktop Linux; Chrome and Edge on Windows and Mac, and Safari 26, have one. Mediabunny with `@mediabunny/aac-encoder` made a correct video in Firefox: High profile, every frame, AAC-LC stereo at 48 kHz, both tracks starting at 0. Mediabunny writes an edit list when a track's first piece is not at 0 (an AAC encoder's first piece starts 1,024 samples early); handing the samples over that much later writes none.
- **Safari 26** (Mac, iPhone, iPad) has WebCodecs with AAC and `createWritable`; on the iPhone, H.264 High in order on the graphics chip, a square clip at 8.2 times real time and 1080p at 3.7 times, private storage up to 4.1 GB, WebGPU with half precision, no element full screen. Its user agent reports "iPhone OS 18_7" on iOS 26.6.1.
- **Memory on the iPhone:** Safari closed the page at about 1,168 MB of WebAssembly memory. A full review of a 1:53 take with the standard model finished on the phone; a 14-minute take at full rate is about 160 MB per copy, and the studio keeps several.
- **The larger model in Firefox on Windows** passed the graphics chip check and took about 4.7 times the take's length (Firefox was on the laptop's integrated chip).
- **The studio's whole path** (Open a recording, cleanup, leveling, the MP3, the review with the tests' stand-in Whisper, the shelf) ran in Firefox and WebKitGTK with no script errors.

**Roadmap:** 1.29.1 (the faults), [1.30.0 Every browser](../ROADMAP.md#1300-every-browser) and 1.31.0 Every phone.

Sources: [MDN: AudioEncoder](https://developer.mozilla.org/en-US/docs/Web/API/AudioEncoder), [MDN: WebCodecs API](https://developer.mozilla.org/en-US/docs/Web/API/WebCodecs_API), [Mediabunny](https://mediabunny.dev/), [@mediabunny/aac-encoder](https://www.npmjs.com/package/@mediabunny/aac-encoder), [mp4-muxer](https://github.com/Vanilagy/mp4-muxer)

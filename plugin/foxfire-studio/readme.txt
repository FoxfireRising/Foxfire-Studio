=== Foxfire Studio ===
Contributors: FoxfireRising
Tags: teleprompter, podcast, audio, recording, accessibility
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.36.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A private studio for reading your posts aloud: a teleprompter, podcast-ready recording, a Whisper review, editing, a player and listener stats.

== Description ==

Foxfire Studio turns any post into a script you can read aloud and record. Pick a post, read it while each word lights up in turn, and get back a clean, podcast-ready MP3 with an honest review of how the reading went. Fix any stumbles by editing the words, without recording the whole post again. Then put the reading on the post, where visitors get a listening player. Formerly Reading Room Teleprompter.

Only logged-in users who can edit posts can open the studio. Recording, cleanup and the Whisper review all run in the reader's own browser, and a recording reaches the site only when the reader chooses to put it there.

= Reading =

* Shows any post as clean, large text: title, headings and paragraphs only.
* Lights up each word karaoke style, timed by syllables with beats at punctuation, or following your voice as you read, with a small speech model on your own device.
* Cue marks: pauses, breaths and emphasis marked on the script, saved with the post without changing its text.
* Pronunciation notes: a short note such as "SOW-in" shown small above a word, for one spot, every time the word appears in a post, or in every post.
* Practice tools: a three minute warm-up, a microphone check, an opening drill and a focus for each take.
* A font switch for the script, adjustable size, width and reading line, and full keyboard control.
* An optional intro video when the studio opens.

= Recording =

* Records you while you read, losslessly, and saves the take as you go so a crash never loses it.
* Cleans up the recording: mouth clicks, stray sounds, whistling S sounds, and background noise lowered by a small speech model.
* Masters it to a podcast loudness standard (or Audible's ACX rules) and hands back a 128 kbps MP3 tagged with the title, author, show name, year, a link to the post, the featured image as artwork, and chapters at the post's headings.
* A music bed: quiet music you own under a reading, dipping under the voice, for the whole show or per post.
* Send to my computer: a take recorded on a phone waits on the site, private to you, and opens on your computer for the full review.
* Phone take mode: on a phone, each take goes to the site in pieces while you record, with a large level meter on screen, so a closed page loses nothing; a take cut off partway can be carried on.

= Review and editing =

* A short read-out after each take: pace, pauses, phrase endings, pitch and room noise.
* Whisper review: the open-source Whisper model runs in your browser, compares your reading with the script word by word, and opens a review studio with every slip marked, click-to-play, take comparison and a captions download.
* Teach it words it mishears, for every post or just one. A larger Whisper model can be chosen on faster computers.
* Text-based editing: cut words, fillers and long pauses by selecting them in the script, or record a sentence again and splice it in. Tidy suggests what to cut. Make edited MP3 builds the new file with captions and chapters moved to match.
* Flags after edits: a flagged moment your edits cover is marked straight away, and Check the edited version has Whisper listen to the edited recording and flag it again.
* Share for feedback: a friend with an account on your site hears a take with the script and leaves comments pinned to words, for 14 days.
* Use this recording puts the finished MP3 and its captions on the post in one click.

= For listeners =

* A listening player on each post with a reading: skips, speed, chapters, read along, resume, links that start at a moment, and lock screen controls on phones.
* A podcast feed of every reading, with cover art, category and each reading's captions as its transcript, ready for Apple Podcasts and Spotify, plus the Podcasting 2.0 extras: chapters, a soundbite, the host, a support link and pings to Podcast Index. Off until you switch it on.
* Clips for social media: a moment of a reading as a short captioned video in three shapes, with a tagged link for each clip.

= Stats, insights and reports =

* Listener analytics: what visitors do with each reading (plays, parts heard, speed, skips, where they stop), where they came from, device and country. Measured on your own site with no cookies, no outside services and no IP addresses stored. Off until you switch it on.
* A Foxfire Studio dashboard: the listening curve for each reading with the sentence playing where listeners leave, sources and a tagged link builder, and your own progress with goals and a practice log.
* Insights: plain sentences about what lines up with readings heard to the end, each saying what it rests on and quiet until there is enough data.
* A report by email, monthly by default, with a preview and an archive.

= Your data stays on your site =

The plugin has no telemetry and sends nothing to its authors. Every take, the words you have taught Whisper and your listener numbers are kept in your own database. Fonts, the MP3 encoder, the noise model and Whisper can all be downloaded to your site from Settings, so the studio makes no outside requests at all. See "External services" below.

== Installation ==

1. In WordPress, go to Plugins, then Add New Plugin, then Upload Plugin.
2. Choose foxfire-studio.zip, then Install Now, then Activate.
3. Go to Foxfire Studio, then Settings (the same screen as Settings, then Foxfire Studio). Pick a look, check the starting values and save.
4. Optional: on the same screen, click Download Whisper to this site, and under "Fonts, MP3 encoder and noise model on this site", Download to this site.
5. Open the studio from Tools, then Teleprompter, or the Teleprompter link under any post in the Posts list.
6. Foxfire Studio, then Help has a short guide to each part, starting with Make your first reading. Every Foxfire Studio screen has a Help link beside its heading, and the studio's Controls panel has Help and guides at its foot.

To update, upload the new zip the same way and choose Replace current with uploaded. Settings and data are kept.

= Upgrading from Reading Room Teleprompter =

The plugin has a new name and folder since 0.11.1, so WordPress installs Foxfire Studio beside the old copy.

1. Upload foxfire-studio.zip and choose Activate Plugin. Foxfire Studio switches the old copy off and takes over its settings, readings, stats and self-hosted files.
2. Then delete Reading Room Teleprompter from the Plugins screen. While Foxfire Studio is active, deleting the old copy keeps all of your data.

Each reader's remembered settings, takes and taught words carry over, and Whisper files saved by older versions are found and used. Settings from the old MAKE IT YOURS block of the theme template are not read any more; set the same choices in Settings.

== Frequently Asked Questions ==

= Where is the help? =

Foxfire Studio, then Help: short guides to each part of the plugin, with a search box. Each Foxfire Studio screen links to the guide that fits it, beside its heading and in WordPress's Help tab, and the studio's Controls panel links to them too. Most controls in the studio also have a ? button that explains them.

= Is it accessible? =

The player, the studio and the admin screens aim to meet WCAG 2.2 AA, checked with automated and keyboard tests: every control can be reached with the keyboard and is named by its own words, the reading has a transcript, focus always shows, and Settings checks your colours and can fix them. The studio aims for the same since 1.27.0: its keys act on the script, every control keeps its own keys, dialogs take and give back focus, words can be reached with a word cursor, and help that was only in tooltips has a ? button. docs/ACCESSIBILITY.md in the repository lists what is covered, how it is tested and the known gaps.

= Who can open the studio? =

Logged-in users who can edit posts, and only for posts they may edit. Visitors never see it, and it asks search engines and page caches to leave it alone. The listener screens, reports, settings and podcast feed are for administrators.

= Are my recordings sent anywhere? =

No. Recording, cleanup, the MP3 and the Whisper review all happen in the reader's browser. A recording reaches your site only when the reader presses Use this recording, shares a take for feedback, or sends a phone take to their computer. Follow my voice runs a small speech model in the reader's browser too (since 1.14.0); the audio it hears is not kept or sent anywhere.

= Which browsers does it work in? =

Reading, timed pace, recording and the MP3 work in current Chrome, Edge, Firefox and Safari. The Whisper review works in all four (more slowly in Safari). Clips and full-length videos work in Chrome, Edge, Safari and Firefox on a computer, with AAC sound in each (Firefox gets it from an AAC encoder that ships with the plugin), and Safari on an iPhone or iPad has every tool they need. Settings and the studio say what the browser in use can do. Follow my voice is tested in Chrome and should work in the others and on phones, with timed pace taking over if a device cannot keep up. On phones, the review waits to be asked for, since a phone can close the page while it runs; phone take mode keeps each take on the site for the review on a computer.

= How do I put a reading on a post? =

After a take, press Use this recording in the review studio. Or upload an MP3 yourself and choose it in the post's Reading audio box. If your readings are already in another custom field, enter its name under Settings, Listening player.

= Is listener analytics allowed under privacy laws? =

It is built to need as little as possible: no cookies, no IP addresses or user agents stored, a listener id that changes every day, Do Not Track and Global Privacy Control respected, and nothing sent to other services. Some laws still treat a daily id as personal data, so the settings screen gives you a paragraph for your privacy policy. Switch analytics on only after adding it. This is not legal advice.

= Does it work with page caching and optimisers? =

Yes. The studio and share pages are never cached, and the player is written to survive strict optimisers. After updating, empty the page cache and check a post while logged out.

= My site is behind Cloudflare or another proxy. =

List the proxy under Settings, Listener analytics, Trusted proxies, so each listener's own address and country are used. Until then every listener looks as if they came from the proxy.

= Can it make no outside requests at all? =

Yes. Download Whisper (and the larger model, if readers use it), the fonts, the MP3 encoder and the noise model to your site from Settings. Every copy is checked against a known fingerprint before it is saved. The country database is always kept on your site.

= What happens when I delete the plugin? =

Its settings, scheduled jobs and the copies of fonts, encoder, noise model and country database are always removed. Your stats, each post's reading links, cue marks, kind of reading, pronunciation notes, shared takes and the Whisper files stay for a reinstall, unless you tick "When the plugin is deleted, also delete everything else it keeps" under Settings, Reader stats first. MP3s and captions in the Media Library are never deleted. Updating with Replace current with uploaded never deletes anything.

= Can it be translated? =

Yes. Every word it shows goes through WordPress's translation functions, and foxfire-studio.pot is in the plugin's languages folder. Put a translation in that folder or in wp-content/languages/plugins.

= Can developers change it? =

The final settings pass through the rrt_config filter, and the player can be placed with the Reading player block, the [foxfire_player] shortcode (the older [reading_room_player] still works) or rrt_player() in a theme. Other filters are listed in the code and in docs/ARCHITECTURE.md in the repository.

== External services ==

The studio works on its own, but until you download these files to your site from Settings, the teleprompter page loads them in the reader's browser from the services below. Each request reveals the reader's IP address and browser to that service, as any web request does. None carries a recording or the post's text. Visitors' browsers load nothing from any of them: the listening player and analytics load from your site only. The optional Google Analytics link, below, is the one way a visitor's browser can report to an outside service, and only through a tag your site already loads.

= Google Fonts =

The studio's and share page's fonts, from fonts.googleapis.com and fonts.gstatic.com, when the font link in Settings is set and the fonts have not been downloaded to your site. Google Fonts privacy: https://developers.google.com/fonts/faq/privacy. Google's privacy policy: https://policies.google.com/privacy. Terms: https://developers.google.com/fonts/terms.

= jsDelivr =

The MP3 encoder (LAME, from wasm-media-encoders), the noise model (RNNoise, from @jitsi/rnnoise-wasm) and Whisper's code (transformers.js and ONNX Runtime Web), from cdn.jsdelivr.net, when they have not been downloaded to your site. Each file is checked against a known fingerprint before it is used. Privacy policy: https://jsdelivr.com/privacy-policy-jsdelivr-net. Terms: https://jsdelivr.com/terms/terms-of-use.

= Hugging Face =

The Whisper speech model, and the larger model when a reader chooses it, from huggingface.co, when they have not been downloaded to your site. Privacy policy: https://huggingface.co/privacy. Terms: https://huggingface.co/terms-of-service.

= Google Analytics =

Only when an administrator switches on Settings, Google Analytics (off by default), and only on pages that already load a Google Analytics tag: the player passes three events (a reading started, reached halfway, heard to the end) with the reading's title to that tag in the visitor's browser, which sends them to Google. The plugin adds no Google Analytics tag of its own. Google's privacy policy: https://policies.google.com/privacy. Terms: https://marketingplatform.google.com/about/analytics/terms/us/.

= DB-IP =

When listener analytics is on, your server downloads DB-IP's free IP to Country Lite database from download.db-ip.com, when an administrator presses the download button and then once a month. Countries are looked up on your own server; no visitor's address is ever sent to DB-IP. The database is licensed under CC BY 4.0: https://db-ip.com/db/download/ip-to-country-lite. IP Geolocation by DB-IP.

= Podcast Index and WebSub =

Only when you switch on Pings in Foxfire Studio, Podcast feed (off by default, and only while the feed is published): when a published post gets a reading, your site sends the podcast feed's address, and nothing else, to Podcast Index (https://podcastindex.org, its terms: https://podcastindex.org/terms) and to a WebSub hub, by default Google's public hub at https://pubsubhubbub.appspot.com (Google's privacy policy: https://policies.google.com/privacy), at most once every 10 minutes, so podcast apps learn of new episodes sooner. Nothing about visitors or listeners is sent.

= Speech recognition in the browser =

Follow my voice runs a small speech model (sherpa-onnx, Apache-2.0, shipped with the plugin) in the reader's browser; the microphone audio goes only to it, inside the page. Its model loads from Hugging Face until it is downloaded to the site in Settings; that request carries no audio. Hugging Face's privacy policy: https://huggingface.co/privacy. Until 1.13.0 it used the browser's Web Speech API, which in Chrome and Edge sends the audio to Google or Microsoft.

The faster review (1.17.0) runs the Whisper review on the reader's own graphics chip where the browser can, with the larger model's graphics chip files from this site when they have been downloaded to it, otherwise from Hugging Face; that request carries no audio, and the audio goes nowhere.

Member feeds (1.21.0), when switched on, give each member a private feed address made of a random code kept with their account; the feed contains nothing about them. A reading for members is kept on your own site in a folder visitors cannot open. Feed activity, off by default, keeps for each member the last fetch, the app's name and a count of networks in the last 7 days, with no IP address, for 30 days.

Download counting (1.2.0, to the IAB guidelines since 1.24.0), when switched on, counts podcast apps' downloads by app, kind of device and day without storing IP addresses or user agents; addresses are checked against a list of data centre and VPN networks this site downloads once a month (nothing about visitors is sent to get it); members' downloads are kept only as totals. A measurement prefix such as OP3, if you add one, sees listeners' requests before your site does.

Storage offload (1.23.0), when switched on, has podcast apps (and, unless you choose otherwise, the player) fetch the readings from the storage or CDN you chose, whose servers then see listeners' IP addresses and apps as any web server does. Files for members and everything private stay on your site, and the plugin sends the storage nothing about listeners.

Sponsor messages (1.22.0), when switched on, place your own recorded messages in copies of your readings for the podcast feed. Every listener gets the same file; nothing about listeners is used or sent anywhere.

Audiobook export (1.20.0) makes every file in the editor's own browser and downloads them as one ZIP; nothing of it is kept on the site or sent anywhere. Audiobook copies of readings and a collection's recorded credits are kept on your own site, in a folder visitors cannot open.

Collections (1.19.0) are joined on your own site from readings already on it; the joined MP3, its captions and any reading matched for it are kept in the Media Library as the collection's own files. Matching measures and remakes a reading in the editor's own browser and sends it only to your site.

Full-length video (1.18.0) is made in the editor's own browser from the reading already on the post, kept in that browser's private storage until it downloads, and sent nowhere.

The stronger cleanup (1.16.0) runs DeepFilterNet 3 (MIT or Apache-2.0, shipped with the plugin) in the reader's browser, after the reading; the audio goes nowhere. It uses the same ONNX Runtime files as the Whisper review, from this site when Whisper has been downloaded to it, otherwise from jsDelivr; that request carries no audio.

Embedded players (1.35.0), when switched on, make this site ask YouTube or SoundCloud once for each video's or track's title and picture, and keep the picture in uploads/rrt-embeds. Visitors' browsers contact those services only after pressing play.

The full list of what the plugin stores and sends is in PRIVACY.md in the repository: https://github.com/FoxfireRising/Foxfire-Studio/blob/main/PRIVACY.md

== Changelog ==

= 1.36.1 =
Small fixes. When Whisper mishears the very first or last word of a script and the word is three letters or fewer ("end" heard as "and"), the review shows it as a misread word, where it had listed the heard word as added and the script's word as not reached. With Follow my voice on, a misheard last word no longer leaves the reading waiting: once a word is heard where only the last word is left, the script ends when no new word has come for a moment, and the take finishes by itself as usual. In the studio's Controls panel every switch, slider and menu now lines up, with each "?" in a column of its own. The studio's background workers (the MP3 encoder, the noise cleanup, the sound worker, voice following and the stronger cleanup) now get a new address whenever their file changes, so a phone that keeps files for a long time fetches the new one. Nothing else changes.

= 1.36.0 =
Fuller help. Foxfire Studio, Help now holds 28 guides in eight groups (getting started, recording and reviewing, finishing and publishing, video, podcast and audiobooks, listening numbers, your site's setup, and when something goes wrong), with a Start here guide that gives site owners and readers each a path to follow. New guides cover Settings section by section, finishing the sound, captions and chapters, recordings and waiting takes, kinds of reading, sharing for feedback, member feeds, sponsor messages, download counting and storage offload, who can do what, what listeners get, and updating, backing up or removing the plugin; the others say much more, with steps. Each guide is divided into parts, every plugin screen opens the guide or part that fits it (Settings and Recordings had opened the wrong ones), and each section of Settings, Podcast feed and Recordings has a Help with this section link under its heading. The studio's Help and guides link opens the recording guides. Nothing else changes.

= 1.35.0 =
YouTube and SoundCloud players that load on play. A YouTube or SoundCloud player loads all of its files, and sets its cookies, as soon as a visitor scrolls near it. With the new Settings, Embedded players switched on for a service (each is off by default), every one of its players in posts, pages and block widgets shows as a picture of the video or track with a play button, at the size the player will take, and the real player loads only when a visitor presses play, then starts at once. YouTube videos play from YouTube's privacy-enhanced address, with their start time and settings kept. The site fetches each picture and title once and keeps the picture in its uploads folder, so visitors contact neither service before they press play. The play button works with the keyboard, and without JavaScript the panel is a plain link to the video or track. One thing plays at a time: a video or track pauses the reading player, and the reading player pauses them. Nothing in the posts changes, so switching off brings the ordinary players back; the editor, feeds and the REST API always get them. Clear saved pictures, and pictures not shown for 90 days go by themselves. Nothing in the database changes.

= 1.34.0 =
The take shelf across computers. Every reviewed take goes on its post's shelf in the browser, for Combine takes and Continue. With the new Settings, Take shelf switched on (it is off by default), each person's shelf is also kept on the site, so it follows them to any computer or browser they sign in on. A take reviewed on one computer is listed on the others at once, so Continue picks up where it stopped and Combine takes counts it; its recording comes from the site the first time Combine takes needs it, checked sample for sample. Recordings go up after each review as lossless FLAC, about half the size of a WAV, in pieces, and carry on after a reload if they are cut off. Phones send their short takes and never download recordings. The site numbers the takes, keeps the newest six a post for 30 days each, and keeps the Combine takes plan; Remove and Clear the shelf work on every computer. Settings shows the space used, with a limit (2 GB by default) and Delete every shelf on the site; Foxfire Studio, Recordings lists your own takes there. The recordings are kept in a private folder and served only to their owner, and are part of WordPress's personal data export and erase tools. The practice reading's shelf stays in the browser. Nothing in the database changes.

= 1.33.1 =
The practice reading is easy to find again. The studio's Controls panel has Practice reading under the Practice heading, beside Warm up and Check microphone, and it opens the one-minute practice script in the same tab. The studio's first screen keeps an Open the practice reading link whenever New here is not showing, and Foxfire Studio, Your progress has a button to it. The help guides say where it is. Nothing in the database changes.

= 1.33.0 =
Other languages. Each post can be read in any language Whisper knows that is written with spaces between words: choose it in the post's Reading audio box, under Reading language (otherwise it is the site's language). Readings in any language but English are reviewed by Whisper's multilingual models with that language set, so Whisper writes down what it hears and never translates it; English readings keep the English models and are reviewed exactly as before. The review compares words in every script, knows each language's common filler sounds, marks a number heard as digits against one written in words as worth a look, and judges the pace against your own once you have three takes in that language. How hard counts long words by their letters outside English and leaves German nouns alone. Words taught to Whisper are kept per language. The feed, captions, player transcript and MP3 tag carry the reading's language. Settings has a download for the other-languages models. Follow my voice stays English only for now. Chinese, Japanese, Thai and the other languages without spaces between words come later. No database change.

= 1.32.0 =
Help and docs. A new Help screen (Foxfire Studio, Help) has short guides to each part of the plugin, from a first reading to the podcast feed, with a search box and a What's new list. Every Foxfire Studio screen links to the guide that fits it, beside its heading and in WordPress's Help tab, and the studio's Controls panel has Help and guides at its foot, opening in a new tab. Nothing in the database changes.

= 1.31.0 =
Every phone. Whether the studio treats a device as a phone is now decided by how it is used (touch first) and how much memory it says it has, never by the browser's name, so iPads, Android phones in any browser and iPhones all get the same careful path, and the clip maker follows the same rule. The Model menu shows on every device; on a phone or tablet Automatic means the standard model, and Larger can be chosen by hand. On a phone, takes up to five minutes are reviewed by themselves, and the review closes voice following before Whisper listens (and for a longer take, lets go of its untrimmed copy), so takes have more room; longer takes still offer Send to my computer first. Short phone takes go on the shelf, so Continue works there; Combine takes stays a computer tool. The practice reading and a browser's first take say that the timed pace does not wait for you, and New here offers Follow my voice as a tick. On a phone the full-length video's card says where the file is, in place of a player Safari would not play. The Controls panel no longer slides sideways in Safari on a phone. In the review, the Pace line now gives the same words a minute as the At a Glance tile, and stray sounds a moment apart are listed once. No database change.

= 1.30.0 =
Every browser. Clips and full-length videos are now written by Mediabunny, which replaces mp4-muxer, so every browser makes them in H.264 High profile, including Firefox, whose encoder sends frames out of order (1.29.1 fell back to the plainer Baseline there). In a browser with no AAC encoder of its own (Firefox on every system, Chrome on Linux), an AAC encoder that ships with the plugin (FFmpeg's, as WebAssembly) is loaded for the sound, so Instagram, Spotify and YouTube get AAC from every browser. The sound's start is placed so the file needs no edit list (Spotify's and Instagram's specs refuse them); it plays at most about 21 ms after the picture. Messages about a browser that cannot make clips or the video now name the missing tool and the browsers that have it, and Settings (Whisper, This browser) and the studio (Controls panel, Recording) say what the browser in use can do. In the studio, Automatic times the larger model on the graphics chip during a review: where it hears slower than the reading, the standard model finishes the review and is used in that browser from then on (choosing Automatic again in the Model menu tries the larger model afresh). The practice reading always records (Record my voice is on for that page, without changing the browser's own setting), and the Combine and Continue tooltips speak of this reading. No database change.

= 1.29.1 =
Fixes. Clips and full-length videos made in Firefox lost about two thirds of their frames while the page still said the video was ready: Firefox's H.264 encoder sends frames out of order in its usual settings, and the MP4 writer dropped each one. The clip maker and Make the video now try each setting on a few frames first and use the first that sends frames in order (in Firefox, Baseline), and a file missing any frame is never finished. The review no longer flags numbers that were read as written: Whisper writes "1200" and "14th" where the script says "twelve hundred" and "the fourteenth", and now these match, as do years, "a hundred" and percentages, and digits in a script read as words. A restart at the start of a reading no longer counts as a pause inside the title. The practice reading's messages no longer speak of a post.

= 1.29.0 =
The setup wizard. A new site is offered setup by one notice on the Plugins screen (Set up, or Not now): a few short steps, each saved with Next, for the show's name, the look (with a sample of each preset and the Readability check), which posts can have readings and where the player goes, privacy (keeping takes on the site, and one button to download Whisper, the MP3 encoder, the noise model and the fonts so the studio makes no outside requests; everything that touches visitors stays off and is listed with where to switch it on), and a first practice reading. The practice reading opens the studio with a short built-in script and Check microphone first; it belongs to no post, and its take is kept with your other takes so Your progress has a starting point. Anyone opening the studio for the first time in a browser is offered the practice reading once. Sites that had the plugin before are never offered setup; Settings has Run setup again at the top for everyone, and each step shows what the site uses now, so going through it changes nothing you do not change.

= 1.28.0 =
The Reading player block. In the block editor, the + button and "reading" (or listen, audio, podcast) finds Reading player: it plays the post's reading wherever it is placed, and Another reading in its sidebar picks any post or collection with a reading, for a page such as Listen or the home page. On block themes it can go in the Site Editor's Single Posts template (each post's own reading, above the title or anywhere the theme allows) or in a Query Loop, and in a widget area it plays the reading of the post being viewed. The editor shows the player as visitors will see it; a post without a reading yet shows Open the studio. A new shortcode, [foxfire_player], with post="123" for another reading; [reading_room_player] keeps working, and either turns into the block when pasted. With Where set to the top of the post, a post (or its template) that places the player by hand now shows it once, where it is placed, and the same reading never shows twice on one page. Starting one player pauses any other on the page. Read along is offered on the reading's own post; elsewhere Transcript does the same job. The preview note for editors shows wherever the player is placed.

= 1.27.0 =
Accessibility for the studio, checked against WCAG 2.2 AA. The teleprompter's keys now act only on the script, so Space ticks a switch, the arrows move a slider and every button presses as expected; Escape from any control goes back to the script, and Page Up and Page Down still work from anywhere for presentation clickers. While reading, a control with focus comes back to full strength. Single-key shortcuts (P, B, the full stop, plus, minus, [ and ]) act only on the script, and a new Single-key shortcuts switch in the Controls panel turns them off. Every dialog takes focus, keeps Tab inside, closes with Escape where nothing is lost and gives focus back. A word cursor: with Cues on, left and right move a word and Enter opens its cues; in the review the script is one Tab stop where the arrows move word by word, Enter plays from the word, Shift and the arrows select and Delete cuts. Controls that explained themselves only on hover have a ? button. Recording, the reading being ready and the review being ready are read out. Shaded sentences in the review carry a text tag (fast, slow). Words ahead of the reading line are held at 3 to 1 or more.

= 1.26.0 =
Accessibility for the player and the admin screens, checked against WCAG 2.2 AA. The player's panel is now solid, so it reads clearly on light themes as well as dark ones. Its buttons say what they do (Back 15s, Ahead 30s, Speed 1×) and are named by those words for voice control; a new speed is read out. A Transcript button opens the reading's captions as lines that play from their moment, on posts and collections. Read along keeps the post in its own colours, with a tint and an underline. Every control shows a clear focus ring, and the progress bar is easier to see and touch. Settings has a Readability check under Colours, with Make it readable to fix only the colours that fall short. Readings without captions say so. Labels for six admin fields and the Reading audio box's buttons. The studio's keyboard use follows in 1.27.0.

= 1.25.0 =
The security review, the first milestone of Phase 3. A reading's file is now checked before the short download address, storage offload, collections or sponsor messages use it: it must be a listening file in uploads, outside the plugin's private folders and outside any folder whose .htaccess turns visitors away, and a file kept for members is only ever its own post's. The plugin's own fields (the reading, its captions and Read by) no longer appear in the Custom Fields panel; the Reading audio box sets them. The short address hands files to the web server only while Count downloads is on. Sponsor messages can be made, recorded and published only by Administrators (the rrt_spot_capability filter can give them to Editors). Readings kept for members move into a folder with a random name of their own (their addresses stay the same), and Podcast feed has a Check private folders button that asks for a test file in each private folder from your browser, with the lines nginx needs. A reading set to Members only or taken off publishing loses its sponsored copies at once. Share for feedback lists everyone only for Editors and Administrators; Authors and Contributors can share with Editors, Administrators and people who shared with them, and anyone can send at most 20 invitations a day. Output escaping and coding-standard tidying throughout.

= 1.24.0 =
Download counting to the IAB guidelines. Count downloads (Foxfire Studio, Podcast feed) now follows the IAB Tech Lab Podcast Technical Measurement Guidelines, version 2.3: a download counts once per podcast consumer (address and app, or a member), episode file and day, once the app has asked for the file's tags and a minute of audio, with the pieces it asks for added together; test requests, Apple Watch duplicates, bots and bot libraries (the open OPAWG list, with OP3's further bots), data centre and VPN addresses (X4BNet's list, refreshed monthly) and busy addresses are left out. After counting, your web server sends the file itself (LiteSpeed, Apache with X-Sendfile, or nginx with two lines of setup), so every piece an app asks for is seen; Check counting in the new Download counting section tries it, and a redirect stays wherever it does not pass. Members' feeds are counted too, as totals. The Overview shows podcast consumers a day, apps and devices by name, unusual days and a twice-yearly review line; each sponsor message shows how often it was delivered. Make the method page adds a draft page describing the method from your settings. Optional measurement prefixes (OP3 with one tick, or others) go in front of the public feed's episode addresses. Counts from before stay, labelled. Database version 8.

= 1.23.0 =
Storage offload. Foxfire Studio, Podcast feed has a new Storage offload section: the readings podcast apps and the player download (each reading's MP3 and captions, sponsored copies and collections) can be served from Bunny Storage, any S3-compatible storage (Cloudflare R2, Backblaze B2, Wasabi, Amazon S3 and others) or a CDN in front of your site, which copies them by itself. Check this setup uploads a small test file, reads it back the way podcast apps do and tries it from your browser before the switch can be ticked. Copy everything now sends the readings already on the site. Your site keeps its own files: every address comes back to them whenever a copy is missing or changed, while the section is off, and by itself if the outside storage fails three checks in a row. Each copy's address is named by its content, so it never plays stale sound. A reading set to Members only, kept aside or unpublished loses its outside copy at once (a bunny.net API key also clears it from the CDN); a reading recorded again keeps its old copy two days for apps still playing it. Files for members and everything private never leave the site. The download-counting short address stays on your site and sends apps on. No code library is added: the plugin signs S3 requests itself.

= 1.22.0 =
Sponsor slots. Foxfire Studio has a new Sponsor messages screen: each message says who it is for, whether it is paid or gifted or your own (your shop, a cause, an event), a link, a script you record in the studio, its dates, where it can go (pre-roll, mid-roll, post-roll) and which readings it goes on, older ones included. Switch on Place sponsor messages in the feed under Foxfire Studio, Podcast feed, Sponsor messages. Each message's screen makes its copies in each reading's sound in your browser, and the site then makes a sponsored copy of each reading for the podcast feed by copying the sound as it is, with the reading's chapters and captions moved and a chapter for each message. The mid-roll goes in a pause you choose in the Reading audio box (three are offered near the middle, each one to hear), only in readings of 10 minutes or more, and by default never in ritual, verse or under the music bed. The post's own player keeps the reading as it is. A paid message's recording is checked for a disclosure such as "supported by" near its start, and the episode notes name the sponsor. Member feeds stay free of messages unless you choose otherwise.

= 1.21.0 =
Member feeds. Each reading's Reading audio box (and each collection's screen) has a new choice, Who can hear this reading: Everyone, as before; Members first, then everyone after a number of days (7 by default); or Members only. Switch on Give members their own feed under Foxfire Studio, Podcast feed, Member feeds, and choose who is a member: the site's roles, a choice of Always or Never on each person's profile, or, when one is active, the members of Paid Memberships Pro, MemberPress, Restrict Content Pro, WooCommerce Memberships or Memberful. Each member gets their own private podcast feed address, shown on their profile and wherever you place the shortcode [foxfire_member_feed], with the readings for everyone and those for members; it works in Apple Podcasts, Overcast, Pocket Casts and most other apps (Spotify cannot take private feeds), whether or not the public feed is published. A reading for members is kept out of the public feed, and its files move to a folder visitors cannot open until it is for everyone again. On its post, a member signed in hears it as usual and everyone else sees "This reading is for members." with Sign in and an optional Become a member link. An address can be made new at any time, and the old one then stops. Feed activity (off) can watch for an address that may have been shared, without keeping any IP address.

= 1.20.0 =
Audiobook export. A collection's screen has a new Audiobook box that makes the collection into the files an audiobook store asks for, to the rules of Audible (ACX), which Spotify for Authors, Authors Republic and Findaway also accept: the opening credits, one MP3 for each reading, the closing credits, a retail sample of one to five minutes and the cover, each at 192 kbps mono with room tone at both ends, checked against the rules and downloaded as one ZIP with a check report. A reading's chapter comes from its audiobook copy: its take finished once more for audiobooks, voice only. Tick Keep an audiobook copy (Settings, Broadcast sound) and Use this recording keeps one for every new reading; for a reading already on its post, the review's Finish for menu has Keep as this post's audiobook chapter. A reading without a copy and without the music bed is made from its post's MP3 instead. The credits are recorded in the studio from a short script the box writes. Missing room tone is made from the reading's own pauses.

= 1.19.0 =
Collections. Foxfire Studio has a new Collections screen: a collection is several readings in an order you choose (a series, the year's sabbats, a themed set), joined on your site into one MP3 with a chapter for each reading. Each reading is copied exactly as it is on its post, with silence between (2 s by default), so nothing is encoded again. The collection gets its own page at /collections/ with the player and a list of its readings, its own captions made from theirs, and, once published, its own episode in the podcast feed (a Bonus episode by default, or Full). When a reading in a collection is recorded again, the collection is joined again by itself; when one is taken off its post, the collection keeps what it has and says so. A reading in another format, or more than 1 dB louder or quieter than the rest, can be matched in the browser: only that one reading is made again for the collection, and the post's own reading is never touched.

= 1.18.0 =
Full-length video. Each reading's page (Foxfire Studio, Readings) has a new Full-length video section: Make the video turns the whole reading into a wide 1080p video for YouTube, Spotify and other apps that show video, with a picture (the post's featured image, the show's cover art, or one chosen from the Media Library), the waveform, the chapter being read and the captions a phrase at a time. It is made in the browser (Chrome or Edge on a computer; a 14-minute reading took about 2 minutes on a laptop), at Standard (5 Mbps) or as a Smaller file (2.5 Mbps), and downloads when ready; nothing is kept on the site. Beside it: a description for YouTube or Spotify with the tagged link and YouTube chapter times, the captions file and a thumbnail, for the upload. Clips are no longer slowed when the browser window is not in front.

= 1.17.0 =
The faster review. On a computer whose graphics chip can run it (WebGPU with half precision, as Chrome and Edge offer on most laptops), the larger Whisper model now runs on the graphics chip: a 14-minute reading took about 5 minutes on a laptop's GeForce GTX 1650 and about 8 on the Radeon graphics inside its processor, against about 27 on the processor. The teleprompter's Model menu (Controls, Recording) has a new first choice, Automatic, which every browser starts on: full reviews use the larger model on the graphics chip where it can run there, and the standard model everywhere else and for opening drills. Larger also runs on the graphics chip where it can. The status line says "Listening on the graphics chip" and the result says where Whisper ran. If the graphics chip fails, the review carries on with the processor and says so. Settings, Whisper on this site: the larger model's Download now also fetches the graphics chip's two files (about 322 MB, pinned by their fingerprints like the others) and only fetches the files still missing, and a note explains how to give the browser the stronger graphics chip on a laptop with two.

= 1.16.0 =
The stronger cleanup. Background noise reduction in Settings, Broadcast sound, now has an Engine: Standard (RNNoise, as before) or Stronger (DeepFilterNet 3, a larger model that comes with the plugin). Stronger also takes out sounds with a shape to them, such as a ringing bell, a creaking chair or mouth sounds, and takes about twice as long; with it, the Strong amount usually sounds best. It runs on the reader's own computer after the reading, like Standard, and if a device is too slow for it or it cannot load, the take uses Standard and the Room line says why. On Standard, the Room line suggests Stronger when the room is noisy or there are steady tones in the pauses, such as a bell or a hum. Hear the polish in the review studio has a Cleanup choice to compare the two on one sentence, and Use for this take cleans the take again with it. Standard stays the default.

= 1.15.1 =
Gentler on verse. In a post whose kind of reading is Verse or Ritual and chant, the read-out's Endings line now judges line endings the way the Faded ending flags do: a trail of up to 14 dB is natural there, and only a deeper one is pointed out, with a reminder of the Fade meant cue. Once the review is ready, the Endings line also says how many sentences were flagged Faded ending, so it no longer says all is well beside the flags, and the Rushed and Slow flags compare a sentence with this take's own pace in plain words. Readings to make's How hard no longer counts the capital at the start of each verse line as a name, nor a word written in lower case anywhere in your posts, a word that only appears inside a book or event title, or a word you marked Not needed on the Pronunciation screen. The end of each verse line now counts as a place to breathe, so a stanza without full stops is no longer one long sentence.

= 1.15.0 =
The pronunciation list. A new screen, Foxfire Studio, Pronunciation, shows every pronunciation note and every word taught to Whisper, wherever it is kept: your own for every post, one post's, or one word only. Search and filter them, click a note to change it, add a note without opening the studio, move a word between every post and one post (it asks which note to keep if both places have one), and forget a taught word. Words that may need a note lists the names and long words in your published posts that have none yet, posts still to read first. Export CSV, Export backup, and Import with a preview that adds to what is there and only replaces a note where you choose. Fixed along the way: a taught word forgotten in one browser came back the next time another browser opened the studio, and a studio tab opened earlier could undo a newer note or cue mark when it saved. Forgotten words are now kept as forgotten, and the studio sends only what it changed. The studio's note menu has an All notes link.

= 1.14.0 =
Voice following on the device. Follow my voice now runs a small speech model in the browser (the sherpa-onnx engine, which comes with the plugin, and a 45 MB model from Hugging Face or from your site), in place of the browser's speech recognition, which in Chrome and Edge sent the audio away. The word you are reading stays on the reading line. A pause stops the scroll, an ad-lib holds the place, and a skip ahead or going back is followed once a few words line up. While following, the control bar shows your pace over the last 30 s (against the post's kind of reading, when it has one), and under the large meter on a phone. When a take stops, a first look gives its length, pace and any paragraph never heard, until the Whisper review is ready. While a take is recorded the follower hears the recorder's own microphone, so there is only ever one. If the model cannot load or the device falls behind, timed pace takes over and a line says why. Settings has Download voice following to this site.

= 1.13.0 =
Voice polish. Each Broadcast sound step (pops on P and B, harsh S sounds, breaths, compression) now has a strength, Off, Gentle, Standard or Strong, in Settings; Standard is exactly the sound before. In the review studio, Hear the polish plays one sentence of a take as recorded and as polished, at the same loudness, looping, and switches between them while it plays; other strengths, the EQ and click lowering can be tried and used on that take only. Slow leveling, on by default, evens out a level that drifts over minutes, by at most 4 dB (2 dB on verse and ritual posts). While recording, a note says when your voice has dropped 4 dB or more since the first minute. Off until switched on: an EQ learned for each device and microphone from its first three takes (at most 3 dB in six broad bands), kept on the site so a phone take reviewed on the computer gets the phone's EQ; and lowering clicks between words, found from Whisper's word times. The Original download always stays as recorded.

= 1.12.1 =
Fixed: a phone take finished at Stop could come back on the phone as "An unfinished recording" after the computer had used it. The phone now forgets its copy once the take is finished, and clears any such copy left from before. Fixed: the read-out's Room line called a room noisy from the background as recorded, even when noise reduction then brought it well down (a phone take at 32 dB, cleaned to 45 dB). It now rates the room by what is left after noise reduction and gives both figures, and its advice for a fairly quiet room suits any device. Also: a phone that lost its own copy of a cut-off take now offers Carry on at once, without waiting 90 seconds for the site.

= 1.12.0 =
Phone take mode. On a phone, a take now goes to the site in pieces of about ten seconds while you record, so a reload, a call or a closed page loses no more than the last few seconds. A piece that cannot go up waits on the phone and is tried again; the next visit sends whatever the site is missing. At Stop the take is finished on the site at once, and the phone does nothing more unless you press Make the MP3 here. The teleprompter on your computer offers it for the Whisper review, like a take sent with Send to my computer. While recording, a large level meter sits across the bottom of the screen with a word under it (Good, A little quiet: hold the phone closer, Too quiet: move closer, or Too loud) and a line saying how much is on the site. When a take was cut off partway, opening the post on the phone offers Carry on, a new take from the sentence before where it stopped, and Combine takes joins the two on the computer. On for phones; switch it off in Controls, Recording, Phone take mode. Opening drills stay on the phone.

= 1.11.1 =
Fixed: when Whisper ran the first words of a reading together (such as "Hex the" heard as "Hexa"), the review showed them as not read and the captions left them out, because the reading seemed to start at the next word. Words Whisper heard just before the first matched word are now paired with the unread words of the script before it, when they sound alike enough and come within two seconds of the reading; they show as read, or as one misread such as "Hex the" heard as "Hexa", with their times, so the captions keep them. The same is done at the end of a reading. A stray phrase before a reading (such as "thank you") is still left out.

= 1.11.0 =
Review for verse and pace. Each post can have a kind of reading: Prose, Story, Verse, or Ritual and chant, each with its own pace range (set in Settings, Reading). Choose it in the teleprompter's Practice section or the post's Reading audio box; for a post of mostly short lines the teleprompter suggests Verse. With a kind, the teleprompter starts in the middle of its range, and the review judges the pace against it, with the Rushed and Slow flags moved to match. A new cue, Fade meant, tells the review a line is meant to trail off, so its sentence is not flagged Faded ending; in Verse and Ritual and chant only a deep fade is flagged. The review's new Pause map counts every pause of a second or more by where it falls, can show each one's length in the script, and notes any pause or breath you cued but ran straight past.

= 1.10.0 =
The full Podcasting 2.0 feed. Each episode now carries its chapters, read from the MP3's own chapter tags and served as a chapters file, a soundbite (the stretch chosen with Use for the feed, else the top suggested clip) and its transcript's language. The show names its host (the author and this site by default, with an optional square picture), a support link such as a donation page with the words apps show beside it, the medium, an optional place, and optional text lines for the codes some directories ask for. Pings, off until switched on and only while the feed is published, send the feed's address to Podcast Index and a WebSub hub when a published post gets a reading, at most once every 10 minutes. The Podcast feed screen lists each episode's chapters, transcript and soundbite, and says when a link is not https or the host picture is not square.

= 1.9.1 =
Fixed: the Whisper review could lose the start of a reading longer than 30 seconds. Whisper hears long audio in windows, and the library that runs it joined the windows' words where they overlapped; on a 72 second phone reading that join lost the first 25 seconds, so the review showed the title and the opening as not read. The studio now cuts a long recording into pieces of up to 28 seconds at its quietest pauses, Whisper hears each piece on its own, and the words are put back together with their times in the whole recording. Nothing overlaps, so nothing is lost at a join. Each piece's answer is also checked: when Whisper gives no words for a long stretch of speech (it can stop after a phrase and a pause as if the rest were silent), the piece is heard again in halves cut at a pause. The clip maker uses the same worker.

= 1.9.0 =
Phone level. A phone recording through the browser comes through about 35 dB under a finished reading, more than the 30 dB a take could be raised by. Now the limit follows the room: when the background, after cleanup and noise reduction, would still sit at -60 dB or below, a take can be raised by up to 45 dB; a noisy room keeps the 30 dB limit. The read-out says when a quiet room allowed more, or which limit held a take back. Check microphone now says how much lift a take will need to reach your loudness, whether this room allows it, and to move closer when it does not. On a phone or tablet, takes are kept in 24 bits (in the browser, in Original, and when sent to your computer), so the quietest detail stays clean when raised that far. The browser's own voice processing stays off, since in a test on a phone it cut the sound above 11 kHz and silenced the pauses. Fixed: a quiet recording was cut short when opened, after its last louder word, because the trim of its ends used a fixed level; it now follows the recording's own level. On a phone, the microphone check's tips are about the phone.

= 1.8.0 =
Keep a reading aside. A reading's page in Foxfire Studio, Readings now has Take off the post, and the post's Reading audio box links to it. The reading's file and its captions leave the Media Library for a folder visitors cannot open; the post loses its player, the podcast feed loses the episode, and the post is back on Readings to make, marked Reading kept aside. Its listening history stays. Foxfire Studio, Recordings lists every reading kept aside with a private player, Download, Put back and Delete for good. Put back returns the files to the Media Library at the address they had, with the captions, the music bed's credit and the pick for the feed, so it is the same episode in the feed and its listening history carries on; if the post has a newer reading by then, the two swap. A reading in the theme's own field is handled the same way when the field points to the Media Library; otherwise only the field is cleared and remembered.

= 1.7.0 =
Clip suggestions. A reading's page in Foxfire Studio, Readings now lists Suggested clips under Make a clip: the opening and the best three stretches of 30 to 60 seconds, each starting and ending with a sentence, worked out from the reading's captions. Paragraph and section edges count for a stretch and a length of 30 to 45 seconds is preferred, while starting on a word that points back (And, But, This, It and the like) and long pauses count against it. Once the reading has 10 plays, what listeners replay and stay through counts too. Each suggestion shows its first and last words and why it was chosen, with a play button, Make this clip (which opens the clip maker at that moment with its exact length) and Use for the feed, which keeps it as the episode's soundbite for the coming Podcasting 2.0 feed. The clip maker shows the same suggestions; after a take in the studio, its own suggestions now start and end with a sentence.

= 1.6.0 =
Finishing presets. Settings, Broadcast sound now has one Finish for menu: Podcast, mono (-19 LUFS, as before, and still the default); Podcast, stereo (-16 LUFS); Spotify and YouTube (-14 LUFS); Broadcast, EBU R128 (-23 LUFS, a 48 kHz 24-bit WAV); Audiobook (ACX); Custom (your own loudness, true peak limit, and mono or stereo); and the simple leveling. Each preset fills in its true peak limit, and the finished file is measured as it is delivered, both channels counted for stereo. After every finish the read-out and the studio check report the loudness range (EBU Tech 3342), with a note when it is wide for speech. In the review, a Finish for menu beside Download makes a one-off version for another destination; Use this recording always uses the Settings choice. Clips are now leveled to -14 LUFS, the level social apps play at. A very quiet recording can now be raised by up to 30 dB (24 before), so laptop microphone readings reach their target.

= 1.5.0 =
Prompter view, for a glass teleprompter rig or a screen across the room. Open it from the Controls panel or with the P key: the studio goes full screen with only the script, which can be mirrored left to right, upside down or both, so it reads the right way round in the rig's glass. It keeps its own size, width and reading line, and adds Shift (to sit the script over the lens), Fade above and below, and bright text on black. Keys suit presentation clickers and foot pedals: Page Down starts and pauses, Page Up goes back a sentence, B or the full stop blanks the screen, plus and minus change the size, F5 cannot reload the page, and Escape leaves. Page Down and Page Up also work in the normal view.

= 1.4.0 =
The backlog. Foxfire Studio, Readings to make lists your published posts that have no reading yet, with the words, how long each would run at your own pace (the median of your latest five full takes), and how hard it looks to read aloud: Easy, Some care or Demanding, worked out per 100 words from long sentences, names, very long words and the words that tripped you or Whisper before. Start opens the studio on the post; Hide takes a post off the list. In the studio, a long post can be read in parts: once a post's shelf has a take, Continue starts the next take one sentence before the first paragraph no take has read, the review says how much is left, and the Combine takes screen shows a bar of which take reads each part.

= 1.3.0 =
One reading from several takes. Every take you review goes on the post's shelf in your browser (the newest six, for 30 days). With two or more, Combine takes (in the review, and beside Open a recording) lets you choose which take reads each paragraph, or each sentence of a paragraph you split, with each take's flags shown side by side; Best of each picks the cleanest, switching takes only for a clear gain. A take that starts partway supplies the paragraphs it covers. Joins go in the pause before a sentence, the takes are leveled to match, and each join can be heard and moved like a retake's. Build the reading cleans and masters it once and opens it in the review with the takes' own Whisper words. It is not added to your take history.

= 1.2.0 =
Wider listening numbers. The Overview and each reading now show Read or listen (visits that listened only, read and listened, read only, or passed through) and Time to press play (the median and four bands, for phones and tablets and for computers), worked out from what the player already measured, so earlier visits still kept are counted too. Three new options, each off until you switch it on: Returning listeners tells new listeners from returning ones with a yes or no from the visitor's own browser; Count downloads (Podcast feed) counts episodes downloaded by podcast apps, by app, once per listener and day; and the Google Analytics link also reports plays to the Google Analytics your site already loads. The report email gains a line for each.

= 1.1.0 =
Pronunciation notes: in the teleprompter, with Cues on, click a word and choose Pronunciation to show a short note above it, for that one word, every time it appears in the post, or in every post. The review shows a word's note beside its flag, offers to teach Whisper every noted word it misheard at once, and offers to add a note after you teach a word. Flags after edits: as soon as a cut or retake covers a flagged moment, it is marked Cut, Shortened or Retaken, and the counts show what is left; Check the edited version has Whisper listen to the edited recording, with a switch between its flags and the take's. Your takes, goals and charts keep the numbers from the reading itself.

= 1.0.0 =
The first stable release: the same plugin as the 0.27 release candidate, after it ran on a live site. One small change: under Measure listening, the advice "Off until you switch it on" now shows only while listening is not being measured. The automatic checks now also run on WordPress 6.0 and PHP 7.4, the oldest versions the plugin supports, and test every scheduled job as if days and weeks had passed.

= 0.27.1 =
The Dark preset has a plain internal name, dark, in place of the name of the site it was first made for. Nothing a reader or visitor sees changes. A site whose saved look still uses the old name keeps every colour, font and wording it saved; Settings shows its look as Custom, and applying the Dark preset again gives it the new name.

= 0.27.0 =
The release candidate for 1.0. Every document was checked against the code: the README, this readme (now in WordPress.org's shape, with a FAQ and the outside services listed), the architecture map, the contributing guide, the setup guide and the privacy notes, which now also mention the share page's fonts. The last settings sentences that were split around code names are now whole, so translators can order them as their language needs. Nothing a reader or visitor sees changes in English.

= 0.26.0 =
Safer upgrades and a clean delete. The automatic checks now upgrade from
every older release that changed the database, and delete the plugin both
keeping and removing data. With "also delete everything else" ticked,
deleting the plugin now also removes each post's reading, captions and
Read by links (the files stay in the Media Library) and the Whisper files
saved on the site. Goals saved in an older shape no longer break Your
progress.

= 0.25.3 =
A Download button in the review studio's header, beside Use this
recording. It always gives the latest file: the take, or the edited MP3
once it is made, with the music bed under it when that is switched on.

= 0.25.2 =
Tidier inside. The rest of the studio now lives in a file for each part:
the core of the teleprompter, recording, cue marks, voice following, the
MP3 and music bed, the review studio and Edit audio, with a short starter
that brings them together. Nothing a reader sees changes. The automatic
checks now also record a take, bring back one that was never downloaded,
download it with and without music, tidy and cut it in Edit audio, and
make the edited MP3.

= 0.25.1 =
Tidier inside, and one fix. The studio's cleanup, read-out, comparison
with the script and Whisper now each live in a file of their own, with
tests that check them on every change, and the clip maker uses the same
Whisper code as the studio. Nothing a reader sees changes, apart from the
fixes: Load from URL in the teleprompter now finds a post when its address
is typed a little differently from the site's own (with or without www,
https or a slash at the end), and a retake that starts with a false start
no longer keeps it, and a line retaken straight after another retake is
no longer dropped. A retake's box also has Retake start and Retake end,
to trim the new recording's own ends by ear.

= 0.25.0 =
Ready for translation, and checked automatically. Every word the plugin
shows, on its screens, in the studio, in the report email, in the podcast
feed and on the public player, can now be translated. A translation goes
in the plugin's languages folder or in wp-content/languages/plugins, and a
template of every string, foxfire-studio.pot, comes with the plugin. In
English nothing reads differently. Behind the scenes, a set of automatic
checks now runs on every change.

= 0.24.0 =
A music bed. Add music you own or have a licence for in Settings, Music
bed, choose the show's default track, and each new take can have quiet
music under it: a few seconds of music alone, a dip just before the first
word, the music low under the voice, and a rise and fade after the last
word. It is mixed in last, when the take is downloaded or put on the post,
so the review, the editing and the Original download stay voice only, and
the finished file is leveled to the loudness standard as a whole. Each
post can choose its own track, or none, in its Reading audio box, and a
Music bed switch with each take leaves it out for that take. Listen with
music plays the mix first. Choose how far under the voice it sits (16, 20
or 24 dB) and whether it rises a little in long pauses. A track's credit
goes into the MP3 and the podcast episode notes. Also: when the intro is
going to play, the studio stays black until it starts, so the page no
longer shows for a moment first.

= 0.23.0 =
An intro for the studio. Choose a short video from the media library in
Settings, Teleprompter look, Intro video, and it plays when the
teleprompter opens in a new tab: with sound when the browser allows, or
muted with a Sound on button. Skip ends it (so do Space, Enter and
Escape), and Skip it from now on stops it in that browser. Play the intro
in the studio's Controls panel turns it back on. It never plays when the
browser asks for less motion, or when a take sent from a phone is being
opened.

= 0.22.0 =
A podcast feed of your readings. Switch it on in Foxfire Studio, Podcast
feed, and every published post with a reading becomes an episode, ready
for Apple Podcasts, Spotify and other apps: cover art, description,
category, owner email and a clean or explicit rating, with the reading's
captions as its transcript. The screen checks what directories ask for
before you submit, and shows the feed's address. Also new: a "read by"
credit for each reading, written in the post's Reading audio box (or read
from another custom field set in Settings), shown under the player's label
and in the feed.

= 0.21.0 =
Send to my computer. A phone records well but cannot run the full Whisper
review, so after a take on a phone the review now offers Send to my
computer beside Try it here anyway. The take's original recording goes to
a folder on the site that only your account can open. The next time you
open the teleprompter on a computer, it offers the take, opens the post it
was read from and runs the full review there, with editing, retakes and
Use this recording as usual. The take is deleted from the site once you
use, download or delete it, and after 14 days at most. Waiting takes are
also listed under Foxfire Studio, Recordings.

= 0.20.0 =
Retake edge nudge. Click a retaken word in Edit audio to open its retake
again. Start and End move each side of the splice 10 ms at a time, cutting
more or less of the old reading (or the [ and ] keys, with Shift for the
end), so a word heard twice at a join can be taken out. Space before and
Space after add a pause at a join, 50 ms at a time, filled with the room's
own background, so words that run together can be pulled apart. Each step
plays the join so you hear the change at once. Reset puts everything back,
and Bring back the original takes the retake out. The changes are kept
with the edit, so Make edited MP3, the captions and the chapters follow
them.

= 0.19.1 =
Fixes for phones. Read along now lights up the post as it plays for
readings whose captions start with the spoken title (every reading made
with the teleprompter), and when a reading still has to fall back to the
caption list, the line being read is shown whole. On iPhones, iPads and
other phones with little memory, the Whisper review waits to be asked for
after a take, since a phone can close the page while it runs; it then uses
the standard model with lighter settings, and says so if the page closed
under it last time.

= 0.19.0 =
What works: insights. A new Insights screen turns your numbers into plain
sentences: whether shorter or slower readings, a tag or category, the day
and time a reading went out, where listeners came from, and slips, pitch
movement and phrase endings line up with readings heard to the end; and
how your own pace, accuracy, fillers and endings are moving. Each one says
what it rests on and stays quiet until there is enough data. The clearest
two appear on the Overview and in the report email, and the teleprompter
mentions when a script runs longer than the readings listeners stayed with.

= 0.18.1 =
Cleaner retakes. The old words are now taken out from where the voice
really starts to where it stops, so no sliver of the old reading is left
beside the new one, and anything said before or after the selected words
in the retake is trimmed away. Selecting is easier too: drag across words,
triple-click for a paragraph, Whole sentence and Whole paragraph buttons,
and Shift with the arrow keys. A word Whisper timed out of step is no
longer lit up out of place or used to place an edit.

= 0.18.0 =
The studio look. A menu to the other Foxfire Studio screens, a fold-away
Controls panel grouped into Reading, Recording and Practice, the script
framed in gold, and a dock with a larger Begin and level waves that move
while recording. Your last take of the post sits beside the script, with
each number compared with the take before. Optional artwork and a short
corner line are in Settings, along with a second look that uses your
WordPress theme's colours with no artwork. Every control works as before.

= 0.17.0 =
Share a take for feedback. From the review studio, share a take with
people who have an account on the site. They hear it with the script
lighting up and leave comments pinned to words or moments; you get one
email per visit. A share lasts 14 days, then its audio is deleted.

= 0.16.0 =
Better listening. "I said it right" asks whether to remember the word in
every post or just this post; words for one post are kept with the post.
A larger Whisper model (about 250 MB) can be chosen beside Whisper review:
more accurate with names and unusual words, slower, and downloadable to
the site from Settings. Fixes teaching the first word for a new reader.

= 0.15.0 =
More studio upgrades. Font: read the script in the reading font, a clean
sans serif, or your computer's own font. Warm up: about three minutes of
breathing, tongue twisters and a pacing passage before you read, with a
level bar that shows the microphone hears you. Cues: mark
a pause or a breath after a word, or emphasise it, without changing the
post; pauses and breaths hold the timed scroll, and the marks show in the
review too.

= 0.14.0 =
Studio upgrades. Check microphone: a 10 second test of the room and your
level, with tips. Opening drill: read just the opening (20 to 60 seconds),
compared with your last drill and kept apart from full readings, with its
own section on Your progress. Focus: pick one habit before a take and the
review leads with it.

= 0.13.0 =
Clips for social media: a moment of a reading as a short captioned video
(square, vertical and wide), with the post's image, a moving waveform, the
title, the site's address and an end card. Made in the browser from a
reading's page in Foxfire Studio, or from the review studio after a take.
Each clip gets its own tagged link. Download it, or save it to the Media
Library to open on a phone.

= 0.12.0 =
The report email: how your readings did, sent every month on the 1st (or
weekly, every two weeks or quarterly) to the addresses you choose. A new
Reports screen with a preview, Send now, a check that scheduled jobs run on
time, and the list of past reports. Optional CSV attachments.

= 0.11.1 =
Reading Room Teleprompter is now Foxfire Studio, in a new folder
(foxfire-studio). The WP-admin menu and settings page are named Foxfire
Studio. Activating it switches the old copy off; deleting the old copy
afterwards keeps your settings and data. Nothing else changes.

= 0.11.0 =
The dashboard: a Foxfire Studio menu with an overview, every reading and its
listening curve (where listeners leave, replay, skip and change speed, with
the sentence playing), sources and campaigns with a link builder, your
progress with goals and a practice log, recordings, change markers and CSV
downloads. Goals also show in the review studio.

= 0.10.1 =
Security fix for listener analytics: the visitor's address now comes from
the connection itself, and forwarding and country headers are trusted only
from proxies listed under Settings, Foxfire Studio, Listener analytics,
Trusted proxies. New hourly limits per address and for the whole site.
Listener analytics is now off on new installs until switched on; sites
that already used 0.10.0 keep it on. Documentation and privacy review; a
new PRIVACY.md and a LICENSE file in the repository. Existing settings are
unchanged; sites behind a CDN should list it under Trusted proxies.

= 0.10.0 =
Listener analytics: the player reports plays, the parts heard, speed and
volume changes, skips, the end and leaving to your own site, with the
referring site, campaign tags, device and country. Nothing that identifies
a visitor is stored. Daily summaries, a recent listens list and a privacy
policy paragraph are in Settings. Country lookups use a local copy of DB-IP's
free database (IP Geolocation by DB-IP, CC BY 4.0).

= 0.9.0 =
Reader stats on the server: every take, the words you have taught Whisper,
and a record of each post's reading are kept on your site, so your history
follows you to any computer. "Use this recording" puts the MP3 and its
captions on the post in one click. The read-out adds an Energy line. A
browser's history from before 0.9.0 is brought over on first visit, and
Settings can switch server storage off.

= 0.8.0 =
Text-based editing: in the review studio, cut words, fillers and long
pauses by selecting them in the script, or record a sentence again and
splice it in. Tidy suggests fillers, repeats and long pauses to cut. Every
edit can be previewed and undone, and the original recording is never
changed. Make edited MP3 builds a new file with the captions and chapters
moved to match.

= 0.7.0 =
Podcast-ready files: each MP3 carries its title, author, show name, year,
a link to the post and the featured image as artwork, plus chapters at the
post's headings once the Whisper review has run. A chapters file is offered
next to the captions, and the listening player shows the chapters. MP3s are
now made by LAME as WebAssembly, about ten times faster than before.

= 0.6.0 =
Speech-model noise reduction: cleanup now runs RNNoise, a small model that
tells your voice from fans, traffic and room noise, with a Gentle, Medium or
Strong setting. The model's fingerprint is checked every time it loads, and
it can be served from your own site. The Room line shows the background
before and after.

= 0.5.0 =
Broadcast sound: pops, rumble, breaths and harsh S sounds are softened,
loud words gently compressed, and each reading is leveled to a podcast
loudness standard with a true-peak limiter. An audiobook preset follows
Audible's ACX rules. The read-out now includes a studio check.

= 0.4.0 =
Lossless recording: the microphone is captured raw and the original
downloads as a WAV. Takes are saved in the browser as you read, and a take
that was never downloaded can be recovered after a crash or closed tab.

= 0.3.2 =
No outside requests: a Download to this site button in Settings serves
the fonts and the MP3 encoder from your own site, with automatic fallback
to Google Fonts and jsDelivr when a copy is missing or out of date.

= 0.3.1 =
Security hardening: readings of drafts, private and password-protected
posts are never shown to visitors; the teleprompter lists only posts the
person may edit; the MP3 encoder loads with an integrity check; and every
self-hosted Whisper file is checked against its known fingerprint.

= 0.3.0 =
The listening player: skips, speed, lock screen controls, read along,
resume, and links that start at a moment.

= 0.1.0 =
One codebase: everything from the earlier theme template (read-out, Whisper
review, teachable words, cleanup, self-hosting) in the plugin, with a
settings screen, presets and a Whisper download button.

== Upgrade Notice ==

= 1.28.0 =
With the player at the top of the post, a post that also holds the shortcode now shows the player once, where the shortcode is. A new Reading player block places it in the block editor and the Site Editor.

= 1.27.0 =
In the studio, Space, the arrows and the other teleprompter keys now act only when the script has focus. If a key seems to do nothing, press Escape (or click the script) and try again.

= 1.26.0 =
The player's panel is now solid, in the colours under Settings, Colours. If your colours were chosen for a see-through panel, look at the Readability check there and press Make it readable if anything is flagged.

= 1.25.0 =
Security fixes. After updating, press Check private folders (Foxfire Studio, Podcast feed). If you give anyone an Author or Editor account, note that only Administrators can now make sponsor messages. On nginx, add the lines the section shows.

= 1.24.0 =
Download counting now follows the IAB guidelines v2.3 (Foxfire Studio, Podcast feed). If Count downloads is on, press Check counting in the new Download counting section (on LiteSpeed and Apache it may add a few marked lines to the top of your .htaccess file, taken out again when counting is switched off), and update your privacy policy line from Foxfire Studio, Settings: it now mentions devices, members' feeds and the monthly list download.

= 1.23.0 =
Adds Storage offload (Foxfire Studio, Podcast feed, off). Nothing changes until you switch it on. If you do, add the line Foxfire Studio, Settings shows to your privacy policy: listeners' apps then fetch the readings from the storage you chose.

= 1.22.0 =
Adds Sponsor messages (Foxfire Studio, Sponsor messages, and Podcast feed, Sponsor messages, off). Nothing changes until you switch them on. Nothing is collected from listeners.

= 1.21.0 =
Adds Member feeds (Foxfire Studio, Podcast feed, off) and the Who can hear this reading choice (Everyone by default). Nothing changes until you use them. If you switch member feeds on, add the line Foxfire Studio, Settings shows to your privacy policy. Nothing new is collected from visitors.

= 1.20.0 =
Adds the Audiobook box to collections and Keep an audiobook copy (Settings, Broadcast sound, off). Nothing changes until you use them. Reload any open studio tab after updating (Ctrl+Shift+R). Nothing new is collected from visitors.

= 1.19.0 =
Adds Collections (Foxfire Studio, Collections). Nothing changes until you make one. A collection's joined MP3 is kept in the Media Library like a reading. Nothing new is collected from visitors.

= 1.18.0 =
Adds Full-length video to each reading's page. Nothing changes until you use it. Nothing new is collected from visitors.

= 1.17.0 =
Whisper reviews can run on the graphics chip. The Model menu in the studio starts on Automatic in every browser, which uses the larger model there when it can; choose Standard to keep the old behaviour. If you host the larger model, press Download the larger model to this site again for its two new files (about 322 MB). Reload any open studio tab after updating (Ctrl+Shift+R). Nothing new is collected from visitors.

= 1.16.0 =
Adds the stronger cleanup, an engine choice under Settings, Broadcast sound, Background noise reduction. Nothing changes until you choose it. The plugin is about 9 MB larger, for the model. Reload any open studio tab after updating (Ctrl+Shift+R). Nothing new is collected from visitors.

= 1.15.1 =
Fixes only: the Endings line for verse and ritual readings, and How hard on Readings to make for verse. Reload any open studio tab after updating (Ctrl+Shift+R). Nothing new is collected from visitors.

= 1.15.0 =
A new Pronunciation screen under Foxfire Studio. Reload any studio tab that is open when you update (Ctrl+Shift+R), so it saves notes and cue marks the new way. Nothing new is collected from visitors.

= 1.14.0 =
Follow my voice now runs on your own device. The first time it is switched on it loads a 45 MB speech model from Hugging Face; Settings can download it to your site instead. No audio leaves the browser.

= 1.13.0 =
Voice polish: a strength for each Broadcast sound step (Standard is the sound you have now), Hear the polish in the review studio, and slow leveling, which is on. The EQ per setup and click lowering are off until switched on. The EQ keeps six numbers per take for each recording setup in the editor's own user data; nothing new is collected from visitors.

= 1.12.1 =
A finished phone take no longer comes back on the phone as an unfinished recording, and the read-out's Room line allows for noise reduction. Nothing to do; nothing new is collected from visitors.

= 1.12.0 =
Phone take mode, on for phones: takes recorded on a phone are kept on the site as they are recorded, in the same private folder Send to my computer uses, for 14 days at most. Switch it off in the teleprompter's Controls, Recording, on each phone. Nothing new is collected from visitors.

= 1.11.1 =
Fixes the first or last words of a reading being shown as not read, and left out of the captions, when Whisper runs them together. Nothing to do; review a take again to see it.

= 1.11.0 =
Review for verse and pace. Nothing to do; posts have no kind of reading until you choose one, and until then the teleprompter and the review work as before. Nothing new is collected from visitors.

= 1.10.0 =
The full Podcasting 2.0 feed. Nothing to do; the new fields are on Foxfire Studio, Podcast feed. Pings are off until you switch them on; if you do, add the line from the privacy paragraph to your privacy policy. Nothing new is collected from visitors.

= 1.9.1 =
Fixes the Whisper review losing the start of a long reading. Nothing to do; nothing new is collected from visitors.

= 1.9.0 =
Phone level. Nothing to do: quiet phone takes in a quiet room now reach their loudness, and laptop takes sound as before. Nothing new is collected from visitors.

= 1.8.0 =
Keep a reading aside, on each reading's page. Nothing to do and nothing new is collected from visitors.

= 1.7.0 =
Clip suggestions on each reading's page. Nothing to do and nothing new is collected from visitors: suggestions come from each reading's own captions and the listening numbers the dashboard already shows.

= 1.6.0 =
Finishing presets. Nothing to do: a podcast loudness of -19 becomes Podcast, mono, any other becomes Custom with the same number, and audiobooks stay audiobooks, so every reading sounds as before. Nothing new is collected from visitors.

= 1.5.0 =
Prompter view. Nothing to do: it is in the studio's Controls panel, and nothing new is collected from visitors.

= 1.4.0 =
Readings to make and Continue. Nothing to do: the new screen is under Foxfire Studio, and nothing new is collected from visitors.

= 1.3.0 =
Combine takes. Nothing to do: takes reviewed from now on go on each post's shelf in your browser by themselves.

= 1.2.0 =
Read or listen and time to press play appear by themselves. Three new options stay off until you switch them on; each adds a line to the privacy paragraph in Settings, so update your privacy policy before you do.

= 1.1.0 =
Pronunciation notes and flags after edits. Nothing to do: your settings, readings and stats carry over unchanged.

= 1.0.0 =
The first stable release. Nothing to do: your settings, readings and stats carry over unchanged.

= 0.27.1 =
An internal name changes; nothing you see changes.

= 0.27.0 =
The release candidate for 1.0. Documentation and translation wording only; nothing a reader or visitor sees changes in English.

= 0.26.0 =
With "also delete everything else" ticked, deleting the plugin now also removes each post's reading links and the self-hosted Whisper files. Updating never deletes anything.

= 0.11.1 =
New name and folder. Upload, choose Activate Plugin, then delete Reading Room Teleprompter; your settings and data carry over.

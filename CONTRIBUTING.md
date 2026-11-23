# Contributing

How changes are made, tested, numbered and released. Security problems are reported privately instead: see [SECURITY.md](SECURITY.md).

---

## Reporting a problem or suggesting something

Open an issue and pick **Something is not working** or **An idea**. Each form asks only for what helps: the plugin, WordPress, PHP and browser versions, and what happened. Please leave out your site's address, recordings, screenshots with names in them, and anything else personal; a description is enough. Security problems go through private reporting instead (see [SECURITY.md](SECURITY.md)).

The plugin is made and looked after by one person, so replies come when they can.

## Sending a change

Pull requests are welcome. This repository receives one commit per release, after each release has been built and tested as a whole, so a pull request here is not merged as it stands: an accepted change is applied by hand into the next release, credited to you in its commit (`Co-authored-by`) and in CHANGELOG.md, and the pull request is closed with a note saying where it went. Small, focused changes with a test are the easiest to take.

## The short version

1. Pull the latest `main`.
2. Make a branch for one change: `feature/short-name` or `fix/short-name`.
3. Make the change. Update the docs it touches.
4. Test it (see below).
5. Open a pull request.
6. Merge, tag, release.

For small fixes it is fine to commit straight to `main`, but say so in the commit message.

---

## Before changing something

Read its section in [docs/DESIGN.md](docs/DESIGN.md). Most odd-looking choices have a reason recorded there. If you change one of those choices, update that section with the new reasoning, including what made the old way wrong. Future you will want to know.

---

## Testing

Every pull request into `main` runs the automatic checks on GitHub (`.github/workflows/tests.yml`) in two jobs; plain branch pushes run nothing, and the Actions tab's **Run workflow** button runs them by hand on any branch. A newer push to the same pull request stops the run it replaces. The two jobs: everything with PHP 8.2 and the newest WordPress, and the oldest versions the plugin claims, PHP 7.4 and WordPress 6.0, with `tests/run.sh oldest`. Run the same checks on your own computer before pushing:

```bash
tests/run.sh          # everything (builds a throwaway WordPress in /tmp/foxfire-wp)
tests/run.sh quick    # syntax and unit tests only, a few seconds
tests/run.sh oldest   # WordPress 6.0 in /tmp/foxfire-wp-oldest: PHP syntax, PHP checks, rollups, scheduled jobs, smoke test
tests/run.sh firefox  # clips and the full-length video in Firefox (local only, see Testing in other browsers)
```

What runs:

- **Syntax** of every PHP and JavaScript file.
- **Unit tests** in `tests/unit/` (Node's built-in test runner, no packages to install) for the pure helpers: loudness and the limiter, audio edits, combining takes (1.3.0), practice tools, MP3 tags, the read-along matcher, clips, and (0.25.1) cleanup, the read-out, the comparison of a take with the script and the Whisper worker. `tests/unit/synth.js` makes the made-up audio they use.
- **PHP checks** in `tests/php/checks.php`, run inside the test WordPress: version numbers agree, listener addresses, CSV safety, cue cleaning, the music bed setting and the defaults for a new install. `tests/php/rollups.php` (0.25.1) rolls up a day of made-up listeners and compares every number with a hand count. `tests/php/time.php` runs the scheduled jobs as if time had passed: shared takes and sent takes past their end lose their audio, a comment waiting over an hour is emailed once, the daily trim keeps the limits in the settings, and the report goes once at its hour on the 1st, is not sent twice, and is tried at most four times when mail fails (the report's clock is moved with the `rrt_report_now` filter; no mail leaves the site). On WordPress 6.0 the comment email check is skipped, because the SQLite plugin version 6.0 needs cannot run `HAVING`; MySQL and MariaDB can.
- **Translations:** `tests/php/placeholders.php` counts the arguments of every `sprintf()` and `printf()` with a translated format, the `.pot` must be up to date, and `tests/browser/translated.py` shows a made-up translation (`tests/wp/pseudo.py`) through every screen, so any English left outside the translation functions shows up.
- **Browser smoke test** in `tests/browser/smoke.py` (Playwright): every Foxfire Studio screen, the teleprompter and a public post load with no PHP or script errors, and the private REST routes refuse visitors who are not signed in.
- **A whole take** in `tests/browser/take.py` (0.25.1): a recording goes through cleanup, the MP3, a stand-in Whisper and the review, which must show the slips built into it. Since 0.25.2 it also records a take with Chrome's stand-in microphone, brings back one never downloaded, downloads with and without the music bed (which `tests/wp/music.php` sets up for the run), and tidies, cuts and makes the edited MP3, so every part of the studio runs. The MP3 encoder and noise model are fetched from npm once (needs `npm`) and checked against the plugin's fingerprints.
- **Prompter view** in `tests/browser/prompter.py` (1.5.0): `run.sh` makes a short post; the view is opened and left by key and by button, mirrored both ways, resized, blanked, and checked to keep its own size apart from the normal view's, to catch F5 and to step aside for a result.
- **Combining takes** in `tests/browser/combine.py` (1.3.0): `run.sh` makes a post of two paragraphs, and three made-up takes of it (one starting at the second paragraph) are opened, reviewed and put on the shelf; Combine takes picks the best of each, a paragraph is split by sentence, a join gets space, the choices come back after a reload, and the built reading opens in the review with none of the slips left out and no new entry in the take history. `tests/browser/take_assets.py` fetches the MP3 encoder and noise model for both browser takes.
- **Uninstall** in `tests/wp/uninstall.sh` (0.26.0): on a test site of its own (`/tmp/foxfire-un`), a real copy of the plugin is filled with something of every kind it stores, then deleted the way the Plugins screen does it, once keeping data and once with the delete box ticked. Keeping, only the settings, jobs, counters, asset copies and feed address may go; deleting, nothing of the plugin's may be left, and the Media Library stays. A new option, table, post or user field, upload folder or scheduled job needs a line in `uninstall.php`, `tests/php/uninstall-seed.php` and this check.
- **Upgrades** in `tests/upgrade.py` (0.26.0): each older release that changed the database or the folder is installed on a test site of its own (`/tmp/foxfire-up`), given settings and data, and upgraded to the build in the repo the way a site owner would. Everything must come through unchanged, with the tables and columns of a fresh install. The release zips are fetched with the GitHub CLI (`gh`, signed in or `GH_TOKEN`) from the repository it runs in, into `/tmp/foxfire-zips`; with no releases there, it skips. `python3 tests/upgrade.py 0.9.0` runs one. When a version changes the database, add it to `RELEASES` once it is released.

The test WordPress uses SQLite, so no database server is needed. It needs PHP, Node 22, Python with `pip install playwright`, and Chrome. Set `WP_REF` to a WordPress version (`WP_REF=7.1.4`) to build the test site from that version instead of the newest. WordPress 6.0 needs an older SQLite plugin, so `tests/run.sh oldest` sets `SQLITE_REF=v2.1.3` for you, and turns `WP_DEBUG_DISPLAY` off, because WordPress 6.0 prints its own deprecation notices on newer PHP; the debug log scan still catches any notice from the plugin.

Add a test with every fix and every new helper.

### Testing in other browsers

The browser tests run in Chrome. Firefox behaves differently where clips and videos are made (no AAC encoder of its own, and H.264 frames out of display order), so `tests/run.sh firefox` (1.30.0) makes a clip and the full-length video in Firefox with `tests/browser/firefox.py` and checks every frame, High profile, AAC sound and no edit list. It is not run on GitHub, to keep the Actions minutes down; run it before a release that touches `clip.js`, `video.js` or the vendor files. It needs Firefox, geckodriver and Selenium (`pip install --break-system-packages selenium`). Firefox and geckodriver come from conda-forge with `micromamba`:

```bash
curl -sSL -o /tmp/mm.tar.bz2 https://conda.anaconda.org/conda-forge/linux-64/micromamba-2.10.0-0.tar.bz2
tar xjf /tmp/mm.tar.bz2 -C /tmp bin/micromamba
MAMBA_ROOT_PREFIX=/tmp/mmroot /tmp/bin/micromamba create -y -p /tmp/ffenv -c conda-forge firefox geckodriver
FIREFOX=/tmp/ffenv/bin/firefox GECKODRIVER=/tmp/ffenv/bin/geckodriver tests/run.sh firefox
```

Safari has no automated driver on Linux; check it on a real device.

### Translations

Every word the plugin shows goes through WordPress's translation functions with the text domain `foxfire-studio`:

- **PHP:** `__()`, `esc_html__()`, `esc_attr__()`, `_n()` for anything with a number, and `sprintf()` for sentences with something filled in. Put the whole sentence in one string, since translators may need to reorder its parts, and add a `/* translators: */` comment that says what each placeholder is.
- **Scripts:** the same `__`, `_n` and `sprintf`, from the lines at the top of each file. They come from `wp.i18n` when the page has it. A new script for the studio or the share page is registered with `rrt_register_script()`; the public player takes its words from the server instead.
- After changing any wording, run `tools/make-pot.sh` and commit `languages/foxfire-studio.pot`. The automatic checks fail until you do.

The automatic checks do not replace a real take, so these still apply:

**In a browser**, on a test post:
- Timed pace: Begin, pause, resume, arrows, Home, click a word.
- Follow my voice (1.14.0, a speech model on the device): read a paragraph, add a sentence of your own, skip a few lines, go back a sentence, and check it holds, then finds its place each time; then a recorded take, and the first look in the result.
- Pronunciation (1.15.0): with a studio tab open on a post, change a note on Foxfire Studio, Pronunciation and move a word; save a note in the old tab, then reload the screen and check both are there.
- Record a short take with cleanup and Whisper review on. Check the MP3 plays, the read-out appears, the review opens, and a few flags play the right moment.
- Review a recording with an existing MP3.
- Resize to phone width.

**On a real voice** for anything touching analysis, cleanup or the comparison. Synthetic tests have missed things every time (see DESIGN.md, section 18).

---

## Version numbers

`MAJOR.MINOR.PATCH`, with a `v` in tags (`v0.27.0`).

- **0.0.x** (the early steps): each finished, deployed step got the next patch number.
- **From 0.1.0:** minor versions are [roadmap](ROADMAP.md) milestones. Patch versions are fixes and small additions within a milestone.
- **1.0.0:** when the roadmap's finish line for stable is met.
- **After 1.0.0:** a minor version adds features and keeps everything that already works; a patch version only fixes. A major version is for a change a site owner has to act on, such as removing a setting, a shortcode, a filter or a REST route.

A new version number appears in six places, and `tests/php/checks.php` fails if the first three disagree:

1. the `Version:` line of the plugin header in `foxfire-studio.php`
2. `RRT_VERSION` in the same file
3. `Stable tag:` in `readme.txt`, with a new entry under its `== Changelog ==` (and `== Upgrade Notice ==` when a site owner should know something before updating)
4. a new heading in [CHANGELOG.md](CHANGELOG.md)
5. the version table in the README
6. `Project-Id-Version` in `languages/foxfire-studio.pot` (`tools/make-pot.sh` keeps the rest of the file up to date)

**The Help screen's guides** (1.32.0) live in `plugin/foxfire-studio/includes/help-text.php`. When a change renames a control or changes how something is done, change the guide in the same commit and run `php tools/help-export.php` to rewrite `docs/guide/`. `tests/php/help.php` fails if a guide names a control that no longer exists, or if `docs/guide/` is out of date.

When WordPress has a new major release, run the checks against it (`WP_REF=7.2 tests/wp/setup.sh /tmp/foxfire-wp-7.2`, then the checks) and raise `Tested up to:` in `readme.txt`.

---

## Releasing

1. Move the `[Unreleased]` notes in CHANGELOG.md under a new version heading. Say what changed and why.
2. Refresh download counting's lists (1.24.0) from shallow clones of [opawg/user-agents-v2](https://github.com/opawg/user-agents-v2), [skymethod/op3](https://github.com/skymethod/op3) and [X4BNet/lists_vpn](https://github.com/X4BNet/lists_vpn): `python3 tools/lists/agents.py <user-agents-v2> <op3>` and `php tools/lists/datacenters.php <lists_vpn>`. They rewrite `data/agents.php`, `data/datacenters.bin` and their licence and date files; `tests/php/downloads.php` checks every pattern still works in PHP.
3. Bump the version in the six places above, add a line or two for it at the top of `rrt_help_news()` in `includes/help-text.php` (What's new on the Help screen; keep the three newest versions), run `php tools/help-export.php` and `tools/make-pot.sh`, then `tests/run.sh`.
4. Build the zip from the branch, so it holds exactly what is committed:
   ```bash
   rm -rf /tmp/build && mkdir /tmp/build
   git archive BRANCH:plugin/foxfire-studio --prefix=foxfire-studio/ | tar x -C /tmp/build
   (cd /tmp/build && zip -qrX foxfire-studio-X.Y.Z.zip foxfire-studio)
   ```
5. Install it on a test site (Plugins, Add New Plugin, Upload Plugin, **Replace current with uploaded**; see [docs/SETUP.md](docs/SETUP.md)), empty the page cache, check a post while logged out, and make a short take.
6. Open a pull request once the build is final (each new commit on it runs the checks again). Merge it once GitHub's checks pass.
7. Tag the merge commit (`git tag -a vX.Y.Z -m "Short name"`) and create a GitHub release from the tag with the changelog entry as notes. Attach the same zip, and check the release asset's SHA-256 matches the zip that was tested.
8. If the version changed the database or the plugin's folder, add it to `RELEASES` in `tests/upgrade.py`, so every later version is checked upgrading from it.

---

## Commit messages

First line short and in plain words, saying what the change does: `Quiet clicks before phrases`. Then a blank line and, if useful, why.

---

## Keep out of the repo

This repository is written to be safe to publish. Everything in it should help someone install, configure, use, troubleshoot or change the plugin. Details of any one person's site or computer stay out, even when they explain where a change came from.

- **Credentials:** passwords, API keys, tokens, server addresses, SSH usernames and ports. Use placeholders such as `USER@HOST` in docs. If one is ever committed, treat it as exposed: rotate it first, then remove it.
- **Personal details:** real names, email addresses, locations, and details about a maintainer's home, equipment or recording room. Refer to maintainers by GitHub username where attribution is needed.
- **Site details:** the names of sites the plugin runs on, their hosting company, server paths, caching setup, theme or child theme, custom field names, and admin URLs. Use `example.com` in examples, and describe test results in general terms ("a real 4 minute take") rather than naming the post or site.
- **Recordings and personal audio.** Use short synthetic or consenting test clips, and keep them out of git (`.gitignore` covers common audio types).
- **The Whisper model files and other downloaded runtimes.** They are large, and each site downloads them from their official sources. The one exception is voice following's engine in `assets/vendor/sherpa-onnx/` (1.14.0): its official build is published only inside an archive of about 175 MB that also packs a demo model, so its three files are copied unchanged from that release, fingerprinted in `includes/follow.php`, with a README saying where they came from. Its speech model is downloaded by each site, like Whisper's. The second exception is the stronger cleanup (1.16.0): the DeepFilterNet 3 model in `assets/vendor/deepfilternet3/` (about 8.6 MB, published only inside an archive), and ONNX Runtime Web's 50 KB loader in `assets/vendor/onnxruntime-web/`, copied unchanged and fingerprinted in `includes/stronger.php`, each with a README. Its runtime's WebAssembly is Whisper's and is not shipped.

Site-specific notes, such as how a particular site is set up, belong in that site owner's own private notes.

**Commit identity:** set your Git email to your GitHub noreply address (`ID+USERNAME@users.noreply.github.com`, shown in GitHub's email settings) and your Git name to your GitHub username, so commits do not publish a personal email or legal name:

```bash
git config user.name "your-github-username"
git config user.email "ID+your-github-username@users.noreply.github.com"
```

---

## Writing style for docs and on-screen text

- Plain, warm, direct. Write the way you would explain it to a friend.
- No em dashes.
- Sentences over scores in anything the reader sees.
- Explain the why along with the what.

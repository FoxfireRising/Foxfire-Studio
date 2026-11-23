# Accessibility

Foxfire Studio aims to meet **WCAG 2.2 at level AA**, checked with automated and keyboard tests. It has not been audited by an outside company, and nothing here is a certificate or a claim of full compliance. Where something falls short, it is listed below with what is planned.

## What is covered

- **The listening player** on public posts and collection pages, and the members' locked player.
- **The admin screens:** Set up (since 1.29.0), Overview, Readings and each reading's page, Readings to make, Pronunciation, Your progress, Insights, Recordings, Reports, Podcast feed, Shared takes, Settings and the Reading audio box on the post edit screen.
- **The studio** (the teleprompter, the review, the editor and Combine takes), since 1.27.0.

## The player

- Every control is a real button or link, reached with Tab in a fixed order: Play, the progress bar, Back, Ahead, Speed, Read along, Transcript, Chapters, Resume and Download.
- Each button is named by the words it shows ("Back 15s", "Ahead 30s", "Speed 1×"), so voice control can use what is on the screen. A change of speed is read out.
- The progress bar is a slider: arrow keys move 5 seconds, Page Up and Page Down 30, Home and End the ends, and a screen reader hears "1:20 of 4:07". It is 24 px tall to touch.
- **Transcript** shows the reading's captions as lines, each a button that plays from its moment; the line being heard is marked as current. Collection pages have it too, from the joined captions. A post read aloud is its own text version; Transcript serves readings with added words, collections, and anyone who prefers to follow line by line.
- **Read along** keeps the post in its own colours: the sentence being read gets a soft tint and the word being read a stronger one with an underline, so it does not depend on colour alone.
- Every control shows a 2 px ring in the accent colour when it has focus.
- The panel is solid, so the colours checked in Settings are the colours listeners see on any theme.
- Read along's page scrolling jumps instead of gliding when the system asks for less motion.
- Without JavaScript the player is the browser's own audio element, with its captions track.

## The studio

- **Keys act where you are.** The teleprompter's keys (Space, the arrows, Home, Enter) work while the script has focus, or nothing has. Every switch, slider, menu and button keeps its own keys, so Space ticks a switch and the arrows move a slider. **Escape** from any control around the script goes back to it. **Page Up** and **Page Down**, a presentation clicker's keys, work from anywhere but a field that takes typing.
- **The script is one Tab stop**, named Script and described by the keys paragraph in the Controls panel. While it has focus, the word where the reading starts shows a ring.
- **Single-key shortcuts** (P for prompter view, B or the full stop to blank it, plus and minus for size, [ and ] for a retake's edges) act only on the script, and the **Single-key shortcuts** switch (Controls panel, Reading) turns them off for voice control users. The buttons do the same things.
- **While reading**, the menus fade away, and come back to full strength while any control in them has focus.
- **Dialogs** (the ready dialog, An unfinished recording, A take that was cut off, A take from your phone, Check microphone, Warm up, Share for feedback and the cue menu) take focus on their heading, keep Tab inside, close with Escape where closing loses nothing (never while a take is recording), and give focus back to what opened them.
- **A word cursor.** With Cues on, left and right move a word, the word is read out, and Enter opens its cue menu. With Cues off, Enter starts from the current word when paused. In the review the script is one Tab stop: left and right move word by word (each word is read out with what Whisper heard), Enter plays from the word, Space plays and pauses, and with Edit audio on Shift and the arrows select and Delete cuts. The timeline is hidden from screen readers; Flagged moments lists the same moments as buttons.
- **Help.** Every control that used to explain itself only in a hover tooltip has a **?** button that opens the explanation under it, and the control is described by it, so phones, keyboards and screen readers all get it.
- **Status read out:** a recording starting and stopping, the reading being ready (its dialog also takes focus) and the full review being ready.
- **Not by colour alone:** shaded sentences in the review start with a small text tag (fast, slow, paraphrased, unfinished), and slow shading has a dotted edge.
- **Headings:** a main heading with the post's title (hidden on screen), the panel's sections as second-level headings, and the review's heading.
- Links that open a new tab say so to screen readers.
- The intro's buttons work with Enter and Space; Escape skips it.

## Colours

Settings, Foxfire Studio, Colours has a **Readability** check: each pair of colours the player and the studio show text with (main text, labels, hints, buttons, and the text on accent-coloured buttons), its contrast ratio, and whether it reaches 4.5 to 1. The studio's words ahead of the reading line are large text (24 px and over), so they need 3 to 1: they are drawn at 42 percent of the text colour, or stronger where that would fall short, and the check lists them too. **Make it readable** saves the form and then moves only the colours that fall short, towards white or black, by the smallest step that makes every pair they are in pass. Backgrounds are never changed. Both built-in presets pass as shipped.

## How it is tested

- `tests/browser/a11y.py` runs **axe-core** (version pinned by fingerprint; a test tool, never shipped) against WCAG 2.0, 2.1 and 2.2 A and AA on the player, with its transcript open, and on every admin screen above, on WordPress's default theme. Every rule must report nothing. Two samples are left out on purpose, because they show colours as chosen: the teleprompter look preview and the Readability table's samples.
- The same test uses the player from the keyboard only (Tab order, focus rings, speed, transcript lines, read along's fallback list), measures the panel and label contrast on a light theme and the progress bar's touch height, and runs Make it readable on a failing set of colours.
- `tests/php/a11y.php` checks the contrast maths against known values, both presets, Make it readable (the smallest step, backgrounds untouched), the player's button markup and the Reading audio box's button names.
- `tests/browser/a11y_studio.py` runs axe-core the same way on the studio with the Controls panel open, during a reading, with an explanation, the microphone check, the cue menu, the ready dialog and the warm-up open, and on the review of a stand-in take. It also uses the studio from the keyboard: Space on every switch and the arrows on every slider (the reading never starts and the pace never changes), Escape to the script and the script's keys, Page Down from a switch, Tab during a reading, single-key shortcuts on and off, the ? help, each dialog's focus, Tab and Escape, the word cursor in the teleprompter and the review, the polite line, and the studio at iPhone width.
- `tests/browser/setup.py` (1.29.0) goes through the whole setup wizard from the keyboard only (Tab, the arrows on the presets and the audience, Space on the boxes, Enter on Next), runs axe-core on its notice and every step, checks each step fits at iPhone width, and checks the studio's New here offer.
- Each release with player or studio changes is also tried by keyboard on a live site.

Automated checkers catch only part of what people run into. Reports from people who use a screen reader, voice control or switch access every day are the most useful of all.

## Known gaps

- **The studio:** Page Up and Page Down belong to presentation clickers, so on a slider they start, pause or step back the reading instead of moving the slider a large step (the arrows, Home and End still move it). Dragging across words to select them in the review, and Combine takes' choice of take for each paragraph, have keyboard ways (Shift and the arrows; the take buttons) but no keyboard equivalent of the drag itself. The retake box's edge buttons explain themselves with their names and the key in their tooltip only.
- **By its nature:** a teleprompter is made for a sighted reader, and the review judges a voice by ear. The aim is that every control can be reached and understood by keyboard and screen reader, and that the review's findings can also be read as text. Prompter view's mirrored text is mirrored on purpose, for glass rigs.
- Readings without captions have no transcript. Readings and each reading's page say "No captions yet" for them.

## Reporting a problem

Open an issue on the repository, saying which screen, which browser, and any assistive technology you use. Problems that keep someone from using a feature are fixed first.

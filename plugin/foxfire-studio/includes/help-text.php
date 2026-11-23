<?php
/**
 * The words of the Help page (1.32.0; grouped, with parts, since 1.36.0):
 * every guide, the groups they are shown in, What's new, which screen opens
 * which guide, and which section of a screen opens which part of a guide.
 * Kept apart from help.php so that tools/help-export.php can write the same
 * text into docs/guide/ without WordPress.
 *
 * Each guide is a list of pieces:
 *   array( 'p', text )            a paragraph
 *   array( 'steps', array(...) )  numbered steps
 *   array( 'list', array(...) )   a plain list
 *   array( 'note', text )         a short note set apart
 *   array( 'go', label, page )    a link to a screen (an admin address
 *                                 relative to wp-admin); docs/guide shows the
 *                                 label only
 *   array( 'h', key, title )      a part of the guide with a heading of its
 *                                 own (1.36.0), reached as guide:key
 *   array( 'links', array(...) )  links to other guides by key (1.36.0)
 *
 * @package FoxfireStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The groups the guides are shown in (1.36.0), in order.
 *
 * @return array<string, string>
 */
function rrt_help_groups() {
	return array(
		'start'   => __( 'Getting started', 'foxfire-studio' ),
		'record'  => __( 'Recording and reviewing', 'foxfire-studio' ),
		'finish'  => __( 'Finishing and publishing', 'foxfire-studio' ),
		'video'   => __( 'Video, clips and embedded players', 'foxfire-studio' ),
		'podcast' => __( 'Podcast and audiobooks', 'foxfire-studio' ),
		'numbers' => __( 'Listening numbers', 'foxfire-studio' ),
		'site'    => __( 'Your site\'s setup', 'foxfire-studio' ),
		'trouble' => __( 'When something goes wrong', 'foxfire-studio' ),
	);
}

/**
 * Every guide, in the order the Help page shows them, each in one group.
 *
 * @return array<string, array{group: string, title: string, intro: string, body: array}>
 */
function rrt_help_topics() {
	return array(
		'start'       => array(
			'group' => 'start',
			'title' => __( 'Start here', 'foxfire-studio' ),
			'intro' => __( 'Which guides to read first, for the person who runs the site and for the person who reads aloud.', 'foxfire-studio' ),
			'body'  => array(
				array( 'p', __( 'Foxfire Studio has two kinds of user: the person who looks after the site, and the person who reads posts aloud. Often they are the same person wearing two hats. Pick the path that fits what you want to do today.', 'foxfire-studio' ) ),
				array( 'h', 'owner', __( 'If you look after the site', 'foxfire-studio' ) ),
				array( 'p', __( 'Begin with the setup wizard: Foxfire Studio, Set up, while it is offered, or Settings, Run setup again at any time. It covers the choices that matter on the first day in about five minutes, and nothing changes unless you change it. Then read these, in this order:', 'foxfire-studio' ) ),
				array( 'links', array( 'settings', 'player', 'privacy', 'roles', 'feed', 'upkeep' ) ),
				array( 'go', __( 'Open Settings', 'foxfire-studio' ), 'options-general.php?page=rrt-settings' ),
				array( 'h', 'reader', __( 'If you read aloud', 'foxfire-studio' ) ),
				array( 'p', __( 'Begin with the practice reading: a one-minute script that belongs to no post, so nothing you do there can go wrong. Then read these, in this order:', 'foxfire-studio' ) ),
				array( 'links', array( 'first', 'studio', 'review', 'edit', 'phone', 'keys' ) ),
				array( 'h', 'words', __( 'Words used in every guide', 'foxfire-studio' ) ),
				array(
					'list',
					array(
						__( 'The studio (also called the teleprompter) is the page where you read a post aloud and record it. It opens in a tab of its own.', 'foxfire-studio' ),
						__( 'The Controls panel is the panel beside the script in the studio (at the bottom on a phone). Its headings, such as Reading, Recording and Practice, group its controls, and the guides name them in that order: Controls, then the heading, then the control.', 'foxfire-studio' ),
						__( 'A take is one recording of a post. Every take you review goes on that post\'s take shelf for 30 days, so you can come back to it.', 'foxfire-studio' ),
						__( 'The review is what Whisper, a speech model that runs in your own browser, heard in a take, word by word, with flags on the moments worth a listen.', 'foxfire-studio' ),
						__( 'A reading is the MP3 that is on a post, usually with its captions. Visitors hear it in the listening player on the post, and podcast apps get it from the feed.', 'foxfire-studio' ),
						__( 'The Reading audio box is the box at the side of each post\'s editor that holds its reading, captions, kind of reading, language and who can hear it.', 'foxfire-studio' ),
					),
				),
			),
		),
		'first'       => array(
			'group' => 'start',
			'title' => __( 'Make your first reading', 'foxfire-studio' ),
			'intro' => __( 'From a post to a finished MP3 on that post, in one sitting.', 'foxfire-studio' ),
			'body'  => array(
				array( 'p', __( 'Before the first reading, an administrator sets up the plugin once with the setup wizard. It asks about the look, the player, privacy and files, and ends with a practice reading. It can be run again at any time from Settings, Run setup again.', 'foxfire-studio' ) ),
				array( 'go', __( 'Open Settings', 'foxfire-studio' ), 'options-general.php?page=rrt-settings' ),
				array(
					'steps',
					array(
						__( 'Open the studio: in the Posts list, hover over a post and click Teleprompter (it opens in a new tab), or go to Tools, Teleprompter and pick a post. Foxfire Studio, Readings to make also has a Start button beside each post that has no reading yet.', 'foxfire-studio' ),
						__( 'Try the practice reading first: a one-minute script that belongs to no post. The first time, click Try it under New here, and it checks your microphone first. Any time after, open it from the Controls panel, under the Practice heading, with Practice reading, or from Open the practice reading on the studio\'s first screen or on Foxfire Studio, Your progress.', 'foxfire-studio' ),
						__( 'In the Controls panel (press Controls at the top if it is closed), under the Recording heading, make sure Record my voice, Clean up audio and Whisper review are ticked.', 'foxfire-studio' ),
						__( 'Press Begin (or Space) and read. Each word lights up as you go. Press Finish recording when you reach the end. With Follow my voice off, the script stops at its last word and the recording keeps going until you press Finish recording.', 'foxfire-studio' ),
						__( 'The dialog Your reading is ready shows a short read-out of your pace and pauses. Choose Open full review to see every slip Whisper heard.', 'foxfire-studio' ),
						__( 'When you are happy with the take, press Use this recording. The MP3 and its captions go on the post, and the post shows a listening player.', 'foxfire-studio' ),
					),
				),
				array( 'p', __( 'Nothing is lost if a take is not right: it stays on the post\'s take shelf, and the next one goes beside it. Read the post again, or fix just the stumbles (see Edit and polish a take).', 'foxfire-studio' ) ),
				array( 'note', __( 'Takes are saved in your browser as you record, so a crash or a closed tab never loses one. Reopen the studio for the same post and choose Recover it.', 'foxfire-studio' ) ),
			),
		),
		'browsers'    => array(
			'group' => 'start',
			'title' => __( 'Browsers and phones', 'foxfire-studio' ),
			'intro' => __( 'What works where.', 'foxfire-studio' ),
			'body'  => array(
				array(
					'list',
					array(
						__( 'Reading, timed pace, recording, the MP3 and the Whisper review work in current Chrome, Edge, Firefox and Safari, on computers and phones.', 'foxfire-studio' ),
						__( 'Clips and full videos work in all four on a computer, and in Safari on an iPhone or iPad.', 'foxfire-studio' ),
						__( 'The larger Whisper model runs on the graphics chip where the browser offers WebGPU, as current Chrome, Edge and Safari do. When it would be slower than the take, Automatic uses the standard model instead.', 'foxfire-studio' ),
						__( 'Follow my voice is tested in Chrome; elsewhere timed pace takes over if the device cannot keep up. It follows English readings only for now.', 'foxfire-studio' ),
						__( 'Chinese, Japanese, Thai and the other languages written without spaces between words are not offered as reading languages yet.', 'foxfire-studio' ),
					),
				),
				array( 'p', __( 'Settings (under Whisper) and the studio (Controls, under Recording) both show a This browser line that says what the browser in use can do: whether it makes clips and videos, where Whisper runs, and whether its stored takes could be cleared.', 'foxfire-studio' ) ),
				array( 'p', __( 'Every browser keeps its own takes, settings and taught words. Chrome and Firefox on the same computer are as separate as two computers. Settings, Reader stats keeps each take\'s numbers on the site, and Settings, Take shelf keeps the takes themselves there, so either one follows you from browser to browser.', 'foxfire-studio' ) ),
				array( 'note', __( 'On a computer with two graphics chips, choose the faster one for your browser in the system\'s graphics settings. On Windows: Settings, System, Display, Graphics, choose the browser, set it to High performance, then close the browser fully and open it again.', 'foxfire-studio' ) ),
			),
		),
		'studio'      => array(
			'group' => 'record',
			'title' => __( 'Tools for reading', 'foxfire-studio' ),
			'intro' => __( 'Ways to make reading aloud easier before you press Begin.', 'foxfire-studio' ),
			'body'  => array(
				array( 'p', __( 'Each of these lives in the studio\'s Controls panel unless it says otherwise. Most controls there have a ? button beside them that explains them in a sentence or two.', 'foxfire-studio' ) ),
				array( 'h', 'pace', __( 'Follow my voice and timed pace', 'foxfire-studio' ) ),
				array( 'p', __( 'Under Controls, Reading, Follow my voice moves the highlight as you speak, with a small speech model on your own device; your voice never leaves the browser. It follows English readings only for now, and timed pace takes over if the device cannot keep up.', 'foxfire-studio' ) ),
				array( 'p', __( 'With Follow my voice off, the script moves at Pace (words a minute) and does not wait for you: pause at each full stop, or lower Pace. Left and right arrow keys change it while you read. Size and Width change the script\'s text, Line sets how far down the screen the reading line sits, and Breathe between paragraphs adds a short stop at the end of each paragraph. Every person\'s choices are kept in their own browser; Settings, Reading holds the starting values.', 'foxfire-studio' ) ),
				array( 'h', 'cues', __( 'Cues', 'foxfire-studio' ) ),
				array(
					'steps',
					array(
						__( 'Open the studio on the post. Under Controls, Practice, switch on Cues.', 'foxfire-studio' ),
						__( 'With the script paused, click a word. Choose a pause or a breath after it, emphasis on it, or Let this line fade for a line meant to trail off.', 'foxfire-studio' ),
						__( 'The marks show every time the post is read, for anyone. The post\'s own text never changes.', 'foxfire-studio' ),
					),
				),
				array( 'h', 'pronounce', __( 'Pronunciation notes', 'foxfire-studio' ) ),
				array( 'p', __( 'A pronunciation note shows a short hint, such as SOW-in, above a word while you read. To add one, switch on Cues as above, click the word and choose Add a note. Choose whether it belongs to every post or just this one.', 'foxfire-studio' ) ),
				array( 'p', __( 'Foxfire Studio, Pronunciation lists every note and every word taught to Whisper, across all posts. Words that may need a note lists names and long words from your published posts that have no note yet, posts still to read first; Add a note there and it shows the first time you read them, or choose Not needed. Click a note to change it. Export CSV, Export backup and Import move the list to another site. A studio tab that is already open shows changes made there once it is reloaded (Ctrl+Shift+R).', 'foxfire-studio' ) ),
				array( 'h', 'prompter', __( 'Prompter view', 'foxfire-studio' ) ),
				array( 'p', __( 'Prompter view (Controls, Prompter view, Open prompter view, or the P key) shows only the script, full screen, for a glass teleprompter rig or a screen across the room. Mirror flips it to read the right way round in the glass (most rigs need Left to right), Shift moves it over the camera lens, Fade above and below keeps your eyes near the lens, and Bright text on black reflects most clearly. Leave prompter view, or Escape, goes back.', 'foxfire-studio' ) ),
				array( 'h', 'practice', __( 'Practice and drills', 'foxfire-studio' ) ),
				array(
					'list',
					array(
						__( 'Warm up: about three minutes of breathing, tongue twisters and pacing.', 'foxfire-studio' ),
						__( 'Check microphone: a ten-second test of the room and your voice level before you record.', 'foxfire-studio' ),
						__( 'Practice reading: the one-minute script that belongs to no post. Its takes never go on a post; Your progress lists them as A practice reading.', 'foxfire-studio' ),
						__( 'Opening drill: read just the opening, again and again, and compare each take with the last.', 'foxfire-studio' ),
						__( 'Focus: one habit to work on. The review leads with it and lists its flags first.', 'foxfire-studio' ),
						__( 'Kind: the kind of reading for this post (see Kinds of reading).', 'foxfire-studio' ),
					),
				),
				array( 'h', 'backlog', __( 'Readings to make', 'foxfire-studio' ) ),
				array( 'p', __( 'Foxfire Studio, Readings to make lists your published posts that have no reading yet, newest first, with each one\'s length in words, how long it would run at your pace, how hard it looks, and its takes so far. Click a column to sort. Start opens the studio on the post in a new tab; Hide takes it off the list.', 'foxfire-studio' ) ),
				array( 'h', 'progress', __( 'Your progress and goals', 'foxfire-studio' ) ),
				array( 'p', __( 'Foxfire Studio, Your progress shows every take you have made over time, with its pace, accuracy and delivery, your personal bests and a practice log. Under Goals, Set goals lets you choose one to three targets, such as pace at least 135 and at most 150, fillers at most 3, or room quiet at least 30. Leave either box empty for a goal in one direction only. Every take is then checked against your goals here and in the studio\'s read-out after each take.', 'foxfire-studio' ) ),
			),
		),
		'review'      => array(
			'group' => 'record',
			'title' => __( 'Read the review', 'foxfire-studio' ),
			'intro' => __( 'What the numbers and flags mean, and what to do about them.', 'foxfire-studio' ),
			'body'  => array(
				array( 'p', __( 'Whisper, a speech model that runs in your own browser, listens to the take and compares it with the script word by word. Nothing is sent anywhere while it does. The review opens from Open full review in the dialog Your reading is ready.', 'foxfire-studio' ) ),
				array( 'h', 'numbers', __( 'The numbers and flags', 'foxfire-studio' ) ),
				array(
					'list',
					array(
						__( 'The first number is the share of the script\'s words Whisper heard as written. A few missed words are often Whisper mishearing a name, so listen before you read again.', 'foxfire-studio' ),
						__( 'Flags mark moments worth a listen: a skipped or changed word, a long pause, a rushed or slow stretch, a stray sound. Click a flag or a word to hear that moment.', 'foxfire-studio' ),
						__( 'Pace is words a minute. In English, between about 115 and 165 is a comfortable listening pace; a post with a kind of reading, such as verse, is judged against that kind\'s own range. In other languages the pace is a number until you have three takes in that language, then it is judged against your own.', 'foxfire-studio' ),
						__( 'When Whisper mishears a word you said correctly, click I said it right on it. It remembers, in this post and in every other.', 'foxfire-studio' ),
					),
				),
				array( 'h', 'parts', __( 'The parts of the review', 'foxfire-studio' ) ),
				array(
					'list',
					array(
						__( 'At a glance: the headline numbers for this take, and your goals if you set any.', 'foxfire-studio' ),
						__( 'How it sounded: a line each on the room, the pauses, how your sentences end, energy and pitch, and pace when there is a kind of reading to judge it by. With a Focus chosen, its line comes first.', 'foxfire-studio' ),
						__( 'Pause map: every pause, long ones marked, so you can see where the reading breathes.', 'foxfire-studio' ),
						__( 'Flagged moments: each flag with the words Whisper heard, in order. Work down the list.', 'foxfire-studio' ),
						__( 'Words Whisper has learned: the words taught with I said it right, for every post or just this one.', 'foxfire-studio' ),
						__( 'Your takes: this post\'s takes side by side (accuracy, pace, slips, clicks, sentences to retake), so you can see whether the last one was better.', 'foxfire-studio' ),
						__( 'Reading the marks: what each colour and mark in the script means.', 'foxfire-studio' ),
					),
				),
				array( 'h', 'model', __( 'Which Whisper model', 'foxfire-studio' ) ),
				array( 'p', __( 'Under Controls, Recording, Model, Automatic picks the larger Whisper model where the computer\'s graphics chip is fast enough and the standard model elsewhere. Choose Standard or Larger to decide for yourself. The larger model hears names, unusual words and quiet endings better and takes longer on a computer without a fast graphics chip.', 'foxfire-studio' ) ),
				array( 'p', __( 'Whisper hears each reading in its own language. A post is read in the site\'s language unless you choose another in its Reading audio box, under Reading language. Readings in any language but English use Whisper\'s multilingual models; English readings keep the English ones. Words taught to Whisper are kept for each language separately.', 'foxfire-studio' ) ),
				array( 'h', 'downloads', __( 'Downloads from the review', 'foxfire-studio' ) ),
				array(
					'list',
					array(
						__( 'Download MP3: the latest MP3 of this take (the edited one once it is made, with the music bed when that is switched on).', 'foxfire-studio' ),
						__( 'Captions (.vtt): the captions file, timed to this take.', 'foxfire-studio' ),
						__( 'Finish for: a one-off version finished for another place, such as an audiobook copy, without changing the site\'s choice.', 'foxfire-studio' ),
						__( 'Use this recording: puts the MP3 and its captions on the post as its reading.', 'foxfire-studio' ),
					),
				),
				array( 'note', __( 'The review lives only in its tab. Reloading the page loses it, though the take itself stays on the shelf.', 'foxfire-studio' ) ),
			),
		),
		'kinds'       => array(
			'group' => 'record',
			'title' => __( 'Kinds of reading', 'foxfire-studio' ),
			'intro' => __( 'Prose, story, verse, and ritual and chant: what each one changes.', 'foxfire-studio' ),
			'body'  => array(
				array( 'p', __( 'Each post can be given a kind of reading. The studio then starts in the middle of that kind\'s pace range, and the review judges the pace of a take against it, so a slow ritual is never flagged as too slow for prose.', 'foxfire-studio' ) ),
				array(
					'list',
					array(
						__( 'Prose: 140 to 160 words a minute.', 'foxfire-studio' ),
						__( 'Story: 130 to 150 words a minute.', 'foxfire-studio' ),
						__( 'Verse: 110 to 135 words a minute. A line that trails off is expected, so only a deep fade is flagged, and slow leveling moves the level by 2 dB at most.', 'foxfire-studio' ),
						__( 'Ritual and chant: 95 to 120 words a minute, treated like verse for fades and leveling.', 'foxfire-studio' ),
					),
				),
				array( 'p', __( 'Choose the kind in the post\'s Reading audio box, under Kind of reading, or in the studio under Controls, Practice, Kind; either saves it with the post. A post with no kind uses the starting pace from Settings, Reading, and its review uses the comfortable English range of about 115 to 165.', 'foxfire-studio' ) ),
				array( 'p', __( 'The ranges above are the starting ones. Settings, Reading, Pace by kind of reading changes them for the whole site. Sponsor messages can also be kept out of some kinds (Podcast feed, Sponsor messages, Mid-rolls in).', 'foxfire-studio' ) ),
			),
		),
		'phone'       => array(
			'group' => 'record',
			'title' => __( 'Record on a phone', 'foxfire-studio' ),
			'intro' => __( 'Phones and tablets can record every reading; long reviews are best left to a computer.', 'foxfire-studio' ),
			'body'  => array(
				array( 'p', __( 'Open the studio on the phone the same way as on a computer. The studio treats any touch-first device as a phone and keeps its work light so the browser does not close the page.', 'foxfire-studio' ) ),
				array(
					'list',
					array(
						__( 'Takes up to five minutes are reviewed on the phone by themselves, with the standard Whisper model.', 'foxfire-studio' ),
						__( 'For a longer take the phone asks first. Send to my computer keeps the take on the site, private to you, and it opens in the studio on your computer for the full review. Try it here anyway reviews it on the phone.', 'foxfire-studio' ),
						__( 'Phone take mode (Controls, Recording) sends each take to the site in pieces while you record, with a large level meter, so a closed page loses nothing. A take cut off partway can be carried on. A 10-minute take sends about 86 MB.', 'foxfire-studio' ),
						__( 'With phone take mode on, the take waits for your computer. To review a short one on the phone itself, tap Make the MP3 here, then Try it here anyway at the bottom of the result box, and keep the page open until it finishes.', 'foxfire-studio' ),
						__( 'Combine takes is only offered on a computer. With Settings, Take shelf switched on, the phone\'s short takes go on your shelf on the site too, so Combine takes on your computer can use them, and Continue on the phone picks up after a part read on a computer.', 'foxfire-studio' ),
					),
				),
				array( 'p', __( 'At most five takes can wait on the site for your computer at once, each for 14 days at most. When you open the studio on the computer, A take from your phone offers each one: Open it reviews it, Delete removes it. Foxfire Studio, Recordings, Sent from your phone lists what is waiting.', 'foxfire-studio' ) ),
				array( 'note', __( 'Keep the room quiet and the phone close and steady. Check microphone (Controls, Practice) tells you how the room and your voice sound before you begin.', 'foxfire-studio' ) ),
			),
		),
		'keys'        => array(
			'group' => 'record',
			'title' => __( 'Keyboard shortcuts', 'foxfire-studio' ),
			'intro' => __( 'The studio can be used without a mouse.', 'foxfire-studio' ),
			'body'  => array(
				array(
					'list',
					array(
						__( 'Space or Page Down starts and pauses. Page Up goes back a sentence.', 'foxfire-studio' ),
						__( 'Left and right arrows change the pace. Up and down step a paragraph.', 'foxfire-studio' ),
						__( 'Enter while paused starts from the chosen word. P opens prompter view.', 'foxfire-studio' ),
						__( 'Escape from any control goes back to the script. Press ? in the studio for the full list.', 'foxfire-studio' ),
					),
				),
				array( 'p', __( 'In the review: Delete cuts the selected words, Ctrl+Z undoes, and [ and ] move the start of a retake\'s splice by 10 ms (Shift+[ and Shift+] move its end).', 'foxfire-studio' ) ),
				array( 'p', __( 'If single-key shortcuts get in the way of a screen reader or voice control, switch them off under Controls, Reading, Single-key shortcuts. The buttons do the same things.', 'foxfire-studio' ) ),
			),
		),
		'edit'        => array(
			'group' => 'finish',
			'title' => __( 'Edit and polish a take', 'foxfire-studio' ),
			'intro' => __( 'Fix a stumble without reading the whole post again.', 'foxfire-studio' ),
			'body'  => array(
				array( 'h', 'cut', __( 'Cut and retake', 'foxfire-studio' ) ),
				array(
					'steps',
					array(
						__( 'In the full review, press Edit audio. Select words in the script to cut them (Whole sentence and Whole paragraph widen the selection), or use Tidy to have fillers, repeats and long pauses suggested, then Apply the ticked cuts.', 'foxfire-studio' ),
						__( 'To fix a sentence, select it and press Retake. Record it, then Hear it in place. The arrows beside it move each edge of the splice by 10 ms, and add or take away space filled with your room\'s own background. Use this take keeps it; Bring back the original undoes it.', 'foxfire-studio' ),
						__( 'Preview plays a few seconds around the selection, as it will sound. Undo takes back the last change.', 'foxfire-studio' ),
						__( 'Press Make edited MP3. Captions and chapters move to match the edits.', 'foxfire-studio' ),
						__( 'Check the edited version has Whisper listen again and flag anything new.', 'foxfire-studio' ),
					),
				),
				array( 'p', __( 'Editing works on a take made or opened in this tab since the page was loaded.', 'foxfire-studio' ) ),
				array( 'h', 'combine', __( 'Combine takes and Continue', 'foxfire-studio' ) ),
				array( 'p', __( 'Combine takes (at the top of the studio, beside Continue) builds one reading from several takes of the same post: choose which take reads each paragraph or sentence, or press Best of each to choose the take with the fewest flags for each paragraph, then Build the reading. Continue records the next part of a reading, starting one sentence before the first paragraph no take on the shelf has read, so a long post can be read in several sittings.', 'foxfire-studio' ) ),
				array( 'p', __( 'Both work from the post\'s take shelf: every take you review goes on it by itself, the newest six for 30 days. Clear the shelf empties it. The shelf is kept in your browser. With Settings, Take shelf switched on, it is kept on the site too and follows you to any computer or browser you sign in on: a take read on one shows on the others at once, and its recording comes from the site the first time Combine takes needs it there. Remove and Clear the shelf then work on every computer.', 'foxfire-studio' ) ),
				array( 'h', 'open', __( 'A recording made elsewhere', 'foxfire-studio' ) ),
				array( 'p', __( 'Open a recording (at the top of the studio) takes a file you already have, cleans and levels it and runs the review, as if you had just read it. Use this recording then puts it on the post with captions.', 'foxfire-studio' ) ),
			),
		),
		'sound'       => array(
			'group' => 'finish',
			'title' => __( 'Finish the sound', 'foxfire-studio' ),
			'intro' => __( 'How each take is cleaned, leveled and made into an MP3, and how to hear the difference.', 'foxfire-studio' ),
			'body'  => array(
				array( 'p', __( 'Every take is finished the same way before it becomes an MP3, in the reader\'s own browser. The Whisper review always listens to the untouched recording, so none of this changes its results. The site\'s choices are in Settings, under Broadcast sound and Music bed.', 'foxfire-studio' ) ),
				array( 'h', 'presets', __( 'Finish for', 'foxfire-studio' ) ),
				array( 'p', __( 'Settings, Broadcast sound, Finish for chooses where readings are going. Podcast, mono is the usual choice: -19 LUFS with peaks under -1 dBTP, as a mono MP3 at 128 kbps, which plays as loud as a -16 LUFS stereo show at half the file size. The other presets are Podcast, stereo; Spotify and YouTube; Broadcast (EBU R128); Audiobook (ACX); Simple leveling; and Custom, which lets you set Loudness, True peak limit and Channels yourself.', 'foxfire-studio' ) ),
				array( 'p', __( 'Steps sets the cleanup the presets share: a low rumble filter, and how strongly pops on P and B, harsh S sounds, breaths and loud words are softened (Off, Gentle, Standard or Strong). Standard is what every version before 1.13.0 made.', 'foxfire-studio' ) ),
				array( 'h', 'cleanup', __( 'Background noise', 'foxfire-studio' ) ),
				array( 'p', __( 'Background noise reduction has two engines. Standard (RNNoise) is quick. Stronger (DeepFilterNet 3) also takes out sounds with a shape, such as a bell, a creaking chair or mouth sounds, and takes about twice as long; with it, the Strong amount usually sounds best. If a device is too slow for Stronger, that take uses Standard and the review\'s Room line says so. It all applies when Clean up audio is ticked in the studio (Controls, Recording). If words start to sound watery or their endings get cut, choose a gentler amount.', 'foxfire-studio' ) ),
				array( 'h', 'polish', __( 'Voice polish', 'foxfire-studio' ) ),
				array(
					'list',
					array(
						__( 'Slow leveling evens out a level that drifts over minutes, by 4 dB at most (2 dB in verse and ritual).', 'foxfire-studio' ),
						__( 'Lower clicks between words softens mouth clicks shorter than 15 ms, using Whisper\'s word times. They are lowered, never cut.', 'foxfire-studio' ),
						__( 'EQ for each setup suggests a gentle EQ for each device and microphone from its first three takes.', 'foxfire-studio' ),
					),
				),
				array( 'p', __( 'Polish shapes new takes, Use this recording, clips and Share for feedback. The Original download stays as recorded, and readings already on posts stay as they are until you make them again.', 'foxfire-studio' ) ),
				array( 'h', 'hear', __( 'Hear the polish', 'foxfire-studio' ) ),
				array( 'p', __( 'In the review, Hear the polish plays one sentence before and after, and can try other strengths, the other engine, the EQ and click lowering on that take only. Use for this take keeps a choice for this take; Back to the Settings choice returns to the site\'s. It is the quickest way to decide a setting before changing it for everyone.', 'foxfire-studio' ) ),
				array( 'h', 'music', __( 'The music bed', 'foxfire-studio' ) ),
				array( 'p', __( 'A music bed is quiet music under a reading: it plays alone for a few seconds, dips before the first word, stays low under the voice, then rises after the last word and fades out. It is mixed in last, after any editing, so the review hears the voice alone and the Original download stays voice only.', 'foxfire-studio' ) ),
				array(
					'steps',
					array(
						__( 'In Settings, Music bed, press Add tracks and choose MP3, WAV, OGG or M4A files from the Media Library. Add a credit if the music needs one. Use only music you own or have a licence for.', 'foxfire-studio' ),
						__( 'Choose a Default track, or leave No music bed unless a post chooses one.', 'foxfire-studio' ),
						__( 'Each post can choose its own track, or none, in its Reading audio box. In the studio, Listen with music plays the take with it before you use it.', 'foxfire-studio' ),
					),
				),
				array( 'p', __( 'Under the voice sets how far below the voice it sits (Standard is 20 dB). A track shorter than the reading loops with a smooth crossfade. The credit goes into the MP3 and the podcast episode\'s notes. A reading with the music bed never gets a sponsor message in the middle, and is not suited to an audiobook.', 'foxfire-studio' ) ),
				array( 'h', 'audiobook', __( 'An audiobook copy', 'foxfire-studio' ) ),
				array( 'p', __( 'With Keep an audiobook copy ticked (Settings, Broadcast sound), Use this recording also keeps a voice-only copy finished for ACX, for the collections\' Audiobook box. It takes about 1.4 MB a minute in a private folder. A reading already on its post can get one from the review: Finish for, then Keep as this post\'s audiobook chapter.', 'foxfire-studio' ) ),
			),
		),
		'captions'    => array(
			'group' => 'finish',
			'title' => __( 'Captions and chapters', 'foxfire-studio' ),
			'intro' => __( 'Where a reading\'s captions and chapters come from, and how to fix or add them.', 'foxfire-studio' ),
			'body'  => array(
				array( 'h', 'captions', __( 'Captions', 'foxfire-studio' ) ),
				array( 'p', __( 'The review makes a reading\'s captions from the post\'s own words, timed by what Whisper heard: a line for each short sentence, longer sentences split every dozen words or so. Use this recording puts them on the post with the MP3. If the review has not finished when you press it, press Use this recording again once it has, to add the captions. After an edit, words that were cut leave the captions too.', 'foxfire-studio' ) ),
				array( 'p', __( 'Captions give the player its Transcript button and Read along, give the podcast episode its transcript, and give clips their words. A reading without them shows No captions yet on Foxfire Studio, Readings.', 'foxfire-studio' ) ),
				array( 'h', 'fix', __( 'Fixing a caption', 'foxfire-studio' ) ),
				array(
					'list',
					array(
						__( 'A wrong word in the captions is a wrong word in the post, since captions use the post\'s text. Fix the post, then record the reading again or open the same recording with Open a recording, and press Use this recording.', 'foxfire-studio' ),
						__( 'A line that starts too early or too late usually means Whisper missed a stretch. Review the take again with the larger model (Controls, Recording, Model, Larger).', 'foxfire-studio' ),
						__( 'For a small fix without a new review, download the captions file (the review\'s Captions (.vtt) button, or the Media Library), correct it in any plain text editor, upload it, and choose it in the post\'s Reading audio box under Captions for read along.', 'foxfire-studio' ),
					),
				),
				array( 'h', 'chapters', __( 'Chapters', 'foxfire-studio' ) ),
				array( 'p', __( 'Headings in the post become chapters. They are written inside the MP3 itself, so they need no file of their own: the player lists them, podcast apps that show chapters show them, and a full video\'s description gets YouTube chapter times. A collection gets one chapter for each reading, with each reading\'s own headings inside it.', 'foxfire-studio' ) ),
				array( 'h', 'own', __( 'A reading you recorded somewhere else', 'foxfire-studio' ) ),
				array( 'p', __( 'In the post\'s Reading audio box, Reading (MP3), press Choose and pick the MP3 from the Media Library. Add its captions file under Captions for read along if you have one. To have Foxfire Studio make captions for it, open the studio on the post, press Open a recording, choose the file, let the review finish, and press Use this recording. Read by adds a credit such as read by Robin under the player and in the feed.', 'foxfire-studio' ) ),
			),
		),
		'player'      => array(
			'group' => 'finish',
			'title' => __( 'Put the player on a post', 'foxfire-studio' ),
			'intro' => __( 'Where visitors hear your readings, and how to choose where it sits.', 'foxfire-studio' ),
			'body'  => array(
				array( 'p', __( 'Use this recording puts a reading on the post. You can also upload an MP3 yourself and choose it in the post\'s Reading audio box, at the side of the post editor.', 'foxfire-studio' ) ),
				array( 'p', __( 'Settings, Listening player, Show the player to chooses Nobody, Only logged-in editors (a preview while you get ready), or Everyone. Where decides where it appears:', 'foxfire-studio' ) ),
				array(
					'list',
					array(
						__( 'At the top of the post, unless the post or its template places it.', 'foxfire-studio' ),
						__( 'Only where I put it: add the Reading player block in the editor, or the [foxfire_player] shortcode.', 'foxfire-studio' ),
					),
				),
				array( 'p', __( 'Listeners get skips, speed, chapters at the post\'s headings, a transcript, links that start at a moment (add ?t=1:45 to a post\'s address), and lock screen controls on phones. Under Features you can switch on Read along (the post\'s text lights up as it is read, when the post has captions), offer to resume where a listener left off, and show a Download button. Buttons sets the skip lengths and the speeds. Show name is what podcast apps and lock screens show as the show.', 'foxfire-studio' ) ),
				array( 'p', __( 'Settings, Readability checks that the player\'s colours are easy to read, and Make it readable fixes any that are not.', 'foxfire-studio' ) ),
				array( 'h', 'off', __( 'Taking a reading off a post', 'foxfire-studio' ) ),
				array( 'p', __( 'To take a reading off a post without losing it, open the reading under Foxfire Studio, Readings and use Take off the post. It is kept aside, private, until you put it back or delete it for good (see Recordings and waiting takes).', 'foxfire-studio' ) ),
				array( 'note', __( 'After any change, empty your page cache and check the post while logged out.', 'foxfire-studio' ) ),
			),
		),
		'recordings'  => array(
			'group' => 'finish',
			'title' => __( 'Recordings and waiting takes', 'foxfire-studio' ),
			'intro' => __( 'Every reading a post has had, readings kept aside, and takes waiting for your computer.', 'foxfire-studio' ),
			'body'  => array(
				array( 'p', __( 'Foxfire Studio, Recordings has up to four lists.', 'foxfire-studio' ) ),
				array( 'h', 'list', __( 'Every recording', 'foxfire-studio' ) ),
				array( 'p', __( 'Every MP3 that has been a post\'s reading, with its length, size, bitrate, tempo, when it went live, whether it is on the post now, whether it was edited, and how it got there. Download CSV saves the list.', 'foxfire-studio' ) ),
				array( 'h', 'kept', __( 'Kept aside', 'foxfire-studio' ) ),
				array( 'p', __( 'Readings taken off their post with Take off the post (on the reading\'s page under Foxfire Studio, Readings). Each is kept in a folder visitors cannot open, and can be played here. Put back returns it to the Media Library at the address it had, with its captions, so it is the same episode in the podcast feed and its listening history carries on. Delete for good removes it after asking.', 'foxfire-studio' ) ),
				array( 'h', 'sent', __( 'Sent from your phone', 'foxfire-studio' ) ),
				array( 'p', __( 'Takes recorded on a phone in phone take mode, or sent with Send to my computer, waiting to be reviewed on a computer. At most five can wait at once. Each is deleted from the site once you use, download or delete it in the studio, and after 14 days at most. To make room, open the studio on your computer: A take from your phone offers each waiting take with Open it and Delete.', 'foxfire-studio' ) ),
				array( 'h', 'shelf', __( 'Your take shelf on the site', 'foxfire-studio' ) ),
				array( 'p', __( 'Shown while Settings, Take shelf is on: the takes of yours kept on the site, by post, with Delete for each. Only you can see or open your own. Takes leave by themselves after 30 days, and each post keeps its newest six.', 'foxfire-studio' ) ),
				array( 'note', __( 'Shared takes are listed on their own screen, Foxfire Studio, Shared takes (see Share for feedback).', 'foxfire-studio' ) ),
			),
		),
		'share'       => array(
			'group' => 'finish',
			'title' => __( 'Share for feedback', 'foxfire-studio' ),
			'intro' => __( 'Let a friend hear a take with the script and leave comments on it.', 'foxfire-studio' ),
			'body'  => array(
				array( 'p', __( 'Share for feedback (in the review) lets someone with an account on your site hear a take with the script lighting up as it plays, and leave comments pinned to a moment or a word. A friend without an account can be added as a Subscriber (Users, Add New User, Role: Subscriber); Subscribers see nothing else of the plugin.', 'foxfire-studio' ) ),
				array(
					'steps',
					array(
						__( 'In the review, press Share for feedback and choose who may hear it.', 'foxfire-studio' ),
						__( 'They get an email. They sign in, listen, leave comments, and press Done.', 'foxfire-studio' ),
						__( 'You get one email for each visit, when they press Done (or an hour after their last comment). The comments show in the review and on Foxfire Studio, Shared takes.', 'foxfire-studio' ),
					),
				),
				array( 'p', __( 'Editors and administrators can share with anyone on the site. Authors and contributors can share with the site\'s editors and administrators, and with anyone who has shared a take with them.', 'foxfire-studio' ) ),
				array( 'p', __( 'A share lasts 14 days, or until you end it. Then its audio is deleted from the site and the people you chose can no longer open it; their comments stay.', 'foxfire-studio' ) ),
			),
		),
		'clips'       => array(
			'group' => 'video',
			'title' => __( 'Make a clip or a full video', 'foxfire-studio' ),
			'intro' => __( 'Short captioned clips for social media, and the whole reading as a video for YouTube or Spotify.', 'foxfire-studio' ),
			'body'  => array(
				array( 'p', __( 'Both are made in your browser from the reading already on the post. Go to Foxfire Studio, Readings and click a reading.', 'foxfire-studio' ) ),
				array( 'h', 'clip', __( 'A clip', 'foxfire-studio' ) ),
				array(
					'steps',
					array(
						__( 'Press Make a clip, or pick one of the Suggested clips: the opening and the three strongest moments, with why each was chosen. The play button beside one plays just that stretch.', 'foxfire-studio' ),
						__( 'Choose the moment, tick the shapes (Square, Vertical, Wide), and press Make the clip.', 'foxfire-studio' ),
						__( 'Watch it before you post it. The words on the picture come from the reading\'s captions.', 'foxfire-studio' ),
					),
				),
				array( 'p', __( 'Use for the feed on a suggestion makes it the episode\'s soundbite in the podcast feed. A clip can also be made straight from a take\'s review (Make a clip), before the take goes on the post.', 'foxfire-studio' ) ),
				array( 'h', 'video', __( 'The full video', 'foxfire-studio' ) ),
				array(
					'steps',
					array(
						__( 'Further down the reading\'s page, find Full-length video. Choose a picture (the post\'s featured image, the show\'s cover art, the look\'s colours, or any picture from the Media Library), whether to put the captions on it, and the quality.', 'foxfire-studio' ),
						__( 'Press Make the video and keep the tab open until it finishes. The page shows the time left, and Cancel stops it.', 'foxfire-studio' ),
						__( 'Download it, with the description (with chapter times when the reading allows), the captions file and a thumbnail for the upload.', 'foxfire-studio' ),
					),
				),
				array( 'note', __( 'On a phone the full video goes straight to Files, Downloads (or your browser\'s downloads); the page shows no player for it.', 'foxfire-studio' ) ),
			),
		),
		'embeds'      => array(
			'group' => 'video',
			'title' => __( 'YouTube and SoundCloud players', 'foxfire-studio' ),
			'intro' => __( 'Show videos and tracks as a picture that loads the player only when someone presses play.', 'foxfire-studio' ),
			'body'  => array(
				array( 'p', __( 'A YouTube or SoundCloud player loads all of its files, and sets its cookies, as soon as a visitor scrolls near it, whether or not anyone presses play. Settings, Embedded players has a switch for each service. With one on, each of its players shows as a picture of the video or track with a play button, at the size the player will take, and the real player loads only when a visitor presses play.', 'foxfire-studio' ) ),
				array(
					'steps',
					array(
						__( 'Add a video or track to a post as you always have: the Embed, YouTube or SoundCloud block, a link pasted on its own line, or embed code copied from YouTube or SoundCloud.', 'foxfire-studio' ),
						__( 'In Settings, under Embedded players, tick YouTube videos load when a visitor presses play, SoundCloud players load when a visitor presses play, or both, and press Save embedded players.', 'foxfire-studio' ),
						__( 'Empty your page cache and open the post while logged out. You see the picture, the title and Plays from YouTube or Plays from SoundCloud. Press the play button once and the player starts in the same place.', 'foxfire-studio' ),
					),
				),
				array(
					'list',
					array(
						__( 'Nothing in your posts changes. Switch a service off and its ordinary players are back at once. The editor, your feeds and the REST API always show the ordinary players.', 'foxfire-studio' ),
						__( 'The site fetches each picture and title once and keeps the picture in its uploads folder, so visitors never contact the service before they press play. YouTube videos then play from YouTube\'s privacy-enhanced address.', 'foxfire-studio' ),
						__( 'One thing plays at a time: pressing play on a video or track pauses the reading player on the same page, and the reading player pauses them.', 'foxfire-studio' ),
						__( 'On an iPhone, Safari may show the player ready and wait for a second tap before the sound starts. That is how Safari treats sound from another site. SoundCloud may also ask whether to listen in the browser or its app; that question is SoundCloud\'s.', 'foxfire-studio' ),
						__( 'Clear saved pictures removes every saved picture; each comes back the next time its post is shown. A picture not shown for 90 days is removed by itself.', 'foxfire-studio' ),
					),
				),
				array( 'note', __( 'Players added by your theme or by other plugins are left as they are. Add the paragraph from Settings, For your privacy policy to your policy when you switch this on.', 'foxfire-studio' ) ),
			),
		),
		'feed'        => array(
			'group' => 'podcast',
			'title' => __( 'The podcast feed', 'foxfire-studio' ),
			'intro' => __( 'Every reading as a podcast episode, for Apple Podcasts, Spotify and other apps.', 'foxfire-studio' ),
			'body'  => array(
				array( 'p', __( 'Every published post with a reading becomes an episode, newest first. The feed is off until you publish it. Foxfire Studio, Podcast feed holds the show\'s details and checks the feed is ready for the directories. Podcast apps fetch readings straight from the site, so those plays are not in the listening numbers; Count downloads counts them instead (see Download counting and storage offload).', 'foxfire-studio' ) ),
				array( 'h', 'publish', __( 'Publishing the feed', 'foxfire-studio' ) ),
				array(
					'steps',
					array(
						__( 'Fill in the show: Show title (left empty, the Show name from Settings is used), Description, Cover art (a square JPEG or PNG, 1400 to 3000 pixels wide, ideally under 512 KB), Author, Owner email (public, and where Apple and Spotify send their codes), Category, Content and Copyright. Press Save.', 'foxfire-studio' ),
						__( 'Fix anything listed under Ready for directories?', 'foxfire-studio' ),
						__( 'Tick Publish the podcast feed and save.', 'foxfire-studio' ),
						__( 'Empty your page cache, then open the feed\'s address while logged out to check it.', 'foxfire-studio' ),
						__( 'Submit the address to Apple Podcasts Connect and Spotify for Creators. They check it and list the show within a few days.', 'foxfire-studio' ),
					),
				),
				array( 'p', __( 'Keep Lock ticked unless you are moving the show to a podcast host yourself: it asks other hosts not to import the show.', 'foxfire-studio' ) ),
				array( 'h', 'more', __( 'More for podcast apps', 'foxfire-studio' ) ),
				array( 'p', __( 'Apps that read the Podcasting 2.0 tags show these; the rest ignore them. Host names the person on the show, with a link and picture. Support link adds a button such as a donation page, with your own words. Place says where the show is made or what it is about. Text lines holds codes a directory may ask for to prove the show is yours. Each episode also gets its chapters, its transcript and a soundbite (the stretch chosen with Use for the feed on the reading\'s page, else the top suggested clip).', 'foxfire-studio' ) ),
				array( 'p', __( 'Pings tells Podcast Index and the WebSub hub when a new reading is in the feed, so apps fetch it within minutes. Only the feed\'s address is sent, at most once every 10 minutes. It needs a line in your privacy policy first.', 'foxfire-studio' ) ),
				array( 'h', 'collections', __( 'Collections in the feed', 'foxfire-studio' ) ),
				array( 'p', __( 'A published collection with In the podcast feed ticked is an episode too, as a Bonus (its readings are also episodes of their own) or Full. See Collections and audiobooks.', 'foxfire-studio' ) ),
				array( 'p', __( 'On the same screen: Member feeds, Sponsor messages, Storage offload, Download counting and Private folders, each with a guide of its own.', 'foxfire-studio' ) ),
			),
		),
		'members'     => array(
			'group' => 'podcast',
			'title' => __( 'Member feeds', 'foxfire-studio' ),
			'intro' => __( 'A private podcast feed for each member, with readings only members can hear.', 'foxfire-studio' ),
			'body'  => array(
				array( 'p', __( 'Member feeds give chosen people a feed of their own, with the readings for members alongside the rest. They work whether or not the public feed is published.', 'foxfire-studio' ) ),
				array( 'h', 'setup', __( 'Setting it up', 'foxfire-studio' ) ),
				array(
					'steps',
					array(
						__( 'Go to Foxfire Studio, Podcast feed, Member feeds, tick Give members their own feed, and choose Who is a member by role. Administrators always have a feed, so you can check yours. Each person\'s profile (Users, Edit) can also say Always or Never.', 'foxfire-studio' ),
						__( 'Optionally give the feed a title and a Become a member link, shown under a reading for members and in a lapsed member\'s feed. Save.', 'foxfire-studio' ),
						__( 'For each reading, choose Who can hear this reading in its Reading audio box: Everyone, Members first, then everyone (with Days for members first), or Members only. Collections have the same choice.', 'foxfire-studio' ),
					),
				),
				array( 'p', __( 'A reading for members shows a short message to visitors who may not hear it. Its files move to a private folder while it is for members, and come back when it opens to everyone.', 'foxfire-studio' ) ),
				array( 'h', 'apps', __( 'What a member does', 'foxfire-studio' ) ),
				array( 'p', __( 'Each member finds their own address on their profile (Users, Profile, under Your members podcast feed), or on any page where you place the [foxfire_member_feed] shortcode. On Podcast feed, the list Members with a feed has Email them their feed address. Then, in their app:', 'foxfire-studio' ) ),
				array(
					'list',
					array(
						__( 'Apple Podcasts on an iPhone or iPad: Library, then the ... menu, then Follow a Show by URL, and paste the address. On a Mac: File, then Follow a Show by URL.', 'foxfire-studio' ),
						__( 'Overcast: the Open in Overcast button on the profile does it in one tap.', 'foxfire-studio' ),
						__( 'Pocket Casts: paste the address into the search box, then follow the show that appears.', 'foxfire-studio' ),
						__( 'Most other podcast apps have an Add by URL or RSS choice. Spotify cannot take private feeds.', 'foxfire-studio' ),
					),
				),
				array( 'p', __( 'The address is private to its member. If it gets out, Make a new address on the profile: the old one stops working and the member adds the new one to their app. Feed activity, when switched on (with a privacy policy line first), shows when each feed was last fetched and marks an address fetched from more than 5 networks in a week as Maybe shared.', 'foxfire-studio' ) ),
			),
		),
		'sponsors'    => array(
			'group' => 'podcast',
			'title' => __( 'Sponsor messages', 'foxfire-studio' ),
			'intro' => __( 'Your own recorded messages before, in the middle of and after readings in the feed.', 'foxfire-studio' ),
			'body'  => array(
				array( 'p', __( 'Sponsor messages are short messages placed in readings in the podcast feed: a pre-roll, a mid-roll or a post-roll. The player on the post keeps the reading as it is. Every listener gets the same file, and nothing about listeners is used. Only administrators can make them.', 'foxfire-studio' ) ),
				array( 'h', 'make', __( 'Making a message', 'foxfire-studio' ) ),
				array(
					'steps',
					array(
						__( 'Go to Foxfire Studio, Sponsor messages, Add New. The title is for your list only.', 'foxfire-studio' ),
						__( 'Under The message, fill in Who it is for (the name used in the episode notes and the chapter A word from...), Kind (Paid or gifted, or Your own), an optional Link, and the Script, one paragraph a line. Add Dates if it should run only for a while.', 'foxfire-studio' ),
						__( 'Under Where it can go, tick Pre-roll, Mid-roll, Post-roll or several, and under Which readings choose every reading, some categories or some readings. Save.', 'foxfire-studio' ),
						__( 'In the Recording box at the side, record it in the studio and press Use this recording there.', 'foxfire-studio' ),
						__( 'Publish the message to start it.', 'foxfire-studio' ),
					),
				),
				array( 'p', __( 'A paid or gifted message must say so near its start: the law in Canada and the US asks for it. The screen says whether it heard a word such as sponsored in the first seconds, as a reminder.', 'foxfire-studio' ) ),
				array( 'h', 'place', __( 'Where messages go', 'foxfire-studio' ) ),
				array( 'p', __( 'Turn them on in Foxfire Studio, Podcast feed, Sponsor messages, Place sponsor messages in the feed. There, Mid-rolls sets the shortest reading that takes one and which kinds of reading may; a reading with the music bed never takes a mid-roll. Chapters gives each message a chapter listeners can skip. Episode notes sets the line at the start of the notes. Thank sponsors under the player adds a short line on the post. Members hear sponsor messages too decides member feeds. When two messages want the same place, the one with the lower Order (Post Attributes) wins.', 'foxfire-studio' ) ),
				array( 'p', __( 'Each message\'s Copies in each reading\'s sound and Where it runs list the episodes it is in now and where it has run. Copies are made again by themselves when a message or reading changes.', 'foxfire-studio' ) ),
			),
		),
		'delivery'    => array(
			'group' => 'podcast',
			'title' => __( 'Download counting and storage offload', 'foxfire-studio' ),
			'intro' => __( 'Counting podcast apps\' downloads, and serving readings from a CDN or outside storage.', 'foxfire-studio' ),
			'body'  => array(
				array( 'h', 'counting', __( 'Download counting', 'foxfire-studio' ) ),
				array( 'p', __( 'Podcast apps download readings straight from the site, so they never show in the listening numbers. Count downloads by podcast apps (Podcast feed, under the show\'s details) points each episode at a short address on the site that counts the download, then sends the file. It counts to the IAB Tech Lab guidelines, version 2.3: once per listener, episode and day, once an app has asked for a minute of audio, leaving out bots and test requests. No IP address is kept.', 'foxfire-studio' ) ),
				array(
					'steps',
					array(
						__( 'Add the counting paragraph from Settings, For your privacy policy to your policy.', 'foxfire-studio' ),
						__( 'Tick Count downloads by podcast apps and save.', 'foxfire-studio' ),
						__( 'Under Download counting, press Check counting. It tries the ways your web server can send files and picks one that lets every piece an app asks for pass the counter.', 'foxfire-studio' ),
						__( 'When the feed goes public, press Make the method page, check the draft and publish it, so sponsors and listeners can read how downloads are counted.', 'foxfire-studio' ),
					),
				),
				array( 'p', __( 'Shared networks lists places where many real listeners share one address, such as an office. Measurement prefixes adds a counting service such as OP3 in front of every public episode address; it sees listeners\' IP addresses, so it needs its own line in your privacy policy, and its numbers will differ a little.', 'foxfire-studio' ) ),
				array( 'h', 'offload', __( 'Storage offload', 'foxfire-studio' ) ),
				array( 'p', __( 'Podcast apps download whole readings, often all at once when a new one comes out. Storage offload has them fetch the files from a CDN or outside storage instead. The site always keeps its own files, and every address comes back to the site if a copy is missing or the storage stops answering. Readings for members, readings kept aside and anything private always stay on the site.', 'foxfire-studio' ) ),
				array(
					'steps',
					array(
						__( 'Under Storage offload, choose Bunny Storage, S3-compatible storage (such as Cloudflare R2, Backblaze B2, Wasabi or Amazon S3), or A CDN in front of this site (it copies files from the site by itself; nothing is uploaded).', 'foxfire-studio' ),
						__( 'Fill in the storage\'s details as the screen describes. The password or secret key can go in wp-config.php instead, which is safer. Save.', 'foxfire-studio' ),
						__( 'Press Check this setup. It uploads a small test file, reads it back the way podcast apps do, then deletes it.', 'foxfire-studio' ),
						__( 'Once the check passes, tick Serve readings from the storage below and save. Copy everything now sends the existing readings at once; new ones go by themselves.', 'foxfire-studio' ),
					),
				),
				array( 'p', __( 'Use it for chooses podcast apps and the player, or podcast apps only. Switching it off brings every address back to the site at once. Deleting the plugin never touches the outside storage: press Delete the outside copies first if you want them gone.', 'foxfire-studio' ) ),
			),
		),
		'collections' => array(
			'group' => 'podcast',
			'title' => __( 'Collections and audiobooks', 'foxfire-studio' ),
			'intro' => __( 'Several readings joined into one, and copies ready for Audible and other stores.', 'foxfire-studio' ),
			'body'  => array(
				array( 'h', 'collection', __( 'Making a collection', 'foxfire-studio' ) ),
				array(
					'steps',
					array(
						__( 'Go to Foxfire Studio, Collections, Add New, and give it a title and, if you like, some text and a featured image.', 'foxfire-studio' ),
						__( 'In Readings in this collection, search for each post with a reading and add it, in the order they should play. Choose the Gap between readings.', 'foxfire-studio' ),
						__( 'Tick In the podcast feed if it should be an episode, and choose Bonus or Full. Choose Who can hear this collection.', 'foxfire-studio' ),
						__( 'Save or publish. The readings are joined into one MP3 on the site, each copied exactly as it is on its post, with a chapter for each and their captions joined.', 'foxfire-studio' ),
					),
				),
				array( 'p', __( 'The collection has its own page with the player. If a reading changes, the collection is joined again by itself; if one is taken off its post, the collection keeps its file and says so until it is saved again.', 'foxfire-studio' ) ),
				array( 'h', 'audiobook', __( 'An audiobook', 'foxfire-studio' ) ),
				array( 'p', __( 'The Audiobook box on a saved collection makes one file for each reading to the rules of Audible (ACX), which Spotify for Authors, Authors Republic and Findaway also accept: room tone at each end, the right level and format, opening and closing credits, and a retail sample. Nothing of it is sent anywhere.', 'foxfire-studio' ) ),
				array(
					'steps',
					array(
						__( 'Under Opening credits and Closing credits, check the scripts, press Record in the studio, read them there and press Use for the audiobook.', 'foxfire-studio' ),
						__( 'Under Retail sample, choose the reading for the sample and where it starts (one to five minutes, starting with narration, nothing explicit).', 'foxfire-studio' ),
						__( 'Set the collection\'s featured image as the cover: square, 2400 by 2400 pixels or larger.', 'foxfire-studio' ),
						__( 'Press Make the audiobook. It downloads a ZIP with every file, the cover and a check report.', 'foxfire-studio' ),
					),
				),
				array( 'p', __( 'Each chapter uses the reading\'s audiobook copy when it has one (see Finish the sound), or is made from the post\'s reading. A reading with the music bed cannot be used, since the music fills the pauses where the room should be heard. The check report lists what passed and what ACX also asks that cannot be checked here.', 'foxfire-studio' ) ),
				array( 'note', __( 'ACX takes an audiobook only for a book already on Amazon as a Kindle ebook, from a rights holder in the US, UK, Canada or Ireland. Listen through once for clicks, bumps or a word read twice before you upload.', 'foxfire-studio' ) ),
			),
		),
		'stats'       => array(
			'group' => 'numbers',
			'title' => __( 'Listening numbers and reports', 'foxfire-studio' ),
			'intro' => __( 'What listeners do with each reading, measured on your own site.', 'foxfire-studio' ),
			'body'  => array(
				array( 'p', __( 'Listener analytics is off on new sites. Add the paragraph from Settings, For your privacy policy to your policy first, then tick Measure listening under Settings, Listener analytics. Each screen has a Period menu at the top, and What these numbers mean at the bottom.', 'foxfire-studio' ) ),
				array( 'note', __( 'Your own visits are left out. To test, use a logged-out browser without Do Not Track, play for a few seconds, then close the tab.', 'foxfire-studio' ) ),
				array( 'h', 'overview', __( 'Overview', 'foxfire-studio' ) ),
				array( 'p', __( 'Plays, how many visitors pressed play, how many were still listening at one minute and heard to the end, average speed and listening time, each compared with the period before. Below them: plays each day, top readings and sources, how visitors read or listen, and how long they take to press play. Changes lets you mark a change on a date (a new cover, a new microphone) so it shows as a flag on the charts. Raw data downloads every visit as CSV.', 'foxfire-studio' ) ),
				array( 'h', 'readings', __( 'Readings', 'foxfire-studio' ) ),
				array( 'p', __( 'Every reading with its plays and how much was heard. Click one for its own page: its listening curve, with the sentence that was playing where listeners leave, where its listeners came from, how they listened, the take it came from, and the clip maker and full video.', 'foxfire-studio' ) ),
				array( 'h', 'sources', __( 'Sources and the link builder', 'foxfire-studio' ) ),
				array( 'p', __( 'Channels and campaigns that bring listeners, with how many pressed play and heard to the end; that matters more than clicks. Channels with fewer than 30 plays are too small to compare. The Link builder makes a tagged link for each place you share a reading (choose the post, the channel, an optional campaign and a start time, then Copy link), so each one shows up as its own source.', 'foxfire-studio' ) ),
				array( 'h', 'insights', __( 'Insights', 'foxfire-studio' ) ),
				array( 'p', __( 'Plain sentences about what lines up with readings heard to the end: length, tempo, tags, the day and time published, where listeners came from, and your delivery. Each stays quiet until there is enough to go on, usually six readings with at least 30 plays each, and says what it still needs. Your reading, below, works from your own full takes once you have five.', 'foxfire-studio' ) ),
				array( 'h', 'reports', __( 'Reports', 'foxfire-studio' ) ),
				array( 'p', __( 'An email with the same numbers as the dashboard, totals only. Under Reports, tick Send the report by email on a schedule, choose How often (monthly by default, or weekly, every two weeks or every quarter), the time of day, who it goes to (up to 10 addresses) and what to include. Preview shows it as on a computer or a phone; Send now sends one at once without changing the schedule. Past reports keeps the archive.', 'foxfire-studio' ) ),
				array( 'h', 'more', __( 'More switches', 'foxfire-studio' ) ),
				array( 'p', __( 'Under Settings, Listener analytics: Leave out chooses whose visits are not counted; Privacy signals respects Do Not Track and Global Privacy Control; Returning listeners tells new listeners from returning ones (it needs a policy line first); Google Analytics also sends plays to a Google Analytics already on the site; Keep sets how long visits are kept; Trusted proxies is for a site behind a CDN. Listener countries can download a free country database so countries show. Recent listens confirms that listens arrive.', 'foxfire-studio' ) ),
			),
		),
		'settings'    => array(
			'group' => 'site',
			'title' => __( 'Settings, section by section', 'foxfire-studio' ),
			'intro' => __( 'What each part of Foxfire Studio\'s Settings screen does.', 'foxfire-studio' ),
			'body'  => array(
				array( 'p', __( 'Settings is under Foxfire Studio, Settings (and Settings, Foxfire Studio in WordPress\'s own menu). The main form\'s choices are saved with Save settings at its bottom; Take shelf and Embedded players have save buttons of their own. Each person\'s own studio choices are kept in their browser; these are the starting values. Each section\'s heading on the screen has a Help link to its part below.', 'foxfire-studio' ) ),
				array( 'h', 'look', __( 'Look, wording, colours and fonts', 'foxfire-studio' ) ),
				array( 'p', __( 'Look starts from a preset (Neutral or Dark), which replaces the wording, colours and fonts. Wording holds the words the studio and player show, such as the name in the control bar and the label on the player. Teleprompter look chooses the Foxfire Studio artwork or your WordPress theme\'s colours, and an optional Intro video for the studio. Colours sets the player\'s and studio\'s colours; Readability, under them, checks every pair against WCAG 2.2 AA, and Make it readable fixes only the ones that fall short. Fonts sets the reading, heading and button fonts and an optional Google Fonts link.', 'foxfire-studio' ) ),
				array( 'h', 'reading', __( 'Reading', 'foxfire-studio' ) ),
				array( 'p', __( 'Starting values for pace, text size, column width and the reading line; Pace by kind of reading (see Kinds of reading); which studio switches start on; and Content that can be read: posts, pages, collections and sponsor messages.', 'foxfire-studio' ) ),
				array( 'h', 'sound', __( 'Broadcast sound', 'foxfire-studio' ) ),
				array( 'p', __( 'How each reading is cleaned, leveled and made into an MP3: Finish for, Steps, Voice polish, Background noise reduction and Audiobook copy. See Finish the sound.', 'foxfire-studio' ) ),
				array( 'h', 'music', __( 'Music bed', 'foxfire-studio' ) ),
				array( 'p', __( 'Tracks to play quietly under readings, and how far under the voice. See Finish the sound, The music bed.', 'foxfire-studio' ) ),
				array( 'h', 'whisper', __( 'Whisper and Whisper on this site', 'foxfire-studio' ) ),
				array( 'p', __( 'This browser says what the browser in use can do. Load Whisper from chooses this site or Hugging Face. Whisper on this site downloads the standard model (about 116 MB) to your hosting, so reviews load from your site; The larger model (about 570 MB) and Other languages (about 650 MB) can be downloaded the same way. Keep the page open while each download runs. Whisper always runs on the reader\'s own computer either way.', 'foxfire-studio' ) ),
				array( 'h', 'player', __( 'Listening player', 'foxfire-studio' ) ),
				array( 'p', __( 'Who sees the player, where it goes, extra custom fields to look for a reading or a Read by credit in, the skip and speed buttons, Read along, resume and Download, and the Show name. See Put the player on a post.', 'foxfire-studio' ) ),
				array( 'h', 'stats', __( 'Reader stats', 'foxfire-studio' ) ),
				array( 'p', __( 'Keep stats on this site keeps each take\'s numbers, the words you have taught Whisper and a record of each post\'s reading on the site, so your history follows you to any computer. Only numbers are kept, never recordings. When the plugin is deleted decides whether everything else goes too (see Update, back up or remove the plugin).', 'foxfire-studio' ) ),
				array( 'h', 'analytics', __( 'Listener analytics, countries and recent listens', 'foxfire-studio' ) ),
				array( 'p', __( 'Measure listening and its options (see Listening numbers and reports), Listener countries, and Recent listens, a quick check that listens arrive.', 'foxfire-studio' ) ),
				array( 'h', 'shelf', __( 'Take shelf', 'foxfire-studio' ) ),
				array( 'p', __( 'Keep each person\'s take shelf on the site, so it follows them to any computer or browser, with Space for every shelf in MB. When the space is used up, new takes stay in the browser and the studio says so. Delete every shelf on the site removes them all. Press Save take shelf settings.', 'foxfire-studio' ) ),
				array( 'h', 'embeds', __( 'Embedded players', 'foxfire-studio' ) ),
				array( 'p', __( 'The two switches that make YouTube and SoundCloud players wait for play. See YouTube and SoundCloud players.', 'foxfire-studio' ) ),
				array( 'h', 'files', __( 'Voice following, fonts, encoder and noise model on this site', 'foxfire-studio' ) ),
				array( 'p', __( 'Voice following on this site downloads Follow my voice\'s model (about 45 MB). Fonts, MP3 encoder and noise model on this site downloads copies of those too, so the studio makes no outside requests apart from Whisper and voice following when their files are not here. After changing the font link, save, then download again.', 'foxfire-studio' ) ),
				array( 'h', 'privacy', __( 'For your privacy policy', 'foxfire-studio' ) ),
				array( 'p', __( 'A paragraph describing the listener analytics and whichever of its options are on, ready to paste into your privacy policy. It is also offered in WordPress\'s own privacy policy guide (Settings, Privacy).', 'foxfire-studio' ) ),
				array( 'go', __( 'Open Settings', 'foxfire-studio' ), 'options-general.php?page=rrt-settings' ),
			),
		),
		'roles'       => array(
			'group' => 'site',
			'title' => __( 'Who can do what', 'foxfire-studio' ),
			'intro' => __( 'Which WordPress roles can record, see the numbers and change settings.', 'foxfire-studio' ),
			'body'  => array(
				array(
					'list',
					array(
						__( 'Administrators: everything, including Settings, Podcast feed, Reports, Sponsor messages, the setup wizard and every listening number.', 'foxfire-studio' ),
						__( 'Editors: the studio on any post, Use this recording, Your progress (Overview opens it for them), Insights about their own reading, Pronunciation, Shared takes, Collections and Help. They can share a take with anyone on the site.', 'foxfire-studio' ),
						__( 'Authors: the same on their own posts and collections. They share takes with editors and administrators.', 'foxfire-studio' ),
						__( 'Contributors: the studio and its review on their own drafts, Your progress, Pronunciation, Shared takes and Help. Putting a reading on a post needs the right to upload files, which contributors do not have: they download the MP3, or share the take with an editor who can put it on.', 'foxfire-studio' ),
						__( 'Subscribers: none of the plugin\'s screens. They can hear a take shared with them, and can be members with a feed of their own (see Member feeds).', 'foxfire-studio' ),
					),
				),
				array( 'p', __( 'Overview, Readings, Readings to make, Sources, Recordings and the listener half of Insights show numbers and readings across the whole site, so they are for administrators. A developer can widen this, and who may make sponsor messages or share with anyone, with the filters rrt_dashboard_cap, rrt_spot_capability and rrt_share_anyone_capability.', 'foxfire-studio' ) ),
			),
		),
		'privacy'     => array(
			'group' => 'site',
			'title' => __( 'Privacy and your privacy policy', 'foxfire-studio' ),
			'intro' => __( 'What stays in your browser, what reaches your site, and which switches need a policy line first.', 'foxfire-studio' ),
			'body'  => array(
				array( 'p', __( 'Recording, cleanup, the MP3 and the Whisper review all happen in the reader\'s own browser. A recording reaches your site only when the reader presses Use this recording, shares a take, sends a phone take to their computer, or reviews a take while Settings, Take shelf is on. The plugin sends nothing to its authors.', 'foxfire-studio' ) ),
				array( 'p', __( 'These are off until you switch them on, and each needs a line in your privacy policy first:', 'foxfire-studio' ) ),
				array(
					'list',
					array(
						__( 'Listener analytics, and Returning listeners with it (Settings).', 'foxfire-studio' ),
						__( 'Google Analytics events (Settings).', 'foxfire-studio' ),
						__( 'Download counting, Measurement prefixes and Pings (Podcast feed).', 'foxfire-studio' ),
						__( 'Feed activity for member feeds (Podcast feed).', 'foxfire-studio' ),
						__( 'Embedded players (Settings).', 'foxfire-studio' ),
					),
				),
				array( 'p', __( 'Settings, Take shelf is off on new sites too. When it is on, each person\'s reviewed takes are kept on the site, where only they can open them, for 30 days at most. Settings, Embedded players is off as well. When it is on, visitors contact YouTube or SoundCloud only when they press play; until then the site shows its own copy of the picture.', 'foxfire-studio' ) ),
				array( 'p', __( 'Settings, For your privacy policy gives the wording to copy. To make no outside requests at all, download Whisper, voice following, the fonts, the MP3 encoder and the noise model to your site from Settings. Readers can ask for their own stats to be exported or erased with WordPress\'s personal data tools (Tools, Export Personal Data and Erase Personal Data).', 'foxfire-studio' ) ),
				array( 'h', 'folders', __( 'Private folders', 'foxfire-studio' ) ),
				array( 'p', __( 'Readings for members, readings kept aside, takes sent from a phone, shared takes, take shelves and audiobook copies are kept in folders in uploads that visitors are turned away from. Foxfire Studio, Podcast feed, Private folders, Check private folders puts a small test file in each and asks for it the way a visitor would, then deletes the test files. Run it after moving the site or changing its server or security plugin.', 'foxfire-studio' ) ),
				array( 'note', __( 'This is not legal advice.', 'foxfire-studio' ) ),
			),
		),
		'listeners'   => array(
			'group' => 'site',
			'title' => __( 'What listeners get', 'foxfire-studio' ),
			'intro' => __( 'The player as your visitors meet it, including with a keyboard or a screen reader.', 'foxfire-studio' ),
			'body'  => array(
				array(
					'list',
					array(
						__( 'Play, skip back and ahead, and speed, with a keyboard as well as a mouse or a finger. The buttons are named for screen readers, and a new speed is read out.', 'foxfire-studio' ),
						__( 'Chapters at the post\'s headings, and links that start at a moment.', 'foxfire-studio' ),
						__( 'Transcript, on every reading with captions: every line as a button that plays from there, the line being heard marked.', 'foxfire-studio' ),
						__( 'Read along, where switched on: the post\'s own text lights up as it is read.', 'foxfire-studio' ),
						__( 'Lock screen and headphone controls on phones, and resume where they left off, remembered in their own browser only.', 'foxfire-studio' ),
						__( 'Only one thing plays at a time: starting one reading, or a YouTube or SoundCloud player, pauses the others.', 'foxfire-studio' ),
					),
				),
				array( 'p', __( 'Settings, Readability checks every colour pair the player uses against WCAG 2.2 AA, and Make it readable fixes only those that fall short. The studio itself works without a mouse too (see Keyboard shortcuts).', 'foxfire-studio' ) ),
				array( 'p', __( 'A reading without captions has no Transcript or Read along. Give your readings captions (see Captions and chapters) so deaf and hard of hearing visitors, and anyone in a quiet room, can follow.', 'foxfire-studio' ) ),
			),
		),
		'upkeep'      => array(
			'group' => 'site',
			'title' => __( 'Update, back up or remove the plugin', 'foxfire-studio' ),
			'intro' => __( 'Installing a new version, what to back up, and what removing it deletes.', 'foxfire-studio' ),
			'body'  => array(
				array( 'h', 'update', __( 'Updating', 'foxfire-studio' ) ),
				array(
					'steps',
					array(
						__( 'Download the new version\'s zip file. Do not unzip it.', 'foxfire-studio' ),
						__( 'In WordPress, go to Plugins, Add New Plugin, Upload Plugin, choose the zip and press Install Now.', 'foxfire-studio' ),
						__( 'WordPress says the plugin is already installed: press Replace current with uploaded.', 'foxfire-studio' ),
						__( 'Empty your page cache. In any studio tab that is open, press Ctrl+Shift+R; on a phone, close the tab and open the page again.', 'foxfire-studio' ),
					),
				),
				array( 'p', __( 'Your settings, readings, takes and numbers are all kept through an update. Foxfire Studio, Help, What\'s new lists what changed.', 'foxfire-studio' ) ),
				array( 'h', 'backup', __( 'What to back up', 'foxfire-studio' ) ),
				array( 'p', __( 'Your host\'s usual backup of the database and the uploads folder covers everything. The database holds the settings, listening numbers, reader stats and each post\'s choices; uploads holds the readings, captions and the plugin\'s own folders (whose names start with rrt-). Keep backup copies outside the site\'s public folder.', 'foxfire-studio' ) ),
				array( 'h', 'remove', __( 'Removing it', 'foxfire-studio' ) ),
				array( 'p', __( 'Deactivate it, then Delete it on the Plugins screen. Before you do, decide on Settings, Reader stats, When the plugin is deleted:', 'foxfire-studio' ) ),
				array(
					'list',
					array(
						__( 'Left unticked (the default), everything stays, so reinstalling loses nothing. Only the settings and the plugin\'s copies of the fonts, MP3 encoder and noise model are removed.', 'foxfire-studio' ),
						__( 'Ticked, everything else goes too: reader stats, listener analytics, cue marks, pronunciation notes, words taught for one post, shared and sent takes, take shelves, each post\'s reading, captions and Read by links, readings kept aside, collections and their joined files, audiobook copies, saved YouTube and SoundCloud pictures, and the Whisper files on this site.', 'foxfire-studio' ),
					),
				),
				array( 'p', __( 'Either way, MP3s and captions in the Media Library are never deleted, readings kept for members go back to their public place first, and outside storage is never touched (press Delete the outside copies under Storage offload first if you want them gone).', 'foxfire-studio' ) ),
			),
		),
		'trouble'     => array(
			'group' => 'trouble',
			'title' => __( 'When something goes wrong', 'foxfire-studio' ),
			'intro' => __( 'Common problems and what usually fixes them.', 'foxfire-studio' ),
			'body'  => array(
				array( 'h', 'studio', __( 'In the studio', 'foxfire-studio' ) ),
				array(
					'list',
					array(
						__( 'The studio or player looks out of date after an update: empty the page cache, then press Ctrl+Shift+R in the studio (on a phone, close the tab and open the page again).', 'foxfire-studio' ),
						__( 'The microphone was not available: the browser was not allowed to use it. Click the lock or settings icon beside the page\'s address, allow the microphone, and reload. On an iPhone: Settings, Apps, Safari, Microphone.', 'foxfire-studio' ),
						__( 'The recording was very quiet: move closer to the microphone, or use a USB microphone. Check microphone (Controls, Practice) shows the level before you record.', 'foxfire-studio' ),
						__( 'The script runs ahead of you: lower Pace (Controls, Reading), or switch on Follow my voice.', 'foxfire-studio' ),
						__( 'Follow my voice turns itself off: the line under the script says why. Download voice following to this site in Settings, or close other tabs.', 'foxfire-studio' ),
						__( 'A take is missing after a crash: open the studio for the same post again and choose Recover it.', 'foxfire-studio' ),
					),
				),
				array( 'h', 'review', __( 'In the review', 'foxfire-studio' ) ),
				array(
					'list',
					array(
						__( 'The Whisper review stays on loading: the first load is large. Wait, or download Whisper to this site from Settings.', 'foxfire-studio' ),
						__( 'Whisper did not hear enough: the take was very short or very quiet. Use Check microphone and record a longer stretch.', 'foxfire-studio' ),
						__( 'Read words show grey as not read: Whisper heard a stretch wrongly. Review again with the larger model (Controls, Recording, Model).', 'foxfire-studio' ),
						__( 'A reading in another language reviews badly: check the post\'s Reading language in its Reading audio box. Download the other-languages models to this site in Settings so they load quickly.', 'foxfire-studio' ),
						__( 'The review was lost: it lives only in its tab, so a reload loses it. The take is still on the shelf; open it again from there.', 'foxfire-studio' ),
					),
				),
				array( 'h', 'phone', __( 'On a phone', 'foxfire-studio' ) ),
				array(
					'list',
					array(
						__( 'A phone closed the page during a review: open the studio again; the take is on the shelf. Next time use Send to my computer for long takes.', 'foxfire-studio' ),
						__( 'Five takes are already waiting for your computer: open the studio on the computer and open or delete one (A take from your phone).', 'foxfire-studio' ),
						__( 'The take shelf on the site is full: the take stays in the browser, and the studio says so. Delete old takes under Foxfire Studio, Recordings, or raise Space for every shelf in Settings, Take shelf.', 'foxfire-studio' ),
					),
				),
				array( 'h', 'making', __( 'Clips, videos and collections', 'foxfire-studio' ) ),
				array(
					'list',
					array(
						__( 'A video stops partway: keep the tab in front and the computer awake until it finishes, and close other heavy tabs. Choose Smaller file for a long reading.', 'foxfire-studio' ),
						__( 'A collection will not join a reading: the reading must be a constant-bitrate MP3 on this site. Make it again in the studio, or leave it out.', 'foxfire-studio' ),
						__( 'The audiobook check fails on a chapter with the music bed: make that chapter\'s audiobook copy from the review (Finish for, Keep as this post\'s audiobook chapter).', 'foxfire-studio' ),
					),
				),
				array( 'h', 'site', __( 'On the site', 'foxfire-studio' ) ),
				array(
					'list',
					array(
						__( 'No listens appear: analytics is off, or you tested while logged in, or your browser sends Do Not Track.', 'foxfire-studio' ),
						__( 'Every listener comes from the same place: the site is behind a proxy such as Cloudflare. List it under Settings, Listener analytics, Trusted proxies.', 'foxfire-studio' ),
						__( 'A directory turns the feed down: look at Ready for directories? on Podcast feed. The cover art\'s size and the owner email are the usual causes.', 'foxfire-studio' ),
						__( 'Check private folders reports a folder as open: the web server is not reading the folder\'s own rules. Ask your host to allow .htaccess files in uploads, or to block those folders another way.', 'foxfire-studio' ),
						__( 'Players look unstyled or the page shows old pictures after a change: empty the page cache and check the page while logged out.', 'foxfire-studio' ),
					),
				),
			),
		),
	);
}

/**
 * What's new, newest first: a few lines a version.
 *
 * @return array<string, string[]>
 */
function rrt_help_news() {
	return array(
		'1.36.1' => array(
			__( 'Small fixes: a misheard first or last word in the review is a misread word, Follow my voice finishes a take whose last word was misheard, and the switches in the studio\'s Controls panel line up.', 'foxfire-studio' ),
		),
		'1.36.0' => array(
			__( 'Fuller help: 28 guides in eight groups, a Start here guide for site owners and for readers, new guides for Settings, finishing the sound, captions and chapters, recordings, kinds of reading, who can do what, updating and removing the plugin, and what listeners get. Settings, Podcast feed and Recordings have a Help with this section link under each section.', 'foxfire-studio' ),
		),
		'1.35.0' => array(
			__( 'YouTube and SoundCloud players can wait for play: switch on Settings, Embedded players, and each one shows as a picture with a play button, so the page loads faster and visitors contact the service only when they press play.', 'foxfire-studio' ),
		),
	);
}

/**
 * Which guide each Foxfire Studio screen opens, by page slug or post type:
 * a guide's key, or guide:part for one part of it (1.36.0).
 *
 * @return array<string, string>
 */
function rrt_help_screens() {
	return array(
		'rrt-dashboard'  => 'stats',
		'rrt-readings'   => 'stats:readings',
		'rrt-sources'    => 'stats:sources',
		'rrt-insights'   => 'stats:insights',
		'rrt-reports'    => 'stats:reports',
		'rrt-recordings' => 'recordings',
		'rrt-backlog'    => 'studio:backlog',
		'rrt-pronounce'  => 'studio:pronounce',
		'rrt-progress'   => 'studio:progress',
		'rrt-shares'     => 'share',
		'rrt-podcast'    => 'feed',
		'rrt-settings'   => 'settings',
		'rrt_collection' => 'collections',
		'rrt_spot'       => 'sponsors',
	);
}

/**
 * The Help link beside each section of a screen (1.36.0): for each page slug,
 * a section heading exactly as the screen shows it, and the guide or part it
 * opens.
 *
 * @return array<string, array<string, string>>
 */
function rrt_help_sections() {
	return array(
		'rrt-settings'   => array(
			__( 'Look', 'foxfire-studio' )                  => 'settings:look',
			__( 'Wording', 'foxfire-studio' )               => 'settings:look',
			__( 'Teleprompter look', 'foxfire-studio' )     => 'settings:look',
			__( 'Colours', 'foxfire-studio' )               => 'settings:look',
			__( 'Fonts', 'foxfire-studio' )                 => 'settings:look',
			__( 'Reading', 'foxfire-studio' )               => 'settings:reading',
			__( 'Broadcast sound', 'foxfire-studio' )       => 'sound',
			__( 'Music bed', 'foxfire-studio' )             => 'sound:music',
			__( 'Whisper', 'foxfire-studio' )               => 'settings:whisper',
			__( 'Listening player', 'foxfire-studio' )      => 'player',
			__( 'Reader stats', 'foxfire-studio' )          => 'settings:stats',
			__( 'Listener analytics', 'foxfire-studio' )    => 'stats:more',
			__( 'Take shelf', 'foxfire-studio' )            => 'settings:shelf',
			__( 'Embedded players', 'foxfire-studio' )      => 'embeds',
			__( 'Whisper on this site', 'foxfire-studio' )  => 'settings:whisper',
			__( 'Voice following on this site', 'foxfire-studio' ) => 'settings:files',
			__( 'Fonts, MP3 encoder and noise model on this site', 'foxfire-studio' ) => 'settings:files',
			__( 'Listener countries', 'foxfire-studio' )    => 'settings:analytics',
			__( 'Recent listens', 'foxfire-studio' )        => 'settings:analytics',
			__( 'For your privacy policy', 'foxfire-studio' ) => 'privacy',
		),
		'rrt-podcast'    => array(
			__( 'Ready for directories?', 'foxfire-studio' ) => 'feed:publish',
			__( 'More for podcast apps', 'foxfire-studio' )  => 'feed:more',
			__( 'Member feeds', 'foxfire-studio' )           => 'members',
			__( 'Members with a feed', 'foxfire-studio' )    => 'members:apps',
			__( 'Sponsor messages', 'foxfire-studio' )       => 'sponsors:place',
			__( 'Storage offload', 'foxfire-studio' )        => 'delivery:offload',
			__( 'Download counting', 'foxfire-studio' )      => 'delivery:counting',
			__( 'Private folders', 'foxfire-studio' )        => 'privacy:folders',
		),
		'rrt-recordings' => array(
			__( 'Kept aside', 'foxfire-studio' )                 => 'recordings:kept',
			__( 'Sent from your phone', 'foxfire-studio' )       => 'recordings:sent',
			__( 'Your take shelf on the site', 'foxfire-studio' ) => 'recordings:shelf',
		),
	);
}

<!-- Written by tools/help-export.php from plugin/foxfire-studio/includes/help-text.php. Change the words there, then run the tool again. -->

# Update, back up or remove the plugin

*Installing a new version, what to back up, and what removing it deletes.*

## Updating

1. Download the new version's zip file. Do not unzip it.
2. In WordPress, go to Plugins, Add New Plugin, Upload Plugin, choose the zip and press Install Now.
3. WordPress says the plugin is already installed: press Replace current with uploaded.
4. Empty your page cache. In any studio tab that is open, press Ctrl+Shift+R; on a phone, close the tab and open the page again.

Your settings, readings, takes and numbers are all kept through an update. Foxfire Studio, Help, What's new lists what changed.

## What to back up

Your host's usual backup of the database and the uploads folder covers everything. The database holds the settings, listening numbers, reader stats and each post's choices; uploads holds the readings, captions and the plugin's own folders (whose names start with rrt-). Keep backup copies outside the site's public folder.

## Removing it

Deactivate it, then Delete it on the Plugins screen. Before you do, decide on Settings, Reader stats, When the plugin is deleted:

- Left unticked (the default), everything stays, so reinstalling loses nothing. Only the settings and the plugin's copies of the fonts, MP3 encoder and noise model are removed.
- Ticked, everything else goes too: reader stats, listener analytics, cue marks, pronunciation notes, words taught for one post, shared and sent takes, take shelves, each post's reading, captions and Read by links, readings kept aside, collections and their joined files, audiobook copies, saved YouTube and SoundCloud pictures, and the Whisper files on this site.

Either way, MP3s and captions in the Media Library are never deleted, readings kept for members go back to their public place first, and outside storage is never touched (press Delete the outside copies under Storage offload first if you want them gone).

[All guides](README.md)

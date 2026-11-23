<!-- Written by tools/help-export.php from plugin/foxfire-studio/includes/help-text.php. Change the words there, then run the tool again. -->

# Download counting and storage offload

*Counting podcast apps' downloads, and serving readings from a CDN or outside storage.*

## Download counting

Podcast apps download readings straight from the site, so they never show in the listening numbers. Count downloads by podcast apps (Podcast feed, under the show's details) points each episode at a short address on the site that counts the download, then sends the file. It counts to the IAB Tech Lab guidelines, version 2.3: once per listener, episode and day, once an app has asked for a minute of audio, leaving out bots and test requests. No IP address is kept.

1. Add the counting paragraph from Settings, For your privacy policy to your policy.
2. Tick Count downloads by podcast apps and save.
3. Under Download counting, press Check counting. It tries the ways your web server can send files and picks one that lets every piece an app asks for pass the counter.
4. When the feed goes public, press Make the method page, check the draft and publish it, so sponsors and listeners can read how downloads are counted.

Shared networks lists places where many real listeners share one address, such as an office. Measurement prefixes adds a counting service such as OP3 in front of every public episode address; it sees listeners' IP addresses, so it needs its own line in your privacy policy, and its numbers will differ a little.

## Storage offload

Podcast apps download whole readings, often all at once when a new one comes out. Storage offload has them fetch the files from a CDN or outside storage instead. The site always keeps its own files, and every address comes back to the site if a copy is missing or the storage stops answering. Readings for members, readings kept aside and anything private always stay on the site.

1. Under Storage offload, choose Bunny Storage, S3-compatible storage (such as Cloudflare R2, Backblaze B2, Wasabi or Amazon S3), or A CDN in front of this site (it copies files from the site by itself; nothing is uploaded).
2. Fill in the storage's details as the screen describes. The password or secret key can go in wp-config.php instead, which is safer. Save.
3. Press Check this setup. It uploads a small test file, reads it back the way podcast apps do, then deletes it.
4. Once the check passes, tick Serve readings from the storage below and save. Copy everything now sends the existing readings at once; new ones go by themselves.

Use it for chooses podcast apps and the player, or podcast apps only. Switching it off brings every address back to the site at once. Deleting the plugin never touches the outside storage: press Delete the outside copies first if you want them gone.

[All guides](README.md)

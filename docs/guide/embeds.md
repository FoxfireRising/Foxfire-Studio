<!-- Written by tools/help-export.php from plugin/foxfire-studio/includes/help-text.php. Change the words there, then run the tool again. -->

# YouTube and SoundCloud players

*Show videos and tracks as a picture that loads the player only when someone presses play.*

A YouTube or SoundCloud player loads all of its files, and sets its cookies, as soon as a visitor scrolls near it, whether or not anyone presses play. Settings, Embedded players has a switch for each service. With one on, each of its players shows as a picture of the video or track with a play button, at the size the player will take, and the real player loads only when a visitor presses play.

1. Add a video or track to a post as you always have: the Embed, YouTube or SoundCloud block, a link pasted on its own line, or embed code copied from YouTube or SoundCloud.
2. In Settings, under Embedded players, tick YouTube videos load when a visitor presses play, SoundCloud players load when a visitor presses play, or both, and press Save embedded players.
3. Empty your page cache and open the post while logged out. You see the picture, the title and Plays from YouTube or Plays from SoundCloud. Press the play button once and the player starts in the same place.

- Nothing in your posts changes. Switch a service off and its ordinary players are back at once. The editor, your feeds and the REST API always show the ordinary players.
- The site fetches each picture and title once and keeps the picture in its uploads folder, so visitors never contact the service before they press play. YouTube videos then play from YouTube's privacy-enhanced address.
- One thing plays at a time: pressing play on a video or track pauses the reading player on the same page, and the reading player pauses them.
- On an iPhone, Safari may show the player ready and wait for a second tap before the sound starts. That is how Safari treats sound from another site. SoundCloud may also ask whether to listen in the browser or its app; that question is SoundCloud's.
- Clear saved pictures removes every saved picture; each comes back the next time its post is shown. A picture not shown for 90 days is removed by itself.

> Players added by your theme or by other plugins are left as they are. Add the paragraph from Settings, For your privacy policy to your policy when you switch this on.

[All guides](README.md)

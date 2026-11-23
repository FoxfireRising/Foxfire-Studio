Mediabunny and its AAC encoder, for clips and full-length videos (Foxfire Studio 1.30.0)
======================================================================================

What this is
------------
Mediabunny writes the MP4 files the clip maker and the full-length video
make in the browser (assets/js/clip.js and assets/js/video.js). It replaced
mp4-muxer, whose author now points to Mediabunny. It can store frames that
an encoder sends out of display order (B-frames), so every browser can use
H.264 High profile.

The AAC encoder is FFmpeg's AAC-LC encoder built as WebAssembly, packaged
as a Mediabunny add-on. It is loaded only in browsers that have no AAC
encoder of their own (Firefox on every system, Chrome on Linux), so their
clips and videos get AAC sound too. Everything runs in the browser; nothing
is sent anywhere.

Where these files come from
---------------------------
They are copied unchanged from the npm packages

  mediabunny 1.66.2
  https://registry.npmjs.org/mediabunny/-/mediabunny-1.66.2.tgz
  SHA-256 0a1a1011496a7527175bb7ada56ecc4371244f6a00f082d77fd3e668230eb01e
  dist/bundles/mediabunny.min.cjs          saved as mediabunny.min.js

  @mediabunny/aac-encoder 1.66.2
  https://registry.npmjs.org/@mediabunny/aac-encoder/-/aac-encoder-1.66.2.tgz
  SHA-256 7bf3ef97572e1d14803282251a519c576780cbc58b28714967a4ebf7a286e8e6
  dist/bundles/mediabunny-aac-encoder.min.js

Both files set a global (window.Mediabunny and window.MediabunnyAacEncoder).
Their own SHA-256 fingerprints are in includes/clip.php
(rrt_clip_vendor_hashes) and checked by the tests.

Licences
--------
Mediabunny and the add-on: Mozilla Public License 2.0 (LICENSE.txt).
FFmpeg's AAC encoder inside the add-on: GNU Lesser General Public License
2.1 (FFMPEG-COPYING.LGPLv2.1.txt, FFMPEG-LICENSE.md). Both may be shipped
with a GPL-2.0-or-later plugin. The add-on's source (its C bridge, worker
and the FFmpeg build settings) is in the npm package's src folder and in
the Mediabunny repository on GitHub (Vanilagy/mediabunny); FFmpeg's source
is at https://ffmpeg.org/download.html.

Updating
--------
Download both packages at the same version (they are released
together), copy the two files above, and update the version,
the package fingerprints here and the file fingerprints in
includes/clip.php.

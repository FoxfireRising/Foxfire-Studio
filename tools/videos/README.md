# The docs site's short videos

`make.sh` records the five videos in `docs/media/` from the throwaway test WordPress the tests use (`/tmp/foxfire-wp`), so they can be made again when a screen changes. They are silent, with captions in the picture and the same words written beside each video on the docs site.

- **Made-up content only:** the site "Lantern Lane", the post "The Keeper of the Light" (`post.html`) and its picture (`lighthouse.jpg`, drawn for this). Never a real site, voice, account or reading.
- **Stand-ins:** the microphone is Chrome's stand-in fed with tone bursts, and Whisper is the tests' stand-in (`tests/browser/whisper_stub.py`), which "hears" the post with a skipped word, a filler, a misheard word and a long pause (`take2.py`). Every screen is the plugin's own; the hearing is staged, and the docs site says so.
- **How:** Chrome's screencast frame by frame (`rec.py`), a visible pointer that moves to each control before it is pressed (`pointer.py`), and ffmpeg, which makes a 30 fps H.264 MP4 at 1280 by 720 with the captions burned into a band under the page and a still for each. `ranges.py` answers byte ranges for the audio, which `php -S` cannot, so the player can jump.
- **Files:** `v1.py` making a first reading, `v2.py` the review, `v3.py` editing, `v4.py` the player (as a visitor, signed out), `v5.py` clips. `put.py` puts the made-up take on the post first.

Watch every video before committing it. They live outside the plugin folder, so they never reach the zip.

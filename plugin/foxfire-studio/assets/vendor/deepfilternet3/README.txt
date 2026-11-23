DeepFilterNet 3 model, for the stronger cleanup (Foxfire Studio 1.16.0)
======================================================================

What this is
------------
DeepFilterNet 3, a speech enhancement model by Hendrik Schröter and others
(https://github.com/Rikorose/DeepFilterNet). The stronger cleanup runs it
in a Web Worker (assets/js/deepfilter.js) on the reader's own device, over a
finished reading. The audio never leaves the browser.

Where these files come from
---------------------------
They are copied unchanged from the model archive in the official repository

  models/DeepFilterNet3_onnx.tar.gz
  https://github.com/Rikorose/DeepFilterNet, commit
  7b14b895fe7fc42cab72b4a4c1e49c982bd6404b
  SHA-256 c94d91f70911001c946e0fabb4aa9adc37045f45a03b56008cb0c8244cb63616

  enc.onnx       the encoder
  erb_dec.onnx   the decoder for the gain in each ERB band
  df_dec.onnx    the decoder for the deep filter's taps

The archive also holds config.ini, whose settings (48 kHz, 960-point frames,
32 ERB bands, 96 deep-filter bins, order 5, 2 frames of lookahead) are
written into assets/js/deepfilter.js. Their own SHA-256 fingerprints are in
includes/stronger.php (rrt_stronger_models) and are checked by the browser
before the model is used, and by the tests. They are shipped with the plugin
because the official model is published only inside that archive.

Licence
-------
DeepFilterNet is under either the MIT License (LICENSE-MIT.txt) or the
Apache License 2.0 (LICENSE-APACHE.txt), at your option. Both texts are
beside this file.

To update
---------
Take the model archive of a newer release, check it, copy the three files
here, compare its config.ini with the constants at the top of
assets/js/deepfilter.js, update the fingerprints in includes/stronger.php,
and run tests/run.sh. Listen to a real noisy take before releasing.

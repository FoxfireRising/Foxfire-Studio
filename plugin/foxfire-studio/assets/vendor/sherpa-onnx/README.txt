sherpa-onnx speech engine, for voice following (Foxfire Studio 1.14.0)
=====================================================================

What this is
------------
The official WebAssembly build of sherpa-onnx, the speech recognition
engine from the k2-fsa project (https://github.com/k2-fsa/sherpa-onnx).
Voice following runs it in a Web Worker (assets/js/follow-worker.js) on the
reader's own device. The audio never leaves the browser.

Where these files come from
---------------------------
They are copied unchanged from the release archive

  sherpa-onnx-wasm-simd-v1.12.20-en-asr-zipformer.tar.bz2
  https://github.com/k2-fsa/sherpa-onnx/releases/download/v1.12.20/
  SHA-256 97268f0836d8383e040ce8182e69d2e65ed20ea213c43db0e7e08ec9a27dc809

  sherpa-onnx-asr.js             the JavaScript wrapper
  sherpa-onnx-wasm-main-asr.js   the engine's loader
  sherpa-onnx-wasm-main-asr.wasm the engine

Their own SHA-256 fingerprints are in includes/follow.php
(rrt_follow_engine_hashes) and checked by the tests.

The archive also holds a demo model packed into a .data file of about
190 MB. It is not shipped: the worker hands the loader an empty package
instead (Module.getPreloadedPackage), and loads its own model, a smaller
one, from the site or from Hugging Face. The engine is shipped with the
plugin because its official build is published only inside that archive.

Licences
--------
sherpa-onnx is under the Apache License 2.0 (LICENSE.txt, beside this file).
The build includes ONNX Runtime (MIT License, Microsoft) and
kaldi-native-fbank (Apache License 2.0). The model the worker loads,
csukuangfj/sherpa-onnx-streaming-zipformer-en-20M-2023-02-17 on Hugging
Face, is under the Apache License 2.0.

To update
---------
Download a newer release archive of the same name, check it, copy the
three files here, update RRT_FOLLOW_ENGINE and the fingerprints in
includes/follow.php, and run tests/run.sh. Check a real reading with
Follow my voice before releasing.

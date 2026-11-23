ONNX Runtime Web loader, for the stronger cleanup (Foxfire Studio 1.16.0)
========================================================================

What this is
------------
ort.wasm.min.js, the JavaScript side of ONNX Runtime Web (MIT License,
Microsoft Corporation, https://github.com/microsoft/onnxruntime). The
stronger cleanup's worker (assets/js/deepfilter.js) loads it to run the
DeepFilterNet 3 model. Its WebAssembly runtime is not shipped: it is the
same runtime the Whisper review already uses, from the site's Whisper files
when they have been downloaded, otherwise from jsDelivr, and its two files
are checked against their SHA-256 fingerprints before use.

Where this file comes from
--------------------------
Copied unchanged from dist/ort.wasm.min.js in the onnxruntime-web npm
package at the version in RRT_ORT_VERSION (includes/whisper.php). The
loader and the runtime must be the same version, so update them together.
Its SHA-256 is in includes/stronger.php (rrt_stronger_loader_sha) and is
checked by the tests.

Licence
-------
MIT License

Copyright (c) Microsoft Corporation

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.

#!/bin/bash
# Foxfire Studio: puts Whisper on your own server.
# Downloads the speech model and the code that runs it into
# wp-content/uploads/rrt-whisper/. Safe to run again; it just refreshes the files.
#
# Set G below to your site's uploads folder before running.

G="$HOME/PATH-TO-SITE/wp-content/uploads/rrt-whisper"
M="$G/onnx-community/whisper-base.en_timestamped"
HF="https://huggingface.co/onnx-community/whisper-base.en_timestamped/resolve/main"
TJS="https://cdn.jsdelivr.net/npm/@huggingface/transformers@4.3.2/dist/transformers.min.js"
ORT="https://cdn.jsdelivr.net/npm/onnxruntime-web@1.31.0-dev.20260914-8d85527a0/dist"

mkdir -p "$M/onnx" "$G/lib"
problems=0

get() {
	echo "Downloading $(basename "$2")..."
	if ! curl -fsSL -o "$2" "$1"; then
		echo "  MISSING: $1"
		rm -f "$2"
		problems=$((problems + 1))
	fi
}

for f in config.json generation_config.json preprocessor_config.json tokenizer.json tokenizer_config.json onnx/encoder_model_quantized.onnx onnx/decoder_model_merged_quantized.onnx; do
	get "$HF/$f" "$M/$f"
done

get "$TJS" "$G/lib/transformers.min.js"

for v in .asyncify ""; do
	get "$ORT/ort-wasm-simd-threaded$v.mjs" "$G/lib/ort-wasm-simd-threaded$v.js"
	get "$ORT/ort-wasm-simd-threaded$v.wasm" "$G/lib/ort-wasm-simd-threaded$v.wasm"
done

echo ""
echo "Files now in rrt-whisper:"
cd "$G" && find . -type f -exec ls -lh {} \; | awk '{print $5, $9}'
echo ""
du -sh "$G"
if [ "$problems" -eq 0 ]; then
	echo "All done. Whisper is on your site."
else
	echo "$problems file(s) could not be downloaded. Check the MISSING lines above, then run the script again."
fi

"""Shared by the browser tests' stand-in transformers.js (1.9.1).

Since 1.9.1 the Whisper worker cuts a long recording into pieces and gives
each to the model on its own, marking each piece with rrtOffset (seconds
into the whole), rrtTotal (the whole's length) and rrtLast. A stand-in works
out the words for the whole recording, as before, then answers each piece
with the words that start in it, timed from the piece's own start. PIECE is
that last step, as JavaScript; a stand-in's pipeline returns
`async function (audio) { return piece(audio, <its answer for the whole>); }`
and reads the whole's length as `total(audio)`.
"""

PIECE = (
	'function total(audio) { return audio.rrtTotal || audio.length / 16000; }\n'
	'function piece(audio, r) {\n'
	'  if (audio.rrtOffset === undefined) { return r; }\n'
	'  const off = audio.rrtOffset, end = off + audio.length / 16000, last = audio.rrtLast;\n'
	'  const c = r.chunks.filter(x => x.timestamp[0] >= off - 1e-6 && (last || x.timestamp[0] < end))\n'
	'    .map(x => ({ text: x.text, timestamp: [x.timestamp[0] - off, x.timestamp[1] === null ? null : x.timestamp[1] - off] }));\n'
	'  return { chunks: c, text: c.map(x => x.text).join("") };\n'
	'}\n'
)

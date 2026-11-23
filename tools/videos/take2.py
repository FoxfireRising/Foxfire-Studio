import io, math, re, struct, wave, tempfile
MISHEARD = {'wick': 'wig'}
def plan(words):
	out = []; t = 0.8
	for w in words:
		k = re.sub(r'[^a-z0-9]', '', w.lower())
		if not k: continue
		if k == 'softly': continue
		w2 = MISHEARD.get(k, w)
		dur = 0.12 + 0.045 * len(k)
		out.append((w2, t, t + dur)); t += dur + 0.1
		if w.endswith(('.', ',')): t += 0.35 if w.endswith('.') else 0.15
		if k == 'steps': out.append(('um', t, t + 0.3)); t += 0.45
		if k == 'it' and w.endswith('.'): t += 2.4
	return out, t + 1.0
def wav_for(heard, seconds, rate=22050):
	n = int(seconds * rate); x = [0.0] * n; seed = 11
	for i in range(n):
		seed = (seed * 1103515245 + 12345) % 2147483648; x[i] = (seed / 2147483648 - 0.5) * 0.004
	for k, (_, s, e) in enumerate(heard):
		a, b = int(s * rate), int(e * rate); f = 150 + 25 * (k % 6)
		for i in range(a, min(b, n)):
			env = min(1.0, (i - a) / 300.0, (b - i) / 300.0)
			x[i] += 0.3 * env * math.sin(2 * math.pi * f * (i - a) / rate)
	buf = io.BytesIO(); w = wave.open(buf, 'wb'); w.setnchannels(1); w.setsampwidth(2); w.setframerate(rate)
	w.writeframes(b''.join(struct.pack('<h', max(-32767, min(32767, int(v * 32767)))) for v in x)); w.close()
	f = tempfile.NamedTemporaryFile(suffix='.wav', delete=False, prefix='the-keeper-take-'); f.write(buf.getvalue()); f.close(); return f.name
def chunks(heard):
	return [{'text': ' ' + w, 'timestamp': [round(s, 3), round(e, 3)]} for (w, s, e) in heard]

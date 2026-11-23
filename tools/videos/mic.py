import io, math, struct, wave, tempfile
def mic_wav(secs=8, rate=48000):
	"""Speech-like bursts for Chrome's stand-in microphone."""
	n = rate * secs; x = [0.0] * n; seed = 7
	for i in range(n):
		seed = (seed * 1103515245 + 12345) % 2147483648
		x[i] = (seed / 2147483648 - 0.5) * 0.003
	t = 0.15; k = 0
	while t < secs - 0.4:
		a = int(t * rate); L = 0.18 + 0.12 * ((k * 7) % 5) / 4; b = a + int(L * rate)
		f = 150 + 25 * (k % 6)
		for i in range(a, min(b, n)):
			env = min(1.0, (i - a) / 400.0, (b - i) / 400.0)
			x[i] += 0.3 * env * (math.sin(2 * math.pi * f * (i - a) / rate) + 0.4 * math.sin(4 * math.pi * f * (i - a) / rate))
		t += L + (0.09 if k % 9 else 0.5); k += 1
	buf = io.BytesIO(); w = wave.open(buf, 'wb'); w.setnchannels(1); w.setsampwidth(2); w.setframerate(rate)
	w.writeframes(b''.join(struct.pack('<h', max(-32767, min(32767, int(v * 32767)))) for v in x)); w.close()
	f = tempfile.NamedTemporaryFile(suffix='.wav', delete=False); f.write(buf.getvalue()); f.close(); return f.name

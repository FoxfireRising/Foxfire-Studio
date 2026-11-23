/*
 * Loads a plugin script into Node for unit tests. Scripts that export with
 * module.exports are required; the rest attach to window, so a bare window
 * object is provided first.
 */
'use strict';
const path = require('path');
const fs = require('fs');
const vm = require('vm');

const JS = path.join(__dirname, '..', '..', 'plugin', 'foxfire-studio', 'assets', 'js');

function load(file, globalName) {
	const full = path.join(JS, file);
	if (!globalName) {
		return require(full);
	}
	const sandbox = { console, Math, Float32Array, Float64Array, Int16Array, Uint8Array, Uint32Array, URL, TextEncoder, TextDecoder };
	sandbox.window = sandbox;
	sandbox.document = { addEventListener() {}, querySelectorAll() { return []; }, readyState: 'loading' };
	vm.createContext(sandbox);
	vm.runInContext(fs.readFileSync(full, 'utf8'), sandbox, { filename: full });
	return sandbox[globalName];
}

module.exports = { load, JS };

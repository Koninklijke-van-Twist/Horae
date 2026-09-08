const { readFileSync } = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const events = {};
const classes = new Set(['bsn-hidden']);
let focused = true;
let reloaded = false;
const toggle = { addEventListener: (key, callback) => { events.click = callback; }, setAttribute() {} };
const document = {
  hidden: false,
  hasFocus: () => focused,
  documentElement: { classList: {
    add: value => classes.add(value),
    contains: value => classes.has(value),
    toggle: (value, yes) => yes ? classes.add(value) : classes.delete(value)
  } },
  addEventListener: (key, callback) => { events[key] = callback; },
  getElementById: () => toggle
};
const window = {
  addEventListener: document.addEventListener,
  location: { reload: () => { reloaded = true; } }
};
vm.runInNewContext(readFileSync(__dirname + '/../web/assets/bsn-privacy.js', 'utf8'), { document, window });
const hidden = () => classes.has('bsn-hidden');
events.DOMContentLoaded();
assert.equal(hidden(), false);
focused = false;
events.blur();
assert.equal(hidden(), true);
focused = true;
events.focus();
assert.equal(hidden(), false);
document.hidden = true;
events.visibilitychange();
assert.equal(hidden(), true);
document.hidden = false;
events.visibilitychange();
assert.equal(hidden(), false);
for (const shortcut of [{ key: 'PrintScreen' }, { key: '4', metaKey: true, shiftKey: true }, { key: 's', metaKey: true, shiftKey: true }]) {
  events.keydown(shortcut);
  assert.equal(hidden(), true);
  events.focus();
  assert.equal(hidden(), true, 'Screenshot suspicion stays masked after focus');
  events.click();
  assert.equal(hidden(), false, 'User explicitly reveals values');
}
events.click();
events.focus();
assert.equal(hidden(), true, 'Manual hiding survives focus');
events.click();
events.pagehide();
assert.equal(hidden(), true);
events.pageshow({ persisted: true });
assert.equal(reloaded, true);
assert.equal(hidden(), true);
console.log('BSN privacy tests passed');

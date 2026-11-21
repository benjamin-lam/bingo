import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { runInNewContext } from 'node:vm';

const html = await readFile(new URL('../docs/index.html', import.meta.url), 'utf8');
const app = await readFile(new URL('../docs/app.js', import.meta.url), 'utf8');
const css = await readFile(new URL('../docs/style.css', import.meta.url), 'utf8');
assert.match(html, /<table id="bingo-card"[\s\S]*?<caption/);
assert.doesNotMatch(html, /id="bingo-card"[^>]*role="group"/);
assert.match(css, /\.bingo-cell:focus-visible/);

class Element {
  constructor(tagName = 'div') {
    this.tagName = tagName;
    this.children = [];
    this.attributes = {};
    this.dataset = {};
    this.classList = { add() {}, remove() {} };
    this.hidden = false;
    this.disabled = false;
    this._text = '';
    this.innerHTML = '';
  }
  set textContent(value) { this._text = String(value); this.children = []; }
  get textContent() { return this._text; }
  setAttribute(name, value) { this.attributes[name] = String(value); }
  append(...items) { this.children.push(...items); }
  replaceChildren(...items) { this.children = items; }
  addEventListener() {}
  querySelector(selector) {
    if (selector === 'thead') return this.head;
    if (selector === 'tbody') return this.body;
    return null;
  }
  querySelectorAll(selector) {
    if (selector === '[data-focus-key]') return collect(this).filter((item) => item.dataset.focusKey);
    return [];
  }
  contains(element) { return collect(this).includes(element); }
  focus() { focusCalls++; document.activeElement = this; }
}

function collect(root) {
  return [root, ...root.children.flatMap(collect)];
}

const selectors = [
  '#home', '#game-view', '#notice', '#game-id', '#connection-status', '#round-label',
  '#round-heading', '#round-number', '#number-display', '#actions', '#drawn-numbers',
  '#drawn-count', '#players-list', '#player-count', '#card-section', '#bingo-card',
  '#game-message', '#create-game', '#join-form', '#copy-id', '#leave-game'
];
const elements = new Map(selectors.map((selector) => [selector, new Element()]));
elements.get('#home').hidden = false;
elements.get('#game-view').hidden = true;
const table = elements.get('#bingo-card');
table.head = new Element('thead');
table.body = new Element('tbody');
table.children = [table.head, table.body];
table.querySelector = (selector) => selector === 'thead' ? table.head : selector === 'tbody' ? table.body : null;

let stateRequests = 0;
let focusCalls = 0;
let intervalCallback;
let gameStatus = 'playing';
const card = Array.from({ length: 5 }, (_, column) => Array.from({ length: 5 }, (_, row) => column === 2 && row === 2 ? null : column * 15 + row + 1));
const state = {
  game: { id: 'B7K4MT', role: 'player', status: gameStatus, round: 1, current_number: 1, drawn: [1], winner: null },
  players: [{ id: 1, name: 'Fokus-Test' }],
  card,
  marks: []
};
const localStorage = { getItem: () => JSON.stringify({ gameId: 'B7K4MT', token: 'test-token', role: 'player' }), setItem() {}, removeItem() {} };
const document = {
  activeElement: null,
  querySelector: (selector) => elements.get(selector) ?? null,
  createElement: (tagName) => new Element(tagName),
  contains(element) { return collect(elements.get('#game-view')).includes(element); }
};
elements.get('#game-view').contains = (element) => collect(table).includes(element);
elements.get('#game-view').querySelectorAll = () => collect(table).filter((element) => element.dataset.focusKey);
const window = { BINGO_CONFIG: { apiBaseUrl: 'https://example.test/api' }, confirm: () => true };
const sandbox = {
  document, window, localStorage, console,
  AbortController,
  FormData: class {},
  navigator: { clipboard: { writeText: async () => {} } },
  fetch: async (_url, options) => {
    const body = JSON.parse(options.body);
    if (body.action === 'state') stateRequests++;
    return { ok: true, json: async () => state };
  },
  setTimeout: () => 1,
  clearTimeout() {},
  setInterval: (callback) => { intervalCallback = callback; return 2; },
  clearInterval() {}
};
runInNewContext(app, sandbox);
await new Promise((resolve) => setImmediate(resolve));
assert.equal(stateRequests, 1, 'initial polling should issue one state request');
assert.equal(table.head.children[0].children.length, 6, 'table should have a row-header plus five column headers');
assert.equal(table.body.children.length, 5, 'table should have five semantic rows');
assert.equal(table.head.children[0].children[1].scope, 'col');
assert.equal(table.body.children[0].children[0].scope, 'row');
assert.equal(table.body.children[0].children[1].headers, 'bingo-row-1 bingo-column-B');
const firstButton = collect(table.body).find((element) => element.tagName === 'button');
assert.equal(firstButton.attributes['aria-pressed'], 'false');
assert.equal(firstButton.attributes['aria-label'].includes('Zeile 1, Spalte B'), true);
assert.equal(firstButton.textContent, '1');
const focused = collect(table).find((element) => element.dataset.focusKey === 'cell:1');
document.activeElement = focused;
await intervalCallback();
assert.equal(stateRequests, 2, 'polling callback should issue another state request');
assert.equal(focusCalls, 0, 'unchanged polling does not rebuild the card or refocus controls');
assert.equal(document.activeElement, focused, 'focus remains on the same cell after an unchanged response');
state.marks = [1];
await intervalCallback();
assert.equal(stateRequests, 3, 'changed polling response should be applied');
assert.equal(focusCalls, 1, 'focus is restored after rendering an actual state update');
assert.notEqual(document.activeElement, focused, 'rendering replaces the old cell element');
assert.equal(document.activeElement.dataset.focusKey, 'cell:1');
assert.equal(document.activeElement.attributes['aria-pressed'], 'true', 'updated marking state is announced programmatically');
assert.equal(state.game.status, gameStatus);
console.log('OK: Frontend DOM-/A11y-Prüfungen');
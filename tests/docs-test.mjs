import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

function assertNoDuplicateObjectKeys(source, label) {
  let position = 0;
  const skipWhitespace = () => {
    while (/\s/.test(source[position] ?? '')) position += 1;
  };
  const parseString = () => {
    const start = position;
    position += 1;
    while (position < source.length) {
      if (source[position] === '\\') {
        position += 2;
      } else if (source[position++] === '"') {
        return JSON.parse(source.slice(start, position));
      }
    }
    assert.fail(`${label} contains an unterminated JSON string`);
  };
  const parseValue = () => {
    skipWhitespace();
    if (source[position] === '{') {
      position += 1;
      skipWhitespace();
      const keys = new Set();
      if (source[position] === '}') {
        position += 1;
        return;
      }
      while (position < source.length) {
        skipWhitespace();
        assert.equal(source[position], '"', `${label} object keys must be strings`);
        const key = parseString();
        assert.ok(!keys.has(key), `${label} contains duplicate object key ${JSON.stringify(key)}`);
        keys.add(key);
        skipWhitespace();
        assert.equal(source[position++], ':', `${label} object key must be followed by a colon`);
        parseValue();
        skipWhitespace();
        if (source[position] === '}') {
          position += 1;
          return;
        }
        assert.equal(source[position++], ',', `${label} object properties must be comma-separated`);
      }
      assert.fail(`${label} contains an unterminated JSON object`);
    }
    if (source[position] === '[') {
      position += 1;
      skipWhitespace();
      if (source[position] === ']') {
        position += 1;
        return;
      }
      while (position < source.length) {
        parseValue();
        skipWhitespace();
        if (source[position] === ']') {
          position += 1;
          return;
        }
        assert.equal(source[position++], ',', `${label} array values must be comma-separated`);
      }
      assert.fail(`${label} contains an unterminated JSON array`);
    }
    if (source[position] === '"') {
      parseString();
      return;
    }
    const start = position;
    while (position < source.length && !/[\s,[\]{}]/.test(source[position])) position += 1;
    assert.ok(position > start, `${label} contains an invalid JSON value`);
  };

  parseValue();
  skipWhitespace();
  assert.equal(position, source.length, `${label} contains trailing data`);
}

const todoSource = await readFile(new URL('../TODO.json', import.meta.url), 'utf8');
assertNoDuplicateObjectKeys(todoSource, 'TODO.json');
const todo = JSON.parse(todoSource);
const reportSource = await readFile(new URL(`../reviews/${todo.date}-stabilisierung.json`, import.meta.url), 'utf8');
assertNoDuplicateObjectKeys(reportSource, 'stabilization report');
const reportDocument = JSON.parse(reportSource);
const report = reportDocument.report;
const reportMarkdown = await readFile(new URL(`../reviews/${todo.date}-stabilisierung.md`, import.meta.url), 'utf8');
const todoMarkdown = await readFile(new URL('../TODO.md', import.meta.url), 'utf8');
const agents = await readFile(new URL('../AGENTS.md', import.meta.url), 'utf8');
const spec = await readFile(new URL('../SPEC.md', import.meta.url), 'utf8');
const expectedFindings = Array.from({ length: 7 }, (_, index) => `BINGO-REV-${String(index + 1).padStart(3, '0')}`);
const findingIds = report.findings.map((finding) => finding.id).sort();
assert.deepEqual(findingIds, expectedFindings, 'JSON report should document all seven stable finding IDs exactly once');
assert.equal(new Set(findingIds).size, findingIds.length, 'finding IDs should be unique');
for (const finding of report.findings) {
  assert.ok(['fixed_verified', 'implemented_unverified', 'open', 'blocked'].includes(finding.status), `${finding.id} should use a documented verification status`);
  assert.match(reportMarkdown, new RegExp(finding.id));
  assert.match(reportMarkdown, new RegExp(finding.status));
  assert.ok(Array.isArray(finding.changed_files) && finding.changed_files.length > 0);
  assert.ok(Array.isArray(finding.tests));
}
assert.match(reportMarkdown, /fixed_verified/);
assert.match(reportMarkdown, /implemented_unverified/);
assert.match(reportMarkdown, /open/);
assert.match(reportMarkdown, /blocked/);

const taskIds = todo.tasks.map((task) => task.id).sort();
assert.equal(new Set(taskIds).size, taskIds.length, 'TODO IDs should be unique');
for (const task of todo.tasks) {
  assert.ok(['completed', 'open', 'blocked'].includes(task.status), `${task.id} should have a supported TODO status`);
  assert.match(todoMarkdown, new RegExp(task.id));
}
const openTaskIds = todo.tasks.filter((task) => ['open', 'blocked'].includes(task.status)).map((task) => task.id).sort();
const markdownOpenTaskIds = [...todoMarkdown.matchAll(/^- \[ \] (STAB-TODO-\d{3}) \((?:open|blocked)\):/gm)].map((match) => match[1]).sort();
assert.deepEqual(markdownOpenTaskIds, openTaskIds, 'TODO.md and TODO.json should contain the same open/blocked IDs');
const markdownCompletedTaskIds = [...todoMarkdown.matchAll(/^- \[x\] (STAB-TODO-\d{3}) \(completed\):/gm)].map((match) => match[1]).sort();
assert.deepEqual(markdownCompletedTaskIds, todo.tasks.filter((task) => task.status === 'completed').map((task) => task.id).sort(), 'completed tasks remain visible in both TODO formats');
assert.match(agents, /versionierte Dateien im[\s\S]*gesichert werden/);
assert.match(spec, /`end_round`/);
await assert.rejects(readFile(new URL('../docs/reviews/2026-11-20-review.md', import.meta.url)), { code: 'ENOENT' });
console.log('OK: Berichts-, TODO- und Spezifikationskonsistenz');

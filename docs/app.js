(() => {
  'use strict';

  const apiUrl = String(window.BINGO_CONFIG?.apiBaseUrl || '').replace(/\/+$/, '') + '/';
  const sessionKey = 'bingo-session';
  const home = document.querySelector('#home');
  const gameView = document.querySelector('#game-view');
  const notice = document.querySelector('#notice');
  const refs = {
    gameId: document.querySelector('#game-id'),
    connection: document.querySelector('#connection-status'),
    roundLabel: document.querySelector('#round-label'),
    roundHeading: document.querySelector('#round-heading'),
    roundNumber: document.querySelector('#round-number'),
    number: document.querySelector('#number-display'),
    actions: document.querySelector('#actions'),
    drawn: document.querySelector('#drawn-numbers'),
    drawnCount: document.querySelector('#drawn-count'),
    players: document.querySelector('#players-list'),
    playerCount: document.querySelector('#player-count'),
    cardSection: document.querySelector('#card-section'),
    card: document.querySelector('#bingo-card'),
    message: document.querySelector('#game-message')
  };
  let session = null;
  let pollTimer = null;
  let polling = false;
  let noticeTimer = null;
  let lastRenderedSnapshot = null;

  function readSession() {
    try {
      const saved = JSON.parse(localStorage.getItem(sessionKey) || 'null');
      return saved && /^[A-HJ-NP-Z2-9]{6}$/.test(saved.gameId) && typeof saved.token === 'string'
        ? saved
        : null;
    } catch {
      return null;
    }
  }

  function saveSession(next) {
    session = next;
    localStorage.setItem(sessionKey, JSON.stringify(next));
  }

  async function request(action, values = {}) {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 10000);
    try {
      const response = await fetch(apiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action, ...values }),
        signal: controller.signal
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(data.error || 'Die Anfrage ist fehlgeschlagen.');
      return data;
    } finally {
      clearTimeout(timeout);
    }
  }

  function showNotice(message) {
    notice.textContent = message;
    notice.classList.add('visible');
    clearTimeout(noticeTimer);
    noticeTimer = setTimeout(() => notice.classList.remove('visible'), 3500);
  }

  function errorMessage(error) {
    return error.name === 'AbortError'
      ? 'Zeitüberschreitung bei der Verbindung.'
      : error.message || 'Die Anfrage ist fehlgeschlagen.';
  }

  function showGame() {
    home.hidden = true;
    gameView.hidden = false;
    refs.gameId.textContent = session.gameId;
    refs.message.textContent = '';
  }

  function showHome() {
    clearInterval(pollTimer);
    pollTimer = null;
    polling = false;
    lastRenderedSnapshot = null;
    session = null;
    localStorage.removeItem(sessionKey);
    gameView.hidden = true;
    home.hidden = false;
  }

  async function act(action, extra = {}) {
    refs.message.textContent = '';
    try {
      const result = await request(action, {
        game_id: session.gameId,
        token: session.token,
        ...extra
      });
      if (result.number) showNotice(`Neue Zahl: ${result.number}`);
      if (result.valid) refs.message.textContent = `BINGO! ${result.winner} hat gewonnen.`;
      await refresh();
    } catch (error) {
      refs.message.textContent = errorMessage(error);
    }
  }

  function addAction(text, action, className = 'button button-primary') {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = className;
    button.textContent = text;
    button.dataset.focusKey = `action:${action}`;
    button.addEventListener('click', () => {
      if (action === 'end_round' && !window.confirm('Möchtest du die Runde wirklich ohne Gewinner beenden?')) return;
      act(action);
    });
    refs.actions.append(button);
  }

  function renderActions(state) {
    refs.actions.replaceChildren();
    let message = '';
    if (state.game.role === 'admin' && state.game.status === 'lobby') {
      addAction('Runde starten', 'start');
    } else if (state.game.role === 'admin' && state.game.status === 'playing') {
      addAction('Nächste Zahl ziehen', 'draw');
      addAction('Runde ohne Gewinner beenden', 'end_round', 'button button-light');
    } else if (state.game.role === 'admin' && state.game.status === 'finished') {
      const winner = document.createElement('p');
      winner.className = 'message';
      winner.textContent = state.game.winner
        ? `BINGO! ${state.game.winner} hat gewonnen.`
        : 'Die Runde wurde ohne Gewinner beendet.';
      refs.actions.append(winner);
      addAction('Neue Runde vorbereiten', 'new_round', 'button button-secondary');
    } else if (state.game.role === 'player' && state.game.status === 'playing') {
      addAction('BINGO! Reihe melden', 'bingo', 'button button-secondary');
    }
    if (state.game.status === 'finished' && !state.game.winner) {
      message = 'Die Runde wurde ohne Gewinner beendet.';
    } else if (state.game.role === 'player' && state.game.status === 'finished') {
      message = state.game.winner ? `BINGO! ${state.game.winner} hat gewonnen.` : 'Die Runde wurde ohne Gewinner beendet.';
    }
    if (state.game.status === 'lobby' && state.game.role === 'player') {
      message = 'Du bist dabei. Die Spielleitung startet die Runde.';
    }
    setText(refs.message, message);
  }

  function renderCard(state) {
    refs.cardSection.hidden = !state.card;
    const tableHead = refs.card.querySelector('thead');
    const tableBody = refs.card.querySelector('tbody');
    tableHead.replaceChildren();
    tableBody.replaceChildren();
    if (!state.card) return;
    const headers = ['B', 'I', 'N', 'G', 'O'];
    const headerRow = document.createElement('tr');
    const rowHeaderHeading = document.createElement('th');
    rowHeaderHeading.scope = 'col';
    rowHeaderHeading.className = 'visually-hidden';
    rowHeaderHeading.textContent = 'Zeile';
    headerRow.append(rowHeaderHeading);
    headers.forEach((letter) => {
      const heading = document.createElement('span');
      const cell = document.createElement('th');
      const headerId = `bingo-column-${letter}`;
      cell.id = headerId;
      cell.scope = 'col';
      heading.className = 'bingo-cell column-heading';
      heading.textContent = letter;
      cell.append(heading);
      headerRow.append(cell);
    });
    tableHead.append(headerRow);
    const marked = new Set(state.marks);
    const drawn = new Set(state.game.drawn);
    for (let row = 0; row < 5; row++) {
      const rowElement = document.createElement('tr');
      const rowHeader = document.createElement('th');
      const rowHeaderId = `bingo-row-${row + 1}`;
      rowHeader.id = rowHeaderId;
      rowHeader.scope = 'row';
      rowHeader.className = 'visually-hidden';
      rowHeader.textContent = `Zeile ${row + 1}`;
      rowElement.append(rowHeader);
      for (let column = 0; column < 5; column++) {
        const number = state.card[column][row];
        const tableCell = document.createElement('td');
        tableCell.headers = `${rowHeaderId} bingo-column-${headers[column]}`;
        const cell = document.createElement(number === null ? 'span' : 'button');
        cell.className = 'bingo-cell';
        if (number === null) {
          cell.textContent = 'Frei';
          cell.classList.add('free');
        } else {
          cell.type = 'button';
          cell.textContent = String(number);
          cell.dataset.focusKey = `cell:${number}`;
          cell.setAttribute('aria-label', `Zeile ${row + 1}, Spalte ${headers[column]}, Zahl ${number}`);
          cell.setAttribute('aria-pressed', String(marked.has(number)));
          cell.disabled = state.game.status !== 'playing' || !drawn.has(number);
          if (drawn.has(number)) cell.classList.add('drawn');
          if (marked.has(number)) cell.classList.add('marked');
          cell.addEventListener('click', () => act('mark', { number, marked: !marked.has(number) }));
        }
        tableCell.append(cell);
        rowElement.append(tableCell);
      }
      tableBody.append(rowElement);
    }
  }

  function setText(element, value) {
    if (element.textContent !== value) element.textContent = value;
  }

  function getFocusKey() {
    const active = document.activeElement;
    return gameView.contains(active) ? active.dataset.focusKey || null : null;
  }

  function restoreFocus(key) {
    if (!key) return;
    const target = Array.from(gameView.querySelectorAll('[data-focus-key]'))
      .find((element) => element.dataset.focusKey === key);
    if (target && !target.disabled) {
      target.focus({ preventScroll: true });
      return;
    }
    const fallback = Array.from(gameView.querySelectorAll('button:not(:disabled)'))
      .find((element) => !element.disabled);
    fallback?.focus({ preventScroll: true });
  }

  function render(state) {
    refs.gameId.textContent = state.game.id;
    refs.roundLabel.textContent = state.game.status === 'playing' ? 'Runde läuft'
      : state.game.status === 'finished' ? 'Runde beendet' : 'Lobby';
    refs.roundHeading.textContent = state.game.status === 'playing' ? 'Die nächste Zahl kommt!'
      : state.game.status === 'finished' && !state.game.winner ? 'Die Runde wurde beendet'
        : state.game.status === 'finished' ? 'Das Spiel ist entschieden' : 'Wartet auf den Start';
    refs.roundNumber.textContent = `Runde ${state.game.round}`;
    setText(refs.number, String(state.game.current_number ?? '–'));
    refs.drawnCount.textContent = `(${state.game.drawn.length})`;
    refs.drawn.replaceChildren();
    state.game.drawn.forEach((number) => {
      const item = document.createElement('li');
      item.textContent = String(number);
      refs.drawn.append(item);
    });
    refs.players.replaceChildren();
    state.players.forEach((player) => {
      const item = document.createElement('li');
      item.textContent = player.name;
      refs.players.append(item);
    });
    refs.playerCount.textContent = String(state.players.length);
    renderActions(state);
    renderCard(state);
  }

  async function refresh() {
    if (!session || polling) return;
    polling = true;
    try {
      const state = await request('state', { game_id: session.gameId, token: session.token });
      const snapshot = JSON.stringify(state);
      if (snapshot !== lastRenderedSnapshot) {
        const focusKey = getFocusKey();
        render(state);
        lastRenderedSnapshot = snapshot;
        restoreFocus(focusKey);
      }
      setText(refs.connection, 'Verbunden');
      refs.connection.classList.remove('error');
    } catch (error) {
      setText(refs.connection, `Verbindung unterbrochen – neuer Versuch in Kürze. ${errorMessage(error)}`);
      refs.connection.classList.add('error');
    } finally {
      polling = false;
    }
  }

  function beginPolling() {
    clearInterval(pollTimer);
    lastRenderedSnapshot = null;
    showGame();
    refresh();
    pollTimer = setInterval(refresh, 2000);
  }

  document.querySelector('#create-game').addEventListener('click', async () => {
    try {
      const result = await request('create');
      saveSession({ gameId: result.game_id, token: result.token, role: result.role });
      beginPolling();
      showNotice('Spiel erstellt. Teile die Spiel-ID mit den anderen.');
    } catch (error) {
      showNotice(errorMessage(error));
    }
  });

  document.querySelector('#join-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    try {
      const result = await request('join', {
        game_id: String(form.get('game_id')).trim().toUpperCase(),
        name: String(form.get('name')).trim()
      });
      saveSession({ gameId: result.game_id, token: result.token, role: result.role });
      beginPolling();
    } catch (error) {
      showNotice(errorMessage(error));
    }
  });

  document.querySelector('#copy-id').addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(session.gameId);
      showNotice('Spiel-ID kopiert.');
    } catch {
      showNotice(`Spiel-ID: ${session.gameId}`);
    }
  });

  document.querySelector('#leave-game').addEventListener('click', showHome);

  const restored = readSession();
  if (restored) {
    session = restored;
    beginPolling();
  }
})();

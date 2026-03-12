/* NIPGL Cup Bracket JS - v6.0.0 */
(function () {
  'use strict';

  var ajaxUrl    = (typeof nipglData !== 'undefined') ? nipglData.ajaxUrl    : '/wp-admin/admin-ajax.php';
  var badges     = (typeof nipglData !== 'undefined') ? nipglData.badges     : {};
  var clubBadges = (typeof nipglData !== 'undefined') ? nipglData.clubBadges : {};

  // ── Badge lookup (same logic as nipgl-widget.js) ─────────────────────────────
  function badgeImg(team, cls) {
    if (!team) return '';
    cls = cls || 'nipgl-cup-team-badge';
    if (badges[team]) return '<img class="' + cls + '" src="' + badges[team] + '" alt="">';
    var upper = team.toUpperCase();
    for (var key in badges) {
      if (key.toUpperCase() === upper) return '<img class="' + cls + '" src="' + badges[key] + '" alt="">';
    }
    var bestKey = '', bestImg = '';
    for (var club in clubBadges) {
      var cu = club.toUpperCase();
      if (upper === cu || upper.indexOf(cu) === 0) {
        var rest = team.slice(club.length);
        if (rest === '' || rest[0] === ' ') {
          if (club.length > bestKey.length) { bestKey = club; bestImg = clubBadges[club]; }
        }
      }
    }
    if (bestImg) return '<img class="' + cls + '" src="' + bestImg + '" alt="">';
    return '';
  }

  // ── Helpers ───────────────────────────────────────────────────────────────────
  function qs(sel, ctx) { return (ctx || document).querySelector(sel); }
  function qsa(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }

  function escHtml(s) {
    return String(s || '')
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;');
  }

  function post(action, data, cb) {
    var fd = new FormData();
    fd.append('action', action);
    for (var k in data) fd.append(k, data[k]);
    fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(cb)
      .catch(function (e) { cb({ success: false, data: e.message }); });
  }

  // ── Bracket rendering ─────────────────────────────────────────────────────────
  //
  // bracket data shape (stored in WP option, served via AJAX):
  // {
  //   title: "Senior Cup 2025",
  //   rounds: ["Round 1","Round 2","Quarter Final","Semi-Final","Final"],
  //   dates:  ["01/05/25", ...],   // optional, one per round
  //   matches: [                   // array of rounds; each round = array of match objects
  //     [ { home:"Ards", away:"Albert Foundry", home_score:null, away_score:null,
  //         draw_num_home:1, draw_num_away:2, bye:false }, ... ],
  //     ...
  //   ]
  // }

  function renderTeamRow(team, score, isWinner, isLoser, drawNum) {
    var cls = 'nipgl-cup-team';
    if (isWinner) cls += ' nipgl-cup-winner';
    if (isLoser)  cls += ' nipgl-cup-loser';
    var badge = team ? badgeImg(team) : '';
    var nameCls = 'nipgl-cup-team-name' + (team ? '' : ' tbd');
    var nameStr = team ? escHtml(team) : 'TBD';
    var scoreStr = (score !== null && score !== undefined && score !== '') ? escHtml(score) : '';
    var dnStr = (drawNum && scoreStr === '') ? '<span class="nipgl-cup-draw-num">' + escHtml(drawNum) + '</span>' : '';
    return '<div class="' + cls + '">'
      + badge
      + '<span class="' + nameCls + '">' + nameStr + '</span>'
      + (scoreStr !== '' ? '<span class="nipgl-cup-score">' + scoreStr + '</span>' : '')
      + dnStr
      + '</div>';
  }

  function renderMatch(match) {
    var home       = match.home  || '';
    var away       = match.away  || '';
    var hs         = match.home_score;
    var as         = match.away_score;
    var hasResult  = (hs !== null && hs !== undefined && hs !== '' &&
                      as !== null && as !== undefined && as !== '');
    var homeWin    = hasResult && parseFloat(hs) > parseFloat(as);
    var awayWin    = hasResult && parseFloat(as) > parseFloat(hs);

    var cls = 'nipgl-cup-match';
    if (match.bye)     cls += ' nipgl-cup-bye';
    if (!home && !away) cls += ' nipgl-cup-tbd';

    return '<div class="' + cls + '">'
      + renderTeamRow(home, hasResult ? hs : null, homeWin, awayWin && home, match.draw_num_home)
      + renderTeamRow(away, hasResult ? as : null, awayWin, homeWin && away, match.draw_num_away)
      + '</div>';
  }

  function renderBracket(wrap, data) {
    var rounds  = data.rounds  || [];
    var matches = data.matches || [];
    var dates   = data.dates   || [];

    // ── Mobile tabs
    var tabsEl = qs('.nipgl-cup-tabs', wrap);
    var tabsInner = tabsEl ? qs('.nipgl-cup-tabs-inner', tabsEl) : null;
    if (tabsInner) {
      tabsInner.innerHTML = '';
      rounds.forEach(function (name, i) {
        var tab = document.createElement('div');
        tab.className = 'nipgl-cup-tab' + (i === 0 ? ' active' : '');
        tab.textContent = name;
        tab.dataset.round = i;
        tabsInner.appendChild(tab);
      });
    }

    // ── Bracket
    var bracketEl = qs('.nipgl-cup-bracket', wrap);
    if (!bracketEl) return;
    bracketEl.innerHTML = '';

    rounds.forEach(function (roundName, ri) {
      var roundMatches = matches[ri] || [];
      var isFinal = ri === rounds.length - 1;

      // Connector column before each round (except first)
      if (ri > 0) {
        var connCol = document.createElement('div');
        connCol.className = 'nipgl-cup-connector-col';
        bracketEl.appendChild(connCol);
      }

      var roundEl = document.createElement('div');
      roundEl.className = 'nipgl-cup-round' + (isFinal ? ' nipgl-cup-round-final' : '') + (ri === 0 ? ' mobile-active' : '');
      roundEl.dataset.round = ri;

      var dateStr = dates[ri] ? '<span class="nipgl-cup-round-date">' + escHtml(dates[ri]) + '</span>' : '';
      roundEl.innerHTML = '<div class="nipgl-cup-round-header">' + escHtml(roundName) + dateStr + '</div>'
        + '<div class="nipgl-cup-round-slots"></div>';

      var slotsEl = qs('.nipgl-cup-round-slots', roundEl);
      roundMatches.forEach(function (match, mi) {
        var matchEl = document.createElement('div');
        matchEl.innerHTML = renderMatch(match);
        var card = matchEl.firstElementChild;
        card.dataset.round = ri;
        card.dataset.match = mi;
        // Admin score entry: click any non-bye, non-TBD match
        var isAdmin = typeof nipglCupData !== 'undefined' && nipglCupData.isAdmin == 1;
        if (isAdmin && !match.bye && (match.home || match.away)) {
          card.classList.add('nipgl-cup-editable');
          card.addEventListener('click', function () {
            openScoreEntry(wrap, card, match, ri, mi);
          });
        }
        slotsEl.appendChild(card);
      });
      bracketEl.appendChild(roundEl);
    });

    // Champion display after final
    var finalRound = matches[rounds.length - 1] || [];
    var finalMatch = finalRound[0] || null;
    var champion   = null;
    if (finalMatch) {
      var fhs = finalMatch.home_score, fas = finalMatch.away_score;
      if (fhs !== null && fhs !== undefined && fhs !== '' && fas !== null && fas !== undefined && fas !== '') {
        champion = parseFloat(fhs) > parseFloat(fas) ? finalMatch.home : finalMatch.away;
      }
    }

    if (champion !== null) {
      var connCol2 = document.createElement('div');
      connCol2.className = 'nipgl-cup-connector-col';
      bracketEl.appendChild(connCol2);

      var champEl = document.createElement('div');
      champEl.className = 'nipgl-cup-champion';
      champEl.innerHTML = '<div class="nipgl-cup-trophy">🏆</div>'
        + '<div class="nipgl-cup-champion-name">' + escHtml(champion) + '</div>'
        + '<div class="nipgl-cup-champion-label">Champion</div>';
      bracketEl.appendChild(champEl);
    }

    // ── Mobile tab switching
    if (tabsInner) {
      tabsInner.addEventListener('click', function (e) {
        var tab = e.target.closest('.nipgl-cup-tab');
        if (!tab) return;
        var ri2 = parseInt(tab.dataset.round);
        qsa('.nipgl-cup-tab', tabsInner).forEach(function (t) { t.classList.toggle('active', t === tab); });
        qsa('.nipgl-cup-round', bracketEl).forEach(function (r) {
          r.classList.toggle('mobile-active', parseInt(r.dataset.round) === ri2);
        });
      });
    }
  }

  // ── Live draw animation ───────────────────────────────────────────────────────
  function runDrawAnimation(pairs, onComplete) {
    // pairs = [ {home, away, bye}, ... ] with optional {type:'header', label:'...'} entries

    // Count actual matches (non-headers) for progress display
    var matchCount = pairs.filter(function (p) { return p.type !== 'header'; }).length;

    var overlay = document.createElement('div');
    overlay.className = 'nipgl-cup-draw-overlay';
    overlay.innerHTML = [
      '<div class="nipgl-cup-draw-title">🏆 Cup Draw</div>',
      '<div class="nipgl-cup-draw-subtitle">The draw is being made…</div>',
      '<div class="nipgl-cup-draw-reveal">',
        '<div class="nipgl-cup-draw-slot-label" id="nipgl-draw-slot-label">Match 1</div>',
        '<div class="nipgl-cup-draw-team" id="nipgl-draw-home"></div>',
        '<div class="nipgl-cup-draw-vs">vs</div>',
        '<div class="nipgl-cup-draw-team" id="nipgl-draw-away"></div>',
      '</div>',
      '<div class="nipgl-cup-draw-progress" id="nipgl-draw-progress">0 / ' + matchCount + ' drawn</div>',
      '<div class="nipgl-cup-draw-pairs" id="nipgl-draw-pairs"></div>',
      '<button class="nipgl-cup-draw-btn" id="nipgl-draw-next">Draw Next</button>',
    ].join('');
    document.body.appendChild(overlay);

    var labelEl    = qs('#nipgl-draw-slot-label', overlay);
    var homeEl     = qs('#nipgl-draw-home',      overlay);
    var awayEl     = qs('#nipgl-draw-away',      overlay);
    var progressEl = qs('#nipgl-draw-progress',  overlay);
    var pairsEl    = qs('#nipgl-draw-pairs',      overlay);
    var nextBtn    = qs('#nipgl-draw-next',       overlay);

    var idx       = 0;  // index into pairs[]
    var matchIdx  = 0;  // count of actual matches drawn so far (skips headers)

    function showPair(i) {
      var pair = pairs[i];

      // Header entry — insert a section divider and immediately advance
      if (pair.type === 'header') {
        var divider = document.createElement('div');
        divider.className = 'nipgl-cup-draw-round-header';
        divider.textContent = pair.label;
        pairsEl.appendChild(divider);
        idx++;
        // Reset the reveal card for the new round
        homeEl.classList.remove('show');
        awayEl.classList.remove('show');
        homeEl.textContent = '';
        awayEl.textContent = '';
        labelEl.textContent = pair.label;
        // If there are more pairs, update button; otherwise close
        if (idx < pairs.length) {
          nextBtn.textContent = 'Draw Next';
          // Don't auto-advance — let user press "Draw Next" for the new round
        } else {
          nextBtn.textContent = 'Close Draw';
        }
        return;
      }

      matchIdx++;
      labelEl.textContent = 'Match ' + matchIdx;

      homeEl.textContent = pair.home;
      awayEl.textContent = pair.bye ? 'BYE' : (pair.away || 'TBD');
      homeEl.classList.remove('show');
      awayEl.classList.remove('show');

      setTimeout(function () {
        homeEl.classList.add('show');
        setTimeout(function () {
          awayEl.classList.add('show');
        }, 600);
      }, 80);

      progressEl.textContent = matchIdx + ' / ' + matchCount + ' drawn';

      // After both teams shown, add chip to the pairs area
      setTimeout(function () {
        var chip = document.createElement('div');
        chip.className = 'nipgl-cup-draw-pair-chip' + (pair.bye ? ' nipgl-cup-draw-bye-chip' : '');
        chip.innerHTML = escHtml(pair.home)
          + '<span class="vs-sep">' + (pair.bye ? 'BYE' : 'v') + '</span>'
          + (pair.bye ? '' : escHtml(pair.away || 'TBD'));
        pairsEl.appendChild(chip);
        requestAnimationFrame(function () {
          requestAnimationFrame(function () { chip.classList.add('show'); });
        });
      }, 900);

      if (i === pairs.length - 1) {
        nextBtn.textContent = 'Close Draw';
      }
    }

    nextBtn.addEventListener('click', function () {
      if (idx < pairs.length) {
        showPair(idx);
        idx++;
      } else {
        document.body.removeChild(overlay);
        if (onComplete) onComplete();
      }
    });

    // Show first pair immediately
    showPair(idx);
    idx++;
  }

  // ── Admin draw trigger ────────────────────────────────────────────────────────
  function initAdminDraw(wrap) {
    var cupId   = wrap.dataset.cupId;
    var drawBtn = qs('.nipgl-cup-admin-draw-btn', wrap);
    if (!drawBtn) return;

    drawBtn.addEventListener('click', function () {
      if (!confirm('Perform the draw now? This will randomise the bracket and publish it live. This cannot be undone.')) return;
      drawBtn.disabled = true;
      drawBtn.textContent = '⏳ Drawing…';

      post('nipgl_cup_perform_draw', { cup_id: cupId, nonce: drawBtn.dataset.nonce }, function (res) {
        drawBtn.disabled = false;
        drawBtn.textContent = '🎲 Perform Draw';
        if (!res.success) { alert('Draw failed: ' + (res.data || 'Unknown error')); return; }
        var bracket = res.data.bracket;
        var pairs   = res.data.pairs; // [{home, away, bye}, ...]
        runDrawAnimation(pairs, function () {
          renderBracket(wrap, bracket);
          updateStatus(wrap, 'Draw complete — bracket published.');
          // Hide the draw button — draw is done
          drawBtn.parentNode.removeChild(drawBtn);
        });
      });
    });
  }

  // ── Admin score entry popover ─────────────────────────────────────────────────
  function openScoreEntry(wrap, card, match, roundIdx, matchIdx) {
    // Remove any existing popover
    var existing = qs('.nipgl-cup-score-popover');
    if (existing) existing.parentNode.removeChild(existing);

    var cupId = wrap.dataset.cupId;
    var nonce = (typeof nipglCupData !== 'undefined') ? nipglCupData.scoreNonce : '';
    var homeName = match.home || 'TBD';
    var awayName = match.away || 'TBD';
    var hs = (match.home_score !== null && match.home_score !== undefined) ? match.home_score : '';
    var as = (match.away_score !== null && match.away_score !== undefined) ? match.away_score : '';

    var pop = document.createElement('div');
    pop.className = 'nipgl-cup-score-popover';
    pop.innerHTML =
      '<div class="nipgl-cup-score-pop-title">Enter Score</div>' +
      '<div class="nipgl-cup-score-pop-row">' +
        '<span class="nipgl-cup-score-pop-name">' + escHtml(homeName) + '</span>' +
        '<input class="nipgl-cup-score-pop-input" id="nipgl-score-home" type="number" min="0" max="99" value="' + escHtml(String(hs)) + '" placeholder="–">' +
      '</div>' +
      '<div class="nipgl-cup-score-pop-row">' +
        '<span class="nipgl-cup-score-pop-name">' + escHtml(awayName) + '</span>' +
        '<input class="nipgl-cup-score-pop-input" id="nipgl-score-away" type="number" min="0" max="99" value="' + escHtml(String(as)) + '" placeholder="–">' +
      '</div>' +
      '<div class="nipgl-cup-score-pop-actions">' +
        '<button class="nipgl-cup-score-pop-save">Save</button>' +
        '<button class="nipgl-cup-score-pop-cancel">Cancel</button>' +
      '</div>' +
      '<div class="nipgl-cup-score-pop-msg"></div>';

    document.body.appendChild(pop);

    // Position near the card
    var rect = card.getBoundingClientRect();
    var top  = rect.top + window.scrollY + rect.height / 2 - 10;
    var left = rect.right + window.scrollX + 8;
    // Keep on screen
    if (left + 220 > window.innerWidth) left = rect.left + window.scrollX - 228;
    pop.style.top  = Math.max(8, top) + 'px';
    pop.style.left = Math.max(8, left) + 'px';

    var homeInput = qs('#nipgl-score-home', pop);
    var awayInput = qs('#nipgl-score-away', pop);
    var msgEl     = qs('.nipgl-cup-score-pop-msg', pop);
    homeInput.focus();

    qs('.nipgl-cup-score-pop-cancel', pop).addEventListener('click', function () {
      pop.parentNode.removeChild(pop);
    });

    qs('.nipgl-cup-score-pop-save', pop).addEventListener('click', function () {
      var saveBtn = this;
      saveBtn.disabled = true;
      saveBtn.textContent = 'Saving…';
      post('nipgl_cup_save_score', {
        cup_id: cupId, nonce: nonce,
        round_idx: roundIdx, match_idx: matchIdx,
        home_score: homeInput.value, away_score: awayInput.value,
      }, function (res) {
        saveBtn.disabled = false;
        saveBtn.textContent = 'Save';
        if (!res.success) {
          msgEl.textContent = 'Error: ' + (res.data || 'Unknown');
          return;
        }
        pop.parentNode.removeChild(pop);
        renderBracket(wrap, res.data.bracket);
      });
    });

    // Close on outside click
    setTimeout(function () {
      document.addEventListener('click', function handler(e) {
        if (!pop.contains(e.target) && e.target !== card) {
          if (pop.parentNode) pop.parentNode.removeChild(pop);
          document.removeEventListener('click', handler);
        }
      });
    }, 50);
  }

  // ── Status bar ────────────────────────────────────────────────────────────────
  function updateStatus(wrap, msg) {
    var statusEl = qs('.nipgl-cup-status', wrap);
    if (!statusEl) return;
    var dot = qs('.nipgl-cup-status-dot', statusEl);
    var txt = qs('.nipgl-cup-status-text', statusEl);
    if (dot) dot.classList.remove('live');
    if (txt) txt.textContent = msg;
  }

  // ── Polling for live draw (visitors) ─────────────────────────────────────────
  function startDrawPoll(wrap, lastVersion) {
    var cupId = wrap.dataset.cupId;
    if (!cupId) return;
    var pollInterval = setInterval(function () {
      post('nipgl_cup_poll', { cup_id: cupId, version: lastVersion }, function (res) {
        if (!res.success) return;
        if (res.data.version !== lastVersion) {
          clearInterval(pollInterval);
          lastVersion = res.data.version;
          var bracket = res.data.bracket;
          var pairs   = res.data.pairs || [];
          if (pairs.length) {
            runDrawAnimation(pairs, function () {
              renderBracket(wrap, bracket);
              updateStatus(wrap, 'Draw complete.');
            });
          } else {
            renderBracket(wrap, bracket);
            updateStatus(wrap, 'Bracket updated.');
          }
        }
      });
    }, 4000);
  }

  // ── Init ──────────────────────────────────────────────────────────────────────
  function initCupWidget(wrap) {
    var cupId   = wrap.dataset.cupId;
    if (!cupId) return;

    var bracketData = null;
    try {
      var raw = wrap.dataset.bracket;
      if (raw) bracketData = JSON.parse(raw);
    } catch (e) {}

    var drawVersion = wrap.dataset.drawVersion || '0';

    if (bracketData && bracketData.rounds && bracketData.rounds.length) {
      renderBracket(wrap, bracketData);
    } else {
      var emptyEl = qs('.nipgl-cup-empty', wrap);
      if (emptyEl) emptyEl.style.display = '';
    }

    // If draw hasn't happened yet, start polling
    if (drawVersion === '0') {
      startDrawPoll(wrap, drawVersion);
    }

    initAdminDraw(wrap);
  }

  // Boot all cup widgets on page
  document.addEventListener('DOMContentLoaded', function () {
    qsa('.nipgl-cup-wrap').forEach(initCupWidget);
  });

})();

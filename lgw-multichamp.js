/* lgw-multichamp.js — Multi-Discipline Championship */
(function(){
'use strict';

// ── Frontend: tab switching + tab persistence ────────────────────────────────

function lgwMcInitTabs() {
    document.querySelectorAll('.lgw-mc-widget').forEach(function(widget){
        var tabs    = widget.querySelectorAll('.lgw-mc-tab');
        var panels  = widget.querySelectorAll('.lgw-mc-panel');
        var champId = widget.dataset.champ;
        var storageKey = 'lgw_mc_tab_' + champId;
        var isAdmin = (typeof lgwMcData !== 'undefined' && lgwMcData.isAdmin === '1');

        function switchTab(target, save) {
            tabs.forEach(function(t){ t.classList.remove('active'); });
            panels.forEach(function(p){ p.classList.remove('active'); });
            var btn = widget.querySelector('[data-tab="' + target + '"]');
            if (btn) btn.classList.add('active');
            var panel = widget.querySelector('[data-panel="' + target + '"]');
            if (panel) panel.classList.add('active');
            if (save) { try { sessionStorage.setItem(storageKey, target); } catch(e){} }
        }

        // Restore tab from sessionStorage
        var savedTab = null;
        try { savedTab = sessionStorage.getItem(storageKey); } catch(e){}
        if (savedTab && widget.querySelector('[data-tab="' + savedTab + '"]')) {
            switchTab(savedTab, false);
        }

        tabs.forEach(function(btn){
            btn.addEventListener('click', function(){ switchTab(btn.dataset.tab, true); });
            btn.addEventListener('keydown', function(e){
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); switchTab(btn.dataset.tab, true); }
            });
        });

        // ── Admin / visitor view toggle ───────────────────────────────────────
        if (isAdmin) {
            var header = widget.querySelector('.lgw-mc-header');
            if (header) {
                var viewAsAdmin = true;
                var toggleBtn = document.createElement('button');
                toggleBtn.type = 'button';
                toggleBtn.className = 'lgw-mc-view-toggle';
                toggleBtn.title = 'Switch to visitor view';
                toggleBtn.textContent = '👁 Visitor view';
                header.appendChild(toggleBtn);

                toggleBtn.addEventListener('click', function(){
                    viewAsAdmin = !viewAsAdmin;
                    widget.classList.toggle('lgw-mc-visitor-preview', !viewAsAdmin);
                    toggleBtn.textContent = viewAsAdmin ? '👁 Visitor view' : '⚙️ Admin view';
                    toggleBtn.title = viewAsAdmin ? 'Switch to visitor view' : 'Switch back to admin view';
                });
            }
        }
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', lgwMcInitTabs);
} else {
    lgwMcInitTabs();
}

// ── Live fixture total update ─────────────────────────────────────────────────

function lgwMcUpdateFixtureTotal(widget, fixId) {
    var card = widget.querySelector('.lgw-mc-fixture-card[data-fix="' + fixId + '"]');
    if (!card) return;

    var bonusWin  = parseInt(widget.dataset.bonusWin)  || 0;
    var bonusDraw = parseInt(widget.dataset.bonusDraw) || 0;
    var bonusLoss = parseInt(widget.dataset.bonusLoss) || 0;

    var totPh = 0, totPa = 0, totSh = 0, totSa = 0, hasScore = false;
    card.querySelectorAll('.lgw-mc-game-row').forEach(function(row){
        var ph = parseInt(row.dataset.ph) || 0;
        var pa = parseInt(row.dataset.pa) || 0;
        var sh = parseInt(row.dataset.sh) || 0;
        var sa = parseInt(row.dataset.sa) || 0;
        if (sh || sa || ph || pa) { hasScore = true; totPh += ph; totPa += pa; totSh += sh; totSa += sa; }
    });

    if (!hasScore) return;

    // Apply bonus
    var dispPh = totPh, dispPa = totPa;
    if (totPh > totPa)      { dispPh += bonusWin;  dispPa += bonusLoss; }
    else if (totPh < totPa) { dispPh += bonusLoss; dispPa += bonusWin; }
    else                    { dispPh += bonusDraw;  dispPa += bonusDraw; }

    var ptsEl   = card.querySelector('.lgw-mc-score-pts');
    var shotsEl = card.querySelector('.lgw-mc-score-shots');
    var vsEl    = card.querySelector('.lgw-mc-vs-placeholder');

    if (ptsEl) {
        ptsEl.textContent = dispPh + ' – ' + dispPa;
        ptsEl.dataset.ph  = dispPh;
        ptsEl.dataset.pa  = dispPa;
        ptsEl.style.display = '';
    }
    if (shotsEl) {
        shotsEl.textContent = totSh + ' – ' + totSa + ' shots';
        shotsEl.dataset.sh  = totSh;
        shotsEl.dataset.sa  = totSa;
        shotsEl.style.display = '';
    }
    if (vsEl) vsEl.style.display = 'none';

    // Update winner highlight
    var homeClub = card.querySelector('.lgw-mc-club:first-child');
    var awayClub = card.querySelector('.lgw-mc-club:last-child');
    if (homeClub) homeClub.classList.toggle('winner', dispPh > dispPa);
    if (awayClub) awayClub.classList.toggle('winner', dispPa > dispPh);
}

// ── Admin: discipline builder ─────────────────────────────────────────────────

var discBody = document.getElementById('lgw-mc-disc-body');
var addDisc  = document.getElementById('lgw-mc-add-disc');

if (addDisc && discBody) {
    // Wire scoring mode → label for any rows already on the page
    discBody.querySelectorAll('.lgw-mc-disc-row').forEach(function(row){
        var modeSelect = row.querySelector('[name="disc_scoring_mode[]"]');
        var endsLabel  = row.querySelector('.lgw-mc-ends-label');
        if (modeSelect && endsLabel) {
            modeSelect.addEventListener('change', function(){
                endsLabel.textContent = modeSelect.value === 'target' ? 'shots' : 'ends';
            });
        }
    });
    addDisc.addEventListener('click', function(){
        var row = document.createElement('tr');
        row.className = 'lgw-mc-disc-row';
        row.innerHTML =
            '<td><input type="text" name="disc_label[]" class="regular-text" placeholder="e.g. Singles"></td>' +
            '<td><input type="text" name="disc_id[]" class="small-text" placeholder="e.g. singles"></td>' +
            '<td><input type="number" name="disc_count[]" value="1" min="1" max="20" style="width:60px"></td>' +
            '<td><select name="disc_scoring_mode[]" class="lgw-mc-scoring-mode">' +
                '<option value="ends">Ends</option>' +
                '<option value="target">Target score</option>' +
            '</select></td>' +
            '<td><input type="number" name="disc_ends[]" value="21" min="1" max="999" style="width:65px"> ' +
                '<span class="lgw-mc-ends-label description">ends</span></td>' +
            '<td><input type="text" name="disc_time_limit[]" placeholder="e.g. 75 mins" style="width:90px"></td>' +
            '<td><input type="number" name="disc_pts_win[]"  value="2" min="0" max="99" style="width:55px"></td>' +
            '<td><input type="number" name="disc_pts_draw[]" value="1" min="0" max="99" style="width:55px"></td>' +
            '<td><input type="number" name="disc_pts_loss[]" value="0" min="0" max="99" style="width:55px"></td>' +
            '<td><button type="button" class="button lgw-mc-remove-disc">Remove</button></td>';

        // Auto-populate slug from label
        var labelInput = row.querySelector('[name="disc_label[]"]');
        var idInput    = row.querySelector('[name="disc_id[]"]');
        labelInput.addEventListener('input', function(){
            if (!idInput._edited) {
                idInput.value = labelInput.value.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '');
            }
        });
        idInput.addEventListener('input', function(){ idInput._edited = true; });

        // Update ends/shots label when scoring mode changes
        var modeSelect  = row.querySelector('[name="disc_scoring_mode[]"]');
        var endsLabel   = row.querySelector('.lgw-mc-ends-label');
        modeSelect.addEventListener('change', function(){
            endsLabel.textContent = modeSelect.value === 'target' ? 'shots' : 'ends';
        });

        discBody.appendChild(row);
    });

    discBody.addEventListener('click', function(e){
        if (e.target.classList.contains('lgw-mc-remove-disc')) {
            var rows = discBody.querySelectorAll('.lgw-mc-disc-row');
            if (rows.length <= 1) { alert('At least one discipline is required.'); return; }
            e.target.closest('tr').remove();
        }
    });
}

// ── Admin: fixture builder ────────────────────────────────────────────────────

var fixBody = document.getElementById('lgw-mc-fix-body');
var addFix  = document.getElementById('lgw-mc-add-fix');

if (addFix && fixBody) {
    addFix.addEventListener('click', function(){
        var entries = [];
        try { entries = JSON.parse(addFix.dataset.entries || '[]'); } catch(e){}
        var opts = entries.map(function(e){ return '<option value="' + escAttr(e) + '">' + escHtml(e) + '</option>'; }).join('');
        var rowNum = fixBody.querySelectorAll('.lgw-mc-fix-row').length + 1;
        var row = document.createElement('tr');
        row.className = 'lgw-mc-fix-row';
        row.draggable = true;
        row.innerHTML =
            '<td class="lgw-mc-drag-handle" title="Drag to reorder">⠿</td>' +
            '<td class="lgw-mc-fix-num">' + rowNum + '</td>' +
            '<td><select name="fix_home[]" class="lgw-mc-club-select">' + opts + '</select></td>' +
            '<td><select name="fix_away[]" class="lgw-mc-club-select">' + opts + '</select></td>' +
            '<td><button type="button" class="button lgw-mc-remove-fix">Remove</button></td>';
        fixBody.appendChild(row);
        lgwMcBindRowDrag(row);
        renumberFixRows();
    });

    fixBody.addEventListener('click', function(e){
        if (e.target.classList.contains('lgw-mc-remove-fix')) {
            e.target.closest('tr').remove();
            renumberFixRows();
        }
    });
}

function renumberFixRows(){
    if (!fixBody) return;
    fixBody.querySelectorAll('.lgw-mc-fix-row').forEach(function(r, i){
        r.cells[0].textContent = i + 1;
    });
}

// ── Admin: auto-draw ──────────────────────────────────────────────────────────

var autoDrawBtn = document.getElementById('lgw-mc-auto-draw');
if (autoDrawBtn) {
    autoDrawBtn.addEventListener('click', function(){
        var champId = autoDrawBtn.dataset.champ;
        var nonce   = autoDrawBtn.dataset.nonce;
        autoDrawBtn.disabled = true;
        autoDrawBtn.textContent = 'Drawing…';

        var fd = new FormData();
        fd.append('action',   'lgw_mc_auto_draw');
        fd.append('nonce',    nonce);
        fd.append('champ_id', champId);

        fetch((typeof lgwMcData !== 'undefined' ? lgwMcData.ajaxurl : window.ajaxurl) || '/wp-admin/admin-ajax.php', { method:'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(data){
                if (!data.success) { alert('Draw failed: ' + (data.data || 'unknown error')); return; }
                var fixtures = data.data.fixtures;
                if (!fixBody) return;

                // Get entries list from existing selects or addFix button
                var entries = [];
                var addFixBtn = document.getElementById('lgw-mc-add-fix');
                if (addFixBtn) {
                    try { entries = JSON.parse(addFixBtn.dataset.entries || '[]'); } catch(e){}
                }
                if (!entries.length) {
                    // Fall back to options from existing selects
                    var sel = fixBody.querySelector('select');
                    if (sel) entries = Array.from(sel.options).map(function(o){ return o.value; });
                }

                var opts = entries.map(function(e){ return '<option value="' + escAttr(e) + '">' + escHtml(e) + '</option>'; }).join('');
                fixBody.innerHTML = '';
                fixtures.forEach(function(fix, i){
                    var homeOpts = entries.map(function(e){
                        return '<option value="' + escAttr(e) + '"' + (e === fix.home ? ' selected' : '') + '>' + escHtml(e) + '</option>';
                    }).join('');
                    var awayOpts = entries.map(function(e){
                        return '<option value="' + escAttr(e) + '"' + (e === fix.away ? ' selected' : '') + '>' + escHtml(e) + '</option>';
                    }).join('');
                    var row = document.createElement('tr');
                    row.className = 'lgw-mc-fix-row';
                    row.draggable = true;
                    row.innerHTML =
                        '<td class="lgw-mc-drag-handle" title="Drag to reorder">⠿</td>' +
                        '<td class="lgw-mc-fix-num">' + (i+1) + '</td>' +
                        '<td><select name="fix_home[]" class="lgw-mc-club-select">' + homeOpts + '</select></td>' +
                        '<td><select name="fix_away[]" class="lgw-mc-club-select">' + awayOpts + '</select></td>' +
                        '<td><button type="button" class="button lgw-mc-remove-fix">Remove</button></td>';
                    fixBody.appendChild(row);
                    lgwMcBindRowDrag(row);
                });
            })
            .catch(function(err){ alert('Draw request failed: ' + err); })
            .finally(function(){ autoDrawBtn.disabled = false; autoDrawBtn.textContent = '⚡ Auto-draw (round-robin)'; });
    });
}

// ── Admin: save individual game scores ───────────────────────────────────────

var scoresWrap = document.getElementById('lgw-mc-scores');
if (scoresWrap) {
    var champId = scoresWrap.dataset.champ;
    var nonce   = scoresWrap.dataset.nonce;

    scoresWrap.addEventListener('click', function(e){
        if (!e.target.classList.contains('mc-save-game')) return;
        var btn = e.target;
        var card = btn.closest('.lgw-mc-game-card');
        if (!card) return;

        var fixId   = card.dataset.fix;
        var discId  = card.dataset.disc;
        var gameIdx = card.dataset.idx;

        var shotsHome   = card.querySelector('.mc-shots-home').value;
        var shotsAway   = card.querySelector('.mc-shots-away').value;
        var playersHome = card.querySelector('.mc-players-home').value;
        var playersAway = card.querySelector('.mc-players-away').value;

        if (shotsHome === '' || shotsAway === '') { alert('Enter shots for both sides.'); return; }

        btn.disabled = true;
        btn.textContent = 'Saving…';

        var fd = new FormData();
        fd.append('action',       'lgw_mc_save_scores');
        fd.append('nonce',        nonce);
        fd.append('champ_id',     champId);
        fd.append('fix_id',       fixId);
        fd.append('disc_id',      discId);
        fd.append('game_idx',     gameIdx);
        fd.append('shots_home',   shotsHome);
        fd.append('shots_away',   shotsAway);
        var statusSel = card.querySelector('.mc-game-status');
        var gameStatus = statusSel ? statusSel.value : 'not_started';
        fd.append('players_home', playersHome);
        fd.append('players_away', playersAway);
        fd.append('game_status',  gameStatus);

        fetch((typeof lgwMcData !== 'undefined' ? lgwMcData.ajaxurl : window.ajaxurl) || '/wp-admin/admin-ajax.php', { method:'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(data){
                if (!data.success) { alert('Save failed: ' + (data.data || 'unknown error')); return; }
                var pts = data.data;
                var ptsEl = card.querySelector('.mc-pts-display');
                if (ptsEl) { ptsEl.innerHTML = pts.pts_home + '<br>v<br>' + pts.pts_away; }
                // Update card header result
                var hdrResult = card.querySelector('.lgw-mc-game-result');
                if (hdrResult) {
                    hdrResult.textContent = pts.pts_home + ' pts / ' + pts.pts_away + ' pts';
                } else {
                    var hdr = card.querySelector('.lgw-mc-game-card-hdr');
                    if (hdr) {
                        var span = document.createElement('span');
                        span.className = 'lgw-mc-game-result';
                        span.textContent = pts.pts_home + ' pts / ' + pts.pts_away + ' pts';
                        hdr.appendChild(span);
                    }
                }
                btn.textContent = '✓ Saved';
                setTimeout(function(){ btn.textContent = 'Save'; btn.disabled = false; }, 1800);
                lgwMcUpdateFixTotals(card.closest('.lgw-mc-fix-card'));
            })
            .catch(function(err){ alert('Request failed: ' + err); btn.disabled = false; btn.textContent = 'Save'; });
    });
}

function lgwMcUpdateFixTotals(card){
    if (!card) return;
    var totalPh = 0, totalPa = 0, totalSh = 0, totalSa = 0;
    card.querySelectorAll('.lgw-mc-game-card').forEach(function(gc){
        var ptsEl = gc.querySelector('.mc-pts-display');
        var ptsText = ptsEl ? ptsEl.textContent.replace(/\s/g,'') : '';
        var pts = ptsText.split('v');
        totalPh += parseInt(pts[0]) || 0;
        totalPa += parseInt(pts[1]) || 0;
        totalSh += parseInt((gc.querySelector('.mc-shots-home') || {}).value) || 0;
        totalSa += parseInt((gc.querySelector('.mc-shots-away') || {}).value) || 0;
    });
    var hdr = card.querySelector('.hndle span');
    if (!hdr) return;
    var baseText = hdr.textContent.split('—')[0].trim();
    hdr.innerHTML = baseText + ' &nbsp;—&nbsp; <strong>' + totalPh + ' pts / ' + totalSh + ' shots</strong> v <strong>' + totalPa + ' pts / ' + totalSa + ' shots</strong>';
}

// ── Admin: create full scorecard for a game ───────────────────────────────────

if (scoresWrap) {
    scoresWrap.addEventListener('click', function(e){
        if (!e.target.classList.contains('mc-create-sc')) return;
        var btn     = e.target;
        var champId = btn.dataset.champ;
        var fixId   = btn.dataset.fix;
        var discId  = btn.dataset.disc;
        var gameIdx = btn.dataset.idx;

        btn.disabled = true;
        btn.textContent = 'Creating…';

        var fd = new FormData();
        fd.append('action',   'lgw_mc_create_scorecard');
        fd.append('nonce',    nonce);
        fd.append('champ_id', champId);
        fd.append('fix_id',   fixId);
        fd.append('disc_id',  discId);
        fd.append('game_idx', gameIdx);

        fetch((typeof lgwMcData !== 'undefined' ? lgwMcData.ajaxurl : window.ajaxurl) || '/wp-admin/admin-ajax.php', { method:'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(data){
                if (!data.success) { alert('Could not create scorecard: ' + (data.data || 'unknown error')); btn.disabled = false; btn.textContent = '+ Full scorecard'; return; }
                // Replace button with edit link
                var cell = btn.closest('.mc-sc-cell');
                if (cell) {
                    cell.innerHTML = '<a href="' + data.data.edit_url + '" class="button button-small" target="_blank">✏️ Edit scorecard</a>';
                }
            })
            .catch(function(err){ alert('Request failed: ' + err); btn.disabled = false; btn.textContent = '+ Full scorecard'; });
    });
}

// ── Admin: clear individual game scores ───────────────────────────────────────

if (scoresWrap) {
    scoresWrap.addEventListener('click', function(e){
        if (!e.target.classList.contains('mc-clear-game')) return;
        var btn  = e.target;
        var card = btn.closest('.lgw-mc-game-card');
        if (!card) return;
        if (!confirm('Clear the scores for this game? The scorecard link will be preserved.')) return;

        btn.disabled = true;

        var fd = new FormData();
        fd.append('action',   'lgw_mc_clear_game');
        fd.append('nonce',    scoresWrap.dataset.nonce);
        fd.append('champ_id', champId);
        fd.append('fix_id',   card.dataset.fix);
        fd.append('disc_id',  card.dataset.disc);
        fd.append('game_idx', card.dataset.idx);

        fetch((typeof lgwMcData !== 'undefined' ? lgwMcData.ajaxurl : window.ajaxurl) || '/wp-admin/admin-ajax.php', { method:'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(data){
                if (!data.success) { alert('Clear failed'); btn.disabled = false; return; }
                card.querySelector('.mc-shots-home').value = '';
                card.querySelector('.mc-shots-away').value = '';
                card.querySelector('.mc-players-home').value = '';
                card.querySelector('.mc-players-away').value = '';
                var ptsEl = card.querySelector('.mc-pts-display');
                if (ptsEl) ptsEl.innerHTML = 'v';
                var result = card.querySelector('.lgw-mc-game-result');
                if (result) result.remove();
                btn.disabled = false;
                lgwMcUpdateFixTotals(card.closest('.lgw-mc-fix-card'));
            })
            .catch(function(){ btn.disabled = false; });
    });
}

// ── Admin: clear all games for a fixture ──────────────────────────────────────

if (scoresWrap) {
    scoresWrap.addEventListener('click', function(e){
        if (!e.target.classList.contains('lgw-mc-clear-fix')) return;
        var btn  = e.target;
        var card = btn.closest('.lgw-mc-fix-card');
        if (!card) return;
        if (!confirm('Clear ALL scores for this fixture? Scorecard links will be preserved.')) return;

        btn.disabled = true;

        var fd = new FormData();
        fd.append('action',   'lgw_mc_clear_fixture');
        fd.append('nonce',    scoresWrap.dataset.nonce);
        fd.append('champ_id', champId);
        fd.append('fix_id',   btn.dataset.fix);

        fetch((typeof lgwMcData !== 'undefined' ? lgwMcData.ajaxurl : window.ajaxurl) || '/wp-admin/admin-ajax.php', { method:'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(data){
                if (!data.success) { alert('Clear failed'); btn.disabled = false; return; }
                card.querySelectorAll('.lgw-mc-game-card').forEach(function(gc){
                    gc.querySelector('.mc-shots-home').value = '';
                    gc.querySelector('.mc-shots-away').value = '';
                    gc.querySelector('.mc-players-home').value = '';
                    gc.querySelector('.mc-players-away').value = '';
                    var ptsEl = gc.querySelector('.mc-pts-display');
                    if (ptsEl) ptsEl.innerHTML = 'v';
                    var result = gc.querySelector('.lgw-mc-game-result');
                    if (result) result.remove();
                });
                lgwMcUpdateFixTotals(card);
                btn.disabled = false;
            })
            .catch(function(){ btn.disabled = false; });
    });
}

function escHtml(str){
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function escAttr(str){
    return String(str).replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

// ── Admin: club table management ──────────────────────────────────────────────

var clubsBody = document.getElementById('lgw-mc-clubs-body');
var addClub   = document.getElementById('lgw-mc-add-club');

if (addClub && clubsBody) {
    addClub.addEventListener('click', function(){
        var row = document.createElement('tr');
        row.className = 'lgw-mc-club-row';
        row.innerHTML =
            '<td><input type="text" name="club_name[]" class="regular-text" placeholder="Club name"></td>' +
            '<td><input type="color" name="club_colour[]" value="#072a82"></td>' +
            '<td><div style="display:flex;align-items:center;gap:8px">' +
                '<input type="hidden" name="club_badge[]" class="lgw-mc-badge-url" value="">' +
                '<button type="button" class="button button-small lgw-mc-choose-badge">Choose badge</button>' +
            '</div></td>' +
            '<td><button type="button" class="button button-small lgw-mc-remove-club">Remove</button></td>';
        clubsBody.appendChild(row);
    });

    clubsBody.addEventListener('click', function(e){
        if (e.target.classList.contains('lgw-mc-remove-club')) {
            if (clubsBody.querySelectorAll('.lgw-mc-club-row').length <= 1) { alert('At least one club required.'); return; }
            e.target.closest('tr').remove();
        }
        if (e.target.classList.contains('lgw-mc-remove-badge')) {
            var row = e.target.closest('tr');
            row.querySelector('.lgw-mc-badge-url').value = '';
            var img = row.querySelector('img');
            if (img) img.remove();
            e.target.remove();
        }
        if (e.target.classList.contains('lgw-mc-choose-badge')) {
            var targetRow = e.target.closest('tr');
            var frame = wp.media({ title: 'Choose Club Badge', button: { text: 'Use this badge' }, multiple: false });
            frame.on('select', function(){
                var att = frame.state().get('selection').first().toJSON();
                targetRow.querySelector('.lgw-mc-badge-url').value = att.url;
                var existing = targetRow.querySelector('img');
                if (existing) existing.remove();
                var img = document.createElement('img');
                img.src = att.url;
                img.style.cssText = 'width:32px;height:32px;object-fit:contain';
                var cell = targetRow.querySelector('.lgw-mc-choose-badge').parentNode;
                cell.insertBefore(img, cell.firstChild);
                // Add remove button if not present
                if (!targetRow.querySelector('.lgw-mc-remove-badge')) {
                    var rm = document.createElement('button');
                    rm.type = 'button';
                    rm.className = 'button button-small lgw-mc-remove-badge';
                    rm.textContent = 'Remove';
                    cell.appendChild(rm);
                }
            });
            frame.open();
        }
    });
}

// ── Admin: add game status dropdown to save handler ───────────────────────────

// The save handler already reads .mc-save-game — we just need to ensure
// the status select is included. It's added in the PHP game card.
// Override the save handler to also send game_status:
if (scoresWrap) {
    // Patch: intercept all save clicks to add status
    scoresWrap.addEventListener('click', function(e){
        if (!e.target.classList.contains('mc-save-game')) return;
        // Status select is rendered by PHP — find it in the card
        var card = e.target.closest('.lgw-mc-game-card');
        if (!card) return;
        var sel = card.querySelector('.mc-game-status');
        if (sel) {
            // Store on the card so the existing save handler picks it up
            card._gameStatus = sel.value;
        }
    }, true); // capture phase so this runs before the existing handler
}

// ── Frontend: persistent unlock bar ──────────────────────────────────────────

document.querySelectorAll('.lgw-mc-widget').forEach(function(widget){
    var bar = widget.querySelector('.lgw-mc-unlock-bar');
    if (!bar) return;

    var input   = bar.querySelector('.lgw-mc-passphrase-input');
    var btn     = bar.querySelector('.lgw-mc-unlock-btn');
    var status  = bar.querySelector('.lgw-mc-unlock-status');
    var champ   = widget.dataset.champ;
    var nonce   = widget.dataset.nonce;
    var unlocked = false;
    var passphrase = '';

    // Try session-stored passphrase
    try {
        var stored = sessionStorage.getItem('lgw_mc_pass_' + champ);
        if (stored) { passphrase = stored; tryUnlock(stored, true); }
    } catch(e){}

    function tryUnlock(pass, silent) {
        // Verify by attempting a no-op frontend score call with the passphrase
        var fd = new FormData();
        fd.append('action',   'lgw_mc_verify_passphrase');
        fd.append('nonce',    nonce);
        fd.append('champ_id', champ);
        fd.append('passphrase', pass);
        fetch((typeof lgwMcData !== 'undefined' ? lgwMcData.ajaxurl : window.ajaxurl) || '/wp-admin/admin-ajax.php', { method:'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(data){
                if (data.success) {
                    unlocked = true;
                    passphrase = pass;
                    try { sessionStorage.setItem('lgw_mc_pass_' + champ, pass); } catch(e){}
                    status.textContent = '✅ Unlocked';
                    status.style.color = '#2e7d32';
                    bar.classList.add('unlocked');
                    // Show all score entry forms
                    widget.querySelectorAll('.lgw-mc-fe-inputs').forEach(function(f){ f.style.display = ''; });
                } else {
                    if (!silent) { status.textContent = '❌ Incorrect passphrase'; status.style.color = '#c62828'; }
                    unlocked = false;
                }
            }).catch(function(){});
    }

    btn.addEventListener('click', function(){
        var pass = input.value.trim();
        if (!pass) return;
        tryUnlock(pass, false);
    });
    input.addEventListener('keydown', function(e){ if (e.key === 'Enter') btn.click(); });

    // Save handler for frontend score entry
    widget.addEventListener('click', function(e){
        if (!e.target.classList.contains('lgw-mc-fe-save')) return;
        if (!unlocked) { alert('Please unlock score entry first.'); return; }

        var row   = e.target.closest('.lgw-mc-game-row');
        if (!row) return;
        var fixId   = row.dataset.fix;
        var discId  = row.dataset.disc;
        var gameIdx = row.dataset.idx;
        var champId = row.dataset.champ;
        var inputs  = row.querySelector('.lgw-mc-fe-inputs');
        var sh         = inputs.querySelector('.lgw-mc-fe-home').value;
        var sa         = inputs.querySelector('.lgw-mc-fe-away').value;
        var gstatus    = inputs.querySelector('.lgw-mc-fe-status').value;
        var playersH   = (inputs.querySelector('.lgw-mc-fe-players-home') || {}).value || '';
        var playersA   = (inputs.querySelector('.lgw-mc-fe-players-away') || {}).value || '';

        if (sh === '' || sa === '') { alert('Enter scores for both sides.'); return; }

        var saveBtn = e.target;
        saveBtn.disabled = true; saveBtn.textContent = 'Saving…';

        var fd = new FormData();
        fd.append('action',      'lgw_mc_frontend_score');
        fd.append('nonce',       nonce);
        fd.append('champ_id',    champId);
        fd.append('fix_id',      fixId);
        fd.append('disc_id',     discId);
        fd.append('game_idx',    gameIdx);
        fd.append('shots_home',  sh);
        fd.append('shots_away',  sa);
        fd.append('game_status',   gstatus);
        fd.append('players_home',  playersH);
        fd.append('players_away',  playersA);
        fd.append('passphrase',    passphrase);

        fetch((typeof lgwMcData !== 'undefined' ? lgwMcData.ajaxurl : window.ajaxurl) || '/wp-admin/admin-ajax.php', { method:'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(data){
                if (!data.success) {
                    alert('Save failed: ' + (data.data || 'error'));
                    saveBtn.disabled = false; saveBtn.textContent = 'Save';
                    return;
                }
                saveBtn.textContent = '✓'; saveBtn.disabled = false;
                // Update displayed scores
                var homeEl = row.querySelector('.lgw-mc-score-col .lgw-mc-game-shots');
                var awayEl = row.querySelector('.lgw-mc-score-col-away .lgw-mc-game-shots');
                if (homeEl) { homeEl.textContent = sh; homeEl.classList.toggle('bold-winner', data.data.pts_home > data.data.pts_away); }
                if (awayEl) { awayEl.textContent = sa; awayEl.classList.toggle('bold-winner', data.data.pts_away > data.data.pts_home); }
                // Update game row data attrs so live total can read them
                row.dataset.sh = sh; row.dataset.sa = sa;
                row.dataset.ph = data.data.pts_home; row.dataset.pa = data.data.pts_away;
                // Update displayed player names
                var pHomeEl = row.querySelector('.lgw-mc-score-col .lgw-mc-players');
                var pAwayEl = row.querySelector('.lgw-mc-score-col-away .lgw-mc-players');
                if (playersH) {
                    if (!pHomeEl) { pHomeEl = document.createElement('span'); pHomeEl.className = 'lgw-mc-players'; row.querySelector('.lgw-mc-score-col').appendChild(pHomeEl); }
                    pHomeEl.textContent = playersH;
                }
                if (playersA) {
                    if (!pAwayEl) { pAwayEl = document.createElement('span'); pAwayEl.className = 'lgw-mc-players'; row.querySelector('.lgw-mc-score-col-away').appendChild(pAwayEl); }
                    pAwayEl.textContent = playersA;
                }
                // Update status badge
                var statusLabels = { 'in_progress': '🟡 In progress', 'complete': '✅ Complete' };
                var badge = row.querySelector('.lgw-mc-status-badge');
                if (!badge) { badge = document.createElement('span'); badge.className = 'lgw-mc-status-badge'; row.querySelector('.lgw-mc-disc-label').appendChild(badge); }
                badge.textContent = statusLabels[gstatus] || '';
                // Show this row if hidden
                row.style.display = '';
                // Update fixture total header
                lgwMcUpdateFixtureTotal(widget, row.dataset.fix);
                setTimeout(function(){ saveBtn.textContent = 'Save'; }, 2000);
            })
            .catch(function(){ saveBtn.disabled = false; saveBtn.textContent = 'Save'; });
    });
});

// ── Admin: drag-and-drop fixture row reordering ───────────────────────────────

var dragSrc = null;

function lgwMcBindRowDrag(row) {
    row.addEventListener('dragstart', function(e){
        dragSrc = row;
        e.dataTransfer.effectAllowed = 'move';
        row.classList.add('lgw-mc-dragging');
    });
    row.addEventListener('dragend', function(){
        row.classList.remove('lgw-mc-dragging');
        if (fixBody) fixBody.querySelectorAll('.lgw-mc-fix-row').forEach(function(r){
            r.classList.remove('lgw-mc-drag-over');
        });
    });
    row.addEventListener('dragover', function(e){
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        if (dragSrc && dragSrc !== row) row.classList.add('lgw-mc-drag-over');
    });
    row.addEventListener('dragleave', function(){ row.classList.remove('lgw-mc-drag-over'); });
    row.addEventListener('drop', function(e){
        e.preventDefault();
        row.classList.remove('lgw-mc-drag-over');
        if (!dragSrc || dragSrc === row) return;
        // Insert dragSrc before this row
        var tbody = row.parentNode;
        var rows  = Array.from(tbody.querySelectorAll('.lgw-mc-fix-row'));
        var srcIdx = rows.indexOf(dragSrc);
        var tgtIdx = rows.indexOf(row);
        if (srcIdx < tgtIdx) {
            tbody.insertBefore(dragSrc, row.nextSibling);
        } else {
            tbody.insertBefore(dragSrc, row);
        }
        renumberFixRows();
        dragSrc = null;
    });
}

// Bind drag to all existing fixture rows on page load
if (fixBody) {
    fixBody.querySelectorAll('.lgw-mc-fix-row').forEach(lgwMcBindRowDrag);
}

// ── Frontend: drag-and-drop fixture card reordering (admin only) ──────────────

document.querySelectorAll('.lgw-mc-widget').forEach(function(widget){
    var isAdmin = (typeof lgwMcData !== 'undefined' && lgwMcData.isAdmin === '1');
    if (!isAdmin) return;

    var champId  = widget.dataset.champ;
    var nonce    = widget.dataset.nonce;
    var panel    = widget.querySelector('[data-panel="fixtures"]');
    if (!panel) return;

    var feCards  = panel.querySelectorAll('.lgw-mc-fixture-card');
    if (!feCards.length) return;

    var feDragSrc = null;

    // Add drag handle to each card
    feCards.forEach(function(card){
        var handle = document.createElement('div');
        handle.className = 'lgw-mc-fe-drag-handle';
        handle.title = 'Drag to reorder';
        handle.textContent = '⠿';
        card.querySelector('.lgw-mc-match-header').prepend(handle);
        card.draggable = true;
        lgwMcBindCardDrag(card, panel, champId, nonce);
    });
});

function lgwMcBindCardDrag(card, panel, champId, nonce) {
    card.addEventListener('dragstart', function(e){
        feDragSrc = card;
        e.dataTransfer.effectAllowed = 'move';
        card.classList.add('lgw-mc-dragging');
    });
    card.addEventListener('dragend', function(){
        card.classList.remove('lgw-mc-dragging');
        panel.querySelectorAll('.lgw-mc-fixture-card').forEach(function(c){
            c.classList.remove('lgw-mc-drag-over');
        });
    });
    card.addEventListener('dragover', function(e){
        e.preventDefault();
        if (feDragSrc && feDragSrc !== card) card.classList.add('lgw-mc-drag-over');
    });
    card.addEventListener('dragleave', function(){ card.classList.remove('lgw-mc-drag-over'); });
    card.addEventListener('drop', function(e){
        e.preventDefault();
        card.classList.remove('lgw-mc-drag-over');
        if (!feDragSrc || feDragSrc === card) return;

        // Reorder in DOM
        var cards  = Array.from(panel.querySelectorAll('.lgw-mc-fixture-card'));
        var srcIdx = cards.indexOf(feDragSrc);
        var tgtIdx = cards.indexOf(card);
        if (srcIdx < tgtIdx) {
            panel.insertBefore(feDragSrc, card.nextSibling);
        } else {
            panel.insertBefore(feDragSrc, card);
        }
        feDragSrc = null;

        // Persist new order via AJAX
        var newOrder = Array.from(panel.querySelectorAll('.lgw-mc-fixture-card')).map(function(c){ return c.dataset.fix; });
        var fd = new FormData();
        fd.append('action',   'lgw_mc_reorder_fixtures');
        fd.append('nonce',    nonce);
        fd.append('champ_id', champId);
        newOrder.forEach(function(id){ fd.append('order[]', id); });

        fetch((typeof lgwMcData !== 'undefined' ? lgwMcData.ajaxurl : window.ajaxurl) || '/wp-admin/admin-ajax.php',
            { method:'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(data){ if (!data.success) console.warn('Reorder failed', data); })
            .catch(function(e){ console.warn('Reorder error', e); });
    });
}

// feDragSrc scoped to widget loop — hoist to module scope
var feDragSrc = null;


})();

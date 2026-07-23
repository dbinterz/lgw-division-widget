/* LGW Group Stage Championships JS — v7.2.9 */
(function () {
    'use strict';

    var ajaxUrl   = (typeof lgwGchampData !== 'undefined') ? lgwGchampData.ajaxUrl : '';
    var nonce     = (typeof lgwGchampData !== 'undefined') ? lgwGchampData.nonce   : '';
    var canScore  = (typeof lgwGchampData !== 'undefined') ? !!lgwGchampData.canScore : false;

    // ── Day-level tabs ────────────────────────────────────────────────────────
    document.querySelectorAll('.lgw-gchamp-wrap').forEach(function (wrap) {
        var champId = wrap.getAttribute('data-gchamp-id');

        // Bind day tabs
        wrap.querySelectorAll('.lgw-gchamp-day-tab').forEach(function (tab) {
            tab.addEventListener('click', function () {
                var idx = tab.getAttribute('data-day-tab');
                wrap.querySelectorAll('.lgw-gchamp-day-tab').forEach(function (t) {
                    t.classList.toggle('active', t === tab);
                    t.setAttribute('aria-selected', t === tab ? 'true' : 'false');
                });
                wrap.querySelectorAll('.lgw-gchamp-day-pane').forEach(function (p) {
                    p.classList.toggle('active', p.getAttribute('data-day-pane') === idx);
                });
                try { if (champId) sessionStorage.setItem('lgw_gchamp_day_' + champId, idx); } catch(e){}
            });
        });

        // Restore saved day tab
        try {
            var savedDay = champId ? sessionStorage.getItem('lgw_gchamp_day_' + champId) : null;
            if (savedDay !== null) {
                var savedTab = wrap.querySelector('.lgw-gchamp-day-tab[data-day-tab="' + savedDay + '"]');
                if (savedTab) savedTab.click();
            }
        } catch(e) {}

        // Bind sub-tabs (Groups | Knockout) — per day pane
        wrap.querySelectorAll('.lgw-gchamp-day-pane').forEach(function (pane) {
            var dayIdx = pane.getAttribute('data-day-pane');
            pane.querySelectorAll('.lgw-gchamp-sub-tab').forEach(function (tab) {
                tab.addEventListener('click', function () {
                    if (tab.classList.contains('locked')) return;
                    var target = tab.getAttribute('data-sub-pane');
                    pane.querySelectorAll('.lgw-gchamp-sub-tab').forEach(function (t) {
                        t.classList.toggle('active', t === tab);
                    });
                    pane.querySelectorAll('.lgw-gchamp-sub-pane').forEach(function (p) {
                        p.classList.toggle('active', p.getAttribute('data-sub-pane') === target);
                    });
                    try { if (champId) sessionStorage.setItem('lgw_gchamp_sub_' + champId + '_' + dayIdx, target); } catch(e){}
                });
            });
            // Restore sub-tab
            try {
                var savedSub = champId ? sessionStorage.getItem('lgw_gchamp_sub_' + champId + '_' + dayIdx) : null;
                if (savedSub) {
                    var st = pane.querySelector('.lgw-gchamp-sub-tab[data-sub-pane="' + savedSub + '"]');
                    if (st && !st.classList.contains('locked')) st.click();
                }
            } catch(e){}
        });
    });

    // ── Fixture toggle ────────────────────────────────────────────────────────
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.lgw-gchamp-fixtures-toggle');
        if (!btn) return;
        var body = btn.nextElementSibling;
        if (!body) return;
        var open = body.classList.toggle('open');
        btn.classList.toggle('open', open);
    });

    // ── Score entry helpers ───────────────────────────────────────────────────
    function showForm(entry) {
        var form    = entry.querySelector('.lgw-gchamp-score-form');
        var display = entry.querySelector('.lgw-gchamp-score-display');
        var addBtn  = entry.querySelector('.lgw-gchamp-score-add-btn');
        var editBtn = entry.querySelector('.lgw-gchamp-score-edit-btn');
        if (form)    form.style.display    = 'flex';
        if (display) display.style.display = 'none';
        if (addBtn)  addBtn.style.display  = 'none';
        if (editBtn) editBtn.style.display = 'none';
        var h = entry.querySelector('.lgw-gchamp-score-h');
        if (h) { h.focus(); h.select(); }
    }

    function hideForm(entry) {
        var form    = entry.querySelector('.lgw-gchamp-score-form');
        var display = entry.querySelector('.lgw-gchamp-score-display');
        var addBtn  = entry.querySelector('.lgw-gchamp-score-add-btn');
        var editBtn = entry.querySelector('.lgw-gchamp-score-edit-btn');
        if (form)    form.style.display    = 'none';
        if (display) display.style.display = '';
        if (addBtn)  addBtn.style.display  = '';
        if (editBtn) editBtn.style.display = '';
    }

    function showError(row, msg) {
        var err = row.nextElementSibling;
        if (err && err.classList.contains('lgw-gchamp-row-err')) err.remove();
        var div = document.createElement('div');
        div.className = 'lgw-gchamp-row-err';
        div.style.cssText = 'padding:4px 10px;font-size:11px;color:#dc2626;background:#fff5f5;border-bottom:1px solid #fca5a5';
        div.textContent = msg;
        row.parentNode.insertBefore(div, row.nextSibling);
        setTimeout(function(){ div.remove(); }, 4000);
    }

    // ── Group score entry ─────────────────────────────────────────────────────
    document.addEventListener('click', function (e) {
        var addBtn  = e.target.closest('.lgw-gchamp-score-add-btn');
        var editBtn = e.target.closest('.lgw-gchamp-score-edit-btn');
        var saveBtn = e.target.closest('.lgw-gchamp-score-save-btn');
        var clearBtn= e.target.closest('.lgw-gchamp-score-clear-btn');
        var cancelBtn=e.target.closest('.lgw-gchamp-score-cancel-btn');

        if (addBtn || editBtn) {
            var entry = (addBtn || editBtn).closest('.lgw-gchamp-score-entry');
            if (entry) showForm(entry);
            return;
        }
        if (cancelBtn) {
            var entry = cancelBtn.closest('.lgw-gchamp-score-entry');
            if (entry) hideForm(entry);
            return;
        }
        if (saveBtn || clearBtn) {
            var entry = (saveBtn||clearBtn).closest('.lgw-gchamp-score-entry');
            var row   = entry ? entry.closest('.lgw-gchamp-fixture-row') : null;
            if (!row) return;
            submitGroupScore(row, entry, !!clearBtn);
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key !== 'Enter') return;
        var inp = e.target.closest('.lgw-gchamp-score-h, .lgw-gchamp-score-a');
        if (!inp) return;
        e.preventDefault();
        var entry = inp.closest('.lgw-gchamp-score-entry');
        var row   = entry ? entry.closest('.lgw-gchamp-fixture-row') : null;
        if (row) submitGroupScore(row, entry, false);
    });

    function submitGroupScore(row, entry, clear) {
        var wrap    = row.closest('.lgw-gchamp-wrap');
        var champId = wrap ? wrap.getAttribute('data-gchamp-id') : '';
        var posKey  = row.getAttribute('data-pos-key');
        var dayId   = row.getAttribute('data-day-id');
        var groupId = row.getAttribute('data-group-id');
        var hInput  = entry.querySelector('.lgw-gchamp-score-h');
        var aInput  = entry.querySelector('.lgw-gchamp-score-a');
        var saving  = entry.querySelector('.lgw-gchamp-score-saving');

        if (!clear) {
            if (!hInput.value.trim() || !aInput.value.trim()) { showError(row,'Enter both scores.'); return; }
            if (parseInt(hInput.value)<0||parseInt(aInput.value)<0) { showError(row,'Scores must be 0 or above.'); return; }
        }

        entry.querySelector('.lgw-gchamp-score-form').style.display = 'none';
        if (saving) saving.style.display = 'inline';

        var fd = new FormData();
        fd.append('action','lgw_gchamp_save_score'); fd.append('nonce',nonce);
        fd.append('champ_id',champId); fd.append('day_id',dayId);
        fd.append('group_id',groupId); fd.append('pos_key',posKey);
        fd.append('context','group');
        if (clear) fd.append('clear','1');
        else { fd.append('home_score',hInput.value); fd.append('away_score',aInput.value); }

        fetch(ajaxUrl,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(data){
            if (saving) saving.style.display='none';
            if (!data.success) { entry.querySelector('.lgw-gchamp-score-form').style.display='flex'; showError(row,data.data||'Error saving score.'); return; }
            if (clear) { hInput.value=''; aInput.value=''; }
            // Update displayed score on the row
            updateGroupFixtureRow(row, data.data, clear);
            // Update standings table in this group card
            if (data.data && data.data.standings) {
                var card = row.closest('.lgw-gchamp-group-card');
                if (card) updateStandingsTable(card, data.data.standings);
            }
            // Reload if day just completed, KO was just seeded, or KO bracket changed (reseed after unlock edit)
            var dayPane    = row.closest('[data-day-pane]');
            var hasKoWrap  = dayPane ? !!dayPane.querySelector('[data-sub-pane="knockout"] .lgw-gchamp-ko-wrap') : false;
            var justSeeded = data.data && data.data.ko_seeded && !hasKoWrap;
            var koChanged  = data.data && data.data.ko_seeded && hasKoWrap; // bracket was reseeded
            var justDone   = data.data && data.data.day_complete;
            if (justDone || justSeeded || koChanged) {
                setTimeout(function(){ location.reload(); }, 800);
            }
        }).catch(function(err){ if(saving)saving.style.display='none'; entry.querySelector('.lgw-gchamp-score-form').style.display='flex'; showError(row,'Request failed: '+err.message); });
    }

    function updateGroupFixtureRow(row, data, clear) {
        var entry   = row.querySelector('.lgw-gchamp-score-entry');
        var display = entry ? entry.querySelector('.lgw-gchamp-score-display') : null;
        var addBtn  = entry ? entry.querySelector('.lgw-gchamp-score-add-btn')  : null;
        var editBtn = entry ? entry.querySelector('.lgw-gchamp-score-edit-btn') : null;
        var hInput  = entry ? entry.querySelector('.lgw-gchamp-score-h') : null;
        var aInput  = entry ? entry.querySelector('.lgw-gchamp-score-a') : null;

        if (clear) {
            if (display) display.style.display = 'none';
            if (addBtn)  { addBtn.style.display=''; }
            if (editBtn) editBtn.remove();
            row.classList.remove('lgw-gf-home-win','lgw-gf-away-win');
            var cb = entry ? entry.querySelector('.lgw-gchamp-score-clear-btn') : null;
            if (cb) cb.remove();
        } else {
            if (display) {
                var hs = hInput ? parseInt(hInput.value) : 0;
                var as = aInput ? parseInt(aInput.value) : 0;
                display.innerHTML = '<span class="lgw-gchamp-fixture-score-h">'+hs+'</span>&ndash;<span class="lgw-gchamp-fixture-score-a">'+as+'</span>';
                display.style.display = '';
            }
            if (addBtn)  addBtn.style.display  = 'none';
            if (!editBtn && entry) {
                var eb = document.createElement('button');
                eb.type='button'; eb.className='lgw-gchamp-score-edit-btn'; eb.textContent='✏';
                entry.insertBefore(eb, entry.querySelector('.lgw-gchamp-score-form'));
            }
            // Win/loss class
            if (hInput && aInput) {
                var hs2 = parseInt(hInput.value), as2 = parseInt(aInput.value);
                row.classList.toggle('lgw-gf-home-win', hs2 > as2);
                row.classList.toggle('lgw-gf-away-win', as2 > hs2);
            }
        }
    }

    // ── Update standings table ───────────────────────────────────────────────
    function updateStandingsTable(card, standings) {
        var tbody = card.querySelector('.lgw-gchamp-standings tbody');
        if (!tbody || !standings) return;
        standings.forEach(function(row, pos) {
            var tr = tbody.rows[pos];
            if (!tr) return;
            var diff = row.sf - row.sa;
            var diffStr = (diff > 0 ? '+' : '') + diff;
            var diffCls = diff > 0 ? 'lgw-gs-diff-pos' : (diff < 0 ? 'lgw-gs-diff-neg' : 'lgw-gs-diff-zero');
            // Update each cell by class
            var cells = {
                '.lgw-gs-num:nth-of-type(3)': row.p,
                '.lgw-gs-num:nth-of-type(4)': row.w,
                '.lgw-gs-num:nth-of-type(5)': row.d,
                '.lgw-gs-num:nth-of-type(6)': row.l,
            };
            // Simpler: update by index (pos 2=P,3=W,4=D,5=L,6=SF,7=SA,8=diff,9=pts)
            var tds = tr.querySelectorAll('td');
            if (tds.length >= 10) {
                tds[2].textContent = row.p;
                tds[3].textContent = row.w;
                tds[4].textContent = row.d;
                tds[5].textContent = row.l;
                tds[6].textContent = row.sf;
                tds[7].textContent = row.sa;
                tds[8].textContent = diffStr;
                tds[8].className   = 'lgw-gs-diff ' + diffCls;
                tds[9].textContent = row.pts;
            }
            // Row qualify highlight — re-sort visually would need DOM move, skip for now
        });
        // Update progress count in group header
        var progress = card.querySelector('.lgw-gchamp-group-progress');
        if (progress) {
            var total = card.querySelectorAll('.lgw-gchamp-fixture-row').length;
            var played = card.querySelectorAll('.lgw-gchamp-fixture-row.lgw-gf-home-win, .lgw-gchamp-fixture-row.lgw-gf-away-win').length;
            // Count drawn games too — simpler to just count rows with a score display visible
            var scoredCount = 0;
            card.querySelectorAll('.lgw-gchamp-fixture-row').forEach(function(r) {
                var disp = r.querySelector('.lgw-gchamp-score-display, .lgw-gchamp-fixture-score:not(.lgw-gchamp-fixture-score-tbd)');
                if (disp && disp.style.display !== 'none') scoredCount++;
            });
            progress.textContent = scoredCount + '/' + total;
        }
    }

    // ── KO score entry ────────────────────────────────────────────────────────
    document.addEventListener('click', function(e) {
        var koBtn    = e.target.closest('.lgw-gchamp-ko-score-btn, .lgw-gchamp-ko-score-edit-btn');
        var koSave   = e.target.closest('.lgw-gchamp-ko-save-btn');
        var koClear  = e.target.closest('.lgw-gchamp-ko-clear-btn');
        var koCancel = e.target.closest('.lgw-gchamp-ko-cancel-btn');

        if (koBtn) {
            var scoreEntry = koBtn.closest('.lgw-gchamp-ko-score-entry');
            if (!scoreEntry) return;
            koBtn.style.display = 'none';
            var form = scoreEntry.querySelector('.lgw-gchamp-ko-score-form');
            if (form) { form.style.display='flex'; var h=form.querySelector('.lgw-gchamp-ko-score-h'); if(h){h.focus();h.select();} }
            return;
        }
        if (koCancel) {
            var scoreEntry = koCancel.closest('.lgw-gchamp-ko-score-entry');
            if (!scoreEntry) return;
            scoreEntry.querySelector('.lgw-gchamp-ko-score-form').style.display='none';
            var btn=scoreEntry.querySelector('.lgw-gchamp-ko-score-btn,.lgw-gchamp-ko-score-edit-btn');
            if(btn) btn.style.display='';
            return;
        }
        if (koSave || koClear) {
            var scoreEntry = (koSave||koClear).closest('.lgw-gchamp-ko-score-entry');
            var match      = scoreEntry ? scoreEntry.closest('.lgw-gchamp-ko-match') : null;
            if (!match) return;
            submitKoScore(match, scoreEntry, !!koClear);
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key!=='Enter') return;
        var inp = e.target.closest('.lgw-gchamp-ko-score-h, .lgw-gchamp-ko-score-a');
        if (!inp) return;
        e.preventDefault();
        var scoreEntry = inp.closest('.lgw-gchamp-ko-score-entry');
        var match      = scoreEntry ? scoreEntry.closest('.lgw-gchamp-ko-match') : null;
        if (match) submitKoScore(match, scoreEntry, false);
    });

    function submitKoScore(matchEl, scoreEntry, clear) {
        var wrap    = matchEl.closest('.lgw-gchamp-wrap');
        var koWrap  = matchEl.closest('.lgw-gchamp-ko-wrap');
        var champId = wrap    ? wrap.getAttribute('data-gchamp-id') : '';
        var dayId   = koWrap  ? koWrap.getAttribute('data-day-id')  : matchEl.getAttribute('data-day-id');
        var round   = matchEl.getAttribute('data-round');
        var match   = matchEl.getAttribute('data-match');
        var hInput  = scoreEntry.querySelector('.lgw-gchamp-ko-score-h');
        var aInput  = scoreEntry.querySelector('.lgw-gchamp-ko-score-a');
        var saving  = scoreEntry.querySelector('.lgw-gchamp-ko-saving');
        var form    = scoreEntry.querySelector('.lgw-gchamp-ko-score-form');

        if (!clear) {
            if (!hInput.value.trim()||!aInput.value.trim()) return;
            if (parseInt(hInput.value)<0||parseInt(aInput.value)<0) return;
        }

        if (form)   form.style.display   = 'none';
        if (saving) saving.style.display = 'inline';

        var fd = new FormData();
        fd.append('action','lgw_gchamp_save_score'); fd.append('nonce',nonce);
        fd.append('champ_id',champId); fd.append('day_id',dayId);
        fd.append('context','ko'); fd.append('ko_round',round); fd.append('ko_match',match);
        fd.append('group_id','-1'); fd.append('pos_key','ko');
        if (clear) fd.append('clear','1');
        else { fd.append('home_score',hInput.value); fd.append('away_score',aInput.value); }

        fetch(ajaxUrl,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(data){
            if (saving) saving.style.display='none';
            if (!data.success) { if(form)form.style.display='flex'; alert('Error: '+(data.data||'Unknown')); return; }
            // Reload to reflect bracket advancement
            location.reload();
        }).catch(function(err){ if(saving)saving.style.display='none'; if(form)form.style.display='flex'; alert('Failed: '+err.message); });
    }

    // ── Clear all KO scores (admin) ───────────────────────────────────────────
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.lgw-gchamp-ko-clear-all-btn');
        if (!btn) return;
        if (!confirm('Clear all knockout scores for this day? This will reset the bracket to the seeded state and unlock all groups.')) return;
        var dayId   = btn.getAttribute('data-day-id');
        var champId = btn.getAttribute('data-champ-id');
        btn.disabled = true;
        btn.textContent = 'Clearing…';
        var fd = new FormData();
        fd.append('action','lgw_gchamp_clear_ko_scores');
        fd.append('nonce', nonce);
        fd.append('champ_id', champId);
        fd.append('day_id', dayId);
        fetch(ajaxUrl,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(data){
            if (data.success) { location.reload(); }
            else { btn.disabled=false; btn.textContent='🗑 Clear KO scores'; alert('Error: '+(data.data||'Unknown')); }
        }).catch(function(err){ btn.disabled=false; btn.textContent='🗑 Clear KO scores'; alert('Failed: '+err.message); });
    });

    // ── Manual seeding: reassign a first-round KO slot (admin) ─────────────────
    document.addEventListener('click', function(e) {
        var seedBtn    = e.target.closest('.lgw-gchamp-ko-seed-btn');
        var seedCancel = e.target.closest('.lgw-gchamp-ko-seed-cancel');
        var seedSave   = e.target.closest('.lgw-gchamp-ko-seed-save');

        if (seedBtn) {
            var team = seedBtn.closest('.lgw-gchamp-ko-team');
            var form = team ? team.querySelector('.lgw-gchamp-ko-seed-form') : null;
            if (form) { form.style.display = 'inline-flex'; seedBtn.style.display = 'none'; }
            return;
        }
        if (seedCancel) {
            var form = seedCancel.closest('.lgw-gchamp-ko-seed-form');
            var team = seedCancel.closest('.lgw-gchamp-ko-team');
            if (form) form.style.display = 'none';
            var b = team ? team.querySelector('.lgw-gchamp-ko-seed-btn') : null;
            if (b) b.style.display = '';
            return;
        }
        if (seedSave) {
            var team   = seedSave.closest('.lgw-gchamp-ko-team');
            var match  = seedSave.closest('.lgw-gchamp-ko-match');
            var koWrap = seedSave.closest('.lgw-gchamp-ko-wrap');
            var wrap   = seedSave.closest('.lgw-gchamp-wrap');
            var select = team ? team.querySelector('.lgw-gchamp-ko-seed-select') : null;
            if (!team || !match || !select) return;
            var champId = wrap   ? wrap.getAttribute('data-gchamp-id') : '';
            var dayId   = koWrap ? koWrap.getAttribute('data-day-id')  : match.getAttribute('data-day-id');
            var slot    = team.getAttribute('data-slot');
            var koMatch = match.getAttribute('data-match');
            seedSave.disabled = true;

            var fd = new FormData();
            fd.append('action','lgw_gchamp_ko_set_slot'); fd.append('nonce',nonce);
            fd.append('champ_id',champId); fd.append('day_id',dayId);
            fd.append('ko_match',koMatch); fd.append('slot',slot);
            fd.append('value',select.value);

            fetch(ajaxUrl,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(data){
                if (data.success) { location.reload(); }
                else { seedSave.disabled=false; alert('Error: '+(data.data||'Unknown')); }
            }).catch(function(err){ seedSave.disabled=false; alert('Failed: '+err.message); });
            return;
        }
    });

    // ── Manual Finals Week seeding: assign a qualifier source to a draw slot ───
    document.addEventListener('click', function(e) {
        var fBtn    = e.target.closest('.lgw-gchamp-finals-seed-btn');
        var fCancel = e.target.closest('.lgw-gchamp-finals-seed-cancel');
        var fSave   = e.target.closest('.lgw-gchamp-finals-seed-save');

        if (fBtn) {
            // The form is the button's sibling in both the pending placeholder
            // slot and the resolved team-name slot — find it via the shared parent.
            var form = fBtn.parentNode ? fBtn.parentNode.querySelector('.lgw-gchamp-finals-seed-form') : null;
            if (form) { form.style.display = 'inline-flex'; fBtn.style.display = 'none'; }
            return;
        }
        if (fCancel) {
            var form = fCancel.closest('.lgw-gchamp-finals-seed-form');
            if (form) form.style.display = 'none';
            var b = form && form.parentNode ? form.parentNode.querySelector('.lgw-gchamp-finals-seed-btn') : null;
            if (b) b.style.display = '';
            return;
        }
        if (fSave) {
            var form   = fSave.closest('.lgw-gchamp-finals-seed-form');
            var select = form ? form.querySelector('.lgw-gchamp-finals-seed-select') : null;
            if (!form || !select) return;
            var champId = form.getAttribute('data-champ-id');
            var seed    = form.getAttribute('data-seed');
            fSave.disabled = true;

            var fd = new FormData();
            fd.append('action','lgw_gchamp_finals_set_slot'); fd.append('nonce',nonce);
            fd.append('champ_id',champId); fd.append('seed',seed); fd.append('src',select.value);

            fetch(ajaxUrl,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(data){
                if (data.success) { location.reload(); }
                else { fSave.disabled=false; alert('Error: '+(data.data||'Unknown')); }
            }).catch(function(err){ fSave.disabled=false; alert('Failed: '+err.message); });
            return;
        }
    });

    // ── Manual Finals Week occupancy: choose who takes a semi-final/final slot ──
    document.addEventListener('click', function(e) {
        var oBtn    = e.target.closest('.lgw-gchamp-finals-occ-btn');
        var oCancel = e.target.closest('.lgw-gchamp-finals-occ-cancel');
        var oSave   = e.target.closest('.lgw-gchamp-finals-occ-save');

        if (oBtn) {
            var form = oBtn.parentNode ? oBtn.parentNode.querySelector('.lgw-gchamp-finals-occ-form') : null;
            if (form) { form.style.display = 'inline-flex'; oBtn.style.display = 'none'; }
            return;
        }
        if (oCancel) {
            var form = oCancel.closest('.lgw-gchamp-finals-occ-form');
            if (form) form.style.display = 'none';
            var b = form && form.parentNode ? form.parentNode.querySelector('.lgw-gchamp-finals-occ-btn') : null;
            if (b) b.style.display = '';
            return;
        }
        if (oSave) {
            var form   = oSave.closest('.lgw-gchamp-finals-occ-form');
            var select = form ? form.querySelector('.lgw-gchamp-finals-occ-select') : null;
            if (!form || !select) return;
            var champId = form.getAttribute('data-champ-id');
            var slot    = form.getAttribute('data-slot');
            oSave.disabled = true;

            var fd = new FormData();
            fd.append('action','lgw_gchamp_finals_set_occupant'); fd.append('nonce',nonce);
            fd.append('champ_id',champId); fd.append('slot',slot); fd.append('token',select.value);

            fetch(ajaxUrl,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(data){
                if (data.success) { location.reload(); }
                else { oSave.disabled=false; alert('Error: '+(data.data||'Unknown')); }
            }).catch(function(err){ oSave.disabled=false; alert('Failed: '+err.message); });
            return;
        }
    });


    if (window.lgwGchampData && lgwGchampData.isAdmin) {
        document.querySelectorAll('.lgw-gchamp-day-pane[data-seed-needed="1"]').forEach(function(pane) {
            var dayIdx = pane.getAttribute('data-day-pane');
            var wrap   = pane.closest('.lgw-gchamp-wrap');
            var champId= wrap ? wrap.getAttribute('data-gchamp-id') : '';
            if (!champId) return;

            // Show a seeding indicator in the KO sub-tab
            var koTab = pane.querySelector('.lgw-gchamp-sub-tab[data-sub-pane="knockout"]');
            if (koTab) koTab.textContent = '🏆 Knockout (seeding…)';

            var fd = new FormData();
            fd.append('action','lgw_gchamp_seed_day_ko'); fd.append('nonce',nonce);
            fd.append('champ_id',champId); fd.append('day_id',dayIdx);

            fetch(ajaxUrl,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(data){
                if (data.success) {
                    location.reload();
                } else {
                    if (koTab) koTab.textContent = '🏆 Knockout';
                    console.warn('lgw_gchamp_seed_day_ko failed:', data.data);
                }
            }).catch(function(err){
                if (koTab) koTab.textContent = '🏆 Knockout';
                console.warn('lgw_gchamp_seed_day_ko error:', err);
            });
        });
    }

    // ── Group lock / unlock ───────────────────────────────────────────────────
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.lgw-gchamp-group-lock-btn');
        if (!btn || btn.disabled) return;

        var koHasScores = btn.getAttribute('data-ko-has-scores') === '1';
        if (koHasScores) return; // button is disabled, but just in case

        var locked  = btn.getAttribute('data-locked') === '1';
        var newLock = !locked;
        var dayId   = btn.getAttribute('data-day-id');
        var groupId = btn.getAttribute('data-group-id');
        var wrap    = btn.closest('.lgw-gchamp-wrap');
        var champId = wrap ? wrap.getAttribute('data-gchamp-id') : '';

        var label = newLock ? 'Lock this group? Score entry will be disabled.' : 'Unlock this group for editing?';
        if (!confirm(label)) return;

        btn.disabled = true;
        var fd = new FormData();
        fd.append('action','lgw_gchamp_toggle_group_lock'); fd.append('nonce',nonce);
        fd.append('champ_id',champId); fd.append('day_id',dayId);
        fd.append('group_id',groupId); fd.append('lock', newLock ? '1' : '0');

        fetch(ajaxUrl,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(data){
            btn.disabled = false;
            if (!data.success) { alert('Error: '+(data.data||'Unknown')); return; }
            // Update button state
            btn.setAttribute('data-locked', data.data.locked ? '1' : '0');
            btn.textContent = data.data.locked ? '🔒' : '🔓';
            btn.title = data.data.locked ? 'Unlock group for editing' : 'Lock group scores';
            // Show/hide score entry buttons on all fixture rows in this group
            var card = btn.closest('.lgw-gchamp-group-card');
            if (card) {
                card.querySelectorAll('.lgw-gchamp-score-add-btn, .lgw-gchamp-score-edit-btn').forEach(function(b) {
                    b.style.display = data.data.locked ? 'none' : '';
                });
            }
        }).catch(function(err){ btn.disabled=false; alert('Failed: '+err.message); });
    });


})();

// ── Group Championship Search ──────────────────────────────────────────────────
(function () {
  'use strict';

  function qs(sel, ctx)  { return (ctx || document).querySelector(sel); }
  function qsa(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }
  function escHtml(s)    { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

  function post(action, data, cb) {
    var ajaxUrl = (typeof lgwGchampData !== 'undefined' && lgwGchampData.ajaxUrl) ? lgwGchampData.ajaxUrl : '/wp-admin/admin-ajax.php';
    var fd = new FormData();
    fd.append('action', action);
    Object.keys(data).forEach(function(k) { fd.append(k, data[k]); });
    fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(cb)
      .catch(function() { cb({ success: false, data: 'Network error' }); });
  }

  function openGchampSearch(gchampId) {
    var existing = qs('.lgw-champ-search-modal');
    if (existing) existing.parentNode.removeChild(existing);

    var nonce = (typeof lgwGchampData !== 'undefined') ? lgwGchampData.searchNonce : '';

    var modal = document.createElement('div');
    modal.className = 'lgw-champ-search-modal';
    modal.innerHTML =
      '<div class="lgw-champ-search-box">' +
        '<div class="lgw-champ-search-header">' +
          '<span class="lgw-champ-search-title">🔍 Group Championship Search</span>' +
          '<button class="lgw-champ-search-close" aria-label="Close">&times;</button>' +
        '</div>' +
        '<div class="lgw-champ-search-hint">Search by player name or club across all group fixtures, knockout rounds, and Finals Week.</div>' +
        '<div class="lgw-champ-search-controls">' +
          '<input class="lgw-champ-search-input" type="text" placeholder="Enter name or club…" autocomplete="off" autocorrect="off">' +
          '<div class="lgw-champ-search-mode-tabs">' +
            '<button class="lgw-champ-search-mode-btn active" data-mode="fixtures">📅 Fixtures</button>' +
            '<button class="lgw-champ-search-mode-btn" data-mode="results">✅ Results</button>' +
          '</div>' +
          '<button class="lgw-champ-search-btn">Search</button>' +
        '</div>' +
        '<div class="lgw-champ-search-status"></div>' +
        '<div class="lgw-champ-search-results"></div>' +
        '<div class="lgw-champ-search-actions" style="display:none">' +
          '<button class="lgw-champ-search-copy-btn">📋 Copy as Text</button>' +
          '<button class="lgw-champ-search-csv-btn">📥 Export CSV</button>' +
        '</div>' +
      '</div>';
    document.body.appendChild(modal);

    var input      = qs('.lgw-champ-search-input',  modal);
    var searchBtn  = qs('.lgw-champ-search-btn',     modal);
    var statusEl   = qs('.lgw-champ-search-status',  modal);
    var resultsEl  = qs('.lgw-champ-search-results', modal);
    var actionsEl  = qs('.lgw-champ-search-actions', modal);
    var modeBtns   = qsa('.lgw-champ-search-mode-btn', modal);
    var currentMode = 'fixtures';
    var lastData    = null;

    qs('.lgw-champ-search-close', modal).addEventListener('click', function() {
      modal.parentNode.removeChild(modal);
    });
    modal.addEventListener('click', function(e) {
      if (e.target === modal) modal.parentNode.removeChild(modal);
    });

    modeBtns.forEach(function(btn) {
      btn.addEventListener('click', function() {
        modeBtns.forEach(function(b) { b.classList.remove('active'); });
        btn.classList.add('active');
        currentMode = btn.dataset.mode;
        if (lastData) renderResults(lastData, currentMode);
      });
    });

    input.focus();

    function doSearch() {
      var q = input.value.trim();
      if (q.length < 2) {
        statusEl.textContent = 'Please enter at least 2 characters.';
        resultsEl.innerHTML = ''; actionsEl.style.display = 'none'; return;
      }
      searchBtn.disabled = true; searchBtn.textContent = '⏳ Searching…';
      statusEl.textContent = ''; resultsEl.innerHTML = ''; actionsEl.style.display = 'none';

      post('lgw_gchamp_search', {
        gchamp_id: gchampId, query: q, nonce: nonce
      }, function(res) {
        searchBtn.disabled = false; searchBtn.textContent = 'Search';
        if (!res.success) { statusEl.textContent = res.data || 'Search failed.'; return; }
        lastData = res.data;
        renderResults(lastData, currentMode);
      });
    }

    searchBtn.addEventListener('click', doSearch);
    input.addEventListener('keydown', function(e) { if (e.key === 'Enter') doSearch(); });

    function renderResults(data, mode) {
      var allMatches = data.matches || [];
      var query      = data.query   || '';
      var queryUpper = query.toUpperCase();

      var filtered = allMatches.filter(function(m) {
        return mode === 'fixtures' ? m.is_fixture : m.is_result;
      });

      if (!filtered.length) {
        var noun0 = mode === 'fixtures' ? 'upcoming fixtures' : 'results';
        statusEl.textContent = 'No ' + noun0 + ' found for "' + query + '".';
        resultsEl.innerHTML = ''; actionsEl.style.display = 'none'; return;
      }

      var noun1  = mode === 'fixtures' ? 'fixture' : 'result';
      var nounPl = filtered.length === 1 ? noun1 : noun1 + 's';
      statusEl.textContent = filtered.length + ' ' + nounPl + ' found for "' + query + '" in ' + escHtml(data.title) + '.';

      var homeMatches = filtered.filter(function(m) {
        return m.home && m.home.toUpperCase().indexOf(queryUpper) !== -1;
      });
      var awayMatches = filtered.filter(function(m) {
        return m.away && m.away.toUpperCase().indexOf(queryUpper) !== -1;
      });

      function groupByDate(arr) {
        var groups = {}, order = [];
        arr.forEach(function(m) {
          var key = m.date || 'TBC';
          if (!groups[key]) { groups[key] = []; order.push(key); }
          groups[key].push(m);
        });
        return { groups: groups, order: order };
      }

      function champBadge(entry) {
        if (!entry) return '';
        var badges = (typeof lgwGchampData !== 'undefined' && lgwGchampData.badges)     ? lgwGchampData.badges     : {};
        var clubs  = (typeof lgwGchampData !== 'undefined' && lgwGchampData.clubBadges) ? lgwGchampData.clubBadges : {};
        // Try exact match, then club extracted from "Name, Club" format
        var src = badges[entry] || '';
        if (!src) {
          var parts = entry.split(',');
          var club  = parts.length > 1 ? parts[parts.length - 1].trim() : '';
          src = clubs[club] || '';
        }
        return src ? '<img src="' + escHtml(src) + '" class="lgw-champ-team-badge" alt="">' : '';
      }

      function renderTable(matches, isHome) {
        if (!matches.length) return '';
        var grouped = groupByDate(matches);
        var html = '<table class="lgw-champ-sr-table"><thead><tr>' +
          '<th>Date</th>' +
          '<th class="lgw-champ-sr-section-cell">Group / Stage</th>' +
          '<th class="lgw-champ-sr-round">Round</th>' +
          '<th>Match</th>' +
          (mode === 'results' ? '<th class="lgw-champ-sr-score-col">Score</th>' : '') +
          '</tr></thead><tbody>';

        grouped.order.forEach(function(dateKey) {
          grouped.groups[dateKey].forEach(function(m, i) {
            var homeWin = m.has_result && parseFloat(m.home_score) > parseFloat(m.away_score);
            var awayWin = m.has_result && parseFloat(m.away_score) > parseFloat(m.home_score);
            var homeClass = 'lgw-champ-sr-vs-name' + (isHome  ? ' lgw-champ-sr-highlight' : '');
            var awayClass = 'lgw-champ-sr-vs-name' + (!isHome ? ' lgw-champ-sr-highlight' : '');
            var dateLabel = dateKey === 'TBC' ? '<span class="lgw-champ-sr-tbd">TBC</span>' : escHtml(dateKey);
            var matchCell =
              '<div class="lgw-champ-sr-vs-row">' +
                '<span class="' + homeClass + '">' + champBadge(m.home) + escHtml(m.home || 'TBD') + '</span>' +
                '<span class="lgw-champ-sr-vs-sep">vs</span>' +
                '<span class="' + awayClass + '">' + champBadge(m.away) + escHtml(m.away || 'TBD') + '</span>' +
              '</div>';
            var scoreCell = '';
            if (mode === 'results') {
              if (m.has_result) {
                var hs = escHtml(String(m.home_score)); var as = escHtml(String(m.away_score));
                scoreCell = '<span class="lgw-champ-sr-score-val' + (homeWin ? ' win-h' : '') + '">' + hs + '</span>'
                  + '<span class="lgw-champ-sr-score-dash">–</span>'
                  + '<span class="lgw-champ-sr-score-val' + (awayWin ? ' win-a' : '') + '">' + as + '</span>';
              } else {
                scoreCell = '<span class="lgw-champ-sr-score-pending">—</span>';
              }
            }
            html += '<tr class="lgw-champ-sr-row">';
            html += '<td class="lgw-champ-sr-date">' + (i === 0 ? dateLabel : '') + '</td>';
            html += '<td class="lgw-champ-sr-section-cell">' + escHtml(m.section) + '</td>';
            html += '<td class="lgw-champ-sr-round">' + escHtml(m.round) + '</td>';
            html += '<td class="lgw-champ-sr-match-cell">' + matchCell + '</td>';
            if (mode === 'results') html += '<td class="lgw-champ-sr-score-cell">' + scoreCell + '</td>';
            html += '</tr>';
          });
        });
        html += '</tbody></table>';
        return html;
      }

      var html = '';
      if (homeMatches.length) {
        html += '<div class="lgw-champ-sr-group">' +
          '<div class="lgw-champ-sr-group-label lgw-champ-sr-home-label">🏠 Home Fixtures</div>' +
          renderTable(homeMatches, true) + '</div>';
      }
      if (awayMatches.length) {
        html += '<div class="lgw-champ-sr-group">' +
          '<div class="lgw-champ-sr-group-label lgw-champ-sr-away-label">✈️ Away Fixtures</div>' +
          renderTable(awayMatches, false) + '</div>';
      }
      resultsEl.innerHTML = html;
      actionsEl.style.display = 'flex';
    }

    // ── Copy as Text ───────────────────────────────────────────────────────────
    qs('.lgw-champ-search-copy-btn', modal).addEventListener('click', function() {
      if (!lastData) return;
      var query      = lastData.query || '';
      var queryUpper = query.toUpperCase();
      var filtered   = (lastData.matches || []).filter(function(m) {
        return currentMode === 'fixtures' ? m.is_fixture : m.is_result;
      });
      var homeMatches = filtered.filter(function(m) { return m.home && m.home.toUpperCase().indexOf(queryUpper) !== -1; });
      var awayMatches = filtered.filter(function(m) { return m.away && m.away.toUpperCase().indexOf(queryUpper) !== -1; });
      var modeLabel = currentMode === 'fixtures' ? 'Fixtures' : 'Results';
      var lines = [escPlain(lastData.title) + ' — ' + modeLabel + ' for "' + escPlain(query) + '"', ''];
      function fmt(matches, isHome) {
        if (!matches.length) return;
        lines.push(isHome ? '🏠 HOME FIXTURES' : '✈️ AWAY FIXTURES'); lines.push('');
        var lastDate = null;
        matches.forEach(function(m) {
          var dateStr = m.date || 'TBC';
          if (dateStr !== lastDate) { if (lastDate !== null) lines.push(''); lines.push('📅 ' + dateStr); lastDate = dateStr; }
          var self_ = isHome ? (m.home||'TBD') : (m.away||'TBD');
          var opp   = isHome ? (m.away||'TBD') : (m.home||'TBD');
          var line  = '  ' + escPlain(m.section) + ' · ' + escPlain(m.round) + ' · ' + escPlain(self_) + ' v ' + escPlain(opp);
          if (currentMode === 'results' && m.has_result) {
            var s1 = isHome ? m.home_score : m.away_score;
            var s2 = isHome ? m.away_score : m.home_score;
            line += '  (' + s1 + '–' + s2 + ')';
          }
          lines.push(line);
        });
        lines.push('');
      }
      fmt(homeMatches, true); fmt(awayMatches, false);
      var text = lines.join('\n');
      var btn = this;
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function() {
          btn.textContent = '✅ Copied!'; setTimeout(function() { btn.textContent = '📋 Copy as Text'; }, 2000);
        }).catch(function() { fallbackCopy(text, btn); });
      } else { fallbackCopy(text, btn); }
    });

    function escPlain(s) { return String(s||''); }
    function fallbackCopy(text, btn) {
      var ta = document.createElement('textarea');
      ta.value = text; ta.style.cssText = 'position:fixed;top:-9999px;left:-9999px;opacity:0';
      document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); btn.textContent = '✅ Copied!'; } catch(e) { btn.textContent = '❌ Copy failed'; }
      setTimeout(function() { btn.textContent = '📋 Copy as Text'; }, 2000);
      document.body.removeChild(ta);
    }

    // ── Export CSV ─────────────────────────────────────────────────────────────
    qs('.lgw-champ-search-csv-btn', modal).addEventListener('click', function() {
      if (!lastData) return;
      var query      = lastData.query || '';
      var queryUpper = query.toUpperCase();
      var filtered   = (lastData.matches || []).filter(function(m) {
        return currentMode === 'fixtures' ? m.is_fixture : m.is_result;
      });
      if (!filtered.length) return;
      var header = currentMode === 'results'
        ? ['H/A','Group/Stage','Round','Date','Matched Entry','Opponent','Home Score','Away Score']
        : ['H/A','Group/Stage','Round','Date','Matched Entry','Opponent'];
      var rows = [header];
      filtered.forEach(function(m) {
        var isHome  = m.home && m.home.toUpperCase().indexOf(queryUpper) !== -1;
        var matched = isHome ? (m.home||'') : (m.away||'');
        var opp     = isHome ? (m.away||'') : (m.home||'');
        if (currentMode === 'results') {
          rows.push([isHome?'H':'A', m.section, m.round, m.date||'', matched, opp,
            m.home_score !== null ? m.home_score : '', m.away_score !== null ? m.away_score : '']);
        } else {
          rows.push([isHome?'H':'A', m.section, m.round, m.date||'', matched, opp]);
        }
      });
      var csv = rows.map(function(row) {
        return row.map(function(cell) {
          var s = String(cell);
          if (s.indexOf(',')!==-1||s.indexOf('"')!==-1||s.indexOf('\n')!==-1) s = '"'+s.replace(/"/g,'""')+'"';
          return s;
        }).join(',');
      }).join('\r\n');
      var blob = new Blob([csv], {type:'text/csv;charset=utf-8;'});
      var url  = URL.createObjectURL(blob);
      var a    = document.createElement('a');
      a.href = url; a.download = 'gchamp-' + query.replace(/[^a-z0-9]/gi,'_') + '-' + currentMode + '.csv';
      document.body.appendChild(a); a.click(); document.body.removeChild(a); URL.revokeObjectURL(url);
    });
  }

  // Wire up search buttons
  document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.lgw-gchamp-search-tab').forEach(function(btn) {
      btn.addEventListener('click', function(e) {
        e.stopPropagation();
        var gchampId = btn.dataset.gchampId;
        if (gchampId) openGchampSearch(gchampId);
      });
    });
  });
})();

/* LGW Division Widget JS - v5.3 */
(function(){
  'use strict';

  var badges     = (typeof lgwData !== 'undefined' && lgwData.badges)     ? lgwData.badges     : {};
  var clubBadges = (typeof lgwData !== 'undefined' && lgwData.clubBadges) ? lgwData.clubBadges : {};
  var ajaxUrl        = (typeof lgwData !== 'undefined') ? lgwData.ajaxUrl : '/wp-admin/admin-ajax.php';
  var scoreOverrides = (typeof lgwData !== 'undefined' && lgwData.scoreOverrides) ? lgwData.scoreOverrides : {};
  var playedDates    = (typeof lgwData !== 'undefined' && lgwData.playedDates)    ? lgwData.playedDates    : {};
  var recentResults  = (typeof lgwData !== 'undefined' && lgwData.recentResults)  ? lgwData.recentResults  : [];
  var scorecardStatus = (typeof lgwData !== 'undefined' && lgwData.scorecardStatus) ? lgwData.scorecardStatus : {};
  var postponements   = (typeof lgwData !== 'undefined' && lgwData.postponements)   ? lgwData.postponements   : {};
  var concessions     = (typeof lgwData !== 'undefined' && lgwData.concessions)     ? lgwData.concessions     : {};
  var nullVoids       = (typeof lgwData !== 'undefined' && lgwData.null_voids)      ? lgwData.null_voids      : {};

  // ── Apply null & void to fixture groups (mark as played, 0–0 result, no pts) ─
  function applyNullVoidsToFixtures(groups){
    if(!Object.keys(nullVoids).length) return groups;
    groups.forEach(function(g){
      g.matches.forEach(function(m){
        var key=(m.homeTeam+'||'+m.awayTeam+'||'+g.date).toLowerCase();
        if(!nullVoids[key]) return;
        m.shotsHome='0'; m.shotsAway='0';
        m.ptsHome='0';   m.ptsAway='0';
        m.played=true;   m.nullVoid=true;
      });
    });
    return groups;
  }

  // ── Apply concession adjustments to parsed table rows ────────────────────────
  // Mirrors lgw_apply_concessions_to_teams() in PHP.
  // Called after parseTableRows(); winner +maxPts/+W/+50F, conceder -maxPts/+L/+50A.
  function applyConcessionsToTable(teams, maxPts){
    if(!Object.keys(concessions).length || !teams.length) return teams;
    // Build name → index map (case-insensitive)
    var idxMap = {};
    teams.forEach(function(t, i){ idxMap[t.team.toUpperCase()] = i; });

    Object.keys(concessions).forEach(function(key){
      var c = concessions[key];
      var parts = key.split('||');
      if(parts.length < 2) return;
      var homeName  = parts[0].trim();
      var awayName  = parts[1].trim();
      var homeConcedes = (c.conceding_team === 'home');
      var winnerName = homeConcedes ? awayName : homeName;
      var loserName  = homeConcedes ? homeName : awayName;
      var wi = idxMap[winnerName.toUpperCase()];
      var li = idxMap[loserName.toUpperCase()];
      // Both teams must exist in this division — skip cross-division concessions
      if(wi === undefined || li === undefined) return;
      teams[wi].pl  = (parseInt(teams[wi].pl,10)  || 0) + 1;
      teams[wi].pts = (parseFloat(teams[wi].pts) || 0) + maxPts;
      teams[wi].w   = (parseInt(teams[wi].w,10)   || 0) + 1;
      teams[wi].f   = (parseInt(teams[wi].f,10)   || 0) + 50;
      teams[wi].diff = (parseInt(teams[wi].f,10)||0) - (parseInt(teams[wi].a,10)||0);
      teams[li].pl  = (parseInt(teams[li].pl,10)  || 0) + 1;
      teams[li].pts = (parseFloat(teams[li].pts) || 0) - maxPts;
      teams[li].l   = (parseInt(teams[li].l,10)   || 0) + 1;
      teams[li].a   = (parseInt(teams[li].a,10)   || 0) + 50;
      teams[li].diff = (parseInt(teams[li].f,10)||0) - (parseInt(teams[li].a,10)||0);
    });
    return teams;
  }

  // ── Apply concessions to parsed fixture groups (mark as played, 50-0 result) ─
  function applyConcessionsToFixtures(groups, maxPts){
    if(!Object.keys(concessions).length) return groups;
    groups.forEach(function(g){
      g.matches.forEach(function(m){
        var key = (m.homeTeam+'||'+m.awayTeam+'||'+g.date).toLowerCase();
        var c   = concessions[key];
        if(!c) return;
        var homeConcedes = (c.conceding_team === 'home');
        m.shotsHome  = homeConcedes ? '0' : '50';
        m.shotsAway  = homeConcedes ? '50' : '0';
        m.ptsHome    = homeConcedes ? String(-maxPts) : String(maxPts);
        m.ptsAway    = homeConcedes ? String(maxPts) : String(-maxPts);
        m.played     = true;
        m.conceded   = c.conceding_team;
      });
    });
    return groups;
  }

  // ── Apply admin score overrides to parsed fixture groups ─────────────────────
  function applyScoreOverrides(groups, csvUrl){
    if(!csvUrl || !Object.keys(scoreOverrides).length) return groups;
    groups.forEach(function(g){
      g.matches.forEach(function(m){
        var key = csvUrl+'||'+g.date+'||'+m.homeTeam+'||'+m.awayTeam;
        var ov  = scoreOverrides[key];
        if(ov){
          if(ov.sh!=='') m.shotsHome = ov.sh;
          if(ov.sa!=='') m.shotsAway = ov.sa;
          if(ov.ph!=='') m.ptsHome   = ov.ph;
          if(ov.pa!=='') m.ptsAway   = ov.pa;
          if(ov.sh!==''||ov.sa!=='') m.played = true;
          m.overridden = true;
        }
      });
    });
    return groups;
  }

  var submissionMode = (typeof lgwData !== 'undefined' && lgwData.submissionMode) ? lgwData.submissionMode : 'open';
  var isAdmin        = (typeof lgwData !== 'undefined' && lgwData.isAdmin === '1');
  var widgetAuthClub = (typeof lgwData !== 'undefined' && lgwData.authClub) ? lgwData.authClub : '';

  var PRINT_ICON = '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M19 8H5c-1.66 0-3 1.34-3 3v6h4v4h12v-4h4v-6c0-1.66-1.34-3-3-3zm-3 11H8v-5h8v5zm3-7c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1zm-1-9H6v4h12V3z"/></svg>';

  // ── Dark mode — stored on :root so modal (on body) inherits variables ─────────
  var DM_KEY = 'lgw_darkmode';

  function getDarkPref(){
    try{
      var v = localStorage.getItem(DM_KEY);
      if(v==='dark'||v==='light') return v;
    }catch(e){}
    return 'auto';
  }

  function applyThemeToRoot(pref){
    if(pref==='dark')       document.documentElement.setAttribute('data-lgw-theme','dark');
    else if(pref==='light') document.documentElement.setAttribute('data-lgw-theme','light');
    else                    document.documentElement.removeAttribute('data-lgw-theme');
  }

  function updateDMBtn(btn, pref){
    btn.textContent = pref==='dark' ? '☀ Light' : pref==='light' ? '⟳ Auto' : '☾ Dark';
    btn.title = pref==='dark' ? 'Switch to light mode' : pref==='light' ? 'Follow device setting' : 'Switch to dark mode';
  }

  // Apply on load
  applyThemeToRoot(getDarkPref());

  // ── Badge lookup: exact → case-insensitive exact → club prefix (longest wins) ─
  function badgeImg(team, cls){
    cls = cls||'lgw-badge';
    // 1. Exact match
    if(badges[team]) return '<img class="'+cls+'" src="'+badges[team]+'" alt="'+team+'">';
    // 2. Case-insensitive exact match
    var upper = team.toUpperCase();
    for(var key in badges){
      if(key.toUpperCase() === upper) return '<img class="'+cls+'" src="'+badges[key]+'" alt="'+team+'">';
    }
    // 3. Club prefix match — case-insensitive, word-boundary aware, longest prefix wins
    var bestKey = '', bestImg = '';
    for(var club in clubBadges){
      var clubUpper = club.toUpperCase();
      if(upper === clubUpper || upper.indexOf(clubUpper) === 0){
        var rest = team.slice(club.length);
        if(rest === '' || rest[0] === ' '){
          if(club.length > bestKey.length){ bestKey = club; bestImg = clubBadges[club]; }
        }
      }
    }
    if(bestImg) return '<img class="'+cls+'" src="'+bestImg+'" alt="'+team+'">';
    return '';
  }

  // ── CSV parser ────────────────────────────────────────────────────────────────
  function parseCSV(text){
    return text.split('\n').map(function(line){
      line=line.replace(/\r$/,'');
      var cells=[], cur='', inQ=false;
      for(var i=0;i<line.length;i++){
        var c=line[i];
        if(c==='"'){inQ=!inQ;}
        else if(c===','&&!inQ){cells.push(cur.trim());cur='';}
        else{cur+=c;}
      }
      cells.push(cur.trim());
      return cells;
    });
  }

  function nonEmpty(row){ return row.some(function(c){return c!=='';}) }

  // ── Parse fixtures ────────────────────────────────────────────────────────────
  function parseFixtureGroups(rows){
    var i=0;
    while(i<rows.length && rows[i].join('').indexOf('FIXTURES')===-1) i++;
    i++;
    if(i>=rows.length) return [];

    var colPtsH=0,colHTeam=2,colHScore=7,colAScore=9,colATeam=10,colPtsA=15,colTime=-1;
    for(var h=i;h<Math.min(i+5,rows.length);h++){
      var rowJoined=rows[h].join('').toLowerCase();
      // New-style reference row: uses labels like 'homepts','home','home shots','awaypts','time'
      if(rowJoined.indexOf('homepts')!==-1){
        for(var c=0;c<rows[h].length;c++){
          var hv=rows[h][c].trim().toLowerCase();
          if(hv==='homepts')    colPtsH=c;
          if(hv==='home')       colHTeam=c;
          if(hv==='home shots') colHScore=c;
          if(hv==='away shots') colAScore=c;
          if(hv==='away')       colATeam=c;
          if(hv==='awaypts')    colPtsA=c;
          if(hv==='time')       colTime=c;
        }
        i=h+1; break;
      }
      // Legacy-style header row: HPts, HTeam, HScore, AScore, ATeam, APts
      if(rowJoined.indexOf('hpts')!==-1){
        for(var c=0;c<rows[h].length;c++){
          var hv=rows[h][c].trim();
          if(hv==='HPts')   colPtsH=c;
          if(hv==='HTeam')  colHTeam=c;
          if(hv==='HScore') colHScore=c;
          if(hv==='Ascore'||hv==='AScore') colAScore=c;
          if(hv==='ATeam')  colATeam=c;
          if(hv==='APts')   colPtsA=c;
        }
        i=h+1; break;
      }
    }

    var dateRe=/^(Mon|Tue|Wed|Thu|Fri|Sat|Sun)\s+\d{1,2}-[A-Za-z]+-\d{4}$/;
    var groups=[], cur=null;
    while(i<rows.length){
      var r=rows[i];
      var first=(r[0]||r[1]||'').trim();
      if(dateRe.test(first)){
        cur={date:first,matches:[]};
        groups.push(cur);
        i++;
        while(i<rows.length && !nonEmpty(rows[i].slice(0,2)) && rows[i].join('').indexOf('Points')!==-1) i++;
        continue;
      }
      if(cur && nonEmpty(r)){
        var ptsHome  =(r[colPtsH]  ||'').trim();
        var homeTeam =(r[colHTeam] ||'').trim();
        var shotsHome=(r[colHScore]||'').trim();
        var shotsAway=(r[colAScore]||'').trim();
        var awayTeam =(r[colATeam] ||'').trim();
        var ptsAway  =(r[colPtsA]  ||'').trim();
        var timeNote='';
        if(colTime>=0){
          // Reference row provided a 'time' column — read directly, no scanning needed
          var tv=(r[colTime]||'').trim();
          if(/^\d{1,2}:\d{2}(:\d{2})?$/.test(tv)){
            timeNote=(tv.split(':').length>2)?tv.replace(/:\d{2}$/,''):tv;
          } else {
            var fv=parseFloat(tv);
            if(!isNaN(fv) && fv>0 && fv<1){
              var mins=Math.round(fv*1440);
              var hh=Math.floor(mins/60), mm=mins%60;
              timeNote=(hh<10?'0':'')+hh+':'+(mm<10?'0':'')+mm;
            }
          }
        } else {
          // No reference row — scan between awayTeam and awayPts (legacy fallback)
          for(var x=colATeam+1;x<=colPtsA+1;x++){
            var tv=(r[x]||'').trim();
            if(/^\d{1,2}:\d{2}(:\d{2})?$/.test(tv)){
              timeNote=(tv.split(':').length>2)?tv.replace(/:\d{2}$/,''):tv;
              break;
            } else {
              var fv=parseFloat(tv);
              if(!isNaN(fv) && fv>=0.333 && fv<=0.938){
                var mins=Math.round(fv*1440);
                var hh=Math.floor(mins/60), mm=mins%60;
                timeNote=(hh<10?'0':'')+hh+':'+(mm<10?'0':'')+mm;
                break;
              }
            }
          }
        }
        if(homeTeam && awayTeam){
          var played=(shotsHome!=='0'||shotsAway!=='0'||ptsHome!=='0'||ptsAway!=='0');
          cur.matches.push({
            ptsHome:ptsHome,ptsAway:ptsAway,
            homeTeam:homeTeam,awayTeam:awayTeam,
            shotsHome:shotsHome,shotsAway:shotsAway,
            timeNote:timeNote,played:played
          });
        }
      }
      i++;
    }
    return groups;
  }

  // ── Parse league table ────────────────────────────────────────────────────────
  function parseTableRows(rows){
    var i=0;
    while(i<rows.length && rows[i].join('').indexOf('LEAGUE TABLE')===-1) i++;
    i++;
    while(i<rows.length && !nonEmpty(rows[i])) i++;
    while(i<rows.length && rows[i][0]!=='POS') i++;
    if(i>=rows.length) return [];

    // Detect column positions from header row
    var hdr=rows[i];
    var colPos=-1,colTeam=-1,colPl=-1,colPts=-1,colDiff=-1,colW=-1,colL=-1,colD=-1,colFor=-1,colAgn=-1;
    for(var c=0;c<hdr.length;c++){
      var v=(hdr[c]||'').trim().toUpperCase().replace(/\s+/g,'');
      if(v==='POS')                          colPos=c;
      else if(v==='TEAM')                    colTeam=c;
      else if(v==='PL'||v==='PLAYED')        colPl=c;
      else if(v==='PTS'||v==='POINTS')       colPts=c;
      else if(v==='+/-'||v==='DIFF')         colDiff=c;
      else if(v==='W'||v==='WON')            colW=c;
      else if(v==='L'||v==='LOST')           colL=c;
      else if(v==='D'||v==='DRAWN')          colD=c;
      else if(v==='FOR')                     colFor=c;
      else if(v==='AGAINST'||v==='AGN')      colAgn=c;
    }
    // Fallback to positional guesses if header detection fails
    if(colPos<0)  colPos=0;
    if(colTeam<0) colTeam=1;
    if(colPl<0)   colPl=5;
    if(colPts<0)  colPts=7;
    if(colDiff<0) colDiff=8;
    if(colW<0)    colW=9;
    if(colL<0)    colL=10;
    if(colD<0)    colD=11;
    if(colFor<0)  colFor=12;
    if(colAgn<0)  colAgn=14;

    i++;
    var teams=[];
    while(i<rows.length && nonEmpty(rows[i])){
      var r=rows[i];
      var pos=r[colPos], team=r[colTeam];
      if(pos && team && !isNaN(parseInt(pos,10))){
        teams.push({
          pos:parseInt(pos,10),  team:team,
          pl:parseInt(r[colPl]||'0',10),
          pts:parseFloat(r[colPts]||'0'),
          diff:r[colDiff]||'0',
          w:r[colW]||'0',  l:r[colL]||'0',  d:r[colD]||'0',
          f:r[colFor]||'0', a:r[colAgn]||'0'
        });
      }
      i++;
    }
    return teams;
  }

  // ── Print helpers ─────────────────────────────────────────────────────────────
  var PRINT_CSS =
    'body{font-family:Saira,Arial,sans-serif;padding:20px;color:#1a1a1a;font-size:13px}'
    +'h2{color:#1a2e5a;margin:0 0 12px}'
    +'table{width:100%;border-collapse:collapse}'
    +'th{background:#1a2e5a;color:#fff;padding:6px 8px;font-size:11px;text-align:center}'
    +'th.ct{text-align:left}td{padding:6px 8px;border-bottom:1px solid #d0d5e8;text-align:center}'
    +'td.ct{text-align:left;font-weight:600}td.cp{color:#999;width:36px}td.ck{font-weight:700;color:#8f1520}'
    +'tr:nth-child(even) td{background:#f0f2f8}'
    +'.row-promote-zone td:first-child,.row-promoted td:first-child{border-left:4px solid #2a7a2a}'
    +'.row-relegate-zone td:first-child,.row-relegated td:first-child{border-left:4px solid #c0202a}'
    +'.row-promote-zone td{background:#f0faf0}.row-promoted td{background:#c8edc8}'
    +'.row-relegate-zone td{background:#fff5f5}.row-relegated td{background:#f5c8c8}'
    +'.lg-legend{padding:6px 10px;font-size:11px;color:#666;border-top:1px solid #d0d5e8}'
    +'.lgw-badge{width:20px;height:20px;max-width:20px;max-height:20px;vertical-align:middle;margin-right:4px}'
    +'.lgw-sponsor-img{max-height:48px;max-width:160px}'
    +'.date-hdr{background:#c0202a;color:#fff;padding:5px 10px;font-size:12px;font-weight:700;letter-spacing:.06em;margin-top:8px}'
    +'.fx-tbl{width:100%;border-collapse:collapse;margin-bottom:4px}'
    +'.fx-tbl td{padding:5px 8px;border-bottom:1px solid #d0d5e8;font-size:12px}'
    +'.fx-tbl tr:nth-child(even) td{background:#f0f2f8}'
    +'.fx-home{text-align:right;font-weight:600;width:35%}'
    +'.fx-away{text-align:left;font-weight:600;width:35%}'
    +'.fx-score{text-align:center;font-weight:700;white-space:nowrap;width:30%}'
    +'.fx-pts{font-size:11px;color:#999}'
    +'@media print{body{padding:0}}'
    +'.fx-score-wrap{display:flex;flex-direction:column;align-items:center;gap:3px}'
    +'.fx-time-pill{display:inline-block;background:#1a2e5a;color:#e8b400;font-size:11px;font-weight:700;padding:2px 10px;border-radius:20px;letter-spacing:.04em}';

  function printFixturesData(groups, title){
    var html='<h2>'+(title||'Fixtures &amp; Results')+'</h2>';
    groups.forEach(function(g){
      html+='<div class="date-hdr">'+g.date+'</div>';
      html+='<table class="fx-tbl"><tbody>';
      g.matches.forEach(function(m){
        var scoreStr = m.played ? m.shotsHome+' – '+m.shotsAway : 'v';
        var ptsStr   = m.played ? '<span class="fx-pts">('+m.ptsHome+' – '+m.ptsAway+')</span>' : '';
        var printTimePill=m.timeNote?'<span class="fx-time-pill">&#9200; '+m.timeNote+'</span>':'';
        html+='<tr>'
          +'<td class="fx-home">'+badgeImg(m.homeTeam)+m.homeTeam+'</td>'
          +'<td class="fx-score"><div class="fx-score-wrap">'+scoreStr+' '+ptsStr+printTimePill+'</div></td>'
          +'<td class="fx-away">'+badgeImg(m.awayTeam)+m.awayTeam+'</td>'
          +'</tr>';
      });
      html+='</tbody></table>';
    });
    return html;
  }

  function openPrintWindow(title, bodyHtml, extraCss){
    var existing=document.getElementById('lgw-print-frame');
    if(existing) existing.parentNode.removeChild(existing);
    var iframe=document.createElement('iframe');
    iframe.id='lgw-print-frame';
    iframe.style.cssText='position:fixed;top:-9999px;left:-9999px;width:1px;height:1px;border:none;visibility:hidden';
    document.body.appendChild(iframe);
    var doc=iframe.contentDocument||iframe.contentWindow.document;
    doc.open();
    doc.write(
      '<!DOCTYPE html><html><head><title>'+(title||'LGW')+'</title>'
      +'<style>'+PRINT_CSS+(extraCss||'')+'</style></head><body>'
      +bodyHtml
      +'</body></html>'
    );
    doc.close();
    iframe.onload=function(){
      try{iframe.contentWindow.focus();iframe.contentWindow.print();}catch(e){window.print();}
    };
    try{iframe.contentWindow.focus();iframe.contentWindow.print();}catch(e){}
  }

  // ── Modal ─────────────────────────────────────────────────────────────────────
  var modalEl=null;

  function ensureModal(){
    if(modalEl) return;
    modalEl=document.createElement('div');
    modalEl.className='lgw-modal-overlay';
    modalEl.innerHTML=
      '<div class="lgw-modal">'
      +'<div class="lgw-modal-head">'
      +'<div class="lgw-modal-title"></div>'
      +'<div class="lgw-modal-actions">'
      +'<button class="lgw-modal-print" title="Print">'+PRINT_ICON+'</button>'
      +'<button class="lgw-modal-close" title="Close">&times;</button>'
      +'</div></div>'
      +'<div class="lgw-modal-body"></div>'
      +'</div>';
    document.body.appendChild(modalEl);
    modalEl.addEventListener('click',function(e){if(e.target===modalEl)closeModal();});
    document.addEventListener('keydown',function(e){if(e.key==='Escape')closeModal();});
    modalEl.querySelector('.lgw-modal-close').addEventListener('click',closeModal);
    modalEl.querySelector('.lgw-modal-print').addEventListener('click',function(){
      var titleEl=modalEl.querySelector('.lgw-modal-title');
      var bodyEl =modalEl.querySelector('.lgw-modal-body');
      var teamName=titleEl.querySelector('h2')?titleEl.querySelector('h2').textContent:'';
      var modalPrintCss=PRINT_CSS
        +'.lgw-modal-title{display:block;margin-bottom:16px;padding-bottom:12px;border-bottom:3px solid #1a2e5a;overflow:hidden}'
        +'.lgw-modal-title h2{margin:0 0 4px;font-size:20px;color:#1a2e5a}'
        +'.lgw-modal-badge{width:40px !important;height:40px !important;max-width:40px !important;max-height:40px !important;object-fit:contain !important;float:left;margin-right:10px}'
        +'.modal-stat-bar{margin-bottom:16px;line-height:2.2}'
        +'.modal-stat{display:inline-block;background:#f0f2f8;border-radius:4px;padding:4px 10px;text-align:center;min-width:52px;margin:2px 4px 2px 0;vertical-align:top}'
        +'.modal-stat-val{display:block;font-size:16px;font-weight:700;color:#1a2e5a}'
        +'.modal-stat-lbl{display:block;font-size:10px;color:#666;text-transform:uppercase}'
        +'.modal-fix-table{width:100%;border-collapse:collapse}'
        +'.modal-fix-table td{padding:6px 8px;border-bottom:1px solid #d0d5e8}'
        +'.modal-fix-table th{background:#1a2e5a;color:#fff;padding:6px 8px;text-align:left;font-size:11px}'
        +'.modal-fix-table .lgw-badge{width:18px !important;height:18px !important;max-width:18px !important;max-height:18px !important;vertical-align:middle;margin-right:4px}'
        +'.modal-result-lbl{font-size:10px;font-weight:700;border-radius:3px;padding:1px 4px;margin-left:4px}'
        +'.res .modal-result-lbl{background:#2a7a2a;color:#fff}'
        +'.drew .modal-result-lbl{background:#888;color:#fff}'
        +'.lost .modal-result-lbl{background:#c0202a;color:#fff}'
        +'img{max-width:40px !important;max-height:40px !important}'  // safety net for any other images
        +'.modal-fix-table img{max-width:18px !important;max-height:18px !important}';
      openPrintWindow(teamName,
        '<div class="lgw-modal-title">'+titleEl.innerHTML+'</div>'+bodyEl.innerHTML,
        modalPrintCss
      );
    });
  }

  function openModal(titleHtml, bodyHtml, sourceWidget){
    ensureModal();
    modalEl.querySelector('.lgw-modal-title').innerHTML=titleHtml;
    modalEl.querySelector('.lgw-modal-body').innerHTML=bodyHtml;
    // Copy scoped CSS variables from widget wrapper onto modal (modal lives on body)
    var wrap=sourceWidget&&sourceWidget.parentElement;
    if(wrap){
      var cs=getComputedStyle(wrap);
      ['--lgw-navy','--lgw-navy-mid','--lgw-gold','--lgw-bg','--lgw-bg-alt',
       '--lgw-bg-hover','--lgw-tab-bg','--lgw-pts'].forEach(function(v){
        var val=cs.getPropertyValue(v).trim();
        if(val) modalEl.style.setProperty(v,val);
      });
    }
    modalEl.classList.add('active');
    document.body.classList.add('lgw-modal-open');
  }

  function closeModal(){
    if(modalEl) modalEl.classList.remove('active');
    document.body.classList.remove('lgw-modal-open');
  }

  function stat(val,lbl){
    return '<div class="modal-stat"><div class="modal-stat-val">'+val+'</div><div class="modal-stat-lbl">'+lbl+'</div></div>';
  }

  function showTeamModal(teamName, allRows, sourceWidget, parseFn, teamsList){
    parseFn = parseFn || parseFixtureGroups;
    var teams =parseTableRows(allRows);
    if(!teams.length && teamsList) teams=teamsList;
    var groups=parseFn(allRows);
    var teamData=null;
    for(var t=0;t<teams.length;t++){
      if(teams[t].team.toUpperCase()===teamName.toUpperCase()){teamData=teams[t];break;}
    }

    var bdg=badgeImg(teamName,'lgw-modal-badge');
    var titleHtml=bdg+'<h2>'+teamName+'</h2>';

    var statsHtml='';
    if(teamData){
      var modalFormMap=buildFormMap(groups);
      var modalForm=formPips(modalFormMap[teamName.toUpperCase()], true);
      statsHtml='<div class="modal-stat-bar">'
        +stat(teamData.pl,'Played')+stat(teamData.pts,'Points')
        +stat(teamData.w,'Won')+stat(teamData.d,'Drawn')+stat(teamData.l,'Lost')
        +stat(teamData.f,'For')+stat(teamData.a,'Against')+stat(teamData.diff,'+/-')
        +'</div>'
        +(modalForm?'<div class="modal-form-row"><span class="modal-form-lbl">Last 5:</span>'+modalForm+'</div>':'');
    }

    var fixtureRows='<table class="modal-fix-table"><thead><tr>'
      +'<th>Date</th><th>H/A</th><th>Opponent</th><th>Score</th><th>Pts</th><th></th>'
      +'</tr></thead><tbody>';
    var hasRows=false;

    groups.forEach(function(g){
      g.matches.forEach(function(m){
        var isHome=m.homeTeam.toUpperCase()===teamName.toUpperCase();
        var isAway=m.awayTeam.toUpperCase()===teamName.toUpperCase();
        if(!isHome&&!isAway) return;
        hasRows=true;
        var opponent=isHome?m.awayTeam:m.homeTeam;
        var ha=isHome?'H':'A';
        var myShots =isHome?m.shotsHome:m.shotsAway;
        var oppShots=isHome?m.shotsAway:m.shotsHome;
        var myPts   =isHome?m.ptsHome:m.ptsAway;
        var scoreStr=m.played?myShots+' - '+oppShots:'-';
        var rowCls='',resultLbl='';
        if(m.played){
          var ms=parseFloat(myShots), os=parseFloat(oppShots);
          // Shots are the primary decider; points used only when shots data absent
          if(!isNaN(ms)&&!isNaN(os)){
            if(ms>os){rowCls='res';resultLbl='W';}
            else if(ms<os){rowCls='lost';resultLbl='L';}
            else{rowCls='drew';resultLbl='D';}
          } else {
            var p=parseInt(myPts,10);
            if(p>=4){rowCls='res';resultLbl='W';}
            else if(p===3){rowCls='drew';resultLbl='D';}
            else{rowCls='lost';resultLbl='L';}
          }
        }
        var scRowId='sc-row-'+m.homeTeam.replace(/[^a-z0-9]/gi,'_')+'-'+m.awayTeam.replace(/[^a-z0-9]/gi,'_');
        var scAttrs=m.played
          ? ' data-home="'+m.homeTeam.replace(/"/g,'&quot;')+'" data-away="'+m.awayTeam.replace(/"/g,'&quot;')+'" data-scrowid="'+scRowId+'" title="Click to view scorecard"'
          : '';
        var timePill=(!m.played&&m.timeNote)?'<span class="modal-fx-time">&#9200; '+m.timeNote+'</span>':'';
        fixtureRows+='<tr class="modal-fx-row'+(rowCls?' '+rowCls:'')+'"'+scAttrs+'>'
          +'<td><div class="modal-fx-date">'+g.date+timePill+'</div></td>'
          +'<td style="text-align:center;font-weight:700;color:'+(isHome?'#1a2e5a':'#c0202a')+'">'+ha+'</td>'
          +'<td>'+badgeImg(opponent)+opponent+'</td>'
          +'<td style="text-align:center">'+scoreStr+'</td>'
          +'<td style="text-align:center;font-weight:700">'+(m.played?myPts:'')+'</td>'
          +'<td style="text-align:center">'+(rowCls?'<span class="modal-result-lbl">'+resultLbl+'</span>':'')
          +(m.played?' <span class="modal-sc-hint" title="View scorecard">&#x1F4CB;</span>':'')+'</td>'
          +'</tr>'
          +(m.played?'<tr class="modal-sc-row" id="'+scRowId+'" style="display:none"><td colspan="6"><div class="modal-sc-inline"></div></td></tr>':'');
      });
    });

    if(!hasRows) fixtureRows+='<tr><td colspan="6" style="text-align:center;color:#999">No fixtures found</td></tr>';
    fixtureRows+='</tbody></table>';
    openModal(titleHtml,statsHtml+fixtureRows,sourceWidget);

    // Bind form pip scorecard click handlers
    if(modalEl){
      modalEl.querySelectorAll('.lgw-pip-link').forEach(function(pip){
        pip.addEventListener('click',function(e){
          e.stopPropagation();
          var h=pip.getAttribute('data-sc-home');
          var a=pip.getAttribute('data-sc-away');
          var d=pip.getAttribute('data-sc-date');
          if(h&&a&&d) showFixtureModal(h,a,d);
        });
      });
    }

    // Bind scorecard click handlers on played rows in the team modal
    if(modalEl){
      modalEl.querySelectorAll('.modal-fx-row[data-home]').forEach(function(row){
        row.style.cursor='pointer';
        row.addEventListener('click', function(){
          var scRowId = row.getAttribute('data-scrowid');
          var scRow   = scRowId ? document.getElementById(scRowId) : null;
          if(!scRow) return;
          var isOpen  = scRow.style.display !== 'none';
          // Collapse any other open scorecard rows
          modalEl.querySelectorAll('.modal-sc-row').forEach(function(r){ r.style.display='none'; });
          if(isOpen){ return; } // toggle off if already open
          scRow.style.display='';
          var container = scRow.querySelector('.modal-sc-inline');
          if(!container) return;
          if(container.dataset.loaded){ return; } // already fetched
          if(typeof window.lgwFetchScorecard === 'function'){
            window.lgwFetchScorecard(
              row.getAttribute('data-home'),
              row.getAttribute('data-away'),
              '',
              container
            );
            container.dataset.loaded = '1';
          } else {
            container.innerHTML='<p class="lgw-sc-none">Scorecard feature not available.</p>';
          }
        });
      });
    }
  }


  // ── Build form map: last 5 results per team ───────────────────────────────
  function parseSortableDate(str){
    if(!str) return 0;
    var m=str.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
    if(m) return new Date(parseInt(m[3]),parseInt(m[2])-1,parseInt(m[1])).getTime();
    try{var p=str.split(' ')[1].split('-');return new Date(p[1]+' '+p[0]+' '+p[2]).getTime();}catch(e){}
    return 0;
  }

  function buildFormMap(groups){
    var byTeam={};
    groups.forEach(function(g){
      g.matches.forEach(function(m){
        if(!m.played) return;
        var ms=parseFloat(m.shotsHome), os=parseFloat(m.shotsAway);
        function result(isMine){
          var my=isMine?ms:os, opp=isMine?os:ms;
          if(!isNaN(my)&&!isNaN(opp)){
            if(my>opp) return 'W';
            if(my<opp) return 'L';
            return 'D';
          }
          // fallback to points
          var p=parseInt(isMine?m.ptsHome:m.ptsAway,10);
          if(p>=4) return 'W';
          if(p===3) return 'D';
          return 'L';
        }
        var pdKey=(m.homeTeam+'||'+m.awayTeam+'||'+g.date).toLowerCase();
        var effectiveDate=playedDates[pdKey]||g.date;
        function tip(isMine){
          var opp=isMine?m.awayTeam:m.homeTeam;
          var sh=isMine?m.shotsHome:m.shotsAway;
          var sa=isMine?m.shotsAway:m.shotsHome;
          return result(isMine)+' v '+opp+' ('+sh+'-'+sa+') '+effectiveDate;
        }
        function push(team,isMine){
          var k=team.toUpperCase();
          if(!byTeam[k]) byTeam[k]=[];
          byTeam[k].push({r:result(isMine),tip:tip(isMine),home:m.homeTeam,away:m.awayTeam,date:effectiveDate});
        }
        push(m.homeTeam,true);
        push(m.awayTeam,false);
      });
    });
    // Sort by effective (played) date, then keep only last 5
    var out={};
    Object.keys(byTeam).forEach(function(k){
      var arr=byTeam[k];
      arr.sort(function(a,b){return parseSortableDate(a.date)-parseSortableDate(b.date);});
      out[k]=arr.slice(Math.max(0,arr.length-5));
    });
    return out;
  }

  function formPips(formArr, clickable){
    if(!formArr||!formArr.length) return '';
    var html='<span class="lgw-form">';
    formArr.forEach(function(f){
      var cls=f.r==='W'?'lgw-pip-w':f.r==='L'?'lgw-pip-l':'lgw-pip-d';
      var tip=f.tip.replace(/"/g,'&quot;');
      if(clickable&&f.home&&f.away){
        html+='<span class="lgw-form-pip '+cls+' lgw-pip-link"'
          +' data-tip="'+tip+'"'
          +' data-sc-home="'+f.home.replace(/"/g,'&quot;')+'"'
          +' data-sc-away="'+f.away.replace(/"/g,'&quot;')+'"'
          +' data-sc-date="'+f.date.replace(/"/g,'&quot;')+'">'+f.r+'</span>';
      } else {
        html+='<span class="lgw-form-pip '+cls+'" data-tip="'+tip+'">'+f.r+'</span>';
      }
    });
    html+='</span>';
    return html;
  }

  // ── Render table ──────────────────────────────────────────────────────────────
  function renderTable(rows, promote, relegate, parseFn, maxPtsOverride){
    parseFn = parseFn || parseFixtureGroups;
    promote=promote||0; relegate=relegate||0;
    var teams=parseTableRows(rows);
    if(!teams.length) return '<div class="lgw-status">Could not find league table in data.</div>';

    // Apply concession adjustments before sorting
    var tableMaxPts = maxPtsOverride || 7;
    if(Object.keys(concessions).length) teams = applyConcessionsToTable(teams, tableMaxPts);

    teams.sort(function(a,b){
      var pd=parseFloat(b.pts)-parseFloat(a.pts); if(pd!==0) return pd;
      return parseFloat(b.diff)-parseFloat(a.diff);
    });
    teams.forEach(function(t,i){t.pos=i+1;});

    var total=teams.length, MAX_PTS=7;
    var gamesLeft={};
    teams.forEach(function(t){gamesLeft[t.team.toUpperCase()]=0;});
    parseFn(rows).forEach(function(g){
      g.matches.forEach(function(m){
        if(!m.played){
          if(m.homeTeam.toUpperCase() in gamesLeft) gamesLeft[m.homeTeam.toUpperCase()]++;
          if(m.awayTeam.toUpperCase() in gamesLeft) gamesLeft[m.awayTeam.toUpperCase()]++;
        }
      });
    });

    function getZone(idx){
      if(promote>0 && idx<promote)          return 'promote';
      if(relegate>0 && idx>=total-relegate) return 'relegate';
      return '';
    }
    function isClinched(idx){
      var zone=getZone(idx); if(!zone) return false;
      var myPts=teams[idx].pts;
      if(zone==='promote'){
        var ch=teams[promote]; if(!ch) return true;
        return (myPts-ch.pts)>((gamesLeft[ch.team.toUpperCase()]||0)*MAX_PTS);
      }
      var safe=teams[total-relegate-1]; if(!safe) return true;
      return (safe.pts-myPts)>((gamesLeft[teams[idx].team.toUpperCase()]||0)*MAX_PTS);
    }

    var formMap=buildFormMap(parseFn(rows));
    var h='<div class="tbl-wrap"><table class="lg"><thead><tr>'
      +'<th class="cp">Pos</th><th class="ct">Team</th>'
      +'<th>Pl</th><th>Pts</th><th>+/-</th><th>W</th><th>L</th><th>D</th><th>For</th><th>Agn</th>'
      +'<th class="cf">Form</th>'
      +'</tr></thead><tbody>';

    teams.forEach(function(t,idx){
      var zone=getZone(idx),clinched=isClinched(idx);
      var bt='';
      if(promote>0  && idx===promote)        bt=' zone-border-top';
      if(relegate>0 && idx===total-relegate) bt=' zone-border-top';
      var rc='';
      if(zone==='promote')  rc=clinched?' row-promoted':' row-promote-zone';
      if(zone==='relegate') rc=clinched?' row-relegated':' row-relegate-zone';
      rc+=bt;
      h+='<tr class="'+(rc.trim())+' lgw-team-row" data-team="'+t.team+'">'
        +'<td class="cp">'+t.pos+'</td>'
        +'<td class="ct"><span class="lgw-team-link">'+badgeImg(t.team)+t.team+'</span></td>'
        +'<td>'+t.pl+'</td><td class="ck">'+t.pts+'</td><td>'+t.diff+'</td>'
        +'<td>'+t.w+'</td><td>'+t.l+'</td><td>'+t.d+'</td>'
        +'<td>'+t.f+'</td><td>'+t.a+'</td>'
        +'<td class="cf">'+formPips(formMap[t.team.toUpperCase()])+'</td></tr>';
    });

    h+='</tbody></table></div>';
    if(promote>0||relegate>0){
      h+='<div class="lg-legend">';
      if(promote>0)  h+='<span class="lg-key lg-key-promote"></span>▲ Promotion&nbsp;&nbsp;';
      if(relegate>0) h+='<span class="lg-key lg-key-relegate"></span>▼ Relegation';
      h+='</div>';
    }
    return h;
  }

  // ── Render fixtures ───────────────────────────────────────────────────────────
  function parseDate(str){
    try{var p=str.split(' ')[1].split('-');return new Date(p[1]+' '+p[0]+' '+p[2]);}catch(e){return null;}
  }

  function renderFixtures(rows, filter, parseFn){
    parseFn = parseFn || parseFixtureGroups;
    var groups=parseFn(rows);
    var now=new Date(), filtered=groups;
    if(filter==='results'){
      filtered=groups.map(function(g){
        return{date:g.date,matches:g.matches.filter(function(m){return m.played;})};
      }).filter(function(g){return g.matches.length;}).reverse();
    } else if(filter==='upcoming'){
      filtered=groups.map(function(g){
        var d=parseDate(g.date);
        return{date:g.date,matches:g.matches.filter(function(m){
          if(m.played) return false;
          var isBye=/^bye$/i.test(m.homeTeam)||/^bye$/i.test(m.awayTeam);
          if(isBye&&d&&d<now) return false;
          return true;
        })};
      }).filter(function(g){return g.matches.length;});
    }
    if(!filtered.length) return '<div class="lgw-status">No fixtures to display.</div>';
    var h='';
    filtered.forEach(function(g){
      h+='<div class="date-group"><div class="date-hdr"><span>'+g.date+'</span><span class="date-hdr-notes">Notes</span></div>';
      g.matches.forEach(function(m){
        var pc=m.played?' played':'';
        var fxAttrs=m.played
          ?' data-home="'+m.homeTeam.replace(/"/g,"&quot;")+'" data-away="'+m.awayTeam.replace(/"/g,"&quot;")+'" data-date="'+g.date.replace(/"/g,"&quot;")+'" title="Click to view full scorecard"'
          :' data-home="'+m.homeTeam.replace(/"/g,"&quot;")+'" data-away="'+m.awayTeam.replace(/"/g,"&quot;")+'" data-date="'+g.date.replace(/"/g,"&quot;")+'"';
        // Build fixture key for all annotation lookups
        var pdKey=(m.homeTeam+'||'+m.awayTeam+'||'+g.date).toLowerCase();
        // Played on a different date pill
        var playedOn=playedDates[pdKey]||'';
        var playedPill=playedOn?'<span class="fx-played-pill">&#x1F4C5; Played '+playedOn+'</span>':'';
        // Scorecard submission status pill
        // scRaw may be 'confirmed', 'disputed', or 'pending:home'/'pending:away'/'pending:both'
        var scRaw=scorecardStatus[pdKey]||'';
        var scStatus=scRaw.indexOf(':')!==-1?scRaw.split(':')[0]:scRaw;
        var scSide=scRaw.indexOf(':')!==-1?scRaw.split(':')[1]:'';
        var scPendingLabel='Pending';
        if(scStatus==='pending'&&scSide&&scSide!=='both'){
          // Show which team is still awaited (the OTHER side from who submitted)
          var submittedTeam=scSide==='home'?m.homeTeam:m.awayTeam;
          var awaitingTeam=scSide==='home'?m.awayTeam:m.homeTeam;
          scPendingLabel='Pending ('+awaitingTeam+')';
        }
        var scIcon=scStatus==='confirmed'?'&#x2705;':scStatus==='disputed'?'&#x26A0;&#xFE0F;':'&#x1F4CB;';
        var scLabel=scStatus==='pending'?scPendingLabel:scStatus.charAt(0).toUpperCase()+scStatus.slice(1);
        var scPill=scStatus?'<span class="fx-sc-status fx-sc-'+scStatus+'">'+scIcon+' '+scLabel+'</span>':'';
        // Postponed pill
        // All annotation pills
        var postEntry=postponements[pdKey]||null;
        // Notes column: postponed splits into two stacked pills
        var postponedNotePills=postEntry
          ?'<span class="fx-postponed-pill">&#x1F6AB; Postponed</span>'
           +(postEntry.rescheduled_for?'<span class="fx-reschedule-pill">&#x1F4C5; Rescheduled '+postEntry.rescheduled_for+'</span>':'')
          :'';
        // Mobile: single combined pill
        var postponedPill=postEntry
          ?'<span class="fx-postponed-pill">&#x1F6AB; Postponed'+(postEntry.rescheduled_for?' &bull; &#x1F4C5; Rescheduled '+postEntry.rescheduled_for:'')+'</span>'
          :'';
        // Concession pill
        var concessionEntry=concessions[pdKey]||null;
        var concessionPill='';
        if(concessionEntry){
          var concedingTeam=concessionEntry.conceding_team==='home'?m.homeTeam:m.awayTeam;
          concessionPill='<span class="fx-conceded-pill">&#x1F3F3;&#xFE0F; Conceded ('+concedingTeam+')</span>';
        }
        // Null & void pill
        var nullVoidPill=nullVoids[pdKey]?'<span class="fx-null-void-pill">&#x274C; Null &amp; Void</span>':'';
        // Notes column (widescreen): all pills including postponed (split)
        var notesInner=postponedNotePills+concessionPill+nullVoidPill+playedPill+scPill;
        var fxNotes='<div class="fx-notes">'+notesInner+'</div>';
        // Mobile pills row: all pills below the row
        var fxPills=(postponedPill||concessionPill||nullVoidPill||playedPill||scPill)?'<div class="fx-pills">'+postponedPill+concessionPill+nullVoidPill+playedPill+scPill+'</div>':'';
        h+='<div class="fx-row'+pc+'"'+fxAttrs+'>'
          +'<div class="fx-ph">'+(m.played?m.ptsHome:'')+'</div>'
          +'<div class="fx-h"><span class="lgw-team-link" data-team="'+m.homeTeam+'">'+badgeImg(m.homeTeam)+m.homeTeam+'</span></div>'
          +'<div class="fx-sc"><span class="fx-sb">'+m.shotsHome+'</span><span class="fx-sep">v</span><span class="fx-sb">'+m.shotsAway+'</span></div>'
          +'<div class="fx-a"><span class="lgw-team-link" data-team="'+m.awayTeam+'">'+badgeImg(m.awayTeam)+m.awayTeam+'</span></div>'
          +'<div class="fx-pa">'+(m.played?m.ptsAway:'')+'</div>'
          +fxNotes
          +(m.timeNote?'<div class="fx-time"><span>&#9200; '+m.timeNote+'</span></div>':'')
          +fxPills
          +'</div>';
      });
      h+='</div>';
    });
    return h;
  }

  // ── Results ticker: horizontal scrolling strip of latest results ─────────────────────
  // Filters to results for a specific division (current season only — PHP already
  // constrains recentResults to the active season before passing to JS).
  function renderResultsTicker(divisionName) {
    if (!recentResults || !recentResults.length) return '';
    // Filter to the current division only (case-insensitive trim match)
    var divNorm = divisionName ? divisionName.trim().toLowerCase() : '';
    var filtered = divNorm
      ? recentResults.filter(function(r) {
          return r.division && r.division.trim().toLowerCase() === divNorm;
        })
      : recentResults;
    if (!filtered.length) return '';
    var items = filtered.map(function(r) {
      var ht  = (r.home_total  !== null && r.home_total  !== undefined) ? r.home_total  : '?';
      var at  = (r.away_total  !== null && r.away_total  !== undefined) ? r.away_total  : '?';
      var pts = (r.home_points !== null && r.home_points !== undefined &&
                 r.away_points !== null && r.away_points !== undefined)
        ? '<span class="lgw-ticker-pts">(' + r.home_points + '–' + r.away_points + ' pts)</span>'
        : '';
      var dt  = r.date ? '<span class="lgw-ticker-date">' + r.date + '</span>' : '';
      return '<span class="lgw-ticker-item">'
        + badgeImg(r.home_team, 'lgw-ticker-badge') + r.home_team
        + '<strong class="lgw-ticker-score"> ' + ht + ' – ' + at + ' </strong>'
        + badgeImg(r.away_team, 'lgw-ticker-badge') + r.away_team
        + pts + dt
        + '</span>';
    });
    // Duplicate content so CSS animation loops seamlessly
    var inner = items.join('<span class="lgw-ticker-sep">●</span>');
    inner = inner + '<span class="lgw-ticker-sep"> </span>' + inner;
    return '<div class="lgw-results-ticker" aria-label="Latest results" role="marquee">'
      + '<div class="lgw-ticker-label">Latest Results</div>'
      + '<div class="lgw-ticker-track">'
      + '<div class="lgw-ticker-scroll">' + inner + '</div>'
      + '</div>'
      + '</div>';
  }

  function filterBar(af){
    var labels = { all: 'All', results: 'Latest Results', upcoming: 'Upcoming' };
    var h='<div class="fix-filter">';
    ['all','results','upcoming'].forEach(function(f){
      h+='<button data-f="'+f+'"'+(af===f?' class="active"':'')+'>'+labels[f]+'</button>';
    });
    return h+'</div>';
  }

  // ── Init widget ─────────────────────────────────────────────────────────────────
  function initWidget(widget){
    // -- Results ticker -- injected per widget, inside the wrap, below sponsor/title,
    //    above the lgw-w element. Filtered to this division's results only.
    var divisionName = widget.getAttribute('data-division') || '';
    var tickerHtml = renderResultsTicker(divisionName);
    if (tickerHtml) {
      var wrap = widget.closest('.lgw-widget-wrap') || widget.parentElement;
      if (wrap) {
        var tickerEl = document.createElement('div');
        tickerEl.innerHTML = tickerHtml;
        var ticker = tickerEl.firstChild;
        // Insert inside the wrap, immediately before the lgw-w widget div
        wrap.insertBefore(ticker, widget);
        // Set animation duration based on content width so speed is constant (px/s)
        var scrollEl = ticker.querySelector('.lgw-ticker-scroll');
        if (scrollEl) {
          requestAnimationFrame(function() {
            var contentWidth = scrollEl.scrollWidth / 2; // content is duplicated, so half = one copy
            var duration = Math.max(8, contentWidth / 80); // 80px/s constant speed
            scrollEl.style.animationDuration = duration.toFixed(1) + 's';
          });
        }
      }
    }
    var csvUrl   =widget.getAttribute('data-csv');
    var promote  =parseInt(widget.getAttribute('data-promote')  ||'0',10);
    var relegate =parseInt(widget.getAttribute('data-relegate') ||'0',10);
    var maxPts   =parseInt(widget.getAttribute('data-maxpts')   ||'7',10);
    var extraSponsors=[];
    try{extraSponsors=JSON.parse(widget.getAttribute('data-sponsors')||'[]');}catch(e){}

    // Season switcher — seasons data encoded on data-seasons attribute
    var seasonsData=[];
    try{
      var raw=widget.getAttribute('data-seasons');
      if(raw) seasonsData=JSON.parse(raw);
    }catch(e){}
    // activeCsvUrl tracks which CSV is currently loaded (may change on season switch)
    var activeCsvUrl=csvUrl;
    // currentSeasonEntry: the entry in seasonsData whose active:true flag is set (= live season)
    var currentSeasonEntry=null;
    for(var si=0;si<seasonsData.length;si++){
      if(seasonsData[si].active){currentSeasonEntry=seasonsData[si];break;}
    }
    var selectedSeasonId=currentSeasonEntry?currentSeasonEntry.id:'';

    // Read division title from data-division attr (set by PHP from shortcode title).
    // Previously used previousElementSibling but that broke when the ticker was
    // injected between the .lgw-title element and the widget.
    var divisionTitle = widget.getAttribute('data-division') || '';

    // Dark mode toggle — cycles auto→dark→light→auto, stored on :root
    var dmBtn=document.createElement('button');
    dmBtn.className='lgw-darkmode-btn';
    updateDMBtn(dmBtn,getDarkPref());
    dmBtn.addEventListener('click',function(){
      var cur=getDarkPref();
      var next=cur==='auto'?'dark':cur==='dark'?'light':'auto';
      try{localStorage.setItem(DM_KEY,next);}catch(e){}
      applyThemeToRoot(next);
      updateDMBtn(dmBtn,next);
    });
    var tabBar=widget.querySelector('.lgw-tabs');
    if(tabBar) tabBar.appendChild(dmBtn);

    // ── Admin view toggle ────────────────────────────────────────────────────
    // Lets admins preview the widget as a regular visitor (no submit controls)
    var viewAsAdmin = true; // starts in admin mode for admins
    if(isAdmin && tabBar){
      var viewToggleBtn = document.createElement('button');
      viewToggleBtn.className = 'lgw-darkmode-btn lgw-view-toggle-btn';
      viewToggleBtn.title = 'Switch to visitor view (hide admin controls)';
      viewToggleBtn.textContent = '\uD83D\uDC41 Admin view';
      viewToggleBtn.addEventListener('click', function(){
        viewAsAdmin = !viewAsAdmin;
        if(viewAsAdmin){
          widget.classList.remove('lgw-visitor-preview');
          viewToggleBtn.textContent = '\uD83D\uDC41 Admin view';
          viewToggleBtn.title = 'Switch to visitor view (hide admin controls)';
        } else {
          widget.classList.add('lgw-visitor-preview');
          viewToggleBtn.textContent = '\uD83D\uDC41 Visitor view';
          viewToggleBtn.title = 'Switch back to admin view';
        }
      });
      tabBar.appendChild(viewToggleBtn);
    }

    function sponsorBar(){
      if(!extraSponsors.length) return '';
      var sp=extraSponsors[Math.floor(Math.random()*extraSponsors.length)];
      if(!sp||!sp.image) return '';
      var img='<img src="'+sp.image+'" alt="'+(sp.name||'Sponsor')+'" class="lgw-sponsor-img">';
      var inner=sp.url?'<a href="'+sp.url+'" target="_blank" rel="noopener">'+img+'</a>':img;
      return '<div class="lgw-sponsor-bar lgw-sponsor-secondary">'+inner+'</div>';
    }

    var activeFilter='all', allRows=null, cachedTeams=null;
    var filterStoreKey = 'lgw_filter_' + (divisionTitle || widget.id || 'default');
    try { var storedFilter = sessionStorage.getItem(filterStoreKey); if(storedFilter) activeFilter = storedFilter; } catch(e){}

    // Wrap parseFixtureGroups to apply concessions and score overrides
    var parseFxGroups = function(rows){
      var groups = parseFixtureGroups(rows);
      if(Object.keys(concessions).length) groups = applyConcessionsToFixtures(groups, maxPts);
      if(Object.keys(nullVoids).length)   groups = applyNullVoidsToFixtures(groups);
      return groups;
    };
    var panels=widget.querySelectorAll('.lgw-panel');
    var tabs=widget.querySelectorAll('.lgw-tab');

    var tabStoreKey = 'lgw_tab_' + (divisionTitle || widget.id || 'default');

    function activateTab(name){
      tabs.forEach(function(t){ t.classList.toggle('active', t.getAttribute('data-tab') === name); });
      panels.forEach(function(p){ p.classList.toggle('active', p.getAttribute('data-panel') === name); });
    }

    // Restore saved tab on load
    try { var savedTab = sessionStorage.getItem(tabStoreKey); if(savedTab) activateTab(savedTab); } catch(e){}

    tabs.forEach(function(tab){
      tab.addEventListener('click',function(){
        var name = tab.getAttribute('data-tab');
        activateTab(name);
        try { sessionStorage.setItem(tabStoreKey, name); } catch(e){}
        if(name==='progress') initProgressTab();
      });
    });

    function getPanel(name){
      for(var i=0;i<panels.length;i++){
        if(panels[i].getAttribute('data-panel')===name) return panels[i];
      }
      return null;
    }

    // ── Season Progress chart ────────────────────────────────────────────────
    var progressLoaded=false;
    var progressChartInst=null;
    var progressMode='pts';
    var progressSeriesData=null;
    var progressAnimRaf=null;
    var progressAnimPausedT=0;
    var progressClipX=null;
    var PROGRESS_COLORS=['#1a2e5a','#c0202a','#2a7a2a','#e8b400','#6a4c93','#17a2b8','#ff6b35','#2d6a4f','#9b2335','#4a4e69'];
var _progressColorIdx={};
var progressTeamColorMap={};
function buildTeamColorMap(teams){
  var clubColors=lgwData.clubColors||{};
  var map={};
  var clubGroups={};
  teams.forEach(function(team){
    var matched=null;
    var names=Object.keys(clubColors);
    for(var n=0;n<names.length;n++){
      if(team.toLowerCase().indexOf(names[n].toLowerCase())===0){matched=names[n];break;}
    }
    var key=matched||'\x00palette';
    if(!clubGroups[key])clubGroups[key]=[];
    clubGroups[key].push(team);
  });
  Object.keys(clubGroups).forEach(function(cname){
    var group=clubGroups[cname].slice().sort();
    if(cname==='\x00palette'){
      group.forEach(function(team){
        if(!(_progressColorIdx[team]>=0))_progressColorIdx[team]=Object.keys(_progressColorIdx).length;
        map[team]=PROGRESS_COLORS[_progressColorIdx[team]%PROGRESS_COLORS.length];
      });
    } else {
      var colors=clubColors[cname];
      if(!Array.isArray(colors))colors=colors?[colors]:[];
      group.forEach(function(team,idx){
        map[team]=colors[idx]||colors[0]||PROGRESS_COLORS[0];
      });
    }
  });
  return map;
}
function getTeamColor(team){
  if(progressTeamColorMap[team])return progressTeamColorMap[team];
  var colors=lgwData.clubColors||{};
  var names=Object.keys(colors);
  for(var n=0;n<names.length;n++){
    if(team.toLowerCase().indexOf(names[n].toLowerCase())===0){
      var c=colors[names[n]];
      return Array.isArray(c)?c[0]:c;
    }
  }
  if(!(_progressColorIdx[team]>=0))_progressColorIdx[team]=Object.keys(_progressColorIdx).length;
  return PROGRESS_COLORS[_progressColorIdx[team]%PROGRESS_COLORS.length];
}

    function loadChartJs(cb){
      if(window.Chart){cb();return;}
      var s=document.createElement('script');
      s.src='https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js';
      s.onload=function(){cb();};
      s.onerror=function(){cb(new Error('load'));};
      document.head.appendChild(s);
    }

    function fmtProgressDate(str){
      var m=str.match(/(\d{1,2})-([A-Za-z]{3})-(\d{4})/);
      return m?m[1]+' '+m[2]:str;
    }

    var progressBadgeCache={};

    function badgeUrl(team){
      if(badges[team]) return badges[team];
      var upper=team.toUpperCase();
      for(var k in badges){ if(k.toUpperCase()===upper) return badges[k]; }
      var bestKey='',bestUrl='';
      for(var club in clubBadges){
        var cu=club.toUpperCase();
        if(upper===cu||upper.indexOf(cu)===0){
          var rest=team.slice(club.length);
          if(rest===''||rest[0]===' '){
            if(club.length>bestKey.length){bestKey=club;bestUrl=clubBadges[club];}
          }
        }
      }
      return bestUrl||'';
    }

    function teamInitials(name){
      var parts=name.trim().split(/\s+/);
      if(parts.length===1) return name.substring(0,3).toUpperCase();
      if(parts.length===2) return (parts[0][0]+parts[1][0]).toUpperCase();
      return (parts[0][0]+parts[parts.length-1][0]).toUpperCase();
    }

    function preloadProgressBadges(teams,cb){
      var rem=teams.length;
      if(!rem){cb();return;}
      teams.forEach(function(team){
        var url=badgeUrl(team);
        var isSvg=url&&/\.svg(\?|$)/i.test(url);
        var entry={img:null,initials:teamInitials(team),color:getTeamColor(team)};
        progressBadgeCache[team]=entry;
        if(url&&isSvg){
          var img=new Image();
          img.onload=function(){entry.img=img;if(--rem===0)cb();};
          img.onerror=function(){if(--rem===0)cb();};
          img.src=url;
        } else {
          if(--rem===0)cb();
        }
      });
    }

    // Clips dataset drawing to the left of progressClipX during animation
    var lgwClipPlugin={
      id:'lgwClip',
      beforeDatasetsDraw:function(chart){
        if(progressClipX===null) return;
        var ctx=chart.ctx,a=chart.chartArea;
        ctx.save();
        ctx.beginPath();
        ctx.rect(a.left,a.top-20,Math.max(0,progressClipX-a.left),a.height+40);
        ctx.clip();
      },
      afterDatasetsDraw:function(chart){
        if(progressClipX===null) return;
        chart.ctx.restore();
      }
    };

    var lgwProgressBadgePlugin={
      id:'lgwBadge',
      afterDraw:function(chart){
        var ctx=chart.ctx;
        var R=11;
        chart.data.datasets.forEach(function(ds,i){
          var meta=chart.getDatasetMeta(i);
          if(meta.hidden) return;
          var firstIdx=-1,lastIdx=-1;
          ds.data.forEach(function(v,j){
            if(v!==null&&v!==undefined){if(firstIdx<0)firstIdx=j;lastIdx=j;}
          });
          if(firstIdx<0) return;
          var entry=progressBadgeCache[ds.label]||{img:null,initials:teamInitials(ds.label),color:ds.borderColor};
          var indices=firstIdx===lastIdx?[lastIdx]:[firstIdx,lastIdx];
          indices.forEach(function(idx){
            var pt=meta.data[idx];
            if(!pt) return;
            var x=pt.x,y=pt.y;
            if(progressClipX!==null&&x>progressClipX+2) return; // hide badges ahead of clip
            ctx.save();
            // White outline ring
            ctx.beginPath();ctx.arc(x,y,R+2,0,Math.PI*2);
            ctx.fillStyle='#fff';ctx.fill();
            if(entry.img){
              ctx.beginPath();ctx.arc(x,y,R,0,Math.PI*2);ctx.clip();
              ctx.drawImage(entry.img,x-R,y-R,R*2,R*2);
            } else {
              ctx.beginPath();ctx.arc(x,y,R,0,Math.PI*2);
              ctx.fillStyle=entry.color;ctx.fill();
              ctx.fillStyle='#fff';
              ctx.font='bold 7px system-ui,sans-serif';
              ctx.textAlign='center';ctx.textBaseline='middle';
              ctx.fillText(entry.initials,x,y);
            }
            ctx.restore();
          });
        });
      }
    };

    function buildProgressChart(data,mode){
      progressClipX=null;
      if(progressAnimRaf){cancelAnimationFrame(progressAnimRaf);progressAnimRaf=null;}
      var pp=getPanel('progress');
      if(!pp) return;
      var canvas=pp.querySelector('canvas');
      if(!canvas) return;
      var isPos=(mode==='pos');
      var teams=Object.keys(data.series);
      var labels=data.dates.map(fmtProgressDate);
      var datasets=teams.map(function(team,i){
        var color=getTeamColor(team);
        return{
          label:team,
          data:data.series[team][mode],
          borderColor:color,
          backgroundColor:color+'33',
          borderWidth:2,
          pointRadius:4,
          pointHoverRadius:7,
          tension:0.15,
          spanGaps:false
        };
      });
      if(progressChartInst) progressChartInst.destroy();
      progressChartInst=new Chart(canvas,{
        type:'line',
        data:{labels:labels,datasets:datasets},
        plugins:[lgwClipPlugin,lgwProgressBadgePlugin],
        options:{
          responsive:true,
          maintainAspectRatio:false,
          layout:{padding:{top:16,left:14,right:14,bottom:0}},
          animation:{duration:500,easing:'easeInOutQuart'},
          interaction:{mode:'index',intersect:false},
          plugins:{
            legend:{position:'bottom',labels:{font:{size:11},padding:10,usePointStyle:true,pointStyleWidth:10}},
            tooltip:{
              itemSort:function(a,b){
                var av=a.raw===null||a.raw===undefined?-Infinity:a.raw;
                var bv=b.raw===null||b.raw===undefined?-Infinity:b.raw;
                return isPos?(av-bv):(bv-av);
              },
              callbacks:{label:function(item){
                var v=item.raw;
                if(v===null||v===undefined) return item.dataset.label+': —';
                return item.dataset.label+': '+(isPos?'P'+v:v+' pts');
              }}
            }
          },
          scales:{
            y:{
              reverse:isPos,
              min:isPos?1:undefined,
              grace:isPos?0.5:undefined,
              ticks:{stepSize:isPos?1:undefined,callback:function(v){return isPos?'P'+v:v;},font:{size:11}},
              grid:{color:'rgba(0,0,0,0.07)'}
            },
            x:{ticks:{font:{size:10},maxRotation:45,minRotation:30},grid:{color:'rgba(0,0,0,0.07)'}}
          }
        }
      });
    }

    function updateProgressChart(){
      if(!progressSeriesData) return;
      buildProgressChart(progressSeriesData, progressMode);
    }

    function stopProgressAnim(){
      if(progressAnimRaf){cancelAnimationFrame(progressAnimRaf);progressAnimRaf=null;}
    }

    function startProgressAnim(pp,fromT){
      stopProgressAnim();
      if(!progressSeriesData||!progressChartInst) return;
      fromT=fromT||0;
      var playBtn=pp?pp.querySelector('.lgw-progress-play'):null;
      if(playBtn) playBtn.textContent='⏸ Pause';
      var area=progressChartInst.chartArea;
      var totalDuration=Math.max(3000,progressSeriesData.dates.length*700);
      var animStart=null;
      function frame(now){
        if(!animStart) animStart=now-fromT*totalDuration;
        var t=Math.min((now-animStart)/totalDuration,1);
        progressClipX=area.left+(area.right-area.left)*t;
        progressChartInst.draw();
        if(t<1){
          progressAnimRaf=requestAnimationFrame(frame);
        } else {
          progressAnimRaf=null;
          progressClipX=null;
          progressChartInst.draw();
          if(playBtn) playBtn.textContent='↺ Replay';
        }
      }
      progressAnimRaf=requestAnimationFrame(frame);
    }

    function initProgressTab(){
      if(progressLoaded) return;
      progressLoaded=true;
      var pp=getPanel('progress');
      if(!pp) return;
      pp.innerHTML='<div class="lgw-status">Loading…</div>';
      var division=divisionTitle;
      var seasonId=(typeof lgwData!=='undefined'&&lgwData.activeSeasonId)?lgwData.activeSeasonId:'';
      var nonce=(typeof lgwData!=='undefined'&&lgwData.progressNonce)?lgwData.progressNonce:'';
      var ajaxUrl=(typeof lgwData!=='undefined'&&lgwData.ajaxUrl)?lgwData.ajaxUrl:'';
      loadChartJs(function(err){
        if(err){pp.innerHTML='<div class="lgw-status">Charting library unavailable.</div>';return;}
        var fd=new FormData();
        fd.append('action','lgw_season_progress');
        fd.append('nonce',nonce);
        fd.append('division',division);
        fd.append('season_id',seasonId);
        fetch(ajaxUrl,{method:'POST',body:fd})
          .then(function(r){return r.json();})
          .then(function(resp){
            if(!resp.success||!resp.data||!resp.data.dates.length){
              pp.innerHTML='<div class="lgw-status">No results recorded yet.</div>';
              return;
            }
            progressSeriesData=resp.data;
            progressMode='pts';
            pp.innerHTML=
              '<div class="lgw-progress-controls">'
              +'<button class="lgw-progress-toggle active" data-mode="pts">Points</button>'
              +'<button class="lgw-progress-toggle" data-mode="pos">Position</button>'
              +'<button class="lgw-progress-play">&#x25B6; Play</button>'
              +'</div>'
              +'<div class="lgw-progress-chart-wrap"><canvas></canvas></div>';
            pp.querySelectorAll('.lgw-progress-toggle').forEach(function(btn){
              btn.addEventListener('click',function(){
                pp.querySelectorAll('.lgw-progress-toggle').forEach(function(b){b.classList.remove('active');});
                btn.classList.add('active');
                progressMode=btn.getAttribute('data-mode');
                progressAnimPausedT=0;
                updateProgressChart();
                var playBtn=pp.querySelector('.lgw-progress-play');
                if(playBtn) playBtn.textContent='▶ Play';
              });
            });
            pp.querySelector('.lgw-progress-play').addEventListener('click',function(){
              var btn=this;
              if(progressAnimRaf){
                // playing — pause, store current t
                var area=progressChartInst.chartArea;
                progressAnimPausedT=area?(progressClipX-area.left)/(area.right-area.left):0;
                progressAnimPausedT=Math.max(0,Math.min(1,progressAnimPausedT));
                stopProgressAnim();
                btn.textContent='▶ Resume';
              } else if(btn.textContent.indexOf('Resume')>=0){
                startProgressAnim(pp,progressAnimPausedT);
              } else {
                // Play or Replay
                progressAnimPausedT=0;
                startProgressAnim(pp,0);
              }
            });
            var teams=Object.keys(resp.data.series);
            progressTeamColorMap=buildTeamColorMap(teams);
            preloadProgressBadges(teams,function(){
              buildProgressChart(progressSeriesData,progressMode);
            });
          })
          .catch(function(){pp.innerHTML='<div class="lgw-status">Could not load progress data.</div>';});
      });
    }

    // Auto-init if progress tab restored from session storage
    (function(){var pp=getPanel('progress');if(pp&&pp.classList.contains('active'))initProgressTab();})();

    function makePrintBtn(tabName){
      var btn=document.createElement('button');
      btn.className='lgw-print-btn';
      btn.innerHTML=PRINT_ICON+'Print';
      btn.title='Print this view';
      btn.addEventListener('click',function(){
        if(tabName==='table'){
          var tp=getPanel('table');
          openPrintWindow(divisionTitle,
            '<h2>'+divisionTitle+'</h2>'+(tp?tp.innerHTML:'')
          );
        } else {
          // Fixtures: use dedicated renderer for reliable mobile print
          var groups=parseFxGroups(allRows);
          var now=new Date();
          if(activeFilter==='results'){
            groups=groups.map(function(g){
              return{date:g.date,matches:g.matches.filter(function(m){return m.played;})};
            }).filter(function(g){return g.matches.length;});
          } else if(activeFilter==='upcoming'){
            groups=groups.map(function(g){
              var d=parseDate(g.date); if(!d) return{date:g.date,matches:[]};
              return{date:g.date,matches:g.matches.filter(function(m){return !m.played&&d>=now;})};
            }).filter(function(g){return g.matches.length;});
          }
          openPrintWindow(divisionTitle, printFixturesData(groups, divisionTitle));
        }
      });
      return btn;
    }

    function bindTeamLinks(){
      // Bind form pip clicks in the standings table (SSR-rendered pips)
      widget.querySelectorAll('.lgw-pip-link').forEach(function(pip){
        pip.addEventListener('click',function(e){
          e.stopPropagation();
          var h=pip.getAttribute('data-sc-home');
          var a=pip.getAttribute('data-sc-away');
          var d=pip.getAttribute('data-sc-date');
          if(h&&a&&d) showFixtureModal(h,a,d);
        });
      });
      widget.querySelectorAll('.lgw-team-link').forEach(function(el){
        el.addEventListener('click',function(e){
          e.stopPropagation();
          var team=el.getAttribute('data-team')||el.closest('[data-team]').getAttribute('data-team');
          if(team&&allRows) showTeamModal(team,allRows,widget,parseFxGroups,cachedTeams);
        });
      });
      // Played fixture rows — click to show scorecard
      widget.querySelectorAll('.fx-row.played[data-home]').forEach(function(row){
        row.style.cursor='pointer';
        row.addEventListener('click',function(e){
          if(e.target.classList.contains('lgw-team-link')||e.target.closest('.lgw-team-link')) return;
          showFixtureModal(
            row.getAttribute('data-home'),
            row.getAttribute('data-away'),
            row.getAttribute('data-date')
          );
        });
      });
      // Unplayed fixture rows — always bind; canSubmit re-evaluated at click time
      // so the view toggle takes effect without rebinding.
      // Postponed fixtures are always clickable (to show postponement info), even
      // when submission is disabled.
      var canShowUnplayed = submissionMode !== 'disabled' && (submissionMode !== 'admin_only' || isAdmin);
      widget.querySelectorAll('.fx-row:not(.played)[data-home]').forEach(function(row){
        var rKey = (row.getAttribute('data-home')+'||'+row.getAttribute('data-away')+'||'+row.getAttribute('data-date')).toLowerCase();
        var isPostponedRow = !!postponements[rKey];
        var canClick = canShowUnplayed || isPostponedRow || isAdmin;
        if(!canClick) return;
        row.style.cursor='pointer';
        row.title = isPostponedRow ? 'View postponement details' : 'Submit scorecard for this fixture';
        row.addEventListener('click',function(e){
          if(e.target.classList.contains('lgw-team-link')||e.target.closest('.lgw-team-link')) return;
          var effectiveAdmin = isAdmin && viewAsAdmin;
          // Allow click if postponed (show info) or if submission is available
          var rKeyNow = (row.getAttribute('data-home')+'||'+row.getAttribute('data-away')+'||'+row.getAttribute('data-date')).toLowerCase();
          if(!postponements[rKeyNow] && submissionMode === 'admin_only' && !effectiveAdmin) return;
          if(!postponements[rKeyNow] && submissionMode === 'disabled' && !effectiveAdmin) return;
          showUnplayedFixtureModal(
            row.getAttribute('data-home'),
            row.getAttribute('data-away'),
            row.getAttribute('data-date'),
            divisionTitle
          );
        });
      });
    }

    function buildPostponePanel(home, away, date, effectiveAdmin){
      var pdKey2    = (home+'||'+away+'||'+date).toLowerCase();
      var postEntry2 = postponements[pdKey2] || null;
      if(!effectiveAdmin && !postEntry2) return { html: '', key: pdKey2, entry: null };

      var html = '';
      if(effectiveAdmin){
        var isPostponed = !!postEntry2;
        var reschedVal  = postEntry2 ? (postEntry2.rescheduled_for || '') : '';
        html =
          '<div class="lgw-postpone-panel" id="lgw-postpone-panel">'          +'<div class="lgw-postpone-toggle">'          +'<label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:600;font-size:13px">'          +'<input type="checkbox" id="lgw-postpone-chk"'+(isPostponed?' checked':'')+' style="width:16px;height:16px;cursor:pointer">'          +'<span>&#x1F6AB; Mark as postponed</span>'          +'</label>'          +'</div>'          +'<div class="lgw-reschedule-row" id="lgw-reschedule-row" style="margin-top:8px;display:'+(isPostponed?'flex':'none')+';align-items:center;gap:8px">'          +'<label style="font-size:12px;color:#666;white-space:nowrap">Rescheduled for:</label>'          +'<input type="text" id="lgw-reschedule-date" placeholder="dd/mm/yyyy" value="'+reschedVal+'" style="flex:1;padding:5px 8px;border:1px solid #d0d5e8;border-radius:5px;font-size:13px">'          +'</div>'          +'<div style="display:flex;gap:8px;margin-top:8px">'          +'<button class="lgw-btn lgw-btn-primary lgw-btn-sm" id="lgw-postpone-save">Save</button>'          +(isPostponed?'<button class="lgw-btn lgw-btn-secondary lgw-btn-sm" id="lgw-postpone-clear">&#x274C; Clear postponement</button>':'')          +'</div>'          +'<p id="lgw-postpone-status" class="lgw-notice" style="display:none;margin-top:6px"></p>'          +'</div>';
      } else if(postEntry2){
        html = '<div class="lgw-postponed-notice">'          +'<span class="fx-postponed-pill" style="font-size:13px;padding:4px 14px">&#x1F6AB; This fixture has been postponed'          +(postEntry2.rescheduled_for?' &mdash; Rescheduled for <strong>'+postEntry2.rescheduled_for+'</strong>':'')+'</span>'          +'</div>';
      }
      return { html: html, key: pdKey2, entry: postEntry2 };
    }

    function showFixtureModal(home, away, date){
      var effectiveAdmin = isAdmin && viewAsAdmin; // respects view toggle
      var pp = buildPostponePanel(home, away, date, effectiveAdmin);
      var titleHtml='<h2>'+home+' v '+away+'</h2>';

      // ── Build summary stats + form for both teams ─────────────────────
      var fxStatsHtml='';
      if(allRows){
        var fxGroups=parseFxGroups(allRows);
        var fxTeams=parseTableRows(allRows);
        if(!fxTeams.length && cachedTeams) fxTeams=cachedTeams;
        var fxFormMap=buildFormMap(fxGroups);
        function fxTeamBlock(tName){
          var td=null;
          for(var i=0;i<fxTeams.length;i++){if(fxTeams[i].team.toUpperCase()===tName.toUpperCase()){td=fxTeams[i];break;}}
          var form=formPips(fxFormMap[tName.toUpperCase()]);
          var s='<div class="fx-modal-team-block">';
          s+='<div class="fx-modal-team-name">'+badgeImg(tName)+tName+'</div>';
          if(td){
            s+='<div class="fx-modal-mini-stats">';
            s+='<span class="fx-mini-stat"><span class="fx-mini-val">'+td.pl+'</span><span class="fx-mini-lbl">Pl</span></span>';
            s+='<span class="fx-mini-stat fx-mini-pts"><span class="fx-mini-val">'+td.pts+'</span><span class="fx-mini-lbl">Pts</span></span>';
            s+='<span class="fx-mini-stat"><span class="fx-mini-val fx-mini-w">'+td.w+'</span><span class="fx-mini-lbl">W</span></span>';
            s+='<span class="fx-mini-stat"><span class="fx-mini-val fx-mini-d">'+td.d+'</span><span class="fx-mini-lbl">D</span></span>';
            s+='<span class="fx-mini-stat"><span class="fx-mini-val fx-mini-l">'+td.l+'</span><span class="fx-mini-lbl">L</span></span>';
            s+='</div>';
          }
          if(form) s+='<div class="fx-modal-form-row">'+form+'</div>';
          s+='</div>';
          return s;
        }
        fxStatsHtml='<div class="fx-modal-matchup">'
          +fxTeamBlock(home)
          +'<div class="fx-modal-vs">v</div>'
          +fxTeamBlock(away)
          +'</div>';
      }

      // ── Concession clear panel (admin only, played conceded fixtures) ──
      var pdKeyFx = (home+'||'+away+'||'+date).toLowerCase();
      var ceFx    = concessions[pdKeyFx] || null;
      var concessionFxPanel = '';
      if(effectiveAdmin && ceFx){
        var concedingNameFx = ceFx.conceding_team === 'home' ? home : away;
        concessionFxPanel =
          '<div class="lgw-concede-panel" id="lgw-concede-panel">'
          +'<div class="lgw-conceded-notice" style="margin-bottom:8px">'
          +'<span class="fx-conceded-pill" style="font-size:13px;padding:4px 14px">'
          +'&#x1F3F3;&#xFE0F; Conceded by <strong>'+concedingNameFx+'</strong></span></div>'
          +'<div style="display:flex;gap:8px;margin-bottom:8px">'
          +'<button class="lgw-btn lgw-btn-secondary lgw-btn-sm" id="lgw-concede-clear">&#x274C; Clear concession</button>'
          +'</div>'
          +'<p id="lgw-concede-status" class="lgw-notice" style="display:none;margin-top:6px"></p>'
          +'</div>';
      }

      var nvFx = nullVoids[pdKeyFx] || null;
      var nullVoidFxPanel = '';
      if(effectiveAdmin){
        var isNullVoidFx = !!nvFx;
        nullVoidFxPanel =
          '<div class="lgw-null-void-panel" id="lgw-null-void-panel">'
          +'<div class="lgw-null-void-toggle">'
          +'<label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:600;font-size:13px">'
          +'<input type="checkbox" id="lgw-null-void-chk"'+(isNullVoidFx?' checked':'')+' style="width:16px;height:16px;cursor:pointer">'
          +'<span>&#x274C; Null &amp; Void</span>'
          +'</label>'
          +'</div>'
          +'<p style="font-size:12px;color:#888;margin:6px 0 8px">'+(isNullVoidFx?'Declared null &amp; void.':'Both teams receive 0 pts, 0 shots. Update the table in Google Sheets separately.')+'</p>'
          +'<div style="display:flex;gap:8px;">'
          +'<button class="lgw-btn lgw-btn-primary lgw-btn-sm" id="lgw-null-void-save">Save</button>'
          +(isNullVoidFx?'<button class="lgw-btn lgw-btn-secondary lgw-btn-sm" id="lgw-null-void-clear">Clear</button>':'')
          +'</div>'
          +'<p id="lgw-null-void-status" class="lgw-notice" style="display:none;margin-top:6px"></p>'
          +'</div>';
      } else if(nvFx){
        nullVoidFxPanel = '<div class="lgw-null-void-notice">'
          +'<span class="fx-null-void-pill" style="font-size:13px;padding:4px 14px">&#x274C; Null &amp; Void</span>'
          +'</div>';
      }

      var fxAdminPanels = effectiveAdmin
        ? '<div class="lgw-admin-panels">'+pp.html+concessionFxPanel+nullVoidFxPanel+'</div>'
        : pp.html + concessionFxPanel + nullVoidFxPanel;
      var bodyHtml=fxStatsHtml
        +'<p class="lgw-sc-date" style="font-size:12px;color:#999;margin:0 0 12px">'+date+'</p>'
        + fxAdminPanels
        +'<hr class="lgw-sc-divider">'
        +'<div class="lgw-sc-title">Full Scorecard</div>'
        +'<div id="lgw-sc-container"><p class="lgw-sc-loading">Loading…</p></div>';
      openModal(titleHtml, bodyHtml, widget);
      bindPostponePanel(home, away, date, pp.key, pp.entry);
      bindConcedePanel(home, away, date, pdKeyFx, ceFx, divisionTitle);
      bindNullVoidPanel(home, away, date, pdKeyFx, nvFx, divisionTitle);
      var container=document.getElementById('lgw-sc-container');
      if(!container) return;

      // Determine if submission can be offered
      var canSubmit = submissionMode !== 'disabled' && (submissionMode !== 'admin_only' || effectiveAdmin);

      if(typeof window.lgwFetchScorecardOrSubmit === 'function'){
        window.lgwFetchScorecardOrSubmit(home, away, date, container, {
          canSubmit: canSubmit,
          division: divisionTitle,
          maxPts: maxPts,
          isAdmin: effectiveAdmin,
          submissionMode: submissionMode,
          authClub: widgetAuthClub,
        });
      } else if(typeof window.lgwFetchScorecard === 'function'){
        window.lgwFetchScorecard(home, away, date, container);
      } else {
        container.innerHTML='<p class="lgw-sc-none">Scorecard feature not loaded.</p>';
      }
    }

    // ── Unplayed fixture modal — submission entry point ───────────────────────
    function showUnplayedFixtureModal(home, away, date, division){
      var effectiveAdmin = isAdmin && viewAsAdmin; // respects view toggle
      // Determine if submission should be offered
      var canSubmit = false;
      if(submissionMode === 'admin_only' && effectiveAdmin) canSubmit = true;
      if(submissionMode === 'open') canSubmit = true;

      var titleHtml = '<h2>'+home+' v '+away+'</h2>';

      // Check existing postponement and concession for this fixture
      var pdKey2 = (home+'||'+away+'||'+date).toLowerCase();
      var postEntry2       = postponements[pdKey2] || null;
      var concessionEntry2 = concessions[pdKey2]   || null;

      // Build concession panel (admin only)
      var concessionPanel = '';
      if(effectiveAdmin){
        var isConceded2    = !!concessionEntry2;
        var concedingSide2 = concessionEntry2 ? (concessionEntry2.conceding_team || 'away') : 'away';
        concessionPanel =
          '<div class="lgw-concede-panel" id="lgw-concede-panel">'
          +'<div class="lgw-concede-toggle">'
          +'<label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:600;font-size:13px">'
          +'<input type="checkbox" id="lgw-concede-chk"'+(isConceded2?' checked':'')+' style="width:16px;height:16px;cursor:pointer">'
          +'<span>&#x1F3F3;&#xFE0F; Mark as conceded</span>'
          +'</label>'
          +'</div>'
          +'<div class="lgw-concede-row" id="lgw-concede-row" style="margin-top:8px;display:'+(isConceded2?'flex':'none')+';align-items:center;gap:12px">'
          +'<label style="font-size:12px;color:#666;white-space:nowrap;font-weight:600">Conceding team:</label>'
          +'<label style="display:flex;align-items:center;gap:5px;font-size:13px;cursor:pointer">'
          +'<input type="radio" name="lgw-concede-side" id="lgw-concede-home" value="home"'+(concedingSide2==='home'?' checked':'')+'>'+home+'</label>'
          +'<label style="display:flex;align-items:center;gap:5px;font-size:13px;cursor:pointer">'
          +'<input type="radio" name="lgw-concede-side" id="lgw-concede-away" value="away"'+(concedingSide2==='away'?' checked':'')+'>'+away+'</label>'
          +'</div>'
          +'<p style="font-size:12px;color:#888;margin:6px 0 8px" id="lgw-concede-note">'+(isConceded2?'':'Penalty: winner receives <strong>'+maxPts+' pts</strong> and a 50–0 victory; conceding team loses <strong>'+maxPts+' pts</strong>.')+'</p>'
          +'<div style="display:flex;gap:8px;">'
          +'<button class="lgw-btn lgw-btn-primary lgw-btn-sm" id="lgw-concede-save">Save</button>'
          +(isConceded2?'<button class="lgw-btn lgw-btn-secondary lgw-btn-sm" id="lgw-concede-clear">&#x274C; Clear concession</button>':'')
          +'</div>'
          +'<p id="lgw-concede-status" class="lgw-notice" style="display:none;margin-top:6px"></p>'
          +'</div>';
      } else if(concessionEntry2){
        var concedingTeamName2 = concessionEntry2.conceding_team === 'home' ? home : away;
        concessionPanel = '<div class="lgw-conceded-notice">'
          +'<span class="fx-conceded-pill" style="font-size:13px;padding:4px 14px">&#x1F3F3;&#xFE0F; This fixture was conceded by <strong>'+concedingTeamName2+'</strong></span>'
          +'</div>';
      }

      // Build postpone panel (admin only)
      var postponePanel = '';
      if(effectiveAdmin){
        var isPostponed = !!postEntry2;
        var reschedVal  = postEntry2 ? (postEntry2.rescheduled_for || '') : '';
        postponePanel =
          '<div class="lgw-postpone-panel" id="lgw-postpone-panel">'
          +'<div class="lgw-postpone-toggle">'
          +'<label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:600;font-size:13px">'
          +'<input type="checkbox" id="lgw-postpone-chk"'+(isPostponed?' checked':'')+' style="width:16px;height:16px;cursor:pointer">'
          +'<span>&#x1F6AB; Mark as postponed</span>'
          +'</label>'
          +'</div>'
          +'<div class="lgw-reschedule-row" id="lgw-reschedule-row" style="margin-top:8px;display:'+(isPostponed?'flex':'none')+';align-items:center;gap:8px">'
          +'<label style="font-size:12px;color:#666;white-space:nowrap">Rescheduled for:</label>'
          +'<input type="text" id="lgw-reschedule-date" placeholder="dd/mm/yyyy" value="'+reschedVal+'" style="flex:1;padding:5px 8px;border:1px solid #d0d5e8;border-radius:5px;font-size:13px">'
          +'</div>'
          +'<div style="display:flex;gap:8px;margin-top:8px">'
          +'<button class="lgw-btn lgw-btn-primary lgw-btn-sm" id="lgw-postpone-save">Save</button>'
          +(isPostponed?'<button class="lgw-btn lgw-btn-secondary lgw-btn-sm" id="lgw-postpone-clear">&#x274C; Clear postponement</button>':'')
          +'</div>'
          +'<p id="lgw-postpone-status" class="lgw-notice" style="display:none;margin-top:6px"></p>'
          +'</div>';
      } else if(postEntry2){
        // Non-admin: just show a notice if postponed
        postponePanel = '<div class="lgw-postponed-notice">'
          +'<span class="fx-postponed-pill" style="font-size:13px;padding:4px 14px">&#x1F6AB; This fixture has been postponed'
          +(postEntry2.rescheduled_for?' &mdash; Rescheduled for <strong>'+postEntry2.rescheduled_for+'</strong>':'')+'</span>'
          +'</div>';
      }

      // Build null & void panel (admin only)
      var nullVoidPanel = '';
      var nvEntry2 = nullVoids[pdKey2] || null;
      if(effectiveAdmin){
        var isNullVoid2 = !!nvEntry2;
        nullVoidPanel =
          '<div class="lgw-null-void-panel" id="lgw-null-void-panel">'
          +'<div class="lgw-null-void-toggle">'
          +'<label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:600;font-size:13px">'
          +'<input type="checkbox" id="lgw-null-void-chk"'+(isNullVoid2?' checked':'')+' style="width:16px;height:16px;cursor:pointer">'
          +'<span>&#x274C; Null &amp; Void</span>'
          +'</label>'
          +'</div>'
          +'<p style="font-size:12px;color:#888;margin:6px 0 8px">'+(isNullVoid2?'Declared null &amp; void.':'Both teams receive 0 pts, 0 shots. Update the table in Google Sheets separately.')+'</p>'
          +'<div style="display:flex;gap:8px;">'
          +'<button class="lgw-btn lgw-btn-primary lgw-btn-sm" id="lgw-null-void-save">Save</button>'
          +(isNullVoid2?'<button class="lgw-btn lgw-btn-secondary lgw-btn-sm" id="lgw-null-void-clear">Clear</button>':'')
          +'</div>'
          +'<p id="lgw-null-void-status" class="lgw-notice" style="display:none;margin-top:6px"></p>'
          +'</div>';
      } else if(nvEntry2){
        nullVoidPanel = '<div class="lgw-null-void-notice">'
          +'<span class="fx-null-void-pill" style="font-size:13px;padding:4px 14px">&#x274C; This fixture has been declared Null &amp; Void</span>'
          +'</div>';
      }

      var adminPanelsHtml = effectiveAdmin
        ? '<div class="lgw-admin-panels">'+concessionPanel+postponePanel+nullVoidPanel+'</div>'
        : concessionPanel + postponePanel + nullVoidPanel;
      var bodyHtml = '<p class="lgw-sc-date" style="font-size:12px;color:#999;margin:0 0 8px">'+date+'</p>'
        + (division ? '<p style="font-size:12px;color:#999;margin:0 0 12px">'+division+'</p>' : '')
        + adminPanelsHtml
        + '<hr class="lgw-sc-divider">'
        + '<div id="lgw-sc-modal-submit"></div>';

      openModal(titleHtml, bodyHtml, widget);
      bindConcedePanel(home, away, date, pdKey2, concessionEntry2, divisionTitle);
      bindPostponePanel(home, away, date, pdKey2, postEntry2);
      bindNullVoidPanel(home, away, date, pdKey2, nvEntry2, divisionTitle);

      var container = document.getElementById('lgw-sc-modal-submit');
      if(!container) return;

      // Check for an existing pending scorecard first; fall back to submission form if none found.
      if(typeof window.lgwFetchScorecardOrSubmit === 'function'){
        window.lgwFetchScorecardOrSubmit(home, away, date, container, {
          canSubmit:      canSubmit,
          division:       division || '',
          maxPts:         maxPts,
          isAdmin:        effectiveAdmin,
          submissionMode: submissionMode,
          authClub:       widgetAuthClub,
          context:        '',
        });
      } else {
        container.innerHTML='<p class="lgw-sc-none">Scorecard submission not available.</p>';
      }
    }

    // ── Concede panel binding ─────────────────────────────────────────────────
    function bindConcedePanel(home, away, date, pdKey2, concessionEntry2, division){
      var chk       = document.getElementById('lgw-concede-chk');
      var row       = document.getElementById('lgw-concede-row');
      var saveBtn   = document.getElementById('lgw-concede-save');
      var clearBtn  = document.getElementById('lgw-concede-clear');
      var statusEl  = document.getElementById('lgw-concede-status');
      var noteEl    = document.getElementById('lgw-concede-note');

      // Already-conceded panel (no checkbox) — skip to end to bind Clear after doSave
      var noChkMode = !chk;

      if(!noChkMode) chk.addEventListener('change', function(){
        if(row) row.style.display = chk.checked ? 'flex' : 'none';
        if(noteEl) noteEl.innerHTML = chk.checked
          ? 'Penalty: winner receives <strong>'+maxPts+' pts</strong> and a 50–0 victory; conceding team loses <strong>'+maxPts+' pts</strong>.'
          : '';
      });

      function doSave(action){
        var concedingSide = 'away';
        var sideEl = document.querySelector('input[name="lgw-concede-side"]:checked');
        if(sideEl) concedingSide = sideEl.value;
        var fd = new FormData();
        fd.append('action',            'lgw_save_concession');
        fd.append('nonce',             (typeof lgwData !== 'undefined' ? lgwData.scNonce : ''));
        fd.append('home',              home);
        fd.append('away',              away);
        fd.append('date',              date);
        fd.append('division',          division || '');
        fd.append('concession_action', action);
        fd.append('conceding_team',    concedingSide);
        if(saveBtn){ saveBtn.disabled = true; saveBtn.textContent = '⏳'; }
        var xhr = new XMLHttpRequest();
        xhr.open('POST', (typeof lgwData !== 'undefined' ? lgwData.ajaxUrl : '/wp-admin/admin-ajax.php'));
        xhr.onload = function(){
          if(saveBtn){ saveBtn.disabled = false; saveBtn.textContent = 'Save'; }
          var res = JSON.parse(xhr.responseText || '{}');
          if(res.success){
            if(action === 'set'){
              concessions[pdKey2] = { conceding_team: concedingSide };
              // Swap panel to conceded-notice state
              var panel = document.getElementById('lgw-concede-panel');
              if(panel){
                var concedingTeamName = concedingSide === 'home' ? home : away;
                panel.innerHTML =
                  '<div class="lgw-conceded-notice" style="margin-bottom:8px">'
                  +'<span class="fx-conceded-pill" style="font-size:13px;padding:4px 14px">'
                  +'&#x1F3F3;&#xFE0F; Conceded by <strong>'+concedingTeamName+'</strong></span>'
                  +'</div>'
                  +'<div style="display:flex;gap:8px;margin-bottom:8px">'
                  +'<button class="lgw-btn lgw-btn-secondary lgw-btn-sm" id="lgw-concede-clear">&#x274C; Clear concession</button>'
                  +'</div>'
                  +'<p id="lgw-concede-status" class="lgw-notice" style="display:none;margin-top:6px"></p>'
                  +'<hr class="lgw-sc-divider">';
                // Re-bind the new clear button
                var newClearBtn = document.getElementById('lgw-concede-clear');
                var newStatusEl = document.getElementById('lgw-concede-status');
                if(newClearBtn) newClearBtn.addEventListener('click', function(){
                  bindConcedePanel(home, away, date, pdKey2, {conceding_team: concedingSide}, division);
                  var innerPanel = document.getElementById('lgw-concede-panel');
                  // trigger clear immediately
                  var clearXhr = new XMLHttpRequest();
                  var clearFd = new FormData();
                  clearFd.append('action','lgw_save_concession');
                  clearFd.append('nonce',(typeof lgwData!=='undefined'?lgwData.scNonce:''));
                  clearFd.append('home',home); clearFd.append('away',away); clearFd.append('date',date);
                  clearFd.append('division',division||'');
                  clearFd.append('concession_action','clear');
                  clearFd.append('conceding_team',concedingSide);
                  newClearBtn.disabled=true; newClearBtn.textContent='⏳';
                  clearXhr.open('POST',(typeof lgwData!=='undefined'?lgwData.ajaxUrl:'/wp-admin/admin-ajax.php'));
                  clearXhr.onload=function(){
                    var r=JSON.parse(clearXhr.responseText||'{}');
                    if(r.success){
                      delete concessions[pdKey2];
                      refreshConcededPill(home,away,date,pdKey2);
                      // Restore the original unchecked panel
                      if(innerPanel) innerPanel.outerHTML=
                        '<div class="lgw-concede-panel" id="lgw-concede-panel">'
                        +'<div class="lgw-concede-toggle"><label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:600;font-size:13px">'
                        +'<input type="checkbox" id="lgw-concede-chk" style="width:16px;height:16px;cursor:pointer">'
                        +'<span>&#x1F3F3;&#xFE0F; Mark as conceded</span></label></div>'
                        +'<div class="lgw-concede-row" id="lgw-concede-row" style="margin-top:8px;display:none;align-items:center;gap:12px">'
                        +'<label style="font-size:12px;color:#666;white-space:nowrap;font-weight:600">Conceding team:</label>'
                        +'<label style="display:flex;align-items:center;gap:5px;font-size:13px;cursor:pointer"><input type="radio" name="lgw-concede-side" value="home">'+home+'</label>'
                        +'<label style="display:flex;align-items:center;gap:5px;font-size:13px;cursor:pointer"><input type="radio" name="lgw-concede-side" value="away" checked>'+away+'</label>'
                        +'</div>'
                        +'<p style="font-size:12px;color:#888;margin:6px 0 8px" id="lgw-concede-note"></p>'
                        +'<div style="display:flex;gap:8px;"><button class="lgw-btn lgw-btn-primary lgw-btn-sm" id="lgw-concede-save">Save</button></div>'
                        +'<p id="lgw-concede-status" class="lgw-notice" style="display:none;margin-top:6px"></p>'
                        +'<hr class="lgw-sc-divider">';
                      bindConcedePanel(home,away,date,pdKey2,null,division);
                    }
                  };
                  clearXhr.send(clearFd);
                });
              }
            } else {
              delete concessions[pdKey2];
              // Remove the concede panel from the played fixture modal
              var clearedPanel = document.getElementById('lgw-concede-panel');
              if(clearedPanel) clearedPanel.parentNode.removeChild(clearedPanel);
            }
            refreshConcededPill(home, away, date, pdKey2);
          } else {
            showPostponeStatus(statusEl, '❌ ' + (res.data || 'Save failed'), 'error');
          }
        };
        xhr.onerror = function(){
          if(saveBtn){ saveBtn.disabled = false; saveBtn.textContent = 'Save'; }
          showPostponeStatus(statusEl, '❌ Network error', 'error');
        };
        xhr.send(fd);
      }

      // Bind buttons — clear-only in no-checkbox mode, full set in normal mode
      if(clearBtn) clearBtn.addEventListener('click', function(){ doSave('clear'); });
      if(!noChkMode){
        if(saveBtn) saveBtn.addEventListener('click', function(){ doSave('set'); });
      }
    }

    function refreshConcededPill(home, away, date, pdKey2){
      widget.querySelectorAll('.fx-row[data-home][data-away][data-date]').forEach(function(row){
        var rKey = (row.getAttribute('data-home')+'||'+row.getAttribute('data-away')+'||'+row.getAttribute('data-date')).toLowerCase();
        if(rKey !== pdKey2) return;
        var ce = concessions[pdKey2] || null;
        var concedingTeam = ce ? (ce.conceding_team === 'home' ? home : away) : '';
        var newPill = ce ? '<span class="fx-conceded-pill">&#x1F3F3;&#xFE0F; Conceded ('+concedingTeam+')</span>' : '';
        // Update notes column
        var notesDiv = row.querySelector('.fx-notes');
        if(notesDiv){
          notesDiv.querySelectorAll('.fx-conceded-pill').forEach(function(el){ el.parentNode.removeChild(el); });
          if(newPill) notesDiv.insertAdjacentHTML('afterbegin', newPill);
        }
        // Update mobile pills
        var pillsDiv = row.querySelector('.fx-pills');
        if(pillsDiv){
          pillsDiv.querySelectorAll('.fx-conceded-pill').forEach(function(el){ el.parentNode.removeChild(el); });
          if(newPill) pillsDiv.insertAdjacentHTML('afterbegin', newPill);
          pillsDiv.style.display = pillsDiv.children.length ? '' : 'none';
        } else if(newPill){
          row.insertAdjacentHTML('beforeend', '<div class="fx-pills">'+newPill+'</div>');
        }
      });
    }

    function bindPostponePanel(home, away, date, pdKey2, postEntry2){
      var chk        = document.getElementById('lgw-postpone-chk');
      var reschedRow = document.getElementById('lgw-reschedule-row');
      var saveBtn    = document.getElementById('lgw-postpone-save');
      var clearBtn   = document.getElementById('lgw-postpone-clear');
      var statusEl   = document.getElementById('lgw-postpone-status');
      if(!chk) return;

      chk.addEventListener('change', function(){
        if(reschedRow) reschedRow.style.display = chk.checked ? 'flex' : 'none';
      });

      function doSave(action){
        var reschedDate = (document.getElementById('lgw-reschedule-date')||{}).value || '';
        var fd = new FormData();
        fd.append('action',          'lgw_save_postponement');
        fd.append('nonce',           (typeof lgwData !== 'undefined' ? lgwData.scNonce : ''));
        fd.append('home',            home);
        fd.append('away',            away);
        fd.append('date',            date);
        fd.append('postpone_action', action);
        fd.append('rescheduled_for', action === 'set' ? reschedDate : '');
        if(saveBtn){ saveBtn.disabled = true; saveBtn.textContent = '⏳'; }
        var xhr = new XMLHttpRequest();
        xhr.open('POST', (typeof lgwData !== 'undefined' ? lgwData.ajaxUrl : '/wp-admin/admin-ajax.php'));
        xhr.onload = function(){
          if(saveBtn){ saveBtn.disabled = false; saveBtn.textContent = 'Save'; }
          var res = JSON.parse(xhr.responseText || '{}');
          if(res.success){
            // Update local map so pill refreshes on next render without page reload
            if(action === 'set'){
              postponements[pdKey2] = { rescheduled_for: (document.getElementById('lgw-reschedule-date')||{}).value || '' };
            } else {
              delete postponements[pdKey2];
            }
            showPostponeStatus(statusEl, action === 'set' ? '✅ Saved — fixture marked as postponed.' : '✅ Postponement cleared.', 'ok');
            // Add/remove postponed pill on the underlying fixture row immediately
            refreshPostponedPill(home, away, date, pdKey2);
          } else {
            showPostponeStatus(statusEl, '❌ ' + (res.data || 'Save failed'), 'error');
          }
        };
        xhr.onerror = function(){
          if(saveBtn){ saveBtn.disabled = false; saveBtn.textContent = 'Save'; }
          showPostponeStatus(statusEl, '❌ Network error', 'error');
        };
        xhr.send(fd);
      }

      if(saveBtn) saveBtn.addEventListener('click', function(){ doSave(chk.checked ? 'set' : 'clear'); });
      if(clearBtn) clearBtn.addEventListener('click', function(){ doSave('clear'); });
    }

    function showPostponeStatus(el, msg, type){
      if(!el) return;
      el.textContent = msg;
      el.className = 'lgw-notice lgw-notice-'+(type==='ok'?'success':'error');
      el.style.display = '';
    }

    function refreshPostponedPill(home, away, date, pdKey2){
      widget.querySelectorAll('.fx-row[data-home][data-away][data-date]').forEach(function(row){
        var rKey = (row.getAttribute('data-home')+'||'+row.getAttribute('data-away')+'||'+row.getAttribute('data-date')).toLowerCase();
        if(rKey !== pdKey2) return;
        var postEntry3 = postponements[pdKey2] || null;
        var newSpan = postEntry3
          ? '<span class="fx-postponed-pill">&#x1F6AB; Postponed'+(postEntry3.rescheduled_for?' &bull; &#x1F4C5; Rescheduled '+postEntry3.rescheduled_for:'')+'</span>'
          : '';
        // Update notes column (widescreen) — split pills
        var notesDiv = row.querySelector('.fx-notes');
        if(notesDiv){
          notesDiv.querySelectorAll('.fx-postponed-pill,.fx-reschedule-pill').forEach(function(el){ el.parentNode.removeChild(el); });
          if(postEntry3){
            var splitHtml = '<span class="fx-postponed-pill">&#x1F6AB; Postponed</span>'
              +(postEntry3.rescheduled_for?'<span class="fx-reschedule-pill">&#x1F4C5; Rescheduled '+postEntry3.rescheduled_for+'</span>':'');
            notesDiv.insertAdjacentHTML('afterbegin', splitHtml);
          }
        }
        // Update mobile pills row
        var pillsDiv = row.querySelector('.fx-pills');
        if(pillsDiv){
          var existingPostponed2 = pillsDiv.querySelector('.fx-postponed-pill');
          if(existingPostponed2) existingPostponed2.parentNode.removeChild(existingPostponed2);
          if(newSpan) pillsDiv.insertAdjacentHTML('afterbegin', newSpan);
          pillsDiv.style.display = pillsDiv.children.length ? '' : 'none';
        } else if(newSpan){
          row.insertAdjacentHTML('beforeend', '<div class="fx-pills">'+newSpan+'</div>');
        }
      });
    }

    function bindNullVoidPanel(home, away, date, pdKey2, nvEntry2, division){
      var chk      = document.getElementById('lgw-null-void-chk');
      var saveBtn  = document.getElementById('lgw-null-void-save');
      var clearBtn = document.getElementById('lgw-null-void-clear');
      var statusEl = document.getElementById('lgw-null-void-status');
      if(!chk) return;

      function doSave(action){
        var fd = new FormData();
        fd.append('action',           'lgw_save_null_void');
        fd.append('nonce',            (typeof lgwData !== 'undefined' ? lgwData.scNonce : ''));
        fd.append('home',             home);
        fd.append('away',             away);
        fd.append('date',             date);
        fd.append('division',         division || '');
        fd.append('null_void_action', action);
        if(saveBtn){ saveBtn.disabled = true; saveBtn.textContent = '⏳'; }
        var xhr = new XMLHttpRequest();
        xhr.open('POST', (typeof lgwData !== 'undefined' ? lgwData.ajaxUrl : '/wp-admin/admin-ajax.php'));
        xhr.onload = function(){
          if(saveBtn){ saveBtn.disabled = false; saveBtn.textContent = 'Save'; }
          var res = JSON.parse(xhr.responseText || '{}');
          if(res.success){
            if(action === 'set'){
              nullVoids[pdKey2] = { voided_on: new Date().toISOString().slice(0,10) };
            } else {
              delete nullVoids[pdKey2];
            }
            showPostponeStatus(statusEl, action === 'set' ? '✅ Saved — fixture marked as null & void.' : '✅ Null & void cleared.', 'ok');
            refreshNullVoidPill(home, away, date, pdKey2);
          } else {
            showPostponeStatus(statusEl, '❌ ' + (res.data || 'Save failed'), 'error');
          }
        };
        xhr.onerror = function(){
          if(saveBtn){ saveBtn.disabled = false; saveBtn.textContent = 'Save'; }
          showPostponeStatus(statusEl, '❌ Network error', 'error');
        };
        xhr.send(fd);
      }

      if(saveBtn)  saveBtn.addEventListener('click',  function(){ doSave(chk.checked ? 'set' : 'clear'); });
      if(clearBtn) clearBtn.addEventListener('click', function(){ doSave('clear'); });
    }

    function refreshNullVoidPill(home, away, date, pdKey2){
      widget.querySelectorAll('.fx-row[data-home][data-away][data-date]').forEach(function(row){
        var rKey = (row.getAttribute('data-home')+'||'+row.getAttribute('data-away')+'||'+row.getAttribute('data-date')).toLowerCase();
        if(rKey !== pdKey2) return;
        var nv = nullVoids[pdKey2] || null;
        var newPill = nv ? '<span class="fx-null-void-pill">&#x274C; Null &amp; Void</span>' : '';
        var notesDiv = row.querySelector('.fx-notes');
        if(notesDiv){
          notesDiv.querySelectorAll('.fx-null-void-pill').forEach(function(el){ el.parentNode.removeChild(el); });
          if(newPill) notesDiv.insertAdjacentHTML('afterbegin', newPill);
        }
        var pillsDiv = row.querySelector('.fx-pills');
        if(pillsDiv){
          pillsDiv.querySelectorAll('.fx-null-void-pill').forEach(function(el){ el.parentNode.removeChild(el); });
          if(newPill) pillsDiv.insertAdjacentHTML('afterbegin', newPill);
          pillsDiv.style.display = pillsDiv.children.length ? '' : 'none';
        } else if(newPill){
          row.insertAdjacentHTML('beforeend', '<div class="fx-pills">'+newPill+'</div>');
        }
      });
    }

    function bindFilterBtns(){
      // Handles both XHR-rendered and SSR-rendered filter bars — both now use
      // .fix-filter with data-f (PHP lgw_cache_render_fixtures mirrors filterBar()).
      widget.querySelectorAll('.fix-filter button').forEach(function(b){
        b.addEventListener('click',function(){
          activeFilter=b.getAttribute('data-f');
          try { sessionStorage.setItem(filterStoreKey, activeFilter); } catch(e){}
          widget.querySelectorAll('.fix-filter button').forEach(function(x){
            x.classList.toggle('active',x.getAttribute('data-f')===activeFilter);
          });
          var fp=getPanel('fixtures');
          if(!fp) return;
          if(widget.dataset.prerendered==='1'){
            // SSR path — show/hide date groups rather than re-rendering
            lgwApplyCachedFilter(fp, activeFilter);
          } else {
            fp.innerHTML=filterBar(activeFilter)+renderFixtures(allRows,activeFilter,parseFxGroups);
            bindFilterBtns(); bindTeamLinks();
          }
        });
      });
    }

    function showError(msg){
      panels.forEach(function(p){
        p.innerHTML='<div class="lgw-status lgw-status-error">⚠️ <strong>Unable to load data.</strong> <small>'+msg+'</small></div>';
      });
    }

    // ── Season switcher ───────────────────────────────────────────────────────
    if(seasonsData.length>1){
      var switcher=document.createElement('div');
      switcher.className='lgw-season-switcher';
      renderSwitcher();
      var tabBar2=widget.querySelector('.lgw-tabs');
      if(tabBar2) widget.insertBefore(switcher,tabBar2);
    }

    function renderSwitcher(){
      if(!switcher) return;
      var sel=document.createElement('select');
      sel.className='lgw-season-select';
      sel.setAttribute('aria-label','Select season');
      for(var i=0;i<seasonsData.length;i++){
        var s=seasonsData[i];
        var opt=document.createElement('option');
        opt.value=s.id;
        opt.textContent=s.label;
        opt.setAttribute('data-csv-url',s.csv_url);
        if(s.id===selectedSeasonId) opt.selected=true;
        sel.appendChild(opt);
      }
      sel.addEventListener('change',function(){
        var opt=sel.options[sel.selectedIndex];
        var sid=opt.value;
        var scsv=opt.getAttribute('data-csv-url');
        if(sid===selectedSeasonId) return;
        selectedSeasonId=sid;
        activeCsvUrl=scsv;
        var isLive=currentSeasonEntry&&(sid===currentSeasonEntry.id);
        loadSeason(scsv,isLive);
      });
      switcher.innerHTML='';
      switcher.appendChild(sel);
    }

    // ── loadSeason — fetch a CSV URL and re-render both panels ────────────────
    // isLive: true = apply score overrides + enable scorecard submission click.
    function loadSeason(url,isLive){
      panels.forEach(function(p){
        p.innerHTML='<div class="lgw-status">Loading&hellip;</div>';
      });
      activeFilter='all';
      var proxyUrl2=ajaxUrl+'?action=lgw_csv&url='+encodeURIComponent(url);
      var xhr2=new XMLHttpRequest();
      xhr2.open('GET',proxyUrl2);
      xhr2.onload=function(){
        if(xhr2.status===200&&xhr2.responseText&&xhr2.responseText.trim().length>10){
          allRows=parseCSV(xhr2.responseText);
          if(isLive){
            parseFxGroups=function(rows){ return applyScoreOverrides(parseFixtureGroups(rows), url); };
          } else {
            // Past season — no overrides, no submission
            parseFxGroups=function(rows){ return parseFixtureGroups(rows); };
          }
          var tp=getPanel('table'), fp=getPanel('fixtures');
          if(tp){
            tp.innerHTML=renderTable(allRows,promote,relegate,parseFxGroups,maxPts)+sponsorBar();
            tp.insertBefore(makePrintBtn('table'),tp.firstChild);
            if(!isLive) addArchiveBanner(tp);
          }
          if(fp){
            fp.innerHTML=filterBar(activeFilter)+renderFixtures(allRows,activeFilter,parseFxGroups);
            fp.insertBefore(makePrintBtn('fixtures'),fp.firstChild);
            if(!isLive) addArchiveBanner(fp);
          }
          bindFilterBtns();
          // For past seasons: fixture click still opens scorecard modal (historical data)
          // For live season: full scorecard + submission behaviour
          bindTeamLinks();
          if(isLive){
            bindFixtureClicks();
          } else {
            bindFixtureClicksReadOnly();
          }
        } else {
          var msg='Could not load season data — please try refreshing.';
          try{ var j=JSON.parse(xhr2.responseText); if(j&&j.error) msg=j.error; }catch(e){}
          showError(msg);
        }
      };
      xhr2.onerror=function(){ showError('Network error — please check your connection and try again.'); };
      xhr2.send();
    }

    function addArchiveBanner(panel){
      var banner=document.createElement('div');
      banner.className='lgw-archive-banner';
      banner.textContent='📁 Archived season — read only';
      panel.insertBefore(banner,panel.firstChild);
    }

    // ── Fixture row click helpers — separated so season switching can swap ────
    function bindFixtureClicks(){
      // Alias for consistency — fixture row clicks are handled inside bindTeamLinks
      bindTeamLinks();
    }

    function bindFixtureClicksReadOnly(){
      // Past seasons: same modal behaviour — scorecards are looked up by home/away/date
      bindTeamLinks();
    }

    // ── Initial data load ─────────────────────────────────────────────────────
    // If PHP pre-rendered the widget from the DB cache, skip the CSV XHR entirely.
    // The cached fixture groups are in data-cached so JS still has allRows-equivalent
    // data for team modals, print, and score overlays.
    if (widget.dataset.prerendered === '1' && widget.dataset.cached) {
      try {
        var cachedGroups = JSON.parse(widget.dataset.cached);
        allRows = lgwCachedGroupsToRows(cachedGroups);
        if(widget.dataset.teams){
          try{ cachedTeams = JSON.parse(widget.dataset.teams); }catch(e){ cachedTeams=null; }
        }
        parseFxGroups = function(rows) { return applyScoreOverrides(cachedGroups, csvUrl); };
        var tp = getPanel('table'), fp = getPanel('fixtures');
        if (tp) {
          tp.insertBefore(makePrintBtn('table'), tp.firstChild);
        }
        if (fp) {
          fp.insertBefore(makePrintBtn('fixtures'), fp.firstChild);
          lgwApplyCachedFilter(fp, activeFilter);
          // Sync button highlight to match the restored filter
          fp.querySelectorAll('.fix-filter button').forEach(function(b) {
            b.classList.toggle('active', b.getAttribute('data-f') === activeFilter);
          });
        }
        console.log('[LGW-SSR] Pre-rendered path active. submissionMode='+submissionMode+' isAdmin='+isAdmin+' fixtures='+cachedGroups.reduce(function(n,g){return n+g.matches.length;},0));
        bindFilterBtns(); bindTeamLinks(); bindFixtureClicks();
        console.log('[LGW-SSR] Rows bound: played='+widget.querySelectorAll('.fx-row.played[data-home]').length+' unplayed='+widget.querySelectorAll('.fx-row:not(.played)[data-home]').length);
      } catch(e) {
        console.log('[LGW-SSR] Error in SSR path, falling back to XHR:', e);
        widget.dataset.prerendered = '0';
        lgwInitXhr();
      }
      return;
    }
    console.log('[LGW-SSR] No pre-rendered data, using XHR path. prerendered='+widget.dataset.prerendered);
    lgwInitXhr();

    function lgwInitXhr() {
    var proxyUrl=ajaxUrl+'?action=lgw_csv&url='+encodeURIComponent(csvUrl);
    var xhr=new XMLHttpRequest();
    xhr.open('GET',proxyUrl);
    xhr.onload=function(){
      if(xhr.status===200&&xhr.responseText&&xhr.responseText.trim().length>10){
        allRows=parseCSV(xhr.responseText);
        parseFxGroups=function(rows){ return applyScoreOverrides(parseFixtureGroups(rows), csvUrl); };
        var tp=getPanel('table'), fp=getPanel('fixtures');
        if(tp){
          tp.innerHTML=renderTable(allRows,promote,relegate,parseFxGroups,maxPts)+sponsorBar();
          tp.insertBefore(makePrintBtn('table'),tp.firstChild);
        }
        if(fp){
          fp.innerHTML=filterBar(activeFilter)+renderFixtures(allRows,activeFilter,parseFxGroups);
          fp.insertBefore(makePrintBtn('fixtures'),fp.firstChild);
        }
        bindFilterBtns(); bindTeamLinks(); bindFixtureClicks();
      } else {
        var msg = 'Could not load league data — please try refreshing the page.';
        try {
          var json = JSON.parse(xhr.responseText);
          if (json && json.error) msg = json.error;
        } catch(e) {}
        showError(msg);
      }
    };
    xhr.onerror=function(){ showError('Network error — please check your connection and try again.'); };
    xhr.send();
    } // end lgwInitXhr
  }

  // ── SSR helpers ───────────────────────────────────────────────────────────────
  // Convert cached fixture groups into a safe allRows value.
  // parseTableRows() is called by showTeamModal — passing [] means no stats row
  // shows in the team modal, which is acceptable until Phase 4 adds SSR team data.
  // parseFxGroups is overridden separately to return cachedGroups directly.
  function lgwCachedGroupsToRows(groups) {
    return [];
  }

  // Apply fixture filter to server-rendered fixture rows.
  // Hides/shows .date-group elements based on filter value.
  function lgwApplyCachedFilter(fp, filter) {
    // Restore rows moved into synthetic groups back to their original groups first
    fp.querySelectorAll('.lgw-synth-group').forEach(function(g) {
      g.querySelectorAll('.fx-row').forEach(function(r) {
        if (r._lgwOriginalGroup) r._lgwOriginalGroup.appendChild(r);
      });
      g.remove();
    });

    var groups = Array.from(fp.querySelectorAll('.date-group:not(.lgw-synth-group)'));

    if (filter === 'results') {
      // Hide all original groups — we'll rebuild by actual played date
      groups.forEach(function(g) { g.style.display = 'none'; });

      // Collect every played row with its effective date
      var playedRows = [];
      groups.forEach(function(group) {
        var hdr = group.querySelector('.date-hdr span');
        var scheduledDate = hdr ? parseDate(hdr.textContent.trim()) : null;
        group.querySelectorAll('.fx-row.played').forEach(function(r) {
          r._lgwOriginalGroup = group;
          var pill = r.querySelector('.fx-played-pill');
          var effDate = pill ? parsePillDate(pill.textContent) : null;
          if (!effDate) effDate = scheduledDate || new Date(0);
          playedRows.push({ row: r, date: effDate });
        });
      });

      // Sort descending — newest played date first
      playedRows.sort(function(a, b) { return b.date - a.date; });

      // Group by effective date key
      var dateGroups = [], dateMap = {};
      playedRows.forEach(function(item) {
        var key = item.date.getFullYear() + '-' +
          String(item.date.getMonth() + 1).padStart(2, '0') + '-' +
          String(item.date.getDate()).padStart(2, '0');
        if (!dateMap[key]) {
          dateMap[key] = { date: item.date, rows: [] };
          dateGroups.push(dateMap[key]);
        }
        dateMap[key].rows.push(item.row);
      });

      // Render synthetic groups
      var DAY_NAMES = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
      var MON_NAMES = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
      dateGroups.forEach(function(dg) {
        var d    = dg.date;
        var dateStr = DAY_NAMES[d.getDay()] + ' ' +
          String(d.getDate()).padStart(2, '0') + '-' +
          MON_NAMES[d.getMonth()] + '-' + d.getFullYear();
        var div  = document.createElement('div');
        div.className = 'date-group lgw-synth-group';
        div.innerHTML = '<div class="date-hdr"><span>' + dateStr +
          '</span><span class="date-hdr-notes">Notes</span></div>';
        dg.rows.forEach(function(r) { r.style.display = ''; div.appendChild(r); });
        fp.appendChild(div);
      });

    } else {
      // All / Upcoming — restore rows to original groups and show/hide by filter
      var now = new Date();
      groups.forEach(function(group) {
        var hdr = group.querySelector('.date-hdr span');
        var groupDate = hdr ? parseDate(hdr.textContent.trim()) : null;
        var rows = Array.from(group.querySelectorAll('.fx-row'));
        var visibleCount = 0;
        rows.forEach(function(r) {
          r.style.display = '';  // ensure rows moved by results are visible
          var played = r.classList.contains('played');
          var home = r.getAttribute('data-home') || '';
          var away = r.getAttribute('data-away') || '';
          var isByePast = (/^bye$/i.test(home) || /^bye$/i.test(away)) && groupDate && groupDate < now;
          var show = filter === 'all' || (filter === 'upcoming' && !played && !isByePast);
          r.style.display = show ? '' : 'none';
          if (show) visibleCount++;
        });
        group.style.display = visibleCount ? '' : 'none';
      });
    }
  }

  function parsePillDate(text) {
    // Pill text: "📅 Played 06/06/2026"  →  dd/mm/yyyy
    var m = text.match(/(\d{2})\/(\d{2})\/(\d{4})/);
    if (!m) return null;
    return new Date(parseInt(m[3]), parseInt(m[2]) - 1, parseInt(m[1]));
  }

  function init(){
    document.querySelectorAll('.lgw-w[data-csv]').forEach(function(w){initWidget(w);});
  }
  if(document.readyState==='loading'){
    document.addEventListener('DOMContentLoaded',init);
  } else {
    init();
  }
})();

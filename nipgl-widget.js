/* NIPGL Division Widget JS - v4.5 */
(function(){
  'use strict';

  var badges = (typeof nipglData !== 'undefined' && nipglData.badges) ? nipglData.badges : {};
  var ajaxUrl = (typeof nipglData !== 'undefined') ? nipglData.ajaxUrl : '/wp-admin/admin-ajax.php';

  // ── Badge helper ─────────────────────────────────────────────────────────────
  function badgeImg(team, cls) {
    cls = cls || 'nipgl-badge';
    if (badges[team]) return '<img class="'+cls+'" src="'+badges[team]+'" alt="'+team+'">';
    var upper = team.toUpperCase();
    for (var key in badges) {
      if (key.toUpperCase() === upper) return '<img class="'+cls+'" src="'+badges[key]+'" alt="'+team+'">';
    }
    return '';
  }

  // ── CSV parser ────────────────────────────────────────────────────────────────
  function parseCSV(text){
    return text.split('\n').map(function(line){
      line = line.replace(/\r$/,'');
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

  // ── Parse fixtures into groups (reusable) ────────────────────────────────────
  function parseFixtureGroups(rows){
    var i=0;
    while(i<rows.length && rows[i].join('').indexOf('FIXTURES')===-1) i++;
    i++;
    if(i>=rows.length) return {groups:[], cols:{h:0,ht:2,hs:7,as:9,at:10,ap:15}};

    var colPtsH=0, colHTeam=2, colHScore=7, colAScore=9, colATeam=10, colPtsA=15;
    for(var h=i;h<Math.min(i+5,rows.length);h++){
      if(rows[h].join('').indexOf('HPts')!==-1){
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
        var timeNote ='';
        for(var x=colATeam+1;x<Math.min(colPtsA,r.length);x++){
          if(/^\d{1,2}:\d{2}$/.test((r[x]||'').trim())) timeNote=r[x].trim();
        }
        if(homeTeam && awayTeam){
          var played=(shotsHome!=='0'||shotsAway!=='0'||ptsHome!=='0'||ptsAway!=='0');
          cur.matches.push({
            ptsHome:ptsHome, ptsAway:ptsAway,
            homeTeam:homeTeam, awayTeam:awayTeam,
            shotsHome:shotsHome, shotsAway:shotsAway,
            timeNote:timeNote, played:played,
            date:first
          });
        }
      }
      i++;
    }
    return groups;
  }

  // ── Parse league table rows ───────────────────────────────────────────────────
  function parseTableRows(rows){
    var i=0;
    while(i<rows.length && rows[i].join('').indexOf('LEAGUE TABLE')===-1) i++;
    i++;
    while(i<rows.length && !nonEmpty(rows[i])) i++;
    while(i<rows.length && rows[i][0]!=='POS') i++;
    if(i>=rows.length) return [];
    i++;
    var teams=[];
    while(i<rows.length && nonEmpty(rows[i])){
      var r=rows[i];
      var pos=r[0], team=r[1];
      if(pos && team && !isNaN(parseInt(pos,10))){
        teams.push({
          pos:parseInt(pos,10), team:team,
          pl:parseInt(r[5]||r[2]||'0',10),
          pts:parseInt(r[7]||r[3]||'0',10),
          diff:r[8]||r[4]||'0',
          w:r[9]||'0', l:r[10]||'0', d:r[11]||'0',
          f:r[12]||'0', a:r[14]||r[13]||'0'
        });
      }
      i++;
    }
    return teams;
  }

  // ── Modal ─────────────────────────────────────────────────────────────────────
  var modalEl = null;

  function ensureModal(){
    if(modalEl) return;
    modalEl = document.createElement('div');
    modalEl.className = 'nipgl-modal-overlay';
    modalEl.innerHTML = '<div class="nipgl-modal"><div class="nipgl-modal-head">'
      +'<div class="nipgl-modal-title"></div>'
      +'<div class="nipgl-modal-actions">'
      +'<button class="nipgl-modal-print" title="Print">&#128438;</button>'
      +'<button class="nipgl-modal-close" title="Close">&times;</button>'
      +'</div></div>'
      +'<div class="nipgl-modal-body"></div>'
      +'</div>';
    document.body.appendChild(modalEl);

    // Close on overlay click
    modalEl.addEventListener('click', function(e){
      if(e.target === modalEl) closeModal();
    });
    // Close on Escape
    document.addEventListener('keydown', function(e){
      if(e.key === 'Escape') closeModal();
    });
    // Close button
    modalEl.querySelector('.nipgl-modal-close').addEventListener('click', closeModal);
    // Print button
    modalEl.querySelector('.nipgl-modal-print').addEventListener('click', function(){
      var title   = modalEl.querySelector('.nipgl-modal-title').innerHTML;
      var content = modalEl.querySelector('.nipgl-modal-body').innerHTML;
      var win = window.open('','_blank','width=800,height=600');
      win.document.write(
        '<!DOCTYPE html><html><head><title>'+title.replace(/<[^>]+>/g,'')+'</title>'
        +'<style>'
        +'body{font-family:Saira,Arial,sans-serif;padding:20px;color:#1a1a1a}'
        +'.nipgl-modal-print-header{display:flex;align-items:center;gap:12px;margin-bottom:16px;border-bottom:2px solid #1a2e5a;padding-bottom:10px}'
        +'.nipgl-modal-print-header img{height:48px;object-fit:contain}'
        +'.nipgl-modal-print-header h2{margin:0;font-size:20px;color:#1a2e5a}'
        +'.modal-stat-bar{display:flex;gap:16px;margin-bottom:16px;flex-wrap:wrap}'
        +'.modal-stat{background:#f0f2f8;border-radius:4px;padding:6px 12px;text-align:center}'
        +'.modal-stat-val{font-size:18px;font-weight:700;color:#1a2e5a}'
        +'.modal-stat-lbl{font-size:10px;color:#666;text-transform:uppercase}'
        +'table{width:100%;border-collapse:collapse;font-size:13px}'
        +'th{background:#1a2e5a;color:#fff;padding:6px 8px;text-align:left}'
        +'td{padding:6px 8px;border-bottom:1px solid #ddd}'
        +'.res{color:#2a7a2a;font-weight:700}.lost{color:#c0202a;font-weight:700}'
        +'@media print{button{display:none}}'
        +'</style></head><body>'
        +'<div class="nipgl-modal-print-header">'+title+'</div>'
        +content
        +'<script>window.onload=function(){window.print();}<\/script>'
        +'</body></html>'
      );
      win.document.close();
    });
  }

  function openModal(titleHtml, bodyHtml){
    ensureModal();
    modalEl.querySelector('.nipgl-modal-title').innerHTML = titleHtml;
    modalEl.querySelector('.nipgl-modal-body').innerHTML  = bodyHtml;
    modalEl.classList.add('active');
    document.body.classList.add('nipgl-modal-open');
  }

  function closeModal(){
    if(modalEl) modalEl.classList.remove('active');
    document.body.classList.remove('nipgl-modal-open');
  }

  // ── Build team modal content ──────────────────────────────────────────────────
  function showTeamModal(teamName, allRows){
    var teams  = parseTableRows(allRows);
    var groups = parseFixtureGroups(allRows);
    var teamData = null;
    for(var t=0;t<teams.length;t++){
      if(teams[t].team.toUpperCase()===teamName.toUpperCase()){teamData=teams[t];break;}
    }

    // Title
    var bdg = badgeImg(teamName, 'nipgl-modal-badge');
    var titleHtml = bdg + '<h2 style="margin:0;font-size:16px;color:#1a2e5a">'+teamName+'</h2>';

    // Stats bar
    var statsHtml = '';
    if(teamData){
      statsHtml = '<div class="modal-stat-bar">'
        +stat(teamData.pl,  'Played')
        +stat(teamData.pts, 'Points')
        +stat(teamData.w,   'Won')
        +stat(teamData.d,   'Drawn')
        +stat(teamData.l,   'Lost')
        +stat(teamData.f,   'For')
        +stat(teamData.a,   'Against')
        +stat(teamData.diff,'+/-')
        +'</div>';
    }

    // Fixtures for this team
    var fixtureRows = '<table class="modal-fix-table"><thead><tr>'
      +'<th>Date</th><th>H/A</th><th>Opponent</th><th>Score</th><th>Pts</th>'
      +'</tr></thead><tbody>';
    var hasRows = false;

    groups.forEach(function(g){
      g.matches.forEach(function(m){
        var isHome = m.homeTeam.toUpperCase()===teamName.toUpperCase();
        var isAway = m.awayTeam.toUpperCase()===teamName.toUpperCase();
        if(!isHome && !isAway) return;
        hasRows = true;
        var opponent = isHome ? m.awayTeam : m.homeTeam;
        var ha       = isHome ? 'H' : 'A';
        var myShots  = isHome ? m.shotsHome : m.shotsAway;
        var oppShots = isHome ? m.shotsAway : m.shotsHome;
        var myPts    = isHome ? m.ptsHome   : m.ptsAway;
        var scoreStr = m.played ? myShots+' - '+oppShots : '-';
        var resultCls= '';
        if(m.played){
          resultCls = parseInt(myPts,10)>=4 ? ' class="res"' : (parseInt(myPts,10)<=2 ? ' class="lost"' : '');
        }
        fixtureRows += '<tr'+resultCls+'>'
          +'<td>'+g.date+'</td>'
          +'<td style="text-align:center;font-weight:700;color:'+(isHome?'#1a2e5a':'#c0202a')+'">'+ha+'</td>'
          +'<td>'+badgeImg(opponent)+''+opponent+'</td>'
          +'<td style="text-align:center">'+scoreStr+'</td>'
          +'<td style="text-align:center;font-weight:700">'+(m.played?myPts:'')+'</td>'
          +'</tr>';
      });
    });

    if(!hasRows) fixtureRows += '<tr><td colspan="5" style="text-align:center;color:#999">No fixtures found</td></tr>';
    fixtureRows += '</tbody></table>';

    openModal(titleHtml, statsHtml + fixtureRows);
  }

  function stat(val, lbl){
    return '<div class="modal-stat"><div class="modal-stat-val">'+val+'</div><div class="modal-stat-lbl">'+lbl+'</div></div>';
  }

  // ── Render league table ───────────────────────────────────────────────────────
  function renderTable(rows, promote, relegate){
    promote  = promote  || 0;
    relegate = relegate || 0;

    var teams = parseTableRows(rows);
    if(!teams.length) return '<div class="nipgl-status">Could not find league table in data.</div>';

    var total  = teams.length;
    var MAX_PTS = 7;
    var gamesLeft={};
    teams.forEach(function(t){ gamesLeft[t.team.toUpperCase()]=0; });

    var groups = parseFixtureGroups(rows);
    groups.forEach(function(g){
      g.matches.forEach(function(m){
        if(!m.played){
          var ht=m.homeTeam.toUpperCase(), at=m.awayTeam.toUpperCase();
          if(ht in gamesLeft) gamesLeft[ht]++;
          if(at in gamesLeft) gamesLeft[at]++;
        }
      });
    });

    function getZone(idx){
      if(promote  > 0 && idx < promote)          return 'promote';
      if(relegate > 0 && idx >= total-relegate)   return 'relegate';
      return '';
    }

    function isClinched(idx){
      var zone=getZone(idx);
      if(!zone) return false;
      var myPts=teams[idx].pts;
      if(zone==='promote'){
        var challenger=teams[promote];
        if(!challenger) return true;
        return (myPts-challenger.pts) > ((gamesLeft[challenger.team.toUpperCase()]||0)*MAX_PTS);
      }
      if(zone==='relegate'){
        var safe=teams[total-relegate-1];
        if(!safe) return true;
        return (safe.pts-myPts) > ((gamesLeft[teams[idx].team.toUpperCase()]||0)*MAX_PTS);
      }
      return false;
    }

    var h='<div class="tbl-wrap"><table class="lg"><thead><tr>'
      +'<th class="cp">Pos</th><th class="ct">Team</th>'
      +'<th>Pl</th><th>Pts</th><th>+/-</th><th>W</th><th>L</th><th>D</th><th>For</th><th>Agn</th>'
      +'</tr></thead><tbody>';

    teams.forEach(function(t,idx){
      var zone=getZone(idx), clinched=isClinched(idx);
      var borderTop='';
      if(promote>0  && idx===promote)         borderTop=' zone-border-top';
      if(relegate>0 && idx===total-relegate)  borderTop=' zone-border-top';
      var rowClass='';
      if(zone==='promote')  rowClass=clinched?' row-promoted':' row-promote-zone';
      if(zone==='relegate') rowClass=clinched?' row-relegated':' row-relegate-zone';
      rowClass+=borderTop;
      h+='<tr class="'+rowClass.trim()+' nipgl-team-row" data-team="'+t.team+'">'
        +'<td class="cp">'+t.pos+'</td>'
        +'<td class="ct"><span class="nipgl-team-link">'+badgeImg(t.team)+t.team+'</span></td>'
        +'<td>'+t.pl+'</td><td class="ck">'+t.pts+'</td><td>'+t.diff+'</td>'
        +'<td>'+t.w+'</td><td>'+t.l+'</td><td>'+t.d+'</td>'
        +'<td>'+t.f+'</td><td>'+t.a+'</td>'
        +'</tr>';
    });

    h+='</tbody></table></div>';

    if(promote>0 || relegate>0){
      h+='<div class="lg-legend">';
      if(promote>0)  h+='<span class="lg-key lg-key-promote"></span>Promotion';
      if(relegate>0) h+='<span class="lg-key lg-key-relegate"></span>Relegation';
      h+='</div>';
    }
    return h;
  }

  // ── Render fixtures ───────────────────────────────────────────────────────────
  function parseDate(str){
    try{var p=str.split(' ')[1].split('-');return new Date(p[1]+' '+p[0]+' '+p[2]);}catch(e){return null;}
  }

  function renderFixtures(rows, filter){
    var groups = parseFixtureGroups(rows);
    var now=new Date(), filtered=groups;
    if(filter==='results'){
      filtered=groups.map(function(g){
        return{date:g.date,matches:g.matches.filter(function(m){return m.played;})};
      }).filter(function(g){return g.matches.length;});
    } else if(filter==='upcoming'){
      filtered=groups.map(function(g){
        var d=parseDate(g.date);
        if(!d) return{date:g.date,matches:[]};
        var matches=g.matches.filter(function(m){return !m.played && d>=now;});
        return{date:g.date,matches:matches};
      }).filter(function(g){return g.matches.length;});
    }
    if(!filtered.length) return '<div class="nipgl-status">No fixtures to display.</div>';
    var h='';
    filtered.forEach(function(g){
      h+='<div class="date-group"><div class="date-hdr">'+g.date+'</div>';
      g.matches.forEach(function(m){
        var pc=m.played?' played':'';
        h+='<div class="fx-row'+pc+'">'
          +'<div class="fx-ph">'+(m.played?m.ptsHome:'')+'</div>'
          +'<div class="fx-h"><span class="nipgl-team-link" data-team="'+m.homeTeam+'">'+badgeImg(m.homeTeam)+m.homeTeam+'</span></div>'
          +'<div class="fx-sc"><span class="fx-sb">'+m.shotsHome+'</span>'
          +'<span class="fx-sep">v</span>'
          +'<span class="fx-sb">'+m.shotsAway+'</span></div>'
          +'<div class="fx-a"><span class="nipgl-team-link" data-team="'+m.awayTeam+'">'+badgeImg(m.awayTeam)+m.awayTeam+'</span></div>'
          +'<div class="fx-pa">'+(m.played?m.ptsAway:'')+'</div>'
          +(m.timeNote?'<div class="fx-time">&#9200; '+m.timeNote+'</div>':'')
          +'</div>';
      });
      h+='</div>';
    });
    return h;
  }

  // ── Filter bar ────────────────────────────────────────────────────────────────
  function filterBar(activeFilter){
    var h='<div class="fix-filter">';
    ['all','results','upcoming'].forEach(function(f){
      var cap=f.charAt(0).toUpperCase()+f.slice(1);
      h+='<button data-f="'+f+'"'+(activeFilter===f?' class="active"':'')+'>'+cap+'</button>';
    });
    return h+'</div>';
  }

  // ── Init widget ───────────────────────────────────────────────────────────────
  function initWidget(widget){
    var csvUrl   = widget.getAttribute('data-csv');
    var promote  = parseInt(widget.getAttribute('data-promote')  || '0', 10);
    var relegate = parseInt(widget.getAttribute('data-relegate') || '0', 10);
    var extraSponsors=[];
    try{extraSponsors=JSON.parse(widget.getAttribute('data-sponsors')||'[]');}catch(e){}

    function sponsorBar(){
      if(!extraSponsors.length) return '';
      var sp=extraSponsors[Math.floor(Math.random()*extraSponsors.length)];
      if(!sp||!sp.image) return '';
      var img='<img src="'+sp.image+'" alt="'+(sp.name||'Sponsor')+'" class="nipgl-sponsor-img">';
      var inner=sp.url?'<a href="'+sp.url+'" target="_blank" rel="noopener">'+img+'</a>':img;
      return '<div class="nipgl-sponsor-bar nipgl-sponsor-secondary">'+inner+'</div>';
    }

    var activeFilter='all', allRows=null;
    var panels=widget.querySelectorAll('.nipgl-panel');
    var tabs=widget.querySelectorAll('.nipgl-tab');

    tabs.forEach(function(tab){
      tab.addEventListener('click',function(){
        tabs.forEach(function(t){t.classList.remove('active');});
        panels.forEach(function(p){p.classList.remove('active');});
        tab.classList.add('active');
        var name=tab.getAttribute('data-tab');
        for(var i=0;i<panels.length;i++){
          if(panels[i].getAttribute('data-panel')===name){panels[i].classList.add('active');break;}
        }
      });
    });

    function getPanel(name){
      for(var i=0;i<panels.length;i++){
        if(panels[i].getAttribute('data-panel')===name) return panels[i];
      }
      return null;
    }

    function bindTeamLinks(){
      widget.querySelectorAll('.nipgl-team-link').forEach(function(el){
        el.addEventListener('click',function(e){
          e.stopPropagation();
          var team = el.getAttribute('data-team') || el.closest('[data-team]').getAttribute('data-team');
          if(team && allRows) showTeamModal(team, allRows);
        });
      });
    }

    function bindFilterBtns(){
      widget.querySelectorAll('.fix-filter button').forEach(function(b){
        b.addEventListener('click',function(){
          activeFilter=b.getAttribute('data-f');
          widget.querySelectorAll('.fix-filter button').forEach(function(x){
            x.classList.toggle('active',x.getAttribute('data-f')===activeFilter);
          });
          var fp=getPanel('fixtures');
          if(fp) fp.innerHTML=filterBar(activeFilter)+renderFixtures(allRows,activeFilter);
          bindFilterBtns();
          bindTeamLinks();
        });
      });
    }

    function showError(msg){
      var e='<div class="nipgl-status"><strong>Unable to load data.</strong><br><small>'+msg+'</small></div>';
      var tp=getPanel('table'), fp=getPanel('fixtures');
      if(tp) tp.innerHTML=e;
      if(fp) fp.innerHTML=e;
    }

    var proxyUrl=ajaxUrl+'?action=nipgl_csv&url='+encodeURIComponent(csvUrl);
    var xhr=new XMLHttpRequest();
    xhr.open('GET',proxyUrl);
    xhr.onload=function(){
      if(xhr.status===200 && xhr.responseText && xhr.responseText.trim().length>10){
        allRows=parseCSV(xhr.responseText);
        var tp=getPanel('table'), fp=getPanel('fixtures');
        if(tp) tp.innerHTML=renderTable(allRows,promote,relegate)+sponsorBar();
        if(fp) fp.innerHTML=filterBar(activeFilter)+renderFixtures(allRows,activeFilter);
        bindFilterBtns();
        bindTeamLinks();
      } else {
        showError('Server returned status '+xhr.status);
      }
    };
    xhr.onerror=function(){showError('Network error — could not reach proxy.');};
    xhr.send();
  }

  function init(){
    document.querySelectorAll('.nipgl-w[data-csv]').forEach(function(w){initWidget(w);});
  }

  if(document.readyState==='loading'){
    document.addEventListener('DOMContentLoaded',init);
  } else {
    init();
  }

})();

(function(){
  function matFrom(el){
    const tables = el.tagName==='TABLE' ? [el] : [...el.querySelectorAll('table')];
    const rows=[];
    tables.forEach(t=>{ [...t.rows].forEach(tr=>{ rows.push([...tr.cells].map(c=>c.innerText.replace(/\s+/g,' ').trim())); }); });
    return rows;
  }
  function dl(blob,name){ const a=document.createElement('a'); a.href=URL.createObjectURL(blob); a.download=name; document.body.appendChild(a); a.click(); a.remove(); setTimeout(()=>URL.revokeObjectURL(a.href),2000); }
  function toCSV(m){ return m.map(r=>r.map(c=>'"'+String(c).replace(/"/g,'""')+'"').join(',')).join('\n'); }
  function exportCSV(el,name){ dl(new Blob(['﻿'+toCSV(matFrom(el))],{type:'text/csv;charset=utf-8'}), name+'.csv'); }
  function exportXLS(el,name){
    const tables=el.tagName==='TABLE'?[el]:[...el.querySelectorAll('table')];
    let h='<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body>';
    tables.forEach(t=>h+=t.outerHTML); h+='</body></html>';
    dl(new Blob([h],{type:'application/vnd.ms-excel'}), name+'.xls');
  }
  function buildPrintHTML(el,title){
    const tables=el.tagName==='TABLE'?[el]:[...el.querySelectorAll('table')];
    let t=''; tables.forEach(x=>t+=x.outerHTML);
    return '<html><head><meta charset="utf-8"><title>'+title+'</title><style>'
      +'body{font-family:-apple-system,Arial,sans-serif;color:#111;padding:26px}h1{font-size:18px;margin:0 0 14px}'
      +'table{border-collapse:collapse;width:100%;font-size:12px;margin:8px 0 18px}'
      +'th,td{border:1px solid #e2e4ea;padding:5px 8px;text-align:right;white-space:nowrap}'
      +'th:first-child,td:first-child{text-align:left}'
      +'thead th{background:#f3f4f6;color:#333}'
      +'input{border:none;background:transparent;font:inherit;color:#111;width:auto;text-align:right}'
      +'.badges,.ar,.rtbar{display:none!important}'
      +'</style></head><body><h1>'+title+'</h1>'+t
      +'<p style="color:#888;font-size:11px;margin-top:14px">KRATFEL Finanzas · '+new Date().toLocaleString('es-MX')+'</p></body></html>';
  }
  function doPrint(el,title){ const w=window.open('','_blank'); w.document.write(buildPrintHTML(el,title)); w.document.close(); w.focus(); setTimeout(()=>w.print(),350); }
  function doEmail(el,title,name){
    const to=prompt('Enviar el reporte a (correo):'); if(!to) return;
    const tables=el.tagName==='TABLE'?[el]:[...el.querySelectorAll('table')];
    let body=''; tables.forEach(x=>body+=x.outerHTML);
    fetch('/report_email.php',{method:'POST',headers:{'Content-Type':'application/json'},
      body:JSON.stringify({to:to,title:title,html:body})})
      .then(r=>r.json()).then(d=>alert(d.ok?('Enviado a '+to):('No se pudo enviar'+(d.error?': '+d.error:'')))).catch(()=>alert('Error de red'));
  }
  document.querySelectorAll('.rtbar').forEach(bar=>{
    const tgt=()=>document.querySelector(bar.dataset.target);
    const name=bar.dataset.name||'reporte', title=bar.dataset.title||name, menu=bar.querySelector('.rtmenu');
    bar.querySelectorAll('button[data-act]').forEach(b=>b.addEventListener('click',e=>{
      e.stopPropagation(); const a=b.dataset.act;
      if(a==='print') doPrint(tgt(),title);
      else if(a==='email') doEmail(tgt(),title,name);
      else if(a==='export'){ document.querySelectorAll('.rtmenu.open').forEach(m=>{if(m!==menu)m.classList.remove('open');}); menu.classList.toggle('open'); }
    }));
    bar.querySelectorAll('.rtmenu [data-fmt]').forEach(it=>it.addEventListener('click',e=>{
      e.preventDefault(); const f=it.dataset.fmt, el=tgt();
      if(f==='csv') exportCSV(el,name); else if(f==='xlsx') exportXLS(el,name); else if(f==='pdf') doPrint(el,title);
      menu.classList.remove('open');
    }));
  });
  document.addEventListener('click',()=>document.querySelectorAll('.rtmenu.open').forEach(m=>m.classList.remove('open')));
})();

(function () {
'use strict';
<<<<<<< Updated upstream
var endpoint = '/rab-project/dev/php-crud/m.proyekCRD.php';
=======
var endpoint = '/rab-project/live/php-crud/m.proyekCRD.php';
>>>>>>> Stashed changes
var mode = window.PROJECT_PRINT_MODE || 'projects';
var projectId = parseInt(window.PROJECT_PRINT_ID, 10) || 0;
var content = document.getElementById('content');
function esc(v) { return String(v == null || v === '' ? '-' : v).replace(/[&<>"']/g, function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }
function date(v) { if (!v) return '-'; var m=String(v).match(/^(\d{4})-(\d{2})-(\d{2})/); return m ? m[3]+'/'+m[2]+'/'+m[1] : esc(v); }
function money(v) { var n=Number(v||0); return n.toLocaleString('id-ID',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function request(params) {
 return fetch(endpoint,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:new URLSearchParams(params).toString(),credentials:'same-origin'})
 .then(function(r){if(!r.ok) throw new Error('HTTP '+r.status);return r.json();})
 .then(function(r){if(!r || r.success===false) throw new Error(r && r.message ? r.message : 'Gagal membaca data.');return r;});
}
function projectRows(rows) {
 var html='<table><thead><tr><th>No.</th><th>Nama Proyek</th><th>Klien</th><th>PIC / PM</th><th>Lokasi</th><th>Tgl Mulai</th><th>Tgl Selesai</th><th>Budget</th><th>Nilai Kontrak</th><th>Progress</th><th>Status</th></tr></thead><tbody>';
 if (!rows.length) return '<p>Tidak ada data proyek.</p>';
 rows.forEach(function(p,i){html+='<tr><td>'+(i+1)+'</td><td>'+esc(p.name)+'</td><td>'+esc(p.client_name)+'</td><td>'+esc(p.pic_name)+'</td><td>'+esc(p.location_name||p.location)+'</td><td>'+date(p.start_date)+'</td><td>'+date(p.end_date)+'</td><td>'+esc(p.budget_currency_code||'IDR')+' '+money(p.budget)+'</td><td>Rp '+money(p.contract_value)+'</td><td>'+esc(p.progress||0)+'%</td><td>'+esc(p.status)+'</td></tr>';});
 return html+'</tbody></table>';
}
function taskRows(rows) {
 var html='<table><thead><tr><th style="width:36px">No.</th><th>Nama Task</th><th>Deskripsi Pekerjaan</th><th>Tgl Mulai</th><th>Tgl Selesai</th><th>Status</th></tr></thead><tbody>';
 if (!rows.length) return '<p>Belum ada task untuk proyek ini.</p>';
 rows.forEach(function(t,i){html+='<tr><td>'+(i+1)+'</td><td>'+esc(t.task_name)+'</td><td>'+esc(t.description)+'</td><td>'+date(t.start_date)+'</td><td>'+date(t.end_date)+'</td><td>'+esc(t.status)+'</td></tr>';});
 return html+'</tbody></table>';
}
document.getElementById('btnPrint').onclick=function(){window.print();};
document.getElementById('btnClose').onclick=function(){window.close();};
if (mode === 'tasks') {
 if (!projectId) {content.innerHTML='<p>ID proyek tidak valid.</p>';return;}
 Promise.all([request({task:'read_one',id:projectId}),request({task:'read_tasks',project_id:projectId})]).then(function(res){
  var p=res[0].data||{}, tasks=res[1].data||[];
  document.getElementById('title').textContent='Rincian Task Proyek';
  document.getElementById('subtitle').textContent=p.name||'Proyek';
  content.innerHTML='<h2>'+esc(p.name)+'</h2><div class="meta"><div><b>Klien:</b> '+esc(p.client_name)+'</div><div><b>PIC / PM:</b> '+esc(p.pic_name)+'</div><div><b>Lokasi:</b> '+esc(p.location_name||p.location)+'</div><div><b>Status:</b> '+esc(p.status)+'</div><div><b>Tanggal Mulai:</b> '+date(p.start_date)+'</div><div><b>Tanggal Selesai:</b> '+date(p.end_date)+'</div></div><h2>Daftar Task</h2>'+taskRows(tasks);
 }).catch(function(e){content.innerHTML='<p>Gagal memuat data: '+esc(e.message)+'</p>';});
} else {
 request({task:'read'}).then(function(res){var rows=res.data||[];document.getElementById('title').textContent='Daftar Seluruh Proyek';document.getElementById('subtitle').textContent='Jumlah proyek: '+rows.length;content.innerHTML=projectRows(rows);}).catch(function(e){content.innerHTML='<p>Gagal memuat data: '+esc(e.message)+'</p>';});
}
})();
<?php
require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/_phrases.php';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$owner=(string)$_SERVER['PHP_AUTH_USER'];
$error='';
try {
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        if (!is_string($_POST['csrf']??null) || !hash_equals($adminCsrf,$_POST['csrf'])) {
            http_response_code(403); throw new InvalidArgumentException('Восьты листбоксӧ выльысь да видзӧдлы нӧшта ӧтчыд.');
        }
        $action=(string)($_POST['action']??''); $id=(string)($_POST['job']??'');
        if (isset($_GET['api'])) {
            header('Content-Type: application/json; charset=utf-8');
            switch ($action) {
                case 'create':
                    $id=pi_create($owner,(string)($_POST['name']??''),(string)($_POST['source']??''),(int)($_POST['size']??0)); break;
                case 'upload':
                    $f=$_FILES['chunk']??[];
                    if (($f['error']??-1)!==UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name']??'') || ($f['size']??0)>PHRASE_IMPORT_CHUNK) throw new InvalidArgumentException('Эз артмы босьтны файллысь юкӧнсӧ. Ысты нӧшта ӧтчыд.');
                    $bytes=file_get_contents($f['tmp_name']);
                    if ($bytes===false) throw new RuntimeException('Эз артмы лыддьыны файллысь юкӧнсӧ.');
                    pi_upload($id,$owner,(int)($_POST['offset']??-1),$bytes); break;
                case 'scan': pi_scan($id,$owner); break;
                case 'begin': pi_begin($id,$owner); break;
                case 'commit': pi_commit($id,$owner); break;
                case 'cancel': pi_cancel($id,$owner); break;
                default: throw new InvalidArgumentException('Татшӧм вӧчӧм абу тӧдса.');
            }
            echo json_encode(['ok'=>true,'job'=>pi_status($id,$owner)],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR); exit;
        }
        if ($action==='save') {
            $new=pi_save((int)($_POST['id']??0),(string)($_POST['revision']??''),(string)($_POST['text']??''),(string)($_POST['source']??''),$owner);
            header('Location: phrases.php?edit='.$new.'&saved=1'); exit;
        }
        if ($action==='toggle') {
            pi_toggle((int)($_POST['id']??0),(string)($_POST['revision']??''),($_POST['enable']??'')==='1',$owner);
            header('Location: phrases.php?edit='.(int)$_POST['id'].'&saved=1'); exit;
        }
        throw new InvalidArgumentException('Татшӧм вӧчӧм абу тӧдса.');
    }
    if (isset($_GET['api'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>true,'job'=>pi_status((string)($_GET['job']??''),$owner)],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR); exit;
    }
} catch (Throwable $e) {
    $error=$e instanceof InvalidArgumentException?$e->getMessage():'Эз артмы. Восьты листбоксӧ выльысь да видзӧдлы нӧшта ӧтчыд. Оз кӧ отсав — юӧрт администраторлы.';
    if (!$e instanceof InvalidArgumentException) error_log('Phrase panel: '.$e->getMessage());
    if (isset($_GET['api'])) {
        if (http_response_code()<400) http_response_code($e instanceof InvalidArgumentException?400:500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false,'error'=>$error],JSON_UNESCAPED_UNICODE); exit;
    }
}
try {
    $jobs=pi_query("SELECT id,filename,state,added,created_at FROM phrase_import_jobs WHERE owner=? ORDER BY CASE WHEN state IN ('uploading','scanning','ready','committing') THEN 0 ELSE 1 END, created_at DESC LIMIT 25",[$owner])->fetchAll();
    $edit=null;
    if ((int)($_GET['edit']??0)>0) $edit=pi_query('SELECT * FROM phrases WHERE id=?',[(int)$_GET['edit']])->fetch();
    $q=mb_substr(trim((string)($_GET['q']??'')),0,200);
    $filter=in_array($_GET['filter']??'', ['active','disabled'],true)?$_GET['filter']:'all';
    $args=[]; $where=[];
    if ($q!=='') { $where[]='(INSTR(p.text,?)>0 OR INSTR(COALESCE(p.source,\'\'),?)>0)'; $args[]=$q; $args[]=$q; }
    if ($filter==='active') $where[]='p.required_times>0';
    if ($filter==='disabled') $where[]='p.required_times=0';
    if ((int)($_GET['after']??0)>0) { $where[]='p.id<?'; $args[]=(int)$_GET['after']; }
    $rows=pi_query('SELECT p.*, (SELECT COUNT(*) FROM recordings r WHERE r.phrase_id=p.id) AS recordings,
        EXISTS(SELECT 1 FROM phrase_reservations r WHERE r.phrase_id=p.id AND r.expires_at>NOW()) AS reserved
        FROM phrases p '.($where?'WHERE '.implode(' AND ',$where):'').' ORDER BY p.id DESC LIMIT 51',$args)->fetchAll();
    $more=count($rows)>50; $rows=array_slice($rows,0,50);
} catch (Throwable $e) {
    error_log('Phrase panel initialization: '.$e->getMessage());
    http_response_code(503); exit('Сёрникузяяслы юкӧныс на абу дась. Юӧрт администраторлы.');
}
$stateLabels=['uploading'=>'Файл сӧвтӧм','scanning'=>'Видлалӧм','ready'=>'Содтӧм вылӧ дась','committing'=>'Содтӧм','done'=>'Дась','cancelled'=>'Дугӧдӧма'];
?>
<!doctype html><html lang="kv"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>FU-Lab — Сёрникузяяс</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f5f7fa;color:#243246;font:16px/1.5 system-ui,sans-serif}main{max-width:1120px;margin:32px auto;padding:0 20px}h1{margin:8px 0}h2{font-size:21px;margin-top:0}a{color:#245a9b}nav{display:flex;gap:22px;margin:16px 0 24px}.card{background:white;border:1px solid #dbe2eb;border-radius:12px;padding:24px;margin:18px 0}.muted,small{color:#627186}label{display:block;margin:14px 0 5px}input,textarea,select{font:inherit;border:1px solid #b9c5d4;border-radius:6px;padding:10px;max-width:100%}input[type=text],textarea{width:100%}textarea{min-height:130px}button,.button{display:inline-block;font:inherit;background:#235b9b;color:white;padding:9px 17px;border:0;border-radius:6px;cursor:pointer;text-decoration:none}button:disabled{opacity:.5;cursor:wait}.secondary{background:#e8edf4;color:#243246}.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}.error{background:#ffe9e7;color:#942522;padding:12px;border-radius:6px}.notice{background:#e5f2e9;padding:12px;border-radius:6px}progress{width:100%;height:20px}table{width:100%;border-collapse:collapse;font-size:14px}td,th{padding:12px 8px;border-bottom:1px solid #e1e6ed;text-align:left;vertical-align:top}td.text{min-width:230px;overflow-wrap:anywhere}details{margin:12px 0}.scroll{overflow:auto}pre{white-space:pre-wrap;overflow-wrap:anywhere}#sample{padding-left:25px}#sample li{margin:10px 0;overflow-wrap:anywhere}.stats{white-space:pre-line}#job{scroll-margin-top:20px}.status{font-size:13px;padding:2px 7px;border-radius:4px;background:#eef2f7}@media(max-width:650px){main{padding:0 12px;margin:16px auto}.card{padding:16px}}
.file-picker{display:flex;align-items:center;gap:14px;flex-wrap:wrap}.file-picker label{margin:0;cursor:pointer}.file-input{position:absolute;width:1px;height:1px;padding:0;border:0;overflow:hidden;clip-path:inset(50%)}.file-input:focus-visible+label{outline:3px solid #82aadd;outline-offset:3px}#fileName{overflow-wrap:anywhere}
</style></head><body><main>
<div class="muted">FU-Lab</div><h1>Сёрникузяяс</h1>
<nav><a href="index.php">Аудиоюӧръяс</a><strong>Сёрникузяяс</strong></nav>
<?php if($error):?><p class="error"><?=e($error)?></p><?php endif?>
<?php if(isset($_GET['saved'])):?><p class="notice">Видзӧма.</p><?php endif?>
<section class="card"><h2>Сӧвтны файл</h2>

<form id="uploadForm"><p id="fileHelp">TXT-файл · 128 МиБ-ӧдз</p><div class="file-picker"><input class="file-input" id="file" type="file" accept=".txt,text/plain" aria-describedby="fileHelp fileName"><label class="button secondary" for="file">Бӧрйыны файл</label><span id="fileName" class="muted" aria-live="polite">Файл абу бӧрйӧма</span></div>
<label for="source">Ӧшмӧс</label><input id="source" type="text" maxlength="100" placeholder="Гижӧдлӧн либӧ чукӧрлӧн ним">
<div class="actions"><button id="uploadButton">Сӧвтны</button></div></form>
<noscript><p class="error">Файл сӧвтӧм вылӧ колӧ JavaScript. Улысас позьӧ содтыны да вежны торъя сёрникузяяс.</p></noscript>
</section>
<section class="card" id="job" hidden><h2 id="jobTitle"></h2><p id="jobState"></p><progress id="progress" max="100" value="0"></progress><p class="stats" id="counts"></p>
<p id="jobError" class="error" hidden></p><ul id="issues"></ul><ol id="sample"></ol>
<p id="commitNote" hidden>Содтӧма лоӧны сӧмын выль, ӧшыбкатӧм сёрникузяяс. Ӧткодь сёрникузяяс оз содтысьны выльысь. Дугӧдан кӧ, содтӧм сёрникузяясыс кольччасны базаын.</p>
<div class="actions"><button id="confirm" hidden>Содтыны ботӧ</button><button id="resume" class="secondary" hidden>Водзӧ</button><button id="pause" class="secondary" hidden>Сувтӧдны</button><button id="cancel" class="secondary" hidden>Дугӧдны</button></div>
<p class="muted">Видлалігӧн да содтігӧн эн пӧд листбоксӧ. Пӧдан кӧ, бӧрын позьӧ водзӧ мунны сӧвтӧм файлъяс лыддьӧгысь. Файлыс эз кӧ сӧвтсьы помӧдз, бӧрйы сійӧ жӧ TXT-файлсӧ выльысь.</p></section>
<section class="card"><h2>Сӧвтӧм файлъяс</h2><div class="scroll"><table><thead><tr><th>Файл</th><th>Вӧчӧм</th><th>Содтӧма</th><th>Кад</th><th></th></tr></thead><tbody>
<?php foreach($jobs as $j):?><tr><td><?=e($j['filename'])?></td><td><?=e($stateLabels[$j['state']]??$j['state'])?></td><td><?=(int)$j['added']?></td><td><?=e($j['created_at'])?></td><td><button class="secondary openJob" data-job="<?=e($j['id'])?>">Восьтыны</button></td></tr><?php endforeach?>
<?php if(!$jobs):?><tr><td colspan="5">Сӧвтӧм файлъяс абуӧсь на.</td></tr><?php endif?></tbody></table></div></section>
<section class="card"><h2><?=$edit?'Вежны сёрникузя · № '.(int)$edit['id']:'Содтыны торъя сёрникузя'?></h2>
<p class="muted">Текст вежигӧн артмӧ выль сёрникузя. Важсӧ ботыс оз нин сет лыддьыны, но сылӧн текстыс да аудиоюӧръясыс видзсьӧны. Ӧні кӧ морт лыддьӧ сёрникузясӧ, видччысь, кор сійӧ помалас гижӧмсӧ либӧ помасьӧ виччысян кадыс.</p>
<form method="post"><input type="hidden" name="csrf" value="<?=e($adminCsrf)?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?=(int)($edit['id']??0)?>"><input type="hidden" name="revision" value="<?=e($edit?pi_revision($edit):'')?>">
<label for="phrase">Сёрникузя</label><textarea id="phrase" name="text" maxlength="3000" required><?=e($edit['text']??'')?></textarea>
<label for="phraseSource">Ӧшмӧс</label><input id="phraseSource" name="source" type="text" maxlength="100" value="<?=e($edit['source']??'')?>">
<div class="actions"><button>Содтыны</button><?php if($edit):?><a class="button secondary" href="phrases.php">Выль сёрникузя</a><?php endif?></div></form>
<?php if($edit):?><form method="post" class="actions"><input type="hidden" name="csrf" value="<?=e($adminCsrf)?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=(int)$edit['id']?>"><input type="hidden" name="revision" value="<?=e(pi_revision($edit))?>"><input type="hidden" name="enable" value="<?=$edit['required_times']>0?'0':'1'?>"><button class="secondary"><?=$edit['required_times']>0?'Дугӧдны сетӧм':'Заводитны сетны'?></button><span class="muted">Кымын аудиоюӧр колӧ: <?=(int)$edit['required_times']?>. Аудиоюӧръяс оз чышкысьны.</span></form><?php endif?>
</section>
<section class="card"><h2>Сёрникузяяс</h2><form method="get"><label for="search">Корсьны текст серти либӧ ӧшмӧс серти</label><input id="search" name="q" type="text" value="<?=e($q)?>" maxlength="200"><div class="actions"><select name="filter"><option value="all">Ставыс</option><option value="active" <?=$filter==='active'?'selected':''?>>Сетсьӧны лыддьыны</option><option value="disabled" <?=$filter==='disabled'?'selected':''?>>Оз сетсьыны лыддьыны</option></select><button>Корсьны</button></div></form>
<div class="scroll"><table><thead><tr><th>ID</th><th>Сёрникузя</th><th>Ӧшмӧс</th><th>Колӧ / гижӧма</th><th></th></tr></thead><tbody>
<?php foreach($rows as $r):?><tr><td><?=(int)$r['id']?></td><td class="text"><?=e($r['text'])?><?php if($r['reserved']):?><br><span class="status">Ӧні сетӧма лыддьыны</span><?php endif?></td><td><?=e($r['source']??'')?></td><td><?=(int)$r['required_times']?> / <?=(int)$r['recordings']?></td><td><a href="?edit=<?=(int)$r['id']?>">Вежны</a></td></tr><?php endforeach?>
<?php if(!$rows):?><tr><td colspan="5">Нинӧм абу аддзӧма.</td></tr><?php endif?></tbody></table></div>
<p class="muted">Тані 50 сёрникузяӧдз. «Гижӧма» лыдӧ пырӧны став аудиоюӧръясыс: лӧсяланаӧсь, видлалӧм вылӧ да оз лӧсявны.</p>
<?php if($more):?><a class="button secondary" href="?<?=e(http_build_query(['q'=>$q,'filter'=>$filter,'after'=>end($rows)['id']]))?>">Водзӧ →</a><?php endif?></section>
</main><script>
'use strict';
const csrf=<?=json_encode($adminCsrf)?>;
const labels=<?=json_encode($stateLabels,JSON_UNESCAPED_UNICODE)?>;
let current=null,busy=false,paused=false;
const el=id=>document.getElementById(id);
el('file').addEventListener('change',()=>{el('fileName').textContent=el('file').files[0]?.name||'Файл абу бӧрйӧма';});
async function api(action,extra={},chunk=null){
 const data=new FormData();data.set('csrf',csrf);data.set('action',action);
 for(const [k,v] of Object.entries(extra))data.set(k,String(v));
 if(chunk)data.set('chunk',chunk,'chunk.bin');
 const response=await fetch('phrases.php?api=1',{method:'POST',body:data,credentials:'same-origin'});
 let result;try{result=await response.json()}catch(e){throw Error('Серверлӧн вочакывыс абу лӧсялана. Восьты листбоксӧ выльысь да мун водзӧ сӧвтӧм файлъяс лыддьӧгысь.')}
 if(!response.ok||!result.ok)throw Error(result.error||'Юалӧмын ӧшыбка');return result.job;
}
function render(j){
 current=j;el('job').hidden=false;el('jobTitle').textContent=j.filename;el('jobState').textContent=labels[j.state]||j.state;
 const count=Number(j.unique_count),done=Number(j.added)+Number(j.commit_skipped);
 el('progress').value=j.state==='uploading'?100*Number(j.uploaded_bytes)/Number(j.total_bytes):j.state==='scanning'?100*Number(j.scan_offset)/Number(j.total_bytes):j.state==='committing'?100*done/Math.max(1,count):100;
 el('counts').textContent=`Визьяс: ${j.lines} · тырлытӧмӧсь: ${j.blanks} · ӧшыбкаясӧн: ${j.invalid}\nӦткодь визьяс: ${j.duplicates} · эм нин базаын: ${j.existing_count}\nТоръя, ӧшыбкатӧм: ${j.unique_count} · выль сёрникузяяс: ${Math.max(0,count-Number(j.existing_count))}\nСодтӧма: ${j.added} · абу содтӧма: ${j.commit_skipped}`;
 el('issues').replaceChildren();for(const issue of j.issues||[]){let li=document.createElement('li');li.textContent=`Визь ${issue.line}: ${issue.reason}`;el('issues').append(li)}
 el('sample').replaceChildren();for(const r of j.sample||[]){let li=document.createElement('li');li.textContent=r.text+(r.existing_id?' — эм нин базаын':'');el('sample').append(li)}
 el('commitNote').hidden=j.state!=='ready';el('confirm').hidden=j.state!=='ready';
 el('resume').hidden=!['uploading','scanning','committing'].includes(j.state);
 el('cancel').hidden=['done','cancelled'].includes(j.state);
 setBusy(busy);
}
function setBusy(value){busy=value;el('pause').disabled=false;el('pause').hidden=!value||!current||!['uploading','scanning','committing'].includes(current.state);for(const selector of ['#uploadButton','#confirm','#resume','#cancel','.openJob'])document.querySelectorAll(selector).forEach(x=>x.disabled=value)}
async function run(fn){if(busy)return;paused=false;setBusy(true);el('jobError').hidden=true;try{await fn()}catch(e){el('job').hidden=false;el('jobError').textContent=e.message;el('jobError').hidden=false}finally{setBusy(false)}}
async function work(){
 while(!paused&&current&&['scanning','committing'].includes(current.state)){
   render(await api(current.state==='scanning'?'scan':'commit',{job:current.id}));
 }
}
async function upload(file){
 if(!file||file.name!==current.filename||file.size!==Number(current.total_bytes))throw Error('Бӧрйы сійӧ жӧ TXT-файлсӧ: нимыс да ыдждаыс мед лоӧны ӧткодьӧсь.');
 // Resume replays prior chunks too: the server verifies their bytes, not just a filename.
 for(let offset=0;!paused&&offset<file.size;offset+=524288){
   render(await api('upload',{job:current.id,offset},file.slice(offset,offset+524288)));
 }
 await work();
}
el('uploadForm').addEventListener('submit',event=>{event.preventDefault();run(async()=>{
 const file=el('file').files[0];if(!file)throw Error('Бӧрйы TXT-файл.');
 if(file.size>134217728)throw Error('Файлыс 128 МиБ-ысь ыджыдджык. Юк сійӧс юкӧнъяс вылӧ.');
 render(await api('create',{name:file.name,size:file.size,source:el('source').value}));
 el('job').scrollIntoView({behavior:'smooth'});await upload(file);
})});
el('pause').onclick=()=>{paused=true;el('pause').disabled=true;el('jobState').textContent='Сувтӧдӧма лоӧ тайӧ юкӧн бӧрын';};
el('confirm').onclick=()=>run(async()=>{render(await api('begin',{job:current.id}));await work()});
el('resume').onclick=()=>run(async()=>{if(current.state==='uploading')await upload(el('file').files[0]);else await work()});
el('cancel').onclick=()=>run(async()=>{if(confirm('Дугӧдны сӧвтӧмсӧ? Содтӧм сёрникузяясыс кольччасны базаын.'))render(await api('cancel',{job:current.id}))});
document.querySelectorAll('.openJob').forEach(b=>b.onclick=()=>run(async()=>{
 const r=await fetch('phrases.php?api=1&job='+encodeURIComponent(b.dataset.job),{credentials:'same-origin'});const data=await r.json();
 if(!r.ok||!data.ok)throw Error(data.error||'Юалӧмын ӧшыбка');render(data.job);el('job').scrollIntoView({behavior:'smooth'});
}));
</script></body></html>

<?php
/** Auth is performed by the caller (_init.php). All mutations use POST + CSRF. */
const PHRASE_IMPORT_MAX = 134217728;
const PHRASE_IMPORT_CHUNK = 524288;
function pi_query(string $sql, array $args = []): PDOStatement {
    $s = db()->prepare($sql); $s->execute($args); return $s;
}
function pi_dir(): string {
    $dir = dirname(UPLOAD_DIR) . '/phrase-imports';
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Эз артмы лӧсьӧдны файлъяслы папка.');
    }
    return $dir;
}
function pi_path(string $id): string {
    if (!preg_match('/\A[a-f0-9]{32}\z/D', $id)) throw new InvalidArgumentException('Сӧвтӧмлӧн номерыс абу стӧч.');
    return pi_dir() . '/' . $id . '.txt';
}
function pi_job(string $id, string $owner, bool $lock = false): array {
    pi_path($id);
    $j = pi_query('SELECT * FROM phrase_import_jobs WHERE id = ? AND owner = ?' . ($lock ? ' FOR UPDATE' : ''), [$id,$owner])->fetch();
    if (!$j) throw new InvalidArgumentException('Сӧвтӧмыс абу аддзӧма.');
    return $j;
}
function pi_lock(callable $fn) {
    // Shared with the existing CLI importer; serializes exact-text insertion.
    $name = 'fulab_import_' . substr(hash('sha256', DB_NAME), 0, 24);
    if ((int)db_val('SELECT GET_LOCK(?, 10)', [$name]) !== 1) throw new RuntimeException('Ӧні сӧвтсьӧ мӧд файл. Видзӧдлы сёрӧнджык.');
    try { return $fn(); } finally { db_val('SELECT RELEASE_LOCK(?)', [$name]); }
}
function pi_transaction(callable $fn) {
    db()->beginTransaction();
    try { $r = $fn(); db()->commit(); return $r; }
    catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
}
function pi_text(string $s): string {
    if (!mb_check_encoding($s, 'UTF-8')) throw new InvalidArgumentException('Кодировкаыс абу UTF-8.');
    $s = preg_replace('/\A[\s\x{FEFF}]+|\s+\z/u', '', $s);
    if ($s === '') return '';
    if (preg_match('/[\x00-\x1F\x7F]/u', $s)) throw new InvalidArgumentException('Эмӧсь лӧсявтӧм пасъяс либӧ некымын визь.');
    if (mb_strlen($s) > 3000) throw new InvalidArgumentException('3000 пасйысь унджык: юк визьсӧ торъя сёрникузяяс вылӧ.');
    return $s;
}
function pi_source(string $s): string {
    $s = trim($s);
    if (!mb_check_encoding($s, 'UTF-8') || mb_strlen($s) > 100 || preg_match('/[\x00-\x1F\x7F]/', $s)) {
        throw new InvalidArgumentException('Ӧшмӧслӧн ним: 100 пасӧдз, ӧти визьын.');
    }
    return $s;
}
function pi_existing(string $text): ?int {
    $id = db_val('SELECT id FROM phrases WHERE import_hash = UNHEX(?) AND BINARY text = BINARY ? ORDER BY id LIMIT 1', [hash('sha256',$text),$text]);
    return $id === null ? null : (int)$id;
}
function pi_create(string $owner, string $name, string $source, int $size): string {
    if ($size <= 0 || $size > PHRASE_IMPORT_MAX) throw new InvalidArgumentException('TXT-файллӧн ыджда: 1 байтсянь 128 МиБ-ӧдз.');
    if (!preg_match('/\.txt\z/i', $name)) throw new InvalidArgumentException('Бӧрйы .txt файл.');
    if (!mb_check_encoding($name,'UTF-8')) throw new InvalidArgumentException('Файллӧн нимыс абу лӧсялана.');
    $source = pi_source($source);
    $count = (int)db_val("SELECT COUNT(*) FROM phrase_import_jobs WHERE owner=? AND state IN ('uploading','scanning','ready','committing')",[$owner]);
    if ($count >= 5) throw new InvalidArgumentException('Помав либӧ дугӧд ӧти важ сӧвтӧм. Ӧтпырйӧ позьӧ сӧвтны 5 файлӧдз.');
    $id = bin2hex(random_bytes(16));
    $f = fopen(pi_path($id),'x');
    if (!$f) throw new RuntimeException('Эз артмы лӧсьӧдны файл.');
    fclose($f); chmod(pi_path($id),0600);
    try {
        pi_query('INSERT INTO phrase_import_jobs(id,owner,filename,source,total_bytes,issues) VALUES(?,?,?,?,?,?)',
            [$id,$owner,mb_substr(basename($name),0,255),$source,$size,'[]']);
    } catch (Throwable $e) { unlink(pi_path($id)); throw $e; }
    return $id;
}
function pi_upload(string $id, string $owner, int $offset, string $bytes): void {
    pi_transaction(function () use ($id,$owner,$offset,$bytes) {
        $j=pi_job($id,$owner,true); $len=strlen($bytes);
        if ($j['state'] !== 'uploading') return; // safe retry of final chunk
        if (!$len || $len>PHRASE_IMPORT_CHUNK || $offset<0 || $offset+$len>(int)$j['total_bytes']) throw new InvalidArgumentException('Файллӧн юкӧныс абу лӧсялана.');
        if ($offset < (int)$j['uploaded_bytes']) {
            $f=fopen(pi_path($id),'rb'); if (!$f) throw new RuntimeException('Файлсӧ оз позь восьтыны.');
            fseek($f,$offset); $stored=fread($f,$len); fclose($f);
            if ($stored!==$bytes || $offset+$len>(int)$j['uploaded_bytes']) throw new InvalidArgumentException('Файллӧн юкӧныс мӧд. Бӧрйы сійӧ жӧ файлсӧ.');
            return;
        }
        if ($offset !== (int)$j['uploaded_bytes']) throw new InvalidArgumentException('Файллӧн юкӧнъясыс воӧмаӧсь мӧд пӧрадокӧн. Восьты листбоксӧ выльысь да мун водзӧ.');
        $f=fopen(pi_path($id),'c+b'); if (!$f) throw new RuntimeException('Файлсӧ оз позь восьтыны.');
        try {
            if (!ftruncate($f,$offset) || fseek($f,$offset)!==0) throw new RuntimeException('Эз артмы дасьтыны файлсӧ.');
            if (fwrite($f,$bytes)!==$len || !fflush($f)) throw new RuntimeException('Файллы абу тырмымӧн места.');
        } finally { fclose($f); }
        $next=$offset+$len;
        pi_query('UPDATE phrase_import_jobs SET uploaded_bytes=?, state=? WHERE id=?', [$next,$next===(int)$j['total_bytes']?'scanning':'uploading',$id]);
    });
}
function pi_scan(string $id, string $owner): void {
    pi_transaction(function () use ($id,$owner) {
        $j=pi_job($id,$owner,true);
        if ($j['state']!=='scanning') return;
        $f=fopen(pi_path($id),'rb'); if (!$f) throw new RuntimeException('Сӧвтӧм файл абу.');
        $issues=json_decode($j['issues'],true) ?: [];
        try {
            fseek($f,(int)$j['scan_offset']); $start=ftell($f); $n=0;
            while ($n++<500 && ftell($f)-$start<2097152 && ($line=fgets($f,65537))!==false) {
                $j['lines']++;
                $long=!str_ends_with($line,"\n") && !feof($f) && strlen($line)>=65536;
                if ($long) { while (($tail=fgets($f,65537))!==false) { if (str_ends_with($tail,"\n")) break; } }
                try {
                    if ($long) throw new InvalidArgumentException('Визьыс зэв кузь.');
                    $text=pi_text($line);
                    if ($text==='') { $j['blanks']++; continue; }
                    $hash=hash('sha256',$text);
                    $previous=pi_query('SELECT text FROM phrase_import_rows WHERE job_id=? AND text_hash=UNHEX(?)',[$id,$hash])->fetch();
                    if ($previous) {
                        if ($previous['text']!==$text) throw new RuntimeException('Файл видлалігӧн лоис ӧшыбка.');
                        $j['duplicates']++; continue;
                    }
                    $exists=pi_existing($text);
                    pi_query('INSERT INTO phrase_import_rows(job_id,line_no,text,text_hash,existing_id) VALUES(?,?,?,UNHEX(?),?)',[$id,$j['lines'],$text,$hash,$exists]);
                    $j['unique_count']++;
                    if ($exists!==null) $j['existing_count']++;
                } catch (InvalidArgumentException $e) {
                    $j['invalid']++;
                    if (count($issues)<20) $issues[]=['line'=>(int)$j['lines'],'reason'=>$e->getMessage()];
                }
            }
            $position=ftell($f); $done=$position>=(int)$j['total_bytes'];
        } finally { fclose($f); }
        pi_query('UPDATE phrase_import_jobs SET scan_offset=?,`lines`=?,blanks=?,invalid=?,duplicates=?,existing_count=?,unique_count=?,issues=?,state=? WHERE id=?',
            [$position,$j['lines'],$j['blanks'],$j['invalid'],$j['duplicates'],$j['existing_count'],$j['unique_count'],json_encode($issues,JSON_UNESCAPED_UNICODE),$done?'ready':'scanning',$id]);
    });
}
function pi_begin(string $id, string $owner): void {
    pi_transaction(function () use ($id,$owner) {
        $j=pi_job($id,$owner,true);
        if ($j['state']==='ready') pi_query("UPDATE phrase_import_jobs SET state='committing' WHERE id=?",[$id]);
        elseif (!in_array($j['state'],['committing','done'],true)) throw new InvalidArgumentException('Медводз видччысь видлалӧм помӧдз.');
    });
}
function pi_commit(string $id, string $owner): void {
    pi_lock(function () use ($id,$owner) {
        pi_transaction(function () use ($id,$owner) {
            $j=pi_job($id,$owner,true); if ($j['state']!=='committing') return;
            $rows=pi_query('SELECT line_no,text FROM phrase_import_rows WHERE job_id=? AND line_no>? ORDER BY line_no LIMIT 250',[$id,$j['cursor_line']])->fetchAll();
            foreach ($rows as $r) {
                if (pi_existing($r['text'])!==null) $j['commit_skipped']++;
                else {
                    pi_query('INSERT INTO phrases(text,source,required_times) VALUES(?,?,1)',[$r['text'],$j['source']]);
                    $j['added']++;
                }
                $j['cursor_line']=$r['line_no'];
            }
            $done=count($rows)<250;
            pi_query('UPDATE phrase_import_jobs SET cursor_line=?,added=?,commit_skipped=?,state=? WHERE id=?',[$j['cursor_line'],$j['added'],$j['commit_skipped'],$done?'done':'committing',$id]);
        });
    });
    $j=pi_job($id,$owner);
    if ($j['state']==='done') { pi_cleanup($id); }
}
function pi_cleanup(string $id): void {
    // Terminal jobs retain counters, but no longer need a second copy of the corpus.
    if (is_file(pi_path($id))) unlink(pi_path($id));
    pi_query('DELETE FROM phrase_import_rows WHERE job_id=?',[$id]);
}
function pi_cancel(string $id,string $owner): void {
    pi_transaction(function () use ($id,$owner) {
        $j=pi_job($id,$owner,true);
        if ($j['state']==='done') return;
        pi_query("UPDATE phrase_import_jobs SET state='cancelled' WHERE id=?",[$id]);
    });
    pi_cleanup($id);
}
function pi_status(string $id,string $owner): array {
    $j=pi_job($id,$owner);
    unset($j['owner']);
    $j['issues']=json_decode($j['issues'],true);
    $j['sample']=pi_query('SELECT line_no,text,existing_id FROM phrase_import_rows WHERE job_id=? ORDER BY line_no LIMIT 12',[$id])->fetchAll();
    return $j;
}
function pi_revision(array $p): string {
    return hash('sha256',json_encode([$p['text'],$p['source'],(int)$p['required_times']],JSON_UNESCAPED_UNICODE));
}
function pi_save(int $id, string $revision, string $text,string $source,string $owner): int {
    $text=pi_text($text); $source=pi_source($source);
    if ($text==='') throw new InvalidArgumentException('Сёрникузя абу гижӧма.');
    return pi_lock(function () use ($id,$revision,$text,$source,$owner) {
        return queue_locked(function () use ($id,$revision,$text,$source,$owner) {
            $old=null;
            if ($id>0) {
                $old=pi_query('SELECT * FROM phrases WHERE id=? FOR UPDATE',[$id])->fetch();
                if (!$old || !hash_equals(pi_revision($old),$revision)) throw new InvalidArgumentException('Сёрникузясӧ вежӧма нин. Восьты листбоксӧ выльысь.');
                // Do not invalidate a sentence somebody is currently reading.
                if (db_val('SELECT 1 FROM phrase_reservations WHERE phrase_id=? AND expires_at>NOW() LIMIT 1',[$id])) throw new InvalidArgumentException('Тайӧ сёрникузясӧ ӧні сетӧма лыддьыны. Видччысь аудиоюӧр воӧдз либӧ виччысян кад помӧдз.');
                if ($old['text']===$text) {
                    pi_query('UPDATE phrases SET source=? WHERE id=?',[$source,$id]); return $id;
                }
            }
            if (pi_existing($text)!==null) throw new InvalidArgumentException('Татшӧм текст эм нин базаын. Видзӧдлы сідзжӧ сёрникузяяссӧ, кодъяс оз сетсьыны лыддьыны.');
            // Always create a version: even unrecorded text may exist in a user's chat.
            $quota=$old?(int)$old['required_times']:1;
            pi_query('INSERT INTO phrases(text,source,required_times) VALUES(?,?,?)',[$text,$source,$quota]);
            $new=(int)db()->lastInsertId();
            if ($old) pi_query('UPDATE phrases SET required_times=0 WHERE id=?',[$id]);
            pi_query('INSERT INTO phrase_changes(phrase_id,replacement_id,action,actor) VALUES(?,?,?,?)',[$old?$id:$new,$old?$new:null,$old?'replace':'add',$owner]);
            return $new;
        });
    });
}
function pi_toggle(int $id,string $revision,bool $enable,string $owner): void {
    queue_locked(function () use ($id,$revision,$enable,$owner) {
        $p=pi_query('SELECT * FROM phrases WHERE id=? FOR UPDATE',[$id])->fetch();
        if (!$p || !hash_equals(pi_revision($p),$revision)) throw new InvalidArgumentException('Сёрникузясӧ вежӧма нин. Восьты листбоксӧ выльысь.');
        if (!$enable && db_val('SELECT 1 FROM phrase_reservations WHERE phrase_id=? AND expires_at>NOW() LIMIT 1',[$id])) throw new InvalidArgumentException('Тайӧ сёрникузясӧ ӧні сетӧма лыддьыны. Видччысь аудиоюӧр воӧдз либӧ виччысян кад помӧдз.');
        pi_query('UPDATE phrases SET required_times=? WHERE id=?',[$enable?max(1,(int)$p['required_times']):0,$id]);
        pi_query('INSERT INTO phrase_changes(phrase_id,action,actor) VALUES(?,?,?)',[$id,$enable?'enable':'disable',$owner]);
    });
}

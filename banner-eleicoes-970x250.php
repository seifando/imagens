<?php
declare(strict_types=1);
// Itamaraju Notícias • apuração oficial. PHP 8.1+, cURL, JSON.
const INB_ORIGIN = 'https://eleicoes.itamarajunoticias.com.br/';
const INB_TSE = 'https://resultados.tse.jus.br/oficial/';
const INB_YEARS = [2026, 2024, 2022, 2020, 2018, 2016];
const INB_UFS = ['AC'=>'Acre','AL'=>'Alagoas','AP'=>'Amapá','AM'=>'Amazonas','BA'=>'Bahia','CE'=>'Ceará','DF'=>'Distrito Federal','ES'=>'Espírito Santo','GO'=>'Goiás','MA'=>'Maranhão','MT'=>'Mato Grosso','MS'=>'Mato Grosso do Sul','MG'=>'Minas Gerais','PA'=>'Pará','PB'=>'Paraíba','PR'=>'Paraná','PE'=>'Pernambuco','PI'=>'Piauí','RJ'=>'Rio de Janeiro','RN'=>'Rio Grande do Norte','RS'=>'Rio Grande do Sul','RO'=>'Rondônia','RR'=>'Roraima','SC'=>'Santa Catarina','SP'=>'São Paulo','SE'=>'Sergipe','TO'=>'Tocantins'];
const INB_OFFICES = [1=>'Presidente',3=>'Governador',5=>'Senador',6=>'Deputado federal',7=>'Deputado estadual',8=>'Deputado distrital',11=>'Prefeito',13=>'Vereador'];

function inb_dir(): string {
    $dir = getenv('INB_CACHE_DIR') ?: sys_get_temp_dir().'/inb-'.substr(hash('sha256', __FILE__),0,16);
    if (!is_dir($dir) && !@mkdir($dir,0700,true) && !is_dir($dir)) throw new RuntimeException('Cache sem permissão de escrita. Configure INB_CACHE_DIR fora da pasta pública.');
    return $dir;
}
function inb_path(string $key): string { return inb_dir().'/'.hash('sha256',$key).'.json'; }
function inb_read(string $key): ?array {
    $p = inb_path($key);
    if (!is_file($p)) return null;
    $j = json_decode((string)@file_get_contents($p),true);
    return is_array($j) ? $j : null;
}
function inb_write(string $key,array $data): void {
    $path=inb_path($key); $tmp=tempnam(inb_dir(),'write-');
    if (!$tmp || file_put_contents($tmp,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),LOCK_EX)===false) throw new RuntimeException('Falha ao gravar o cache.');
    @chmod($tmp,0600);
    if (!@rename($tmp,$path)) { @unlink($tmp); throw new RuntimeException('Falha ao substituir o cache.'); }
}
function inb_lock(string $key) {
    $fp=fopen(inb_dir().'/'.hash('sha256',$key).'.lock','c');
    if (!$fp || !flock($fp,LOCK_EX)) throw new RuntimeException('Cache ocupado.');
    return $fp;
}
function inb_unlock($fp): void { flock($fp,LOCK_UN); fclose($fp); }
function inb_date(string $date,string $time='17:00:00'): int {
    $dt=DateTimeImmutable::createFromFormat('!d/m/Y H:i:s', $date.' '.$time,new DateTimeZone('America/Sao_Paulo'));
    $errors=DateTimeImmutable::getLastErrors();
    if (!$dt || ($errors && ($errors['warning_count'] || $errors['error_count']))) throw new RuntimeException('Data oficial inválida.');
    return $dt->getTimestamp();
}
function inb_num($v): float {
    if (!is_scalar($v) || !preg_match('/^\d+(?:[.,]\d+)?$/',(string)$v)) throw new RuntimeException('Valor numérico inválido no arquivo oficial.');
    $n=(float)str_replace(',','.',(string)$v);
    if (!is_finite($n) || $n<0) throw new RuntimeException('Valor oficial fora do intervalo.');
    return $n;
}
function inb_int($v): int { $n=inb_num($v); if (floor($n)!==$n || $n>PHP_INT_MAX) throw new RuntimeException('Contagem inválida.'); return (int)$n; }

/** Cache compartilhado, requisições agrupadas e circuito global; nenhuma URL vem do visitante. */
function inb_fetch(string $path,int $ttl=45): array {
    if (!preg_match('~^(?:comum/config/ele-c\.json|ele(?:2024|2026)/\d{1,6}/(?:config/mun-e\d{6}-cm\.json|dados/(?:br|[a-z]{2})/[a-z]{2}(?:\d{5})?-c\d{4}-e\d{6}-u\.json))$~D',$path)) throw new RuntimeException('Caminho de origem não permitido.');
    $lock=inb_lock('http-'.$path);
    try {
        $key='http-'.$path; $cached=inb_read($key); $now=time();
        if ($cached && ($cached['next']??0)>$now) {
            if (isset($cached['data'])) return ['data'=>$cached['data'],'fetchedAt'=>$cached['at'],'stale'=>isset($cached['error']),'source'=>INB_TSE.$path];
            throw new RuntimeException('Arquivo ainda indisponível no TSE.');
        }
        $global=inb_lock('upstream-global');
        try {
            $breaker=inb_read('upstream-control')??[];
            if (($breaker['until']??0)>$now) throw new RuntimeException('Origem em pausa temporária após falha.');
            $wait=400000-(int)((microtime(true)-($breaker['last']??0))*1000000);
            if ($wait>0) usleep($wait);
            if (!function_exists('curl_init')) throw new RuntimeException('Ative a extensão cURL do PHP.');
            $h=curl_init(INB_TSE.$path); $body='';
            curl_setopt_array($h,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>12,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_USERAGENT=>'ItamarajuNoticias-Eleicoes/1.0',CURLOPT_HTTPHEADER=>['Accept: application/json'],CURLOPT_WRITEFUNCTION=>static function($h,$chunk) use (&$body) { if (strlen($body)+strlen($chunk)>12582912) return 0; $body.=$chunk; return strlen($chunk); }]);
            $ok=curl_exec($h); $status=(int)curl_getinfo($h,CURLINFO_RESPONSE_CODE); curl_close($h);
            $failure=$ok===false || $status>=500 || in_array($status,[403,429],true);
            inb_write('upstream-control',['last'=>microtime(true),'until'=>$failure?$now+(in_array($status,[403,429],true)?660:60):0]);
        } finally { inb_unlock($global); }
        if (!$ok || $status!==200) throw new RuntimeException('Origem indisponível (HTTP '.$status.').');
        $data=json_decode($body,true,512,JSON_THROW_ON_ERROR);
        if (!is_array($data) || (($data['f']??'o')!=='o')) throw new RuntimeException('Arquivo fora do ambiente oficial.');
        $fetched=gmdate('c');
        inb_write($key,['at'=>$fetched,'next'=>$now+$ttl,'data'=>$data]);
        return ['data'=>$data,'fetchedAt'=>$fetched,'stale'=>false,'source'=>INB_TSE.$path];
    } catch (Throwable $e) {
        $cached=$cached??null;
        $failure=['next'=>time()+900,'error'=>true];
        if ($cached && isset($cached['data']) && strtotime($cached['at'])>time()-2592000) {
            inb_write($key,array_merge($cached,$failure));
            return ['data'=>$cached['data'],'fetchedAt'=>$cached['at'],'stale'=>true,'source'=>INB_TSE.$path];
        }
        inb_write($key,$failure);
        throw $e;
    } finally { inb_unlock($lock); }
}
function inb_query(array $input): array {
    foreach (['year','office','uf','municipality','round'] as $k) if (isset($input[$k]) && !is_scalar($input[$k])) throw new InvalidArgumentException('Filtro inválido.');
    $q=['year'=>(int)($input['year']??2026),'office'=>(int)($input['office']??1),'uf'=>strtolower((string)($input['uf']??'br')),'municipality'=>(string)($input['municipality']??''),'round'=>(string)($input['round']??'auto')];
    if (!in_array($q['year'],INB_YEARS,true) || !isset(INB_OFFICES[$q['office']]) || !in_array($q['round'],['auto','1','2'],true) || ($q['uf']!=='br' && !isset(INB_UFS[strtoupper($q['uf'])])) || ($q['municipality']!=='' && !preg_match('/^\d{5}$/D',$q['municipality']))) throw new InvalidArgumentException('Combinação de filtros inválida.');
    if ($q['uf']==='br' && ($q['office']!==1 || $q['municipality']!=='')) throw new InvalidArgumentException('Escolha uma UF para este cargo.');
    if (($q['office']===8 && $q['uf']!=='df') || ($q['office']===7 && $q['uf']==='df') || (in_array($q['office'],[11,13],true) && ($q['municipality']==='' || $q['uf']==='df'))) throw new InvalidArgumentException('Selecione um território compatível com o cargo.');
    if (($q['year']%4===0)!==in_array($q['office'],[11,13],true)) throw new InvalidArgumentException('Cargo não disputado nesta eleição ordinária.');
    return $q;
}
function inb_matches(array $e,array $q): bool {
    if (!in_array((string)($e['tp']??''),['1','3','8'],true)) return false;
    foreach ($e['abr']??[] as $a) {
        if (!in_array(strtolower($a['cd']),['br',$q['uf']],true)) continue;
        if (!in_array((string)$q['office'],array_map('strval',array_column($a['cp']??[],'cd')),true)) continue;
        if (isset($a['mu']) && !in_array($q['municipality'],array_map('strval',array_column($a['mu'],'cd')),true)) continue;
        return true;
    }
    return false;
}
function inb_elections(array $catalog,array $q): array {
    $all=[];
    foreach ($catalog['pl']??[] as $p) if ($p['c']==='ele'.$q['year']) foreach ($p['e']??[] as $e) if (inb_matches($e,$q)) $all[]=['id'=>(string)$e['cd'],'round'=>(int)$e['t'],'date'=>$p['dt'],'pleito'=>(string)$p['cd'],'family'=>$e['sqele']??'','next'=>(string)($e['cdt2']??'')];
    $first=array_values(array_filter($all,fn($e)=>$e['round']===1));
    // Só aceita o segundo turno explicitamente vinculado ao primeiro pelo TSE.
    $all=array_values(array_filter($all,fn($e)=>$e['round']===1 || count(array_filter($first,fn($a)=>$a['next']===$e['id'] && $a['family']===$e['family']))>0));
    usort($all,fn($a,$b)=>$b['round']<=>$a['round']);
    return $all;
}
function inb_normalize(array $r,array $e,array $q,string $source,string $fetchedAt,bool $stale=false,?int $clock=null): array {
    $now=$clock??time(); $release=inb_date($e['date']);
    if (($r['f']??'')!=='o' || ($r['sup']??'n')!=='n' || (string)($r['ele']??'')!==$e['id'] || (int)($r['t']??0)!==$e['round']) throw new RuntimeException('Eleição ou turno incompatível com a consulta.');
    $scope=$q['municipality']?:$q['uf']; $type=$q['municipality']!==''?'mu':($q['uf']==='br'?'br':'uf');
    if (strtolower((string)($r['cdabr']??''))!==$scope || ($r['tpabr']??'')!==$type) throw new RuntimeException('Território oficial incompatível com a consulta.');
    $generated=inb_date((string)($r['dg']??''),(string)($r['hg']??''));
    if ($now<$release || $generated<$release || $generated>$now+300) throw new RuntimeException('Resultado ainda não publicado no período de votação.');
    $carg=null; foreach ($r['carg']??[] as $c) if ((int)$c['cd']===$q['office']) $carg=$c;
    if (!$carg) throw new RuntimeException('Cargo ausente no arquivo oficial.');
    $sections=['total'=>inb_int($r['s']['ts']??null),'counted'=>inb_int($r['s']['st']??null),'percent'=>inb_num($r['s']['pst']??null)];
    if (!$sections['total'] || !$sections['counted'] || $sections['counted']>$sections['total'] || $sections['percent']>100) throw new RuntimeException('Sem totalização válida para este recorte.');
    $candidates=[]; $seen=[];
    foreach ($carg['agr']??[] as $group) foreach ($group['par']??[] as $party) foreach ($party['cand']??[] as $c) {
        $id=(string)($c['sqcand']??''); if (!$id || isset($seen[$id])) throw new RuntimeException('Identificação de candidato inválida.'); $seen[$id]=true;
        $percent=inb_num($c['pvap']??null); if ($percent>100) throw new RuntimeException('Percentual de candidato inválido.');
        $photoUf=$q['office']===1?'br':$q['uf'];
        $candidates[]=['id'=>$id,'number'=>(string)$c['n'],'name'=>(string)($c['nmu']??$c['nm']),'party'=>(string)$party['sg'],'votes'=>inb_int($c['vap']??null),'percent'=>$percent,'status'=>(string)($c['st']??'Não informado'),'destination'=>(string)($c['dvt']??''),'photo'=>INB_TSE.'ele'.$q['year'].'/'.$e['id'].'/fotos/'.$photoUf.'/'.rawurlencode($id).'.jpeg'];
    }
    if (!$candidates) throw new RuntimeException('Arquivo sem candidatos.');
    usort($candidates,fn($a,$b)=>($b['votes']<=>$a['votes'])?:strcmp($a['id'],$b['id']));
    return ['ok'=>true,'query'=>$q,'election'=>$e['id'],'pleito'=>$e['pleito'],'round'=>$e['round'],'year'=>$q['year'],'sections'=>$sections,'candidates'=>$candidates,'votes'=>['total'=>inb_int($r['v']['tv']??null),'valid'=>inb_int($r['v']['vv']??null),'white'=>inb_int($r['v']['vb']??null),'null'=>inb_int($r['v']['tvn']??null)],'electorate'=>inb_int($r['e']['te']??null),'abstentions'=>inb_int($r['e']['a']??null),'final'=>($r['and']??'')==='f','generatedAt'=>gmdate('c',$generated),'fetchedAt'=>$fetchedAt,'source'=>$source,'generation'=>(string)($r['idg']??''),'stale'=>$stale];
}
function inb_municipalities(int $year,string $uf): array {
    if (!in_array($year,[2024,2026],true)) throw new RuntimeException('Municípios históricos dependem da importação oficial descrita no guia.');
    if (!isset(INB_UFS[strtoupper($uf)])) throw new InvalidArgumentException('UF inválida.');
    $catalog=inb_fetch('comum/config/ele-c.json',300); $q=['year'=>$year,'office'=>$year%4===0?11:1,'uf'=>$uf,'municipality'=>'','round'=>'1'];
    $es=inb_elections($catalog['data'],$q); $e=null; foreach ($es as $a) if ($a['round']===1) $e=$a;
    if (!$e) throw new RuntimeException('Configuração oficial não disponível.');
    $id=str_pad($e['id'],6,'0',STR_PAD_LEFT);
    $response=inb_fetch('ele'.$year.'/'.$e['id'].'/config/mun-e'.$id.'-cm.json',86400);
    foreach ($response['data']['abr']??[] as $a) if (strtolower($a['cd'])===$uf) return ['ok'=>true,'items'=>array_map(fn($m)=>['code'=>(string)$m['cd'],'name'=>$m['nm'],'ibge'=>$m['cdi']??''],$a['mu']??[]),'stale'=>$response['stale'],'source'=>$response['source']];
    return ['ok'=>true,'items'=>[],'stale'=>$response['stale']];
}
function inb_result(array $q,?callable $fetch=null,?int $clock=null): array {
    if (!in_array($q['year'],[2024,2026],true)) throw new RuntimeException('Arquivo histórico ainda não importado. Consulte o guia para 2016–2022.');
    $fetch=$fetch??'inb_fetch'; $now=$clock??time();
    $key='snapshot-'.implode('-',[$q['year'],$q['office'],$q['uf'],$q['municipality'],$q['round']]);
    $lock=inb_lock($key);
    try {
        $previous=inb_read($key); $errors=[];
        try {
            $cat=$fetch('comum/config/ele-c.json',300);
            $elections=inb_elections($cat['data'],$q);
            if ($q['municipality']!=='') {
                $municipalities=inb_municipalities($q['year'],$q['uf']);
                if (!in_array($q['municipality'],array_column($municipalities['items'],'code'),true)) throw new InvalidArgumentException('Município não pertence à UF selecionada.');
            }
            foreach ($elections as $e) {
                if ($q['round']!=='auto' && $e['round']!==(int)$q['round']) continue;
                if ($q['round']==='auto' && $previous && $e['round']<$previous['round']) continue;
                if ($now<inb_date($e['date'])) continue;
                try {
                    $p=str_pad($e['id'],6,'0',STR_PAD_LEFT); $office=str_pad((string)$q['office'],4,'0',STR_PAD_LEFT);
                    $path='ele'.$q['year'].'/'.$e['id'].'/dados/'.$q['uf'].'/'.$q['uf'].$q['municipality'].'-c'.$office.'-e'.$p.'-u.json';
                    $raw=$fetch($path);
                    $snapshot=inb_normalize($raw['data'],$e,$q,$raw['source'],$raw['fetchedAt'],$raw['stale']||$cat['stale'],$now);
                    if ($previous && $previous['round']===$snapshot['round'] && strtotime($snapshot['generatedAt'])<strtotime($previous['generatedAt'])) throw new RuntimeException('Origem retornou versão anterior.');
                    $hasSecond=count(array_filter($elections,fn($item)=>$item['round']===2))>0;
                    $snapshot['roundNotice']=$q['round']==='auto'?($snapshot['round']===2?'Turno mais recente com totalização oficial válida.':($hasSecond?'Exibindo o 1º turno. A troca ocorrerá após totalização oficial válida do 2º turno neste recorte.':'Exibindo o 1º turno. Não há segundo turno cadastrado para este cargo e território.')):'Consulta manual ao '.$snapshot['round'].'º turno.';
                    if ($snapshot['stale']) $snapshot['warning']='Falha na consulta ao TSE. Exibindo a última cópia disponível; confira a data de geração.';
                    inb_write($key,$snapshot); return $snapshot;
                } catch (Throwable $e) { $errors[]=$e->getMessage(); }
            }
        } catch (InvalidArgumentException $e) { throw $e; }
        catch (Throwable $e) { $errors[]=$e->getMessage(); }
        if ($previous) {
            $previous['stale']=true; $previous['warning']='Atualização temporariamente indisponível. Mantida a última totalização válida deste recorte e turno.';
            return $previous;
        }
        throw new RuntimeException($errors ? end($errors) : 'Ainda não há totalização oficial válida para o turno e recorte selecionados.');
    } finally { inb_unlock($lock); }
}

if (defined('INB_LIBRARY_ONLY') && INB_LIBRARY_ONLY) return;

function inb_escape($value): string { return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function inb_endpoint(array $config): string {
    if (!empty($config['endpoint'])) {
        $url=(string)$config['endpoint'];
        if (str_starts_with($url,'/') && !str_starts_with($url,'//')) return $url;
        $host=parse_url($url,PHP_URL_HOST);
        $current=parse_url('http://'.($_SERVER['HTTP_HOST']??'localhost'),PHP_URL_HOST);
        if (parse_url($url,PHP_URL_SCHEME)==='https' && $host && $host===$current) return $url;
        throw new InvalidArgumentException('O endpoint precisa pertencer à mesma origem do site.');
    }
    if (defined('WP_CONTENT_DIR') && function_exists('content_url')) {
        $content=realpath(WP_CONTENT_DIR); $file=realpath(__FILE__);
        if ($content && $file && str_starts_with(str_replace('\\','/',$file),rtrim(str_replace('\\','/',$content),'/').'/')) return content_url(substr(str_replace('\\','/',$file),strlen(str_replace('\\','/',$content))));
    }
    $docroot=realpath($_SERVER['DOCUMENT_ROOT']??''); $file=realpath(__FILE__);
    if ($docroot && $file) {
        $base=rtrim(str_replace('\\','/',$docroot),'/');$file=str_replace('\\','/',$file);
        if (str_starts_with($file,$base.'/')) return substr($file,strlen($base));
    }
    if (realpath($_SERVER['SCRIPT_FILENAME']??'')===realpath(__FILE__)) return (string)($_SERVER['SCRIPT_NAME']??'/faixa-eleicoes-itamaraju.php');
    throw new RuntimeException('Informe endpoint na configuração da faixa; a URL pública do arquivo não pôde ser identificada.');
}

// A API só responde quando este arquivo é acessado diretamente. Um include no tema nunca interrompe o WordPress.
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===realpath(__FILE__) && isset($_GET['inb_api'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    try {
        if (!is_string($_GET['inb_api'])) throw new InvalidArgumentException('Rota inválida.');
        if ($_GET['inb_api']==='results') { $input=$_GET;$input['round']='auto';$answer=inb_result(inb_query($input)); }
        elseif ($_GET['inb_api']==='municipalities' && is_scalar($_GET['year']??2026) && is_scalar($_GET['uf']??'ba')) $answer=inb_municipalities((int)($_GET['year']??2026),strtolower((string)($_GET['uf']??'ba')));
        else throw new InvalidArgumentException('Rota inválida.');
        if (isset($answer['candidates'])) { $answer['candidateCount']=count($answer['candidates']);$answer['candidates']=array_slice($answer['candidates'],0,2); }
        echo json_encode($answer,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    } catch (InvalidArgumentException $e) { http_response_code(400);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE); }
    catch (Throwable $e) { http_response_code(503);echo json_encode(['ok'=>false,'error'=>'A totalização oficial deste recorte está temporariamente indisponível.','detail'=>$e->getMessage()],JSON_UNESCAPED_UNICODE); }
    exit;
}

// Closure para não alterar variáveis do template que faz o include.
(static function(array $config): void {
    try {
        $query=inb_query(['year'=>$config['ano']??2026,'office'=>$config['cargo']??1,'uf'=>$config['uf']??'br','municipality'=>$config['municipio']??'','round'=>'auto']);
        $endpoint=inb_endpoint($config);
    } catch (Throwable $e) {
        echo '<p class="inb-configuration-error">Faixa eleitoral: '.inb_escape($e->getMessage()).' <a href="'.INB_ORIGIN.'">Acompanhar apuração</a></p>';return;
    }
    $presets=[
        ['label'=>'Presidente · Brasil','year'=>2026,'office'=>1,'uf'=>'br','municipality'=>''],
        ['label'=>'Governador · Bahia','year'=>2026,'office'=>3,'uf'=>'ba','municipality'=>''],
        ['label'=>'Senador · Bahia','year'=>2026,'office'=>5,'uf'=>'ba','municipality'=>''],
        ['label'=>'Deputado federal · Bahia','year'=>2026,'office'=>6,'uf'=>'ba','municipality'=>''],
        ['label'=>'Deputado estadual · Bahia','year'=>2026,'office'=>7,'uf'=>'ba','municipality'=>''],
        ['label'=>'Deputado distrital · DF','year'=>2026,'office'=>8,'uf'=>'df','municipality'=>''],
        ['label'=>'Prefeito · Itamaraju · 2024','year'=>2024,'office'=>11,'uf'=>'ba','municipality'=>'36137'],
        ['label'=>'Vereador · Itamaraju · 2024','year'=>2024,'office'=>13,'uf'=>'ba','municipality'=>'36137'],
    ];
    if (isset($config['opcoes']) && is_array($config['opcoes'])) {
        $presets=[];
        foreach ($config['opcoes'] as $item) {
            try { $checked=inb_query(['year'=>$item['ano']??2026,'office'=>$item['cargo']??1,'uf'=>$item['uf']??'br','municipality'=>$item['municipio']??'','round'=>'auto']);$presets[]=array_merge($checked,['label'=>(string)($item['rotulo']??INB_OFFICES[$checked['office']])]); }
            catch (Throwable $e) { /* Configuração inválida não produz uma consulta à origem. */ }
        }
    }
    $selected=null;
    foreach ($presets as $i=>$preset) if ($preset['year']===$query['year'] && $preset['office']===$query['office'] && $preset['uf']===$query['uf'] && $preset['municipality']===$query['municipality']) $selected=$i;
    if ($selected===null) { $selected=count($presets);$presets[]=array_merge($query,['label'=>(string)($config['rotulo']??INB_OFFICES[$query['office']].' · '.strtoupper($query['uf']))]); }
    $queryLabel=$presets[$selected]['label'];
    $seed=null;
    try {
        $key='snapshot-'.implode('-',[$query['year'],$query['office'],$query['uf'],$query['municipality'],'auto']);$seed=inb_read($key);
        if ($seed) { $seed['candidateCount']=count($seed['candidates']);$seed['candidates']=array_slice($seed['candidates'],0,2);$seed['stale']=true;$seed['warning']='Cópia em cache. Confirmando a atualização oficial…'; }
    } catch (Throwable $e) { /* O restante do portal continua funcionando se o cache não estiver disponível. */ }
    $id='inb-'.bin2hex(random_bytes(5));
    $nonce=isset($config['nonce'])?' nonce="'.inb_escape($config['nonce']).'"':'';
    $client=['endpoint'=>$endpoint,'presets'=>$presets,'selected'=>$selected,'seed'=>$seed,'interval'=>60000];
?>
<section class="inb-widget" id="<?=inb_escape($id)?>" aria-label="Apuração eleitoral — Itamaraju Notícias">
<style<?=$nonce?>>.inb-widget{--inb-ink:#17353b;--inb-muted:#72828a;--inb-line:#e1e8ed;--inb-blue:#223d89;--inb-red:#c63b48;--inb-green:#13796b;box-sizing:border-box;display:block;max-width:1180px;width:100%;margin:24px auto;background:#fff;border:1px solid #dce4e9;border-radius:10px;overflow:hidden;color:var(--inb-ink);font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.4;text-align:left;box-shadow:0 6px 22px #18333d05}.inb-widget *{box-sizing:border-box}.inb-widget a{color:inherit;text-decoration:none!important;box-shadow:none}.inb-widget [hidden]{display:none!important}.inb-widget h2,.inb-widget h3,.inb-widget p{font-family:inherit;padding:0;margin:0;font-weight:400;line-height:1.4}.inb-widget a:focus-visible,.inb-widget select:focus-visible{outline:3px solid #d4a34e;outline-offset:-3px}.inb-widget .inb-toolbar{display:flex;align-items:center;justify-content:space-between;gap:20px;padding:16px 26px;border-top:3px solid #193941;border-bottom:1px solid var(--inb-line);background:#fff}.inb-widget .inb-brand{display:flex;align-items:center;gap:10px;flex-shrink:0}.inb-widget .inb-mark{font-size:40px;line-height:.9;letter-spacing:-5px;font-weight:800;margin-right:5px}.inb-widget .inb-mark>span{color:#e6a13e}.inb-widget .inb-brand>span:last-child{font-size:11px;letter-spacing:1.8px;line-height:1.6}.inb-widget .inb-brand strong{font-size:12px;font-weight:800}.inb-widget .inb-brand small{display:block;color:#758890;font-size:8px;font-weight:400;letter-spacing:1.6px}.inb-widget .inb-picker{margin:0;padding:0;display:flex;align-items:center;gap:12px;font-size:8px;color:#73858d;letter-spacing:1px;font-weight:600}.inb-widget .inb-picker select{font-family:inherit;font-size:11px;line-height:1.4;letter-spacing:0;min-width:215px;max-width:100%;height:36px;padding:7px 30px 7px 11px;color:var(--inb-ink);border:1px solid #d9e1e8;border-radius:4px;background:#fff;cursor:pointer}.inb-widget .inb-main{display:block;padding:17px 26px 0;background:linear-gradient(110deg,#f8fafc 0%,#fff 47%,#fff 56%,#faf8f8 100%);transition:background-color .15s}.inb-widget .inb-context{display:flex;justify-content:space-between;gap:12px;color:#788b94;font-size:8px;letter-spacing:1px;font-weight:600;text-transform:uppercase}.inb-widget .inb-context>span:first-child{display:flex;align-items:center;gap:6px}.inb-widget .inb-dot{width:5px;height:5px;display:inline-block;background:#399889;border-radius:50%}.inb-widget .inb-duel{display:grid;grid-template-columns:minmax(0,1fr) 230px minmax(0,1fr);align-items:center;gap:29px;min-height:198px;padding:19px 0 21px}.inb-widget .inb-candidate{display:grid;grid-template-columns:104px minmax(0,1fr);column-gap:17px;position:relative;align-items:center;min-width:0}.inb-widget .inb-photo-wrap{grid-row:1;position:relative;width:104px;height:120px;border-radius:8px;background:#eaf0f5;overflow:hidden;align-self:end}.inb-widget .inb-photo{display:block;width:100%;height:100%;object-fit:cover;object-position:center 15%;filter:saturate(.85)}.inb-widget .inb-avatar{display:flex;align-items:center;justify-content:center;height:100%;width:100%;color:#96a5b0;font-size:36px;background:#e9eff3}.inb-widget .inb-rank{font-size:7px;letter-spacing:1.1px;color:#8b9aa3;display:block;margin-bottom:6px}.inb-widget .inb-person h3{font-size:18px;letter-spacing:-.4px;font-weight:750;line-height:1.2;margin:0 0 6px;color:var(--inb-ink);overflow-wrap:anywhere}.inb-widget .inb-party{display:flex;gap:6px;align-items:center;flex-wrap:wrap;font-size:9px;color:#667e8b;min-height:17px}.inb-widget .inb-party>span:first-child{font-weight:700}.inb-widget .inb-candidate-status{font-size:8px;letter-spacing:0;background:#edf2f4;padding:2px 5px;border-radius:3px;max-width:100%}.inb-widget .inb-percent{font-size:31px;letter-spacing:-1px;line-height:1.1;display:block;font-weight:750;color:var(--inb-party-color,var(--inb-blue));margin:9px 0 3px;font-variant-numeric:tabular-nums}.inb-widget .inb-candidate-1 .inb-percent{color:var(--inb-party-color,var(--inb-red))}.inb-widget .inb-votes{font-size:10px;color:#83939c;font-variant-numeric:tabular-nums}.inb-widget .inb-vote-bar{appearance:none;height:5px;width:100%;border:0;background:#e7edf4;border-radius:3px;grid-column:1/-1;overflow:hidden;margin-top:14px;display:block}.inb-widget .inb-vote-bar::-webkit-progress-bar{background:#e7edf4}.inb-widget .inb-vote-bar::-webkit-progress-value{background:var(--inb-party-color,var(--inb-blue));border-radius:3px}.inb-widget .inb-vote-bar::-moz-progress-bar{background:var(--inb-party-color,var(--inb-blue));border-radius:3px}.inb-widget .inb-candidate-1 .inb-vote-bar{background:#f1e8e8}.inb-widget .inb-candidate-1 .inb-vote-bar::-webkit-progress-bar{background:#f1e8e8}.inb-widget .inb-candidate-1 .inb-vote-bar::-webkit-progress-value{background:var(--inb-party-color,var(--inb-red))}.inb-widget .inb-candidate-1 .inb-vote-bar::-moz-progress-bar{background:var(--inb-party-color,var(--inb-red))}.inb-widget .inb-candidate-1 .inb-photo-wrap{grid-column:2}.inb-widget .inb-candidate-1{grid-template-columns:minmax(0,1fr) 104px;text-align:right}.inb-widget .inb-candidate-1 .inb-person{grid-column:1;grid-row:1}.inb-widget .inb-candidate-1 .inb-party{justify-content:flex-end}.inb-widget .inb-center{text-align:center;border-left:1px solid #e7ecef;border-right:1px solid #e7ecef;padding:0 17px}.inb-widget .inb-special{font-size:7px;letter-spacing:1.8px;color:#83959b;display:block;margin-bottom:4px}.inb-widget .inb-center h2{font-size:27px;font-weight:800;letter-spacing:-1.3px;line-height:1.15;color:var(--inb-ink);white-space:nowrap;margin:0 0 8px}.inb-widget .inb-center h2>span{color:#1f8b7e;font-weight:400}.inb-widget .inb-round{font-size:8px;letter-spacing:1px;font-weight:700;background:#e6f0ed;color:#397467;border-radius:3px;padding:4px 8px;display:inline-block;margin-bottom:7px}.inb-widget .inb-center>strong{font-size:9px;letter-spacing:1.6px;text-transform:uppercase;display:block;font-weight:600;color:#75888d}.inb-widget .inb-counted{display:flex;flex-direction:column;gap:1px;margin-top:10px}.inb-widget .inb-counted b{font-size:17px;letter-spacing:-.4px;font-variant-numeric:tabular-nums;font-weight:700}.inb-widget .inb-counted span{font-size:8px;color:#89979c}.inb-widget .inb-final{font-size:8px;color:#4d8c7c;display:block;margin-top:5px}.inb-widget .inb-call{display:flex;justify-content:space-between;align-items:center;gap:12px;border-top:1px solid #e0e6ea;padding:12px 0;font-size:9px;color:#7f919a}.inb-widget .inb-call strong{font-weight:700;color:#294d59;font-size:11px;white-space:nowrap}.inb-widget .inb-call strong span{margin-left:16px;font-size:16px}.inb-widget .inb-footer{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:10px 26px;background:#f4f7f8;border-top:1px solid var(--inb-line);font-size:8px;color:#84959e}.inb-widget .inb-footer p{font-size:8px;line-height:1.6;flex:1}.inb-widget .inb-footer a{font-size:9px;color:#537180;white-space:nowrap}.inb-widget .inb-feedback{padding:8px 26px;border-top:1px solid #e6ecee;color:#85969d;background:#fff;font-size:9px;line-height:1.6}.inb-widget .inb-feedback.inb-warning{background:#fff7e8;color:#8a652c}.inb-widget .inb-feedback.inb-error{background:#fff2ef;color:#a25340}.inb-widget .inb-noscript{padding:12px 26px;font-size:11px;color:#835f32;background:#fff6e7}.inb-widget .inb-noscript a{text-decoration:underline!important}.inb-widget.inb-busy .inb-dot{background:#b29564}.inb-widget.inb-busy .inb-picker{opacity:.8}.inb-widget .inb-main:hover .inb-call strong{color:#13796b}
@media(max-width:1000px){.inb-widget .inb-duel{grid-template-columns:minmax(0,1fr) 185px minmax(0,1fr);gap:19px}.inb-widget .inb-candidate{grid-template-columns:76px minmax(0,1fr);column-gap:12px}.inb-widget .inb-candidate-1{grid-template-columns:minmax(0,1fr) 76px}.inb-widget .inb-photo-wrap{width:76px;height:98px}.inb-widget .inb-person h3{font-size:15px}.inb-widget .inb-percent{font-size:28px}.inb-widget .inb-center h2{font-size:23px}.inb-widget .inb-center{padding:0 11px}.inb-widget .inb-picker>span{display:none}.inb-widget .inb-toolbar{padding:15px 20px}.inb-widget .inb-main{padding:16px 20px 0}.inb-widget .inb-context{font-size:7px}.inb-widget .inb-footer{padding:10px 20px}.inb-widget .inb-feedback{padding:8px 20px}.inb-widget .inb-all-note{display:none}}
@media(max-width:650px){.inb-widget{margin:16px auto;border-radius:7px}.inb-widget .inb-toolbar{gap:12px;padding:13px 15px}.inb-widget .inb-brand{gap:7px}.inb-widget .inb-mark{font-size:31px;letter-spacing:-4px;margin-right:3px}.inb-widget .inb-brand>span:last-child{font-size:8px;letter-spacing:1px;line-height:1.5}.inb-widget .inb-brand strong{font-size:9px;display:block;letter-spacing:1.5px}.inb-widget .inb-brand small{display:none}.inb-widget .inb-picker{min-width:0;flex-shrink:1;max-width:57%}.inb-widget .inb-picker select{min-width:0;width:100%;font-size:9px;height:33px;padding:6px 19px 6px 8px}.inb-widget .inb-main{padding:14px 15px 0}.inb-widget .inb-context{font-size:6px;letter-spacing:.7px;gap:8px}.inb-widget .inb-context>span:last-child{text-align:right;max-width:47%}.inb-widget .inb-duel{grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:21px 18px;padding:17px 0 15px;min-height:0}.inb-widget .inb-center{grid-column:1/-1;grid-row:1;border:0;border-bottom:1px solid #e3e9ec;padding:0 0 13px;display:grid;grid-template-columns:1fr 1fr;gap:3px 10px;text-align:left;align-items:center}.inb-widget .inb-special{grid-column:1;font-size:6px;letter-spacing:1px;margin:0}.inb-widget .inb-center h2{grid-row:2;grid-column:1;font-size:25px;margin:2px 0 4px}.inb-widget .inb-center>strong{grid-row:3;grid-column:1;font-size:8px;letter-spacing:1px}.inb-widget .inb-round{grid-row:1;grid-column:2;justify-self:end;font-size:7px;padding:3px 5px;margin:0}.inb-widget .inb-counted{grid-row:2;grid-column:2;margin:0;text-align:right;gap:0}.inb-widget .inb-counted b{font-size:19px}.inb-widget .inb-counted span{font-size:7px}.inb-widget .inb-final{grid-column:2;grid-row:3;margin:0;font-size:7px;text-align:right}.inb-widget .inb-candidate{grid-template-columns:1fr;gap:0;text-align:left;align-self:start}.inb-widget .inb-candidate-1{grid-template-columns:1fr;text-align:right}.inb-widget .inb-photo-wrap{grid-column:1;grid-row:1;width:66px;height:73px;align-self:start;border-radius:6px;margin:0 0 10px}.inb-widget .inb-candidate-1 .inb-photo-wrap{grid-column:1;justify-self:end}.inb-widget .inb-candidate-1 .inb-person,.inb-widget .inb-person{grid-column:1;grid-row:2}.inb-widget .inb-person h3{font-size:14px;min-height:34px;line-height:1.25;margin:0 0 3px}.inb-widget .inb-rank{font-size:6px;letter-spacing:.7px;margin-bottom:5px}.inb-widget .inb-party{font-size:8px;gap:5px;min-height:19px}.inb-widget .inb-candidate-status{font-size:7px;padding:2px 4px;line-height:1.4}.inb-widget .inb-percent{font-size:27px;margin-top:7px}.inb-widget .inb-votes{font-size:9px}.inb-widget .inb-vote-bar{margin-top:10px;height:4px}.inb-widget .inb-call{padding:11px 0;font-size:8px;gap:9px}.inb-widget .inb-call>span{max-width:48%;font-size:7px;line-height:1.6}.inb-widget .inb-call strong{font-size:9px}.inb-widget .inb-call strong span{margin-left:8px;font-size:14px}.inb-widget .inb-footer{padding:9px 15px;align-items:start;gap:10px}.inb-widget .inb-footer p{font-size:7px;line-height:1.7}.inb-widget .inb-footer a{font-size:8px;padding-top:2px}.inb-widget .inb-feedback{padding:8px 15px;font-size:8px}.inb-widget .inb-noscript{padding:12px 15px;font-size:10px}}
@media(prefers-reduced-motion:reduce){.inb-widget *{transition:none!important}}

.inb-widget [data-inb-party="PL"]{--inb-party-color:#223d89}.inb-widget [data-inb-party="PT"]{--inb-party-color:#c63b48}.inb-widget [data-inb-party="UNIÃO"]{--inb-party-color:#3866a1}.inb-widget [data-inb-party="PSD"]{--inb-party-color:#4763a0}.inb-widget [data-inb-party="PSB"]{--inb-party-color:#b37d17}.inb-widget [data-inb-party="NOVO"]{--inb-party-color:#ce6924}.inb-widget [data-inb-party="MDB"]{--inb-party-color:#378354}

@media(max-width:650px){.inb-widget .inb-center{grid-template-columns:minmax(0,1fr) minmax(0,1fr)}.inb-widget .inb-center h2{white-space:normal;font-size:23px;letter-spacing:-1px}}
</style>
<div class="inb-toolbar"><div class="inb-brand"><span class="inb-mark" aria-hidden="true">in<span>.</span></span><span>ITAMARAJU <strong>NOTÍCIAS</strong><small>CENTRAL DAS ELEIÇÕES</small></span></div><label class="inb-picker"><span>CONSULTAR RESULTADOS</span><select data-inb="preset" aria-label="Cargo e território da apuração"><?php foreach ($presets as $i=>$p):?><option value="<?=$i?>"<?=$selected===$i?' selected':''?>><?=inb_escape($p['label'])?></option><?php endforeach;?></select></label></div>
<a class="inb-main" href="https://eleicoes.itamarajunoticias.com.br/" aria-label="Acompanhar a apuração no Itamaraju Notícias" data-inb="main">
<div class="inb-context"><span><span class="inb-dot" aria-hidden="true"></span> RESULTADOS OFICIAIS · TSE</span><span data-inb="scope"><?=inb_escape($queryLabel)?></span></div>
<div class="inb-duel">
<?php foreach ([0,1] as $position):?>
<article class="inb-candidate inb-candidate-<?=$position?>" data-inb-candidate="<?=$position?>"><div class="inb-photo-wrap"><img class="inb-photo" data-inb="photo" alt="" width="104" height="120" hidden><span class="inb-avatar" data-inb="avatar" aria-hidden="true">—</span></div><div class="inb-person"><span class="inb-rank"><?=$position+1?>ª MAIOR VOTAÇÃO</span><h3 data-inb="name">Aguardando dados</h3><p class="inb-party"><span data-inb="party">—</span><span class="inb-candidate-status" data-inb="status"></span></p><strong class="inb-percent" data-inb="percent">—</strong><p class="inb-votes" data-inb="votes">Consultando o TSE</p></div><progress class="inb-vote-bar" data-inb="bar" max="100" value="0" aria-label="Percentual do candidato"></progress></article>
<?php if ($position===0):?><div class="inb-center"><span class="inb-special">COBERTURA ESPECIAL</span><h2>ELEIÇÕES <span data-inb="year"><?=$query['year']?></span></h2><span class="inb-round" data-inb="round">VERIFICANDO TURNO</span><strong data-inb="office"><?=inb_escape(INB_OFFICES[$query['office']])?></strong><div class="inb-counted"><b data-inb="counted">—</b><span>das seções totalizadas</span></div><span class="inb-final" data-inb="final">Aguardando totalização</span></div><?php endif;?>
<?php endforeach;?>
</div>
<div class="inb-call"><span>Os dois candidatos com mais votos neste recorte. <span class="inb-all-note">Confira a lista completa no portal.</span></span><strong>Acompanhar apuração <span aria-hidden="true">→</span></strong></div>
</a>
<div class="inb-footer"><p data-inb="updated">Fonte: TSE · Consulta em andamento</p><a href="https://eleicoes.itamarajunoticias.com.br/">Todos os resultados ↗</a></div>
<p class="inb-feedback" data-inb="feedback" role="status" aria-live="polite">Consultando a totalização oficial…</p>
<noscript><p class="inb-noscript">Ative o JavaScript para atualização automática ou <a href="https://eleicoes.itamarajunoticias.com.br/">acompanhe a apuração no portal</a>.</p></noscript>
<script type="application/json" data-inb-config<?=$nonce?>><?=json_encode($client,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR)?></script>
<script<?=$nonce?>>(()=>{
  'use strict';
  const script=document.currentScript,root=script.closest('.inb-widget');if(!root)return;
  const cfg=JSON.parse(root.querySelector('[data-inb-config]').textContent),$=name=>root.querySelector('[data-inb="'+name+'"]');
  const nf=new Intl.NumberFormat('pt-BR'),pf=new Intl.NumberFormat('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2}),df=new Intl.DateTimeFormat('pt-BR',{dateStyle:'short',timeStyle:'medium',timeZone:'America/Sao_Paulo'});
  const officeNames={1:'Presidente',3:'Governador',5:'Senador',6:'Deputado federal',7:'Deputado estadual',8:'Deputado distrital',11:'Prefeito',13:'Vereador'};
  let selected=cfg.selected,current=null,requestId=0,controller=null,busy=false,next=Date.now()+cfg.interval;
  function feedback(text,state=''){const p=$('feedback');p.className='inb-feedback'+(state?' inb-'+state:'');p.textContent=text;}
  function clear(){current=null;for(const article of root.querySelectorAll('[data-inb-candidate]')){const at=n=>article.querySelector('[data-inb="'+n+'"]');at('photo').hidden=true;at('photo').removeAttribute('src');at('avatar').hidden=false;at('name').textContent='Aguardando dados';at('party').textContent='—';at('status').textContent='';at('percent').textContent='—';at('votes').textContent='Consultando o TSE';at('bar').value=0;} $('round').textContent='VERIFICANDO TURNO';$('counted').textContent='—';$('final').textContent='Aguardando totalização';$('updated').textContent='Fonte: TSE · Consulta em andamento';}
  function label(){const p=cfg.presets[selected];$('scope').textContent=p.label;$('year').textContent=p.year;$('office').textContent=officeNames[p.office];$('main').setAttribute('aria-label','Acompanhar '+p.label+' no Itamaraju Notícias');}
  function matches(data,preset){const q=data.query;return q&&Number(q.year)===Number(preset.year)&&Number(q.office)===Number(preset.office)&&q.uf===preset.uf&&q.municipality===preset.municipality;}
  function render(data){
    if(!matches(data,cfg.presets[selected]))throw Error('A origem retornou outro recorte.');
    if(current&&(data.round<current.round||(data.round===current.round&&Date.parse(data.generatedAt)<Date.parse(current.generatedAt))))throw Error('Mantida a totalização mais recente já recebida.');
    current=data;
    root.querySelectorAll('[data-inb-candidate]').forEach((article,index)=>{
      const c=data.candidates[index],at=n=>article.querySelector('[data-inb="'+n+'"]');
      if(!c){at('name').textContent='Sem outro candidato';at('party').textContent='';at('status').textContent='';at('percent').textContent='—';at('votes').textContent='Neste recorte';at('bar').value=0;at('photo').hidden=true;at('avatar').hidden=false;return;}
      article.dataset.inbParty=c.party;at('name').textContent=c.name;at('party').textContent=c.party+' · '+c.number;at('status').textContent=c.status;at('status').title=c.destination||c.status;at('percent').textContent=pf.format(c.percent)+'%';at('votes').textContent=nf.format(c.votes)+' votos';at('bar').value=c.percent;at('bar').setAttribute('aria-label',c.name+': '+pf.format(c.percent)+'%, conforme o TSE');
      const img=at('photo'),fallback=at('avatar');fallback.textContent=c.name.split(' ').slice(0,2).map(s=>s[0]).join('');img.alt='Foto de '+c.name;
      if(img.getAttribute('src')!==c.photo){img.hidden=false;fallback.hidden=true;img.src=c.photo;}else if(img.complete&&img.naturalWidth){img.hidden=false;fallback.hidden=true;}
    });
    $('round').textContent=data.round+'º TURNO';$('counted').textContent=pf.format(data.sections.percent)+'%';$('final').textContent=data.stale?'Cópia em cache':data.final?'Totalização finalizada':'Totalização em andamento';
    $('main').setAttribute('aria-label',cfg.presets[selected].label+', '+data.round+'º turno. '+data.candidates.slice(0,2).map(c=>c.name+': '+pf.format(c.percent)+'%, '+nf.format(c.votes)+' votos').join('. ')+'. '+pf.format(data.sections.percent)+'% das seções totalizadas. Acompanhar apuração no Itamaraju Notícias.');
    $('updated').textContent='Fonte: TSE · Gerado: '+df.format(new Date(data.generatedAt))+' · Coletado: '+df.format(new Date(data.fetchedAt))+' · Brasília';
    feedback(data.warning||data.roundNotice,data.stale?'warning':'');
  }
  for(const article of root.querySelectorAll('[data-inb-candidate]'))article.querySelector('[data-inb="photo"]').addEventListener('error',()=>{article.querySelector('[data-inb="photo"]').hidden=true;article.querySelector('[data-inb="avatar"]').hidden=false;});
  async function load(){
    const id=++requestId;controller?.abort();const ownController=new AbortController();controller=ownController;busy=true;root.classList.add('inb-busy');root.setAttribute('aria-busy','true');
    const timeout=setTimeout(()=>ownController.abort(),45000),preset=cfg.presets[selected];
    try{
      const endpoint=new URL(cfg.endpoint,location.origin);if(endpoint.origin!==location.origin)throw Error('Configure o endpoint na mesma origem do site.');
      endpoint.searchParams.set('inb_api','results');for(const key of ['year','office','uf','municipality'])endpoint.searchParams.set(key,preset[key]);
      const response=await fetch(endpoint,{signal:ownController.signal,cache:'no-store',credentials:'same-origin'});const contentType=response.headers.get('content-type')||'';if(!contentType.includes('application/json'))throw Error('O arquivo PHP da faixa não está acessível como endpoint JSON.');
      const data=await response.json();if(!response.ok||!data.ok)throw Error(data.detail||data.error||'Totalização temporariamente indisponível.');if(id!==requestId)return;render(data);next=Date.now()+cfg.interval;
    }catch(e){if(id!==requestId)return;const msg=e.name==='AbortError'?'A consulta excedeu o tempo de espera.':e.message;if(current){feedback('Atualização indisponível. Mantida a última cópia válida deste recorte e turno. Confira a data de geração.','warning');$('final').textContent='Cópia preservada';}else{feedback(msg+' Acesse o portal para acompanhar a apuração.','error');$('round').textContent='SEM DADOS VÁLIDOS';$('final').textContent='Consulta indisponível';}next=Date.now()+300000;}
    finally{clearTimeout(timeout);if(id===requestId){busy=false;root.classList.remove('inb-busy');root.setAttribute('aria-busy','false');}}
  }
  $('preset').addEventListener('change',()=>{selected=Number($('preset').value);clear();label();feedback('Consultando o cargo e território selecionados…');load();});
  label();if(cfg.seed){try{render(cfg.seed);}catch{clear();}}load();
  const tick=setInterval(()=>{if(!root.isConnected){clearInterval(tick);controller?.abort();return;}if(!document.hidden&&!busy&&Date.now()>=next)load();},1000);
  document.addEventListener('visibilitychange',()=>{if(root.isConnected&&!document.hidden&&!busy&&Date.now()>=next)load();});
  window.addEventListener('online',()=>{if(root.isConnected&&!busy)load();});
})();
</script>
</section>
<?php
})($itamaraju_eleicoes_config??[]);

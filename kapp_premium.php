<?php
$API_KEY = "01000000003722b9ac34402bfaf57ad299324c5aa7ee19f045097597a7b3d5b4aa13773684";
$SECRET_KEY = "AQAAAABPpWpWLqcB3Ptzm2v2AUEqulSZh0ZqII5+OO+usyRiTQ==";
$CUSTOMER_ID = "436324";
$NAVER_CLIENT_ID = "rC3G5rYjmcPecMZidCAP";
$NAVER_CLIENT_SECRET = "DDofjHvdTw";
/*
중요
- 월간 검색량/경쟁도: 네이버 검색광고 API 사용
- 총 발행량: 네이버 Search Open API 사용 권장
- 일일 발행량: 블로그/뉴스는 Open API 날짜 필드로 계산, 카페/웹문서는 네이버가 공식 일자 필드를 제공하지 않아 검색결과 페이지 보조 수집으로 계산
- 아래 CLIENT 값이 비어 있으면 총 발행량/일일 발행량은 네이버 HTML 차단 때문에 안정적으로 나오지 않습니다.
*/


$BASE_URL = "https://api.searchad.naver.com";
$URI = "/keywordstool";
$METHOD = "GET";

date_default_timezone_set('Asia/Seoul');

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function make_signature($timestamp, $method, $uri, $secret_key){ return base64_encode(hash_hmac("sha256", $timestamp.'.'.$method.'.'.$uri, $secret_key, true)); }
function normalize_keyword($value){ return strtoupper(preg_replace('/\s+/u', '', trim((string)$value))); }
function to_number($value){ if ($value === "< 10") return 0; return is_numeric($value) ? intval($value) : 0; }
function comp_level($comp){ $c=strtoupper(trim((string)$comp)); if($c==='HIGH'||$c==='높음')return'높음'; if($c==='MEDIUM'||$c==='중간')return'중간'; if($c==='LOW'||$c==='낮음')return'낮음'; return $comp?:'정보없음'; }
function comp_score($comp){ $c=comp_level($comp); return $c==='낮음'?25:($c==='중간'?55:($c==='높음'?85:45)); }
function pct($n){ return max(0,min(100,round($n))); }
function format_count_value($v){ return is_null($v) ? "-" : number_format((int)$v)."건"; }
function supply_level($daily){ if(is_null($daily))return"참고"; if($daily>=80)return"매우 높음"; if($daily>=30)return"높음"; if($daily>=10)return"보통"; if($daily>=1)return"낮음"; return"거의 없음"; }
function volume_grade($v){ if($v>=10000)return"높음"; if($v>=2000)return"중간"; if($v>0)return"낮음"; return"데이터부족"; }
function source_label($status){
    $st=(string)$status;
    if(strpos($st,"OpenAPI")!==false) return "공식 API";
    if(strpos($st,"검색결과")!==false) return "검색결과 추정";
    if(strpos($st,"없음")!==false) return "0건 또는 비공개";
    if(strpos($st,"차단")!==false || strpos($st,"구조")!==false) return "추정 불가";
    return $st ?: "-";
}
function has_count($v){ return !is_null($v); }

function make_row($input_keyword,$type,$keyword,$pc,$mobile,$competition,$status,$sort_group,$input_order){
    return ["입력키워드"=>$input_keyword,"구분"=>$type,"키워드"=>$keyword,"PC검색수"=>$pc,"모바일검색수"=>$mobile,"총검색수"=>to_number($pc)+to_number($mobile),"경쟁정도"=>comp_level($competition),"상태"=>$status,"정렬그룹"=>$sort_group,"입력순서"=>$input_order];
}

function get_keywords($keyword,$input_order,$API_KEY,$SECRET_KEY,$CUSTOMER_ID,$BASE_URL,$URI,$METHOD){
    $timestamp=round(microtime(true)*1000); $signature=make_signature($timestamp,$METHOD,$URI,$SECRET_KEY); $clean=normalize_keyword($keyword);
    $url=$BASE_URL.$URI."?".http_build_query(["hintKeywords"=>$clean,"showDetail"=>1]);
    $headers=["X-Timestamp: $timestamp","X-API-KEY: $API_KEY","X-Customer: $CUSTOMER_ID","X-Signature: $signature"];
    $ch=curl_init(); curl_setopt_array($ch,[CURLOPT_URL=>$url,CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>6]);
    $res=curl_exec($ch); $http=curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_error($ch); curl_close($ch);
    if($res===false||$http!==200) return [make_row($keyword,"입력키워드",$keyword,"오류","오류","","검색광고 API 오류 $http $err",0,$input_order)];
    $json=json_decode($res,true); $list=$json["keywordList"]??[]; $input_row=null; $related=[];
    foreach($list as $item){
        $rel=$item["relKeyword"]??""; $is_exact=normalize_keyword($rel)===$clean;
        $row=make_row($keyword,$is_exact?"입력키워드":"연관키워드",$is_exact?$keyword:$rel,$item["monthlyPcQcCnt"]??0,$item["monthlyMobileQcCnt"]??0,$item["compIdx"]??"","성공",$is_exact?0:1,$input_order);
        if($is_exact)$input_row=$row; else $related[]=$row;
    }
    if(!$input_row) $input_row=make_row($keyword,"입력키워드",$keyword,0,0,"데이터 없음","정확히 일치하는 키워드가 검색광고 API 결과에 없어 최상단 별도 표시",0,$input_order);
    usort($related,fn($a,$b)=>$b["총검색수"]<=>$a["총검색수"]);
    return array_merge([$input_row],$related);
}

function fetch_url_html($url){
    $ch=curl_init();
    curl_setopt_array($ch,[CURLOPT_URL=>$url,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>5,CURLOPT_ENCODING=>'',CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_SSL_VERIFYHOST=>false,CURLOPT_HTTPHEADER=>[
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'Accept-Language: ko-KR,ko;q=0.9,en-US;q=0.8,en;q=0.7','Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8','Referer: https://www.naver.com/'
    ]]);
    $html=curl_exec($ch); $http=curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_error($ch); curl_close($ch);
    return [$html,$http,$err];
}

function count_items_from_html($html,$where){
    $markers=[
        'blog'=>['title_link','view_wrap','api_txt_lines','total_tit'],
        'article'=>['title_link','cafe_name','api_txt_lines','total_tit'],
        'news'=>['news_tit','news_area','news_wrap','sa_text_title'],
        'web'=>['total_tit','link_tit','total_wrap','web_link']
    ];
    $max=0; foreach(($markers[$where]??$markers['blog']) as $m) $max=max($max,substr_count($html,$m));
    return $max;
}

function parse_total_from_html($html){
    $text=html_entity_decode(strip_tags($html),ENT_QUOTES,'UTF-8'); $text=preg_replace('/\s+/u',' ',$text);
    $patterns=['/(?:전체|검색결과|결과)\s*(?:약\s*)?([0-9,]+)\s*건/u','/([0-9,]+)\s*건\s*(?:의)?\s*(?:검색결과|결과|글|문서)/u'];
    foreach($patterns as $pat){ if(preg_match($pat,$text,$m)) return intval(str_replace(',','',$m[1])); }
    return null;
}

function fetch_naver_search_count($query,$where='blog',$period='all'){
    $pc=['blog'=>'blog','article'=>'article','news'=>'news','web'=>'web'];
    $mo=['blog'=>'m_blog','article'=>'m_cafe','news'=>'m_news','web'=>'m_web'];
    $urls=[]; $nso=$period==='day'?'so:r,p:1d':'';
    $p=['where'=>$pc[$where]??$where,'query'=>$query,'sm'=>'tab_opt']; if($nso)$p['nso']=$nso; $urls[]='https://search.naver.com/search.naver?'.http_build_query($p);
    $m=['where'=>$mo[$where]??'m_blog','query'=>$query]; if($nso)$m['nso']=$nso; $urls[]='https://m.search.naver.com/search.naver?'.http_build_query($m);
    $last='검색결과 차단/구조변경';
    foreach($urls as $url){
        [$html,$http,$err]=fetch_url_html($url);
        if(!$html||$http>=400){ $last='HTTP '.$http; continue; }
        if($period==='day'){
            $cnt=count_items_from_html($html,$where);
            if($cnt>0) return ['count'=>$cnt,'status'=>'검색결과 1일 최소추정'];
            $last='1일 결과 없음/차단'; continue;
        }
        $total=parse_total_from_html($html); if(!is_null($total)) return ['count'=>$total,'status'=>'검색결과 총량'];
        $cnt=count_items_from_html($html,$where); if($cnt>0) return ['count'=>$cnt,'status'=>'검색결과 최소추정'];
    }
    return ['count'=>null,'status'=>$last];
}

function naver_openapi($query,$type,$client_id,$client_secret){
    if(!$client_id||!$client_secret) return null;
    $map=['blog'=>'blog','article'=>'cafearticle','news'=>'news','web'=>'webkr']; $endpoint=$map[$type]??'blog';
    $url='https://openapi.naver.com/v1/search/'.$endpoint.'.json?'.http_build_query(['query'=>$query,'display'=>100,'start'=>1,'sort'=>($endpoint==='blog'||$endpoint==='news')?'date':'sim']);
    $ch=curl_init(); curl_setopt_array($ch,[CURLOPT_URL=>$url,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>5,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_HTTPHEADER=>['X-Naver-Client-Id: '.$client_id,'X-Naver-Client-Secret: '.$client_secret]]);
    $res=curl_exec($ch); $http=curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_error($ch); curl_close($ch);
    if($res===false||$http!==200) return ['total'=>null,'daily'=>null,'status'=>'OpenAPI 오류 '.$http];
    $json=json_decode($res,true); if(!is_array($json)) return ['total'=>null,'daily'=>null,'status'=>'OpenAPI 응답 오류'];
    $total=isset($json['total'])?intval($json['total']):null; $today=date('Ymd'); $daily=null;
    if(isset($json['items'])&&is_array($json['items'])){
        if($endpoint==='blog'){
            $daily=0; foreach($json['items'] as $it){ if(($it['postdate']??'')===$today)$daily++; }
        } elseif($endpoint==='news'){
            $daily=0; foreach($json['items'] as $it){ $t=strtotime($it['pubDate']??''); if($t&&date('Ymd',$t)===$today)$daily++; }
        }
    }
    return ['total'=>$total,'daily'=>$daily,'status'=>'OpenAPI'];
}

function get_content_supply_data($keyword,$client_id='',$client_secret=''){
    // 속도개선 버전
    // 총 발행량: 공식 OpenAPI 우선
    // 일일 발행량: 블로그/뉴스는 공식 날짜 필드 사용
    // 카페/웹문서 일일 발행량은 공식 날짜 필드가 없어 느린 HTML 수집을 생략합니다.
    // HTML 수집은 OpenAPI 총량 실패 시에만 짧은 타임아웃으로 1회 보조 시도합니다.
    $channels=[
        'blog'=>['label'=>'블로그/VIEW','where'=>'blog'],
        'cafe'=>['label'=>'카페','where'=>'article'],
        'news'=>['label'=>'뉴스','where'=>'news'],
        'web'=>['label'=>'웹문서','where'=>'web']
    ];
    $out=[];
    foreach($channels as $key=>$cfg){
        $open=naver_openapi($keyword,$cfg['where'],$client_id,$client_secret);
        $total=['count'=>null,'status'=>'공식 API'];
        $daily=['count'=>null,'status'=>'공식 일일 데이터 없음'];

        if($open){
            if(!is_null($open['total'])){
                $total=['count'=>$open['total'],'status'=>$open['status']];
            }
            if(!is_null($open['daily'])){
                $daily=['count'=>$open['daily'],'status'=>$open['status'].' 날짜계산'];
            }
        }

        // OpenAPI가 실패한 경우에만 총량 보조 수집 1회 시도
        if(is_null($total['count'])){
            $t=fetch_naver_search_count($keyword,$cfg['where'],'all');
            if(!is_null($t['count'])) $total=$t;
            else $total=['count'=>null,'status'=>'총량 확인불가'];
        }

        // 카페/웹문서는 공식 일일 날짜값이 없으므로 느린 HTML 수집 생략
        // 필요하면 아래 한 줄을 켜면 되지만 속도가 많이 느려질 수 있습니다.
        // if(($key==='cafe'||$key==='web') && is_null($daily['count'])) $daily=fetch_naver_search_count($keyword,$cfg['where'],'day');

        $out[$key]=[
            'label'=>$cfg['label'],
            'daily'=>$daily['count'],
            'total'=>$total['count'],
            'daily_status'=>$daily['status'],
            'total_status'=>$total['status']
        ];
    }
    return $out;
}

function marketing_analysis($main,$related,$supply){
    $volume=(int)$main['총검색수']; $pc=to_number($main['PC검색수']); $mob=to_number($main['모바일검색수']); $mobile_share=($pc+$mob)>0?($mob/($pc+$mob))*100:60;
    $comp=comp_score($main['경쟁정도']); $related_top=array_slice($related,0,10); $related_volume=array_sum(array_column($related_top,'총검색수')); $longtail=min(100,(count($related)*3)+min(50,$related_volume/500));
    $search=pct(52+min(28,$volume/500)-max(0,($comp-50)*.35)); $blog=pct(45+min(28,$longtail*.45)+($comp>=70?8:0)); $cafe=pct(42+($mobile_share>=65?10:4)+min(24,$longtail*.35)); $seo=pct(36+min(34,$longtail*.42)-($comp>=75?6:0));
    $bd=$supply['blog']['daily']??null; $cd=$supply['cafe']['daily']??null; $wt=$supply['web']['total']??null; $nd=$supply['news']['daily']??null;
    if(!is_null($bd))$blog=pct($blog+($bd<=10?10:($bd<=40?4:-8))); if(!is_null($cd))$cafe=pct($cafe+($cd<=15?8:($cd<=50?3:-7))); if(!is_null($wt))$seo=pct($seo+($wt<=300?8:($wt<=2000?3:-6))); if(!is_null($nd)&&$nd>=10)$search=pct($search+4);
    if($volume===0){$search=35;$blog=68;$cafe=62;$seo=55;}
    $channels=[['name'=>'네이버 검색광고','score'=>$search,'desc'=>'즉시 노출과 전화 전환 테스트에 유리','action'=>'정확 키워드·지역 키워드 위주로 소액 테스트','daily'=>$nd,'total'=>$supply['news']['total']??null],['name'=>'블로그/VIEW 콘텐츠','score'=>$blog,'desc'=>'비교·후기·분양 정보 탐색 고객 확보에 유리','action'=>'입지, 가격, 모델하우스, 방문혜택 콘텐츠 제작','daily'=>$bd,'total'=>$supply['blog']['total']??null],['name'=>'네이버 카페 마케팅','score'=>$cafe,'desc'=>'지역 커뮤니티 반응과 상담 유도에 유리','action'=>'실거주 관심층이 많은 지역 카페 중심으로 운영','daily'=>$cd,'total'=>$supply['cafe']['total']??null],['name'=>'웹사이트 SEO/랜딩페이지','score'=>$seo,'desc'=>'장기적으로 광고비를 낮추는 기반 채널','action'=>'키워드별 전용 랜딩페이지와 상담 CTA 강화','daily'=>$supply['web']['daily']??null,'total'=>$wt]];
    usort($channels,fn($a,$b)=>$b['score']<=>$a['score']); $best=$channels[0];
    $reason=$volume>=5000&&comp_level($main['경쟁정도'])!=='높음'?'검색량이 충분하고 경쟁 부담이 과하지 않아 검색광고로 빠르게 반응을 확인하는 전략이 좋습니다.':(comp_level($main['경쟁정도'])==='높음'?'경쟁도가 높아 광고비가 커질 수 있으므로 콘텐츠와 카페로 신뢰를 만든 뒤 검색광고를 보조로 쓰는 편이 효율적입니다.':'검색량은 크지 않지만 의도가 분명한 키워드라 소액 광고와 콘텐츠를 같이 운영하는 혼합 전략이 적합합니다.');
    $budget=$best['name']==='네이버 검색광고'?'초기 예산은 검색광고 50%, 블로그/VIEW 25%, 카페 15%, SEO 10% 비중을 권장합니다.':($best['name']==='블로그/VIEW 콘텐츠'?'초기 예산은 블로그/VIEW 40%, 카페 25%, 검색광고 25%, SEO 10% 비중을 권장합니다.':($best['name']==='네이버 카페 마케팅'?'초기 예산은 카페 40%, 블로그/VIEW 30%, 검색광고 20%, SEO 10% 비중을 권장합니다.':'초기 예산은 SEO/랜딩페이지 35%, 블로그/VIEW 30%, 검색광고 25%, 카페 10% 비중을 권장합니다.'));
    return ['volume_grade'=>volume_grade($volume),'mobile_share'=>round($mobile_share),'competition'=>comp_level($main['경쟁정도']),'channels'=>$channels,'best'=>$best,'reason'=>$reason,'budget'=>$budget,'content_supply'=>$supply];
}

$results=[]; $error_message=''; $keyword_text=$_POST['keywords']??'';
if($_SERVER['REQUEST_METHOD']==='POST'){
    $keywords=array_values(array_filter(array_map('trim',preg_split('/\r\n|\r|\n/',$keyword_text))));
    if(empty($keywords)) $error_message='키워드를 입력해주세요.';
    else { foreach($keywords as $i=>$kw){ $results=array_merge($results,get_keywords($kw,$i,$API_KEY,$SECRET_KEY,$CUSTOMER_ID,$BASE_URL,$URI,$METHOD)); } usort($results,function($a,$b){ if($a['입력순서']!==$b['입력순서'])return$a['입력순서']<=>$b['입력순서']; if($a['정렬그룹']!==$b['정렬그룹'])return$a['정렬그룹']<=>$b['정렬그룹']; return$b['총검색수']<=>$a['총검색수'];}); }
}
$display_results=array_map(function($r){unset($r['정렬그룹'],$r['입력순서']);return$r;},$results); $main_rows=array_values(array_filter($display_results,fn($r)=>$r['구분']==='입력키워드')); $related_rows_all=array_values(array_filter($display_results,fn($r)=>$r['구분']==='연관키워드')); $total_volume=array_sum(array_column($display_results,'총검색수')); $analyses=[];
foreach($main_rows as $main){ $rel=array_values(array_filter($related_rows_all,fn($r)=>$r['입력키워드']===$main['입력키워드'])); $supply=get_content_supply_data($main['키워드'],$NAVER_CLIENT_ID,$NAVER_CLIENT_SECRET); $analyses[$main['입력키워드']]=marketing_analysis($main,$rel,$supply); }
$openapi_ready=($NAVER_CLIENT_ID!==''&&$NAVER_CLIENT_SECRET!=='');
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>네이버 키워드 마케팅 분석 · YOUNGMAN</title>
<link rel="icon" type="image/png" href="logo_main.png">
<link rel="stylesheet" as="style" crossorigin href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/static/pretendard.min.css">
<link rel="stylesheet" href="style.css?v=20260512-forms-ledger-css">
<style>
:root{
  --bg:#ffffff; --card:#ffffff; --text:#0a0a0a; --muted:#8e8e93; --secondary:#525252;
  --line:rgba(20,14,8,0.09); --line-strong:rgba(20,14,8,0.16);
  --soft:#fbf7ef; --bg-muted:#f4efe7; --accent:#c8362c; --success:#047a55;
}
*{box-sizing:border-box}
body{margin:0;font-family:Pretendard,-apple-system,BlinkMacSystemFont,"SF Pro Text",Inter,"Helvetica Neue",sans-serif;background:var(--bg);color:var(--text);letter-spacing:-.005em;overflow-x:hidden;-webkit-font-smoothing:antialiased}
.wrap{max-width:1180px;margin:0 auto;padding:32px 24px 64px}
.hero{background:transparent;border:0;border-radius:0;padding:0 0 8px;box-shadow:none;margin-bottom:32px}
.badge{display:inline-flex;padding:0;border-radius:0;background:transparent;color:var(--muted);font-size:11px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase}
h1{margin:8px 0 0;font-size:32px;line-height:1.18;letter-spacing:-.025em;font-weight:600}
.sub{margin-top:8px;max-width:680px;color:var(--muted);font-size:15px;line-height:1.55;font-weight:400}
.layout{display:grid;grid-template-columns:300px minmax(0,1fr);gap:32px;align-items:start}
.card,.panel{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:24px;box-shadow:none;min-width:0}
.card-title{font-size:15px;font-weight:600;margin-bottom:16px;letter-spacing:-.01em}
.label{font-size:12px;font-weight:500;color:var(--secondary);margin-bottom:8px}
textarea{width:100%;min-height:180px;border:1px solid var(--line-strong);border-radius:6px;background:var(--card);padding:12px;font-size:14px;line-height:1.55;resize:vertical;outline:none;color:var(--text);font-family:inherit;transition:all 180ms cubic-bezier(0.16,1,0.3,1)}
textarea:focus{border-color:rgba(0,0,0,0.85);box-shadow:0 0 0 3px rgba(0,0,0,0.06)}
button{width:100%;height:42px;margin-top:14px;border:0;border-radius:6px;background:var(--text);color:#fff;font-size:14px;font-weight:600;cursor:pointer;box-shadow:none;letter-spacing:-.005em;transition:opacity 180ms}
button:hover{opacity:.85}
.notice{margin-top:12px;color:var(--muted);font-size:12.5px;line-height:1.5}
.error{margin-bottom:16px;padding:12px 14px;border-radius:6px;background:rgba(201,42,42,0.06);border:1px solid rgba(201,42,42,0.28);color:#c92a2a;font-size:13.5px}
.metrics{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:24px}
.metric{border:1px solid var(--line);border-radius:10px;padding:18px 20px;background:var(--card)}
.metric span{display:block;color:var(--muted);font-size:12px;font-weight:500}
.metric strong{display:block;margin-top:8px;font-size:24px;font-weight:600;letter-spacing:-.022em}
.main-analysis{border:1px solid var(--line);border-radius:10px;background:var(--card);padding:24px;margin-bottom:24px}
.analysis-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:16px}
.kicker{font-size:11px;color:var(--muted);font-weight:600;letter-spacing:.06em;text-transform:uppercase}
.main-keyword{font-size:26px;font-weight:600;margin-top:4px;letter-spacing:-.022em}
.pill{padding:0 10px;height:24px;display:inline-flex;align-items:center;border-radius:999px;background:transparent;border:1px solid var(--line);color:var(--secondary);font-size:12px;font-weight:500;white-space:nowrap}
.summary-box{border:1px solid var(--line);border-radius:8px;background:var(--soft);padding:16px 18px;color:var(--text);line-height:1.6;font-size:14px}
.mini-data,.content-supply-table,.channel-grid{display:grid;gap:12px;margin-top:16px}
.mini-data{grid-template-columns:repeat(4,1fr)}
.content-supply-table,.channel-grid{grid-template-columns:repeat(auto-fit,minmax(220px,1fr))}
.mini,.supply-card,.channel{border:1px solid var(--line);border-radius:10px;background:var(--card);padding:16px}
.mini span{display:block;color:var(--muted);font-size:12px;font-weight:500}
.mini strong{display:block;margin-top:6px;font-size:16px;font-weight:600;letter-spacing:-.01em}
.section-title{font-size:15px;font-weight:600;margin:24px 0 10px;letter-spacing:-.01em}
.supply-card .name,.channel-name{font-size:14.5px;font-weight:600;margin-bottom:12px;letter-spacing:-.01em}
.data-line{display:flex;justify-content:space-between;gap:10px;margin-top:8px;font-size:13px;color:var(--secondary)}
.data-line strong{color:var(--text);font-weight:500}
.supply-level{display:inline-flex;margin-top:12px;padding:0 9px;height:22px;align-items:center;border-radius:999px;background:rgba(4,122,85,0.08);color:var(--success);font-size:12px;font-weight:500}
.status{margin-top:8px;color:var(--muted);font-size:11px;line-height:1.45}
.channel-top{display:flex;justify-content:space-between;gap:10px}
.score{font-size:15px;font-weight:600;color:var(--text)}
.bar{height:4px;background:var(--bg-muted);border-radius:999px;overflow:hidden;margin:12px 0}
.bar span{display:block;height:100%;border-radius:999px;background:var(--text)}
.channel p{margin:0;color:var(--muted);font-size:13px;line-height:1.55}
.supply-row{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:12px}
.supply-chip{border:1px solid var(--line);border-radius:8px;background:var(--soft);padding:10px 12px}
.supply-chip span{font-size:11px;color:var(--muted);font-weight:500}
.supply-chip b{display:block;margin-top:4px;font-size:13.5px;font-weight:600}
.action{margin-top:12px;color:var(--success);font-size:13px;font-weight:500;line-height:1.5}
.table-wrap{width:100%;overflow-x:auto;overflow-y:hidden;border:1px solid var(--line);border-radius:10px;background:var(--card);padding-bottom:0}
table{width:100%;min-width:820px;border-collapse:collapse;font-size:13.5px}
th,td{padding:12px 16px;border-bottom:1px solid var(--line);text-align:left;white-space:nowrap}
th{background:transparent;color:var(--muted);font-weight:600;font-size:12px;letter-spacing:-.005em}
tr:last-child td{border-bottom:0}
.tag-main,.tag-related{display:inline-flex;padding:0 8px;height:22px;align-items:center;border-radius:999px;font-size:12px;font-weight:500}
.tag-main{background:transparent;color:var(--text);border:1px solid var(--line-strong)}
.tag-related{background:transparent;color:var(--muted);border:1px solid var(--line)}
.empty{padding:24px 0;color:var(--muted)}
@media(max-width:920px){.wrap{padding:12px 10px 28px}.hero{padding:20px 16px;border-radius:20px}h1{font-size:28px;letter-spacing:-.8px}.sub{font-size:14px}.layout,.metrics,.mini-data,.content-supply-table,.channel-grid{grid-template-columns:1fr}.card,.panel,.main-analysis{padding:14px;border-radius:18px}.analysis-head{display:block}.main-keyword{font-size:24px}.pill{display:inline-flex;margin-top:10px;white-space:normal}.summary-box{font-size:14px;padding:13px}.table-wrap{display:none}textarea{min-height:150px}.card-title{font-size:18px}}

/* unified mobile cards */
.supply-card{
  position:relative;
  min-height:180px;
}
.supply-card .name{
  display:flex;
  justify-content:space-between;
  align-items:flex-start;
  gap:10px;
  font-size:17px;
}
.supply-card:after{
  content:"";
  display:block;
  height:8px;
  border-radius:999px;
  background:linear-gradient(90deg,#38bdf8,#10b981);
  margin:12px 0 2px;
}
.supply-card .data-line{
  border:1px solid var(--line);
  border-radius:14px;
  background:#fbfdff;
  padding:10px;
}
.supply-card .data-line + .data-line{
  margin-top:8px;
}
.supply-card .status{
  display:none;
}
@media(max-width:920px){
  .content-supply-table .supply-card,
  .channel-grid .channel{
    min-height:0;
    padding:18px;
    border-radius:20px;
  }
  .content-supply-table .supply-card .name,
  .channel-grid .channel-name{
    font-size:20px;
    font-weight:900;
    line-height:1.25;
  }
  .content-supply-table .supply-card:after,
  .channel-grid .bar{
    height:8px;
    margin:14px 0;
  }
  .content-supply-table .supply-card .data-line{
    display:grid;
    grid-template-columns:1fr 1fr;
    align-items:center;
    gap:8px;
    margin-top:8px;
  }
  .content-supply-table .supply-card .data-line span,
  .channel-grid .supply-chip span{
    font-size:12px;
    color:#667085;
  }
  .content-supply-table .supply-card .data-line strong,
  .channel-grid .supply-chip b{
    font-size:16px;
    font-weight:900;
    text-align:right;
  }
  .content-supply-table .supply-level{
    margin-top:12px;
  }
}


/* monthly search split */
.monthly-search-mobile{
  display:none;
  grid-template-columns:1fr 1fr;
  gap:10px;
  margin-top:12px;
}
.monthly-search-mobile div{
  border:1px solid var(--line);
  border-radius:16px;
  background:#fbfdff;
  padding:13px;
}
.monthly-search-mobile span{
  display:block;
  color:#667085;
  font-size:12px;
  margin-bottom:5px;
}
.monthly-search-mobile b{
  display:block;
  color:#101828;
  font-size:18px;
  font-weight:900;
}
@media(min-width:921px){
  .mini-data{grid-template-columns:repeat(6,1fr)}
}
@media(max-width:920px){
  .mini-data{grid-template-columns:1fr 1fr}
  .monthly-search-mobile{display:grid}
  .supply-row{grid-template-columns:1fr}
}


.speed-note{
  background:rgba(4,122,85,0.06);
  border:1px solid rgba(4,122,85,0.18);
  color:var(--success);
  font-weight:500;
}

</style>
</head>
<body>

<header id="app-header" class="app-header"></header>
<script type="module">
    import { mountAppHeader, mountAppFooter } from './auth-shared.js?v=20260517-session-persist';
    mountAppHeader();
    mountAppFooter();
</script>

<div class="wrap">
  <section class="hero">
    <div class="badge">NAVER MARKETING ANALYZER</div>
    <h1>키워드 마케팅 효율 분석</h1>
    <div class="sub">입력 키워드를 기준으로 월간 검색량, 발행량, 경쟁강도를 함께 분석해 가장 효율적인 마케팅 채널을 추천합니다.</div>
  </section>

  <div class="layout">
    <aside class="card">
      <div class="card-title">키워드 입력</div>
      <form method="post">
        <div class="label">분석할 키워드</div>
        <textarea name="keywords"><?=h($keyword_text)?></textarea>
        <button type="submit">분석하기</button>
      </form>
      <div class="notice">여러 개를 입력하면 줄 단위로 각각 분석합니다.</div><div class="notice speed-note">빠른 조회 모드: 공식 API 중심으로 조회합니다. 카페/웹문서 일일 발행량은 속도 개선을 위해 생략될 수 있습니다.</div>
    </aside>

    <main class="panel">
      <div class="card-title">분석 결과</div>
      <?php if($error_message):?><div class="error"><?=h($error_message)?></div><?php endif;?>
      <div class="metrics">
        <div class="metric"><span>분석 키워드</span><strong><?=number_format(count($main_rows))?>개</strong></div>
        <div class="metric"><span>전체 데이터</span><strong><?=number_format(count($display_results))?>개</strong></div>
        <div class="metric"><span>총 검색량</span><strong><?=number_format($total_volume)?></strong></div>
      </div>

      <?php if(empty($display_results)):?>
        <div class="empty">키워드를 입력하고 분석 버튼을 누르세요.</div>
      <?php else:?>
        <?php foreach($main_rows as $main): $a=$analyses[$main['입력키워드']];?>
        <section class="main-analysis">
          <div class="analysis-head">
            <div><div class="kicker">입력 키워드 분석</div><div class="main-keyword"><?=h($main['키워드'])?></div></div>
            <div class="pill">추천 채널: <?=h($a['best']['name'])?></div>
          </div>
          <div class="summary-box"><b><?=h($a['best']['name'])?></b> 효율 점수가 가장 높습니다. <?=h($a['reason'])?><br><?=h($a['budget'])?></div>
          <div class="mini-data">
            <div class="mini"><span>검색량 등급</span><strong><?=h($a['volume_grade'])?></strong></div>
            <div class="mini"><span>월간 총검색수</span><strong><?=number_format($main['총검색수'])?></strong></div>
            <div class="mini"><span>월간 모바일</span><strong><?=h($main['모바일검색수'])?></strong></div>
            <div class="mini"><span>월간 PC</span><strong><?=h($main['PC검색수'])?></strong></div>
            <div class="mini"><span>모바일 비중</span><strong><?=h($a['mobile_share'])?>%</strong></div>
            <div class="mini"><span>경쟁정도</span><strong><?=h($a['competition'])?></strong></div>
          </div>

          <div class="monthly-search-mobile">
            <div><span>월간 모바일 검색량</span><b><?=h($main['모바일검색수'])?></b></div>
            <div><span>월간 PC 검색량</span><b><?=h($main['PC검색수'])?></b></div>
          </div>

          <div class="section-title">노출결과 수집 분석</div>
          <div class="content-supply-table">
            <?php foreach($a['content_supply'] as $s):?>
            <div class="supply-card">
              <div class="name"><?=h($s['label'])?></div>
              <div class="data-line"><span>일일 발행량</span><strong><?=format_count_value($s['daily'])?></strong></div>
              <div class="data-line"><span>총 발행량</span><strong><?=format_count_value($s['total'])?></strong></div>
              <div class="supply-level">경쟁강도 <?=h(supply_level($s['daily']))?></div>
              <div class="status">일일 <?=h(source_label($s['daily_status']))?> · 총량 <?=h(source_label($s['total_status']))?></div>
            </div>
            <?php endforeach;?>
          </div>

          <div class="channel-grid">
            <?php foreach($a['channels'] as $ch):?>
            <div class="channel">
              <div class="channel-top"><div class="channel-name"><?=h($ch['name'])?></div><div class="score"><?=$ch['score']?>점</div></div>
              <div class="bar"><span style="width:<?=$ch['score']?>%"></span></div>
              <p><?=h($ch['desc'])?></p>
              <div class="supply-row"><div class="supply-chip"><span>일일</span><b><?=format_count_value($ch['daily'])?></b></div><div class="supply-chip"><span>총량</span><b><?=format_count_value($ch['total'])?></b></div></div>
              <div class="action">실행: <?=h($ch['action'])?></div>
            </div>
            <?php endforeach;?>
          </div>
        </section>
        <?php endforeach;?>

        <div class="section-title">키워드 상세 데이터</div>
        <div class="table-wrap"><table><thead><tr><th>입력키워드</th><th>구분</th><th>키워드</th><th>PC</th><th>모바일</th><th>총검색수</th><th>경쟁정도</th><th>상태</th></tr></thead><tbody><?php foreach($display_results as $row):?><tr><td><?=h($row['입력키워드'])?></td><td><span class="<?=$row['구분']==='입력키워드'?'tag-main':'tag-related'?>"><?=h($row['구분'])?></span></td><td><b><?=h($row['키워드'])?></b></td><td><?=h($row['PC검색수'])?></td><td><?=h($row['모바일검색수'])?></td><td><?=number_format($row['총검색수'])?></td><td><?=h($row['경쟁정도'])?></td><td><?=h($row['상태'])?></td></tr><?php endforeach;?></tbody></table></div>
      <?php endif;?>
    </main>
  </div>
</div>
</body>
</html>

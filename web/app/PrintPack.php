<?php
declare(strict_types=1);
namespace Hunt;
function qrSvg(Database $db,array $e,array $c): string {
 $raw=json(signed($db->key,['event_id'=>$e['id'],'checkpoint_id'=>$c['id'],'nonce'=>$c['nonce']]));
 $renderer=new \BaconQrCode\Renderer\ImageRenderer(new \BaconQrCode\Renderer\RendererStyle\RendererStyle(640,4),new \BaconQrCode\Renderer\Image\SvgImageBackEnd());
 return (new \BaconQrCode\Writer($renderer))->writeString($raw);
}
function printPack(Database $db,array $e,string $mode): BinaryReply {
 if(!$e['riddles'])fail('Add riddles first',409);
 if(!in_array($mode,['organizer','stations'],true))fail('Unknown print layout');
 $h=fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');$cp=array_column($e['checkpoints'],null,'id');$cards=[];
 $riddles=$e['riddles'];usort($riddles,fn($a,$b)=>[$a['round']??1,$a['title']]<=>[$b['round']??1,$b['title']]);
 $compass='<svg class="compass" viewBox="0 0 40 40" aria-hidden="true"><circle cx="20" cy="20" r="17" fill="none" stroke="currentColor"/><path d="M20 2 25 15 38 20 25 25 20 38 15 25 2 20 15 15Z" fill="none" stroke="currentColor"/><path d="M20 8 24 20 20 32 16 20Z" fill="currentColor"/></svg>';
 foreach($riddles as $r){$c=$cp[$r['checkpoint_id']];$image='data:image/svg+xml;base64,'.base64_encode(qrSvg($db,$e,$c));
  $details=$mode==='organizer'?'<div class="question">'.nl2br($h($r['question'])).'</div>'.(!continuousHunt($e)&&!empty($r['hint'])?'<p class="placement">Hint: '.$h($r['hint']).'</p>':'').(!empty($r['placement'])?'<p class="placement"><strong>Place:</strong> '.$h($r['placement']).'</p>':''):'<p class="tagline">Fortune favours the curious.<br>Scan to check your discovery.</p>';
  $label='Seal '.strtoupper(substr(hash('sha256',$c['id'].':'.$c['nonce']),0,8));
  $cards[]='<article><header>TREASURE HUNT</header>'.$compass.'<h1>'.$h($mode==='organizer'?$r['title']:'THE HIDDEN SEAL').'</h1>'.$details.'<img class="qr" alt="Checkpoint QR" src="'.$image.'"><p class="number">'.$h($label).'</p><footer>'.$h($mode==='organizer'?'PRIVATE - Placement copy. Do not display.':'Open Treasure Hunt - Scan location QR').'</footer></article>';
 }
 $pages='';foreach(array_chunk($cards,4) as $page)$pages.='<section class="print-page">'.implode('',$page).'</section>';
 $css=file_get_contents(dirname(__DIR__).'/dashboard/print.css');
 $html='<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Treasure Hunt QR print pack</title><style>'.$css.'</style><aside>A4 portrait - 4 cards per page - Print at 100% scale. '.($mode==='organizer'?'Keep this organizer copy private.':'Cut on the dashed borders and place each seal at its location.').'</aside><main class="print-pack '.$mode.'">'.$pages.'</main></html>';
 return new BinaryReply($html,'text/html; charset=utf-8','Treasure-Hunt-QR-'.$mode.'.html');
}

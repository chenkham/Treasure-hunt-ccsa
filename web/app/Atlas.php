<?php
declare(strict_types=1);
namespace Hunt;
function atlasMeta(): array {
 static $meta;return $meta??=parse(file_get_contents(dirname(__DIR__).'/database/atlas-meta.json'));
}
function atlasPoint(float $lat,float $lon): ?array {
 if(!is_finite($lat)||!is_finite($lon)||$lat<0||$lat>84||$lon<90||$lon>96)return null;
 $e2=.0066943799901413165;$ep=$e2/(1-$e2);$p=deg2rad($lat);$l=deg2rad($lon-93);
 $n=6378137/sqrt(1-$e2*sin($p)**2);$t=tan($p)**2;$c=$ep*cos($p)**2;$a=cos($p)*$l;
 $m=6378137*((1-$e2/4-3*$e2**2/64-5*$e2**3/256)*$p
  -(3*$e2/8+3*$e2**2/32+45*$e2**3/1024)*sin(2*$p)
  +(15*$e2**2/256+45*$e2**3/1024)*sin(4*$p)-(35*$e2**3/3072)*sin(6*$p));
 $east=.9996*$n*($a+(1-$t+$c)*$a**3/6+(5-18*$t+$t*$t+72*$c-58*$ep)*$a**5/120)+500000;
 $north=.9996*($m+$n*tan($p)*($a*$a/2+(5-$t+9*$c+4*$c*$c)*$a**4/24+(61-58*$t+$t*$t+600*$c-330*$ep)*$a**6/720));
 $meta=atlasMeta();$x=$east-$meta['utmOrigin'][0];$y=$meta['utmOrigin'][1]-$north;
 if($x<0||$y<0||$x>$meta['width']||$y>$meta['height'])return null;
 return ['x'=>$x,'y'=>$y];
}

<?php
require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';
$path=database_path('data/international-carrier-agencies.json');
$data=json_decode(file_get_contents($path),true);
$bad=[];$good=[];
foreach($data['locations'] as $p){
 $found=App\Support\DeliveryCountryBoundaries::countryAt($p['latitude'],$p['longitude']);
 if($found!==$p['country']){$p['coordinate_country']=$found;$bad[]=$p;}else{$good[]=$p;}
}
file_put_contents('/tmp/asso-agencies-coordinate-rejected.json',json_encode($bad,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
$data['locations']=$good;
$data['validation']='Coordinates checked against country boundaries; inconsistent or unresolvable points excluded.';
file_put_contents($path,json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n");
echo count($good).' valid points in '.count(array_unique(array_column($good,'country'))).' countries; '.count($bad)." rejected\n";

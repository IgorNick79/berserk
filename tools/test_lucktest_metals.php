<?php
declare(strict_types=1);
require __DIR__.'/test_lucktest.php';

$catalog=[];
foreach ([3,5,8] as $price) foreach ([false,true] as $gold) {
    $card=card('metal_'.$price.'_'.(int)$gold,$price);$card['elite']=$gold;$catalog[]=$card;
}
$currency=static function(array $cards): array {
    $sum=[0,0];foreach ($cards as $c) $sum[$c['elite']?0:1]+=$c['price']*$c['count'];return $sum;
};
$difference=static function(array $squads) use ($currency): int {
    $a=$currency($squads['host']);$b=$currency($squads['player']);return abs($a[0]-$b[0])+abs($a[1]-$b[1]);
};
$improved=0;
foreach (range(1,24) as $seed) {
    $outputs=[];
    foreach ([false,true] as $enabled) {
        mt_srand($seed);
        $settings=\Berserk\Core\GameSettings::defaults();
        $settings->lucktest['balanced']=false;$settings->lucktest['balance_metals']=$enabled;
        $outputs[]=(new \Berserk\Core\Prepare\LuckTestGenerator(static fn(int $min,int $max): int=>mt_rand($min,$max)))->generate($catalog,$settings);
    }
    check($outputs[1]['host']===$outputs[0]['host'],'host remains the fixed reference');
    check($difference($outputs[1])<=$difference($outputs[0]),'currency balance does not worsen');
    $errors=array_map(static fn(array $s): int=>abs(cost($s['host'])-25)+abs(cost($s['player'])-25),$outputs);
    check($errors[1]<=$errors[0],'total cost target always retained');
    if ($difference($outputs[1])<$difference($outputs[0])) $improved++;
}
check($improved>0,'separate gold and silver totals improve');
echo "Metals: 73 checks passed; improved $improved of 24 seeded squads.\n";

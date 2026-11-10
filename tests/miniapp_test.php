<?php
require_once __DIR__ . '/../trip_app.php';

function miniAppInput(): array
{
    return ['origin'=>'tehran','destination'=>'mashhad','date'=>'1490-01-01','count'=>2,'type'=>0,'coupe'=>false,'allow_partial_capacity'=>false,'search_filters'=>[]];
}

$tests['mini app validates complete trip and rejects malformed input'] = static function () {
    $input = miniAppInput();
    expect(miniAppValidateTrip($input)['route'], 'tehran-mashhad');
    foreach ([['origin'=>'fake'],['destination'=>'tehran'],['count'=>0],['count'=>'2'],['count'=>13],['type'=>3],['coupe'=>1],['allow_partial_capacity'=>1],['date'=>'2027-01-01'],['date'=>'1404-12-30'],['date'=>'1399-01-01'],['search_filters'=>['max_price'=>'500']]] as $change) {
        assertFollowError(static fn()=>miniAppValidateTrip(array_replace($input,$change)),400);
    }
};

$tests['mini app creation is idempotent and always follows all trains'] = static function () {
    [$db] = followFixture();
    $body = ['request_id'=>str_repeat('a',32),'trip'=>miniAppInput()];
    $first = miniAppCreate($db,'101',$body);
    $second = miniAppCreate($db,'101',$body);
    expect($first['created'],true); expect($second['created'],false);
    expect($first['trip']['id'],$second['trip']['id']); expect($first['trip']['mode'],'all');
    expect((int)$db->querySingle('SELECT COUNT(*) FROM user_trips'),2);
    $body['trip']['count']=3;
    assertFollowError(static fn()=>miniAppCreate($db,'101',$body),409);
    $other=miniAppCreate($db,'202',$body);
    expect($other['created'],true);
};

$tests['mini app preferences use ownership and revision without changing train choices'] = static function () {
    [$db,$trip] = followFixture();
    $body=['trip_id'=>(int)$trip['id'],'revision'=>0,'allow_partial_capacity'=>true,'search_filters'=>['max_price'=>900000]];
    assertFollowError(static fn()=>miniAppPreferences($db,'202',$body),404);
    $saved=miniAppPreferences($db,'101',$body);
    expect($saved['revision'],1);expect($saved['allow_partial_capacity'],true);expect($saved['mode'],'all');
    $body['revision']=1;
    expect(miniAppPreferences($db,'101',$body)['revision'],1,'Unchanged saves must not re-arm alerts');
    $body['revision']=0;
    assertFollowError(static fn()=>miniAppPreferences($db,'101',$body),409);
};

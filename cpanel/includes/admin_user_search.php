<?php
declare(strict_types=1);

/** Fold common Persian/Arabic keyboard variants without altering stored data. */
function admin_user_search_text(string $value): string
{
    $value=strtr($value, [
        '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
        '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
        'ي'=>'ی','ى'=>'ی','ك'=>'ک',"\u{200C}"=>'',"\u{200D}"=>'',
    ]);
    return mb_strtolower(preg_replace('/\s+/u','',$value)??$value, 'UTF-8');
}

function admin_user_search_phone(string $value): string
{
    $digits=preg_replace('/[^0-9]/','',admin_user_search_text($value))??'';
    if(str_starts_with($digits,'0098') && strlen($digits)>=7)return '0'.substr($digits,4);
    if(str_starts_with($digits,'98') && strlen($digits)>=5)return '0'.substr($digits,2);
    return $digits;
}

function admin_user_search_filter(array $users,string $query): array
{
    $needle=admin_user_search_text($query);
    if($needle==='')return $users;
    $numeric=preg_match('/^[+().0-9\-]+$/D',$needle)===1;
    $phoneNeedle=$numeric?admin_user_search_phone($query):'';
    return array_values(array_filter($users,static function(array $user) use($needle,$phoneNeedle):bool{
        foreach(['name','username'] as $field){
            if(str_contains(admin_user_search_text((string)($user[$field]??'')),$needle))return true;
        }
        return $phoneNeedle!=='' && str_contains(admin_user_search_phone((string)($user['phone']??'')),$phoneNeedle);
    }));
}

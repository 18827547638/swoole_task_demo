<?php
namespace app\api\validate;


class TokenGet
{
    protected $rule = [
        'code' => 'require|isNotEmpty'
    ];

    protected $message=[
        'code' => 'code不能为空'
    ];
}
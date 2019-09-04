<?php

namespace app\facade;

use think\Facade;

/**
 * @method static \app\common\model\Redis setKey($name) 设置key
 */
class Caches extends Facade
{
    protected static function getFacadeClass()
    {
        return 'app\util\caches';
    }
}
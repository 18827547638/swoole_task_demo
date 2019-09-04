<?php
/**
 * Created by PhpStorm.
 * User: 54123
 * Date: 2019/3/25
 * Time: 20:18
 */

namespace app\facade;
use think\Facade;

/**
 * Class ModelFactory
 * @package app\facade
 */
class ModelFactory extends Facade
{
    private static $ModelName;

    public function __construct($ModelName) {
        self::$ModelName = $ModelName;
    }

    protected static function getFacadeClass() {
        $ModelName = 'app\common\model\\' . self::$ModelName;
        return $ModelName;
    }

}
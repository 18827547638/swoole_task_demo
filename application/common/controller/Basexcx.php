<?php
namespace app\common\controller;

use think\Controller;
use think\facade\Request;
use app\common\service\XcxService;

class Basexcx extends Controller
{
    //用户部分信息
    protected $user_info;
    //需要验证信息的url
    protected static $check_url = [
        'meishi/order/*',//美食订单
        'meishi/store/recdiscount',//领取美食折扣
        'meishi/store/inseval',//用户插入评价数据
        'meishi/owner/*',//我的
    ];
    
    public function initialize()
    {
        header("Access-Control-Allow-Origin: *");
        if(self::_need_to_check(request()->module(), request()->controller(), request()->action())){
            $authorization = Request::instance()->header('authorization');
            $this->user_info = (new XcxService())->getUserInfoByAuth($authorization);
        }
    }
    
    private static function _need_to_check($module, $controller, $action)
    {
        $end_str = '*';
        $separator = '/';
        $current_url = $module.$separator.$controller.$separator.$action;
        foreach(self::$check_url as $val){
            if(substr($val, -1) === $end_str)$current_url = $module.$separator.$controller.$separator.$end_str;
            if($val == strtolower($current_url))return true;
        }
        return false;
    }
}
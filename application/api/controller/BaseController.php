<?php
/**
 * Created by PhpStorm.
 * User: Administrator
 * Date: 2019/4/23 0023
 * Time: 19:53
 */

namespace app\api\controller;


use app\facade\Redis;
use think\Controller;

class BaseController extends Controller
{
    /**
     * 不需要鉴权方法
     */
    protected $noAuth = [];

    protected $uid;
    /**
     * 登陆注册码
     * @var int
     */
    public static $ERROR_LOGIN = 40001;

    public function initialize()
    {
        $this->init();
    }

    /**
     * 初始化
     * @throws \Exception
     */
    public function init(){
        //token校验
        if(!self::match($this->noAuth)){
            $token = request()->header('Token', '');
            if(!$token) exception('请先登陆',self::$ERROR_LOGIN);
            $this->uid = self::getUidByToken($token);
            if(empty($this->uid)) exception('请先登陆',self::$ERROR_LOGIN);
            app('app\api\service\UserToken')->saveToCache($this->uid, $token);
        }
    }

    /**
     * 检测当前控制器和方法是否匹配传递的数组
     *
     * @param array $arr 需要验证权限的数组
     * @return boolean
     */
    public static function match($arr = [])
    {
        $arr = is_array($arr) ? $arr : explode(',', $arr);
        if (!$arr)
        {
            return false;
        }
        $arr = array_map('strtolower', $arr);
        // 是否存在
        if (in_array(strtolower(request()->action()), $arr) || in_array('*', $arr))
        {
            return true;
        }
        // 没找到匹配
        return false;
    }

    public static function getUidByToken($token){
        $uid = Redis::get(config('wx.token_prefix').$token);
        return $uid;
    }

}
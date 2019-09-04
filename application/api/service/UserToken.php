<?php

namespace app\api\service;

use app\api\model\User;
use app\facade\Redis;
use app\util\Strs;
use think\facade\Log;

/**
 * 微信登录
 * 如果担心频繁被恶意调用，请限制ip
 * 以及访问频率
 */
class UserToken
{
    protected $code;
    protected $rawData; //微信用户信息
    protected $wxLoginUrl;
    protected $wxAppID;
    protected $wxAppSecret;

    function __construct($code, $rawData)
    {
        $this->code = $code;
        $this->rawData = json_decode($rawData, true);//转对象
        $this->wxAppID = config('wx.app_id');
        $this->wxAppSecret = config('wx.app_secret');
        $this->wxLoginUrl = sprintf(
            config('wx.login_url'), $this->wxAppID, $this->wxAppSecret, $this->code);
    }

    public function get()
    {
        $result = httpGet($this->wxLoginUrl);

        // 注意json_decode的第一个参数true
        // 这将使字符串被转化为数组而非对象
        $wxResult = json_decode($result, true);
        if (empty($wxResult)) {
            // 这种情况通常是由于传入不合法的code
            exception('登录异常');
        } else {
            $loginFail = array_key_exists('errcode', $wxResult);
            if ($loginFail) {
                exception('登录失败');
            } else {
                return $this->Login($wxResult);
            }
        }
    }

    public function Login($wxResult)
    {
        $openid = $wxResult['openid'];
        $user = User::getByOpenID($openid);
        $app_user = User::getByUnionID($wxResult['unionid']);
        if (!$user) {
            //第一次登陆小程序
            $id = $this->newUser($openid, $wxResult['unionid']);
            //是非app用户  非app用户需要绑定手机,app用户直接绑定
            if (empty($app_user)) {
                return json_success('', ['id' => $id, 'token' => '', 'expire' => '', 'need_mobile' => 1]);
            }
            User::updateUidByUnionId($wxResult['unionid'], $app_user['id']);
            $user['user_id'] = $app_user['id'];
        } elseif (empty($user['user_id'])) {
            //不是第一次登陆,是否绑定手机
            if (empty($app_user)) {
                return json_success('', ['id' => $user['id'], 'token' => '', 'expire' => '', 'need_mobile' => 1]);
            }
            User::updateUidByUnionId($wxResult['unionid'], $app_user['id']);
            $user['user_id'] = $app_user['id'];
        }
        //授权token
        $token = $this->saveToCache($user['user_id']);
        return json_success('', ['id' => '', 'token' => $token, 'need_mobile' => 0]);
    }

    // token写入缓存
    public function saveToCache($uid, $token = '')
    {
        $token = empty($token) ? self::generateToken() : $token;
        $key = config('wx.token_prefix') . $token;
        $expire_in = config('wx.token_expire_in');
        Redis::set($key, $uid, $expire_in);
        $result = Redis::get($key);
        if (!$result) {
            Log::error('服务器缓存异常');
            exception("服务器异常");
        }
        return $key;
    }

    // 创建新用户
    private function newUser($openid, $unionid = '')
    {
        //创建用户
        return db('xcx_user')->insertGetId(
            [
                'open_id' => $openid,
                'union_id' => $unionid,
                'nickname' => $this->rawData->nickName,
                'head_img' => $this->rawData->avatarUrl,
                'gender' => $this->rawData->gender,
                'city' => $this->rawData->city,
                'province' => $this->rawData->province,
                'country' => $this->rawData->country,
            ]);
    }

    /**
     * 生成令牌
     * @return string
     */
    public static function generateToken()
    {
        $randChar  = Strs::randString(32);
        $timestamp = $_SERVER['REQUEST_TIME_FLOAT'];
        $tokenSalt = config('secure.token_salt');
        return md5($randChar . $timestamp . $tokenSalt);
    }

}

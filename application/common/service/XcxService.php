<?php
namespace app\common\service;

use think\Exception;
use think\Db;
use app\common\service\xcx_aes\WXBizDataCrypt;
use app\common\service\xcx_aes\ErrorCode;

class XcxService extends CommonService
{
    //用户数据
    protected $user_info;
    
    /**
     * 前端请求用户数据
     * @param string $authorization token
     * @throws Exception
     * @return mixed|boolean
     */
    public function getUserInfoByAuth($authorization)
    {
        if(empty($authorization))throw new Exception('信息为空', 401);
        $this->user_info = cache($authorization);
        if(empty($this->user_info))throw new Exception('数据为空', 401);
        return $this->user_info;
    }
    
    /**
     * 根据code获取用户微信信息
     * @param string $code  code
     * @param string $type  类型，meishi
     * @return array
     */
    public function getInfoFromWechatByCode($code, $type)
    {
        $user = ['openid'=>'xx','unionid'=>'xx','need_mobile'=>1,'token'=>'xx'];
        return ['status' => 1, 'message' => 'success', 'data' => $user];
        if(empty($code))return ['stauts' => 0, 'message' => 'code获取失败'];
        $data = [
            'appid' => config('xcx.'.$type.'_appid'),
            'secret' => config('xcx.'.$type.'_secret'),
            'js_code' => $code,
            'grant_type' => 'authorization_code'
        ];
        $res_json = self::openCurl(config('xcx.xcx_get_openid_by_code_url'), $data, false);
        $res_arr = json_decode($res_json, true);
        if(!isset($res_arr['errcode']))return ['status' => 0, 'message' => '用户信息获取失败'];
        if($res_arr['errcode'] != 0)return ['status' => 0, 'message' => $res_arr['errmsg'], 'errcode' => $res_arr['errcode']];
        if(!isset($res_arr['unionid']) || empty($res_arr['unionid']))return ['status' => 0, 'message' => '用户UNIONID获取失败'];
        $user = ['openid' => $res_arr['openid'], 'unionid' => $res_arr['unionid'], 'session_key' => $res_arr['session_key'], 'type' => $type];
        cache($res_arr['openid'], $user, 60*60*24);
        unset($user['session_key'], $user['type']);
        $need_mobile = $this->checkUser($user['unionid'], $user['openid']);
        if(empty($need_mobile['status']))return $need_mobile;
        $user['need_mobile'] = $need_mobile['status'] == -1 ? 1 : 0;
        $user['token'] = $need_mobile['status'] == -1 ? '' : $need_mobile['token'];
        return ['status' => 1, 'message' => 'success', 'data' => $user];
    }
    
    /**
     * 解密微信用户数据
     * @param string $encrypted_data    加密数据
     * @param string $iv                加密算法的初始向量
     * @param string $session_key       session_key
     * @param string $appid             小程序appid
     * @return array
     */
    public function decryptWxData($encrypted_data, $iv, $session_key, $appid)
    {
        $wx_crypt = new WXBizDataCrypt($appid, $session_key);
        $err_code = $wx_crypt->decryptData($encrypted_data, $iv, $data);
        if($err_code == 0)return ['status' => 1, 'message' => '解密成功', 'data' => json_decode($data, true)];
        return ['status' => 0, 'message' => ErrorCode::getErrorStr($err_code)];
    }
    
    /**
     * 绑定手机号码
     * @param array $web_data   绑定数据
     * ['mobile'=>'x','openid'=>'o','unionid'=>'u',...]
     * @return array
     */
    public function bindMobile(&$web_data)
    {
        $user_unionid = isset($web_data['unionid']) ? $web_data['unionid'] : '';
        $user_openid = isset($web_data['openid']) ? $web_data['openid'] : '';
        $user_mobile = isset($web_data['mobile']) ? $web_data['mobile'] : '';
        if(!($cache_user = cache($user_openid)))return ['status' => 0, 'message' => '用户信息异常，请刷新重试'];
        if($cache_user['unionid'] != $user_unionid)return ['status' => 0, 'message' => '用户信息异常，请刷新重试...'];
        if(!preg_match('/1[3456789]{1}\d{9}/', $user_mobile))return ['status' => 0, 'message' => '手机号码不正确'];
        //根据手机号码查询用户信息
        $user_by_mobile = Db::name('user')->field('id,user,number,weixin_uid,status')->where(['user' => $user_mobile])->find();
        $user_by_unionid = Db::name('user')->field('id,user,number,weixin_uid,status')->where(['weixin_uid' => $user_unionid])->find();
        if(empty($user_by_mobile) && empty($user_by_unionid)){
            //插入新用户数据
            $res = (new UserService())->createNewUser($user_mobile, ['weixin_uid' => $user_unionid]);
            if(!empty($res['status'])){
                $this->_oper_xcx_user($web_data, $cache_user, $user_mobile, $res['user_id']);
                return ['status' => 1, 'message' => '绑定成功.', 'token' => $this->rebackUserInfo($res['user_id'], $user_openid)];
            }else{
                return $res;
            }
        }else if(empty($user_by_mobile) && !empty($user_by_unionid)){
            $de_mobile = substr($user_by_unionid['user'], 0, 3).'****'.substr($user_by_unionid['user'], -4);
            return ['status' => 0, 'message' => '该微信已被账号'.$de_mobile.'绑定，若有疑问请联系客服..'];
        }else if(!empty($user_by_mobile) && empty($user_by_unionid)){
            if($user_by_mobile['status'] != 1)return ['status' => 0, 'message' => '该账户已被冻结，请联系客服'];
            if($user_by_mobile['weixin_uid'] && ($user_by_mobile['weixin_uid'] != $user_unionid))return ['status' => 0, '该手机号码授权微信与当前微信不一致'];
            //修改用户微信信息
            Db::name('user')->where(['id' => $user_by_mobile['id']])->update(['weixin_uid' => $user_unionid]);
            $this->_oper_xcx_user($web_data, $cache_user, $user_mobile, $user_by_mobile['id']);
            return ['status' => 1, 'message' => '绑定成功...', 'token' => $this->rebackUserInfo($user_by_mobile['id'], $user_openid)];
        }else if(!empty($user_by_mobile) && !empty($user_by_unionid)){
            if($user_by_mobile['id'] == $user_by_unionid['id']){
                if($user_by_mobile['status'] != 1)return ['status' => 0, 'message' => '该账户已被冻结，请联系客服'];
                $this->_oper_xcx_user($web_data, $cache_user, $user_mobile, $user_by_mobile['id']);
                return ['status' => 1, 'message' => '已绑定成功', 'token' => $this->rebackUserInfo($user_by_mobile['id'], $user_openid)];
            }else{
                return ['status' => 0, 'message' => '绑定信息已存在，绑定失败.'];
            }
        }
    }
    
    /**
     * 小程序用户信息
     * @param array $web_data       前端提交数据
     * @param array $cache_data     服务端缓存数据
     * @param number $user_mobile   用户绑定手机号码
     * @param int $user_id          用户id
     * @return boolean
     */
    private function _oper_xcx_user(&$web_data, &$cache_data, $user_mobile, $user_id)
    {
        $user = Db::name('xcx_user')->field('id,open_id')->where(['union_id' => $cache_data['unionid']])->find();
        $arr = [
            'head_img' => isset($web_data['head_img']) ? $web_data['head_img'] : '',
            'nickname' => isset($web_data['nickname']) ? $web_data['nickname'] : '',
            'gender' => isset($web_data['gender']) ? $web_data['gender'] : 0,
            'city' => isset($web_data['city']) ? $web_data['city'] : '',
            'province' => isset($web_data['province']) ? $web_data['province'] : '',
            'country' => isset($web_data['country']) ? $web_data['country'] : '',
        ];
        if(empty($user)){
            $arr['open_id'] = json_encode([$cache_data['type'] => $cache_data['openid']]);
            $arr['union_id'] = $cache_data['unionid'];
            $arr['user_mobile'] = $user_mobile;
            $arr['user_id'] = $user_id;
            $arr['created_at'] = time();
            Db::name('xcx_user')->insert($arr);
        }else{
            $temp = json_decode($user['open_id'], true);
            $temp[$cache_data['type']] = $cache_data['openid'];
            $arr['open_id'] = json_encode($temp);
            $arr['updated_at'] = time();
            Db::name('xcx_user')->where(['id' => $user['id']])->update($arr);
        }
        return true;
    }
    
    /**
     * 根据微信unionid检查用户是否需要绑定手机
     * @param string $user_unionid
     * @return array
     */
    public function checkUser($user_unionid, $user_openid)
    {
        $user_by_unionid = Db::name('user')->field('id,user,status')->where(['weixin_uid' => $user_unionid])->all();
        if(empty($user_by_unionid))return ['status' => -1, 'message' => '未查询到授权用户需绑定手机号码'];
        if(count($user_by_unionid) > 1)return ['status' => 0, 'message' => '该微信授权过多个账号，无法登录'];
        $user = $user_by_unionid[0];
        if($user['status'] != 1)return ['status' => 0, 'message' => '账号已被冻结，请联系管理员'];
        return ['status' => 1, 'message' => 'success', 'token' => $this->rebackUserInfo($user['id'], $user_openid)];
    }
    
    /**
     * 返回前端所需用户信息
     * @param int $user_id  用户id
     * @return string
     */
    private function rebackUserInfo($user_id, $user_openid)
    {
        $expire_time = 60*60*24*3;
        $reback_cache_key = '_reback_user_id_'.$user_id.$user_openid;
        if(($reback_cache_value = cache($reback_cache_key)))cache($reback_cache_value, null);
        //$user = Db::name('user')->field('id,user,number,vip,sid,weixin_uid,status')->where(['id' => $user_id])->find();
        $user_res = (new OwnerService())->getUserBaseInfo($user_id);
        $user = $user_res['status'] ? $user_res['user'] : '';
        if($user)$user['openid'] = $user_openid;
        $cache_key = md5($user_id.'_'.$user['user'].'_'.time());
        cache($reback_cache_key, $cache_key, $expire_time);
        cache($cache_key, $user, $expire_time);
        return $cache_key;
    }
}
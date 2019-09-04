<?php

namespace app\common\service;

use app\api\model\User;
use think\Db;
use think\facade\Log;
use think\Request;
use app\api\model\User as Userinfo;

/**
 * 用户
 */
class UserService extends CommonService
{
    /**
     *注册
     */
    public function _regist($data, $type)
    {
        $temp_d_ca = 300;
        $userData = [
            'user' => $data['mobile'],
            'create_time' => strtotime('now'),
            'update_time' => strtotime('now'),
            'ip' => $data['ip'],
            'd_ca' => $temp_d_ca,
            'sheng' => 0,
            'shi' => 0,
            'qu' => 0
        ];
        if (!empty($data['id'])) {
            $userData['id'] = $data['id'];
        }
        if (!empty($data['login_password'])) {
            $userData['login_password'] = md5($data['login_password']);
        }
        if (!empty($data['nick'])) {
            $userData['nick'] = $data['nick'];
        }
        if ($type == 'wx') {
            if (!empty($data['weixin_uid'])) $userData['weixin_uid'] = $data['weixin_uid'];
            if (!empty($data['weixin_openid'])) $userData['weixin_openid'] = $data['weixin_openid'];
        } elseif ($type == 'alipay') {
            if (!empty($data['ali_uid'])) $userData['ali_uid'] = $data['ali_uid'];
        }
        //注册更新
        $user_id = $this->add_or_update_user($userData);
        //回查用户信息
        $model = new UserInfo();
        $userInfo = $model->getUserInfo(["id" => $user_id]);
        //更新用户头像
        if(empty($userInfo['avator']) && !empty($data['avator'])) {
            (new UserService())->upload_wx_avator_and_update($user_id, $data['avator']);
        }
        $model->recordLoginInfo($user_id);
        $userInfo['token'] = $this->login_log($user_id, $type);
        return ['success' => true, 'data' => $userInfo];
    }

    /**
     * 获取用户推荐人
     */
    public function getUvips()
    {
        $page = request()->get("page", 1);
        $size = request()->get("size", 10);
        $shi_user = db("user")->where("level", "2")->field("id number,user,avator")->select();
        $qu_user = db("user")->where("level", "3")->field("id number,user,avator")->select();
        $users = array_merge($shi_user, $qu_user);
        if (empty($users)) {
            return json_success('success', []);
        }
        $num = count($users);
        $data = array_slice($users, ($page - 1) * $size, $size);
        foreach ($data as $k => &$v) {
            $v['avator'] = avatorUrl($v['avator']);
        }
        return json_success('success', ["count" => $num, "page" => $page, 'data' => $data]);
    }

    /**
     * 注册或更新用户信息
     * @param array $userData
     * @return int|mixed|string
     * @throws \Exception
     */
    public function add_or_update_user($userData = [])
    {
        $user_id = 0;
        if (empty($userData) || !is_array($userData)) exception('非法操作');
        try {
            //新增用户和更新
            if (empty($userData['id'])) {
                //新增
                $user_id = Db::name('user')->insertGetId($userData);
                $up_data = ['number' => $this->unicode($user_id + 666)];
                Db::name('user')->where('id', $user_id)->update($up_data);
                coin_log($user_id, 1, 1, $userData['d_ca'], 0, $userData['d_ca'], '注册赠送', 2);
                Db::commit();
            } else {
                //更新
                $user_id = $userData['id'];
                unset($userData['create_time'], $userData['d_ca'], $userData['sheng'], $userData['shi'], $userData['qu'], $userData['sid']);
                Db::name('user')->update($userData);
                Db::commit();
            }
            return $user_id;
        } catch (\Exception $e) {
            // 回滚事务
            Db::rollback();
            exception($e->getMessage());
//            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    public function login_log($uid, $type = 'mobile')
    {
        $token = md5($uid . time() . rand(10000, 99999));
        $log = [
            'uid' => $uid,
            'token' => $token,
            'type' => $type,
            'create_time' => time(),
            'device_id' => input('deviceId'),
        ];
        db("user_log")->insert($log);
        return $token;
    }

    /**
     * 更新微信头像和昵称
     * @param $uid
     * @param string $avator
     * @param string $nick
     * @return string
     */
    public function upload_wx_avator_and_update($uid, $avator = '', $nick = ''){
        try{
            $route = '';
            if(empty($uid) || (empty($avator) && empty($nick))) return '';
            if (!empty($avator) && $uid !== 0) {
                //上传微信头像到本地
                $file_name = md5($uid) .'.jpg';
                $route = $this->download($avator, $file_name);
            }
            App('app\api\model\User')->updateUserNickAndAvator($uid, $route, $nick);
        }catch (\Exception $e){
            Log::error($e->getMessage());
        }
    }

    /**
     * 是否是创始会员
     * @param $uid
     */
    public function is_member($uid){
        $member = Db::name('app_user_member_rel')->where(['app_user_id'=>$uid])->count();
        return $member > 0;
    }
    
    public function createNewUser($user_mobile, $data = [])
    {
        $d_ca = 300;
        $password = (isset($data['password']) && !empty($data['password'])) ? md5($data['password']) : md5(substr($user_mobile, -6));
        $now_time = time();
        $user_data = [
            'user' => $user_mobile,
            'ip' => request()->ip(),
            'create_time' => $now_time,
            'update_time' => $now_time,
            'd_ca' => $d_ca,
            'login_password' => $password,
            'safe_password' => $password,
            'sid' => isset($data['sid']) ? $data['sid'] : 0,
            'avator' => isset($data['avator']) ? $data['avator'] : '',
            'weixin_uid' => isset($data['union_id']) ? $data['union_id'] : '',
        ];
        
        Db::startTrans();
        try {
            $user_id = Db::name('user')->insert($user_data, false, true);
            $number = $this->unicode($user_id+666);
            Db::name('user')->where(['id' => $user_id])->update(['number' => $number]);
            coin_log($user_id, 1, 1, $d_ca, 0, $d_ca, '注册赠送', 2);
            Db::commit();
            return ['status' => 1, 'message' => '注册成功', 'user_id' => $user_id];
        } catch (\Exception $e) {
            Db::rollback();
            return ['status' => 0, 'message' => '注册失败', 'err_message' => $e->getMessage().$e->getLine().$e->getFile()];
        }
    }
}
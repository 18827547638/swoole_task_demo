<?php

/**
 * 用户信息缓存
 * @author zhanwei <615275972@qq.com>
 */

namespace app\util;

use app\common\model\Redis;
use think\Validate;
use think\Db;
use think\facade\Cache;

class caches {

    /**
     * 设置订单锁
     * 防止挂单订单重复收购/撤单
     */
    public function setGuaLock($oid, $delete = false) {
        $key = 'gua_lock_' . $oid;
        if ($delete) {
            return Cache::store('redis')->rm($key);
        }
        return Cache::store('redis')->set($key, 1);
    }

    /**
     * 提取订单锁
     */
    public function getGuaLock($oid) {
        $key = 'gua_lock_' . $oid;
        return Cache::store('redis')->get($key);
    }

    /**
     * 获取用户昵称
     */
    public function getAccount($uid, $delete = false) {
        $key = 'user_account';
        if ($delete == true) {
            return Cache::store('redis')->hDel($key, $uid);
        }
        if (($value = Cache::store('redis')->hGet($key, $uid)) == true) {
            return $value;
        }
        $where = array('id' => $uid);
        if (($value = Db::name('user')->where($where)->value('user')) == false) {
            return '';
        }
        Cache::store('redis')->hSet($key, $uid, $value);
        return $value;
    }

    /**
     * 获取商家名称
     */
    public function getShopName($shop_id, $delete = false) {
        $key = 'user_shop_name';
        if ($delete == true) {
            return Cache::store('redis')->hDel($key, $shop_id);
        }
        if (($value = Cache::store('redis')->hGet($key, $shop_id)) == true) {
            return $value;
        }
        $where = array('id' => $shop_id);
        if (($value = Db::name('meishi')->where($where)->value('title')) == false) {
            return '';
        }
        Cache::store('redis')->hSet($key, $shop_id, $value);
        return $value;
    }

    /**
     * 获取用户头像
     */
    public function getUserAvatar($uid, $delete = false) {
        $key = 'user_avatar_cache';
        if ($delete == true) {
            return Cache::store('redis')->hDel($key, $uid);
        }
        if (($value = Cache::store('redis')->hGet($key, $uid)) == true) {
            return $value;
        }
        $where = array('id' => $uid);
        if (($value = Db::name('user')->where($where)->value('avator')) == false) {
            return 'http://api.lmsggdc.com/static/logo.png';
        }else{
            if(!strstr($value,'http://api.lmsggdc.com')){
                $value='http://api.lmsggdc.com/static/logo.png';
            }
        }
        Cache::store('redis')->hSet($key, $uid, $value);
        return $value;
    }

    /**
     * 通过手机号码查找用户uid
     */
    public function getUserUidByMobile($mobile, $delete = false) {
        $key = 'user_uid_mobile';
        if ($delete == true) {
            return Cache::store('redis')->hDel($key, $mobile);
        }
        if (($value = Cache::store('redis')->hGet($key, $mobile)) != false) {
            return $value;
        }
        $where = array('user' => $mobile);
        if (($value = Db::name('user')->where($where)->value('id')) == false) {
            return 0;
        }
        Cache::store('redis')->hSet($key, $mobile, $value);
        return $value;
    }


    /**
     * 通过id查找用户手机号码
     */
    public function getUserMobileById($uid, $delete = false) {
        $key = 'user_mobile_uid';
        if ($delete == true) {
            return Cache::store('redis')->hDel($key, $uid);
        }
        if (($value = Cache::store('redis')->hGet($key, $uid)) != false) {
            return $value;
        }
        $where = array('id' => $uid);
        if (($value = Db::name('user')->where($where)->value('user')) == false) {
            return 0;
        }
        Cache::store('redis')->hSet($key, $uid, $value);
        return $value;
    }

    /**
     * 获取编号
     */
    public function getUserNumber($uid, $delete = false) {
        $key = 'user_number';
        if ($delete == true) {
            return Cache::store('redis')->hDel($key, $uid);
        }
        if (($value = Cache::store('redis')->hGet($key, $uid)) != false) {
            return $value;
        }
        $where = array('id' => $uid);
        if (($value = Db::name('user')->where($where)->value('number')) === null) {
            return '';
        }
        Cache::store('redis')->hSet($key, $uid, $value);
        return $value;
    }

    /**
     * 获取用户id
     */
    public function getUserUidByNumber($number, $delete = false) {
        $key = 'user_uid_number';
        if ($delete == true) {
            return Cache::store('redis')->hDel($key, $number);
        }
        if (($value = Cache::store('redis')->hGet($key, $number)) != false) {
            return $value;
        }
        $where = array('number' => $number);
        if (($value = Db::name('user')->where($where)->value('id')) === null) {
            return '';
        }
        Cache::store('redis')->hSet($key, $number, $value);
        return $value;
    }

    /**
     * 获取推荐人id
     */
    public function getShareUid($uid, $delete = false) {
        $key = 'user_share_uid';
        if ($delete == true) {
            return Cache::store('redis')->hDel($key, $uid);
        }
        if (($value = Cache::store('redis')->hGet($key, $uid)) != false) {
            return $value;
        }
        $where = array('id' => $uid);
        if (($value = Db::name('user')->where($where)->value('sid')) == false) {
            return 0;
        }
        Cache::store('redis')->hSet($key, $uid, $value);
        return $value;
    }

    /**
     * 获取配置
     */
    public function getConfig($index, $delete = false) {
        $key = 'system_config';
        if ($delete == true) {
            return Cache::store('redis')->hDel($key, $index);
        }
        if (($value = Cache::store('redis')->hGet($key, $index)) == true) {
            return $value;
        }
        $where = array('key' => $index);
        if (($value = Db::name('system')->where($where)->value('value')) == false) {
            return '';
        }
        Cache::store('redis')->hSet($key, $index, $value);
        return $value;
    }

    /**
     * 收款码设置
     */
    public function setQrcode($key, $value, $delete = false) {
        $keys = 'imcome_code_' . $key;
        if ($delete) {
            Cache::store('redis')->rm($keys);
        }
        return Cache::store('redis')->set($keys, $value);
    }

    /**
     * 获取收款码
     */
    public function getQrcode($key) {
        $keys = 'imcome_code_' . $key;
        return Cache::store('redis')->get($keys);
    }


}

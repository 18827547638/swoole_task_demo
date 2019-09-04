<?php

namespace app\api\model;


class User extends BaseModel
{
    protected $autoWriteTimestamp = true;
    //    protected $createTime = ;
    /**
     * 用户是否存在
     * 存在返回uid，不存在返回0
     */
    public static function getByOpenID($id)
    {
        $user = db('xcx_user')->alias('xu')
            ->where(['open_id' => $id])
            ->field('open_id,head_img,city,gender,nickname,province,county,user_mobile,user_id')
            ->find();
        return $user;
    }

    /**
     * 用户是否存在
     * 存在返回uid，不存在返回0
     */
    public static function getByUnionID($id)
    {
        $user = db('user')
            ->where(['weixin_uid' => $id])
            ->find();
        return $user;
    }

    /**
     * 更新小程序表中user_id
     * 存在返回uid，不存在返回0
     */
    public static function updateUidByUnionId($id,$uid)
    {
        $result = db('xcx_user')
            ->update(['user_id'=>$uid,'union_id'=>$id]);
        if(empty($result)) exception('登陆失败');
        return $result;
    }
}
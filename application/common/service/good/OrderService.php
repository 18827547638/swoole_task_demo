<?php

namespace app\common\service\good;

/**
 * 订单
 */
class OrderService
{
    /**
     * 获取会员专属商品购买次数
     * @param $good_id
     * @param $uid
     * @return mixed
     * @throws \Exception
     */
    public function get_member_good_num_by_good_id_uid($good_id, $uid)
    {
        !(empty($good_id) || empty($uid)) || exception('参数错误');
        return db('app_good_order')->alias('ago')
            ->leftJoin('app_good_order_detail agod', 'ago.id = agod.order_id')
            ->where(['ago.uid' => $uid, 'agod.good_type' => 1, 'agod.good_id' => $good_id])
            ->whereIn('ago.status', '2,4,5')
            ->count();
    }
}
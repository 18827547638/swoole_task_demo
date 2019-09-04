<?php

namespace app\common\service\good;

use think\Db;

/**
 * 商场--商品
 */
class GoodService
{

    /**
     * 根据商品id获取商品库存,兼容秒杀活动
     * @param $good_id
     * @return float|int
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getGoodSpecNumById($good_id)
    {
        if (empty($good_id)) return 0;
        $activity = Db::name('app_good_activity')->where('good_id', $good_id)->find();
        $spec = Db::name('app_good_rel_spec')->where('app_good_id', $good_id)->select();
        if (empty($spec)) return 0;
        $num = 0;
        if (!empty($activity) && $activity['activity_type'] == 1) {
            //秒杀活动
            if (isMiaoSha($activity['activity_type'], $activity['start_time'], $activity['end_time']) || $activity['start_time'] > time()) {
                //即将开抢和正在抢的库存
                $num = $this->spec_num_sum($spec, 1);
            } elseif ($activity['end_time'] < time()) {
                //已结束库存
                $num = $this->spec_num_sum($spec, 0);
            }
        } else {
            //普通商品
            $num = $this->spec_num_sum($spec, 0);
        }
        return $num;

    }

    /**
     * 计算规格库存
     * @param $spec
     * @param int $activity
     * @return float|int
     */
    private function spec_num_sum($spec, $activity = 0)
    {
        $num = 0;
        if ($activity == 1) {
            foreach ($spec as $_spec) {
                if ($_spec['activity_price'] > 0) {
                    $num += $_spec['activity_num'];
                } else {
                    $num += $_spec['spec_num'];
                }
            }
        } else {
            $num = array_sum(array_column($spec, 'spec_num'));
        }
        return $num;
    }
    /**
     * 检查会员商品购买资格及数量
     * @param $good_id
     * @param $uid
     * @throws \Exception
     */
    public function check_member_and_num($good_id, $uid, $num){
        $good = Db::name('app_good')->where(['id'=>$good_id,'status'=>1])->field('id,buy_max,buy_num,good_type')->find();
        if(!empty($good)){
            //会员商品
            if($good['good_type'] == 1){
                if(!(app('app\common\service\UserService')->is_member($uid))){
                    exception('只有会员才能购买会员专属商品!');
                }
                //会员专属商品购买次数判断
                $buy_num = app('app\common\service\good\OrderService')->get_member_good_num_by_good_id_uid($good_id, $uid);
                $buy_num < $good['buy_num'] || exception('没有会员专属商品的购买次数了');
            }
            //购买数量判断
            $num <= $good['buy_max'] || exception('会员专属商品每次最多限购' . $good['buy_max'] . '件');
        } else {
            exception('无效商品!');
        }
    }

    /**
     * 是否是会员商品
     * @param $good_id
     * @return bool
     */
    public function is_member_goods($good_id){
        $count = Db::name('app_good')->where(['good_type'=>1,'id'=>$good_id,'status'=>1])->count();
        return $count > 0;
    }


}
<?php
namespace app\common\model;

use think\Db;

class GoodService
{
    /**
     * 根据交易单号或商品类别查找相似产品
     * @param string $trade_str  交易号
     * @param array $cate_ids    商品类别
     * @return array
     */
    public function equalGoods($trade_str = '', $cate_ids = [])
    {
        if($trade_str){
            $cate_ids = Db::name('app_good_order_share_rate')->alias('agosr')
            ->leftJoin('app_good ag', 'agosr.good_id=ag.id')
            ->where(['agosr.trade_str' => $trade_str])->column('ag.cate_id');
        }
        
        $str = '';
        $sql = 'select id,shop_name,pic,sall,shop_money from app_good where id>=(select floor(rand()*(select max(id) from app_good))) and status=1';
        if($cate_ids)$str = ' and cate_id in ('.implode(',', $cate_ids).') ';
        $limit_str = ' limit 12';
        $sql .= $str.$limit_str;
        
        $list = Db::query($sql);
        if($list){
            $good_ids = array_column($list, 'id');
            $spec = Db::name('app_good_rel_spec')->where('app_good_id', 'in', $good_ids)->orderRaw('spec_value*1 desc')->column('id,spec_value,point_money,app_good_id', 'app_good_id');
            foreach($list as $key => $val){
                //现金值和最低价格
                $list[$key]['cash_money'] = isset($spec[$val['id']]) ? $spec[$val['id']]['point_money'] : '0.00';
                $list[$key]['spec_value'] = isset($spec[$val['id']]) ? $spec[$val['id']]['spec_value'] : $val['shop_money'];
                $list[$key]['spec_id'] = isset($spec[$val['id']]) ? $spec[$val['id']]['id'] : 0;
            }
        }
        return $list;
    }

}
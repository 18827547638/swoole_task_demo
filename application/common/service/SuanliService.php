<?php
namespace app\common\service;

use think\Db;

class SuanliService extends CommonService
{
    private static function _get_item_cate($item)
    {
        $arr = [
            'i_money' => 4,
            'd_ca' => 1,
            'd_gc' => 2,
            'ca_money' => 1,
            'gc_money' => 2,
            'share_point' => 3
        ];
        return isset($arr[$item]) ? $arr[$item] : 0;
    }
    private function _create_coin_log($item, $type, $num, $record, $frogen, $now_time, $remark)
    {
        $now_money = ($type == 1) ? ($record[$item] + $num) : max(0, $record[$item] - $num);
        $arr = [
            'coin_id' => self::_get_item_cate($item),
            'type' => $type,
            'frogen' => $frogen,
            'num' => $num,
            'old_money' => $record[$item],
            'now_money' => $now_money,
            'remark' => $remark,
            'uid' => $record['id'],
            'create_time' => $now_time
        ];
        Db::name('user_coin_log')->insert($arr);
    }
    public function suanli($record, $item, $type, $count, $frogen, $now_time, $remark)
    {
        if($record && ($count > 0)){
            if($type == 2 && $record[$item] < $count)return true;
            $handle = Db::name('user')->where(['id' => $record['id']]);
            if($type == 1){
                $handle->setInc($item, $count);
            }else{
                $handle->setDec($item, $count);
            }
            $this->_create_coin_log($item, $type, $count, $record, $frogen, $now_time, $remark);
        }
        return true;
    }
    
    /**
     * 公司算力
     * @param string $item
     * @param int $type
     * @param int $count
     * @param int $frogen
     * @param int $now_time
     * @param string $remark
     * @return boolean
     */
    public function companySuanli($item, $type, $count, $frogen, $now_time, $remark)
    {
        $company = Db::name('user')->field('id,i_money,d_ca,d_gc,ca_money,gc_money,share_point')->where(['user' => self::COMPANY_MOBILE])->find();
        return $this->suanli($company, $item, $type, $count, $frogen, $now_time, $remark);
    }
    
    /**
     * 用户算力
     * @param int $uid
     * @param string $item
     * @param int $type
     * @param int $count
     * @param int $frogen
     * @param int $now_time
     * @param string $remark
     * @return boolean
     */
    public function userSuanli($uid, $item, $type, $count, $frogen, $now_time, $remark)
    {
        $user = Db::name('user')->field('id,i_money,d_ca,d_gc,ca_money,gc_money,share_point')->where(['id' => $uid])->find();
        return $this->suanli($user, $item, $type, $count, $frogen, $now_time, $remark);
    }
}
<?php
namespace app\common\service;

use think\Db;
/**
 * 店铺折扣
 *
 */
class StoreDiscountService extends CommonService
{
    //单个店铺每周折扣缓存key
    const SINGLE_STORE_DISCOUNT_CACHE_KEY = '_single_store_discount_cache_key_';
    
    /**
     * 获取店铺每周折扣
     * @param int $store_id
     * @return array
     */
    public static function getStoreDiscount($store_id)
    {
        $cache_key = self::SINGLE_STORE_DISCOUNT_CACHE_KEY.$store_id;
        if(($cache_value = cache($cache_key)))return $cache_value;
        
        $store_discount =Db::name('app_food_store_discount')->field('id,store_id,week,start_time,end_time,rate,count,created_at')
        ->where(['store_id' => $store_id])->order(['week' => 'asc', 'start_time' => 'asc'])->all();
        cache($cache_key, $store_discount, (60*60*24*7));
        return $store_discount;
    }
    
    /**
     * 删除每周折扣缓存
     * @param int $store_id
     * @return boolean
     */
    public static function deleteStoreDiscountCache($store_id)
    {
        $cache_key = self::SINGLE_STORE_DISCOUNT_CACHE_KEY.$store_id;
        if(cache($cache_key))cache($cache_key, null);
        return true;
    }
    
    /**
     * 获取某一折扣下的所有店铺id
     * @param number $rate  折扣
     * @param string $oper  比较符
     * @return array
     */
    public static function getStoreIdByRate($rate, $oper = '=')
    {
        $cache_key = '_get_store_id_by_rate_'.$rate.$oper;
        if(($cache_value = cache($cache_key)))return $cache_value;
        
        $store_ids = Db::name('app_food_store_discount')->where([['rate', $oper, $rate]])->column('store_id');
        $store_ids = $store_ids ? array_unique($store_ids) : [];
        cache($cache_key, $store_ids, 60*60);
        return $store_ids;
    }
    
    /**
     * 整理店铺每周折扣数据
     * @param array $data
     * @return array
     */
    private function _arrange_store_discount_data($data)
    {
        if(empty($data))return ['status' => 0, 'message' => '提交数据为空'];
        if(!isset($data['uid']) || empty($data['uid']))return ['status' => 0, 'message' => '用户信息为空'];
        if(!isset($data['store_id']) || empty($data['store_id']))return ['status' => 0, 'message' => '店铺信息为空'];
        if(!isset($data['week']) || !in_array($data['week'], [0,1,2,3,4,5,6]))return ['status' => 0, 'message' => '请选择星期'];
        if(!isset($data['start_time']) || empty($data['start_time']))return ['status' => 0, 'message' => '请选择开始时间'];
        if(!isset($data['end_time']) || empty($data['end_time']))return ['status' => 0, 'message' => '请选择结束时间'];
        if(strtotime($data['end_time']) <= strtotime($data['start_time']))return ['status' => 0, 'message' => '结束时间不能小于开始时间'];
        if(!isset($data['rate']) || ($data['rate'] <= 0))return ['status' => 0, 'message' => '请选择折扣'];
        $data['rate'] = round($data['rate'], 2);
        if($data['rate'] <= 0 || $data['rate'] >=1)return ['status' => 0, 'message' => '折扣请上传0到1间的小数'];
        if(!isset($data['count']) || ($data['count'] <= 0))return ['status' => 0, 'message' => '请输入数量'];
        return ['status' => 1, 'message' => 'success', 'data' => $data];
    }
    
    /**
     * 检查时间区间是否正确
     * @param array $store_discount   已存在的折扣数据
     * @param int $id                 记录id
     * @param int $week               星期
     * @param string $start_time      开始时间
     * @param string $end_time        结束时间
     * @return array
     */
    private function _check_week_time(&$store_discount, $id, $week, $start_time, $end_time)
    {
        $start_time = strtotime($start_time);
        $end_time = strtotime($end_time);
        foreach($store_discount as $key => $val){
            if($id && ($val['id'] == $id))continue;
            if($week != $val['week'])continue;
            $temp_start = strtotime($val['start_time']);
            $temp_end = strtotime($val['end_time']);
            if($start_time >= $temp_start && $start_time < $temp_end)return ['status' => 0, 'message' => '开始区间已存在'];
            if($end_time > $temp_start && $end_time <= $temp_end)return ['status' => 0, 'message' => '结束区间已存在'];
        }
        return ['status' => 1, 'message' => 'success'];
    }
    
    /**
     * 修改/添加店铺每周折扣数据
     * @param array $input
     * @return array
     */
    public function operStoreDiscount($input)
    {
        //提交数据
        $check_res = $this->_arrange_store_discount_data($input);
        if(empty($check_res['status']))return $check_res;
        $data = $check_res['data'];
        //店铺
        $store = (new FoodService())->_get_store_by_user_id($data['uid']);
        if(empty($store))return ['status' => 0, 'message' => '未查询到店铺信息'];
        if($store['id'] != $data['store_id'])return ['status' => 0, 'message' => '店铺信息不正确'];
        //折扣
        $store_discount = self::getStoreDiscount($store['id']);
        $id = (isset($data['id']) && $data['id']) ? $data['id'] : 0;
        //时间区间
        $check_time = $this->_check_week_time($store_discount, $id, $data['week'], $data['start_time'], $data['end_time']);
        if(empty($check_time['status']))return $check_time;
        
        $arr = [
            'store_id' => $data['store_id'],
            'week' => $data['week'],
            'start_time' => $data['start_time'],
            'end_time' => $data['end_time'],
            'rate' => $data['rate'],
            'count' => $data['count'],
            'created_by' => $data['uid'],
        ];
        if($id){
            $arr['updated_at'] = time();
            Db::name('app_food_store_discount')->where([['id', '=', $id], ['store_id', '=', $store['id']]])->update($arr);
        }else{
            $arr['created_at'] = time();
            Db::name('app_food_store_discount')->insert($arr);
        }
        self::deleteStoreDiscountCache($store['id']);
        return ['status' => 1, 'message' => '操作成功'];
    }
    
    /**
     * 删除店铺每周折扣数据，可删除多条
     * @param int|array $id   记录id
     * @param int $store_id   店铺id
     * @return array
     */
    public function delStoreDiscount($id, $store_id)
    {
        if(empty($id) || empty($store_id))return ['status' => 0, 'message' => '提交数据为空'];
        $temp_id = is_array($id) ? $id : [$id];
        Db::name('app_food_store_discount')->where([['store_id', '=', $store_id], ['id', 'in', $temp_id]])->delete();
        self::deleteStoreDiscountCache($store_id);
        return ['status' => 1, 'message' => '删除成功'];
    }
    
    /**
     * 获取每周的店铺折扣数据
     * @param int $store_id
     * @return array
     */
    public function getStoreDiscountByWeek($store_id)
    {
        $store_discount = self::getStoreDiscount($store_id);
        if(empty($store_discount))return ['status' => 1, 'message' => 'success', 'data' => []];
        
        $arr = [];
        foreach($store_discount as $key => $val){
            $arr[$val['week']]['week'] = $val['week'];
            $arr[$val['week']]['store_id'] = $val['store_id'];
            $arr[$val['week']]['week_str'] = self::getWeekStr($val['week']);
            $arr[$val['week']]['discount'][] = $val;
        }
        sort($arr);
        $store_discount = null;
        return ['status' => 1, 'message' => 'success', 'data' => $arr];
    }
    
    /**
     * 针对当天店铺折扣排序，未过时间段排前面，已过排后面
     * @param int $store_id 店铺id
     * @return array
     */
    public function getStoreDiscountByWeekTodaySort($store_id)
    {
        $res = $this->getStoreDiscountByWeek($store_id);
        $data = $res['data'];
        if(empty($data))$data;
        
        $week = self::getWeekStr(date('w'));
        $today_start = date('H:i');
        //排序，当天折扣已过时间段排在后面，未过排在前面
        foreach($data as $key => $val){
            if(($week == $val['week_str']) && $val['discount']){
                $head = $tail = [];
                foreach($val['discount'] as $va){
                    ($va['end_time'] >= $today_start) ? array_push($head, $va) : array_push($tail, $va);
                }
                $data[$key]['discount'] = array_merge($head, $tail);
                $head = $tail = null;
            }
        }
        return $data;
    }
    
    /**
     * 计算具体的折扣时间戳
     * @param array $store_discount  折扣记录
     * @param int $now_time          当前时间戳
     * @return array
     */
    private function _count_time($store_discount, $now_time)
    {
        //今日星期
        $now_week = date('w', $now_time);
        //折扣星期与今日相隔天数
        $dec_week = $store_discount['week'] - $now_week;
        $days = ($dec_week >= 0) ? $dec_week : (7+$dec_week);
        //具体的折扣开始时间戳
        $flag_start_time = strtotime($store_discount['start_time'].' +'.$days.' day');
        //具体的折扣结束时间戳
        $flag_end_time = strtotime($store_discount['end_time'].' +'.$days.' day');
        if($flag_end_time <= $now_time)return ['status' => 0, 'message' => '折扣时间已过，请选择其他折扣'];
        return ['status' => 1, 'message' => 'success', 'data' => $flag_start_time];
    }
    
    /**
     * 获取单店单折扣记录数据
     * @param int $store_id     店铺id
     * @param int $id           记录id
     * @return boolean|array
     */
    private function _get_single_store_discount_by_id($store_id, $id)
    {
        $discount = self::getStoreDiscount($store_id);
        if(empty($discount))return false;
        foreach($discount as $val){
            if($val['id'] == $id)return $val;
        }
        return false;
    }
    /**
     * 用户领取店铺每周折扣
     * @param int $user_id      用户id
     * @param int $store_id     店铺id
     * @param int $discount_id  折扣id
     * @return array
     * TODO 并发控制
     */
    public function userReceiveDiscount($user_id, $store_id, $discount_id)
    {
        $now_time = time();
        if(empty($user_id) || empty($store_id) || empty($discount_id))return ['status' => 0, 'message' => '提交数据为空'];
        
        $cache_key = '_user_receive_discount_limit_'.$user_id.'_'.$store_id.'_'.$discount_id;
        if(cache($cache_key))return ['status' => 0, 'message' => '操作过于频繁，请稍后再试...'];
        cache($cache_key, true, 5);
        
        //查询店铺折扣
        $store_discount = $this->_get_single_store_discount_by_id($store_id, $discount_id);
        if(empty($store_discount))return ['status' => 0, 'message' => '折扣不存在'];
        //具体的折扣时间戳
        $count_time_res = $this->_count_time($store_discount, $now_time);
        if(empty($count_time_res['status']))return $count_time_res;
        $flag_start_time = $count_time_res['data'];
        //计算已领取的数量
        $receive_count = Db::name('app_food_user_store_discount')->where(['store_id' => $store_id, 'discount_id' => $discount_id, 'flag_time' => $flag_start_time])->count();
        if($receive_count >= $store_discount['count'])return ['status' => 0, 'message' => '该折扣已领完...'];
        //判断是否领取过该折扣
        $user_rec = Db::name('app_food_user_store_discount')->field('id,updated_at')->where(['user_id' => $user_id, 'store_id' => $store_id, 'discount_id' => $discount_id, 'flag_time' => $flag_start_time])->find();
        if(!empty($user_rec))return empty($user_rec['updated_at']) ? ['status' => 1,'message' => '领取成功', 'data' => $user_rec['id']] : ['status' => 0, 'message' => '已领取过该折扣'];
        
        //插入领取记录
        $arr = [
            'user_id' => $user_id,
            'store_id' => $store_id,
            'discount_id' => $discount_id,
            'flag_time' => $flag_start_time,
            'rate' => $store_discount['rate'],
            'created_at' => $now_time
        ];
        $insert_id = Db::name('app_food_user_store_discount')->insert($arr, false, true);
        return ['status' => 1, 'message' => '领取成功', 'data' => $insert_id];
    }
}
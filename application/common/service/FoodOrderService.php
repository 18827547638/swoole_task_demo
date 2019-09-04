<?php
namespace app\common\service;

use think\Db;

class FoodOrderService extends CommonService
{
    /**
     * 获取单店铺订单数据
     * @param int $user_id      当前用户id
     * @param int $store_id     店铺id
     * @param int $status       订单状态，1未到店，2已到店，3已完成，4已取消
     * @param int $page         当前页码
     * @param int $page_size    每页数量
     * @return array
     */
    public function getFoodStoreOrder($user_id, $store_id, $status, $page = 1, $page_size = 10)
    {
        if(!in_array($status, [1, 2, 3, 4]))return ['status' => 0, 'message' => '订单状态不正确'];
        $store = (new FoodService())->_get_store_by_user_id($user_id);
        if(empty($store) || ($store['id'] != $store_id))return ['status' => 0, 'message' => '店铺信息不正确'];
        
        $where = [['store_id', '=', $store_id]];
        $where[] = ($status != 4) ? ['status', '=', 2] : ['status', '=', 6];
        if($status == 1){//未到店
            $where[] = ['arrive_time', '=', 0];
        }else if($status == 2){//已到店
            $where[] = ['arrive_time', '>', 0];
            $where[] = ['check_time', '=', 0];
        }else if($status == 3){//已完成
            $where[] = ['arrive_time', '>', 0];
            $where[] = ['check_time', '>', 0];
        }
        $handle = Db::name('app_food_order')->field('order_no,user_name,user_mobile,people_count,pre_time,check_number')->where($where);
        $count = $handle->count();
        $page_count = ceil($count/$page_size);
        $page_start = ($page_count >= $page) ? max(0, $page-1)*$page_size : 0;
        $list = $handle->order(['id' => 'desc'])->limit($page_start, $page_size)->all();
        foreach($list as $key => $val){
            $list[$key]['pre_time'] = self::_get_food_store_order_show_date($val['pre_time']);
        }
        
        $data = ['count' => $count, 'list' => $list];
        return ['status' => 1, 'message' => 'success', 'data' => $data];
    }
    
    /**
     * 订单列表展示时间
     * @param int $time     时间戳
     * @return string
     */
    private static function _get_food_store_order_show_date($time)
    {
        $week = self::getWeekStr(date('w', $time), '周');
        return date('Y-m-d H:i', $time).' ('.$week.')';
    }
    
    /**
     * 核销订单
     * @param int $user_id      当前用户id
     * @param int $store_id     店铺id
     * @param str $num          核销券号
     * @return array
     */
    public function checkFoodStoreOrder($user_id, $store_id, $num)
    {
        $now_time = time();
        if(empty($user_id) || empty($store_id) || empty($num))return ['status' => 0, 'message' => '提交数据为空'];
        $store = (new FoodService())->_get_store_by_user_id($user_id);
        if(empty($store) || ($store['id'] != $store_id))return ['status' => 0, 'message' => '未查询到店铺数据'];
        
        $cache_key = '_check_food_order_'.$num;
        if(cache($cache_key))return ['status' => 0, 'message' => '操作过于频繁...'];
        cache($cache_key, true, 5);
        
        $where = [['check_number', '=', $num], ['store_id', '=', $store_id], ['status', '=', 2], ['check_time', '=', 0]];
        $order = Db::name('app_food_order')->field('id,order_no,pay_type,real_price')->where($where)->find();
        if(empty($order))return ['status' => 0, 'message' => '订单数据异常或已核销'];
        
        Db::startTrans();
        try {
            $res = Db::name('app_food_order')->where(['id' => $order['id']])->update(['check_time' => $now_time]);
            if($res){//销券成功之后，商家返回支付金额百分之九十的余额
                $reback_money = round($order['real_price']*0.9, 2);
                if(in_array($order['pay_type'], [2, 3, 4]) && ($reback_money > 0)){
                    (new SuanliService())->userSuanli($store['uid'], 'i_money', 1, $reback_money, 3, $now_time, '店铺消费');
                    $this->insertIncome($store['uid'], $order['order_no'], $reback_money);
                }
            }
            Db::commit();
            return ['status' => 1, 'message' => '核销成功'];
        } catch (\Exception $e) {
            Db::rollback();
            return ['status' => 0, 'message' => '核销失败，请刷新重试'];
        }
    }
    
    /**
     * 订单商品详情
     * @param string $order_no  订单号
     * @return array
     */
    public function foodStoreOrderDetail($order_no)
    {
        //查订单
        $order = Db::name('app_food_order')->field('rate,order_price')->where(['order_no' => $order_no, 'status' => 2])->find();
        if(empty($order))return ['status' => 0, 'message' => '订单不存在'];
        $detail = Db::name('app_food_order_detail')->field('name,num,sell_price')->where(['order_no' => $order_no])->all();
        
        $data = ['rate' => $order['rate'], 'detail' => $detail];
        return ['status' => 1, 'message' => 'success', 'data' => $data];
    }
    
    /**
     * 到店操作
     * @param string $order_no  订单号
     * @param string $store_id  店铺id
     * @return array
     */
    public function customerArriveStore($order_no, $store_id)
    {
        if(empty($order_no) || empty($store_id))return ['status' => 0, 'message' => '提交数据为空'];
        
        $order = Db::name('app_food_order')->field('id,arrive_time')->where(['order_no' => $order_no, 'store_id' => $store_id, 'status' => 2])->find();
        if(empty($order))return ['status' => 0, 'message' => '订单数据不存在'];
        if(!empty($order['arrive_time']))return ['status' => 0, 'message' => '该订单已到店'];
        
        $res = Db::name('app_food_order')->where(['id' => $order['id']])->update(['arrive_time' => time()]);
        return $res ? ['status' => 1, 'message' => '操作成功'] : ['status' => 0, 'message' => '操作失败，请刷新重试'];
    }
    
    /**
     * 店铺营业统计
     * @param int $store_id 店铺id
     * @param int $type     统计类型，1本日，2本周，3本月
     * @param int $user_id  当前用户id
     * @return array
     */
    public function censusFoodStoreOrder($store_id, $type, $user_id)
    {
        if(empty($store_id) || empty($user_id))return ['status' => 0, 'message' => '店铺或用户数据为空'];
        if(!in_array($type, [1, 2, 3]))return ['status' => 0, 'message' => '类型不正确'];
        $store = (new FoodService())->_get_store_by_user_id($user_id);
        if(empty($store) || ($store['id'] != $store_id))return ['status' => 0, 'message' => '未查询到店铺数据'];
        
        $now_time = time();
        if($type == 1){
            $start_time = strtotime(date('Y-m-d', $now_time));
        }else if($type == 2){
            $week = date('w', $now_time);
            $days = $week ? max(0, $week-1) : 6;
            $start_time = strtotime(date('Y-m-d', $now_time).' -'.$days.' day');
        }else if($type == 3){
            $start_time = strtotime(date('Y-m', $now_time));
        }
        
        $where = [['store_id', '=', $store_id], ['status', '=', 2], ['updated_at', 'between', [$start_time, $now_time]]];
        $arrive = Db::name('app_food_order')->field('sum(real_price) as money,count(*) as count')->where(array_merge($where, [['arrive_time', '>', 0]]))->find();
        $not_arrive = Db::name('app_food_order')->field('sum(real_price) as money,count(*) as count')->where(array_merge($where, [['arrive_time', '=', 0]]))->find();
        
        $arr = [
            'over_money' => isset($arrive['money']) ? $arrive['money'] : 0,
            'over_count' => isset($arrive['count']) ? $arrive['count'] : 0,
            'not_money' => isset($not_arrive['money']) ? $not_arrive['money'] : 0,
            'not_count' => isset($not_arrive['count']) ? $not_arrive['count'] : 0,
        ];
        $arr['total_money'] = $arr['over_money'] + $arr['not_money'];
        $arr['total_count'] = $arr['over_count'] + $arr['not_count'];
        
        return ['status' => 1, 'message' => 'success', 'data' => $arr];
    }
    
    /**
     * 整理下单数据
     * @param array $input
     * @return array
     */
    private function _arrange_place_order_data($input)
    {
        if(!isset($input['uid']) || empty($input['uid']))return ['status' => 0, 'message' => '用户信息不存在'];
        if(!isset($input['store_id']) || empty($input['store_id']))return ['status' => 0, 'message' => '店铺信息不存在'];
        if(!isset($input['people_count']) || empty($input['people_count']))return ['status' => 0, 'message' => '请选择用餐人数'];
        if(!isset($input['foods']) || empty($input['foods']))return ['status' => 0, 'message' => '请选择菜品'];
        
        return ['status' => 1, 'message' => 'success', 'data' => $input];
    }
    
    /**
     * 订单详情商品数据
     * @param array $data   提交数据
     * @param string $order_no   订单号
     * @param int $now_time     时间戳
     * @return array
     */
    private function _get_detail_food_data(&$data, $order_no, $now_time)
    {
        $ids = array_column($data['foods'], 'id');
        $sys_foods = Db::name('app_food')->where([['store_id', '=', $data['store_id']], ['id', 'in', $ids]])->column('id,name,thumb_img,sell_price,point_money', 'id');
        if(empty($sys_foods))return ['status' => 0, 'message' => '未查询到商品'];
        
        $order_price = $point_money = 0;
        $detail_data = [];
        foreach($data['foods'] as $key => $val){
            $temp = [
                'user_id' => $data['uid'],
                'store_id' => $data['store_id'],
                'order_no' => $order_no,
                'food_id' => $val['id'],
                'name' => isset($sys_foods[$val['id']]) ? $sys_foods[$val['id']]['name'] : '',
                'thumb_img' => isset($sys_foods[$val['id']]) ? $sys_foods[$val['id']]['thumb_img'] : '',
                'num' => max(1, $val['num']),
                'sell_price' => isset($sys_foods[$val['id']]) ? $sys_foods[$val['id']]['sell_price'] : 0,
                'point_money' => isset($sys_foods[$val['id']]) ? $sys_foods[$val['id']]['point_money'] : 0,
                'status' => 1,
                'created_at' => $now_time
            ];
            Db::name('app_food')->where(['id' => $temp['food_id']])->setInc('sell_count', $temp['num']);
            $order_price += ($temp['num'] * $temp['sell_price']);
            $point_money += ($temp['num'] * $temp['point_money']);
            $detail_data[] = $temp;
            $temp = null;
        }
        $order_price = round($order_price, 2);
        $point_money = round($point_money, 2);
        if($order_price <= 0)return ['status' => 0, 'message' => '订单金额小于0'];
        $arr = [
            'detail_data' => $detail_data,
            'order_price' => $order_price,
            'point_money' => $point_money
        ];
        return ['status' => 1, 'message' => 'success', 'data' => $arr];
    }
    
    /**
     * 订单数据
     * @param array $data       提交数据
     * @param number $rate      折扣
     * @param int $flag_time    折扣时间戳
     * @param string $order_no  订单号
     * @param string $trade_str 交易号
     * @param array $detail     订单详情数据
     * @param int $now_time     时间戳
     * @return array
     */
    private function _get_food_order_data(&$data, $rate, $flag_time, $order_no, $trade_str, $detail, $now_time)
    {
        $order_data = [
            'user_id' => $data['uid'],
            'store_id' => $data['store_id'],
            'order_no' => $order_no,
            'trade_str' => $trade_str,
            'rate' => $rate,
            'rate_time' => $flag_time,
            'order_price' => $detail['order_price'],
            'real_price' => round($detail['order_price'] * $rate, 2),
            'point_money' => $detail['point_money'],
            'people_count' => $data['people_count'],
            'pay_type' => isset($data['pay_type']) ? $data['pay_type'] : 0,
            'status' => 1,
            'check_number' => self::createRandomNumber(12),
            'pre_time' => $flag_time,
            'user_name' => isset($data['user_name']) ? trim($data['user_name']) : '',
            'user_mobile' => isset($data['user_mobile']) ? trim($data['user_mobile']) : '',
            'remark' => isset($data['remark']) ? trim($data['remark']) : '',
            'created_at' => $now_time
        ];
        if($order_data['real_price'] <= 0)return ['status' => 0, 'message' => '支付金额小于0'];
        return ['status' => 1, 'message' => 'success', 'data' => $order_data];
    }
    
    /**
     * 获取对应的折扣
     * @param array $data
     * @param int $now_time
     * @return array
     */
    private function _get_user_rate(&$data, $now_time)
    {
        $rate = 1;
        $flag_time = $now_time;
        //查询领取折扣
        $discount_id = (isset($data['discount_id']) && $data['discount_id']) ? $data['discount_id'] : 0;
        if($discount_id){
            $user_discount = Db::name('app_food_user_store_discount')->field('id,flag_time,rate')->where(['id' => $discount_id, 'user_id' => $data['uid'], 'store_id' => $data['store_id'], 'updated_at' => 0])->find();
            if($user_discount){
                $rate = $user_discount['rate'];
                $flag_time = $user_discount['flag_time'];
            }
        }
        return ['rate' => $rate, 'flag_time' => $flag_time];
    }
    /**
     * 根据店铺获取，分店/总店人员id
     * @param int $store_id  店铺id
     * @return int
     */
    private function _get_master_sub_id_by_store_id($store_id)
    {
        //查询店铺信息
        $store = Db::name('app_store')->field('id,uid')->where(['id' => $store_id])->find();
        if(empty($store))return ['p_id' => 0, 's_id' => 0];
        //TODO 查询是否有总店人员id
        $p_id = $store['uid'];
        return ['p_id' => $p_id, 's_id' => $store['uid']];
    }
    
    /**
     * 构造总/分店订单关联表数据
     * @param int $store_id
     * @param int $buyer_id
     * @param string $order_no
     * @param int $now_time
     * @return array
     */
    private function _get_master_sub_store_rel_data($store_id, $buyer_id, $order_no, $now_time)
    {
        $mas_sub = $this->_get_master_sub_id_by_store_id($store_id);
        $arr = [
            'p_id' => $mas_sub['p_id'],
            's_id' => $mas_sub['s_id'],
            'store_id' => $store_id,
            'buyer_id' => $buyer_id,
            'order_no' => $order_no,
            'created_at' => $now_time
        ];
        return $arr;
    }
    
    /**
     * 下单
     * @param array $input
     * @return array
     * [
     *      uid=>1258,
     *      store_id=>12,
     *      people_count=>5,
     *      foods=>[
     *          [id=>1,num=>1],
     *          [id=>3,num=>2]
     *      ],
     * ]
     */
    public function placeOrder($input, $channel = 0)
    {
        $check_res = $this->_arrange_place_order_data($input);
        if(empty($check_res['status']))return $check_res;
        $data = $check_res['data'];
        
        $cache_key = '_place_order_'.$data['uid'];
        if(cache($cache_key))return ['status' => 0, 'message' => '操作过于频繁...'];
        cache($cache_key, true, 5);
        
        $now_time = time();
        //折扣
        $rate_res = $this->_get_user_rate($data, $now_time);
        $rate = $rate_res['rate'];
        $flag_time = $rate_res['flag_time'];
        
        $order_no = date('Ymd', $now_time).mt_rand(1000, 9999).date('His', $now_time).mt_rand(10000, 99999);
        $trade_str = md5($now_time.$data['uid'].mt_rand(10000, 99999));
        //订单商品详情数据
        $detail_res = $this->_get_detail_food_data($data, $order_no, $now_time);
        if(empty($detail_res['status']))return $detail_res;
        //订单数据
        $order_res = $this->_get_food_order_data($data, $rate, $flag_time, $order_no, $trade_str, $detail_res['data'], $now_time);
        if(empty($order_res['status']))return $order_res;
        //总分店关联数据
        $mas_sub_data = $this->_get_master_sub_store_rel_data($data['store_id'], $data['uid'], $order_no, $now_time);
        
        Db::startTrans();
        try {
            Db::name('app_food_order_detail')->insertAll($detail_res['data']['detail_data']);
            $order_res['data']['channel'] = mb_substr($channel, 0, 16, 'UTF-8');
            Db::name('app_food_order')->insert($order_res['data']);
            Db::name('app_food_order_store_rel')->insert($mas_sub_data);
            //Db::name('app_food_user_store_discount')->where(['user_id' => $data['uid'], 'flag_time' => $flag_time])->update(['updated_at' => $now_time]);
            Db::commit();
            return ['status' => 1, 'message' => '下单成功', 'order_no' => $order_no];
        } catch (\Exception $e) {
            Db::rollback();
            return ['status' => 0, 'message' => '下单失败，请稍后再试', 'error' => $e->getMessage()];
        }
    }
    
    /**
     * 预支付订单
     * @param string $order_no  订单号
     * @param int $pay_type     支付方式
     * @return array
     */
    public function payOrder($order_no, $pay_type)
    {
        $now_time = time();
        if(empty($order_no))return ['status' => 0, 'message' => '订单号不正确'];
        if(!in_array($pay_type, [1, 2, 3, 4]))return ['status' => 0, 'message' => '支付方式不正确'];
        if($pay_type == 1)return ['status' => 0, 'message' => '暂不支持纯消费值支付'];
        
        $cache_key = $order_no.'_'.$pay_type;
        if(cache($cache_key))return ['status' => 0, 'message' => '操作过于频繁..'];
        cache($cache_key, true, 5);
        
        $food_order = Db::name('app_food_order')->field('id,user_id,store_id,order_no,rate_time,check_number,status,real_price,point_money,created_at')->where(['order_no' => $order_no])->find();
        if(empty($food_order) || ($food_order['status'] != 1))return ['status' => 0, 'message' => '订单不存在或已支付'];
        if(($food_order['created_at']+1800) < $now_time){
            (new FoodService())->userCancelOrder($food_order['order_no'], $food_order['user_id']);
            return ['status' => 0, 'message' => '订单已过期，请重新下单'];
        }
        
        //修改支付方式
        Db::name('app_food_order')->where(['id' => $food_order['id']])->update(['pay_type' => $pay_type]);
        
        $user = Db::name('user')->field('id,i_money,d_ca,d_gc,ca_money,gc_money,share_point,user')->where(['id' => $food_order['user_id']])->find();
        $point_money = ($pay_type == 1) ? ($food_order['real_price'] + $food_order['point_money']) : $food_order['point_money'];
        if(empty($user))return ['status' => 0, 'message' => '用户信息不存在'];
        if($user['d_ca'] < $point_money)return ['status' => 0, 'message' => '消费值不足'];
        if($pay_type == 4 && $user['i_money'] < $food_order['real_price'])return ['status' => 0, 'message' => '余额不足'];
        
        $suanli = new SuanliService();
        Db::startTrans();
        try {
            if(($pay_type == 1 || $pay_type == 4) && $point_money > 0){
                $suanli->suanli($user, 'd_ca', 2, $point_money, 2, $now_time, '消费扣除');
                $suanli->companySuanli('d_ca', 1, $point_money, 2, $now_time, '消费奖励');
            }
            if($pay_type == 4 && $food_order['real_price'] > 0){
                $suanli->suanli($user, 'i_money', 2, $food_order['real_price'], 3, $now_time, '消费扣除');
                $suanli->companySuanli('i_money', 1, $food_order['real_price'], 3, $now_time, '消费奖励');
            }
            if($pay_type == 1 || $pay_type == 4){
                Db::name('app_food_order')->where(['id' => $food_order['id']])->update(['status' => 2, 'updated_at' => $now_time]);
                Db::name('app_food_user_store_discount')->where(['user_id' => $food_order['user_id'], 'store_id' => $food_order['store_id'], 'flag_time' => $food_order['rate_time']])->update(['updated_at' => $now_time]);
                Db::commit();
                return ['status' => 1, 'message' => '支付成功', 'data' => [], 'check_number' => $food_order['check_number']];
            }
        } catch (\Exception $e) {
            Db::rollback();
            return ['status' => 0, 'message' => '提交订单失败', 'error' => $e->getMessage()];
        }
        
        if($pay_type == 2 || $pay_type == 3){
            $res_json = $this->_to_pay($user, $pay_type, $food_order['order_no'], $now_time);
            $res_arr = json_decode($res_json, true);
            if(isset($res_arr['status'])){
                if($res_arr['status'] == 1){
                    return ['status' => 1, 'message' => 'success', 'data' => $res_arr['data'], 'check_number' => $food_order['check_number']];
                }else{
                    return ['status' => 0, 'message' => $res_arr['message']];
                }
            }else{
                return ['status' => 0, 'message' => '您的网络不给力，请稍后再试...', 'error' => $res_json];
            }
        }
    }
    
    //支付
    private function _to_pay($user, $pay_type, $trade_str, $now_time)
    {
        $to_pay_data = [
            'user_id' => $user['id'],
            'order_type' => 4,
            'timestamp' => $now_time,
            'trade_str' => $trade_str,
            'sign' => md5('id='.$user['id'].'&user='.$user['user'].'&timestamp='.$now_time)
        ];
        $temp_str = ($pay_type == 2) ? 'pay/al-app-pay' : 'pay/wx-app-pay';
        $res = self::openCurl(self::OUT_API_URL.$temp_str, $to_pay_data);
        return $res;
    }
    
    /**
     * 订单对应商品总数
     * @param array $order_no 订单数组
     * @return array
     */
    private function _get_order_detail_count($order_no)
    {
        $all = Db::name('app_food_order_detail')->field('order_no,count(*) as num')->where([['order_no', 'in', $order_no]])->group('order_no')->all();
        $arr = []; 
        if(!empty($all)){
            $arr = array_column($all, 'num', 'order_no');
            $all = null;
        }
        return $arr;
    }
    /**
     * 订单对应评论数量
     * @param array $order_no 订单数组
     * @return array
     */
    private function _get_order_evaluate_count($order_no)
    {
        $all = Db::name('app_food_order_evaluate')->field('order_no,count(*) as num')->where([['order_no', 'in', $order_no]])->group('order_no')->all();
        $arr = [];
        if(!empty($all)){
            $arr = array_column($all, 'num', 'order_no');
            $all = null;
        }
        return $arr;
    }
    /**
     * 获取前端展示列表订单状态及描述
     * @param array $record     订单记录
     * @param string $is_eval   是否已评价
     * @return array
     */
    private function _get_order_status_flag_for_frontend(&$record, $is_eval = true)
    {
        switch($record['status']){
            case 1:
                $flag = 1;
                $flag_str = '待付款';
                break;
            case 2:
                if($record['check_time']){
                    $flag = $is_eval ? 3 : 7;
                    $flag_str = $is_eval ? '已完成' : '待评价';
                }else{
                    $flag = 2;
                    $flag_str = '待使用';
                }
                break;
            case 3:
                $flag = 4;
                $flag_str = '退款中';
                break;
            case 4:
                $flag = 5;
                $flag_str = '退款驳回';
                break;
            case 5:
                $flag = 6;
                $flag_str = '退款成功';
                break;
            default:
                $flag = 0;
                $flag_str = '';
                break;
        }
        return ['flag' => $flag, 'flag_str' => $flag_str];
    }
    /**
     * 获取前端展示订单列表数据
     * @param int $user_id
     * @param int $type
     * @param int $page
     * @param int $page_size
     * @return array
     */
    private function _get_order_list_for_frontend($user_id, $type, $page, $page_size)
    {
        $expire_time = time()-1800;
        if($type == 0)$where = [['afo.user_id', '=', $user_id], ['afo.status', 'in', [1, 2, 3, 4, 5]]];
        if($type == 1)$where = [['afo.user_id', '=', $user_id], ['afo.status', '=', 1], ['afo.created_at', '>=', $expire_time]];
        if($type == 2)$where = [['afo.user_id', '=', $user_id], ['afo.status', '=', 2], ['afo.check_time', '=', 0]];
        if($type == 3)$where = [['afo.user_id', '=', $user_id], ['afo.status', '=', 2], ['afo.check_time', '>', 0]];
        if($type == 4)$where = [['afo.user_id', '=', $user_id], ['afo.status', 'in', [3, 4, 5]]];
        
        $handle = Db::name('app_food_order')->alias('afo')
        ->leftJoin('app_store asto', 'afo.store_id=asto.id')
        ->field('afo.order_no,afo.store_id,afo.status,afo.check_time,afo.check_number,afo.real_price,afo.created_at,asto.title,asto.main_img')
        ->where($where);
        if($type == 0)$handle->whereRaw(' case when afo.status=1 then afo.created_at>'.$expire_time.' else afo.created_at>0 end ');
        
        $count = $handle->count();
        $page_count = ceil($count/$page_size);
        $page_start = ($page_count >= $page) ? max(0, $page-1)*$page_size : 0;
        $list = $handle->order(['afo.id' => 'desc'])->limit($page_start, $page_size)->all();
        $arr = [];
        $i = 0;
        if($list){
            $order_no = array_column($list, 'order_no');
            $detail = $this->_get_order_detail_count($order_no);
            $eval = $this->_get_order_evaluate_count($order_no);
            foreach($list as $key => $val){
                if(($type == 3) && isset($eval[$val['order_no']]))continue;
                $temp = $this->_get_order_status_flag_for_frontend($val, isset($eval[$val['order_no']]));
                $arr[$i] = [
                    'order_no' => $val['order_no'],
                    'title' => $val['title'],
                    'main_img' => $val['main_img'],
                    'real_price' => $val['real_price'],
                    'num' => isset($detail[$val['order_no']]) ? $detail[$val['order_no']] : 1,
                    'created_at' => date('Y-m-d H:i', $val['created_at']),
                    'flag' => $temp['flag'],
                    'flag_str' => $temp['flag_str'],
                    'store_id' => $val['store_id'],
                    'check_number' => $val['check_number'],
                ];
                $i++;
            }
        }
        
        return ['count' => $count, 'list' => $arr];
    }
    /**
     * 获取前端展示订单列表数据
     * @param int $user_id
     * @param int $type
     * @param int $page
     * @param int $page_size
     * @return array
     */
    public function getOrderForFrontend($user_id, $type = 0, $page = 1, $page_size = 10)
    {
        if(empty($user_id))return ['status' => 0, 'message' => '提交数据为空'];
        if(!in_array($type, [0, 1, 2, 3, 4]))return ['status' => 0, 'message' => '订单状态不正确'];
        
        $data = $this->_get_order_list_for_frontend($user_id, $type, $page, $page_size);
        return ['status' => 1, 'message' => 'success', 'data' => $data];
    }
    
    /**
     * 用户申请退款
     * @param int $user_id              当前用户
     * @param string $order_no          订单号
     * @param string $refund_reason     退款原因
     * @param string $refund_remark     退款说明
     * @return array
     */
    public function foodStoreOrderRefund($user_id, $order_no, $refund_reason, $refund_remark)
    {
        if(empty($user_id) || empty($order_no))return ['status' => 0, 'message' => '提交数据为空'];
        //查询订单
        $order = Db::name('app_food_order')->field('id,user_id,order_no,store_id,real_price,point_money,pay_type')
        ->where(['user_id' => $user_id, 'order_no' => $order_no, 'status' => 2, 'check_time' => 0])->find();
        if(empty($order))return ['status' => 0, 'message' => '未查询到订单信息或该订单无法申请退款'];
        //查询是否有申请记录
        $refund = Db::name('app_food_order_refund')->field('id,refund_status')->where(['user_id' => $user_id, 'order_no' => $order_no])->find();
        if(!empty($refund) && ($refund['refund_status'] == 2))return ['status' => 0, 'message' => '该订单已退款成功，请勿重复申请'];
        
        //计算退款金额
        $real_money = $order['real_price'];
        $point_money = $order['point_money'];
        
        $now_time = time();
        Db::startTrans();
        try {
            //修改订单状态为申请退款
            Db::name('app_food_order')->where(['id' => $order['id']])->update(['status' => 3]);
            //申请记录及日志
            if(!empty($refund)){
                Db::name('app_food_order_refund')->where(['id' => $refund['id']])->update(['check_status' => 1, 'refund_status' => 1, 'refund_reason' => $refund_reason, 'refund_remark' => $refund_remark]);
                Db::name('app_food_order_refund_log')->insertAll([
                    ['order_no' => $order['order_no'], 'desc_title' => '重新提交退款申请', 'desc_detail' => '退款原因:'.$refund_reason, 'created_at' => $now_time],
                    ['order_no' => $order['order_no'], 'desc_title' => '等待审核', 'desc_detail' => '', 'created_at' => $now_time]
                ]);
            }else{
                Db::name('app_food_order_refund')->insert([
                    'user_id' => $order['user_id'],
                    'order_no' => $order['order_no'],
                    'store_id' => $order['store_id'],
                    'refund_money' => $real_money,
                    'refund_point_money' => $point_money,
                    'refund_reason' => $refund_reason,
                    'refund_remark' => $refund_remark,
                    'pay_type' => $order['pay_type'],
                    'created_at' => $now_time
                ]);
                Db::name('app_food_order_refund_log')->insertAll([
                    ['order_no' => $order['order_no'], 'desc_title' => '提交退款申请', 'desc_detail' => '退款原因:'.$refund_reason, 'created_at' => $now_time],
                    ['order_no' => $order['order_no'], 'desc_title' => '等待审核', 'desc_detail' => '', 'created_at' => $now_time]
                ]);
            }
            Db::commit();
            return ['status' => 1, 'message' => '申请成功，请耐心等待审核'];
        } catch (\Exception $e) {
            Db::rollback();
            return ['status' => 0, 'message' => '申请失败，请刷新重试'];
        }
    }
    
    /**
     * 用户查看退款进度页面数据
     * @param int $user_id      当前用户id
     * @param string $order_no  订单号
     * @return array
     */
    public function getFoodStoreOrderRefundLog($user_id, $order_no)
    {
        //申请退款记录
        $refund = Db::name('app_food_order_refund')->field('refund_money,refund_reason,refund_status')->where(['user_id' => $user_id, 'order_no' => $order_no])->find();
        if(empty($refund))return ['status' => 0, 'message' => '尚未申请退款'];
        //订单信息
        $order = Db::name('app_food_order')->alias('afo')
        ->leftJoin('app_store asto', 'afo.store_id=asto.id')
        ->field(('afo.real_price,afo.pay_type,afo.order_no,afo.status,asto.title,asto.main_img'))
        ->where(['afo.user_id' => $user_id, 'afo.order_no' => $order_no])->find();
        if(empty($order))return ['status' => 0, 'message' => '未查询到订单信息'];
        //订单商品总数
        $num = Db::name('app_food_order_detail')->where(['order_no' => $order_no])->count();
        //记录
        $logs = Db::name('app_food_order_refund_log')->field('desc_title,desc_detail')->fieldRaw('from_unixtime(created_at) as created_at')->where(['order_no' => $order])->order(['id' => 'desc'])->all();
        
        $data = [
            'refund_money' => $refund['refund_money'],
            'refund_account' => ($order['pay_type'] == 2) ? '支付宝' : (($order['pay_type'] == 3) ? '微信' : '平台账户'),
            'refund_status_str' => ($refund['refund_status'] == 1) ? '申请中' : (($refund['refund_status'] == 2) ? '退款成功' : '退款失败'),
            'title' => $order['title'],
            'main_img' => $order['main_img'],
            'real_price' => $order['real_price'],
            'num' => $num,
            'refund_reason' => $refund['refund_reason'],
            'order_no' => $order['order_no'],
            'logs' => $logs
        ];
        return ['status' => 1, 'message' => 'success', 'data' => $data];
    }
    
    /**
     * C端用户查看订单详情
     * @param int $user_id
     * @param string $order_no
     * @param number $lng
     * @param number $lat
     * @return array
     */
    public function getOrderDetailForFrontend($user_id, $order_no, $lng = 0, $lat = 0)
    {
        $lng = is_numeric($lng) ? $lng : 0;
        $lat = is_numeric($lat) ? $lat : 0;
        $arr = [];
        if(empty($user_id) || empty($order_no))return $arr;
        
        $cache_key = '_order_detail_for_frontend_'.$user_id.'_'.$order_no;
        if(($cache_value = cache($cache_key)))return $cache_value;
        //查订单
        $order = Db::name('app_food_order')->field('store_id,order_no,user_mobile,created_at,order_price,real_price,rate,rate_time,check_number')
        ->where(['user_id' => $user_id, 'order_no' => $order_no])->find();
        if(empty($order))return $arr;
        //订单详情
        $detail = Db::name('app_food_order_detail')->field('name,thumb_img,num,sell_price')
        ->where(['user_id' => $user_id, 'store_id' => $order['store_id'], 'order_no' => $order_no])->all();
        //店铺数据
        $store = (new StoreService())->getStoreDetailByStoreId($order['store_id'], 'main_img,title,dress,phone', $lng, $lat);
        
        $order['created_at'] = date('Y-m-d H:i', $order['created_at']);
        $order['rate_time'] = date('Y-m-d H:i', $order['rate_time']);
        $order['user_mobile'] = substr($order['user_mobile'], 0, 3).'****'.substr($order['user_mobile'], -4);
        $order['food_count'] = count($detail);
        $arr = ['order' => $order, 'detail' => $detail, 'store' => $store];
        cache($cache_key, $arr, 60*60*7);
        return $arr;
    }

    /**
     * 商家收入明细
     * @param $uid
     */
    public function getStoreIncome($uid, $page = 1, $page_size = 10)
    {
        $store = (new FoodService())->_get_store_by_user_id($uid);
        if (empty($store)) exception('店铺信息不正确');
        $handle = Db::name('app_food_income')->where('uid', $uid);
        $count = $handle->count();
        $page_count = ceil($count / $page_size);
        $page_start = ($page_count >= $page) ? max(0, $page - 1) * $page_size : 0;
        $income = $handle->order(['id' => 'desc'])->limit($page_start, $page_size)->all();
        foreach ($income as &$_income) {
            if ($_income['type'] == 1) {
                $_income['title'] = '收入';
            } elseif ($_income['type'] == 2) {
                $_income['title'] = '支出';
            } else {
                $_income['title'] = '未知';
            }
            $_income['create_at'] = date('Y-m-d H:i:s', $_income['create_at']);
        }
        $data = ['count' => $count, 'list' => $income];
        return $data;
    }

    /**
     * 商家收入记录
     * @param string $uid
     * @param float $money
     * @param int $type
     */
    public function insertIncome($uid = '', $order_no = '', $money = 0.00, $type = 1)
    {
        return Db::name('app_food_income')->insert(['uid' => $uid, 'order_no'=> $order_no, 'money' => $money, 'type' => $type, 'create_at' => time()]);
    }
}
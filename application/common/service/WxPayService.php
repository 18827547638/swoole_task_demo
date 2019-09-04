<?php
namespace app\common\service;

use think\Db;

class WxPayService extends CommonService
{
    /**
     * 充值订单
     * @param int $user_id          用户id
     * @param string $openid        用户openid
     * @param number $money         充值金额
     * @param array $config = ['appid' => '', 'mch_id' => '', 'mch_secret' => ''];//小程序配置
     * @param string $notify_url    支付成功通知地址
     * @return array
     */
    public function createRechargeOrder($user_id, $openid, $money, $config, $notify_url)
    {
        self::limitOperateFrequency('_create_recharge_order_user_id_'.$user_id);
        $money = round($money, 2);
        if($money <= 0)return ['status' => 0, 'message' => '金额不正确'];
        $order_id = md5(time().self::createRandomNumber(32));
        $arr = [
            'user_id' => $user_id,
            'order_id' => $order_id,
            'order_type' => 3,
            'pay_type' => 2,
            'total_money' => $money,
            'real_money' => $money,
            'created_at' => time(),
        ];
        
        Db::startTrans();
        try {
            //插入订单数据
            $record_id = Db::name('app_pay_order')->insertGetId($arr);
            //微信预订单
            $order_res = $this->_wx_place_an_order($order_id, round($arr['real_money']*100), $openid, $config, $notify_url, '积分充值');
            Db::name('app_pay_order')->where(['id' => $record_id])->update(['order_data' => json_encode($order_res)]);
            if(empty($order_res['status'])){
                Db::rollback();
                return $order_res;
            }
            Db::commit();
            return ['status' => 1, 'message' => 'success', 'data' => $order_res['web_data']];
        } catch (\Exception $e) {
            Db::rollback();
            return ['status' => 0, 'message' => '订单生成失败，请稍后再试'];
        }
    }
    
    /**
     * 美食订单支付数据
     * @param string $order_no  订单号
     * @param int $pay_type     支付方式，3微信，4余额
     * @param int $user_id      付款人id
     * @param string $openid    付款人openid
     * @return array
     */
    public function createMeishiPayData($order_no, $pay_type, $user_id, $openid)
    {
        $config = ['appid' => config('xcx.meishi_appid'), 'secret' => config('xcx.meishi_secret'), 'mch_id' => config('xcx.meishi_mch_id'), 'mch_secret' => config('xcx.meishi_mch_secret')];
        $notify_url = config('xcx.meishi_notify_url');
        $now_time = time();
        if(empty($order_no) || empty($pay_type))return ['status' => 0, 'message' => '提交数据为空'];
        self::limitOperateFrequency('_create_meishi_pay_data_order_no_'.$order_no);
        if(!in_array($pay_type, [3, 4]))return ['status' => 0, 'message' => '支付方式不正确'];
        $food_order = Db::name('app_food_order')->field('id,user_id,order_no,real_price,point_money,status,created_at')->where(['order_no' => $order_no])->find();
        if(empty($food_order) || ($food_order['status'] != 1))return ['status' => 0, 'message' => '未查询到订单信息或订单状态异常'];
        if(($food_order['created_at']+1800) < $now_time){
            (new FoodService())->userCancelOrder($food_order['order_no'], $food_order['user_id']);
            return ['status' => 0, 'message' => '订单已过期，请重新下单'];
        }
        
        $user = Db::name('user')->field('id,i_money,d_ca')->where(['id' => $user_id])->find();
        if(empty($user))return ['status' => 0, 'message' => '用户信息异常'];
        if($food_order['point_money'] > $user['d_ca'])return ['status' => 0, 'message' => '消费值不足'];
        if(($pay_type == 4) && ($user['i_money'] <= 0 || $food_order['real_price'] > $user['i_money']))return ['status' => 0, 'message' => '余额不足'];
        
        if($pay_type == 4){//余额支付
            return $this->_pay_type_by_balance($food_order, $user_id, $now_time);
        }else if($pay_type == 3){
            $flag = 1;
            $order_res = $this->_wx_place_an_order($order_no, round($food_order['real_price']*100), $openid, $config, $notify_url, '恋美食小程序消费');
            if(empty($order_res['status']))return $order_res;
            $count = Db::name('app_pay_order')->where(['order_id' => $order_no])->count();
            if(empty($count)){
                $arr = [
                    'user_id' => $user_id,
                    'order_id' => $order_no,
                    'order_type' => 4,
                    'pay_type' => 2,
                    'total_money' => ($food_order['real_price'] + $food_order['point_money']),
                    'real_money' => $food_order['real_price'],
                    'created_at' => $now_time,
                    'order_data' => json_encode($order_res),
                ];
                $flag = Db::name('app_pay_order')->insert($arr);
            }
            if($flag && is_numeric($flag)){
                return ['status' => 1, 'message' => 'success', 'data' => $order_res['web_data']];
            }else{
                return ['status' => 0, 'message' => '生成订单失败'];
            }
        }
    }
    
    /**
     * 余额支付
     * @param array $food_order     部分订单数据
     * @param int $user_id          付款人id
     * @param int $now_time         当前时间
     * @return array
     */
    private function _pay_type_by_balance(&$food_order, $user_id, $now_time)
    {
        Db::startTrans();
        try {
            $suanli = new SuanliService();
            //扣消费值
            if($food_order['point_money'] > 0){
                $suanli->userSuanli($user_id, 'd_ca', 2, $food_order['point_money'], 2, $now_time, '美食消费扣除');
                $suanli->companySuanli('d_ca', 1, $food_order['point_money'], 2, $now_time, '美食消费奖励');
            }
            if($food_order['real_price'] > 0){
                $suanli->userSuanli($user_id, 'i_money', 2, $food_order['real_price'], 3, $now_time, '美食消费扣除');
                $suanli->companySuanli('i_money', 1, $food_order['real_price'], 3, $now_time, '美食消费奖励');
                Db::name('app_food_order')->where(['id' => $food_order['id']])->update(['status' => 2, 'updated_at' => $now_time, 'pay_type' => 4]);
            }
            Db::commit();
            return ['status' => 1, 'message' => '支付成功'];
        } catch (\Exception $e) {
            Db::rollback();
            return ['status' => 0, 'message' => '支付失败', 'error' => $e->getMessage().'--'.$e->getLine().'--'.$e->getFile()];
        }
    }
    
    /**
     * 生成微信签名
     * @param array $data           待签名数据
     * @param string $app_secret    密钥
     * @return string
     */
    public static function _create_wx_sign($data, $app_secret)
    {
        ksort($data);
        $temp_param = '';
        foreach ($data as $key => $val){
            $temp_param .= $key.'='.$val.'&';
        }
        $temp_param .= 'key='.$app_secret;
        return strtoupper(md5($temp_param));
    }
    /**
     * 微信预支付订单
     * @param string $order_id      订单
     * @param number $total_fee     订单金额，单位分
     * @param string $openid        用户openid
     * @param array $config         部分配置文件
     * @param string $notify_url    支付成功通知地址
     * @param string $body          商品描述
     * @return array
     */
    private function _wx_place_an_order($order_id, $total_fee, $openid, $config, $notify_url, $body)
    {
        $data['appid'] = $config['appid'];//小程序ID
        $data['mch_id'] = $config['mch_id'];//商户号
        $data['nonce_str'] = strtoupper(time().self::createRandomNumber());//随机字符串
        $data['body'] = $body;//商品描述
        $data['out_trade_no'] = $order_id;//商户订单号
        $data['total_fee'] = $total_fee;//订单总金额，单位为分
        $data['spbill_create_ip'] = request()->ip();
        $data['notify_url'] = $notify_url;
        $data['trade_type'] = 'JSAPI';//交易类型,小程序取值如下：JSAPI
        $data['openid'] = $openid;//trade_type=JSAPI，此参数必传
        $data['sign'] = self::_create_wx_sign($data, $config['secret']);
        
        $xml = self::openCurl(config('xcx.wx_place_an_order_url'), self::arrayToXml($data));
        $result = self::xmlToArray($xml);
        if(empty($result))return ['status' => 0, 'message' => '您的网络不给力，请稍后再试'];
        if(isset($result['return_code']) && isset($result['result_code']) && ($result['return_code'] == 'SUCCESS') && ($result['result_code'] == 'SUCCESS')){
            $web_data = $this->_wx_pay_data_to_web($result, $config['mch_secret']);
            return ['status' => 1, 'message' => '微信预支付订单生成成功', 'data' => $result, 'web_data' => $web_data];
        }
        return ['status' => 0, 'message' => $result['return_msg'], 'data' => $result];
    }
    /**
     * 返回前端发起支付所需数据
     * @param array $data           预支付成功之后返回的数据
     * @param string $mch_secret    密钥
     * @return number
     */
    private function _wx_pay_data_to_web(&$data, $mch_secret)
    {
        $time = time();
        $temp['timeStamp'] = $time;
        $temp['nonceStr'] = $data['nonce_str'];
        $temp['package'] = 'prepay_id='.$data['prepay_id'];
        $temp['signType'] = 'MD5';
        $str = 'appId='.$data['appid'].'&nonceStr='.$data['nonce_str'].'&package=prepay_id='.$data['prepay_id'].'&signType=MD5&timeStamp='.$time.'&key='.$mch_secret;
        $temp['paySign'] = strtoupper(md5($str));
        return $temp;
    }
    
    /**
     * 微信支付成功通知
     * @param xml $xml
     * @return array
     */
    public function wxXcxPaySuccessNotify($xml)
    {
        if(empty($xml))return ['status' => 0, 'message' => '未收到通知数据'];
        $data = self::xmlToArray($xml);
        if(isset($data['return_code']) && $data['return_code'] == 'SUCCESS'){
            //查询微信订单
            $order_query_res = $this->_wx_order_query($data['transaction_id'], $data['nonce_str'], $data['sign']);
            if(empty($order_query_res['status']))return $order_query_res;
            //查询系统订单
            $pay_order = Db::name('app_pay_order')->field('id,user_id,order_id,order_type,status,real_money')->where(['order_id' => $data['out_trade_no']])->find();
            if(empty($pay_order))return ['status' => 0, 'message' => '未查询到订单信息'];
            if($pay_order['status'] == 2)return ['status' => 1, 'message' => '订单已支付成功'];
            if(($pay_order['real_money']*100) != $data['total_fee'])return ['status' => 0, 'message' => '订单金额不正确'];
            if($pay_order['order_type'] == 1){//会员直接付款升级
                return ['status' => 0, 'message' => '已屏蔽直接购买升级'];
            }else if($pay_order['order_type'] == 2){//商城购物
                return $this->_pay_success_notify_gouwu($pay_order['user_id'], $pay_order['id'], $pay_order['order_id'], strtotime($data['time_end']), json_encode($data));
            }else if($pay_order['order_type'] == 3){//积分充值
                return $this->_pay_success_notify_jifen($pay_order['user_id'], $pay_order['real_money'], $pay_order['id'], strtotime($data['time_end']), json_encode($data));
            }else if($pay_order['order_type'] == 4){//美食消费
                return $this->_pay_success_notify_meishi($pay_order['user_id'], $pay_order['id'], $pay_order['order_id'], strtotime($data['time_end']), json_encode($data));
            }
            return ['status' => 0, 'message' => '订单类型异常'];
        }
        return ['status' => 0, 'message' => '通知信息异常', 'data' => $data];
    }
    private function _wx_order_query($transaction_id, $nonce_str, $sign, $config)
    {
        $data['appid'] = $config['appid'];
        $data['mch_id'] = $config['mch_id'];
        $data['transaction_id'] = $transaction_id;
        $data['nonce_str'] = $nonce_str;
        $str = 'appid='.$config['appid'].'&mch_id='.$config['mch_id'].'&nonce_str='.$nonce_str.'&sign_type=MD5&transaction_id='.$transaction_id.'&key='.$config['mch_secret'];
        $data['sign'] = strtoupper(md5($str));
        $data['sign_type'] = 'MD5';
        
        $xml = self::openCurl(config('xcx.wx_order_query_url'), self::arrayToXml($data));
        $data = self::xmlToArray($xml);
        if(empty($data))return ['status' => 0, 'message' => '获取数据失败'];
        if(isset($data['return_code']) && isset($data['result_code']) && isset($data['trade_state'])
            && $data['return_code'] == 'SUCCESS' && $data['result_code'] == 'SUCCESS' && $data['trade_state'] == 'SUCCESS'){
                return ['status' => 1, 'message' => '查询成功', 'data' => $data];
        }
        return ['status' => 0, 'message' => '查询失败', 'data' => $data];
    }
    
    /**
     * 支付成功-充值业务逻辑
     * @param int $user_id          用户id
     * @param number $real_money    实际支付金额，单位元
     * @param int $pay_order_id     app_pay_order表记录id
     * @param string $notify_data   通知数据
     * @return array
     */
    private function _pay_success_notify_jifen($user_id, $real_money, $pay_order_id, $pay_time, $notify_data)
    {
        Db::startTrans();
        try {
            //修改用户余额
            (new SuanliService())->userSuanli($user_id, 'i_money', 1, $real_money, 0, $pay_time, '积分充值');
            //修改状态
            Db::name('app_pay_order')->where(['id' => $pay_order_id])->update(['status' => 2, 'updated_at' => $pay_time, 'notify_data' => $notify_data]);
            Db::commit();
            return ['status' => 1, 'message' => 'success'];
        } catch (\Exception $e) {
            Db::rollback();
            return ['status' => 0, 'message' => 'Error:'.$e->getMessage().'--'.$e->getFile().'--'.$e->getLine()];
        }
    }
    
    /**
     * 支付成功-美食消费业务逻辑
     * @param int $user_id          用户id
     * @param int $pay_order_id     app_pay_order表记录id
     * @param string $order_no      订单号
     * @param number $pay_time      支付成功时间
     * @param string $notify_data   通知数据
     * @return array
     */
    private function _pay_success_notify_meishi($user_id, $pay_order_id, $order_no, $pay_time, $notify_data)
    {
        //查美食订单
        $food_order = Db::name('app_food_order')->field('id,point_money,store_id,rate_time')->where(['order_no' => $order_no, 'user_id' => $user_id, 'status' => 1])->find();
        if(empty($food_order))return ['status' => 0, 'message' => '美食订单不存在或已支付'];
        
        Db::startTrans();
        try {
            //扣除现金值
            if($food_order['point_money'] > 0){
                $suanli = new SuanliService();
                $suanli->userSuanli($user_id, 'd_ca', 2, $food_order['point_money'], 2, $pay_time, '美食消费扣除');
                $suanli->companySuanli('d_ca', 1, $food_order['point_money'], 2, $pay_time, '美食消费奖励');
            }
            //修改订单状态
            Db::name('app_pay_order')->where(['id' => $pay_order_id])->update(['status' => 2, 'updated_at' => $pay_time, 'notify_data' => $notify_data]);
            Db::name('app_food_order')->where(['id' => $food_order['id']])->update(['status' => 2, 'updated_at' => $pay_time]);
            Db::name('app_food_user_store_discount')->where(['user_id' => $user_id, 'store_id' => $food_order['store_id'], 'flag_time' => $food_order['rate_time']])->update(['updated_at' => $pay_time]);
            Db::commit();
            return ['status' => 1, 'message' => 'success'];
        } catch (\Exception $e) {
            Db::rollback();
            return ['status' => 0, 'message' => 'Error:'.$e->getMessage().'--'.$e->getFile().'--'.$e->getLine()];
        }
    }
    
    /**
     * 支付成功-商城购物业务逻辑
     * @param int $user_id          用户id
     * @param int $pay_order_id     app_pay_order表记录id
     * @param string $order_no      交易号
     * @param number $pay_time      支付完成时间
     * @param string $notify_data   通知时间
     * @return array
     */
    private function _pay_success_notify_gouwu($user_id, $pay_order_id, $order_no, $pay_time, $notify_data)
    {
        //商城订单
        $good_order = Db::name('app_good_order')->fieldRaw('sum(pay) as pay,sum(point_money) as point_money,max(can_upgrade_vip) as can_upgrade_vip')
        ->where(['trade_str' => $order_no, 'uid' => $user_id, 'status' => 1])->find();
        if(empty($good_order))return ['status' => 0, 'message' => '商城订单不存在或已支付'];
        
        Db::startTrans();
        try {
            //扣除现金值
            if($good_order['point_money'] > 0){
                $suanli = new SuanliService();
                $suanli->userSuanli($user_id, 'd_ca', 2, $good_order['point_money'], 2, $pay_time, '购买商品扣除');
                $suanli->companySuanli('d_ca', 1, $good_order['point_money'], 2, $pay_time, '购买商品奖励');
            }
            //升级会员
            if($good_order['can_upgrade_vip'] == 1)$this->_user_upgrade_vip($user_id);
            //修改订单状态
            Db::name('app_pay_order')->where(['id' => $pay_order_id])->update(['status' => 2, 'updated_at' => $pay_time, 'notify_data' => $notify_data]);
            Db::name('app_good_order')->where(['trade_str' => $order_no, 'uid' => $user_id])->update(['status' => 2, 'update_time' => $pay_time]);
            Db::commit();
            return ['status' => 1, 'message' => 'success'];
        } catch (\Exception $e) {
            Db::rollback();
            return ['status' => 0, 'message' => 'Error:'.$e->getMessage().'--'.$e->getFile().'--'.$e->getLine()];
        }
    }
    
    /**
     * 用户升级会员
     * @param int $user_id  用户id
     * @param number $upper_money   用户升级上级返点
     * @param number $upper_upper_money     用户上级上级的上级返点
     * @return boolean
     */
    private function _user_upgrade_vip($user_id, $upper_money = 200, $upper_upper_money = 100)
    {
        $now_time = time();
        $expire_time = strtotime('+1 year', $now_time);
        //
        $member = Db::name('app_user_member_rel')->field('id,expire_time')->where(['app_user_id' => $user_id]);
        $flag = 0;
        if(empty($member)){
            $flag = Db::name('app_user_member_rel')->insert(['app_user_id' => $user_id, 'created_at' => $now_time, 'expire_time' => $expire_time]);
        }else{
            if($member['expire_time'] >= $now_time)return true;
            $flag = Db::name('app_user_member_rel')->where(['id' => $member['id']])->update(['expire_time' => $expire_time]);
        }
        //奖励
        if(!empty($flag)){
            $suanli = new SuanliService();
            $upper_id = Db::name('user')->where(['id' => $user_id])->value('sid');
            if(empty($upper_id))return true;
            $upper = $this->_get_user_member_data($upper_id);
            if(!empty($upper) && !empty($upper['vip_id'])){
                $suanli->userSuanli($upper['id'], 'i_money', 1, $upper_money, 3, $now_time, '推荐会员奖励');
            }
            if(!empty($upper) && !empty($upper['sid'])){
                $upper_upper = $this->_get_user_member_data($upper['sid']);
                if(!empty($upper_upper) && !empty($upper_upper['vip_id'])){
                    $suanli->userSuanli($upper_upper['id'], 'i_money', 1, $upper_upper_money, 3, $now_time, '推荐会员奖励');
                }
            }
        }
        return true;
    }
    /**
     * 获取用户会员信息
     * @param int $user_id  用户id
     * @return array
     */
    private function _get_user_member_data($user_id)
    {
        $user = Db::name('user')->alias('u')
        ->leftJoin('app_user_member_rel aumr', 'u.id=aumr.app_user_id')
        ->field('u.id,u.sid,aumr.id as vip_id,aumr.is_service_provider,aumr.expire_time')
        ->where(['u.id' => $user_id])->find();
        if(empty($user))return [];
        if($user['expire_time'] < time())$user['vip_id'] = 0;
        return $user;
    }
}
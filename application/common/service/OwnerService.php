<?php
namespace app\common\service;

use think\Db;

class OwnerService extends CommonService
{
    /**
     * 获取用户的粉丝数据
     * @param int $user_id
     * @param int $page
     * @param int $page_size
     * @return array
     */
    public function getUserFans($user_id, $page = 1, $page_size = 10)
    {
        //粉丝数量
        $fans_count = Db::name('user')->where(['sid' => $user_id])->count();
        //粉丝里面的会员数量
        $vip_fans_count = Db::name('app_user_member_rel')->alias('aumr')->leftJoin('user u', 'aumr.app_user_id=u.id')->where(['u.sid' => $user_id])->count();
        $page_count = ceil($fans_count/$page_size);
        $page_start = ($page_count >= $page) ? max(0, ($page-1))*$page_size : 0;
        
        $list = [];
        if($fans_count > 0){
            $sql = 'select a.number,a.user,a.create_time,(select count(*) from user u where u.sid=a.id) as fans_count,aumr.id as vip_id,aumr.is_service_provider from user a
                    left join app_user_member_rel aumr on a.id=aumr.app_user_id
                    where a.sid='.$user_id.' order by a.id desc limit '.$page_start.','.$page_size;
            $list = Db::query($sql);
            foreach($list as $key => $val){
                $list[$key]['create_time'] = date('Y-m-d', $val['create_time']);
                $list[$key]['is_member'] = empty($val['vip_id']) ? 0 : (($val['vip_id'] && $val['is_service_provider']) ? 2 : 1);
            }
        }
        $arr = [
            'count' => $fans_count,
            'list' => $list,
            'page_count' => $page_count,
            'fans_count' => $fans_count,
            'vip_fans_count' => $vip_fans_count
        ];
        return $arr;
    }
    
    /**
     * 获取用户基本信息，包含推荐人部分信息
     * @param int $user_id
     * @return array
     */
    public function getUserBaseInfo($user_id)
    {
        $now_time = time();
        //用户数据
        $user = Db::name('user')->alias('u')
        ->leftJoin('app_user_member_rel aumr', 'u.id=aumr.app_user_id')
        ->field('u.id,u.user,u.number,u.avator,u.nick,u.vip,u.d_ca,u.share_point,u.i_money,u.sid,u.safe_password,u.weixin_uid,aumr.id as vip_id,aumr.is_service_provider,aumr.expire_time')
        ->where(['u.id' => $user_id, 'status' => 1])->find();
        if(empty($user))return ['status' => 0, 'message' => '用户不存在或被禁用'];
        $user['safe_password'] = empty($user['safe_password']) ? 0 : 1;
        $user['vip_id'] = ($user['vip_id'] && ($user['expire_time'] >= $now_time)) ? ($user['is_service_provider'] ? 2 : 1) : 0;
        unset($user['is_service_provider']);
        
        //用户推荐人
        $upper = [];
        if($user['sid']){
            $upper = Db::name('user')->alias('u')
            ->leftJoin('app_user_member_rel aumr', 'u.id=aumr.app_user_id')
            ->field('u.id,u.user,u.number,u.avator,u.nick,u.vip,u.sid,aumr.id as vip_id,aumr.is_service_provider,aumr.expire_time')
            ->where(['u.id' => $user['sid']])->find();
            if($upper){
                $upper['vip_id'] = ($upper['vip_id'] && ($upper['expire_time'] >= $now_time)) ? ($upper['is_service_provider'] ? 2 : 1) : 0;
                unset($upper['expire_time'], $upper['is_service_provider']);
            }
        }
        $user['upper'] = $upper;
        return ['status' => 1, 'message' => 'success', 'user' => $user];
    }
    
    /**
     * 绑定推荐人
     * @param array $user       当前用户数据
     * @param number $number    推荐人number
     * @return array
     */
    public function bindRecommenderId($user, $number)
    {
        if(empty($user) || empty($number))return ['status' => 0, 'message' => '数据为空'];
        if($user['number'] == $number)return ['status' => 0, 'message' => '不允许绑定自己'];
        $current_user = Db::name('user')->field('id,sid')->where(['id' => $user['id']])->find();
        if(empty($current_user) || !empty($current_user['sid']))return ['status' => 0, 'message' => '已绑定推荐人'];
        $recommender_id = Db::name('user')->where(['number' => $number, 'status' => 1])->value('id');
        if(empty($recommender_id))return ['status' => 0, 'message' => '推荐人不存在或被禁用'];
        
        Db::name('user')->where(['id' => $current_user['id']])->update(['sid' => $recommender_id]);
        return ['status' => 1, 'message' => '操作成功'];
    }
    
    /**
     * 消费值与分享值取小转换成余额
     * @param int $user_id      用户id
     * @param number $count     转换数量，0为全部转换
     * @return array
     */
    public function changeMoney($user_id, $count = 0)
    {
        self::limitOperateFrequency('_change_money_user_id_'.$user_id);
        $user = Db::name('user')->field('id,i_money,d_ca,share_point')->where(['id' => $user_id, 'status' => 1])->find();
        if(empty($user))return ['status' => 0, 'message' => '数据异常，请刷新重试'];
        $change_money = min($user['d_ca'], $user['share_point']);
        if($change_money <= 0)return ['status' => 0, 'message' => '可转换值不足'];
        if($count < 0 || ($count > $change_money))return ['status' => 0, 'message' => '可转换值不足...'];
        if($count > 0)$change_money = $count;
        
        $str = '余额转换';
        $now_time = time();
        Db::startTrans();
        try {
            Db::name('user')->inc('i_money', $change_money)->dec(['d_ca', 'share_point'], $change_money)->where(['id' => $user['id']])->update();
            $logs = [
                ['uid'=>$user['id'],'coin_id'=>1,'type'=>2,'num'=>$change_money,'old_money'=>$user['d_ca'],'now_money'=>($user['d_ca']-$change_money),'remark'=>$str,'create_time'=>$now_time,'frogen'=>2],
                ['uid'=>$user['id'],'coin_id'=>3,'type'=>2,'num'=>$change_money,'old_money'=>$user['share_point'],'now_money'=>($user['share_point']-$change_money),'remark'=>$str,'create_time'=>$now_time,'frogen'=>3],
                ['uid'=>$user['id'],'coin_id'=>4,'type'=>1,'num'=>$change_money,'old_money'=>$user['i_money'],'now_money'=>($user['i_money']+$change_money),'remark'=>$str,'create_time'=>$now_time,'frogen'=>3],
            ];
            Db::name('user_coin_log')->insertAll($logs);
            Db::commit();
            return ['status' => 1, 'message' => '转换成功'];
        } catch (\Exception $e) {
            Db::rollback();
            return ['status' => 0, 'message' => '转换失败'];
        }
    }
    
    /**
     * C端财富榜页面数据
     * @param int $user_id  用户id
     * @return array
     */
    public function wealthData($user)
    {
        $user_id = $user['id'];
        $cache_key = '_wealth_data_list_';
        //分享收益
        //粉丝数量
        $fans_count = Db::name('user')->where(['sid' => $user_id])->count();
        //已提现总金额
        $take_cash = Db::name('app_user_cash')->where(['user_id' => $user_id, 'status' => 1])->sum('total_money');
        //累计收益
        $history_cash = Db::name('user_coin_log')->where(['uid' => $user_id, 'coin_id' => 3, 'type' => 1])->sum('num');
        //财富榜
        //参与人数
        $join_count = Db::name('user')->count();
        //财富榜列表
        $list = cache($cache_key);
        if(empty($list)){
            $list_count = 10;
            $sql = 'select sum(ucl.num) as total,u.user from user_coin_log ucl
                    left join user u on u.id=ucl.uid
                    where ucl.coin_id in (3,4) and ucl.type=1 and ucl.frogen=3 group by ucl.uid order by total desc limit '.$list_count;
            $list = Db::query($sql);
            foreach($list as $key => $val){
                if($val['user'] == '18627126988')unset($list[$key]);
                $list[$key]['user'] = substr($val['user'], 0, 3).'****'.substr($val['user'], -4);
                $list[$key]['total'] = round($val['total'], 2);
            }
            $list = array_slice($list, 0, 3);
            cache($cache_key, $list, 60*60);
        }
        $data = [
            'take_cash' => $take_cash,
            'fans_count' => $fans_count,
            'history_cash' => $history_cash,
            'join_count' => $join_count,
            'list' => $list,
            'number' => $user['number'],
        ];
        return $data;
    }
    
    /**
     * 获取用户的收款方式
     * @param int $user_id
     * @return array
     */
    public function getUserPaymentMethod($user_id)
    {
        //银行卡列表
        $bank = Db::name('user_bank')->field('id,user,name,bank_card,bank_name,default,sub_bank_name')->where(['uid' => $user_id])->all();
        //支付宝账号
        $ali_pay = Db::name('user')->where(['id' => $user_id])->value('alipay');
        return ['bank' => $bank, 'ali_pay' => $ali_pay];
    }
    
    /**
     * 验证添加/修改银行卡提交数据
     * @param array $input
     * @return array
     */
    private function _check_bank_data(&$input)
    {
        if(empty($input))return ['status' => 0, 'message' => '提交数据为空'];
        if(!isset($input['name']) || empty($input['name']))return ['status' => 0, 'message' => '请输入持卡人姓名'];
        if(!isset($input['bank_name']) || empty($input['bank_name']))return ['status' => 0, 'message' => '请选择开户银行'];
        if(!isset($input['sub_bank_name']) || empty($input['sub_bank_name']))return ['status' => 0, 'message' => '请输入所属支行'];
        if(!isset($input['bank_card']) || empty($input['bank_card']))return ['status' => 0, 'message' => '请输入银行卡号'];
        if(!self::verifyBankNumber($input['bank_card']))return ['status' => 0, 'message' => '银行卡号不正确'];
        $input['default'] = (isset($input['default']) && ($input['default'] != 0)) ? 1 : 0;
        $input['id'] = (isset($input['id']) && ($input['id'] > 0)) ? $input['id'] : 0;
        
        return ['status' => 1, 'message' => 'success', 'data' => $input];
    }
    //添加银行卡数据
    private function _insert_bank_data(&$user, &$data)
    {
        $count = Db::name('user_bank')->where(['uid' => $user['id'], 'bank_card' => $data['bank_card']])->count();
        if($count)return ['status' => 0, 'message' => '该卡号已添加.'];
        $insert_data = [
            'uid' => $user['id'],
            'user' => $user['user'],
            'name' => $data['name'],
            'bank_name' => $data['bank_name'],
            'bank_card' => $data['bank_card'],
            'default' => $data['default'],
            'time' => time(),
            'sub_bank_name' => $data['sub_bank_name'],
        ];
        Db::name('user_bank')->insert($insert_data);
        return ['status' => 1, 'message' => '添加成功'];
    }
    //修改银行卡数据
    private function _update_bank_data(&$user, &$data)
    {
        $bank = Db::name('user_bank')->field('id,bank_card')->where(['id' => $data['id'], 'uid' => $user['id']])->find();
        if(empty($bank))return ['status' => 0, 'message' => '修改数据不存在'];
        if($bank['bank_card'] != $data['bank_card']){
            $count = Db::name('user_bank')->where(['uid' => $user['id'], 'bank_card' => $data['bank_card']])->count();
            if($count)return ['status' => 0, 'message' => '该卡号已添加.'];
        }
        $update_data = [
            'name' => $data['name'],
            'bank_name' => $data['bank_name'],
            'bank_card' => $data['bank_card'],
            'sub_bank_name' => $data['sub_bank_name']
        ];
        Db::name('user_bank')->where(['id' => $bank['id']])->update($update_data);
        return ['status' => 1, 'message' => '修改成功'];
    }
    /**
     * C端用户添加/修改银行卡数据
     * @param array $user       用户数据
     * @param array $input      银行卡数据
     * @return array
     */
    public function insertOrUpdateBankInfo($user, $input)
    {
        if(empty($user))return ['status' => 0, 'message' => '数据为空'];
        $check_res = $this->_check_bank_data($input);
        if(empty($check_res['status']))return $check_res;
        $data = $check_res['data'];
        
        if(empty($data['id'])){//添加
            return $this->_insert_bank_data($user, $data);
        }else{//修改
            return $this->_update_bank_data($user, $data);
        }
    }
    
    /**
     * C端用户添加/修改支付宝账号
     * @param int $user_id  用户id
     * @param array $input  提交数据
     * @return array
     */
    public function insertOrUpdateAlipay($user_id, $input)
    {
        if(empty($user_id) || !isset($input['ali_pay']) || empty($input['ali_pay']))return ['status' => 0, 'message' => '提交数据为空'];
        Db::name('user')->where(['id' => $user_id])->update(['alipay' => trim($input['ali_pay'])]);
        return ['status' => 1, 'message' => '操作成功'];
    }
    
    /**
     * 提现至银行卡
     * @param int $user_id      用户id
     * @param number $count     提现金额
     * @param int $bank_id      银行卡记录id
     * @return array
     */
    private function _cash_to_bank($user_id, $count, $bank_id)
    {
        self::limitOperateFrequency('_cash_to_bank_user_id_'.$user_id);
        $rate_money = round($count*0.05, 2);
        if($rate_money <= 0)return ['status' => 0, 'message' => '手续费过低'];
        $real_money = round(($count-$rate_money), 2);
        $bank = Db::name('user_bank')->field('name,bank_name,bank_card,id_card,leave_phone,sub_bank_name')->where(['id' => $bank_id, 'uid' => $user_id])->find();
        if(empty($bank))return ['status' => 0, 'message' => '未查询到银行卡信息'];
        
        $now_time = time();
        $app_user_cash_data = [
            'user_id' => $user_id,
            'order_id' => self::createRandomNumber(32),
            'order_type' => 3,
            'total_money' => $count,
            'real_money' => $real_money,
            'rate_money' => $rate_money,
            'created_at' => $now_time,
            'order_data' => json_encode([
                'user_name' => $bank['name'],
                'bank_no' => $bank['bank_card'],
                'bank_name' => $bank['bank_name'],
                'id_card' => $bank['id_card'],
                'leave_phone' => $bank['leave_phone'],
                'sub_bank_name' => $bank['sub_bank_name'],
            ]),
        ];
        
        Db::startTrans();
        try {
            $suanli_service = new SuanliService();
            //扣除用户余额
            $suanli_service->userSuanli($user_id, 'i_money', 2, $count, 3, $now_time, '提现划扣');
            //手续费转入公司账户
            $suanli_service->companySuanli('i_money', 1, $rate_money, 3, $now_time, '提现手续费');
            //插入提现记录
            Db::name('app_user_cash')->insert($app_user_cash_data);
            Db::commit();
            return ['status' => 1, 'message' => '申请成功，请等待审核'];
        } catch (\Exception $e) {
            Db::rollback();
            return ['status' => 0, 'message' => '提现失败，请稍后再试'];
        }
    }
    /**
     * 用户提现
     * @param int $user_id      用户id
     * @param int $type         提现类型，1支付宝，2微信，3银行卡
     * @param number $count
     * @param number $bank_id
     * @return array
     */
    public function userCash($user_id, $type, $count, $bank_id = 0)
    {
        if(empty($user_id) || empty($type) || ($count <= 0))return ['status' => 0, 'message' => '提交数据有误'];
        if(!in_array($type, [1, 2, 3]))return ['status' => 0, 'message' => '提现方式不正确'];
        if(($type == 3) && empty($bank_id))return ['status' => 0, 'message' => '请选择提现银行'];
        if($type != 3 && $count >= 1000)return ['status' => 0, 'message' => '大于1000请提现至银行卡'];
        $count = round($count, 2);
        if($count < 10)return ['status' => 0, 'message' => '提现金额必须大于10'];
        
        $user = Db::name('user')->field('user,i_money')->where(['id' => $user_id, 'status' => 1])->find();
        if(empty($user))return ['status' => 0, 'message' => '用户不存在或被禁用'];
        if($user['i_money'] < $count)return ['status' => 0, 'message' => '可提现余额不足'];
        
        $now_time = time();
        $sign = md5('id='.$user_id.'&user='.$user['user'].'&timestamp='.$now_time);
        $post_data = ['uid' => $user_id, 'money' => $count, 'bank_id' => $bank_id, 'time' => $now_time, 'sign' => $sign];
        if($type == 1){
            $res = self::openCurl(self::OUT_API_URL.'pay/al-cash', $post_data);
            $res = json_decode($res, true);
        }else if($type == 2){
            $res = ['status' => 0, 'message' => '暂不支持提现至微信'];
        }else if($type == 3){
            $res = $this->_cash_to_bank($user_id, $count, $bank_id);
        }
        if(isset($res['status'])){
            return $res;
        }else{
            return ['status' => 0, 'message' => '系统无响应，请稍后再试..'];
        }
    }
    
    /**
     * 提现记录
     * @param int $user_id
     * @param int $page
     * @param int $page_size
     * @return array
     */
    public function userCashLog($user_id, $page = 1, $page_size = 10)
    {
        $arr = [];
        $count = Db::name('app_user_cash')->where(['user_id' => $user_id])->where('status', '<', 3)->count();
        if(empty($count))return ['count' => $count, 'list' => $arr, 'page_count' => 0];
        $page_count = ceil($count/$page_size);
        $page_start = ($page_count >= $page) ? max(0, ($page-1))*$page_size : 0;
        $list = Db::name('app_user_cash')->where([['user_id', '=', $user_id], ['status', '<', 3]])
        ->field('id,order_type,total_money,real_money,rate_money,status,remark,created_at,order_data')
        ->limit($page_start, $page_size)->order(['id' => 'desc'])->all();
        if($list){
            foreach($list as $key => $val){
                $order_data_arr = json_decode($val['order_data'], true);
                if($val['order_type'] == 1){
                    $str = '提现-支付宝';
                }else if($val['order_type'] == 3){
                    $str = '提现-'.$order_data_arr['bank_name'].'('.substr($order_data_arr['bank_no'], -4).')';
                }else{
                    $str = '提现-微信';
                }
                $arr[$key]['title'] = $str;
                $arr[$key]['status_str'] = $val['status'] == 0 ? '申请中' : (($val['status'] == 1) ? '成功' : '失败');
                $arr[$key]['reject_reason'] = ($val['status'] == 2) ? $val['remark'] : '';
                $arr[$key]['created_at'] = date('Y-m-d H:i:s', $val['created_at']);
                $order_data_arr = null;
            }
            $list = null;
        }
        return ['count' => $count, 'list' => $arr, 'page_count' => $page_count];
    }
    
    /**
     * 用户升级服务商
     * @param int $user_id  用户id
     * @return array
     */
    public function userUpgradeServiceProvider($user_id)
    {
        $limit_fans_count = 15;
        if(empty($user_id))return ['status' => 0, 'message' => '用户数据异常'];
        $member = Db::name('app_user_member_rel')->field('id,is_service_provider,expire_time')->where(['app_user_id' => $user_id])->find();
        if(empty($member))return ['status' => 0, 'message' => '不是会员无法升级成服务商'];
        if($member['is_service_provider'] == 1)return ['status' => 0, 'message' => '已是服务商，无需重复升级'];
        if($member['expire_time'] < time())return ['status' => 0, 'message' => '会员过期无法升级服务商'];
        $vip_fans_count = Db::name('app_user_member_rel')->alias('aumr')->leftJoin('user u', 'aumr.app_user_id=u.id')->where(['u.sid' => $user_id])->count();
        if($vip_fans_count < $limit_fans_count)return ['status' => 0, 'message' => '粉丝创始会员数量少于'.$limit_fans_count];
        
        Db::name('app_user_member_rel')->where(['id' => $member['id']])->update(['is_service_provider' => 1, 'service_update_type' => 1, 'updated_at' => time()]);
        return ['status' => 1, 'message' => '升级成功'];
    }
}
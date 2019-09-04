<?php
namespace app\common\service;

use think\Db;

/**
 * 美食-商品
 */
class FoodService extends CommonService
{
    //商品结账单位缓存key
    const FOOD_SETTLEMENT_UNIT_CACHE_KEY = '_food_settlement_unit_cache_key_';
    //商品分类缓存key
    const FOOD_CATE_CACHE_KEY = '_food_cate_cache_key_';
    //用户店铺缓存key
    const STORE_BY_USER_ID_CACHE_KEY = '_store_by_user_id_cache_key_';
    //商品分类及其下的商品数据缓存key
    const FOOD_IN_FOOD_CATE_CACHE_KEY = '_food_in_food_cate_cache_key_';
    
    /**
     * 获取结账单位信息
     * @return array
     */
    public static function getFoodSettlementUnit()
    {
        if(($cache_value = cache(self::FOOD_SETTLEMENT_UNIT_CACHE_KEY)))return $cache_value;
        
        $settlement_unit = Db::name('app_food_settlement_unit')->field('id,name')->where([['sort', '>', 0]])->order(['sort' => 'asc'])->all();
        cache(self::FOOD_SETTLEMENT_UNIT_CACHE_KEY, $settlement_unit, (60*60*24*30));
        return $settlement_unit;
    }
    
    /**
     * 删除缓存的结账单位信息
     * @return boolean
     */
    public static function deleteFoodSettlementUnitCache()
    {
        if(cache(self::FOOD_SETTLEMENT_UNIT_CACHE_KEY))cache(self::FOOD_SETTLEMENT_UNIT_CACHE_KEY, null);
        return true;
    }
    
    /**
     * 根据uid获取店铺信息
     * @param int $uid  用户id
     * @return boolean|array
     */
    public function _get_store_by_user_id($user_id)
    {
        $cache_key = self::STORE_BY_USER_ID_CACHE_KEY.$user_id;
        if(empty($user_id))return false;
        if(($cache_value = cache($cache_key)))return $cache_value;
        $store = Db::name('app_store')->field('id,uid,status')->where(['uid' => $user_id])->find();
        cache($cache_key, $store, (60*60*24*5));
        return $store;
    }
    
    /**
     * 获取食品分类
     * @param int $store_id
     * @return array
     */
    public static function getFoodCate($store_id)
    {
        $cache_key = self::FOOD_CATE_CACHE_KEY.$store_id;
        if(($cache_value = cache($cache_key)))return $cache_value;
        
        $food_cate = Db::name('app_food_cate')->field('id,store_id,name,icon,created_at')->where([['store_id', '=', $store_id], ['sort', '>', 0]])->order(['sort' => 'asc', 'id' => 'desc'])->all();
        cache($cache_key, $food_cate, (60*60*24*30));
        return $food_cate;
    }
    
    /**
     * 删除食品分类缓存
     * @param int $store_id  店铺id
     * @return boolean
     */
    public static function deleteFoodCateCache($store_id)
    {
        $cache_key = self::FOOD_CATE_CACHE_KEY.$store_id;
        if(cache($cache_key))cache($cache_key, null);
        return true;
    }
    
    /**
     * 操作商品分类信息
     * @param int $user_id    当前操作人id
     * @param string $cate_name   分类名称
     * @param int $id         当前记录id
     * @param int $sort       排序，传0等于删除
     * @param string $icon    图片
     * @return array
     */
    public function operFoodCate($user_id, $cate_name, $id, $sort = 1, $icon = '')
    {
        if(empty($cate_name) && ($sort > 0))return ['status' => 0, 'message' => '分类名称不能为空'];
        //查询用户对应的店铺id
        $store = $this->_get_store_by_user_id($user_id);
        if(empty($store))return ['status' => 0, 'message' => '未查询到店铺信息'];
        
        $data = ['store_id' => $store['id'], 'icon' => $icon, 'sort' => $sort, 'created_by' => $user_id];
        if($sort != 0)$data['name'] = $cate_name;
        
        if(empty($id)){
            $data['created_at'] = time();
            Db::name('app_food_cate')->insert($data);
        }else{
            $count = Db::name('app_food_cate')->where(['id' => $id, 'store_id' => $store['id']])->count();
            if(empty($count))return ['status' => 0, 'message' => '无权进行该操作'];
            $data['updated_at'] = time();
            Db::name('app_food_cate')->where(['id' => $id])->update($data);
        }
        self::deleteFoodCateCache($store['id']);
        self::deleteFoodInFoodCateCache($store['id']);
        
        //返回新数据
        $new_data = $this->getFoodCate($store['id']);
        return ['status' => 1, 'message' => '操作成功', 'data' => $new_data];
    }
    
    /**
     * 商品分类排序
     * @param array $data   提交数据
     * @param int $store_id 店铺id
     * @return array
     */
    public function sortFoodCate($data, $store_id)
    {
        if(empty($data) || empty($store_id) || !is_array($data))return ['status' => 0, 'message' => '提交数据为空或不正确'];
        $now_time = time();
        foreach($data as $key => $val){
            Db::name('app_food_cate')->where(['id' => $val, 'store_id' => $store_id])->update(['sort' => ($key+1), 'updated_at' => $now_time]);
        }
        self::deleteFoodCateCache($store_id);
        self::deleteFoodInFoodCateCache($store_id);
        return ['status' => 1, 'message' => '操作成功'];
    }
    
    /**
     * 整理商品数据
     * @param array $data
     * @return array
     */
    private function _arrange_food_data($data)
    {
        if(empty($data))return ['status' => 0, 'message' => '提交数据为空'];
        if(!isset($data['uid']) || empty($data['uid']))return ['status' => 0, 'message' => '用户信息异常'];
        if(isset($data['status']) && ($data['status'] == 0 || $data['status'] == 2))return ['status' => 1, 'message' => 'success', 'data' => $data];
        if(!isset($data['cate_id']) || empty($data['cate_id']))return ['status' => 0, 'message' => '请选择分类'];
        if(!isset($data['name']) || empty($data['name']))return ['status' => 0, 'message' => '请输入名称'];
        if(!isset($data['price']) || (round($data['price'], 2) <= 0))return ['status' => 0, 'message' => '请输入单价'];
        if(!isset($data['unit_id']) || empty($data['unit_id']))return ['status' => 0, 'message' => '请选择结账单位'];
        if(!isset($data['img']) || empty($data['img']))return ['status' => 0, 'message' => '请上传主图'];
        return ['status' => 1, 'message' => 'success', 'data' => $data];
    }
    
    /**
     * 添加/修改/删除商品
     * @param array $input
     * @return array
     */
    public function operFood($input)
    {
        $check_res = $this->_arrange_food_data($input);
        if(empty($check_res['status']))return $check_res;
        $data = $check_res['data'];
        
        $store = $this->_get_store_by_user_id($data['uid']);
        if(empty($store))return ['status' => 0, 'message' => '未查询到店铺信息'];
        $id = (isset($data['id']) && $data['id']) ? $data['id'] : 0;
        
        $now_time = time();
        if(isset($data['status']) && ($data['status'] == 0 || $data['status'] == 2)){
            Db::name('app_food')->where(['id' => $id, 'store_id' => $store['id']])->update(['status' => $data['status'], 'updated_at' => $now_time]);
        }else{
            $arr = [
                'store_id' => $store['id'],
                'cate_id' => $data['cate_id'],
                'name' => trim($data['name']),
                'sell_price' => round($data['price'], 2),
                'origin_price' => round($data['price'], 2),
                'settlement_unit_id' => $data['unit_id'],
                'thumb_img' => $data['img'],
                'created_by' => $store['uid'],
            ];
            if($id){
                $arr['updated_at'] = $now_time;
                $arr['status'] = $data['status'];
                Db::name('app_food')->where(['id' => $id, 'store_id' => $store['id']])->update($arr);
            }else{
                $arr['created_at'] = $now_time;
                Db::name('app_food')->insert($arr);
            }
        }
        self::deleteFoodInFoodCateCache($store['id']);
        return ['status' => 1, 'message' => '操作成功'];
    }
    
    /**
     * 获取商品分类及其下商品数据
     * @param int $store_id   店铺id
     * @return boolean|array
     */
    private function _get_food_in_food_cate_by_cache($store_id, $search = '')
    {
        $cache_key = self::FOOD_IN_FOOD_CATE_CACHE_KEY.$store_id;
        if(($search == '') && ($cache_value = cache($cache_key)))return $cache_value;
        
        if(empty($store_id))return false;
        $food_cate = self::getFoodCate($store_id);
        if(empty($food_cate))return false;
        $where = [['store_id', '=', $store_id], ['status', '>', 0]];
        if($search)$where[] = ['name', 'like', '%'.$search.'%'];
        $food = Db::name('app_food')->where($where)
        ->field('id,store_id,cate_id,is_menu,point_money,name,sell_price,sell_count,settlement_unit_id,thumb_img,status,created_at')
        ->order(['sort' => 'asc'])
        ->all();
        
        foreach($food_cate as $key => $val){
            foreach($food as $ke => $va){
                if($va['cate_id'] == $val['id']){
                    $food_cate[$key]['foods'][] = $va;
                }
            }
            if(!isset($food_cate[$key]['foods']) || empty($food_cate[$key]['foods']))unset($food_cate[$key]);
        }
        $food = null;
        if($search == '')cache($cache_key, $food_cate, (60*60));
        return $food_cate;
    }
    
    /**
     * 删除商品分类及其下商品的缓存数据
     * @param int $store_id
     * @return boolean
     */
    public static function deleteFoodInFoodCateCache($store_id)
    {
        $cache_key = self::FOOD_IN_FOOD_CATE_CACHE_KEY.$store_id;
        if(cache($cache_key))cache($cache_key, null);
        return true;
    }
    
    /**
     * 获取商品分类及其下商品数据
     * @param int $store_id   店铺id
     * @param boolean $is_all   是否获取全部
     * @return boolean|array
     */
    public function getFoodInFoodCate($store_id, $search = '', $is_all = false)
    {
        $foods = $this->_get_food_in_food_cate_by_cache($store_id, $search);
        if(empty($foods))return false;
        if($is_all == false){
            foreach($foods as $key => $val){
                foreach($val['foods'] as $ke => $va){
                    if(empty($va['is_menu']))unset($foods[$key]['foods'][$ke]);
                }
                if(!isset($foods[$key]['foods']) || empty($foods[$key]['foods']))unset($foods[$key]);
            }
        }
        sort($foods);
        return $foods;
    }
    
    /**
     * 用户取消订单
     * @param string $order_no  订单号
     * @param int $user_id      用户id
     * @return array
     */
    public function userCancelOrder($order_no, $user_id)
    {
        if(empty($order_no) || empty($user_id))return ['status' => 0, 'message' => '提交数据为空'];
        $order = Db::name('app_food_order')->field('id,store_id,user_id,order_no,rate,rate_time,status')->where(['user_id' => $user_id, 'order_no' => $order_no, 'status' => 1])->find();
        if(empty($order))return ['status' => 0, 'message' => '订单不存在或已取消'];
        
        Db::startTrans();
        try {
            //修改订单状态
            Db::name('app_food_order')->where(['id' => $order['id']])->update(['status' => 6, 'updated_at' => time()]);
            //释放所抢折扣
            Db::name('app_food_user_store_discount')->where(['user_id' => $order['user_id'], 'store_id' => $order['store_id'], 'flag_time' => $order['rate_time'], 'rate' => $order['rate'], 'updated_at' => 0])->delete();
            Db::commit();
            return ['status' => 1, 'message' => '取消成功.'];
        } catch (\Exception $e) {
            Db::rollback();
            return ['status' => 0, 'message' => '取消失败，请稍后再试..'];
        }
    }
}
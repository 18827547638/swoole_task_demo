<?php
namespace app\common\service;

use think\Db;

class StoreService extends CommonService
{
    //店铺评价标签缓存key
    const FOOD_STORE_EVALUATE_TAG_CACHE_KEY = '_food_store_evaluate_tag_cache_key_';
    //店铺分类缓存key
    const FOOD_STORE_CATE_CACHE_KEY = '_food_store_cate_cache_key_';
    //单店铺数据缓存key
    const SINGLE_FOOD_STORE_CACHE_KEY = '_single_food_store_cache_key_';
    //生活周边分类id
    const LIFE_SURROUNDING = 15;
    
    /**
     * 获取店铺分类
     * @return array
     */
    public static function getFoodStoreCate()
    {
        if(($cache_value = cache(self::FOOD_STORE_CATE_CACHE_KEY)))return $cache_value;
        
        $cate = Db::name('app_store_cate')->where([['sort', '>', 0]])->order(['sort' => 'asc'])->all();
        cache(self::FOOD_STORE_CATE_CACHE_KEY, $cate, (60*60*24*30));
        return $cate;
    }
    
    /**
     * 删除店铺分类缓存
     * @return boolean
     */
    public static function deltedFoodStoreCateCache()
    {
        if(cache(self::FOOD_STORE_CATE_CACHE_KEY))cache(self::FOOD_STORE_CATE_CACHE_KEY, null);
        return true;
    }
    
    /**
     * 获取店铺评价标签
     * @return array
     */
    public static function getFoodStoreEvaluateTag()
    {
        if(($cache_value = cache(self::FOOD_STORE_EVALUATE_TAG_CACHE_KEY)))return $cache_value;
        
        $store_tag = Db::name('app_food_store_evaluate_tag')->field('id,name')->where([['sort', '>', 0]])->order(['sort' => 'asc'])->all();
        cache(self::FOOD_STORE_EVALUATE_TAG_CACHE_KEY, $store_tag, (60*60*24*30));
        return $store_tag;
    }
    
    /**
     * 删除店铺评价标签缓存数据
     * @return boolean
     */
    public static function deleteFoodStoreEvaluateTagCache()
    {
        if(cache(self::FOOD_STORE_EVALUATE_TAG_CACHE_KEY))cache(self::FOOD_STORE_EVALUATE_TAG_CACHE_KEY, null);
        return true;
    }
    
    /**
     * 增加评价标签数量
     * @param int $store_id     店铺id
     * @param array $tag_ids    选中标签id数组,[1,2...]
     * @return boolean
     */
    public static function operFoodStoreEvaluateTagCount($store_id, $tag_ids)
    {
        if(empty($store_id) || empty($tag_ids))return true;
        $step = 1;
        $tag_ids = array_unique($tag_ids);
        foreach($tag_ids as $val){
            $update = Db::name('app_food_store_evaluate_tag_count')->where(['store_id' => $store_id, 'tag_id' => $val])->setInc('tag_count', $step);
            if(empty($update)){
                Db::name('app_food_store_evaluate_tag_count')->insert(['store_id' => $store_id, 'tag_id' => $val, 'tag_count' => $step]);
            }
        }
        return true;
    }
    
    /**
     * 整理用户评价数据
     * @param array $input
     * @return array
     */
    private function _arrange_food_store_evaluate_data($input)
    {
        if(!isset($input['uid']) || empty($input['uid']))return ['status' => 0, 'message' => '用户信息不存在'];
        if(!isset($input['store_id']) || empty($input['store_id']))return ['status' => 0, 'message' => '店铺信息不存在'];
        if(!isset($input['order_no']) || empty($input['order_no']))return ['status' => 0, 'message' => '订单信息不存在'];
        
        if(!isset($input['tas_score']))return ['status' => 0, 'message' => '请选择味道评分'];
        $input['tas_score'] = round($input['tas_score'], 1);
        if(($input['tas_score'] <= 0) || ($input['tas_score'] > 5))return ['status' => 0, 'message' => '味道评分不正确'];
        
        if(!isset($input['env_score']))return ['status' => 0, 'message' => '请选择环境评分'];
        $input['env_score'] = round($input['env_score'], 1);
        if(($input['env_score'] <= 0) || ($input['env_score'] > 5))return ['status' => 0, 'message' => '环境评分不正确'];
        
        if(!isset($input['ser_score']))return ['status' => 0, 'message' => '请选择服务评分'];
        $input['ser_score'] = round($input['ser_score'], 1);
        if(($input['ser_score'] <= 0) || ($input['ser_score'] > 5))return ['status' => 0, 'message' => '服务评分不正确'];
        
        if(!isset($input['desc_str']) && !isset($input['desc_img']))return ['status' => 0, 'message' => '请输入文字评价或上传图片评价'];
        if(empty($input['desc_str']) && empty($input['desc_img']))return ['status' => 0, 'message' => '请输入文字评价或上传图片评价...'];
        if(!isset($input['tag_id']) && empty($input['tag_id']))return ['status' => 0, 'message' => '请选择评价标签'];
        
        return ['status' => 1, 'message' => 'success', 'data' => $input];
    }
    
    /**
     * 插入评价数据
     * @param array $input
     * @return array
     * [
     *      'uid' => 1258,
     *      'store_id' => 12,
     *      'order_no' => 'xxx',
     *      'tas_score' => 4.5,
     *      'env_score' => 5,
     *      'ser_score' => 3.5,
     *      'desc_str' => 'xxxxxx',
     *      'desc_img' => ['xxx1', 'xxx2', 'xxxx3']
     *      'tag_id' => [1,2,3]
     * ]
     */
    public function insertFoodStoreEvaluate($input)
    {
        $check_res = $this->_arrange_food_store_evaluate_data($input);
        if(empty($check_res['status']))return $check_res;
        $data = $check_res['data'];
        
        $cache_key = '_temp_store_evaluate_'.$data['order_no'];
        if(cache($cache_key))return ['status' => 0, 'message' => '操作过于频繁..'];
        cache($cache_key, true, 5);
        
        //查询是否已评价过
        $is_exist = Db::name('app_food_order_evaluate')->where(['order_no' => $data['order_no'], 'pid' => 0])->count();
        if($is_exist)return ['status' => 0, 'message' => '已评价过，无需重复评价'];
        //查询订单是否已支付
        $order = Db::name('app_food_order')->field('id,user_id,order_no,check_time')->where(['order_no' => $data['order_no'], 'status' => 2])->find();
        if(empty($order) || ($order['user_id'] != $data['uid']))return ['status' => 0, 'message' => '订单数据异常'];
        if(empty($order['check_time']))return ['status' => 0, 'message' => '订单未到店核验暂时无法评价'];
        
        //评价数据
        $evaluate_data = [
            'store_id' => $data['store_id'],
            'user_id' => $data['uid'],
            'order_no' => $data['order_no'],
            'tas_score' => $data['tas_score'],
            'env_score' => $data['env_score'],
            'ser_score' => $data['ser_score'],
            'avg_score' => round(($data['tas_score']+$data['env_score']+$data['ser_score'])/3, 1),
            'desc_str' => trim($data['desc_str']),
            'desc_img' => !empty($data['desc_img']) ? json_encode($data['desc_img']) : '',
            'created_at' => time()
        ];
        
        Db::startTrans();
        try {
            Db::name('app_food_order_evaluate')->insert($evaluate_data);
            self::operFoodStoreEvaluateTagCount($evaluate_data['store_id'], $data['tag_id']);
            Db::commit();
            return ['status' => 1, 'message' => '评价成功'];
        } catch (\Exception $e) {
            Db::rollback();
            return ['status' => 0, 'message' => '您的网络不给力，请稍后再试', 'error' => $e->getMessage().$e->getFile().$e->getLine()];
        }
    }
    
    /**
     * 审核用户评价
     * @param int $store_id         店铺id
     * @param string $order_no      订单号
     * @param int $status           审核状态，0删除，1审核不通过，2审核通过
     * @return number[]|string[]    
     */
    public function checkFoodStoreEvaluate($user_id, $id, $status)
    {
        if(empty($user_id) || empty($id))return ['status' => 0, 'message' => '提交数据为空'];
        if(!in_array($status, [0, 1, 2]))return ['stauts' => 0, 'message' => '审核状态不正确'];
        $food_service = new FoodService();
        $store = $food_service->_get_store_by_user_id($user_id);
        if(empty($store))return ['status' => 0, 'message' => '无权审核'];
        
        $evaluate_data = Db::name('app_food_order_evaluate')->field('id,status,store_id,tas_score,env_score,ser_score')->where(['id' => $id, 'store_id' => $store['id']])->find();
        if(empty($evaluate_data))return ['status' => 0, 'message' => '评价数据不存在'];
        if($evaluate_data['status'] == 2)return ['status' => 0, 'message' => '该评价已审核过'];
        
        Db::startTrans();
        try {
            $res = Db::name('app_food_order_evaluate')->where(['id' => $evaluate_data['id']])->update(['status' => $status, 'updated_at' => time()]);
            if($res && ($status == 2)){
                $score_update = Db::name('app_food_store_score')->where(['store_id' => $evaluate_data['store_id']])
                ->inc('tas_score', $evaluate_data['tas_score'])
                ->inc('env_score', $evaluate_data['env_score'])
                ->inc('ser_score', $evaluate_data['ser_score'])
                ->inc('evaluate_count', 1)
                ->update();
                if(empty($score_update)){
                    Db::name('app_food_store_score')->insert([
                        'store_id' => $evaluate_data['store_id'],
                        'tas_score' => $evaluate_data['tas_score'],
                        'env_score' => $evaluate_data['env_score'],
                        'ser_score' => $evaluate_data['ser_score'],
                        'evaluate_count' => 1,
                    ]);
                }
            }
            Db::commit();
            return ['status' => 1, 'message' => '审核成功'];
        } catch (\Exception $e) {
            Db::rollback();
            return ['status' => 0, 'message' => '审核失败', 'error' => $e->getMessage().$e->getFile().$e->getLine()];
        }
    }
    
    /**
     * 获取每家店铺的评价标签
     * @param int $store_id
     * @return array
     */
    public function getEvaluateTag($store_id)
    {
        $cache_key = '_evaluate_tag_'.$store_id;
        if(($cache_value = cache($cache_key)))return $cache_value;
        $all = Db::name('app_food_store_evaluate_tag_count')->alias('afsetc')
        ->leftJoin('app_food_store_evaluate_tag afset', 'afsetc.tag_id=afset.id')
        ->field('afset.name,afsetc.tag_count')
        ->where([['afsetc.store_id', '=', $store_id], ['afset.sort', '>', 0]])
        ->all();
        cache($cache_key, $all, 60*60);
        return $all;
    }
    
    /**
     * 店铺评分及有效评论数量
     * @param int $store_id
     * @return array
     */
    public function getStoreScore($store_id)
    {
        $cache_key = '_store_score_'.$store_id;
        if(($cache_value = cache($cache_key)))return $cache_value;
        
        $tas_score = $env_score = $ser_score = $store_score = 0.0;
        $evaluate_count = 0;
        $score = Db::name('app_food_store_score')->field('tas_score,env_score,ser_score,evaluate_count')->where(['store_id' => $store_id])->find();
        if(!empty($score) && !empty($score['evaluate_count'])){
            $tas_score = round($score['tas_score']/$score['evaluate_count'], 1);
            $env_score = round($score['env_score']/$score['evaluate_count'], 1);
            $ser_score = round($score['ser_score']/$score['evaluate_count'], 1);
            $store_score = round(($tas_score+$env_score+$ser_score)/3, 1);
            $evaluate_count = $score['evaluate_count'];
        }
        $arr = [
            'tas_score' => $tas_score,
            'env_score' => $env_score,
            'ser_score' => $ser_score,
            'store_score' => $store_score,
            'evaluate_count' => $evaluate_count
        ];
        cache($cache_key, $arr, 60*60);
        return $arr;
    }
    
    /**
     * 评价信息
     * @param int $store_id
     * @param int $page
     * @param int $page_size
     * @return array
     */
    public function getEvaluate($store_id, $status = [], $page = 1, $page_size = 10)
    {
        $handle = Db::name('app_food_order_evaluate')->alias('afoe')
        ->leftJoin('user u', 'afoe.user_id=u.id')
        ->field('u.number,u.user,u.avator,u.nick,afoe.avg_score,afoe.desc_str,afoe.desc_img,afoe.created_at,afoe.status,afoe.id')
        ->where([['afoe.store_id', '=', $store_id], ['afoe.status', 'in', $status]]);
        
        $count = $handle->count();
        $page_count = ceil($count/$page_size);
        $page_start = ($page_count >= $page) ? max(0, $page-1)*$page_size : 0;
        $list = $handle->limit($page_start, $page_size)->order(['afoe.status' => 'asc', 'afoe.id' => 'desc'])->all();
        
        foreach($list as $key => $val){
            $list[$key]['user'] = substr($val['user'], 0, 3).'****'.substr($val['user'], -4);
            $list[$key]['created_at'] = date('Y-m-d', $val['created_at']);
            $list[$key]['desc_img'] = !empty($val['desc_img']) ? json_decode($val['desc_img'], true) : '';
        }
        return ['count' => $count, 'list' => $list, 'page_count' => $page_count];
    }
    
    /**
     * 评价信息，商家后台展示
     * @param int $user_id
     * @param int $store_id
     * @param number $page
     * @param number $page_size
     * @return array
     */
    public function getEvaluateForBackend($user_id, $store_id, $page = 1, $page_size = 10)
    {
        $store = (new FoodService())->_get_store_by_user_id($user_id);
        if(empty($store) || ($store['id'] != $store_id))return ['status' => 0, 'message' => '未查询到店铺信息'];
        
        $data = $this->getEvaluate($store['id'], [1, 2], $page, $page_size);
        return ['status' => 1, 'message' => 'success', 'data' => $data];
    }
    
    /**
     * 获取单个店铺数据
     * @param int $store_id     店铺id
     * @param int $user_id      用户id
     * @return boolean|array;
     */
    public static function getSingleFoodStore($store_id, $user_id = 0)
    {
        if(empty($store_id) && !empty($user_id)){
            $store = (new FoodService())->_get_store_by_user_id($user_id);
            $store_id = !empty($store) ? $store['id'] : 0;
        }
        if(empty($store_id))return false;
        
        $cache_key = self::SINGLE_FOOD_STORE_CACHE_KEY.$store_id;
        if(($cache_value = cache($cache_key)))return $cache_value;
        
        $app_store = Db::name('app_store')->where(['id' => $store_id])->find();
        cache($cache_key, $app_store, 60*60*24*7);
        return $app_store;
    }
    
    /**
     * 删除单个店铺缓存数据
     * @param int $store_id 店铺id
     * @return boolean
     */
    public static function deleteSingleFoodStoreCache($store_id)
    {
        $cache_key = self::SINGLE_FOOD_STORE_CACHE_KEY.$store_id;
        if(cache($cache_key))cache($cache_key, null);
        return true;
    }
    
    /**
     * 获取店铺分类文本信息
     * @param string|int|array $cate_id 分类id
     * @param boolean $return_array     是否返回数组
     * @param string $implode_str       返回字符串时的连接符
     * @return string|array
     */
    public static function getFoodStoreCateStrById($cate_id, $return_array = false, $implode_str = ' |')
    {
        $ids = (is_string($cate_id) || is_numeric($cate_id)) ? explode(',', $cate_id) : $cate_id;
        $cate = self::getFoodStoreCate();
        $cate_str = '';
        $arr = [];
        foreach($cate as $key => $val){
            if(in_array($val['id'], $ids)){
                if($return_array){
                    $arr[$val['id']] = $val['title'];
                }else{
                    $cate_str .= $val['title'].$implode_str;
                }
            }
        }
        return $return_array ? $arr : rtrim($cate_str, $implode_str);
    }
    
    /**
     * C端商铺列表数据
     * @param number $city_lat  当前用户纬度
     * @param number $city_lng  当前用户经度
     * @param string $city      市
     * @param string $area      区
     * @param string $name      店铺名称
     * @param string $cate_id   分类
     * @param number $rate      折扣
     * @param int $distance     1距离优先，2综合排序
     * @param int $type         0全部，1名店优选，2口碑好店，3本地美食，4生活周边，5低至五折
     * @param int $page         当前页码
     * @param int $page_size    每页数量
     * @return array
     */
    private function _store_list_for_frontend($city_lat, $city_lng, $city, $area, $name, $cate_id, $rate, $distance, $type, $page, $page_size)
    {
        $city_lat = is_numeric($city_lat) ? $city_lat : 0;
        $city_lng = is_numeric($city_lng) ? $city_lng : 0;
        $order_by = [];
        $rate_flag = ($rate > 0 && $rate < 1) ? true : false;
        $where = [['status', '=', 1]];
        //市
        if($city) $where[] = ['shi', '=', SystemAreaService::getAreaIdByName($city)];
        //区
        if($area)$where[] = ['qu', '=', SystemAreaService::getAreaIdByName($area)];
        //分类，部分查询剔除生活周边
        $where[] = $cate_id ? ['', 'EXP', Db::raw("FIND_IN_SET($cate_id, cid)")] : ['', 'EXP', Db::raw("!FIND_IN_SET(".self::LIFE_SURROUNDING.", cid)")];
        //店铺名称
        if($name)$where[] =['title', 'like', '%'.$name.'%'];
        //具体折扣查询(先查折扣店铺，再查店铺信息)
        if($rate_flag)$where[] = ['id', 'in', StoreDiscountService::getStoreIdByRate($rate)];
        //店铺归类
        //if($type)$where[] = ['', 'EXP', Db::raw("FIND_IN_SET($type, 'store_type)")];
        //距离排序
        if($distance == 1)$order_by['distance'] = 'asc';
        
        $count = Db::name('app_store')->where($where)->count();
        $page_count = ceil($count/$page_size);
        $page_start = ($page_count >= $page) ? max(0, $page - 1)*$page_size : 0;
        $field="id,main_img,title,cid,per_price,dress,(ACOS(SIN((".$city_lat." * 3.1415) / 180 ) *SIN((lat * 3.1415) / 180 ) +COS((".$city_lat." * 3.1415) / 180 ) * COS((lat * 3.1415) / 180 ) *COS((".$city_lng." * 3.1415) / 180 - (lng * 3.1415) / 180 ) ) * 6380) as distance";
        $list = Db::name('app_store')->field($field)->where($where)->order($order_by)->limit($page_start, $page_size)->all();
        
        if($list){
            //查询最低折扣便于显示，若是折扣查询，则直接显示该折扣
            if(!$rate_flag)$discount = Db::name('app_food_store_discount')->where('store_id', 'in', array_column($list, 'id'))->order(['rate' => 'desc'])->column('store_id,rate', 'store_id');
            foreach($list as $key => $val){
                $list[$key]['cate_title'] = StoreService::getFoodStoreCateStrById($val['cid']);
                $list[$key]['rate'] = $rate ? $rate : (isset($discount[$val['id']]) ? $discount[$val['id']] : 0.99);
            }
        }
        return ['count' => $count, 'list' => $list, 'page_count' => $page_count];
    }
    
    //C端展示商铺列表数据
    public function getStoreListForFrontend($city_lat, $city_lng, $city, $area, $name, $cate_id, $rate, $distance, $type, $page, $page_size)
    {
        $flag_type = $type;
        //1名店优选，2口碑好店，3本地美食，4生活周边，5低至五折
        if($type == 4){
            $cate_id = self::LIFE_SURROUNDING;
            $type = 0;
        }else if($type == 5){
            $rate = 0;
            $type = 0;
        }
        //return $this->_store_list_for_frontend($city_lat, $city_lng, $city, $area, $name, $cate_id, $rate, $distance, $type, $page, $page_size);
        $res = $this->_store_list_for_frontend($city_lat, $city_lng, $city, $area, $name, $cate_id, $rate, $distance, $type, $page, $page_size);
        if($flag_type == 5 && $res['list']){
            array_multisort(array_column($res['list'], 'rate'), SORT_ASC, $res['list']);
        }
        return $res;
    }
    
    /**
     * 店铺详情，带距离
     * @param int $store_id         店铺id
     * @param string|array $items   查询字段
     * @param number $lng           用户所在经度
     * @param number $lat           用户所在纬度
     * @return boolean|array
     */
    public function getStoreDetailByStoreId($store_id, $items = '', $lng = 0, $lat = 0)
    {
        $lng = is_numeric($lng) ? $lng : 0;
        $lat = is_numeric($lat) ? $lat : 0;
        
        $arr = [];
        $store = self::getSingleFoodStore($store_id, 0);
        if(empty($store))return false;
        if(empty($items)){
            $arr = $store;
        }else{
            $items = is_string($items) ? explode(',', $items) : $items;
            foreach($store as $key => $val){
                if(in_array($key, $items))$arr[$key] = $val;
            }
        }
        $arr['distance'] = self::getDistance($lng, $lat, $store['lng'], $store['lat']);
        $store = null;
        return $arr;
    }
}
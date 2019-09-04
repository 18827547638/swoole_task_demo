<?php
/**
 * Created by PhpStorm.
 * User: xin
 * Date: 2018/10/21
 * Time: 9:49
 */

namespace app\api\controller;
use app\common\service\good\GoodService;
use think\Db;
use think\facade\Cache;

class Good  extends  BaseController
{
    protected $noAuth = ['*'];
    /**
     * 商城新首页数据
     */
    public function newgoodlist()
    {
        $page_size = 9;
        //主商品分类
        $res = $this->_get_good_cate();
        //分类下图片
        $cate_img = $this->_get_good_cate_img();
        foreach($res as $key => $val){
            array_unshift($val['sub_ids'], $val['id']);
            //各主分类下的商品
            $res[$key]['goods'] = $this->_get_good_cate_goods($val['sub_ids'], $page_size);
            unset($res[$key]['sub_ids']);
            //分类下大图
            $res[$key]['cate_img'] = isset($cate_img[$val['id']]) ? $cate_img[$val['id']] : [];
        }
        //排序
        $cate_img_empty = $cate_img_not_empty = [];
        foreach($res as $key => $val){
            //$val['total'] = (new GoodService())->getGoodSpecNumById($val['id']);
            if(empty($val['cate_img'])){
                $cate_img_empty[] = $val;
            }else{
                $cate_img_not_empty[] = $val;
            }
        }
        $res = array_merge($cate_img_not_empty, $cate_img_empty);
        return json_success('success', $res);
    }
    //获取商品分类
    private function _get_good_cate($pid = 0, $expire = 86400)
    {
        $cache_key = '_app_get_good_cate_by_pid_'.$pid;
        $arr = [];
//         if(Cache::get($cache_key))return Cache::get($cache_key);
        $res = Db::name('app_good_cate')->field('id,title,en_title as english,image,pid')->where(['status' => 1])->order('sort', 'asc')->select();
        if(empty($res))return $arr;
        $temp_data = $this->_arrange_data($res, 0);
        foreach($temp_data as $key => $val){
            if($pid == 0){
                $val['sub_ids'] = array_column($val['children'], 'id');
                unset($val['children']);
                $arr[] = $val;
            }else{
                if($val['id'] == $pid){
                    $arr = $val['children'];
                    break;
                }
            }
        }
        Cache::set($cache_key, $arr, $expire);
        return $arr;
    }
    //整理数据
    private function _arrange_data($data, $p_id)
    {
        $temp = array();
        foreach($data as $key => $val){
            if($val['pid'] == $p_id) {
                $length = count($temp);
                $temp[$length] = $val;
                unset($data[$key]);
                $temp[$length]['children'] = $this->_arrange_data($data, $val['id']);
            }
        }
        return $temp;
    }
    //获取首页分类展示大图
    private function _get_good_cate_img($expire = 86400)
    {
        $cache_key = '_app_get_good_cate_img_';
//         if(Cache::get($cache_key))return Cache::get($cache_key);
        $arr = [];
        $cate_img = Db::name('app_good_cate_homepage_rel')->field('id,good_cate_id,app_admin_user_id,img')->where(['status' => 1])->order(['sort' => 'asc'])->all();
        foreach($cate_img as $key => $val){
            $arr[$val['good_cate_id']][] = $val;
        }
        Cache::set($cache_key, $arr, $expire);
        return $arr;
    }
    //获取分类下商品，用于商城首页展示
    private function _get_good_cate_goods($cate_ids, $page_size = 9)
    {
        $cache_key = '_app_get_good_cate_goods_'.md5(implode(',', $cate_ids));
//         if(Cache::get($cache_key))return Cache::get($cache_key);
        $list = Db::name('app_good')->alias('ag')->leftJoin('app_good_rel agr', 'ag.id=agr.app_good_id')
        ->field('ag.id,ag.shop_name,ag.pic,ag.sall,ag.total,agr.another_name as nick')
        ->where([['ag.cate_id', 'in', $cate_ids], ['ag.status', '=', 1], ['agr.special', '=', 1], ['ag.can_upgrade_vip', '=', 0]])
        ->limit(0, $page_size)
        ->order(['ag.update_time' => 'desc', 'ag.id' => 'desc'])
        ->select();
        $good_ids = array_column($list, 'id');
        $spec = Db::name('app_good_rel_spec')->where('app_good_id', 'in', $good_ids)->orderRaw('spec_value*1 desc')->column('id,spec_value,point_money,app_good_id', 'app_good_id');
        foreach($list as $key => $val){
            //现金值和最低价格
            $list[$key]['cash_money'] = isset($spec[$val['id']]) ? $spec[$val['id']]['point_money'] : '0.00';
            $list[$key]['spec_value'] = isset($spec[$val['id']]) ? $spec[$val['id']]['spec_value'] : $val['shop_money'];
        }
        Cache::set($cache_key, $list, 60*60);
        return $list;
    }
    //获取单一主类下所有子类id
    public function _get_child_cate_ids($pid, $is_all = true)
    {
        $all = $this->_get_good_cate();
        foreach($all as $key => $val){
            if($val['id'] == $pid){
                if($is_all){
                    array_unshift($val['sub_ids'], $val['id']);
                    return $val['sub_ids'];
                }else{
                    return $val['sub_ids'];
                }
            }
        }
    }
    /**
     * 最新发布
     */
    public function latelygood()
    {
        $page = input('page', 1, 'trim');
        $cate_id = input('cate_id', 0, 'trim');
        $res = $this->_get_lately_sellwell_good(1, $cate_id, $page);
        return json_success('success', $res);
    }
    /**
     * 热销榜单
     */
    public function sellwellgood()
    {
        $page = input('page', 1, 'trim');
        $cate_id = input('cate_id', 0, 'trim');
        $res = $this->_get_lately_sellwell_good(2, $cate_id, $page);
        return json_success('success', $res);
    }
    /**
     * 获取最新发布和销量最多的商品，供商城首页调用
     * @param int $type  1最新发布，2热销榜单
     * @param int $cate_id  分类id
     * @param int $page
     * @param int $page_size
     * @return array
     */
    private function _get_lately_sellwell_good($type, $cate_id, $page = 1, $page_size = 10)
    {
        $where[] = ['status', '=', 1];
        $order_by = [];
        if($type == 1){//最新发布
            $order_by['ag.id'] = 'desc';
        }else if($type == 2){//热销榜单
            $order_by['sall'] = 'desc';
        }
        if(!empty($cate_id)){
            $all_cate_ids = $this->_get_child_cate_ids($cate_id, true);
            $where[] = ['ag.cate_id', 'in', $all_cate_ids];
        }
        $count = Db::name('app_good')->alias('ag')
            ->leftJoin('app_good_rel agr', 'ag.id=agr.app_good_id')
            ->where($where)
            ->count();
        $page_count = ceil($count/$page_size);
        $page_start = $page_count >= $page ? max(0, $page-1)*$page_size : 0;
        $list = Db::name('app_good')->alias('ag')
            ->leftJoin('app_good_rel agr', 'ag.id=agr.app_good_id')
            ->field('ag.id,ag.shop_name,ag.shop_money,ag.pic,ag.sall,agr.origin_price')
            ->where($where)
            ->order($order_by)
            ->limit($page_start, $page_size)
            ->all();
        $good_ids = array_column($list, 'id');
        $spec = Db::name('app_good_rel_spec')->where('app_good_id', 'in', $good_ids)->orderRaw('spec_value*1 desc')->column('id,spec_value,point_money,app_good_id', 'app_good_id');
        $temp = ($page_count - $page + 1)*100;
        foreach($list as $key => $val){
            //$list[$key]['total'] = (new GoodService())->getGoodSpecNumById($val['id']);
            //现金值和最低价格
            $list[$key]['cash_money'] = isset($spec[$val['id']]) ? $spec[$val['id']]['point_money'] : '0.00';
            $list[$key]['spec_value'] = isset($spec[$val['id']]) ? $spec[$val['id']]['spec_value'] : $val['shop_money'];
            $list[$key]['spec_id'] = isset($spec[$val['id']]) ? $spec[$val['id']]['id'] : 0;
            $list[$key]['sall'] = $val['sall'] + max(0, mt_rand(($temp-($key+1)*10), $temp-$key*10));
        }
        return ['listSize' => $count, 'list' => $list, 'listView' => $page, 'listPage' => $page_count];
    }
    /**
     * 商城首页商品数据
     * @return json
     */
    public function goodList()
    {
        try{
            $page = input("pageNum",1);
            $page_size = 10;
            $offset = max(0, $page-1)*$page_size;
            
            $list = Db::name('app_good')->alias('ag')->leftJoin('app_good_rel agr', 'ag.id=agr.app_good_id')
            ->field('ag.id,ag.shop_name,ag.pic,ag.shop_money,ag.weight,ag.unit,agr.origin_price')
            ->where(['ag.status' => 1, 'agr.special' => 1])->limit($offset, $page_size)->order(['ag.shop_money' => 'asc', 'ag.id' => 'desc'])->select();
            $total = Db::name('app_good')->alias('ag')->leftJoin('app_good_rel agr', 'ag.id=agr.app_good_id')
            ->where(['ag.status' => 1, 'agr.special' => 1])->count();
            $good_ids = array_column($list, 'id');
            $spec = Db::name('app_good_rel_spec')->where('app_good_id', 'in', $good_ids)->orderRaw('spec_value*1 desc')->column('id,spec_value,point_money,app_good_id', 'app_good_id');
            foreach($list as $key => $val){
                //现金值和最低价格
                $list[$key]['cash_money'] = isset($spec[$val['id']]) ? $spec[$val['id']]['point_money'] : '0.00';
                $list[$key]['spec_value'] = isset($spec[$val['id']]) ? $spec[$val['id']]['spec_value'] : $val['shop_money'];
            }
            
            $data['list'] = $list;
            $data['listSize'] = $total;
            $data['listView'] = $page;
            $data['listPage'] = ceil($total/$page_size);
            return json_success('1', $data);
        }catch (\Exception $e){
            return json_error($e->getMessage() . $e->getCode() . $e->getFile() . $e->getLine(), 201);
        }
    }
    /**
     * 获取分类列表
     * @return arrayr
     * @throws \think\exception\DbException
     */
    public function goodCate()
    {
        $pid = input('id', 0);
        $res = $this->_get_good_cate($pid);
        return json_success(1, ['cate' => $res]);
    }

    /**
     * 首页每日特辑商品
     */
    public function dailyGood()
    {
        $app_good_data = Db::name('app_good')->alias('ag')->leftJoin('app_good_rel agr', 'ag.id=agr.app_good_id')
        ->field('ag.id,ag.shop_money,ag.shop_name,ag.pic,ag.sall,ag.total,agr.origin_price,agr.another_name as nick')
//         ->cache(true, 60*60*3)
        ->where('ag.reward', '=', '1')->where('ag.status', '=', '1')->order('ag.update_time', 'desc')->limit(5)->select();
        return json_success(1, ['list' => $app_good_data]);
    }

    /**
     * 首页秒杀时间
     */
    public function miaoShaTime() {
        $today_start_time = Db::name('app_good_activity')->group('start_time')->having('activity_type = 1 and start_time >= ' . day_0_hour(0) . " and start_time < ". day_0_hour(1) ." and end_time > " . time())->field('activity_type,start_time,end_time')->select();
        $yesterday_start_time = Db::name('app_good_activity')->group('start_time')->having('activity_type = 1 and start_time >= ' . day_0_hour(-1) . " and  start_time < ". day_0_hour(0) ." and end_time > " . time())->field('activity_type,start_time,end_time')->select();
        $tomorrow_start_time = Db::name('app_good_activity')->group('start_time')->having('activity_type = 1 and start_time >= ' . day_0_hour(1) . " and  start_time < ". day_0_hour(2))->field('activity_type,start_time,end_time')->select();
        $start_time_array = array_column($today_start_time, 'start_time');
        if (empty($start_time_array)) {
            $start_time_array = array_column($yesterday_start_time, 'start_time');
        }
        rsort($start_time_array);
        $default_time = 0;
        foreach ($start_time_array as $key => $val){
            if ($val < time() ) {
                $default_time = $val;
                break;
            }
        }
        sort($yesterday_start_time);
        sort($today_start_time);
        sort($tomorrow_start_time);
        foreach ($yesterday_start_time as $key => &$value){
            $value['time'] = '昨日' . date("H:i", $value['start_time']);
            $value['title'] = "昨日精选";
            if ($default_time == $value['start_time']) {
                $value['default'] = true;
            } else {
                $value['default'] = false;
            }
            unset($value['end_time']);
        }

        foreach ($today_start_time as $key => &$value){
            $value['time'] = date("H:i", $value['start_time']);
            if (time() > $value['start_time']) {
                $value['title'] = "抢购中";
            } else {
                $value['title'] = "即将开抢";
            }
            if($key == 0 && $value['start_time'] > $default_time){
                $value['default'] = true;
            } else {
                if ($default_time == $value['start_time']) {
                    $value['default'] = true;
                } else {
                    $value['default'] = false;
                }
            }

            unset($value['end_time']);
        }

        foreach ($tomorrow_start_time as $key => &$value){
            $value['time'] = '明日' . date("H:i", $value['start_time']);
            $value['title'] = "即将开抢";
            if(empty($today_start_time) && empty($yesterday_start_time) && $default_time == 0 && $key == 0){
                $value['default'] = true;
            } else {
                $value['default'] = false;
            }
            unset($value['end_time']);
        }
        return json_success(1, ['list' => array_merge($yesterday_start_time, $today_start_time, $tomorrow_start_time)]);
    }

    /**
     * 首页秒杀商品列表
     */
    public function miaoShaGood() {
        $time = request()->param('time', 0);
        if(empty($time)) {
            return json_success(1, ['list' => []]);
        }
        $where[] = ['activity_type', '=', 1];
        $where[] = ['start_time', '=', $time];
        $where[] = ['end_time', '>', time()];
        $where[] = ['status', '=', 1];
        $list = Db::name('app_good_activity')->alias('aga')->leftJoin('app_good ag', 'ag.id=aga.good_id')
            ->leftJoin('app_good_rel agr', 'aga.good_id=agr.app_good_id')
            ->field('ag.id,ag.shop_name,ag.pic,ag.shop_money,ag.fake_sall,agr.origin_price,ag.sall,ag.total,aga.activity_type,aga.start_time,aga.end_time')
            ->where($where)
            ->order('ag.sall', 'desc')
            ->limit(15)
            ->select();
        foreach ($list as $key => &$val){
            $spec = Db::name('app_good_rel_spec')->where(['app_good_id' => $val['id']])->select();
            foreach ($spec as $k => &$v){
                if($v['activity_price'] == 0){
                    $v['activity_price'] = $v['spec_value'];
                    $v['activity_point'] = $v['point_money'];
                }
            }
            $spec = array_unique_choose($spec, 'app_good_id', 'activity_price', 0);
            $val['shop_money'] = $spec[0]['activity_price'];
            $val['cash_money'] = $spec[0]['activity_point'];
            if (time() < $val['start_time']) {
                $val['buy_title'] = "即将开抢";
                $val['enable_buy'] = false;
                $val['rate'] = 0;
                $val['rate_titile'] = '已抢0%';
            } else {
                $val['buy_title'] = "马上抢";
                $val['enable_buy'] = true;
                $val['rate'] = ($val['sall'] + $val['total'] + $val['fake_sall']) > 0 ? ceil(($val['sall'] + $val['fake_sall']) * 100 / ($val['sall'] + $val['total'] + $val['fake_sall'])) : 100;
                if ($val['rate'] == 100) {
                    $val['rate_titile'] = "已抢光";
                    $val['enable_buy'] = false;
                } elseif ($val['rate'] >= 80) {
                    $val['rate_titile'] = "即将售罄";
                } else {
                    $val['rate_titile'] = "已抢" . $val['rate'] . '%';
                }
                $val['total'] = array_sum(array_column($spec, 'activity_num'));
            }
        };
        return json_success(1, ['list' => $list]);
    }

    public function cateGoodList(){
        try{
            $post=request()->param();
            $field = input('field', '');
            $page = input("pageNum", 1);
            $page_size = 10;
            $offset = max(0, $page-1)*$page_size;
            $order = 'agr.special desc';
            $where[] = ['ag.status', '=', 1];
            if(isset($post['cate_id']) && $post['cate_id']){
                $all_cate_ids = $this->_get_child_cate_ids($post['cate_id']);
                $where[] = $all_cate_ids ? ['ag.cate_id', 'in', $all_cate_ids] : ['ag.cate_id', '=', $post['cate_id']];
            }
            if(isset($post['keyword']) && $post['keyword']) {
                $where[] = ['ag.shop_name|as.supplier_name', 'like', "%{$post['keyword']}%"];
            }
            if($field){
                if($field == 'price'){
                    $order = 'ag.shop_money asc';
                }else{
                    $order = 'ag.'.$field.' desc';
                }
            }

            $list = Db::name('app_good')->alias('ag')->leftJoin('app_good_rel agr', 'ag.id=agr.app_good_id')
                ->leftJoin('app_supplier as','as.app_admin_user_id = agr.app_admin_user_id')
                ->field('ag.id,ag.shop_name,ag.pic,ag.shop_money,ag.weight,ag.unit,ag.sall,ag.total')->where($where)->order($order)->limit($offset, $page_size)->select();
            $count = Db::name('app_good')->alias('ag')->leftJoin('app_good_rel agr', 'ag.id=agr.app_good_id')
                ->leftJoin('app_supplier as','as.app_admin_user_id = ag.uid')
                ->field('ag.id,ag.shop_name,ag.pic,ag.shop_money,ag.weight,ag.unit,ag.sall,ag.total')->where($where)->count();
            $good_ids = array_column($list, 'id');
            $spec = Db::name('app_good_rel_spec')->where('app_good_id', 'in', $good_ids)->orderRaw('spec_value*1 desc')->column('id,spec_value,point_money,app_good_id', 'app_good_id');
            foreach($list as $key => $val){
                //现金值和最低价格
                $list[$key]['cash_money'] = isset($spec[$val['id']]) ? $spec[$val['id']]['point_money'] : '0.00';
                $list[$key]['spec_value'] = isset($spec[$val['id']]) ? $spec[$val['id']]['spec_value'] : $val['shop_money'];
            }
            
            $data['list'] = $list;
            $data['listSize'] = $count;
            $data['listView'] = $page;
            $data['listPage'] = ceil($count/$page_size);
            return json_success('1',$data);
        }catch (\Exception $e){
            return json_error($e->getMessage() . $e->getCode() . $e->getFile() . $e->getLine(), 201);
        }
    }

    /**
     * 商城二级分类首页类目
     */
    public function cateChildsList() {
        $cate_id = request()->param('cate_id', 0);
        $data = Db::name('app_good_cate')->field('id,title')->where(['pid' => $cate_id, 'status' => 1])->order('sort', 'asc')->select();
        if (empty($data)) {
            return json_success('', []);
        }
        array_unshift($data, ['id' => $cate_id, 'title' => "热卖"]);
        return json_success('1', $data);
    }

    /**
     * 商城二级分类首页商品列表
     */
    public function goodByCateList() {
        try {
            $cate_id = request()->param('cate_id', 0);
            $page = request()->param('page', 1);
            $type = request()->param('type', '');
            $sort_by = request()->param('sort_by', 'sall');
            $sort_type = request()->param('sort_type', 'desc');
            $keyword = request()->param('keyword');
            $page_size = 9;
            $offset = max(0, $page - 1) * $page_size;
            $cate_id > 0 || exception('类目id不正确');
            if ($type == 'hot') {
                //热卖
                $data = Db::name('app_good_cate')->field('id,title,en_title as english,image,banner_imgb,banner_imgs')->where(['pid' => $cate_id, 'status' => 1])->order('sort', 'asc')->select();
            } else {
                //普通二级类目
                $data = Db::name('app_good_cate')->field('id,title,en_title as english,image,banner_imgb,banner_imgs')->where(['id' => $cate_id, 'status' => 1])->select();
            }
            if (empty($data)) {
                return json_success('', []);
            }
            foreach ($data as $k => &$v) {
                $where[] = ['ag.status', '=', 1];
                $where[] = ['ag.cate_id', '=', $v['id']];
                $where[] = ['ag.can_upgrade_vip', '=', 0];
                if (!empty($keyword)) $where[] = ['ag.shop_name', 'like', "%{$keyword}%"];
                $list = Db::name('app_good')->alias('ag')->leftJoin('app_good_rel agr', 'ag.id=agr.app_good_id')
                    ->field('ag.id,ag.shop_name,ag.pic,ag.shop_money,ag.weight,ag.unit,ag.sall,ag.total,agr.origin_price')
                    ->where($where)->order('ag.' . $sort_by, $sort_type)->limit($offset, $page_size)->select();
                $good_ids = array_column($list, 'id');
                $spec = Db::name('app_good_rel_spec')->where('app_good_id', 'in', $good_ids)->column('id,spec_value,point_money,app_good_id', 'app_good_id');
                foreach ($list as $key => $val) {
                    //总库存
                    //$list[$key]['total'] = (new GoodService())->getGoodSpecNumById($val['id']);
                    //现金值和最低价格
                    $list[$key]['cash_money'] = isset($spec[$val['id']]) ? $spec[$val['id']]['point_money'] : '0.00';
                    $list[$key]['spec_value'] = isset($spec[$val['id']]) ? $spec[$val['id']]['spec_value'] : $val['shop_money'];
                }
                $v['goods'] = $list;
                if ($page > 1) {
                    $v['banner_imgs'] = '';
                }
                unset($where);
            }
            //返回数据组装

            if ($type == 'hot') {
                return json_success('1', ['banner' => array_filter(array_column($data, 'banner_imgb')), 'list' => $data]);
            } else {
                $where[] = ['ag.status', '=', 1];
                $where[] = ['ag.cate_id', '=', $data[0]['id']];
                $num = Db::name('app_good')->alias('ag')->leftJoin('app_good_rel agr', 'ag.id=agr.app_good_id')
                    ->where($where)->count();
                return json_success('1', ['listSize'=>$page_size,'listView'=>$page,'listPage'=> ceil($num/$page_size),'banner'=>array_filter(array_column($data, 'banner_imgb')),'list'=>$data]);
            }

        } catch (\Exception $e) {
            return json_error($e->getMessage() . $e->getCode() . $e->getFile() . $e->getLine(), 201);
        }
    }

    /**
     * 商品详情页面
     */
    public function goodDetail()
    {
        try{
            $id=request()->param('id',0);

            if(empty($id))return json_error('error');
            $app_good_data = Db::name('app_good')->alias('ag')
            ->leftJoin('app_good_rel agr', 'ag.id=agr.app_good_id')
            ->leftJoin('app_good_activity aga', 'ag.id=aga.good_id')
            ->leftJoin('app_supplier asup', 'agr.app_admin_user_id=asup.app_admin_user_id')
            ->field('ag.*,asup.supplier_name,agr.app_admin_user_id,agr.pur_price,agr.origin_price,
            aga.activity_type,aga.start_time,aga.end_time,aga.activity_type,aga.id activity_id,
            agr.quality_period,agr.origin_place,agr.package_list')
            ->where(['ag.id' => $id])->find();

            if ($app_good_data) {
                $app_good_data['spec'] = Db::name('app_good_rel_spec')->field('id as spec_id,app_good_id,spec_name,spec_value,spec_num,point_money as cash_money,activity_price,activity_num,activity_point')->where(['app_good_id' => $app_good_data['id']])->select();
                $app_good_data = $this->_activity_detail($app_good_data);
                foreach ($app_good_data['spec'] as &$val) {
                    $app_good_data['total'] = (new GoodService())->getGoodSpecNumById($val['app_good_id']);
                    if ($app_good_data['activity_type'] == 1) {
                        //秒杀
                        $val = $this->miao_sha_detail_convert($app_good_data, $val);
                    } else {
                        //普通
                        if($app_good_data['total'] >0){
                            $val['enable_buy'] = true;
                        } else {
                            $val['enable_buy'] = false;
                        }
                    }
                    unset($val['activity_price'], $val['activity_point'], $val['activity_num']);
                }
                $app_good_data['gallery'] = explode(',', $app_good_data['gallery']);
                if($app_good_data['good_type'] == 1){
                    $app_good_data['good_tip'][] = '创始会员专属商品';
                    $app_good_data['good_tip'][] = $app_good_data['buy_num'] < 5 ? '一人限购' . $app_good_data['buy_num'] . '次' : '';
                }
                $app_good_data['good_tip'][] = $app_good_data['buy_max'] <= 10 ? '一次限购' . $app_good_data['buy_max'] . '件' : '';
                $app_good_data['good_tip'] = array_filter($app_good_data['good_tip']);
            }
            return json_success('', $app_good_data);
        }catch (\Exception $e){
            return json_error($e->getMessage() . $e->getCode() . $e->getFile() . $e->getLine(), 201);
        }
    }

    /**
     * 秒杀详情最终商品信息
     * @param $app_good_data
     * @param $val
     * @return mixed
     */
    private function miao_sha_detail_convert(&$app_good_data, $val){
        if (isMiaoSha($app_good_data['activity_type'], $app_good_data['start_time'], $app_good_data['end_time'])) {
            $val = $this->miao_sha_convert($val, $app_good_data['total']);
        } elseif($app_good_data['start_time'] < time() && $app_good_data['end_time'] < time()  && $app_good_data['total'] > 0) {
            $val['enable_buy'] = true;
            $app_good_data['activity_type'] = 0;
        } elseif($app_good_data['start_time'] < time() && $app_good_data['end_time'] > time()  && $val['activity_num'] > 0) {
            $val = $this->miao_sha_convert($val, $app_good_data['total']);
        } else {
            $val['enable_buy'] = false;
        }
        return $val;
    }
    /**
     * 商详规格数据组装
     * @param $val         每个规格信息
     * @param int $total   常规商品库存
     * @return mixed
     */
    private function miao_sha_convert($val,$total = 0){
        if($val['activity_price'] > 0){
            if($val['activity_num'] > 0){
                $val['spec_value'] = $val['activity_price'];
                $val['cash_money'] = $val['activity_point'];
                $val['enable_buy'] = true;
            } else {
                $val['spec_value'] = $val['activity_price'];
                $val['cash_money'] = $val['activity_point'];
                $val['enable_buy'] = false;
            }
        } elseif($total > 0) {
            $val['enable_buy'] = true;
        } else {
            $val['enable_buy'] = false;
        }
        return $val;
    }
    /**
     * 商详活动数据组装
     * @param $app_good_data
     * @return mixed
     */
    private function _activity_detail($app_good_data) {
        $spec = array();
        foreach ($app_good_data['spec'] as $k => $v) {
            if ($v['activity_price'] == 0) {
                $v['activity_price'] = $v['spec_value'];
                $v['activity_num'] = $v['spec_num'];
                $v['activity_point'] = $v['cash_money'];
            }
            $spec[] = $v;
        }
        $spec_low_price = array_unique_choose($spec, 'app_good_id', 'activity_price');
        if ($app_good_data['activity_type'] == 1) {
            //
            $app_good_data['rate'] = ($app_good_data['sall'] + $app_good_data['total'] + $app_good_data['fake_sall']) > 0 ? ceil(($app_good_data['sall'] + $app_good_data['fake_sall']) * 100 / ($app_good_data['sall'] + $app_good_data['total'] + $app_good_data['fake_sall'])) : 100;
            if (isMiaoSha($app_good_data['activity_type'], $app_good_data['start_time'], $app_good_data['end_time'])) {
                $app_good_data['activity_desc'] = "距结束仅剩";
                $app_good_data['activity_time'] = $app_good_data['end_time'] - time();
            } elseif ($app_good_data['start_time'] > time()) {
                $app_good_data['activity_desc'] = ($app_good_data['start_time'] > day_0_hour(1) ? '明天' : '今天') . date("H:i", $app_good_data['start_time']) . '开抢';
                $app_good_data['activity_time'] = $app_good_data['start_time'] - time();
                $app_good_data['rate'] = 0;
            } elseif($app_good_data['start_time'] < time() && $app_good_data['end_time'] < time()) {
                $app_good_data['activity_desc'] = '已结束';
                $app_good_data['activity_time'] = 0;
            } elseif($app_good_data['start_time'] < time() && $app_good_data['end_time'] > time()) {
                $app_good_data['activity_desc'] = '距结束仅剩';
                $app_good_data['activity_time'] = $app_good_data['end_time'] - time();
            }
            if (!empty($spec_low_price)) $app_good_data['shop_money'] = $spec_low_price[0]['activity_price'];
            $app_good_data['total'] = array_sum(array_column($app_good_data['spec'], 'activity_num'));
        }

        $app_good_data['activity_id'] = empty($app_good_data['activity_id']) ? 0 : $app_good_data['activity_id'];
        $app_good_data['activity_type'] = empty($app_good_data['activity_type']) ? 0 : $app_good_data['activity_type'];
        return $app_good_data;
    }
    //获取一级分类
    private function _get_parent_cate_data()
    {
        $key = '_good_parent_cate_ids_';
        $data = Cache::get($key);
        if($data)return $data;
        
        $parent_cate = Db::name('app_good_cate')->cache($key, (60*60*24))->where(['pid' => 0, 'status' => 1])->column('id,title');
        return $parent_cate;
    }
    /**
     * 获取供应商商品一级分类(只获取包含商品的分类)
     */
    public function supplierCate()
    {
        $supplier_id = input('supplier_id', 0);
        $key = '_supplier_parent_cate_'.$supplier_id.'_';
        
        $data = Cache::get($key);
        if($data)return json_success('success', $data);
        
        $all_cate = Db::name('app_good_rel')->alias('agr')->leftJoin('app_good ag', 'agr.app_good_id = ag.id')
        ->field('agc.id as cate_id,agc.title,agc.pid,agc.sort')
        ->leftJoin('app_good_cate agc', 'ag.cate_id=agc.id')
        ->where(['agr.app_admin_user_id' => $supplier_id, 'ag.status' => 1])->distinct('agc.id')->select();
        $p_cate = $this->_get_parent_cate_data();
        
        $cate = [];
        foreach($all_cate as $val){
            if($val['pid'] == 0){
                $cate[$val['cate_id']] = ['id' => $val['cate_id'], 'title' => $val['title'], 'sort' => $val['sort']];
            }else{
                if(isset($p_cate[$val['pid']])){
                    $cate[$val['pid']] = ['id' => $val['pid'], 'title' => $p_cate[$val['pid']], 'sort' => $val['sort']];
                }
            }
            
        }
        array_multisort(array_column($cate, 'sort'), SORT_ASC, $cate);
        Cache::set($key, $cate, (60*60*24));
        return json_success('success', $cate);
    }
    //店铺(供应商)页面商品数据
    public function supplier()
    {
        $supplier_id = input('supplier_id', 0);
        $page_size = 10;
        $page = input('page', 1);
        $id = input('id', 0);
        $field = input('field', '');
        $offset = max(0, $page-1)*$page_size;
        if($field){
            if($field == 'price')$order_by['ag.shop_money'] = 'asc';
            if($field == 'shop_money')$order_by['ag.shop_money'] = 'desc';
            if($field == 'sall')$order_by['ag.sall'] = 'desc';
        }
        $order_by['ag.id'] = 'desc';
        $where = [['ag.status', '=', 1], ['agr.app_admin_user_id', '=', $supplier_id]];
        if($id){
            $child_cate_ids = $this->_get_child_cate_ids($id);
            $where[] = $child_cate_ids ? ['ag.cate_id', 'in', $child_cate_ids] : ['ag.cate_id', '=', $id];
        }
        $handle = Db::name('app_good')->alias('ag')->leftJoin('app_good_rel agr', 'ag.id=agr.app_good_id')
        ->field('ag.id,ag.shop_name,ag.pic,ag.shop_money,ag.weight,ag.unit,ag.cate_id,ag.sall,agr.origin_price')
        ->where($where);
        
        $count = $handle->count();
        $page_count = ceil($count/$page_size);
        $page_start = ($page_count >= $page) ? max(0, $page-1)*$page_size : 0;
        $list = $handle->order($order_by)->limit($page_start, $page_size)->all();
        
        $good_ids = array_column($list, 'id');
        $spec = Db::name('app_good_rel_spec')->where('app_good_id', 'in', $good_ids)->orderRaw('spec_value*1 desc')->column('id,spec_value,point_money,app_good_id', 'app_good_id');
        foreach($list as $key => $val){
            //现金值和最低价格
            $list[$key]['cash_money'] = isset($spec[$val['id']]) ? $spec[$val['id']]['point_money'] : '0.00';
            $list[$key]['spec_value'] = isset($spec[$val['id']]) ? $spec[$val['id']]['spec_value'] : $val['shop_money'];
            $list[$key]['spec_id'] = isset($spec[$val['id']]) ? $spec[$val['id']]['id'] : 0;
        }
        
        $data['list'] = $list;
        $data['listSize'] = $count;
        $data['listView'] = $page;
        $data['listPage'] = $page_count;
        return json_success('success', $data);
    }
    
    /**
     * 获取购买能升级会员的商品
     * @return json
     */
    public function vipGoods()
    {
//         if(!request()->isPost())return json_error('提交方式有误');
        $page = input('page', 1);
        $page_size = 10;
        
        $count = Db::name('app_good')->where(['can_upgrade_vip' => 1, 'status' => 1])->count();
        $page_count = ceil($count/$page_size);
        $page_start = ($page_count >= $page) ? max(0, $page-1)*$page_size : 0;
        $list = Db::name('app_good')->alias('ag')
        ->leftJoin('app_good_rel agr', 'ag.id = agr.app_good_id')
        ->field('ag.id,ag.shop_name,ag.shop_money,ag.pic,ag.sall,ag.weight,ag.unit,ag.cate_id,ag.sall,agr.origin_price')
        ->where(['ag.can_upgrade_vip' => 1, 'ag.status' => 1])->limit($page_start, $page_size)->all();
        
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
        
        $data['list'] = $list;
        $data['listSize'] = $count;
        $data['listView'] = $page;
        $data['listPage'] = $page_count;
        return json_success('success', $data);
    }
}
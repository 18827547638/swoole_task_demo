<?php

use young\StringCrypt;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use think\Db;

function json_success($message = '', $data = [], $code = 0) {
    return json(['error' => 0, 'message' => $message, 'code' => $code, 'data' => $data]);
}

function json_error($message = '', $code = 0,$log="")
{
    if($log){
        @file_put_contents("error.txt", date('Y-m-d H:i:s').'--'.$log.PHP_EOL,FILE_APPEND);
    }
    return json(['error' => 1, 'message' => $message, 'code' => $code]);
}

function addToken($uid){
    $token = md5($uid . time());
    $log = [
        'uid' => $uid,
        'token' => $token,
        'type' => 'mobile',
        'create_time' => time(),
        'device_id' => input('deviceId'),
    ];
    db("user_log")->insert($log);
    return $token;
}
function create_qrcode($url,$uid,$set_log=true, $number = ''){
    $qrCode = new QrCode($url);
    $qrCode->setSize(260);
    if($set_log ==true){
        $qrCode->setLogoPath('static/logo.png');
        $qrCode->setLogoWidth(80);
    }
    $qrCode->setErrorCorrectionLevel(ErrorCorrectionLevel::HIGH);

    $path ='qrcode/qrcode_'.$uid.'.png';
//     if(!file_exists($path)){
        $qrCode->writeFile($path);
        $image_1 = imagecreatefrompng('static/new_background.png');
        $image_2 = imagecreatefrompng($path);
        $image_3 = imageCreatetruecolor(imagesx($image_1),imagesy($image_1));
        imagecopyresampled($image_3,$image_1,0,0,0,0,imagesx($image_1),imagesy($image_1),imagesx($image_1),imagesy($image_1));
        imagecopymerge($image_3,$image_2, 183,260,0,0,imagesx($image_2),imagesy($image_2), 100);
        $font_path = dirname(__DIR__).'/public/PINGFANG MEDIUM.TTF';
        $str = '注册邀请码：'.$number;
        imagefttext($image_3, 20, 0, 190, 630, imagecolorallocate($image_3, 255, 255, 255), $font_path, $str);
        imagepng($image_3,$path);
        
//     }

    return  $path;
}


/**
 * 资金记录
 */
function coin_log($uid, $bid, $type, $num, $old, $new, $remark = '', $frogen = 2) {
    $data = [
        'uid' => $uid,
        'coin_id' => $bid,
        'type' => $type,
        'num' => $num,
        'old_money' => $old,
        'now_money' => $new,
        'remark' => $remark,
        'create_time' => time(),
        'frogen' => $frogen
    ];
    Db::name("user_coin_log")->insert($data);
    return $data;
}




function buildFailed($code, $msg, $data = []) {
    $return = [
        'code' => $code,
        'msg'  => $msg,
        'data' => $data
    ];


    return json($return);
}
function rand_string($length = 5)
{
    $array = array_merge(range('a', 'z'), range('A', 'Z'), range(0, 9));
    shuffle($array);
    $value = '';
    for ($i = 0; $i < $length; $i++) {
        $value .= $array[array_rand($array, 1)];
    }
    return $value;
}

function string_encode($text, $key = '123456')
{
    $text = str_replace(['/', '.', '#', ':', '&', '?', '+'], ['[a]', '[b]', '[c]', '[d]', '[e]', '[f]', '[g]'], $text);
    return urlencode(StringCrypt::encrypt($text, $key));
}

function string_decode($text, $key = '123456')
{
    $text = StringCrypt::decrypt($text, $key);
    return (string)str_replace(['[a]', '[b]', '[c]', '[d]', '[e]', '[f]', '[g]'], ['/', '.', '#', ':', '&', '?', '+'], $text);
}
// 应用公共文件
/**
 * 把返回的数据集转换成Tree
 * @param $list
 * @param string $pk
 * @param string $pid
 * @param string $child
 * @param string $root
 * @return array
 */
function listToTree($list, $pk='id', $pid = 'fid', $child = '_child', $root = '0') {
    $tree = array();
    if(is_array($list)) {
        $refer = array();
        foreach ($list as $key => $data) {
            $refer[$data[$pk]] = &$list[$key];
        }
        foreach ($list as $key => $data) {
            $parentId =  $data[$pid];
            if ($root == $parentId) {
                $tree[] = &$list[$key];
            }else{
                if (isset($refer[$parentId])) {
                    $parent = &$refer[$parentId];
                    $parent[$child][] = &$list[$key];
                }
            }
        }
    }
    return $tree;
}

function formatTree($list, $lv = 0, $title = 'name'){
    $formatTree = array();
    foreach($list as $key => $val){
        $title_prefix = '';
        for( $i=0;$i<$lv;$i++ ){
            $title_prefix .= "|---";
        }
        $val['lv'] = $lv;
        $val['namePrefix'] = $lv == 0 ? '' : $title_prefix;
        $val['showName'] = $lv == 0 ? $val[$title] : $title_prefix.$val[$title];
        if(!array_key_exists('_child', $val)){
            array_push($formatTree, $val);
        }else{
            $child = $val['_child'];
            unset($val['_child']);
            array_push($formatTree, $val);
            $middle = formatTree($child, $lv+1, $title); //进行下一层递归
            $formatTree = array_merge($formatTree, $middle);
        }
    }
    return $formatTree;
}
function getLocation($lat,$lng){

    $ak=config("bmap_ak");
    $url="http://api.map.baidu.com/geocoder/v2/?&location={$lat},{$lng}&output=json&pois=1&ak={$ak}";
    $result=httpGet($url);
    $result=json_decode($result,true);
    if(empty($result))return ['province' => 0, 'city' => 0, 'district' => 0];
    $result=$result['result'];
    return [
        'province'=>$result['addressComponent']['province'],
        'city'=>$result['addressComponent']['city'],
        'district'=>$result['addressComponent']['district'],
    ];

}
function GetRange($lat,$lon,$raidus){
    //计算纬度
    $degree = (24901 * 1609) / 360.0;
    $dpmLat = 1 / $degree;
    $radiusLat = $dpmLat * $raidus;
    $minLat = $lat - $radiusLat; //得到最小纬度
    $maxLat = $lat + $radiusLat; //得到最大纬度
    //计算经度
    $mpdLng = $degree * cos($lat * (pi() / 180));
    $dpmLng = 1 / $mpdLng;
    $radiusLng = $dpmLng * $raidus;
    $minLng = $lon - $radiusLng; //得到最小经度
    $maxLng = $lon + $radiusLng; //得到最大经度
    //范围
    $range = array(
        'minLat' => $minLat,
        'maxLat' => $maxLat,
        'minLon' => $minLng,
        'maxLon' => $maxLng
    );
    return $range;
}

function httpGet($url) {
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_TIMEOUT, 500);
    // 为保证第三方服务器与微信服务器之间数据传输的安全性，所有微信接口采用https方式调用，必须使用下面2行代码打开ssl安全校验。
    // 如果在部署过程中代码在此处验证失败，请到 http://curl.haxx.se/ca/cacert.pem 下载新的证书判别文件。
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($curl, CURLOPT_URL, $url);

    $res = curl_exec($curl);
    curl_close($curl);

    return $res;
}

function sms($mobile,$content){
    if(empty($mobile) || empty($content)) return ["success"=>false];
    $content=json_encode($content);
    $url = "http://api.sms.cn/sms/?ac=send&uid=ghl461898996&pwd=6e432388bcc97b079abe07848cb194cb&template=480568&mobile={$mobile}&content={$content}";
    $result = httpGet($url);
    $result = json_decode($result, true);
    if ($result['stat'] == 100) {
        return ["success"=>true];
    }
    return ['success'=>false,'msg'=>$result['message']];
}
/**
 * 生成编码
 */
function create_number($uid) {
    do {
        $upper_count = empty($count) ? 0 : $count;
        $num_digit = strlen($uid);
        $num = [];
        $repeat = [];

        for ($i = $num_digit; $i > 0; $i--) {
            $num[$i] = floor($uid / pow(10, $i));
            $remainder = $uid % pow(10, $i);
            if ($remainder >= (4 * pow(10, $i - 1))) {
                $num[$i] ++;
            }
            $repeat[$i] = 0;
            for ($j = $num_digit; $j > $i; $j--) {
                $repeat[$i] += ($num[$j] - $repeat[$j]) * pow(10, $j - $i - 1);
            }
        }

        $count = 0;
        foreach ($num as $key => $value) {
            $count += ($value - $repeat[$key]) * pow(10, $key - 1);
        }

        $uid += $count - $upper_count;
    } while ($count - $upper_count);

    if (floor($uid / 100000) % 10 == 3) {
        $uid += 200000;
    } else {
        $uid += 100000;
    }

    return $uid;
}
function   get_week($date){
    //强制转换日期格式
    $date_str=date('Y-m-d',strtotime($date));
    //封装成数组
    $arr=explode("-", $date_str);

    //参数赋值
    //年
    $year=$arr[0];

    //月，输出2位整型，不够2位右对齐
    $month=sprintf('%02d',$arr[1]);

    //日，输出2位整型，不够2位右对齐
    $day=sprintf('%02d',$arr[2]);

    //时分秒默认赋值为0；
    $hour = $minute = $second = 0;

    //转换成时间戳
    $strap = mktime($hour,$minute,$second,$month,$day,$year);

    //获取数字型星期几
    $number_wk=date("w",$strap);

    //自定义星期数组
    $weekArr=array("星期日","星期一","星期二","星期三","星期四","星期五","星期六");

    //获取数字对应的星期
    return $weekArr[$number_wk];
}

/**
 * 模型工厂
 * @param $ModelName
 * @return ModelFactory
 */
function M($ModelName) {
    try {
        $Model = new ModelFactory($ModelName);
        return $Model;
    } catch (Exception $ex) {
        @file_put_contents("error.txt",$ex->getMessage().PHP_EOL,FILE_APPEND);
        exit('实例化模型错误');
    }
}

function isBetween($data = 0, $start_range = 0, $end_range = 0) {
    if (is_numeric($data) && is_numeric($start_range) && is_numeric($end_range)) {
        if ($data >= $start_range && $data < $end_range) {
            return true;
        }
    }
    return false;
}

/**
 * @param $arr             比较数组
 * @param string $column   比较维度
 * @param string $compare  比较字段
 * @param int $choose      0=>保留比较字段小的,1=>保留比较字段大的
 * @return array           返回结果
 *
 * $arr = [['id'=>1,'value'=>6],['id'=>1,'value'=>2],['id'=>2,'value'=>1],['id'=>2,'value'=>3],['id'=>1,'value'=>5]]
 * array_unique_choose($arr,'id','value',0)  ===>
 * [['id'=>1,'value'=>2],['id'=>2,'value'=>1]]
 *
 */
function array_unique_choose($arr = [], $column = 'id', $compare = 'id', $choose = 0) {
    if (!is_array($arr)) return [];
    if (count($arr) == 1) return $arr;
    if (count(array_column($arr, $compare)) < 1) return [];
    $res = [];
    foreach ($arr as $k => $v) {
        if (isset($res[$v[$column]])) {
            if ($choose == 0) {
                //取小
                if ($v[$compare] < $res[$v[$column]][$compare]) {
                    $res[$v[$column]] = $v;
                }
            } else {
                //取大
                if ($v[$compare] > $res[$v[$column]][$compare]) {
                    $res[$v[$column]] = $v;
                }
            }
        } else {
            $res[$v[$column]] = $v;
        }
    }
    return array_values($res);
}

function result_to_map($result, $field = 'id') {
    $map = array();
    if (!$result || !is_array($result)) {
        return $map;
    }

    foreach ($result as $entry) {
        if (is_array($entry)) {
            $map[$entry[$field]] = $entry;
        } else {
            $map[$entry->$field] = $entry;
        }
    }
    return $map;
}

/**
 * 是否在秒杀活动时间内
 * @param int $activity_type
 * @param int $start_time
 * @param int $end_time
 * @return bool
 */
function isMiaoSha($activity_type = 0, $start_time = 0, $end_time = 1) {
    if (empty($start_time) || empty($end_time) || $start_time == $end_time) return false;
    if ($activity_type == 1 && isBetween(time(), $start_time, $end_time)) {
        return true;
    }
    return false;
}
/**
 * n天 0点时间戳
 * @return false|int
 */
function day_0_hour($n = 0) {
    if ($n == 0) {
        $day = "now";
    } else {
        $day = $n . ' day';
    }
    if (empty($day)) return 0;
    return strtotime(date('Y-m-d', strtotime($day)));
}

/**
 * 二维数组按字段去重
 * @param $arr
 * @param $key
 * @return array
 */
function array_unique_by_value($arr,$key){
    //建立一个目标数组
    $res = array();
    foreach ($arr as $value) {
        //查看有没有重复项
        if(isset($res[$value[$key]])){
            unset($value[$key]);  //有：销毁
        }else{
            $res[$value[$key]] = $value;
        }
    }
    return $res;
}

/**
 * 补全用户头像链接
 * @param string $avator
 * @return string
 */
function avatorUrl($avator = ''){
    $startWith = substr($avator,0, 7);
    if ( $startWith ==  DIRECTORY_SEPARATOR . 'upload'){
        return config('img_domin') . $avator;
    }
    return $avator;
}



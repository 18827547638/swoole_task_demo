<?php
namespace app\common\service;

use think\facade\Env;
use think\Exception;

class CommonService
{
    //公司手机号码
    const COMPANY_MOBILE = '18627126988';
    //外部url
    const OUT_API_URL = 'https://xcx.100ncy.net/';
//     const OUT_API_URL = 'http://liao-lmsapi.lms.com/';

    const AVATOR_ROUTE = DIRECTORY_SEPARATOR  . 'uploads'.DIRECTORY_SEPARATOR .'avator';
    /**
     * curl请求
     * @param string $url
     * @param array $param
     * @param string $is_post
     * @param array $header
     * @return boolean|string
     */
    public static function openCurl($url, $param = [], $is_post = true, $header = [])
    {
        if(empty($url))return false;
        
        $items = is_array($param) ? http_build_query($param) : $param;
        if(!$is_post)$url = (strpos($url, '?') === false) ? $url.'?'.$items : $url.'&'.$items;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL,$url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        if(!empty($_SERVER['HTTP_USER_AGENT']))curl_setopt($ch, CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT']);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_AUTOREFERER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        if($header)curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        if($is_post){
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $items);
        }
        $data = curl_exec($ch);
        if(curl_errno($ch))return curl_error($ch);
        curl_close($ch);
        
        return $data;
    }
    
    /**
     * 获取星期中文信息
     * @param int $week 下标
     * @param string $pre 前缀
     * @return string
     */
    public static function getWeekStr($week, $pre = '星期')
    {
        $arr = [
            0 => '日',
            1 => '一',
            2 => '二',
            3 => '三',
            4 => '四',
            5 => '五',
            6 => '六'
        ];
        return isset($arr[$week]) ? $pre.$arr[$week] : '';
    }
    
    /**
     * 生成随机的纯数字串
     * @param number $length
     * @return string
     */
    public static function createRandomNumber($length = 12)
    {
        $str = '0123456789';
        $temp = '';
        for($i = 0; $i < $length; $i++){
            $index = mt_rand(0, 9);
            //$temp .= str_shuffle($str){$index};
            $temp .= str_split(str_shuffle($str))[$index];
        }
        return $temp;
    }

    /**
     * 取两经纬坐标之间距离
     * @param number $lng1
     * @param number $lat1
     * @param number $lng2
     * @param number $lat2
     * @param boolean $is_km    true千米，false米
     * @param number $deci      小数点位数
     * @return number
     */
    public static function getDistance($lng1, $lat1, $lng2, $lat2, $is_km = true, $deci = 2)
    {
        $radLat1 = deg2rad($lat1);
        $radLat2 = deg2rad($lat2);
        $radLng1 = deg2rad($lng1);
        $radLng2 = deg2rad($lng2);
        $a = ($radLat1 - $radLat2)/2;
        $b = ($radLng1 - $radLng2)/2;
        $distance = 2 * asin(sqrt(pow(sin($a), 2) + cos($radLat1) * cos($radLat2) * pow(sin($b), 2)))*6371.393;
        $distance = $is_km ? $distance : ($distance*1000);
        return round($distance, $deci);
    }

    /**
     * 初步验证银行卡号
     * @param number $bank_number   银行卡号
     * @return boolean
     */
    public static function verifyBankNumber($bank_number)
    {
        if(empty($bank_number) || !is_numeric($bank_number))return false;
        $arr_no = str_split($bank_number);
        $last_n = $arr_no[count($arr_no)-1];
        krsort($arr_no);
        $i = 1;
        $total = 0;
        foreach ($arr_no as $n){
            if($i%2==0){
                $ix = $n*2;
                if($ix>=10){
                    $nx = 1 + ($ix % 10);
                    $total += $nx;
                }else{
                    $total += $ix;
                }
            }else{
                $total += $n;
            }
            $i++;
        }
        $total -= $last_n;
        $total *= 9;
        return ($last_n == ($total%10));
    }

    /**
     * xml 转换 array
     * @param string $xml
     */
    public static function xmlToArray($xml)
    {
        if(empty($xml))return [];
        libxml_disable_entity_loader(true);
        return json_decode(json_encode(simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA)), true);
    }

    /**
     * array 转换 xml
     * @param array $arr
     * @return string
     */
    public static function arrayToXml($arr)
    {
        $xml = "<xml>";
        foreach ($arr as $key => $val){
            if (is_numeric($val)){
                $xml.="<".$key.">".$val."</".$key.">";
            }else{
                $xml.="<".$key."><![CDATA[".$val."]]></".$key.">";
            }
        }
        $xml.="</xml>";
        return $xml;
    }
    
    /**
     * 限制用户操作频率
     * @param string $cahce_key
     * @param number $limit_time
     * @throws Exception
     */
    public static function limitOperateFrequency($cahce_key, $limit_time = 5)
    {
        if(cache($cahce_key))throw new Exception('操作过于频繁....');
        cache($cahce_key, true, 5);
    }

    /**
     * 生成用户编号
     */
    public function unicode($uid) {
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
    /**
     * 微信头像下载到本地文件下载
     * @param  [type] $url  [下载链接包含协议]
     * @param  [type] $absolute_path [本地绝对路径包含扩展名]
     * @return [type]       [description]
     */
    public function download($url, $absolute_path = '')
    {
        $file_name = Env::get('root_path') . 'public' . self::AVATOR_ROUTE . DIRECTORY_SEPARATOR . date('Ymd');
        if (!is_dir($file_name)) {
            mkdir($file_name, 0777, true);
            chmod($file_name, 0755);
        }
        $route = $file_name . DIRECTORY_SEPARATOR . $absolute_path;
        if(file_exists($route)) return false;
        $file = httpGet($url);
        $resource = fopen($route, 'a');
        fwrite($resource, $file);
        fclose($resource);
        return self::AVATOR_ROUTE . DIRECTORY_SEPARATOR . date('Ymd') . DIRECTORY_SEPARATOR . $absolute_path;
    }
}
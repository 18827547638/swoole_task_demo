<?php
namespace app\common\service;

use think\Db;

/**
 * 省/市/区
 *
 */
class SystemAreaService extends CommonService
{
    //根据name获取id缓存key
    const SYSTEM_AREA_ID_BY_NAME_CACHE_KEY = '_system_area_id_by_name_cache_key_';
    //根据id获取name缓存key
    const SYSTEM_AREA_NAME_BY_ID_CACHE_KEY = '_system_area_name_by_id_cache_key_';
    //根据市名称获取区数据缓存key
    const SYSTEM_AREA_BY_CITY_NAME = '_system_area_by_city_name_';
    
    /**
     * 根据城市名称获取对应的id
     * @param string $name  城市名称
     * @return number
     */
    public static function getAreaIdByName($name)
    {
        $cacke_key = md5(self::SYSTEM_AREA_ID_BY_NAME_CACHE_KEY.$name);
        if(($cacke_value = cache($cacke_key)))return $cacke_value;
        
        $id = Db::name('system_area')->where(['name' => $name])->value('id');
        $id = $id ? $id : -1;
        cache($cacke_key, $id, 60*60*24*30);
        return $id;
    }
    
    /**
     * 删除城市名称对应城市id缓存信息
     * @param string $name  城市名称
     * @return boolean
     */
    public static function deleteAreaIdCache($name)
    {
        $cacke_key = md5(self::SYSTEM_AREA_ID_BY_NAME_CACHE_KEY.$name);
        if(cache($cacke_key))cache($cacke_key, null);
        return true;
    }
    
    /**
     * 根据城市id获取对应的城市名称
     * @param int $id   城市id
     * @return string
     */
    public static function getAreaNameById($id)
    {
        $cache_key = self::SYSTEM_AREA_NAME_BY_ID_CACHE_KEY.$id;
        if(($cache_value = cache($cache_key)))return $cache_value;
        
        $name = Db::name('system_area')->where(['id' => $id])->value('name');
        $name = $name ? $name : '无';
        cache($cache_key, $name, 60*60*24*30);
        return $name;
    }
    
    /**
     * 删除城市id对应城市名称缓存信息
     * @param int $id   城市id
     * @return boolean
     */
    public static function deleteAreaNameCache($id)
    {
        $cache_key = self::SYSTEM_AREA_NAME_BY_ID_CACHE_KEY.$id;
        if(cache($cache_key))return cache($cache_key, null);
        return true;
    }
    
    /**
     * 根据市名称获取区名称
     * @param string $city_name 市名称
     * @return array
     */
    public static function getAllAreaNameByCityName($city_name)
    {
        $arr = [];
        if(empty($city_name))return $arr;
        $cache_key = md5(self::SYSTEM_AREA_BY_CITY_NAME.$city_name);
        if(($cache_value = cache($cache_key)))return $cache_value;
        
        $city_id = Db::name('system_area')->where(['name' => $city_name])->value('id');
        if(empty($city_id))return $arr;
        $arr = Db::name('system_area')->where(['pid' => $city_id])->column('name');
        cache($cache_key, $arr, 60*60*24*30);
        return $arr;
    }
    
    /**
     * 删除区名称数据缓存
     * @param string $city_name
     * @return boolean
     */
    public static function deleteAllAreaNameCache($city_name)
    {
        $cache_key = md5(self::SYSTEM_AREA_BY_CITY_NAME.$city_name);
        if(cache($cache_key))cache($cache_key, null);
        return true;
    }

    public function test(){
               file_put_contents('zg_test.txt',time());
    }
}

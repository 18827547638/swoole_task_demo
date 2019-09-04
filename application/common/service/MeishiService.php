<?php
namespace app\common\service;

use think\Db;

class MeishiService extends CommonService
{
    /**
     * 美食首页数据
     * @return array[]
     */
    public function homePageData()
    {
        //美食banner图
        $banner = $this->_get_meishi_banner();
        return [
            'banner' => $banner,
        ];
    }
    
    /**
     * 美食banner
     * type = 1
     * status = 1
     * @return array
     */
    private function _get_meishi_banner()
    {
        $cache_key = '_frontend_meishi_banner_';
        if(($cache_value = cache($cache_key)))return $cache_value;
        $banner = Db::name('app_slider')->alias('asli')->field('asli.image,asli.url as store_id,asto.main_img')
        ->leftJoin('app_store asto', 'asli.url=asto.id')
        ->where(['asli.type' => 1, 'asli.status' => 1])->order(['sort' => 'asc'])->all();
        cache($cache_key, $banner, 60*30);
        return $banner;
    }
}
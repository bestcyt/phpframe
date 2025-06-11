<?php
namespace Fw;

/**
 * 大数据行为日志记录
 *
 * http://cf.meitu.com/confluence/pages/viewpage.action?pageId=15011225
 * http://cf.meitu.com/confluence/pages/viewpage.action?pageId=15009539
 */
class BehaviorLogger
{
    use InstanceTrait;

    protected $filterParamKeys = [];
    protected $commonParamKeysMap = [
        'os_type' => 'os_type',
        'os_version' => 'client_os',
        'app_version' => 'version',
        'idfa' => 'idfa',
        'idfv' => 'idfv',
        'android_id' => 'android_id',
        'imei' => 'imei',
        'iccid' => 'iccid',
        'model' => 'client_model',
        'channel' => 'client_channel',
        'carrier' => 'client_carrier',
        'network' => 'client_network',
        'language' => 'client_language',
        'resolution' => 'resolution',
        'mac_addr' => 'mac',
        'country' => 'client_country',
        'city' => 'client_city',
        'timezone' => 'client_timezone',
        'longitude' => 'client_lng',
        'latitude' => 'client_lat',
        'gid' => 'client_gid',
        'gid_status' => 'client_gid_status',
        'ab_codes' => 'client_ab_codes',
    ];

    public function write($data)
    {
        $data = $this->getData($data);

        $path = app_env('app.behavior_log_path');
        if (!$path) {
            $path = app_root_path() . '/logs/statistics';
        } else {
            $path = rtrim($path, '/\\');
        }
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
        $filename = $this->getLogFilename();
        $content = json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . "\n";
        $filename = $path . '/' . $filename;
        return error_log($content, 3, $filename);
    }

    public function setFilterParamKeys(array $paramKeys)
    {
        if ($paramKeys) {
            $this->filterParamKeys = $paramKeys;
        }
    }

    public function addFilterParamKey($paramKey)
    {
        $this->filterParamKeys[] = $paramKey;
    }

    public function setCommonParamKeys(array $keysMap)
    {
        $this->commonParamKeysMap = $keysMap;
    }

    public function addCommonParamKey($behaviorKey, $paramKey)
    {
        $this->commonParamKeysMap[$behaviorKey] = $paramKey;
    }

    private function getCommonParam($behaviorKey)
    {
        $paramKey = isset($this->commonParamKeysMap[$behaviorKey]) ? $this->commonParamKeysMap[$behaviorKey] : $behaviorKey;
        return $paramKey ? Request::getInstance()->input($paramKey) : '';
    }

    protected function getLogFilename()
    {
        $time = app_time();
        $dateHour = date('YmdH', $time);
        return 'bigdata-' . $dateHour. '.log';
    }

    protected function getCommonData()
    {
        $request = Request::getInstance();
        $data = [
            //必填
            'time' => intval(app_microtime() * 1000), //类型：long，服务端接收请求的系统timestamp，精确到毫秒
            'request_ip' => $request->getClientIp(), //类型：string，客户端请求公网ip
            'os_type' => (string)$this->getCommonParam('os_type'), //类型：string，客户端操作系统类别，参考值（iOS、android，其他客户端平台放空）
            'os_version' => (string)$this->getCommonParam('os_version'), //类型：string，客户端操作系统版本
            'app_version' => (string)$this->getCommonParam('app_version'), //类型：string，客户端app版本号
            'idfa' => (string)$this->getCommonParam('idfa'), //类型：string，iOS设备的广告标识码
            'idfv' => (string)$this->getCommonParam('idfv'), //类型：string，iOS设备的Vector标识码
            'android_id' => (string)$this->getCommonParam('android_id'), //类型：string，android设备的唯一标识
            'imei' => (string)$this->getCommonParam('imei'), //类型：string，android设备的标识码
            'iccid' => (string)$this->getCommonParam('iccid'), //类型：string，SIM卡卡号
            'model' => (string)$this->getCommonParam('model'), //类型：string，设备机型，参考值（iPhone9,1 或者 OPPO R9 Plusm A）
            'channel' => (string)$this->getCommonParam('channel'), //类型：string，渠道标识
            'carrier' => (string)$this->getCommonParam('carrier'), //类型：string，运营商，参考值（中国移动）
            'network' => (string)$this->getCommonParam('network'), //类型：string，网络类别，参考值（2G、3G、WiFi）
            'language' => (string)$this->getCommonParam('language'), //类型：string，系统语言，参考值（zh-Hans）
            'local_ip' => $request->getServerIp(), //类型：string，服务端本地ip
        ];

        //////非必填//////
        //类型：string，分辨率，参考值（1920x1080）
        $resolution = $this->getCommonParam('resolution');
        if ($resolution) {
            $data['resolution'] = (string)$resolution;
        }
        //类型：string，mac地址
        $macAddr = $this->getCommonParam('mac_addr');
        if ($macAddr) {
            $data['mac_addr'] = (string)$macAddr;
        }
        //类型：string，客户端系统设置的国家
        $country = $this->getCommonParam('country');
        if ($country) {
            $data['country'] = (string)$country;
            //类型：string，客户端系统设置的城市
            $city = $this->getCommonParam('city');
            if ($city) {
                $data['city'] = (string)$city;
            }
        }
        //类型：string，时区，参考值（GMT+8）
        $timezone = $this->getCommonParam('timezone');
        if ($timezone) {
            $data['timezone'] = (string)$timezone;
        }
        //类型：double，经度
        $longitude = $this->getCommonParam('longitude');
        if ($longitude) {
            $data['longitude'] = (float)$longitude;
            //类型：double，纬度
            $latitude = $this->getCommonParam('latitude');
            if ($latitude) {
                $data['latitude'] = (float)$latitude;
            }
        }
        //类型：string，美图统一设备ID（由app客户端开发调用美图统计SDK接口获取）
        $gid = $this->getCommonParam('gid');
        if (strlen($gid) > 0) {
            $data['gid'] = (string)$gid;
        }
        //类型：string，gid设备ID的状态（由app客户端开发调用美图统计SDK接口获取）
        $gidStatus = $this->getCommonParam('gid_status');
        if (strlen($gidStatus) > 0) {
            $data['gid_status'] = (string)$gidStatus;
        }
        //类型：array[int]，美图abtest code列表（由app客户端开发调用美图abtesting SDK接口获取）
        $abCodes = $this->getCommonParam('ab_codes');
        if ($abCodes && is_array($abCodes)) {
            foreach ($abCodes as $abCode) {
                $data['ab_codes'][] = (int)$abCode;
            }
        }

        return $data;
    }

    protected function getCommonBizData()
    {
        $data = array_diff_key($_REQUEST, array_fill_keys($this->commonParamKeysMap, ''), array_fill_keys($this->filterParamKeys, ''));
        $result = [];
        if ($data) {
            foreach ($data as $key => $value)
            {
                $result['r_' . $key] = $value;
            }
        }

        $request = Request::getInstance();
        $controller = '';
        $appName = App::getInstance()->getAppName();
        if ($appName) {
            $controller .= $appName . '-';
        }
        $module = $request->getModule();
        if ($module) {
            $controller .= $module . '-';
        }
        $controller .= $request->getController();
        $action = $request->getAction();
        $result['controller'] = $controller;
        $result['action'] = $action;

        return $result;
    }

    public function getData($statisticsData = [])
    {
        if (!is_array($statisticsData)) {
            $statisticsData = [];
        }
        return array_merge($this->getCommonData(), $this->getCommonBizData(), $statisticsData);
    }

}
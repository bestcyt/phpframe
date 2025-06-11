<?php
namespace Fw\Cache;

use Fw\InstanceTrait;

class ApcuCache implements CacheInterface
{
    use InstanceTrait {
        getInstance as _getInstance;
    }

    /**
     * @param array $config
     * @param array $options
     * @return self
     */
    public static function getInstance(array $config = [], array $options = [])
    {
        return self::_getInstance($config, $options);
    }

    public function get($key)
    {
        return \apcu_fetch($key);
    }

    public function set($key, $value, $ttl = 0)
    {
        return \apcu_store($key, $value, $ttl);
    }

    public function delete($key)
    {
        return \apcu_delete($key);
    }

    public function getMulti(array $keys, array $keyMaps = [], &$idsByMiss = [])
    {
        $result = \apcu_fetch($keys);
        if (!$result) {
            $result = [];
        }
        $idsByMiss = array_values(array_diff_key($keyMaps, $result));
        return $result;
    }

    public function setMulti(array $items, $ttl = 0)
    {
        //保存成功返回空数组
        $result = \apcu_store($items, null, $ttl);
        return empty($result) ? true : false;
    }

    public function deleteMulti(array $keys)
    {
        return \apcu_delete($keys);
    }


}
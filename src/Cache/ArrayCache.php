<?php
namespace Fw\Cache;
use Fw\InstanceTrait;

/**
 * 注:基于性能和实现方便的考虑,缓存item个数超过预设的maxItemCount时,直接不缓存新的元素(而非剔除最早的元素)
 */
class ArrayCache implements CacheInterface
{
    use InstanceTrait {
        getInstance as _getInstance;
    }

    private static $data = [];
    private static $itemCount = 0;
    private $maxItemCount = 1000;

    private function __construct(array $config)
    {
        if (!empty($config['max_item_count']) && $config['max_item_count'] > 0) {
            $this->maxItemCount = intval($config['max_item_count']);
        }
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
        return isset(self::$data[$key]) ? self::$data[$key] : false;
    }

    public function set($key, $value, $ttl = 0)
    {
        //缓存item个数已满时,之前不在缓存池的新item直接忽略不缓存,而之前有在缓存池中的item则更新其缓存值
        if (self::$itemCount < $this->maxItemCount) {
            self::$data[$key] = $value;
            self::$itemCount++;
        } elseif (isset(self::$data[$key])) {
            self::$data[$key] = $value;
        } else {
            return false;
        }
        return true;
    }

    public function delete($key)
    {
        if (isset(self::$data[$key])) {
            unset(self::$data[$key]);
            self::$itemCount--;
            return true;
        } else {
            return false;
        }
    }

    public function getMulti(array $keys, array $keyMaps = [], &$idsByMiss = [])
    {
        $result = [];
        foreach ($keys as $key) {
            if (isset(self::$data[$key])) {
                $result[$key] = self::$data[$key];
            }
        }
        $idsByMiss = array_values(array_diff_key($keyMaps, $result));
        return $result;
    }

    public function setMulti(array $items, $ttl = 0)
    {
        foreach ($items as $key => $value) {
            $this->set($key, $value, $ttl);
        }
        return true;
    }

    public function deleteMulti(array $keys)
    {
        $result = true;
        foreach ($keys as $key) {
            if (!$this->delete($key)) {
                $result = false;
            }
        }
        return $result;
    }


}
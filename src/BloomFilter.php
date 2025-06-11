<?php

namespace Fw;

/**
 * 使用redis实现的布隆过滤器
 * https://blog.csdn.net/u010412301/article/details/89320513
 */
class BloomFilter
{
    use InstanceTrait {
        getInstance as _getInstance;
    }
    /**
     * redis的缓存key
     * @var $bucket string
     */
    protected $bucket = "";

    /**
     * 布隆过滤器hash算法(算法名数组)
     * @var $hashFunction array
     */
    protected $hashFunction = array();

    /**
     * redis配置
     * @var array $server_config
     */
    protected $server_config = array();

    /**
     * 单key最多容纳多少数据,防止单key太大
     * @var int $shard_split
     */
    protected $shard_split = 10000000;//单key最多容纳多少数据

    /**
     * @var $Hash BloomFilterHash
     */
    protected $Hash = null;//布隆过滤器hash实例
    /**
     * @var $Redis Redis
     */
    protected $Redis = null;//redis连接

    protected $initialized = false;

    /**
     * BloomFilter constructor.
     * @param array $redis_config redis配置
     * @param array $hashFunction 布隆过滤器hash算法(算法名数组)
     * @param string $bucket redis的缓存key
     * @param int $shard_split 单key最多容纳多少数据,防止单key太大
     */
    protected function __construct(array $redis_config, array $hashFunction, $bucket, $shard_split = 10000000)
    {
        $this->server_config = $redis_config;
        $this->hashFunction = $hashFunction;
        $this->bucket = $bucket;
        $this->shard_split = $shard_split;
        $this->_initServer();
    }

    /**
     * @param array $redis_config redis配置
     * @param array $hashFunction 布隆过滤器hash算法(算法名数组)
     * @param string $bucket redis的缓存key
     * @param int $shard_split 单key最多容纳多少数据,防止单key太大
     * @return static
     */
    public static function getInstance(array $redis_config, array $hashFunction, $bucket, $shard_split = 10000000)
    {
        return self::_getInstance($redis_config, $hashFunction, $bucket, $shard_split);
    }

    protected function _initServer()
    {
        if ($this->initialized) {
            return;
        }
        if (empty($this->server_config) || empty($this->hashFunction) || empty($this->bucket)) {
            return;
        }
        $this->Redis = Redis::getInstance($this->server_config);
        $this->Hash = new BloomFilterHash();
        $this->initialized = true;
    }

    /**
     * 添加到集合中
     * @param string $string
     * @return mixed
     */
    public function add($string)
    {
        if (!$this->initialized) {
            return false;
        }
        $bucket = $this->getRealBucket($string);
        $pipe = $this->Redis->multi();
        foreach ($this->hashFunction as $function) {
            $hash = $this->Hash->$function($string);
            $pipe->setBit($bucket, $hash, 1);
        }
        return $pipe->exec();
    }

    /**
     * 查询是否存在, 未命中的一定不存在，命中的一定概率会误判
     * @param string $string
     * @return bool
     */
    public function exists($string)
    {
        if (!$this->initialized) {
            return false;
        }
        $pipe = $this->Redis->multi();
        $bucket = $this->getRealBucket($string);
        $len = strlen($string);
        foreach ($this->hashFunction as $function) {
            $hash = $this->Hash->$function($string, $len);
            $pipe = $pipe->getBit($bucket, $hash);
        }
        $res = $pipe->exec();
        foreach ($res as $bit) {
            if ($bit == 0) {
                return false;
            }
        }
        return true;
    }

    /**
     * 只能删除 $string 所在的key
     * 慎重操作,key非常大
     * @param $string
     * @return int
     */
    public function deleteAllByString($string)
    {
        $bucket = $this->getRealBucket($string);
        return $this->Redis->del($bucket);
    }

    /**
     * 获取计算过的bucket
     * @param $string
     * @return string
     */
    protected function getRealBucket($string)
    {
        if (!is_numeric($string)) {
            $string = crc32($string);
        }
        return $this->bucket . intval($string / $this->shard_split);
    }

}
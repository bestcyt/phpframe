<?php

namespace Fw;
/**
 * https://cloud.tencent.com/developer/article/1426370
 * 发号器
 * 总共64位二进制  1符号位  41毫秒时间戳位(当前时间-开始的时间戳) 5服务器位 5业务位 12毫秒并发请求位
 * 41毫秒时间位只能支持从开始时间开始69年
 * 5服务位和5业务位分别只能支持32种情况
 * 12位毫秒并发位只能支持4096个数据(redis incr原子操作),超过就休眠到下一毫秒再获取
 * Class IdGenerator
 * @package Fw
 */
final class IdGenerator
{
    CONST BITS_FULL = 64;
    CONST BITS_PRE = 1;//固定
    CONST BITS_TIME = 41;//毫秒时间戳 可以最多支持69年
    CONST BITS_SERVER = 5; //服务器最多支持32台
    CONST BITS_WORKER = 5; //最多支持32种业务
    CONST BITS_SEQUENCE = 12; //一毫秒内支持4096个请求

    /**
     * 基准时间
     * @var string
     */
    protected $offset_time = "2019-05-05 00:00:00";//时间戳起点时间

    /**
     * 缓存key
     * @var string
     */
    protected $key = "id:";
    /**
     * 服务器id
     */
    protected $serverId;

    /**
     * 业务id
     */
    protected $workerId;

    /**
     * @var $redis Redis
     */
    protected $redis;

    public function __construct(Redis $redis)
    {
        $this->redis = $redis;
    }

    /**
     * 获取唯一值
     * @return bool|int|mixed
     */
    public function getNumber()
    {
        if (!isset($this->serverId)) {
            return false;
        }
        if (!isset($this->workerId)) {
            return false;
        }

        do {
            $id = pow(2, self::BITS_FULL - self::BITS_PRE) << self::BITS_PRE;

            //时间戳 41位
            $nowTime = (int)(microtime(true) * 1000);
            $startTime = (int)(strtotime($this->offset_time) * 1000);
            $diffTime = $nowTime - $startTime;
            $shift = self::BITS_FULL - self::BITS_PRE - self::BITS_TIME;
            $id |= $diffTime << $shift;

            //服务器
            $shift = $shift - self::BITS_SERVER;
            $id |= $this->serverId << $shift;

            //业务
            $shift = $shift - self::BITS_WORKER;
            $id |= $this->workerId << $shift;

            //自增值
            $sequenceNumber = $this->getSequence($id);
            if (false === $sequenceNumber) {
                return false;
            }
            if ($sequenceNumber > pow(2, self::BITS_SEQUENCE)) {
                usleep(1000);
            } else {
                $id |= $sequenceNumber;
                return $id;
            }
        } while (true);
        return false;
    }

    /**
     * 反解获取业务数据
     * @param $number
     * @return array
     */
    public function reverseNumber($number)
    {
        $uuidItem = [];
        $shift = self::BITS_FULL - self::BITS_PRE - self::BITS_TIME;
        $uuidItem['diffTime'] = ($number >> $shift) & (pow(2, self::BITS_TIME) - 1);

        $shift -= self::BITS_SERVER;
        $uuidItem['serverId'] = ($number >> $shift) & (pow(2, self::BITS_SERVER) - 1);

        $shift -= self::BITS_WORKER;
        $uuidItem['workerId'] = ($number >> $shift) & (pow(2, self::BITS_WORKER) - 1);

        $shift -= self::BITS_SEQUENCE;
        $uuidItem['sequenceNumber'] = ($number >> $shift) & (pow(2, self::BITS_SEQUENCE) - 1);

        $time = (int)($uuidItem['diffTime'] / 1000) + strtotime($this->offset_time);
        $uuidItem['generateTime'] = date("Y-m-d H:i:s", $time);

        return $uuidItem;
    }

    /**
     * 获取自增序列
     * @param $id
     * @return bool|mixed
     */
    protected function getSequence($id)
    {
        $lua = <<<LUA
            local sequenceKey = KEYS[1]
            local sequenceNumber = redis.call("incr", sequenceKey);
            redis.call("pexpire", sequenceKey, 1);
            return sequenceNumber
LUA;
        $sequence = $this->redis->eval($lua, [$this->key . $id], 1);
        $luaError = $this->redis->getLastError();
        if (isset($luaError)) {
            return false;
        } else {
            return $sequence;
        }
    }

    /**
     * @return mixed
     */
    public function getServerId()
    {
        return $this->serverId;
    }

    /**
     * @param $serverId
     * @return $this
     */
    public function setServerId($serverId)
    {
        $this->serverId = $serverId;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getWorkerId()
    {
        return $this->workerId;
    }

    /**
     * @param $workerId
     * @return $this
     */
    public function setWorkerId($workerId)
    {
        $this->workerId = $workerId;
        return $this;
    }

    /**
     * @param $offsetTime
     * @return $this
     */
    public function setOffsetTime($offsetTime)
    {
        $this->offset_time = $offsetTime;
        return $this;
    }

    public function getOffsetTime()
    {
        return $this->offset_time;
    }

    /**
     * @param $key
     * @return $this
     */
    public function setCacheKey($key)
    {
        $this->key = $key;
        return $this;
    }

    public function getCacheKey()
    {
        return $this->key;
    }
}
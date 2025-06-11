<?php
namespace Fw\Db;

class Pool
{
    /** @var ConnectInfo[] */
    private static $connectInfoPool = [];

    /**
     * @param $connectKey
     * @return ConnectInfo|null
     */
    public static function getConnectInfo($connectKey)
    {
        return $connectKey && isset(self::$connectInfoPool[$connectKey]) ? self::$connectInfoPool[$connectKey] : null;
    }

    public static function setConnectInfo($connectKey, $connectInfo)
    {
        self::$connectInfoPool[$connectKey] = $connectInfo;
    }

    public static function deleteConnectInfo($connectKey)
    {
        unset(self::$connectInfoPool[$connectKey]);
    }
}
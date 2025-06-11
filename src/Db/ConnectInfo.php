<?php
namespace Fw\Db;

class ConnectInfo
{
    /** @var  \PDO */
    private $conn; //db connect
    private $aliveTime = 0; //db connect alive time
    private $dbname = ''; //current dbname
    private $config = []; //connect config

    public function __construct($conn, $config)
    {
        $this->conn = $conn;
        $this->config = $config;
        $this->aliveTime = time();
    }

    public function getConn()
    {
        return $this->conn;
    }

    public function getDbname()
    {
        return $this->dbname;
    }

    public function setDbname($dbname)
    {
        $this->dbname = $dbname;
    }

    public function getAliveTime()
    {
        return $this->aliveTime;
    }

    public function keepAlive()
    {
        $this->aliveTime = time();
    }

    public function getConfig()
    {
        return $this->config;
    }
}
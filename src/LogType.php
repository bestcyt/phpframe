<?php
namespace Fw;

class LogType
{
    const MYSQL_CONNECT = 'fw.mysql.connect';
    const MYSQL_EXEC = 'fw.mysql.exec';

    const REDIS = 'fw.redis';

    const MEMCACHE = 'fw.memcache';

    const HTTP = 'fw.http';
    const HTTP_CONNECT = 'fw.http.connect';
    const HTTP_CLIENT = 'fw.http.client';
    const HTTP_SERVER = 'fw.http.server';
    const HTTP_SLOW = 'fw.http.slow';

}
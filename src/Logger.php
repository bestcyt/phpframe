<?php
namespace Fw;

class Logger
{
    use InstanceTrait;

    const LEVEL_ERROR = 1;
    const LEVEL_WARN = 2;
    const LEVEL_INFO = 3;
    const LEVEL_DEBUG = 4;

    const LEVEL_ERROR_TEXT = 'error';
    const LEVEL_WARN_TEXT = 'warn';
    const LEVEL_INFO_TEXT = 'info';
    const LEVEL_DEBUG_TEXT = 'debug';

    private $levelArr = [
        self::LEVEL_ERROR => self::LEVEL_ERROR_TEXT,
        self::LEVEL_WARN => self::LEVEL_WARN_TEXT,
        self::LEVEL_INFO => self::LEVEL_INFO_TEXT,
        self::LEVEL_DEBUG => self::LEVEL_DEBUG_TEXT,
    ];

    const HANDLER_FILE = 'file';
    const HANDLER_STDOUT = 'stdout';

    private $logMessageStringFormat = false; //日志的message字段是否转成json字符串后再写入
    private $indexFields = []; //用户自定义的日志索引字段，主要用于ES索引等类似场景，字段名若与已有字段重复则会被忽略，app.log_handler没有配置时则忽略自定义字段
    private $indexFieldKeys = []; //最多允许500个索引字段

    /**
     * @param $level
     * @param $message array|string
     * @param $type
     * @return bool
     */
    public function log($level, $message, $type)
    {
        //app.log_handler没有配置时，日志格式为:[time(ISO8601)]  [host]  [type(service.module.function)]  [req_id]  [server_ip]  [client_ip]  [message(json:code,message,file,line,trace,biz_data)]
        //app.log_handler为file/stdout时，日志格式为:{"t": "time(ISO8601)", "lvl": "level", "h": "host", "type": "type(service.module.function)", "reqid": "req_id", "sip": "server_ip", "cip": "client_ip", "msg": {"code": 0, "message": "xxx", "file": "file", "line": 0}}

        $logLevelText = app_env('app.log_level');
        $foundKey = array_search($logLevelText, $this->levelArr);
        if ($foundKey === false) {
            $logLevel = self::LEVEL_INFO;
        } else {
            $logLevel = $foundKey;
        }
        //大于配置中的log_level则不打印,默认打印error,warn,info类型
        if ($level > $logLevel) {
            return false;
        }

        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'cli';
        $time = date('c'); //ISO8601标准时间格式,形如:2016-11-02T06:46:10+00:00
        $request = Request::getInstance();
        $reqId = $request->getReqId();
        $serverIp = $request->getServerIp();
        $clientIp = $request->getClientIp();

        $logHandler = app_env('app.log_handler');
        $result = false;
        $levelText = isset($this->levelArr[$level]) ? $this->levelArr[$level] : self::LEVEL_INFO_TEXT;
        if ($this->logMessageStringFormat || app_env('app.log_message_string_format') == true) {
            if (!$this->logMessageStringFormat) {
                $this->logMessageStringFormat = true;
            }
            if (!is_string($message)) {
                $message = json_encode($message, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PARTIAL_OUTPUT_ON_ERROR);
            } else {
                $message = str_replace(["\r", "\n"], ' ', $message);
            }
        }
        switch ($logHandler) {
            case self::HANDLER_STDOUT:
                $log = [
                    't' => $time,
                    'lvl' => $levelText,
                    'h' => $host,
                    'type' => $type,
                    'reqid' => $reqId,
                    'sip' => $serverIp,
                    'cip' => $clientIp,
                    'msg' => $message
                ];
                if ($this->indexFields) {
                    $log += $this->indexFields;
                    $this->indexFields = [];
                }
                $content = json_encode($log, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n";
                $logStdout = app_env('app.log_stdout');
                if ($logStdout) {
                    $fp = fopen($logStdout, 'wb');
                    $result = $this->_fwrite($fp, $content) !== false;
                    fclose($fp);
                } else {
                    $fp = defined('STDOUT') ? STDOUT : fopen('php://stdout', 'wb');
                    $result = $this->_fwrite($fp, $content) !== false;
                }

                break;

            case self::HANDLER_FILE:
                $log = [
                    't' => $time,
                    'level' => $levelText,
                    'h' => $host,
                    'type' => $type,
                    'reqid' => $reqId,
                    'sip' => $serverIp,
                    'cip' => $clientIp,
                    'msg' => $message
                ];
                if ($this->indexFields) {
                    $log += $this->indexFields;
                    $this->indexFields = [];
                }
                $content = json_encode($log, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n";
                $dir = app_env('app.log_path');
                if (!$dir) {
                    $dir = app_root_path() . '/logs';
                }
                if (!is_dir($dir)) {
                    mkdir($dir, 0777, true);
                }
                $filename = $dir . '/' . $levelText . '.log';
                $isFileExist = is_file($filename);
                $result = $this->_error_log($content, 3, $filename);
                if ($result && !$isFileExist) {
                    chmod($filename, 0777);
                }
                break;

            default:
                if (!$this->logMessageStringFormat) {
                    if (!is_string($message)) {
                        $message = json_encode($message, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PARTIAL_OUTPUT_ON_ERROR);
                    } else {
                        $message = str_replace(["\r", "\n"], ' ', $message);
                    }
                }
                $log = [
                    $time,
                    $host,
                    $type,
                    $reqId,
                    $serverIp,
                    $clientIp,
                    $message
                ];
                $content = '[' . implode(']  [', $log) . ']' . "\n";
                $dir = app_env('app.log_path');
                if (!$dir) {
                    $dir = app_root_path() . '/logs';
                }
                if (!is_dir($dir)) {
                    mkdir($dir, 0777, true);
                }
                $filename = $dir . '/' . $levelText . '.log';
                $isFileExist = is_file($filename);
                $result = $this->_error_log($content, 3, $filename);
                if ($result && !$isFileExist) {
                    chmod($filename, 0777);
                }
                break;
        }
        return $result;
    }

    public function error($logInfo, $type)
    {
        $this->log(self::LEVEL_ERROR, $logInfo, $type);
    }

    public function warn($logInfo, $type)
    {
        $this->log(self::LEVEL_WARN, $logInfo, $type);
    }

    public function info($logInfo, $type)
    {
        $this->log(self::LEVEL_INFO, $logInfo, $type);
    }

    public function debug($message, $type)
    {
        $this->log(self::LEVEL_DEBUG, $message, $type);
    }

    public function setIndexFieldKeys($indexFieldKeys = [])
    {
        if (count($this->indexFieldKeys) + count($indexFieldKeys) > 500) {
            trigger_error("logger index field keys count > 500");
            return false;
        }
        $this->indexFieldKeys = array_merge($this->indexFieldKeys, $indexFieldKeys);
        return true;
    }

    public function field($fieldName, $value)
    {
        return $this->indexField($fieldName, $value);
    }

    private function indexField($fieldName, $value)
    {
        if (isset($this->indexFieldKeys[$fieldName])) {
            $fieldType = strtolower($this->indexFieldKeys[$fieldName]);
            switch ($fieldType) {
                case 'int':
                    $value = intval($value);
                    break;
                case 'float':
                    $value = floatval($value);
                    break;
                case 'bool':
                    $value = boolval($value);
                    break;
                default: //string
                    $value = (string)$value;
                    break;
            }
            $this->indexFields[$fieldName] = $value;
        }
        return $this;
    }

    private function _error_log($content, $type, $dest)
    {
        $arr = [];
        if (strlen($content) > 1024) {
            $arr = str_split($content, 1024);
        } else {
            $arr[] = $content;
        }
        foreach ($arr as $item) {
            if (!error_log($item, $type, $dest)) {
                return false;
            }
        }
        return true;
    }

    private function _fwrite($fp, $content)
    {
        $arr = [];
        $needLock = false;
        if (strlen($content) > 1024) {
            $needLock = true;
            $arr = str_split($content, 1024);
        } else {
            $arr[] = $content;
        }
        $bytes = 0;
        if ($needLock) {
            flock($fp, LOCK_EX);
        }
        foreach ($arr as $item) {
            $result = fwrite($fp, $item);
            if ($result === false) {
                if ($needLock) {
                    flock($fp, LOCK_UN);
                }
                return false;
            } else {
                $bytes += $result;
            }
        }
        if ($needLock) {
            flock($fp, LOCK_UN);
        }
        return $bytes;
    }
}
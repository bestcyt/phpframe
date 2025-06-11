<?php

namespace Fw;

use Fw\Exception\ErrorHandlerException;
use Fw\Exception\ExitException;
use Fw\Exception\ShutdownException;
use Fw\Exception\WarningHandlerException;

class App
{
    use InstanceTrait;

    /**
     * @var array $namespaces
     * 命名空间前缀与目录的映射关系
     * 形如:['ns1' => ['path11', 'path12'], 'ns2' => 'path21']
     */
    private static $namespaces = [];
    private static $importedFiles = [];
    private static $error500Codes = [
        E_ERROR,
        E_CORE_ERROR,
        E_COMPILE_ERROR,
        E_USER_ERROR,
        E_PARSE,
        E_RECOVERABLE_ERROR
    ];
    private static $currentTime;
    private static $currentMicrotime;

    private $currentAppPath;
    private $appName;
    private $appNamespace;
    private $environment;
    private $envPath;
    private $rootPath;
    private $beforeRouteCallbacks;
    private $afterRouteCallbacks;
    private $beforeDispatchCallbacks;
    private $afterDispatchCallbacks;
    private $appMode = self::MODE_WEB;
    private $supportedSuffixes = []; //PATH_INFO所支持的后缀,如:json/xml等
    private $modules = []; //指定支持的module,只有被指定的module才作为module来识别,否则当做controller来识别
    private $logger; //日志类
    private $envLabels = null; //环境标识数据

    private $initialized = false; //标记运行run()前是否已经调用过init()

    private $controllerNamespace = null;
    private $controllerPath = null;

    const MODE_WEB = 'web';
    const MODE_CONSOLE = 'console';

    public function setCustomEnvPath($envPath)
    {
        if (!empty($envPath) && empty($this->envPath)) {
            $envPath = rtrim($envPath, '/\\');
            $env = require_once($envPath . "/env.php");
            if (!empty($env)) {
                $this->environment = $env;
                $this->setEnvPath($envPath);
            }
        }
        return $this;
    }

    public function init($rootPath, $appName, $appMode = null)
    {
        $this->initialized = true;

        $this->appMode = $appMode;
        $this->appName = $appName;

        //设置一些关键目录的优先级最高
        $rootPath = rtrim($rootPath, '/\\');
        $this->currentAppPath = $rootPath . '/src/App/' . $this->appName;
        $this->rootPath = $rootPath;
        $this->appNamespace = '\\Mt\\App\\' . $this->appName;

        //set namespaces
        $this->setNamespaces($this->config('namespaces'));
        //preload files
        $this->preloadFiles();

        spl_autoload_register([$this, 'autoload']);
        set_exception_handler([$this, '_exceptionHandler']);
        set_error_handler([$this, '_errorHandler'], error_reporting());
        register_shutdown_function([$this, '_shutdownFunction']);
    }

    public function run($rootPath, $appName, $appMode = null)
    {
        if (!$this->initialized) {
            $this->init($rootPath, $appName, $appMode);
        }

        $request = Request::getInstance();
        $router = Router::getInstance();
        $dispatcher = Dispatcher::getInstance();

        //before route
        if ($this->beforeRouteCallbacks) {
            $this->executeCallbacks($this->beforeRouteCallbacks);
        }

        //加载约定的默认路由重写规则配置文件
        $routeConfig = $this->config('route/' . $this->formatStudlyCapsToUnderScore($appName));
        if ($routeConfig) {
            $router->set($routeConfig);
        }
        $routePath = $router->route($request->getPathInfo());
        $request->setRoutePath($routePath);

        //after route
        if ($this->afterRouteCallbacks) {
            $this->executeCallbacks($this->afterRouteCallbacks);
        }

        //before dispatch
        if ($this->beforeDispatchCallbacks) {
            $this->executeCallbacks($this->beforeDispatchCallbacks);
        }

        $dispatcher->dispatch($request);

        //after dispatch
        if ($this->afterDispatchCallbacks) {
            $this->executeCallbacks($this->afterDispatchCallbacks);
        }

    }

    /**
     * 预加载指定的PHP文件，在preload.php配置文件中直接指定绝对路径文件
     * 形如：['/root/src/const.php', '/root/src/functions.php']
     */
    private function preloadFiles()
    {
        $preloadFiles = $this->config('preload');
        if ($preloadFiles && is_array($preloadFiles)) {
            foreach ($preloadFiles as $file) {
                if ($file) {
                    self::import($file);
                }
            }
        }
    }

    public function autoload($fullClassName)
    {
        foreach (self::$namespaces as $namespace => $paths) {
            $namespaceLen = strlen($namespace);
            if (substr($fullClassName, 0, $namespaceLen) == $namespace) {
                if (!is_array($paths)) {
                    $paths = [$paths];
                } else {
                    $paths = array_unique($paths);
                }
                $relativeFile = trim(str_replace('\\', '/', substr($fullClassName, $namespaceLen)), '/');
                foreach ($paths as $path) {
                    $file = rtrim($path, '/\\') . '/' . $relativeFile . '.php';
                    if (self::import($file)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    public function _errorHandler($errorNo, $error, $file, $line)
    {
        if (!(error_reporting() & $errorNo)) {
            return false;
        }
        if (self::isError500Code($errorNo)) {
            $e = new ErrorHandlerException($error, $errorNo);
            $e->setFile($file);
            $e->setLine($line);
        } else {
            $e = new WarningHandlerException($error, $errorNo);
            $e->setFile($file);
            $e->setLine($line);
        }
        $this->_exceptionHandler($e);
    }

    public function getControllerNamespace()
    {
        if (!$this->controllerNamespace) {
            $isConsoleMode = $this->getAppMode() == self::MODE_CONSOLE;
            $namespace = $this->getAppNamespace() . '\\';
            if ($isConsoleMode) {
                //console模式下控制器的命名空间
                $namespace .= 'Console\\';
            } else {
                $namespace .= 'Controller\\';
            }
            $this->controllerNamespace = $namespace;
        }
        return $this->controllerNamespace;
    }

    public function getControllerPath()
    {
        if (!$this->controllerPath) {
            $isConsoleMode = $this->getAppMode() == self::MODE_CONSOLE;
            $path = $this->getCurrentAppPath();
            if ($isConsoleMode) {
                $path .= '/Console';
            } else {
                $path .= '/Controller';
            }
            $this->controllerPath = $path;
        }
        return $this->controllerPath;
    }

    public function getErrorControllerName()
    {
        return $this->getControllerNamespace() . 'Error';
    }

    public function _exceptionHandler($exception)
    {
        //ExitException异常不处理
        if ($exception instanceof ExitException) {
            return;
        }
        $fullErrorClassName = $this->getErrorControllerName();
        try {
            if (class_exists($fullErrorClassName)) {
                $obj = new $fullErrorClassName();
                if (method_exists($obj, 'main')) {
                    $obj->main($exception);
                } else {
                    throw $exception;
                }
            } else {
                throw $exception;
            }
        } catch (\Exception $exception) {
            //忽略ExitException异常
            if (!($exception instanceof ExitException)) {
                throw $exception;
            }
        }
    }

    public function _shutdownFunction()
    {
        $error = error_get_last();
        $errorTypes = [
            E_ERROR,
            E_PARSE,
            E_CORE_ERROR,
            E_CORE_WARNING,
            E_COMPILE_ERROR,
            E_COMPILE_WARNING
        ];
        if ($error && in_array($error['type'], $errorTypes)) {
            //$error['type'], $error['message'], $error['file'], $error['line']
            $e = new ShutdownException($error['message'], $error['type']);
            $e->setFile($error['file']);
            $e->setLine($error['line']);
            $this->_exceptionHandler($e);
        }
    }

    public static function import($file)
    {
        if (self::$importedFiles && in_array($file, self::$importedFiles)) {
            return true;
        }
        if (is_file($file)) {
            require $file;
            array_push(self::$importedFiles, $file);
            return true;
        }
        return false;
    }

    public function registerNamespace($namespace, $path, $prepend = false)
    {
        if (empty(self::$namespaces[$namespace])) {
            self::$namespaces[$namespace] = $path;
            //命名空间前缀一样时,需要把更多层级的放在前面先匹配
            krsort(self::$namespaces);
        } else {
            if (!is_array(self::$namespaces[$namespace])) {
                self::$namespaces[$namespace] = [self::$namespaces[$namespace]];
            }
            if ($prepend) {
                array_unshift(self::$namespaces[$namespace], $path);
            } else {
                array_push(self::$namespaces[$namespace], $path);
            }
        }
        return $this;
    }

    public function setNamespaces($namespaces)
    {
        if ($namespaces) {
            foreach ($namespaces as $namespace => $path) {
                self::registerNamespace($namespace, $path);
            }
        }
        return $this;
    }

    public function getAppName()
    {
        return $this->appName;
    }

    public function getAppNamespace()
    {
        return $this->appNamespace;
    }

    public function getCurrentAppPath()
    {
        return $this->currentAppPath;
    }

    public function getRootPath()
    {
        if (!$this->rootPath) {
            throw new Exception('root path has not set');
        }
        return $this->rootPath;
    }

    public function setRoutes($routes)
    {
        $route = Router::getInstance();
        $route->set($routes);
        return $this;
    }

    public function supportSuffix($suffix)
    {
        $this->supportedSuffixes[] = $suffix;
        return $this;
    }

    public function setSupportedSuffixes($suffixes)
    {
        $this->supportedSuffixes = $suffixes;
        return $this;
    }

    public function getSupportedSuffixes()
    {
        return $this->supportedSuffixes;
    }

    public function setModules(array $modules)
    {
        $this->modules = $modules;
        return $this;
    }

    public function getModules()
    {
        return $this->modules;
    }

    public function setLogger(Logger $logger)
    {
        if ($logger) {
            $this->logger = $logger;
        }
        return $this;
    }

    /**
     * @return Logger
     */
    public function getLogger()
    {
        if (!($this->logger instanceof Logger)) {
            $this->logger = Logger::getInstance();
        }
        return $this->logger;
    }

    public function formatUnderScoreToCamelCase($underScore)
    {
        $underScoreArr = explode('_', strtolower($underScore));
        $camelCase = '';
        foreach ($underScoreArr as $index => $item) {
            if ($item == '') {
                //为了让a_bc与a__bc不等价
                $item = '_';
            }
            $camelCase .= $index > 0 ? ucfirst($item) : $item;
        }
        return $camelCase;
    }

    public function formatUnderScoreToStudlyCaps($underScore)
    {
        $underScoreArr = explode('_', strtolower($underScore));
        $studlyCaps = '';
        foreach ($underScoreArr as $index => $item) {
            if ($item == '') {
                //为了让a_bc与a__bc不等价
                $item = '_';
            }
            $studlyCaps .= ucfirst($item);
        }
        return $studlyCaps;
    }

    public function formatCamelCaseToUnderScore($camelCase)
    {
        $length = strlen($camelCase);
        $chrArr = [];
        for ($i = 0; $i < $length; $i++) {
            if ($camelCase[$i] >= 'A' && $camelCase[$i] <= 'Z') {
                //大写字母
                $chrArr[] = '_';
                $chrArr[] = strtolower($camelCase[$i]);
            } else {
                $chrArr[] = $camelCase[$i];
            }
        }
        return implode('', $chrArr);
    }

    public function formatStudlyCapsToUnderScore($studlyCaps)
    {
        $length = strlen($studlyCaps);
        $chrArr = [];
        for ($i = 0; $i < $length; $i++) {
            if ($studlyCaps[$i] >= 'A' && $studlyCaps[$i] <= 'Z') {
                //大写字母
                if ($i > 0) {
                    $chrArr[] = '_';
                }
                $chrArr[] = strtolower($studlyCaps[$i]);
            } else {
                $chrArr[] = $studlyCaps[$i];
            }
        }
        return implode('', $chrArr);
    }

    public function getError500Codes()
    {
        return self::$error500Codes;
    }

    public static function isError500Code($errorCode)
    {
        return in_array($errorCode, self::$error500Codes);
    }

    /**
     * 根据key获取对应配置项
     * @param string $key 如:app.log_path
     * @return null
     */
    public function config($key)
    {
        if (!$key) {
            return null;
        }
        $path = $this->getRootPath() . '/src/config';
        return Config::getProvider(Config::TYPE_PHP, $path)->get($key);
    }

    private function getProductEnv()
    {
        if (defined('ENVIRONMENT_RELEASE')) {
            return ENVIRONMENT_RELEASE;
        } elseif (defined('ENVIRONMENT_PRODUCT')) {
            return ENVIRONMENT_PRODUCT;
        } else {
            return 'product';
        }
    }

    public function getEnvironment()
    {
        if (!$this->environment) {
            $myEnvKey = defined('ENVIRONMENT_KEY') ? ENVIRONMENT_KEY : 'ENVIRONMENT';
            $this->environment = strtolower(trim(getenv($myEnvKey)));
            if (!$this->environment) {
                $this->environment = $this->getProductEnv();
            }
        }
        return $this->environment;
    }

    private function setEnvPath($path)
    {
        $this->envPath = rtrim($path, '/\\');
        return $this;
    }

    /**
     * 根据key获取对应环境配置项
     * @param string $key 如:db.host
     * @return mixed|null
     */
    public function env($key)
    {
        if (!$key) {
            return null;
        }
        if (!$this->envPath) {
            $env = $this->getEnvironment();
            $envFilePath = '';
            $envPaths = $this->config('app.env_path');
            if ($envPaths) {
                $envPath = isset($envPaths[$env]) ? $envPaths[$env] : '';
                if (!$envPath) {
                    $envPath = isset($envPaths['_else']) ? $envPaths['_else'] : '';
                }
                if ($envPath) {
                    $envFilePath = rtrim($envPath, '/\\') . '/' . $env;
                }
            } else {
                $envFilePath = $this->config('app.env_file_path.' . $env);
            }
            if (!$envFilePath) {
                $envFilePath = $this->getRootPath() . '/env/' . $env;
            }
            $this->setEnvPath($envFilePath);
        }
        return Config::getProvider(Config::TYPE_PHP, $this->envPath)->get($key);
    }

    public function beforeRoute(callable $callback)
    {
        $this->beforeRouteCallbacks[] = $callback;
        return $this;
    }

    public function afterRoute(callable $callback)
    {
        $this->afterRouteCallbacks[] = $callback;
        return $this;
    }

    public function beforeDispatch(callable $callback)
    {
        $this->beforeDispatchCallbacks[] = $callback;
        return $this;
    }

    public function afterDispatch(callable $callback)
    {
        $this->afterDispatchCallbacks[] = $callback;
        return $this;
    }

    private function executeCallbacks($callbacks)
    {
        $request = Request::getInstance();
        $app = App::getInstance();
        foreach ($callbacks as $callback) {
            call_user_func($callback, $request, $app);
        }
    }

    public static function getCurrentTime()
    {
        if (php_sapi_name() == 'cli') {
            return time();
        }
        if (!self::$currentTime) {
            if (isset($_SERVER['REQUEST_TIME'])) {
                self::$currentTime = $_SERVER['REQUEST_TIME'];
            } else {
                self::$currentTime = time();
            }
        }
        return self::$currentTime;
    }

    public static function getCurrentMicrotime()
    {
        if (php_sapi_name() == 'cli') {
            return microtime(true);
        }
        if (!self::$currentMicrotime) {
            if (isset($_SERVER['REQUEST_TIME_FLOAT'])) {
                self::$currentMicrotime = $_SERVER['REQUEST_TIME_FLOAT'];
            } else {
                self::$currentMicrotime = microtime(true);
            }
        }
        return self::$currentMicrotime;
    }

    public function getAppMode()
    {
        return $this->appMode;
    }

    public function getEnvLabels()
    {
        if (is_null($this->envLabels)) {
            $data = [];
            $envLabelFile = $this->config('app.env_label_file');
            if (is_file($envLabelFile)) {
                $data = parse_ini_file($envLabelFile);
            }
            $this->envLabels = $data;
        }
        return $this->envLabels;
    }

    public function getEnvLabel($label)
    {
        if (!$label) {
            return null;
        }
        $data = $this->getEnvLabels();
        return isset($data[$label]) ? $data[$label] : null;
    }

}
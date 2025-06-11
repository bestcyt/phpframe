<?php
namespace Fw;

class Purview
{
    use InstanceTrait {
        getInstance as _getInstance;
    }

    private static $nodes;
    protected $cacheKey = 'mtphp.fw.purview.all_nodes';
    protected $cacheTtl = 300;

    public static function getInstance($config = [])
    {
        return self::_getInstance($config);
    }

    private function __construct($config = [])
    {
        if ($config) {
            if (isset($config['cache_key'])) {
                $this->cacheKey = $config['cache_key'];
            }
            if (isset($config['cache_ttl'])) {
                $this->cacheTtl = intval($config['cache_ttl']);
            }
        }
    }

    /**
     * 从配置中获取权限节点信息
     * 默认模块的key用index来标识,默认模块的权限节点不展示在导航、菜单中
     * 当权限节点的配置项中有sort_weight字段时，对节点进行排序，默认排序权重都为0，权重越大排序越前，权重允许负数
     * @return mixed|null
     */
    public function getAllNodes()
    {
        if (!self::$nodes) {
            $config = [];
            if (function_exists('apcu_fetch')) {
                $config = apcu_fetch($this->cacheKey);
            }
            if (!$config) {
                $app = App::getInstance();
                $config = $app->config('purview');
                if (!$config) {
                    $modulesConfig = $app->config('purview/modules');
                    if ($modulesConfig) {
                        $purviewConfigPath = $app->getRootPath() . '/src/config/purview';
                        foreach ($modulesConfig as $moduleId => $moduleItem) {
                            $config[$moduleId] = $moduleItem;
                            $controllerConfigFilePath = glob($purviewConfigPath . '/' . $moduleId . '/*.php');
                            foreach ($controllerConfigFilePath as $file) {
                                $controllerConfig = $app->config('purview/' . $moduleId . '/' . basename($file, '.php'));
                                if ($controllerConfig && !empty($controllerConfig['id'])) {
                                    $config[$moduleId]['controllers'][$controllerConfig['id']] = $controllerConfig;
                                }
                            }
                        }
                    }
                }

                if ($config && is_array($config)) {
                    //当配置项中有sort_weight字段时，对节点进行排序，默认排序权重都为0，权重越大排序越前，权重允许负数
                    $sortModuleKeys = [];
                    foreach ($config as $moduleKey => $moduleItem) {
                        //modules
                        $sortModuleKeys[] = [
                            'key' => $moduleKey,
                            'weight' => isset($moduleItem['sort_weight']) ? intval($moduleItem['sort_weight']) : 0
                        ];
                        if (isset($moduleItem['controllers'])) {
                            //controllers
                            $sortControllerKeys = [];
                            foreach ($moduleItem['controllers'] as $controllerKey => $controllerItem) {
                                $sortControllerKeys[] = [
                                    'key' => $controllerKey,
                                    'weight' => isset($controllerItem['sort_weight']) ? intval($controllerItem['sort_weight']) : 0
                                ];
                                if (isset($controllerItem['actions'])) {
                                    //actions
                                    $sortActionKeys = [];
                                    foreach ($controllerItem['actions'] as $actionKey => $actionItem) {
                                        $sortActionKeys[] = [
                                            'key' => $actionKey,
                                            'weight' => isset($actionItem['sort_weight']) ? intval($actionItem['sort_weight']) : 0
                                        ];
                                    }
                                    $sortActionKeys = $this->sortKeys($sortActionKeys);
                                    $actions = [];
                                    foreach ($sortActionKeys as $item) {
                                        $actions[$item['key']] = $config[$moduleKey]['controllers'][$controllerKey]['actions'][$item['key']];
                                    }
                                    $config[$moduleKey]['controllers'][$controllerKey]['actions'] = $actions;
                                }
                            }
                            $sortControllerKeys = $this->sortKeys($sortControllerKeys);
                            $controllers = [];
                            foreach ($sortControllerKeys as $item) {
                                $controllers[$item['key']] = $config[$moduleKey]['controllers'][$item['key']];
                            }
                            $config[$moduleKey]['controllers'] = $controllers;
                        }
                    }
                    $sortModuleKeys = $this->sortKeys($sortModuleKeys);
                    $modules = [];
                    foreach ($sortModuleKeys as $item) {
                        $modules[$item['key']] = $config[$item['key']];
                    }
                    $config = $modules;
                }

                if (function_exists('apcu_store')) {
                    apcu_store($this->cacheKey, $config, $this->cacheTtl);
                }
            }
            self::$nodes = $config;
        }
        return self::$nodes;
    }

    public function isGranted($module, $controller, $action, $privileges, $isReadonly = false)
    {
        if ($module == '') {
            $module = 'index';
        }
        $isGranted = false;
        if ($privileges == '*'
            ||
            isset($privileges[$module])
            &&
            $privileges[$module] == '*'
            ||
            isset($privileges[$module][$controller])
            &&
            $privileges[$module][$controller] == '*'
            ||
            isset($privileges[$module][$controller][$action])
            &&
            $privileges[$module][$controller][$action] == '*'
        ) {
            $isGranted = true;
        }
        if ($isGranted && $isReadonly) {
            $nodes = $this->getAllNodes();
            $moduleNodes = isset($nodes[$module]) ? $nodes[$module] : [];
            if (!empty($moduleNodes['controllers'][$controller]['actions'][$action]['writable'])) {
                $isGranted = false;
            }
        }
        return $isGranted;
    }

    public function mergePrivileges($privilegesArr)
    {
        $allPrivileges = [];
        foreach ($privilegesArr as $privileges) {
            $privileges = $privileges ? explode(',', $privileges) : [];
            foreach ($privileges as $privilege) {
                $privilege = trim($privilege);
                if ($privilege) {
                    $segments = explode(':', $privilege);
                    $module = !empty($segments[0]) ? $segments[0] : '';
                    if (!$module) {
                        continue;
                    } elseif ($module == '*') {
                        $allPrivileges = '*';
                        break 2;
                    }
                    $controller = !empty($segments[1]) ? $segments[1] : '*';
                    if ($allPrivileges == '*') {
                        continue;
                    }
                    if ($controller == '*') {
                        $allPrivileges[$module] = '*';
                        continue;
                    }
                    $action = !empty($segments[2]) ? $segments[2] : '*';
                    if (isset($allPrivileges[$module]) && $allPrivileges[$module] == '*') {
                        continue;
                    }
                    if ($action == '*') {
                        $allPrivileges[$module][$controller] = '*';
                    } elseif (!isset($allPrivileges[$module][$controller]) || $allPrivileges[$module][$controller] != '*') {
                        $allPrivileges[$module][$controller][$action] = '*';
                    }
                }
            }
        }
        return $allPrivileges;
    }

    public function getMenus($privileges)
    {
        $menus = [];
        $allNodes = $this->getAllNodes();
        foreach ($allNodes as $moduleId => $moduleInfo) {
            if ($privileges != '*' && empty($privileges[$moduleId])) {
                continue;
            }
            if (!empty($moduleInfo['controllers'])) {
                $controllerMenus = [];
                foreach ($moduleInfo['controllers'] as $controllerId => $controllerInfo) {
                    if ($privileges != '*' && $privileges[$moduleId] != '*' && empty($privileges[$moduleId][$controllerId])) {
                        continue;
                    }
                    if (!empty($controllerInfo['actions'])) {
                        $actionMenus = [];
                        foreach ($controllerInfo['actions'] as $actionId => $actionInfo) {
                            if ($privileges != '*' && $privileges[$moduleId] != '*' && $privileges[$moduleId][$controllerId] != '*' && empty($privileges[$moduleId][$controllerId][$actionId])) {
                                continue;
                            }
                            if (!empty($actionInfo['is_menu'])) {
                                $actionUrl = $moduleId == 'index' ? '/' . $controllerId : '/' . $moduleId . '/' . $controllerId;
                                $actionUrl .= $actionId == 'index' ? '' : '/' . $actionId;

                                $actionInfo['id'] = $actionId;
                                $actionInfo['url'] = $actionUrl;
                                $actionMenus[$actionId] = $actionInfo;
                            }
                        }
                        if ($actionMenus) {
                            $controllerUrl = '';
                            if (count($actionMenus) == 1) {
                                $curActionMenu = current($actionMenus);
                                if (isset($curActionMenu['id']) && $curActionMenu['id'] == 'index') {
                                    $controllerUrl = $moduleId == 'index' ? "/{$controllerId}" : "/{$moduleId}/{$controllerId}";
                                    $actionMenus = [];
                                }
                            }

                            $controllerInfo['id'] = $controllerId;
                            $controllerInfo['url'] = $controllerUrl;
                            $controllerInfo['action_menus'] = $actionMenus;
                            unset($controllerInfo['actions']);
                            $controllerMenus[$controllerId] = $controllerInfo;
                        }
                    }
                }
                if ($controllerMenus) {
                    $moduleUrl = '';
                    $curControllerMenu = current($controllerMenus);
                    if (!empty($curControllerMenu['url'])) {
                        $moduleUrl = $curControllerMenu['url'];
                    } else {
                        $curActionMenu = current($curControllerMenu['action_menus']);
                        if (!empty($curActionMenu['url'])) {
                            $moduleUrl = $curActionMenu['url'];
                        }
                    }

                    $moduleInfo['id'] = $moduleId;
                    $moduleInfo['url'] = $moduleUrl;
                    $moduleInfo['controller_menus'] = $controllerMenus;
                    unset($moduleInfo['controllers']);
                    $menus[$moduleId] = $moduleInfo;
                }
            }
        }

        return $menus;
    }

    public function getTreeNodes($privilegeNodes, $checkedPrivileges = [])
    {
        $nodes = [];
        foreach ($privilegeNodes as $moduleId => $module) {
            $moduleAllChecked = $checkedPrivileges == '*'
                || isset($checkedPrivileges[$moduleId])
                && $checkedPrivileges[$moduleId] == '*';
            $moduleChecked = $moduleAllChecked
                || !empty($checkedPrivileges[$moduleId]);
            $controllers = !empty($module['controllers']) ? $module['controllers'] : [];
            $controllerNodes = [];
            if ($controllers) {
                foreach ($controllers as $controllerId => $controller) {
                    $controllerAllChecked = $moduleAllChecked
                        || isset($checkedPrivileges[$moduleId][$controllerId])
                        && $checkedPrivileges[$moduleId][$controllerId] == '*';
                    $controllerChecked = $controllerAllChecked
                        || !empty($checkedPrivileges[$moduleId][$controllerId]);
                    $actions = !empty($controller['actions']) ? $controller['actions'] : [];
                    $actionNodes = [];
                    if ($actions) {
                        foreach ($actions as $actionId => $action) {
                            $actionChecked = $controllerAllChecked
                                || !empty($checkedPrivileges[$moduleId][$controllerId][$actionId]);
                            $name = $action['name'];
                            if (!empty($action['is_menu'])) {
                                $name .= ' [菜单]';
                            }
                            if (!empty($action['writable'])) {
                                $name .= ' [可写]';
                            }
                            $actionNodes[] = [
                                'id' => $moduleId . ':' . $controllerId . ':' . $actionId,
                                'name' => $name,
                                'checked' => $actionChecked
                            ];
                        }
                    }
                    $controllerNodes[] = [
                        'id' => $moduleId . ':' . $controllerId,
                        'name' => $controller['name'],
                        'checked' => $controllerChecked,
                        'children' => $actionNodes
                    ];
                }
            }
            $nodes[] = [
                'id' => $moduleId,
                'name' => $module['name'],
                'checked' => $moduleChecked,
                'open' => true,
                'children' => $controllerNodes
            ];
        }
        return $nodes;
    }

    public function clearCache()
    {
        if (function_exists('apcu_delete')) {
            return apcu_delete($this->cacheKey);
        }
        return false;
    }

    private function sortKeys($keys) {
        $arrByWeight = [];
        foreach ($keys as $item) {
            $arrByWeight[$item['weight']][$item['key']] = $item;
        }
        krsort($arrByWeight);
        $result = [];
        foreach ($arrByWeight as $itemByWeight) {
            foreach ($itemByWeight as $item) {
                $result[] = $item;
            }
        }
        return $result;
    }

}
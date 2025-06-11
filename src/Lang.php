<?php
namespace Fw;

class Lang
{
    use InstanceTrait;

    private $supportedLangArr = []; //['en', 'zh-Hans', ...]
    private $defaultLang = '';
    private $currentLang = '';
    private $langPath = '';
    private $langPacksCache = [];
    protected $configKey = 'lang';

    private function __construct()
    {
        $config = App::getInstance()->config($this->configKey);
        $this->langPath = isset($config['lang_path']) ? $config['lang_path'] : '';
        $this->supportedLangArr = isset($config['supported_lang_arr']) ? $config['supported_lang_arr'] : [];
        $this->defaultLang = isset($config['default_lang']) ? $config['default_lang'] : '';
    }

    private function getLangPack($lang)
    {
        if (!isset($this->langPacksCache[$lang])) {
            $file = $this->langPath . '/' . $lang . '.php';
            if (is_file($file)) {
                $this->langPacksCache[$lang] = include $file;
            }
        }
        return $this->langPacksCache[$lang];
    }

    public function show($langCode, $args = [], $lang = '')
    {
        if (!$lang) {
            if ($this->currentLang) {
                $lang = $this->currentLang;
            } else {
                $lang = $this->defaultLang;
            }
        }
        $langConfig = $this->getLangPack($lang);
        $content = '';
        if ($langConfig && isset($langConfig[$langCode])) {
            $content = (string)$langConfig[$langCode];
            if ($content && $args) {
                array_unshift($args, $content);
                $content = call_user_func_array('sprintf', $args);
            }
        }
        return $content;
    }

    public function setCurrentLang($lang)
    {
        if (in_array($lang, $this->supportedLangArr)) {
            $this->currentLang = $lang;
        }
    }

    public function getCurrentLang()
    {
        return $this->currentLang ? $this->currentLang : $this->defaultLang;
    }

    public function getSupportedLangArr()
    {
        return $this->supportedLangArr;
    }

    public function setSupportedLangArr(array $langArr)
    {
        $this->supportedLangArr = $langArr;
    }

    public function addSupportedLang($lang)
    {
        if (!$this->supportedLangArr) {
            $this->supportedLangArr[] = $lang;
        } elseif (!in_array($lang, $this->supportedLangArr)) {
            $this->supportedLangArr[] = $lang;
        }
    }

    public function setDefaultLang($lang)
    {
        if (!in_array($lang, $this->supportedLangArr)) {
            return false;
        }
        $this->defaultLang = $lang;
        return true;
    }

    public function convertSupportedLang($language)
    {
        //lang语言标识采用iOS系统的语言标识,即简体中文为zh-Hans,繁体中文为zh-Hant
        $lang = '';
        $segments = explode('_', $language, 2);
        $language = isset($segments[0]) ? $segments[0] : '';
        $locale = isset($segments[1]) ? $segments[1] : '';

        //中文简体、中文繁体进行特殊处理
        if (in_array($language, ['zh', 'zh-Hans', 'zh-Hant'])) {
            if (!$locale) {
                $language = $language == 'zh-Hant' ? 'zh-Hant' : 'zh-Hans';
            } else {
                //台湾、香港、澳门三个地区采用繁体中文,其余地区认为采用简体中文
                $language = in_array($locale, ['TW', 'HK', 'MO']) ? 'zh-Hant' : 'zh-Hans';
            }
        }

        if ($locale && in_array($language . '_' . $locale, $this->supportedLangArr)) {
            $lang = $language . '_' . $locale;
        } elseif (in_array($language, $this->supportedLangArr)) {
            $lang = $language;
        }

        return $lang;
    }
}
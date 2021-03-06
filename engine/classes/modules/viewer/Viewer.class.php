<?php
/*---------------------------------------------------------------------------
 * @Project: Alto CMS
 * @Project URI: http://altocms.com
 * @Description: Advanced Community Engine
 * @Copyright: Alto CMS Team
 * @License: GNU GPL v2 & MIT
 *----------------------------------------------------------------------------
 * Based on
 *   LiveStreet Engine Social Networking by Mzhelskiy Maxim
 *   Site: www.livestreet.ru
 *   E-mail: rus.engine@gmail.com
 *----------------------------------------------------------------------------
 */

// For Smarty
define('DS', '/');

F::IncludeLib('Smarty/libs/Smarty.class.php');

/**
 * Модуль обработки шаблонов используя шаблонизатор Smarty
 *
 * @package engine.modules
 * @since 1.0
 */
class ModuleViewer extends Module {

    /** @var bool Устанавливаем признак предзагрузки (влияет на порядок шатдауна) */
    protected $bPreloaded = true;

    /**
     * Объект Smarty
     *
     * @var Smarty
     */
    protected $oSmarty;

    protected $aPresetTemplateDirs = array();

    /**
     * Коллекция(массив) виджетов
     *
     * @var array
     */
    protected $aWidgets = array();

    /**
     * Признак сортировки виджетов
     *
     * @var bool
     */
    protected $bWidgetsSorted = false;

    /**
     * Массив правил организации виджетов
     *
     * @var array
     */
    protected $aBlockRules = array();

    /**
     * Стандартные настройки вывода js, css файлов
     *
     * @var array
     */
    protected $aFilesDefault = array(
        'js' => array(),
        'css' => array()
    );

    /**
     * Правила переопределение массивов js и css
     *
     * @var array
     */
    protected $aFileRules = array();

    /**
     * Каталог для кешировния js,css файлов
     *
     * @var string
     */
    protected $sCacheDir = '';

    /**
     * Заголовок HTML страницы
     *
     * @var string
     */
    protected $sHtmlTitle;

    /**
     * SEO ключевые слова страницы
     *
     * @var string
     */
    protected $sHtmlKeywords;

    /**
     * SEO описание страницы
     *
     * @var string
     */
    protected $sHtmlDescription;

    /**
     * Разделитель заголовка HTML страницы
     *
     * @var string
     */
    protected $sHtmlTitleSeparation = ' / ';

    /**
     * Альтернативный адрес страницы по RSS
     *
     * @var array
     */
    protected $aHtmlRssAlternate = null;

    /**
     * Указание поисковику основного URL страницы, для борьбы с дублями
     *
     * @var string
     */
    protected $sHtmlCanonical;

    /**
     * Html код для подключения js,css
     *
     * @var array
     */
    protected $aHtmlHeadFiles = array(
        'js' => '',
        'css' => ''
    );

    /**
     * Html-теги, добавляемые в HEAD страницы
     *
     * @var array
     */
    protected $aHtmlHeadTags = array();

    /**
     * Переменные для отдачи при ajax запросе
     *
     * @var array
     */
    protected $aVarsAjax = array();

    /**
     * Определяет тип ответа при ajax запросе
     *
     * @var string
     */
    protected $sResponseAjax = null;

    /**
     * Отправляет специфичный для ответа header
     *
     * @var bool
     */
    protected $bResponseSpecificHeader = true;

    /**
     * Список меню для рендеринга
     *
     * @var array
     */
    protected $aMenu = array();

    /**
     * Скомпилированные меню
     *
     * @var array
     */
    protected $aMenuFetch = array();

    /**
     * Локальный вьюер
     *
     * @var bool
     */
    protected $bLocal = false;

    protected $sSkin;

    /**
     * Константа для компиляции LESS-файлов
     */
    const ALTO_SRC = '___ALTO_SRC___';

    static protected $_renderCount = 0;
    static protected $_renderTime = 0;
    static protected $_renderStart = 0;
    static protected $_preprocessTime = 0;

    static public function GetRenderCount() {

        return self::$_renderCount;
    }

    static public function GetRenderTime() {

        return self::$_renderTime + (self::$_renderStart ? microtime(true) - self::$_renderStart : 0);
    }

    static public function GetPreprocessingTime() {

        return self::$_preprocessTime + self::GetRenderTime();
    }

    static public function GetTotalTime() {

        return self::GetPreprocessingTime() + self::GetRenderTime();
    }

    /**
     * Инициализация модуля
     *
     */
    public function Init($bLocal = false) {

        $this->Hook_Run('viewer_init_start', compact('bLocal'));

        $this->bLocal = (bool)$bLocal;

        // * Создаём объект Smarty
        $this->oSmarty = $this->CreateSmartyObject();

        $this->InitSkin($this->bLocal);

        // * Устанавливаем необходимые параметры для Smarty
        $this->oSmarty->compile_check = Config::Get('smarty.compile_check');
        $this->oSmarty->force_compile = Config::Get('smarty.force_compile');

        // * Подавляем NOTICE ошибки - в этом вся прелесть смарти )
        $this->oSmarty->error_reporting = error_reporting() & ~E_NOTICE;

        // * Заголовок HTML страницы
        $this->sHtmlTitle = Config::Get('view.name');

        // * SEO ключевые слова страницы
        $this->sHtmlKeywords = Config::Get('view.keywords');

        // * SEO описание страницы
        $this->sHtmlDescription = Config::Get('view.description');

        // * Пустой вызов только для того, чтоб модуль Message инициализировался, если еще не
        $this->Message_IsInit();

        $this->sCacheDir = Config::Get('path.runtime.dir');
    }

    protected function InitSkin($bLocal = false) {

        $this->sSkin = Config::Get('view.skin');
        if (!$bLocal) {
            // * Load skin config
            $s = Config::Get('view.skin');
            $aConfig = Config::Get('skin.' . $this->sSkin . '.config');
            if (F::File_Exists($sFile = Config::Get('path.smarty.template') . '/settings/config/config.php')) {
                $aConfig = F::Array_Merge(F::IncludeFile($sFile, false, true), $aConfig);
            }
            Config::SetLevel(Config::LEVEL_SKIN);
            if ($aConfig) {
                Config::Load($aConfig, false);
            }
            // * Load skin widgets
            if (F::File_Exists($sFile = Config::Get('path.smarty.template') . '/settings/config/widgets.php')) {
                $aSkinWidgets = F::IncludeFile($sFile, false, true);
                if (isset($aSkinWidgets['widgets']) && is_array($aSkinWidgets['widgets']) && count($aSkinWidgets['widgets'])) {
                    $aWidgets = array_merge(Config::Get('widgets'), $aSkinWidgets['widgets']);
                    Config::Set('widgets', $aWidgets);
                }
            }
        }

        // * Папки расположения шаблонов по умолчанию
        if (Config::Get('path.smarty.template_seek')) {
            $this->oSmarty->setTemplateDir(F::File_NormPath(F::Str2Array(Config::Get('path.smarty.template_seek'))));
        } else {
            $this->oSmarty->setTemplateDir(F::File_NormPath(F::Str2Array(Config::Get('path.smarty.template'))));
        }

        // * Для каждого скина устанавливаем свою директорию компиляции шаблонов
        $sCompilePath = F::File_NormPath(Config::Get('path.smarty.compiled'));
        F::File_CheckDir($sCompilePath);
        $this->oSmarty->setCompileDir($sCompilePath);
        $this->oSmarty->setCacheDir(Config::Get('path.smarty.cache'));
        $this->oSmarty->addPluginsDir(array(Config::Get('path.smarty.plug'), 'plugins'));
        $this->oSmarty->default_template_handler_func = array($this, 'SmartyDefaultTemplateHandler');

        // * Параметры кеширования, если заданы
        if (Config::Get('smarty.cache_lifetime')) {
            $this->oSmarty->caching = Smarty::CACHING_LIFETIME_SAVED;
            $this->oSmarty->cache_lifetime = F::ToSeconds(Config::Get('smarty.cache_lifetime'));
        }

        // * Загружаем локализованные тексты
        $this->Assign('aLang', $this->Lang_GetLangMsg());
        $this->Assign('oLang', $this->Lang_Dictionary());

        // * Загружаем переменные из конфига
        if (($aVars = Config::Get('view.assign')) && is_array($aVars)) {
            foreach ($aVars as $sKey => $sVal) {
                $this->Assign($sKey, $sVal);
            }
        }

        // * Получаем настройки JS-, CSS-файлов
        $this->InitFileParams();
    }

    /**
     * Возвращает локальную копию модуля
     *
     * @return ModuleViewer
     */
    public function GetLocalViewer() {

        $sClass = $this->Plugin_GetDelegate('module', __CLASS__);

        $oViewerLocal = new $sClass(Engine::getInstance());
        $oViewerLocal->Init(true);
        $oViewerLocal->VarAssign();
        $oViewerLocal->Assign('aLang', $this->Lang_GetLangMsg());
        return $oViewerLocal;
    }

    /**
     * Возвращает версию Smarty
     *
     * @return string|null
     */
    public function GetSmartyVersion() {

        $sSmartyVersion = null;
        if (property_exists($this->oSmarty, '_version')) {
            $sSmartyVersion = $this->oSmarty->_version;
        } elseif (defined('Smarty::SMARTY_VERSION')) {
            $sSmartyVersion = Smarty::SMARTY_VERSION;
        }
        return $sSmartyVersion;
    }

    /**
     * Возвращает путь к общей asset-папке
     *
     * @return string
     */
    public function GetAssetDir() {

        return F::File_NormPath(Config::Get('path.runtime.dir') . 'assets/');
    }

    /**
     * Возвращает URL к общей asset-папке
     *
     * @return string
     */
    public function GetAssetUrl() {

        return Config::Get('path.runtime.url') . 'assets/';
    }

    /**
     * Выполняет загрузку необходимых (возможно даже системных :)) переменных в шаблонизатор
     */
    public function VarAssign() {

        // * Загружаем весь $_REQUEST, предварительно обработав его функцией func_htmlspecialchars()
        $aRequest = $_REQUEST;
        F::HtmlSpecialChars($aRequest);
        $this->Assign('_aRequest', $aRequest);

        // * Параметры стандартной сессии
        // TODO: Убрать! Не должно этого быть на страницах сайта
        $this->Assign('_sPhpSessionName', session_name());
        $this->Assign('_sPhpSessionId', session_id());

        // * Загружаем объект доступа к конфигурации
        // * Перенесено в PluginLs_Viewer
        // TODO: Пока здесь, но надо убирать - незачем таскать в шаблоны объект, если можно в них к стат.классу напрямую обращаться
        $this->Assign('oConfig', Config::getInstance());

        // * Загружаем роутинг с учетом правил rewrite
        $aRouter = array();
        $aPages = Config::Get('router.page');

        if (!$aPages || !is_array($aPages)) {
            throw new Exception('Router rules is underfined.');
        }
        foreach ($aPages as $sPage => $aAction) {
            $aRouter[$sPage] = Router::GetPath($sPage);
        }
        $this->Assign('aRouter', $aRouter);

        // * Загружаем виджеты
        $this->Assign('aWidgets', $this->GetWidgets());

        // * Загружаем HTML заголовки
        $this->Assign('sHtmlTitle', htmlspecialchars($this->sHtmlTitle));
        $this->Assign('sHtmlKeywords', htmlspecialchars($this->sHtmlKeywords));
        $this->Assign('sHtmlDescription', htmlspecialchars($this->sHtmlDescription));
        $this->Assign('aHtmlHeadFiles', $this->aHtmlHeadFiles);
        $this->Assign('aHtmlRssAlternate', $this->aHtmlRssAlternate);
        $this->Assign('sHtmlCanonical', $this->sHtmlCanonical);
        $this->Assign('aHtmlHeadTags', $this->aHtmlHeadTags);

        $this->Assign('aJsAssets', $this->ViewerAsset_GetPreparedAssetLinks());

        // * Загружаем список активных плагинов
        $aPlugins = $this->oEngine->GetPlugins();
        $this->Assign('aPluginActive', array_fill_keys(array_keys($aPlugins), true));

        // * Загружаем пути до шаблонов плагинов
        $aTemplateWebPathPlugin = array();
        $aTemplatePathPlugin = array();
        foreach ($aPlugins as $sPlugin => $oPlugin) {
            $sDir = Plugin::GetTemplateDir(get_class($oPlugin));
            $this->oSmarty->addTemplateDir($sDir, $oPlugin->GetName(false));
            $aTemplatePathPlugin[$sPlugin] = $sDir;
            $aTemplateWebPathPlugin[$sPlugin] = Plugin::GetTemplateUrl(get_class($oPlugin));
        }
        if (E::ActivePlugin('ls')) {
            // LS-compatible //
            $this->Assign('aTemplateWebPathPlugin', $aTemplateWebPathPlugin);
            $this->Assign('aTemplatePathPlugin', $aTemplatePathPlugin);
        }

        $sSkinTheme = Config::Get('view.theme');
        if (!$sSkinTheme) {
            $sSkinTheme = 'default';
        }
        // Проверка существования темы
        if ($this->CheckTheme($sSkinTheme)) {
            $this->oSmarty->compile_id = $sSkinTheme;
        }
        $this->Assign('sSkinTheme', $sSkinTheme);
    }

    /**
     * Загружаем содержимое menu-контейнеров
     */
    protected function MenuVarAssign() {

        $this->Assign('aMenuFetch', $this->aMenuFetch);
        $this->Assign('aMenuContainers', array_keys($this->aMenu));
    }

    /**
     * Выводит на экран (в браузер) обработанный шаблон
     *
     * @param string $sTemplate - Шаблон для вывода
     */
    public function Display($sTemplate) {

        // Проверка существования папки с текущим скином
        $this->CheckSkin();

        if ($this->sResponseAjax) {
            $this->DisplayAjax($this->sResponseAjax);
        }
        /*
         * Если шаблон найден то выводим, иначе - ошибка
         * Но предварительно проверяем наличие делегата
         */
        if ($sTemplate) {
            $sTemplate = $this->Plugin_GetDelegate('template', $sTemplate);
            if ($this->TemplateExists($sTemplate, true)) {
                // Установка нового secret key непосредственно перед рендерингом
                $this->Security_SetSessionKey();

                self::$_renderCount++;
                self::$_renderStart = microtime(true);
                $this->oSmarty->display($sTemplate);
                self::$_renderTime += (microtime(true) - self::$_renderStart);
                self::$_renderStart = 0;
            }
        }
    }

    /**
     * Возвращает отрендеренный шаблон
     *
     * @param   string $sTemplate    - Шаблон для рендеринга
     * @param   array  $aOptions     - Опции рендеринга
     *
     * @return  string
     */
    public function Fetch($sTemplate, $aOptions = array()) {

        // * Проверяем наличие делегата
        $sTemplate = $this->Plugin_GetDelegate('template', $sTemplate);
        if ($this->TemplateExists($sTemplate, true)) {
            // Если задаются локальные параметры кеширования, то сохраняем общие
            if (isset($aOptions['cache'])) {
                $nOldCaching = $this->oSmarty->caching;
                $nOldCacheLifetime = $this->oSmarty->cache_lifetime;

                $this->oSmarty->caching = Smarty::CACHING_LIFETIME_SAVED;
                if ($aOptions['cache'] === false) {
                    // Отключаем кеширование
                    $this->oSmarty->cache_lifetime = 0;
                } elseif (isset($aOptions['cache']['time'])) {
                    if ($aOptions['cache']['time'] == -1) {
                        // Задаем бессрочное кеширование
                        $this->oSmarty->cache_lifetime = -1;
                    } elseif ($aOptions['cache']['time']) {
                        // Задаем время кеширования
                        $this->oSmarty->cache_lifetime = F::ToSeconds($aOptions['cache']['time']);
                    } else {
                        // Отключаем кеширование
                        $this->oSmarty->cache_lifetime = 0;
                    }
                }
            }

            self::$_renderCount++;
            self::$_renderStart = microtime(true);

            $sContent = $this->oSmarty->fetch($sTemplate);

            self::$_renderTime += (microtime(true) - self::$_renderStart);
            self::$_renderStart = 0;

            if (isset($aOptions['cache'])) {
                $this->oSmarty->caching = $nOldCaching;
                $this->oSmarty->cache_lifetime = $nOldCacheLifetime;
            }

            return $sContent;
        }
    }

    /**
     * Возвращает отрендеренный шаблон виджета
     *
     * @param string $sTemplate - Шаблон для рендеринга
     * @param array  $aVars     - Переменные для локального рендеринга
     * @param array  $aOptions  - Опции рендеринга
     *
     * @return string
     */
    public function FetchWidget($sTemplate, $aVars = array(), $aOptions = array()) {

        // * Проверяем наличие делегата
        $sDelegateTemplate = $this->Plugin_GetDelegate('template', $sTemplate);
        if ($sDelegateTemplate == $sTemplate && !$this->TemplateExists($sTemplate)) {
            $sWidgetTemplate = 'widgets/widget.' . $sTemplate;
            $sWidgetTemplate = $this->Plugin_GetDelegate('template', $sWidgetTemplate);
            if ($this->TemplateExists($sWidgetTemplate)) {
                $sRenderTemplate = $sWidgetTemplate;
            }

            if (!$sRenderTemplate) {
                // * LS-compatible *//
                $sWidgetTemplate = 'blocks/block.' . $sTemplate;
                $sWidgetTemplate = $this->Plugin_GetDelegate('template', $sWidgetTemplate);
                if ($this->TemplateExists($sWidgetTemplate)) {
                    $sRenderTemplate = $sWidgetTemplate;
                }
            }

            if (!$sRenderTemplate) {
                $sRenderTemplate = $sWidgetTemplate;
            }
        }
        $oSmarty = $this->GetSmartyObject();
        $oTpl = $oSmarty->createTemplate($sRenderTemplate, $oSmarty);
        $oTpl->assign($aVars);
        return $oTpl->Fetch();
    }

    /**
     * Ответ на ajax запрос
     *
     * @param string $sType - Варианты: json, jsonIframe, jsonp
     */
    public function DisplayAjax($sType = 'json') {

        $aHeaders = array();
        $sOutput = '';

        // * Загружаем статус ответа и сообщение
        $bStateError = false;
        $sMsgTitle = '';
        $sMsg = '';
        $aMsgError = $this->Message_GetError();
        $aMsgNotice = $this->Message_GetNotice();
        if (count($aMsgError) > 0) {
            $bStateError = true;
            $sMsgTitle = $aMsgError[0]['title'];
            $sMsg = $aMsgError[0]['msg'];
        } elseif (count($aMsgNotice) > 0) {
            $sMsgTitle = $aMsgNotice[0]['title'];
            $sMsg = $aMsgNotice[0]['msg'];
        }
        $this->AssignAjax('sMsgTitle', $sMsgTitle);
        $this->AssignAjax('sMsg', $sMsg);
        $this->AssignAjax('bStateError', $bStateError);
        if ($sType == 'json') {
            if ($this->bResponseSpecificHeader && !headers_sent()) {
                $aHeaders[] = 'Content-type: application/json';
            }
            $sOutput = F::jsonEncode($this->aVarsAjax);
        } elseif ($sType == 'jsonIframe') {
            // Оборачивает json в тег <textarea>, это не дает браузеру выполнить HTML, который вернул iframe
            if ($this->bResponseSpecificHeader && !headers_sent()) {
                $aHeaders[] = 'Content-type: application/json';
            }

            // * Избавляемся от бага, когда в возвращаемом тексте есть &quot;
            $sOutput = '<textarea>' . htmlspecialchars(F::jsonEncode($this->aVarsAjax)) . '</textarea>';
        } elseif ($sType == 'jsonp') {
            if ($this->bResponseSpecificHeader && !headers_sent()) {
                $aHeaders[] = 'Content-type: application/json';
            }
            $sOutput = getRequest('jsonpCallback', 'callback') . '(' . F::jsonEncode($this->aVarsAjax) . ');';
        }
        if ($aHeaders) {
            foreach ($aHeaders as $sHeader) {
                header($sHeader);
            }
        }
        echo $sOutput;
        exit();
    }

    /**
     * Создает и возвращает объект Smarty
     *
     * @return Smarty
     */
    public function CreateSmartyObject() {

        return new Smarty();
    }

    /**
     * Возвращает объект Smarty
     *
     * @return Smarty
     */
    public function GetSmartyObject() {

        return $this->oSmarty;
    }

    /**
     * Возвращает скин
     *
     * @param bool $bSiteSkin - если задано, то возвращает скин, установленный для сайта (игнорирует скин экшена)
     *
     * @return  string
     */
    public function GetSkin($bSiteSkin = true) {

        if ($bSiteSkin) {
            return Config::Get('view.skin', Config::LEVEL_CUSTOM);
        } else {
            return Config::Get('view.skin');
        }
    }

    /**
     * Возвращает путь к папке скина
     *
     * @param bool $bSiteSkin - Если задано, то возвращает скин, установленный для сайта (игнорирует скин экшена)
     *
     * @return  string
     */
    public function GetTemplateDir($bSiteSkin = true) {

        if ($bSiteSkin) {
            return F::File_NormPath(Config::Get('path.skins.dir') . '/' . $this->GetSkin(true));
        } else {
            return Config::Get('path.smarty.template');
        }
    }

    /**
     * Возвращает тип отдачи контекта
     *
     * @return string
     */
    public function GetResponseAjax() {

        return $this->sResponseAjax;
    }

    /**
     * Устанавливает тип отдачи при ajax запросе, если null то выполняется обычный вывод шаблона в браузер
     *
     * @param string $sResponseAjax    Тип ответа
     * @param bool $bResponseSpecificHeader    Установливать специфичные тиру заголовки через header()
     * @param bool $bValidate    Производить или нет валидацию формы через {@link Security::ValidateSendForm}
     */
    public function SetResponseAjax($sResponseAjax = 'json', $bResponseSpecificHeader = true, $bValidate = true) {

        // Для возможности кросс-доменных запросов
        if ($sResponseAjax != 'jsonp' && $bValidate) {
            $this->Security_ValidateSendForm();
        }
        $this->sResponseAjax = $sResponseAjax;
        $_REQUEST['ALTO_AJAX'] = $sResponseAjax;
        $this->bResponseSpecificHeader = $bResponseSpecificHeader;
    }

    /**
     * Загружает переменную в шаблон
     *
     * @param string $sName    Имя переменной в шаблоне
     * @param mixed $value    Значение переменной
     */
    public function Assign($sName, $value) {

        $this->oSmarty->assign($sName, $value);
    }

    /**
     * Загружаем переменную в ajax ответ
     *
     * @param string $sName    Имя переменной в шаблоне
     * @param mixed $value    Значение переменной
     */
    public function AssignAjax($sName, $value) {

        $this->aVarsAjax[$sName] = $value;
    }

    /**
     * Проверяет существование шаблона
     *
     * @param   string $sTemplate   - Шаблон
     * @param   bool   $bException  - Нужно ли генерить ошибку, если шаблон не найден
     *
     * @return  bool
     */
    public function TemplateExists($sTemplate, $bException = false) {

        $bResult = $this->oSmarty->templateExists($sTemplate);
        if (!$bResult && $bException) {
            $sMessage = 'Can not find the template "' . $sTemplate . '" in skin "' . Config::Get('view.skin') . '"';
            if ($aTpls = $this->GetSmartyObject()->template_objects) {
                if (is_array($aTpls)) {
                    $sMessage .= ' (from: ';
                    foreach($aTpls as $oTpl) {
                        $sMessage .= $oTpl->template_resource . '; ';
                    }
                    $sMessage .= ')';
                }
            }

            // записываем доп. информацию - пути к шаблонам Smarty
            $sErrorInfo = 'Template Dirs: ' . implode('; ', $this->oSmarty->getTemplateDir());
            return $this->_error($sMessage, $sErrorInfo);
        }
        return $bResult ? $sTemplate : $bResult;
    }

    /**
     * Проверяет существование скина/темы
     *
     * @param   string $sSkin      - Скин (или скин/тема)
     * @param   bool   $bException - Нужно ли генерить ошибку, если скин не найден
     *
     * @return  bool
     */
    public function SkinExists($sSkin, $bException = false) {

        if (strpos($sSkin, '/') !== false) {
            // Разделяем сам скин и тему
            list($sSkin, $sTheme) = explode('/', $sSkin, 2);
            if (!$sSkin) {
                $sSkin = Config::Get('view.skin');
            }
        } else {
            $sTheme = null;
        }
        $sCheckDir = Config::Get('path.skin.dir');
        // Если проверяется не текущий скин, то корректируем путь
        if ($sSkin != Config::Get('view.skin')) {
            $sCheckDir = str_replace('/' . Config::Get('view.skin') . '/', '/' . $sSkin . '/', $sCheckDir);
        }
        // Проверяем только скин или тему скина
        if ($sTheme) {
            $sCheckDir .= 'themes/' . $sTheme . '/';
        }
        $bResult = is_dir($sCheckDir);
        if (!$bResult && $bException) {
            if ($sTheme) {
                $sMessage = 'Can not find the theme "' . $sTheme . '" of skin "' . $sSkin . '"';
                // записываем доп. информацию - пути к шаблонам Smarty
                $sErrorInfo = 'Theme dir: ' . $sCheckDir;
            } else {
                $sMessage = 'Can not find the skin "' . $sSkin . '"';
                // записываем доп. информацию - пути к шаблонам Smarty
                $sErrorInfo = 'Skin dir: ' . $sCheckDir;
            }
            return $this->_error($sMessage, $sErrorInfo);
        }
        return $bResult;
    }

    /**
     * Проверяет существование папки текущего скина
     *
     * @return  bool
     */
    public function CheckSkin() {

        if (!$this->SkinExists(Config::Get('view.skin'), true)) {
            die('Please check skin folder');
        }
    }

    /**
     * Проверяет существование папки конкретной темы текущего скина
     *
     * @param   string $sTheme
     *
     * @return  bool
     */
    public function CheckTheme($sTheme) {

        return $this->SkinExists(Config::Get('view.skin') . '/' . $sTheme, false);
    }

    /**
     * Генерация ошибки "Шаблон не найден"
     *
     * @param   string $sMessage   - сообщение об ошибке
     * @param   string $sErrorInfo - доп. информация для записи в лог
     *
     * @throws  Exception
     */
    protected function _error($sMessage, $sErrorInfo = null) {

        $oException = new Exception($sMessage);
        $oException->sAdditionalInfo = $sErrorInfo;
        throw $oException;
    }

    /**
     * Добавляет виджет для отображения
     *
     * @param   string $sGroup     Группа виджетов
     * @param   string $sName      Название виджета - Можно передать название виджета, тогда для обработки данных
     *          будет вызван обработчик из /classes/widgets/, либо передать путь до шаблона, тогда будет выполнено
     *          обычное подключение шаблона
     * @param   array $aParams     Параметры виджета, которые будут переданы обработчику
     * @param   int $iPriority    Приоритет, согласно которому сортируются виджеты
     * @return  bool
     */
    public function AddWidget($sGroup, $sName, $aParams = array(), $iPriority = 0) {
        /**
         * Если не указана директория шаблона, но указана приналежность к плагину,
         * то "вычисляем" правильную директорию
         */
        if (!isset($aParams['dir']) && isset($aParams['plugin'])) {
            $aParams['dir'] = Plugin::GetTemplatePath($aParams['plugin']);
        }

        $aWidgetData = array(
            'name' => $sName,
            'params' => $aParams,
            'priority' => $iPriority,
        );

        // Создавать виджет нужно до определения его типа, чтоб ID виджета сформировался правильно
        $oWidget = Engine::GetEntity('Widget', $aWidgetData);

        $sDir = isset($aParams['dir']) ? $aParams['dir'] : null;
        $sPlugin = isset($aParams['plugin']) ? $aParams['plugin'] : null;
        // Если смогли определить тип виджета то добавляем его
        $sType = $this->DefineWidgetType($sName, $sDir, $sPlugin);
        if ($sType == 'undefined') {
            return false;
        }

        $oWidget->setType($sType);
        if ($sType == 'template') {
            // в $sName возвращается найденный шаблон
            $oWidget->setTemplate($sName);
            if ($sName != $aWidgetData['name']) {
                $oWidget->setName($sName);
            }
        }

        // Добавляем виджет в группу
        $this->aWidgets[$sGroup][] = $oWidget;

        // Сбрасываем флаг сортировки
        $this->bWidgetsSorted = false;
        return true;
    }

    /**
     * Добавляет список виджетов
     *
     * @param   string $sGroup          - Группа виджетов
     * @param   array  $aWidgets        - Список добавляемых виджетов
     * @param   bool   $ClearWidgets    - Очищать или нет список виджетов, добавленных до этого, в данной группе
     * <pre>
     * $this->Viewer_AddWidgets('right', array('tags', array('widget'=>'stream', 'priority'=>100)));
     * </pre>
     */
    public function AddWidgets($sGroup, $aWidgets, $ClearWidgets = true) {

        // * Удаляем ранее добавленые виджеты
        if ($ClearWidgets) {
            $this->ClearWidgets($sGroup);
        }
        foreach ($aWidgets as $sWidget) {
            if (is_array($sWidget)) {
                $this->AddWidget(
                    $sGroup,
                    $sWidget['widget'],
                    isset($sWidget['params']) ? $sWidget['params'] : array(),
                    isset($sWidget['priority']) ? $sWidget['priority'] : 5
                );
            } else {
                $this->AddWidget($sGroup, $sWidget);
            }
        }
    }

    /**
     * Удаляет виджеты заданной группы
     *
     * @param   string $sGroup
     */
    public function ClearWidgets($sGroup) {

        $this->aWidgets[$sGroup] = array();
    }

    /**
     * Удаляет виджеты из всех групп
     *
     */
    public function ClearAllWidgets() {

        foreach ($this->aWidgets as $sGroup => $aWidget) {
            $this->aWidgets[$sGroup] = array();
        }
    }

    /**
     * Возвращает список виджетов
     *
     * @param bool $bSort - Выполнять или нет сортировку виджетов
     *
     * @return array
     */
    public function GetWidgets($bSort = true) {

        if ($bSort && !$this->bWidgetsSorted) {
            $this->SortWidgets();
        }
        return $this->aWidgets;
    }

    /**
     * Определяет тип виджета
     *
     * @param   string $sName   - Название виджета
     * @param   string $sDir    - Путь до шаблона виджета, обычно определяется автоматически для плагинов,
     *                            если передать параметр 'plugin'=>'myplugin'
     * @param   string $sPlugin - Имя плагина виджета, берется из параметра 'plugin'=>'myplugin'
     *
     * @return  string ('exec', 'block', 'template', 'undefined')
     */
    protected function DefineWidgetType(&$sName, $sDir = null, $sPlugin = null) {

        // Добавляем проверку на рсширение, чтобы не делать лишних телодвижений
        $bTpl = (substr($sName, -4) == '.tpl');
        if (!$bTpl) {
            if ($this->Widget_FileClassExists($sName, $sPlugin)) {
                // Если найден файл класса виджета, то это исполняемый виджет
                return 'exec';
            }
        }
        if (strpos($sName, 'block.') && ($sTplName = $this->TemplateExists(is_null($sDir) ? $sName : rtrim($sDir, '/') . '/' . ltrim($sName, '/')))) {
            // * LS-compatible * //
            $sName = $sTplName;
            return 'template';
        } elseif ($sTplName = $this->TemplateExists(is_null($sDir) ? $sName : rtrim($sDir, '/') . '/' . ltrim($sName, '/'))) {
            // Если найден шаблон, то считаем, что это шаблонный виджет
            $sName = $sTplName;
            return 'template';
        } elseif ($sTplName = $this->TemplateExists(is_null($sDir) ? 'widgets/widget.' . $sName : rtrim($sDir, '/') . '/widgets/widget.' . $sName)) {
            // Если найден шаблон вида widget.name.tpl то считаем что тип 'template'
            $sName = $sTplName;
            return 'template';
        }

        // Считаем что тип не определен
        F::SysWarning('Can not define type of widget "' . $sName . '"');
        return 'undefined';
    }


    /**
     * Вспомагательная функция для сортировки виджетов:
     *  - первыми идут с приоритетом 'top'
     *  - потом те, у кого выше приоритет
     *  - потом те, которые были раньше добавлены
     *
     * @param  array $a
     * @param  array $b
     * @return int
     */
    protected function _SortWidgetsCompare($a, $b) {

        if ($a->getPriority() == $b->getPriority()) {
            return $a->getOrder() - $b->getOrder();
        } elseif ($a->isTop()) {
            return 1;
        } elseif ($b->isTop()) {
            return -1;
        }
        if ($a->getPriority() < $b->getPriority()) {
            return -1;
        }
        return 1;
    }

    /**
     * Сортируем виджеты по приоритетам
     *
     */
    protected function SortWidgets() {

        if ($this->aWidgets)
            foreach ($this->aWidgets as $sGroup => $aWidgets) {
                if (sizeof($aWidgets)) {
                    uasort($aWidgets, array($this, '_SortWidgetsCompare'));
                    $this->aWidgets[$sGroup] = array_reverse($aWidgets);
                }
            }
        $this->bWidgetsSorted = true;
    }

    protected function MakeWidgetsLists() {

        $aWidgets = $this->Widget_GetWidgets();
        if ($aWidgets) {
            foreach ($aWidgets as $oWidget) {
                if ($sGroup = $oWidget->getGroup()) {
                    // Свойство "order" потребуется для сортировки по поядку добавления, если не задан приоритет
                    if (!$oWidget->getOrder()) {
                        $oWidget->setOrder(isset($this->aWidgets[$sGroup]) ? sizeof($this->aWidgets[$sGroup]) : 0);
                    }
                    if (is_null($oWidget->getType())) {
                        $sName = $oWidget->getName();
                        $sType = $this->DefineWidgetType($sName, $oWidget->getDir(), $oWidget->getPluginId());

                        $oWidget->setType($sType);
                        if ($sType == 'template') {
                            $oWidget->setName($sName);
                            /*
                            $aParams = $oWidget->getParams();
                            if (!isset($aParams['dir'])) {
                                $aParams['dir'] = $oWidget->getDir();
                                $oWidget->setParams($aParams);
                            }
                            */
                        }
                        /* LS-compatible */
                        if (!$oWidget->getParam('plugin') && $oWidget->getPluginId()) {
                            $oWidget->setParam('plugin', $oWidget->getPluginId());
                        }
                    }
                }
                // Список всех виджетов, в т.ч. и без группы
                $this->aWidgets['_all_'][$oWidget->GetId()] = $oWidget;
                // Список виджетов с разбивкой по круппам (чтоб не дублировать, сохраняем ссылку на элемент в общем списке)
                $this->aWidgets[$sGroup][$oWidget->GetId()] = & $this->aWidgets['_all_'][$oWidget->GetId()];
            }
            $this->SortWidgets();
        }
    }

    /**
     * Инициализирует параметры вывода js- и css- файлов
     */
    protected function InitFileParams() {

        $this->ViewerAsset_AssetMake(Config::Get('head.default'));
    }


    /**
     * Добавляет js-файл в конец списка
     *
     * @param string $sFile    - js-файл
     * @param array  $aParams  - Параметры, например, можно указать параметр 'name'=>'jquery.plugin.foo'
     *                           для исключения повторного добавления файла с таким именем
     * @param bool   $bReplace - заменять ли файл с таким же именем, если он уже есть
     *
     * @return bool
     */
    public function AppendScript($sFile, $aParams = array(), $bReplace = false) {

        return $this->ViewerAsset_AppendJs($sFile, $aParams, $bReplace);
    }

    /**
     * Добавляет js-файл в начало списка
     *
     * @param string $sFile    - js-файл
     * @param array  $aParams  - Параметры, например, можно указать параметр 'name'=>'jquery.plugin.foo'
     *                           для исключения повторного добавления файла с таким именем
     * @param bool   $bReplace - заменять ли файл с таким же именем, если он уже есть
     *
     * @return bool
     */
    public function PrependScript($sFile, $aParams = array(), $bReplace = false) {

        return $this->ViewerAsset_PrependJs($sFile, $aParams, $bReplace);
    }

    /**
     * Добавляет css-файл в конец списка
     *
     * @param string $sFile    - css-файл стилей
     * @param array  $aParams  - Параметры, например, можно указать параметр 'name'=>'blueprint'
     *                           для исключения повторного добавления файла с таким именем
     * @param bool   $bReplace - заменять ли файл с таким же именем, если он уже есть
     *
     * @return bool
     */
    public function AppendStyle($sFile, $aParams = array(), $bReplace = false) {

        return $this->ViewerAsset_AppendCss($sFile, $aParams, $bReplace);
    }

    /**
     * Добавляет css-файл в начало списка
     *
     * @param string $sFile      - css-файл стилей
     * @param array  $aParams    - Параметры, например, можно указать параметр 'name'=>'blueprint'
     *                             для исключения повторного добавления файла с таким именем
     * @param bool   $bReplace   - заменять ли файл с таким же именем, если он уже есть
     *
     * @return bool
     */
    public function PrependStyle($sFile, $aParams = array(), $bReplace = false) {

        return $this->ViewerAsset_PrependCss($sFile, $aParams, $bReplace);
    }

    /**
     * Готовит для подключения js-файл
     *
     * @param string $sFile
     * @param array  $aParams
     * @param bool   $bReplace
     */
    public function PrepareScript($sFile, $aParams = array(), $bReplace = false) {

        if (is_array($aParams)) {
            $aParams['prepare'] = true;
        } else {
            $aParams = array('prepare' => true);
        }
        return $this->ViewerAsset_AppendJs($sFile, $aParams, $bReplace);
    }

    /**
     * Готовит для подключения css-файл
     *
     * @param string $sFile
     * @param array  $aParams
     * @param bool   $bReplace
     */
    public function PrepareStyle($sFile, $aParams = array(), $bReplace = false) {

        if (is_array($aParams)) {
            $aParams['prepare'] = true;
        } else {
            $aParams = array('prepare' => true);
        }
        return $this->ViewerAsset_AppendCss($sFile, $aParams, $bReplace);
    }

    /**
     * Строит массив для подключения css и js,
     * преобразовывает их в строку для HTML
     *
     */
    protected function BuildHeadFiles() {

        $sPath = Router::GetPathWebCurrent();

        // * По умолчанию имеем дефолтные настройки
        $aFiles = $this->aFilesDefault;

        $this->aFileRules = Config::Get('head.rules');
        foreach ((array)$this->aFileRules as $sName => $aRule) {
            if (!$aRule['path']) continue;

            foreach ((array)$aRule['path'] as $sRulePath) {
                $sPattern = "~" . str_replace(array('/', '*'), array('\/', '\w+'), $sRulePath) . "~";
                if (preg_match($sPattern, $sPath)) {

                    // * Преобразование JS
                    if (isset($aRule['js']['empty']) && $aRule['js']['empty']) {
                        $this->ViewerAsset_ClearJs();
                    }
                    if (isset($aRule['js']['exclude']) && is_array($aRule['js']['exclude'])) {
                        $this->ViewerAsset_ExcludeJs($aRule['js']['exclude']);
                    }
                    if (isset($aRule['js']['include']) && is_array($aRule['js']['include'])) {
                        $this->ViewerAsset_AddJsFiles($aRule['js']['exclude']);
                    }

                    // * Преобразование CSS
                    if (isset($aRule['css']['empty']) && $aRule['css']['empty']) {
                        $this->ViewerAsset_ClearCss();
                    }
                    if (isset($aRule['css']['exclude']) && is_array($aRule['css']['exclude'])) {
                        $this->ViewerAsset_ExcludeCss($aRule['js']['exclude']);
                    }
                    if (isset($aRule['css']['include']) && is_array($aRule['css']['include'])) {
                        $this->ViewerAsset_AddCssFiles($aRule['js']['exclude']);
                    }

                    // * Продолжаем поиск
                    if (isset($aRule['stop'])) {
                        break(2);
                    }
                }
            }
        }

        $this->ViewerAsset_Prepare();


        // * Объединяем файлы в наборы
        $aHeadFiles = array('js' => array(), 'css' => array());

        // * Получаем HTML код
        $aHtmlHeadFiles = $this->BuildHtmlHeadFiles($aHeadFiles);
        $this->SetHtmlHeadFiles($aHtmlHeadFiles);
    }


    /**
     * Аналог realpath + обработка URL
     *
     * @param string $sPath
     * @return string
     */
    protected function GetRealpath($sPath) {

        if (preg_match("@^(http|https):@", $sPath)) {
            $aUrl = parse_url($sPath);
            $sPath = $aUrl['path'];

            $aParts = array();
            $sPath = preg_replace('~/\./~', '/', $sPath);
            foreach (explode('/', preg_replace('~/+~', '/', $sPath)) as $sPart) {
                if ($sPart === "..") {
                    array_pop($aParts);
                } elseif ($sPart != "") {
                    $aParts[] = $sPart;
                }
            }
            return ((array_key_exists('scheme', $aUrl)) ? $aUrl['scheme'] . '://' . $aUrl['host'] : "") . "/" . implode("/", $aParts);
        } else {
            return realpath($sPath);
        }
    }

    /**
     * Преобразует абсолютный путь к файлу в WEB-вариант
     *
     * @param  string $sFile    Серверный путь до файла
     * @return string
     */
    protected function GetWebPath($sFile) {

        return F::File_Dir2Url($sFile);
    }

    /**
     * Преобразует WEB-путь файла в серверный вариант
     *
     * @param  string $sFile    Web путь до файла
     * @return string
     */
    protected function GetServerPath($sFile) {

        return F::File_Url2Dir($sFile);
    }

    /**
     * Строит массив HTML-ссылок на ресурсы
     *
     * @param $aHeadFiles
     * @return array
     */
    protected function BuildHtmlHeadFiles($aHeadFiles) {

        foreach($aHeadFiles as $sType => $aFiles) {
            $aHeaderLinks = $this->ViewerAsset_BuildHtmlLinks($sType);
            if (isset($aHeaderLinks[$sType]) && $aHeaderLinks[$sType]) {
                $aHeadFiles[$sType] = join(PHP_EOL, $aHeaderLinks[$sType]);
            } else {
                $aHeadFiles[$sType] = '';
            }
        }
        return $aHeadFiles;
    }

    /**
     * Устанавливает список файлов для вывода в хидере страницы
     *
     * @param array $aText    Список файлов
     */
    public function SetHtmlHeadFiles($aText) {

        $aCfg = array(
            'url' => array(
                'root' => Config::Get('path.root.url'),
            ),
            'assets' => $this->ViewerAsset_GetPreparedAssetLinks(),
            'lang' => Config::Get('lang.current'),
            'wysiwyg' => Config::Get('view.wysiwyg'),
        );

        $sScript = 'var ls = ls || { };' . PHP_EOL;
        $sScript .= 'ls.cfg = ' . json_encode($aCfg) . ';' . PHP_EOL;
        $sScript = '<script>' . $sScript . '</script>' . PHP_EOL;

        if (isset($aText['js'])) {
            $aText['js'] = $sScript . $aText['js'];
        } else {
            $aText['js'] = $sScript;
        }
        $this->aHtmlHeadFiles = $aText;
    }

    /**
     * Добавляет тег для вывода в хидере страницы
     *
     * @param   string  $sTag
     */
    public function AddHtmlHeadTag($sTag) {

        $this->aHtmlHeadTags[] = $sTag;
    }

    /**
     * Устанавливаем заголовок страницы(тег title)
     *
     * @param string $sText    Заголовок
     */
    public function SetHtmlTitle($sText) {

        $this->sHtmlTitle = $sText;
    }

    /**
     * Добавляет часть заголовка страницы через разделитель
     *
     * @param string $sText    Заголовок
     */
    public function AddHtmlTitle($sText) {

        $this->sHtmlTitle = $sText . $this->sHtmlTitleSeparation . $this->sHtmlTitle;
    }

    /**
     * Возвращает текущий заголовок страницы
     *
     * @return string
     */
    public function GetHtmlTitle() {

        return $this->sHtmlTitle;
    }

    /**
     * Устанавливает ключевые слова keywords
     *
     * @param string $sText    Кейворды
     */
    public function SetHtmlKeywords($sText) {

        $this->sHtmlKeywords = $sText;
    }

    /**
     * Устанавливает описание страницы desciption
     *
     * @param string $sText    Описание
     */
    public function SetHtmlDescription($sText) {

        $this->sHtmlDescription = $sText;
    }

    /**
     * Устанавливает основной адрес страницы
     *
     * @param string $sUrl    URL страницы
     * @param bool $bRewrite    Перезаписывать URL, если он уже установлен
     */
    public function SetHtmlCanonical($sUrl, $bRewrite = false) {

        if (!$this->sHtmlCanonical || $bRewrite) {
            $this->sHtmlCanonical = $sUrl;
        }
    }

    /**
     * Устанавливает альтернативный адрес страницы по RSS
     *
     * @param string $sUrl    URL
     * @param string $sTitle    Заголовок
     */
    public function SetHtmlRssAlternate($sUrl, $sTitle) {

        $this->aHtmlRssAlternate['title'] = htmlspecialchars($sTitle);
        $this->aHtmlRssAlternate['url'] = htmlspecialchars($sUrl);
    }

    /**
     * Формирует постраничный вывод
     *
     * @param int $iCount    Общее количество элементов
     * @param int $iCurrentPage    Текущая страница
     * @param int $iCountPerPage    Количество элементов на одну страницу
     * @param int $iCountPageLine    Количество ссылок на другие страницы
     * @param string $sBaseUrl    Базовый URL, к нему будет добавлять постикс /pageN/  и GET параметры
     * @param array $aGetParamsList    Список GET параметров, которые необходимо передавать при постраничном переходе
     * @return array
     */
    public function MakePaging($iCount, $iCurrentPage, $iCountPerPage, $iCountPageLine, $sBaseUrl, $aGetParamsList = array()) {

        if ($iCount == 0) {
            return false;
        }

        $iCountPage = ceil($iCount / $iCountPerPage);
        if (!preg_match("/^[1-9]\d*$/i", $iCurrentPage)) {
            $iCurrentPage = 1;
        }
        if ($iCurrentPage > $iCountPage) {
            $iCurrentPage = $iCountPage;
        }

        $aPagesLeft = array();
        $iTemp = $iCurrentPage - $iCountPageLine;
        $iTemp = $iTemp < 1 ? 1 : $iTemp;
        for ($i = $iTemp; $i < $iCurrentPage; $i++) {
            $aPagesLeft[] = $i;
        }

        $aPagesRight = array();
        for ($i = $iCurrentPage + 1; $i <= $iCurrentPage + $iCountPageLine && $i <= $iCountPage; $i++) {
            $aPagesRight[] = $i;
        }

        $iNextPage = $iCurrentPage < $iCountPage ? $iCurrentPage + 1 : false;
        $iPrevPage = $iCurrentPage > 1 ? $iCurrentPage - 1 : false;

        $sGetParams = '';
        if (is_string($aGetParamsList) || count($aGetParamsList)) {
            $sGetParams = '?' . (is_array($aGetParamsList) ? http_build_query($aGetParamsList, '', '&') : $aGetParamsList);
        }
        $aPaging = array(
            'aPagesLeft' => $aPagesLeft,
            'aPagesRight' => $aPagesRight,
            'iCount' => $iCount,
            'iCountPage' => $iCountPage,
            'iCurrentPage' => $iCurrentPage,
            'iNextPage' => $iNextPage,
            'iPrevPage' => $iPrevPage,
            'sBaseUrl' => rtrim($sBaseUrl, '/'),
            'sGetParams' => $sGetParams,
        );
        /**
         * Избавляемся от дублирования страниц с page=1
         */
        if ($aPaging['iCurrentPage'] == 1) {
            $this->SetHtmlCanonical($aPaging['sBaseUrl'] . '/' . $aPaging['sGetParams']);
        }
        return $aPaging;
    }

    /**
     * Добавить меню в контейнер
     *
     * @param string $sContainer
     * @param string $sTemplate
     */
    public function AddMenu($sContainer, $sTemplate) {

        $this->aMenu[strtolower($sContainer)] = $sTemplate;
    }

    /**
     * Компилирует меню по контейнерам
     *
     */
    protected function BuildMenu() {

        foreach ($this->aMenu as $sContainer => $sTemplate) {
            $this->aMenuFetch[$sContainer] = $this->Fetch($sTemplate);
        }
    }

    /**
     * Обработка поиска файла шаблона, если его не смог найти шаблонизатор Smarty
     *
     * @param string $sType       - Тип шаблона/ресурса
     * @param string $sName       - Имя шаблона - имя файла
     * @param string $sContent    -  Возврат содержания шаблона при return true;
     * @param int    $iTimestamp  -  Возврат даты модификации шаблона при return true;
     * @param Smarty $oSmarty     -  Объект Smarty
     *
     * @return string|bool
     */
    public function SmartyDefaultTemplateHandler($sType, $sName, &$sContent, &$iTimestamp, $oSmarty) {
        /**
         * Название шаблона может содержать, как полный путь до файла шаблона,
         * так и относительный любого из каталога в $oSmarty->getTemplateDir()
         * По дефолту каталоги такие: /templates/skin/[name]/ и /plugins/
         */
        /**
         * Задача: если это файл плагина для текущего шаблона, то смотрим этот же файл шаблона плагина в /default/
         */
        if (Config::Get('view.skin') != 'default') {
            $sSkin = preg_quote(Config::Get('view.skin'));
            if (preg_match('@^/plugins/([\w\-_]+)/templates/skin/' . $sSkin . '/(.+)$/@i', $sName, $aMatch)) {
                // => /root/plugins/[plugin name]/templates/skin/[skin name]/dir/test.tpl
                $sPluginDir = Plugin::GetDir($aMatch[1]);
                $sTemplateFile = $aMatch[2];

            } elseif (preg_match('@^([\w\-_]+)/templates/skin/' . $sSkin . '/(.+)$/@i', $sName, $aMatch)) {
                // => [plugin name]/templates/skin/[skin name]/dir/test.tpl
                $sPluginDir = Plugin::GetDir($aMatch[1]);
                $sTemplateFile = $aMatch[2];

            } else {
                $sPluginDir = '';
                $sTemplateFile = '';
            }
            if ($sPluginDir && $sTemplateFile) {
                $sFile = $sPluginDir . '/templates/skin/default/' . $sTemplateFile;
                if ($this->TemplateExists($sFile)) {
                    return $sFile;
                }
            }
        }
        return false;
    }

    /**
     * Clear all viewer's temporary & cache files
     */
    public function ClearAll() {

        $this->ClearSmartyFiles();
        $this->ClearAssetsFiles();
    }

    /**
     * Clear all cached and compiled files of Smarty
     */
    public function ClearSmartyFiles() {

        $this->oSmarty->clearCompiledTemplate();
        $this->oSmarty->clearAllCache();
        F::File_ClearDir(Config::Get('path.tmp.dir') . '/templates/');
    }

    public function ClearAssetsFiles() {

        $sDir = $this->GetAssetDir();
        F::File_RemoveDir($sDir);
    }

    /**
     * Загружаем переменные в шаблон при завершении модуля
     *
     */
    public function Shutdown() {

        $timer = microtime(true);

        if ($this->sSkin != Config::Get('view.skin')) {
            $this->InitSkin($this->bLocal);
        }

        // * Создаются списки виджетов для вывода
        $this->MakeWidgetsLists();

        // * Добавляем JS и CSS по предписанным правилам
        $this->BuildHeadFiles();
        $this->VarAssign();

        // * Рендерим меню для шаблонов и передаем контейнеры в шаблон
        $this->BuildMenu();
        $this->MenuVarAssign();

        self::$_preprocessTime += microtime(true) - $timer;
    }

}

// EOF

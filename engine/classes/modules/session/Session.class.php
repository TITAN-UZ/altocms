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

/**
 * Модуль для работы с сессиями
 * Выступает в качестве надстроки для стандартного механизма сессий
 *
 * @package engine.modules
 * @since 1.0
 */
class ModuleSession extends Module {
    /**
     * ID  сессии
     *
     * @var null|string
     */
    protected $sId = null;
    /**
     * Данные сессии
     *
     * @var array
     */
    protected $aData = array();
    /**
     * Список user-agent'ов для флеш плеера
     * Используется для передачи ID сессии при обращениии к сайту через flash, например, загрузка файлов через flash
     *
     * @var array
     */
    protected $aFlashUserAgent = array(
        'Shockwave Flash'
    );
    /**
     * Использовать или нет стандартный механизм сессий
     * ВНИМАНИЕ! Не рекомендуется ставить false - т.к. этот режим до конца не протестирован
     *
     * @var bool
     */
    protected $bUseStandartSession = true;

    /**
     * Инициализация модуля
     *
     */
    public function Init() {

        $this->bUseStandartSession = Config::Get('sys.session.standart');

        // * Стартуем сессию
        $this->Start();
        $this->SetCookie('visitor_id', F::RandomStr());
    }

    /**
     * Старт сессии
     *
     */
    protected function Start() {

        if ($this->bUseStandartSession) {
            session_name(Config::Get('sys.session.name'));
            session_set_cookie_params(
                Config::Get('sys.session.timeout'),
                Config::Get('sys.session.path'),
                Config::Get('sys.session.host')
            );
            if (!session_id()) {

                // * Попытка подменить идентификатор имени сессии через куку
                if (isset($_COOKIE[Config::Get('sys.session.name')]) && !is_string($_COOKIE[Config::Get('sys.session.name')])) {
                    unset($_COOKIE[Config::Get('sys.session.name')]);
                    setcookie(Config::Get('sys.session.name') . '[]', '', 1, Config::Get('sys.cookie.path'), Config::Get('sys.cookie.host'));
                }

                // * Попытка подменить идентификатор имени сессии в реквесте
                $aRequest = array_merge($_GET, $_POST); // Исключаем попадаение $_COOKIE в реквест
                if (@ini_get('session.use_only_cookies') === '0' && isset($aRequest[Config::Get('sys.session.name')]) && !is_string($aRequest[Config::Get('sys.session.name')])) {
                    session_name($this->GenerateId());
                }

                // * Даем возможность флешу задавать id сессии
                $sUserAgent = isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : null;
                $sSSID = getRequestStr('SSID');
                if ($sUserAgent && (in_array($sUserAgent, $this->aFlashUserAgent) || strpos($sUserAgent, "Adobe Flash Player") === 0) && $sSSID && preg_match("/^[\w\d]{5,40}$/", $sSSID)) {
                    session_id(getRequest('SSID'));
                } else {
                    session_regenerate_id();
                }
                session_start();
            }
        } else {
            $this->SetId();
            $this->ReadData();
        }
    }

    /**
     * Устанавливает уникальный идентификатор сессии
     *
     */
    protected function SetId() {

        // * Если идентификатор есть в куках то берем его
        if (isset($_COOKIE[Config::Get('sys.session.name')])) {
            $this->sId = $_COOKIE[Config::Get('sys.session.name')];
        } else {
            // * Иначе создаём новый и записываем его в куку
            $this->sId = $this->GenerateId();
            setcookie(
                Config::Get('sys.session.name'),
                $this->sId,
                time() + Config::Get('sys.session.timeout'),
                Config::Get('sys.session.path'),
                Config::Get('sys.session.host')
            );
        }
    }

    /**
     * Получает идентификатор текущей сессии
     *
     */
    public function GetId() {

        if ($this->bUseStandartSession) {
            return session_id();
        } else {
            return $this->sId;
        }
    }

    /**
     * Returns hash-key of current session
     */
    public function GetKey() {

        return $this->Security_Salted($this->GetId(), 'sess');
    }

    /**
     * Гинерирует уникальный идентификатор
     *
     * @return string
     */
    protected function GenerateId() {

        return md5(F::RandomStr() . time());
    }

    /**
     * Читает данные сессии в aData
     *
     */
    protected function ReadData() {

        $this->aData = $this->Cache_Get($this->sId);
    }

    /**
     * Сохраняет данные сессии
     *
     */
    protected function Save() {

        $this->Cache_Set($this->aData, $this->sId, array(), Config::Get('sys.session.timeout'));
    }

    /**
     * Получает значение из сессии
     *
     * @param   string      $sName    Имя параметра
     * @param   string|null $sDefault Значение по умолчанию
     * @return  mixed|null
     */
    public function Get($sName = null, $sDefault = null) {

        if (is_null($sName)) {
            return $this->GetData();
        } else {
            if ($this->bUseStandartSession) {
                return isset($_SESSION[$sName]) ? $_SESSION[$sName] : $sDefault;
            } else {
                return isset($this->aData[$sName]) ? $this->aData[$sName] : $sDefault;
            }
        }
    }

    /**
     * Записывает значение в сессию
     *
     * @param string $sName    Имя параметра
     * @param mixed $data    Данные
     */
    public function Set($sName, $data) {

        if ($this->bUseStandartSession) {
            $_SESSION[$sName] = $data;
        } else {
            $this->aData[$sName] = $data;
            $this->Save();
        }
    }

    /**
     * Удаляет значение из сессии
     *
     * @param string $sName    Имя параметра
     */
    public function Drop($sName) {

        if ($this->bUseStandartSession) {
            unset($_SESSION[$sName]);
        } else {
            if (isset($_SESSION[$sName])) unset($_SESSION[$sName]);
            unset($this->aData[$sName]);
            $this->Save();
        }
    }

    /**
     * Получает разом все данные сессии
     *
     * @return array
     */
    public function GetData() {

        if ($this->bUseStandartSession) {
            return $_SESSION;
        } else {
            return $this->aData;
        }
    }

    /**
     * Завершает сессию, дропая все данные
     *
     */
    public function DropSession() {

        if ($this->bUseStandartSession) {
            unset($_SESSION);
            session_destroy();
        } else {
            unset($this->sId);
            unset($this->aData);
            $this->DelCookie(Config::Get('sys.session.name'));
        }
    }

    /**
     * Sets cookie
     *
     * @param   string          $sName
     * @param   string          $sValue
     * @param   int|string|null $xPeriod  - period in seconds or in string like 'P<..>'
     */
    public function SetCookie($sName, $sValue, $xPeriod = null) {

        if ($xPeriod) {
            $nTime = time() + F::ToSeconds($xPeriod);
        } else {
            $nTime = 0;
        }
        setcookie($sName, $sValue, $nTime, Config::Get('sys.cookie.path'), Config::Get('sys.cookie.host'), false, true);
    }

    /**
     * Gets cookie
     *
     * @param   string  $sName
     * @return  string|null
     */
    public function GetCookie($sName) {

        if (isset($_COOKIE[$sName])) {
            return $_COOKIE[$sName];
        }
        return null;
    }

    /**
     * Deletes cookie
     *
     * @param   string  $sName
     */
    public function DelCookie($sName) {

        setcookie($sName, '', time() - 3600, Config::Get('sys.cookie.path'), Config::Get('sys.cookie.host'));
    }
}

// EOF
<?php

namespace ottimis\phplibs;

use Smarty\Exception;
use Smarty\Smarty;

class OGSmarty
{
    protected Smarty $smarty;

    function __construct($smartyFolder = "/var/www/html/smarty")
    {
        $this->smarty = new Smarty();
        $this->smarty->setTemplateDir($smartyFolder . '/templates/');
        $this->smarty->setCompileDir($smartyFolder . '/templates_c/');
        $this->smarty->setConfigDir($smartyFolder . '/configs/');
        $this->smarty->setCacheDir($smartyFolder . '/cache/');
    }

    /**
     * @throws Exception
     */
    public function loadTemplate($templateName = null, $templateString = null, $data = []): false|string
    {
        foreach ($data as $key => $value) {
            $this->smarty->assign($key, $value);
        }
        if ($templateName) {
            return $this->smarty->fetch($templateName);
        } else if ($templateString) {
            return $this->smarty->fetch('string:' . $templateString);
        }
        return false;
    }
}

<?php

namespace ls\tests\controllers;

use ThemeOptionsController;
use TemplateConfiguration;
use PHPUnit\Framework\TestCase;

class ThemeOptionsControllerTest extends TestCase
{
    /**
     * @var themeoptions
     */
    private $controller;

    /**
     * @var TemplateConfiguration
     */
    private $templateConfiguration;

    public function setUp()
    {
        \Yii::import('application.controllers.ThemeOptionsController', true);
        $this->controller = new ThemeOptionsController();

    }

    public function tearDown()
    {
        $this->controller = null;
    }

    /**
     * Tests if its possible to set an admin theme.
     */
    public function testSetAdminTheme()
    {
        $adminTheme = 'Apple_Blossom';
        
        $this->controller->actionSetAdminTheme($adminTheme);
        $actualTheme = $this->controller->adminTheme;

        $this->assertEquals($actualTheme, $adminTheme);
    }

    /**
     * This test will check if the ajaxmode will be turned off.
     * @skip
     */
    public function testTurnAjaxModeOffAsDefault()
    {
        $expected = 'off';
        $json = json_encode(['ajaxmode' => 'on']);

        $this->templateConfiguration = new TemplateConfiguration();
        $this->templateConfiguration->setAttribute('options', (string) $json);
        $this->templateConfiguration->setAttribute('surveyid', 1);

        $actual = $this->controller->turnAjaxmodeOffAsDefault($this->templateConfiguration);
        $actualOptions = json_decode($actual->getAttribute('options'), true);

        $this->assertEquals($expected, $actualOptions['ajaxmode']);
    }
}

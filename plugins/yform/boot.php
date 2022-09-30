<?php

// plugin is backend only
if (rex::isBackend()):

$beUser = rex::getUser();

// include current active set
if($beUser instanceof rex_user) {
    // if(rex_be_controller::getCurrentPage() == 'yform/manager/data_edit') {
    rex_view::addCssFile($this->getAssetsUrl('css/collaborate.plugin.yform.backend.css'));
    rex_view::addJsFile($this->getAssetsUrl('js/collaborate.plugin.yform.backend.js'));

    // move yform specific request params to client js
    $requestParams = [];

    foreach(['table_name','func','data_id','list'] as $param) {
        if(rex_request::request($param, '', null) != null) {
            $requestParams[$param] = rex_request::request($param);
        }
    }

    if(isset($requestParams['data_id'])) {
        // trying to determine some kind of name/title
        if (isset($requestParams['table_name'])) {
            $dataset = rex_yform_manager_dataset::get($requestParams['data_id'], $requestParams['table_name']);
            $name = $dataset->getValue("name") ?? ($dataset->getValue("title") ?? ($dataset->getValue("firstname") ?? null));
            $requestParams['name'] = $name;
        }
    }

    rex_view::setJsProperty(
        'collaborate_plugin_yform_requests',
        $requestParams
    );
}

endif;
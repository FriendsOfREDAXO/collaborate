<?php

// plugin is backend only
if (rex::isBackend()):

$beUser = rex::getUser();

// include current active set
if($beUser instanceof rex_user) {
    rex_view::addCssFile($this->getAssetsUrl('css/collaborate.plugin.structure.backend.css'));
    rex_view::addJsFile($this->getAssetsUrl('js/collaborate.plugin.structure.backend.js'));

    // move yform specific request params to client js
    $requestParams = [];

    foreach(['category_id','article_id','mode'] as $param) {
        if(rex_request::request($param, '', null) != null) {
            $requestParams[$param] = rex_request::request($param);
        }
    }

    if(isset($requestParams['article_id']) && (int)$requestParams['article_id'] > 0) {
        $requestParams['name'] = rex_article::get($requestParams['article_id'])->getName();
    }

    rex_view::setJsProperty(
        'collaborate_plugin_structure_requests',
        $requestParams
    );

    if(rex_be_controller::getCurrentPage() == 'structure' && $beUser instanceof rex_user && $beUser->getComplexPerm('structure')->hasStructurePerm()) {
        rex_view::setJsProperty('collaborate_clang', rex_clang::getCurrentId());
        rex_view::setJsProperty('collaborate_category', rex_category::getCurrent() ? rex_category::getCurrent()->getId() : null);
    }
}

endif;
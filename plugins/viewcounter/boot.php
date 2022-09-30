<?php

// plugin is mixed: public users collect data (their visits) and backend users see them counted
// frontend stuff is added via RE_VAR
if (rex::isBackend()) {
    $beUser = rex::getUser();

    if(rex_be_controller::getCurrentPage() == 'structure' && $beUser instanceof rex_user && $beUser->getComplexPerm('structure')->hasStructurePerm()) {
        rex_view::addCssFile($this->getAssetsUrl('css/collaborate.plugin.viewcounter.css'));
        rex_view::addJsFile($this->getAssetsUrl('js/collaborate.plugin.viewcounter.backend.js'));
    }

    rex_view::setJsProperty('collaborate_clang', rex_clang::getCurrentId());
    rex_view::setJsProperty('collaborate_category', rex_category::getCurrent() ? rex_category::getCurrent()->getId() : null);
}
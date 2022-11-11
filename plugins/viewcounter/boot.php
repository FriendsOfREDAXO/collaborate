<?php

rex_perm::register("collaborate[viewcounter_structure]", "Viewcounter: Einsehen der einzelnen Live-Page-Views in der Strukturverwaltung & Url-AddOn", rex_perm::EXTRAS);
rex_perm::register("collaborate[viewcounter_global]", "Viewcounter: Einsehen aller Live-Page-Views als Bubble am Menüpunkt von Strukturverwaltung & Url-AddOn", rex_perm::EXTRAS);

// plugin is mixed: public users collect data (their visits) and backend users see them counted
// frontend stuff is added via RE_VAR
if (rex::isBackend() ) {
    $beUser = rex::getUser();

    if($beUser != null && ($beUser->hasPerm("collaborate[viewcounter_structure]") || $beUser->hasPerm("collaborate[viewcounter_global]"))) {
        // if(rex_be_controller::getCurrentPage() == 'structure' && $beUser instanceof rex_user && $beUser->getComplexPerm('structure')->hasStructurePerm()) {
        rex_view::addCssFile($this->getAssetsUrl('css/collaborate.plugin.viewcounter.css'));
        rex_view::addJsFile($this->getAssetsUrl('js/collaborate.plugin.viewcounter.backend.js'));
        // }

        rex_view::setJsProperty('collaborate_clang', rex_clang::getCurrentId());
        rex_view::setJsProperty('collaborate_category', rex_category::getCurrent() ? rex_category::getCurrent()->getId() : null);
        rex_view::setJsProperty('collaborate_perm_viewcounter_structure', $beUser->hasPerm("collaborate[viewcounter_structure]"));
        rex_view::setJsProperty('collaborate_perm_viewcounter_global', $beUser->hasPerm("collaborate[viewcounter_global]"));
    }
}
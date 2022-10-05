<?php

// add perms
rex_perm::register("collaborate[]", "zeigt Infobox im Header, mit Anzahl derzeit eingeloggter Backend-Nutzer", rex_perm::EXTRAS);
rex_perm::register("collaborate[users]", "Infobox ausklappbar; zeigt an, welche Backend-Nutzer konkret online sind und seit wann", rex_perm::EXTRAS);
rex_perm::register("collaborate[user_locations]", "zeigt an, welche Nutzer in welchen Sektionen (1-n) unterwegs sind und auch wie viele Tabs/Fenster sie geöffnet haben", rex_perm::EXTRAS);
//rex_perm::register("collaborate[supervisor]", "sehr hohes Recht, bei dem man von Sperren beim Betreten von Artikeln oder YForm-Datensätzen nicht betroffen ist und andere Nutzer dies nicht mitbekommen", rex_perm::EXTRAS);

$beUser = rex::getUser();

if (rex::isBackend() && $beUser instanceof rex_user) {
    rex_view::addCssFile($this->getAssetsUrl('css/collaborate.backend.css'));
    rex_view::addJsFile($this->getAssetsUrl('js/collaborate.backend.class.js'));
    rex_view::addJsFile($this->getAssetsUrl('js/collaborate.backend.js'));

    rex_view::setJsProperty('collaborate_login', $beUser->getLogin());
    rex_view::setJsProperty('collaborate_login_hash', sha1("#".$beUser->getLogin()."~".$beUser->getValue("createdate")."+".$beUser->getValue("createuser")."???"));

    // get collaborate permissions
    if(!$beUser->isAdmin()) {
        $permsRaw = rex_sql::factory()->getArray(
            "SELECT JSON_EXTRACT(perms, '$.extras') AS perms FROM rex_user_role WHERE
                    JSON_EXTRACT(perms, '$.extras') LIKE '%collaborate%' AND FIND_IN_SET(id, :roles) ",
            [":roles" => $beUser->getValue("role")]
        );
        $perms = [];

        foreach($permsRaw as $entry) {
            $collaboratePerms = explode("|", trim($entry['perms'], "|"));

            foreach($collaboratePerms as $cp) {
                if(trim($cp, '" |') == "") {
                    continue;
                }

                if(!in_array($cp, $perms)) {
                    $perms[] = $cp;
                }
            }
        }
    } else {
        $perms = 'ADMIN';
    }

    rex_view::setJsProperty('collaborate_user_perms', $perms);

    // push data for init function
    rex_extension::register('PAGE_HEADER', function($ep){
        $subject = $ep->getSubject();
        $addon = rex_addon::get('collaborate');
        $config = [];

        // write translations
        $file = $addon->getPath('lang/'.rex_i18n::getLocale().'.lang');
        $collaborateTranslations = [];

        if (($content = rex_file::get($file)) && preg_match_all('/^collaborate_([^=\s]+)\h*=\h*(\S.*)(?<=\S)/m', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $collaborateTranslations[$match[1]] = $match[2];
            }
        }

        // add translations of plugins
        $plugins = $addon->getAvailablePlugins();

        if(count($plugins) > 0) {
            foreach($plugins as $p) {
                $file = $p->getPath('lang/'.rex_i18n::getLocale().'.lang');

                if (($content = rex_file::get($file)) && preg_match_all('/^collaborate_([^=\s]+)\h*=\h*(\S.*)(?<=\S)/m', $content, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $match) {
                        if(!isset($collaborateTranslations[$p->getName()])) {
                            $collaborateTranslations[$p->getName()] = [];
                        }

                        $collaborateTranslations["plugin_".$p->getName()][$match[1]] = $match[2];
                    }
                }
            }
        }

        // set config
        $collaborateSettings = [];

        foreach(["websocket-client-port","websocket-path"] as $config) {
            $collaborateSettings[str_replace("websocket-", "", $config)] = $addon->getConfig($config);
        }

        $subject .= '
        <!-- collaborate addon -->
        <script type="text/javascript">
            let CollaborateI18N = '.json_encode($collaborateTranslations).';
            let CollaborateSettings = '.json_encode($collaborateSettings).';
        </script>
        <!-- end collaborate addon -->
        ';

        return $subject;
    }, rex_extension::LATE, ['addon' => $this]);

    // show header
    if($beUser->hasPerm("collaborate[]")) {
        rex_extension::register('OUTPUT_FILTER', function(rex_extension_point $ep){
            $suchmuster = '<ul class="nav navbar-nav navbar-right">';

            ob_start();
            include_once(rex_addon::get("collaborate")->getPath("pages/header.box.php"));
            $collaborateBox = ob_get_contents();
            ob_end_clean();

            $ersetzen = $collaborateBox.$suchmuster;
            $ep->setSubject(str_replace($suchmuster, $ersetzen, $ep->getSubject()));
        });
    }
}
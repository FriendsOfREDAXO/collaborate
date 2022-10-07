<?php
$addon = rex_addon::get("collaborate");
$plugins = $addon->getAvailablePlugins();
$out = '';

if(count($plugins) > 0) {
    // set config
    $collaborateSettings = [];
    $setUserId = false;

    foreach(["websocket-client-port","websocket-path"] as $config) {
        $collaborateSettings[str_replace("websocket-", "", $config)] = $addon->getConfig($config);
    }

    // embed addon resources
    $out .= '<script type="text/javascript">let CollaborateSettings = '.json_encode($collaborateSettings).';</script>';
    $out .= '<script type="text/javascript" src="'.rex_url::addonAssets("collaborate", "js/collaborate.frontend.class.js").'"></script>'."\n";
    $out .= '<script type="text/javascript" src="'.rex_url::addonAssets("collaborate", "js/collaborate.frontend.js").'"></script>'."\n";

    // add plugin frontend resources
    foreach($plugins as $p) {
        // check scope
        if((int)$p->getConfig("upstream_scope") < 1 && (int)$p->getConfig("downstream_scope") < 1) {
            continue;
        }

        $setUserId = true;

        // js
        if(file_exists(rex_path::pluginAssets("collaborate", $p->getName(), "js/collaborate.plugin.{$p->getName()}.frontend.js"))) {
            $out .= '<script type="text/javascript" src="'.rex_url::pluginAssets("collaborate", $p->getName(), "js/collaborate.plugin.{$p->getName()}.frontend.js").'"></script>'."\n";
        }
        // css
        if(file_exists(rex_path::pluginAssets("collaborate", $p->getName(), "css/collaborate.plugin.{$p->getName()}.frontend.css"))) {
            $out .= '<link rel="stylesheet" href="'.rex_url::pluginAssets("collaborate", $p->getName(), "css/collaborate.plugin.{$p->getName()}.frontend.css").'" type="text/css" media="screen,print" />'."\n";
        }
    }

    // add ip as id
    if($setUserId) {
        $out .= "<script type=\"text/javascript\">let collaborate_userid = '".sha1(
            isset($_SERVER['HTTP_CLIENT_IP']) ?:
            isset($_SERVER['HTTP_X_FORWARDED_FOR']) ?:
            isset($_SERVER['HTTP_X_FORWARDED']) ?:
            isset($_SERVER['HTTP_X_CLUSTER_CLIENT_IP']) ?:
            isset($_SERVER['HTTP_FORWARDED_FOR']) ?:
            isset($_SERVER['HTTP_FORWARDED']) ?:
            isset($_SERVER['REMOTE_ADDR']) ?:
            microtime()
        )."';</script>";
    }
}

echo $out;
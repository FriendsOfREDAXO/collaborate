<?php
/**
 * Class collaborate_frontend
 * collects all enabled collaborate plugins and searches for atleast one that uses up- or down stream for frontend
 * if found frontend main class for websocket connection is added and JS/CSS of the package (if found: collaborate.plugin.[NAME].js / .css)
 *
 * @category rex_var
 * @author Peter Schulze | p.schulze[at]bitshifters.de
 * @created 19.09.2022
 */
class rex_var_collaborate_frontend extends rex_var
{
    /**
     * @return false|string
     * @author Peter Schulze | p.schulze[at]bitshifters.de
     */
    protected function getOutput() {
        // only allowed in mod input for now
        if (!$this->environmentIs(self::ENV_FRONTEND)) {
            return false;
        }

        $addon = rex_addon::get("collaborate");
        $plugins = $addon->getAvailablePlugins();
        $out = '';

        if(count($plugins) > 0) {
            // set config
            $collaborateSettings = [];

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
                if((int)$p->getConfig("upstream-scope") < 1 && (int)$p->getConfig("downstream-scope") < 1) {
                    continue;
                }

                // js
                if(file_exists(rex_path::pluginAssets("collaborate", $p->getName(), "js/collaborate.plugin.{$p->getName()}.frontend.js"))) {
                    $out .= '<script type="text/javascript" src="'.rex_url::pluginAssets("collaborate", $p->getName(), "js/collaborate.plugin.{$p->getName()}.frontend.js").'"></script>'."\n";
                }
                // css
                if(file_exists(rex_path::pluginAssets("collaborate", $p->getName(), "css/collaborate.plugin.{$p->getName()}.frontend.css"))) {
                    $out .= '<link rel="stylesheet" href="'.rex_url::pluginAssets("collaborate", $p->getName(), "css/collaborate.plugin.{$p->getName()}.frontend.css").'" type="text/css" media="screen,print" />'."\n";
                }
            }
        }

        return self::quote($out);
    }
}
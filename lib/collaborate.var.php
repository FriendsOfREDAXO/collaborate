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
        if (!$this->environmentIs(self::ENV_FRONTEND)) {
            return false;
        }

        // using fragment to bypass caching
        return "rex_var_collaborate_frontend::getFrontendFragment('collaborate.frontend.php')";
    }

    /**
     * frontend code fragment
     * @param $fragmentFilename
     * @return mixed
     * @throws rex_exception
     * @author Peter Schulze | p.schulze[at]bitshifters.de
     * @created 07.10.2022
     */
    public static function getFrontendFragment($fragmentFilename) {
        $fragment = new rex_fragment();
        return $fragment->parse($fragmentFilename);
    }
}
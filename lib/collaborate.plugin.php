<?php

namespace Collaborate;
use Ratchet\ConnectionInterface;
use rex_file;
use rex_path;
use rex_plugin;

/**
 * template/abstract class for server side plugin development
 * @author Peter Schulze | p.schulze[at]bitshifters.de
 */
abstract class CollaboratePlugin {

    /**
     * prevent periodical reloading of the plugin
     * @var bool
     */
    const PREVENT_RELOAD = false;

    /**
     * reference to main application
     * @var CollaborateApplication
     */
    protected CollaborateApplication $app;

    /**
     * plugin name (as listed in rex addons)
     * @var null|string
     */
    private ?string $name = null;

    /**
     * class file hash
     * @var string
     */
    private string $hash;

    /**
     * Collaborate constructor
     * @param CollaborateApplication $application
     * @param string|null $name
     */
    public function __construct(CollaborateApplication &$application, ?string $name = null) {
        $this->app = $application;
        $this->name = $name;

        if(!is_null($name)) {
            $this->hash = sha1(rex_file::get(rex_path::plugin("collaborate", $this->name, "lib/collaborate.plugin.{$this->name}.php")));
        }
    }

    /**
     * open event handler
     * @param mixed $data
     * @param ConnectionInterface $client
     * @author Peter Schulze | p.schulze[at]bitshifters.de
     * @created 20.09.2022
     */
    public function onOpen(mixed &$data, ConnectionInterface &$client) {}

    /**
     * close event handler
     * @param mixed $data
     * @param ConnectionInterface $client
     * @author Peter Schulze | p.schulze[at]bitshifters.de
     * @created 20.09.2022
     */
    public function onClose(mixed &$data, ConnectionInterface &$client) {}

    /**
     * fired in main thread when backend user visits some page
     * @param mixed $data
     * @param ConnectionInterface $client
     * @author Peter Schulze | p.schulze[at]bitshifters.de
     * @created 20.09.2022
     */
    public function onEnteredPage(mixed &$data, ConnectionInterface &$client) {}

    /**
     * manipulate incoming messages before backend user check and consistency validation fire
     * @param mixed $data
     * @param ConnectionInterface $client
     * @author Peter Schulze | p.schulze[at]bitshifters.de
     */
    public function onBeforeMessage(mixed &$data, ConnectionInterface &$client) {
        // default behaviour: if plugin allows frontend upstreams we can set client and its message as permitted
        // to do that clients need to authorize by sending "plugin: [PLUGINNAME]" and "pluginhash: [PLUGINHASH]" as payload
        // while [PLUGINHASH] is a sha1 of plugin's main class code (@see constructor)
        if(!is_null($this->name) &&
            isset($data->plugin) && $data->plugin == $this->name &&
            isset($data->pluginhash) && $this->hash == $data->pluginhash
        ) {
            // finally check if addon config allows incoming frontend messages (and connections)
            if((int)rex_plugin::get("collaborate", $this->name)->getConfig("upstream-scope", -1) >= 1 ) {
                // multiple plugins could mark a message as permitted > therefore it's an array
                if(!isset($data->permitted)) {
                    $data->permitted = [];
                }

                $data->permitted[] = $this->name;
            }
        }
    }

    /**
     * manipulate incoming messages
     * @param mixed $data
     * @param ConnectionInterface $client
     * @author Peter Schulze | p.schulze[at]bitshifters.de
     */
    public function onMessage(mixed &$data, ConnectionInterface &$client) {}

    /**
     * manipulate data before sending
     * @param mixed $data
     * @param ConnectionInterface $client
     * @author Peter Schulze | p.schulze[at]bitshifters.de
     */
    public function onBeforeSend(mixed &$data, ConnectionInterface &$client) {}

    /**
     * get plugin name
     * @return string|null
     * @author Peter Schulze | p.schulze[at]bitshifters.de
     * @created 26.09.2022
     */
    public function getName(): ?string {
        return $this->name;
    }
}
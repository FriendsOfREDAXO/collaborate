<?php

namespace Collaborate;

use Ratchet\ConnectionInterface;

/**
 * Class CollaboratePluginYform
 * @package Collaborate
 * @author Peter Schulze | p.schulze[at]bitshifters.de
 */
class CollaboratePluginYform extends CollaboratePlugin {
    /**
     * handle messages
     * - default: entering a yform table manager dataset
     * - check: on details page, check if someone edited this before (and is still editing ...)
     *
     * @param $data
     * @param ConnectionInterface $client
     * @return mixed
     * @author Peter Schulze | p.schulze[at]bitshifters.de
     */
    public function onMessage(&$data, ConnectionInterface &$client) {
        $clients = $this->app->getClients();

        if($data->type == 'PAGE' && $data->page->path == "yform/manager/data_edit" && count($clients) > 1) {
            // do not send data edit position if someone else is already there
            foreach ($clients as $hash => $c) {
                // skip current user
                if (!is_null($client) && $c['user'] == $data->user) {
//                    echo "don't send self: ".$c['user']."\n";
                    continue;
                }

                $page = $c['data']['page'] ?? null;
                
                // check if someone already entered the current dataset
                if ($page != null &&
                    isset($page['table_name']) && isset($data->page->table_name) &&
                    isset($page['data_id']) && isset($data->page->data_id) &&
                    $page['plugin'] == 'yform' && $data->page->plugin == 'yform' &&
                    $page['path'] == $data->page->path &&
                    $page['table_name'] == $data->page->table_name &&
                    $page['data_id'] == $data->page->data_id &&
                    $data->page->func == 'edit'
                ) {
                    CollaborateApplication::echo("yform: preventing edit of ID:{$data->page->data_id} in table '{$data->page->table_name}' (user \"{$c['user']}\" opened dataset earlier)");

                    // don't send dataset identifier to not block the origin (first) user working on the current yform dataset
                    unset($data->page->table_name);
                    unset($data->page->data_id);
                }
            }
        }
    }
}
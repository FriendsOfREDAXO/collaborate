<?php

namespace Collaborate;

use Ratchet\ConnectionInterface;

/**
 * Class CollaboratePluginStructure
 * @package Collaborate
 * @author Peter Schulze | p.schulze[at]bitshifters.de
 */
class CollaboratePluginStructure extends CollaboratePlugin {
    /**
     * handle messages
     * - default: entering a structure dataset (article) for edit
     * - check: on details page, check if someone edited this before (and is still editing ...)
     *
     * @param $data
     * @param ConnectionInterface $client
     * @author Peter Schulze | p.schulze[at]bitshifters.de
     */
    public function onMessage(&$data, ConnectionInterface &$client) {
        $clients = $this->app->getClients();

        if($data->type == 'PAGE' && ($data->page->path == "content/edit" || $data->page->path == "content/functions") && count($clients) > 1) {
            // do not send data edit position if someone else is already there
            foreach ($clients as $hash => $c) {
                // skip current user
                if (!is_null($client) && $c['user'] == $data->user) {
                    continue;
                }

                $page = $c['data']['page'] ?? null;
                
                // check if someone already entered the current dataset
                if ($page != null &&
                    isset($page['article_id']) && isset($data->page->article_id) &&
                    isset($page['category_id']) && isset($data->page->category_id) &&
                    $page['plugin'] == 'structure' && $data->page->plugin == 'structure' &&
                    $page['path'] == $data->page->path &&
                    (int)$page['article_id'] == (int)$data->page->article_id &&
                    (int)$page['category_id'] == (int)$data->page->category_id
                ) {
                    CollaborateApplication::echo("structure: preventing edit of article:{$data->page->article_id} of category:{$data->page->category_id} (user \"{$c['user']}\" opened article earlier)");

                    // don't send dataset identifier to not block the origin (first) user working on the current yform dataset
                    unset($data->page->article_id);
                    unset($data->page->category_id);
                }
            }
        }
    }
}
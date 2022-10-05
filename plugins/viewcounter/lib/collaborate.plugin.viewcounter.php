<?php

namespace Collaborate;

use Ratchet\ConnectionInterface;
use rex_article;
use rex_clang;
use rex_user;
use rex_yrewrite;

/**
 * Class CollaboratePluginViewcounter
 * @package Collaborate
 * @author Peter Schulze | p.schulze[at]bitshifters.de
 */
class CollaboratePluginViewcounter extends CollaboratePlugin {

    /**
     * DO prevent periodical reloading of the plugin
     * @var bool
     */
    const PREVENT_RELOAD = true;

    /**
     * server tick rate (0 = disabled for now ...)
     */
    const TICK_RATE = 0;

    /**
     * article list refresh rate [sec]
     */
    const REFRESH_ARTICLE_LIST_AFTER = 300;

    /**
     * storing FE client connections
     * @var array
     */
    private array $frontendClients = [];

    /**
     * store array of current articles
     * @var array
     */
    private array $articles = [];

    /**
     * @var int
     */
    private int $lastSendDataTimestamp = 0;

    /**
     * storing last sent data
     * @var array
     */
    private array $lastSendData = [];

    /**
     * @param CollaborateApplication $application
     * @param string|null $name
     */
    public function __construct(CollaborateApplication &$application, ?string $name = null) {
        parent::__construct($application, $name);

        // write article list
        $this->refreshArticleList();

        // refresh list periodically
        $application->registerLoop(function() use ($application, $name) {
            $this->refreshArticleList();
            //$application::echo(sprintf("%s: refreshing article list ... found %s articles over %s clangs", $name, count($this->articles), rex_clang::count(true)));
        }, self::REFRESH_ARTICLE_LIST_AFTER, "{$name}_refresh_article_list");
    }

    /**
     * get all articles
     * @return void
     * @author Peter Schulze | p.schulze[at]bitshifters.de
     * @created 27.09.2022
     */
    private function refreshArticleList() {
        $domains = rex_yrewrite::getDomains();
        $this->articles = [];

        foreach($domains as $key => $domain) {
            if($key == "default") {
                continue;
            }

            foreach (rex_yrewrite::getPathsByDomain($domain->getName()) as $article_id => $path) {
                foreach ($domain->getClangs() as $clang_id) {
                    if (!rex_clang::get($clang_id)->isOnline()) {
                        continue;
                    }

                    $article = rex_article::get($article_id, $clang_id);

                    if ($article && ($article_id != $domain->getNotfoundId() || $article_id == $domain->getStartId())) {
                        $this->articles[$domain->getUrl().$path[$clang_id]] = $article;
                    }
                }
            }
        }
    }

    /**
     * send view count to backend users
     * @return void
     * @author Peter Schulze | p.schulze[at]bitshifters.de
     * @created 28.09.2022
     */
    private function sendViewcountToBackendusers() {
        // prevent sending too much data too often (skip empty arrays on last call since they could be a temporary state between leaving one page an entering a new one)
        if(time() - $this->lastSendDataTimestamp < self::TICK_RATE && count($this->lastSendData) > 0) {
            CollaborateApplication::echo(sprintf("viewcounter: tick rate limit hit"));
            return;
        }

        $beUsers = $this->app->getClients();
        $viewCountData = $this->collectViewCountData();

        if(count($beUsers) > 0) {
            // send to backend users watching page "structure"
            foreach($beUsers as $beUser) {
                // TODO: when connection is stored as backend-user > create user object and save it there
                $beUserLogin = $this->app->getLoginForConnection($beUser['connection']);

                if(is_null($beUserLogin)) {
                    continue;
                }

                rex_user::clearInstance('login_' . $beUserLogin);
                $beUserObject = rex_user::forLogin($beUserLogin);

                // if user visits structure page and is allowed to do that and has perm to see viewcounter stats there OR user is allowed to see global counter
                if($beUserObject->getComplexPerm('structure')->hasStructurePerm() &&
                    (
                        ($beUserObject->hasPerm("collaborate[viewcounter_structure]") &&
                         isset($beUser['data']['page']['path']) &&
                         $beUser['data']['page']['path'] == 'structure'
                        ) || $beUserObject->hasPerm("collaborate[viewcounter_global]")
                    )
                ) {
                    $this->app->sendDataDedicated(
                        $beUser['connection'],
                        ["viewcount" => $viewCountData],
                        true,
                        true
                    );
                }
            }

            // set last sent time
            $this->lastSendDataTimestamp = time();
            $this->lastSendData = $viewCountData;
        }
    }

    /**
     * send view count to dedicated backend user
     * @param ConnectionInterface $client
     * @return void
     * @author Peter Schulze | p.schulze[at]bitshifters.de
     * @created 28.09.2022
     */
    private function sendViewcountToBackenduser(ConnectionInterface &$client) {
        $beUserLogin = $this->app->getLoginForConnection($client);

        if(is_null($beUserLogin)) {
            return;
        }

        rex_user::clearInstance('login_' . $beUserLogin);
        $beUserObject = rex_user::forLogin($beUserLogin);

        // if user visits structure page and is allowed to do that and has perm to see viewcounter stats there OR user is allowed to see global counter
        if($beUserObject->getComplexPerm('structure')->hasStructurePerm() &&
            ($beUserObject->hasPerm("collaborate[viewcounter_structure]") || $beUserObject->hasPerm("collaborate[viewcounter_global]"))
        ) {
            $viewCountData = $this->collectViewCountData();

            $this->app->sendDataDedicated(
                $client,
                ["viewcount" => $viewCountData],
                true,
                true
            );
        }
    }

    /**
     * collect view count data
     * @return array
     * @author Peter Schulze | p.schulze[at]bitshifters.de
     * @created 29.09.2022
     */
    private function collectViewCountData(): array {
        // collect data
        $viewCountData = [];
        $clangs = rex_clang::getAll(true);

        foreach($this->frontendClients as $userId => $pages) {
            foreach($pages as $pageUrl => $page) {
                // ignore unknown articles
                if(!isset($this->articles[$pageUrl])) {
                    continue;
                }

                if(!isset($viewCountData[$this->articles[$pageUrl]->getId()])) {
                    $viewCountData[$this->articles[$pageUrl]->getId()] = [];

                    foreach($clangs as $clang) {
                        $viewCountData[$this->articles[$pageUrl]->getId()]['count_'.$clang->getId()] = [];
                        $viewCountData[$this->articles[$pageUrl]->getId()]['children_'.$clang->getId()] = 0;
                    }
                }
                // ignore same pages of same user
                elseif(isset($viewCountData[$this->articles[$pageUrl]->getId()]['count_'.$this->articles[$pageUrl]->getClangId()][$userId])) {
                    continue;
                }

                $viewCountData[$this->articles[$pageUrl]->getId()]['count_'.$this->articles[$pageUrl]->getClangId()][$userId] = true;
            }
        }

        // compress data
        foreach($viewCountData as &$data) {
            foreach($clangs as $clang) {
                $data['count_'.$clang->getId()] = count($data['count_'.$clang->getId()]);
            }
        }

        // collect data for child articles
        foreach($viewCountData as $articleId => &$data) {
            foreach($this->articles as $pageUrl => $article) {
                $path = $article->getPathAsArray();

                if($article->getId() == $articleId) {
                    // check if article is inside category/ies > create category entry if so
                    if(count($path) > 0 && $path[0] != $articleId) {
                        foreach($path as $parentId) {
                            if($parentId == $articleId) {
                                continue;
                            }

                            // create empty structure for current path element
                            if(!isset($viewCountData[$parentId])) {
                                $viewCountData[$parentId] = [];

                                foreach($clangs as $clang) {
                                    $viewCountData[$parentId]['count_'.$clang->getId()] = 0;
                                    $viewCountData[$parentId]['children_'.$clang->getId()] = 0;
                                }
                            }

                            // counting
                            if($data['count_'.$article->getClangId()] > 0) {
                                $viewCountData[$parentId]['children_'.$article->getClangId()] += $data['count_'.$article->getClangId()];
//                                CollaborateApplication::echo(sprintf("adding +%s to children for parent article %s (base article id: %s) | clang %s",
//                                    $data['count_'.$article->getClangId()], $parentId, $articleId, $article->getClangId()
//                                ));
                            }
                        }
                    }
                }
            }
        }

        return $viewCountData;
    }

    /**
     * handle before messages event
     * @param mixed $data
     * @param ConnectionInterface $client
     * @author Peter Schulze | p.schulze[at]bitshifters.de
     */
    public function onBeforeMessage(mixed &$data, ConnectionInterface &$client) {
        if($data->type == 'PAGEVIEW' &&
            isset($data->userid) &&
            preg_match("@\d+\.\d+\.\d+@", $data->userid) &&
            isset($data->tabid) &&
            isset($data->page->plugin) &&
            $data->page->plugin == 'viewcounter'
        ) {
            // store client connection or replace
            if(!isset($this->frontendClients[$data->userid])) {
                $this->frontendClients[$data->userid] = [
                    $data->page->path->origin . $data->page->path->pathname => array_merge(
                        (array)$data->page,
                        ['tabid' => $data->tabid, 'created' => microtime(true), 'connection' => $client]
                    )
                ];
            }
            // user exists, check if page already in stack
            else {
                $pageVisited = false;

                foreach($this->frontendClients[$data->userid] as $pageUrl => $page) {
                    // same page of same user already known > do nothing
                    if($pageUrl == ($data->page->path->origin . $data->page->path->pathname)) {
                        $pageVisited = true;
                        break;
                    }
                }

                // user known but page not
                if(!$pageVisited) {
                    $this->frontendClients[$data->userid][$data->page->path->origin . $data->page->path->pathname] = array_merge(
                        (array)$data->page,
                        ['tabid' => $data->tabid, 'created' => microtime(true), 'connection' => $client]
                    );
                }
            }

            // tell main process that this plugin permitted the non-backend-client
            if(!isset($data->permitted)) {
                $data->permitted = [];
            }

            $data->permitted[] = 'viewcounter';
            $this->sendViewcountToBackendusers();
        }
    }

    /**
     * provide data to incoming backend users visiting structure-pages
     * @param mixed $data
     * @param ConnectionInterface $client
     * @return void
     * @author Peter Schulze | p.schulze[at]bitshifters.de
     * @created 29.09.2022
     */
    public function onEnteredPage(mixed &$data, ConnectionInterface &$client) {
        $this->sendViewcountToBackenduser($client);
    }

    /**
     * clear connections on close > send fresh status
     * @param mixed $data
     * @param ConnectionInterface $client
     * @return void
     * @author Peter Schulze | p.schulze[at]bitshifters.de
     * @created 29.09.2022
     */
    public function onClose(mixed &$data, ConnectionInterface &$client) {
        CollaborateApplication::echo(sprintf("viewcounter: checking closing of connection %s", $client->resourceId));

        foreach($this->frontendClients as $userId => $pages) {
            foreach($pages as $pageUrl => $pageData) {
                if ($client->resourceId == $pageData["connection"]->resourceId) {
                    //CollaborateApplication::echo(sprintf("viewcounter: deleting page '%s' of connection %s", $pageUrl, $client->resourceId));
                    unset($this->frontendClients[$userId][$pageUrl]);
    
                    // check if FE client is left without pages
                    if(count($this->frontendClients[$userId]) == 0) {
                        // clear FE client
                        unset($this->frontendClients[$userId]);
                        //CollaborateApplication::echo(sprintf("viewcounter: connection %s deleted > no pages left", $client->resourceId));
                    }
                    
                    $this->sendViewcountToBackendusers();
                    break;
                }
            }
        }
    }
}
<?php

namespace Collaborate;

use DateTime;
use Exception;
use Ratchet\Server\IoServer;
use rex_file;
use rex_package;
use rex_path;
use rex_plugin;
use RuntimeException;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use rex_addon;
use rex_user;

/**
 * ratchet application handling all the incoming and outgoing stuff
 * @author Peter Schulze | p.schulze[at]bitshifters.de
 */
class CollaborateApplication implements MessageComponentInterface {
    /**
     * kick inactive clients if they were not seen for x[sec] or longer
     */
    const KICK_INACTIVE_CLIENTS_AFTER = 180;

    /**
     * general broadcast after x[sec]
     * ensuring sync when only 1 active user is left and there is no one else to send data
     */
    const SEND_FINAL_SYNC_AFTER = 300;

    /**
     * reconnect db after x[sec]
     */
    const REFRESH_BACKEND_USERS_AFTER = 1800;

    /**
     * check and update active/enabled plugins after x[sec]
     */
    const REFRESH_ACTIVE_PLUGINS_AFTER = 300;

    /**
     * server instance
     * @var IoServer|null
     */
    private ?IoServer $server = null;

    /**
     * start timestamp
     * @var int
     */
    private int $startTime;

    /**
     * last action
     * @var float
     */
    private float $lastActionTimestamp = 0;

    /**
     * @var array users available
     */
    private array $backendUsers;

    /**
     * storing clients
     */
    private array $clients = [];

    /**
     * storing old clients
     */
    private array $formerClients = [];

    /**
     * event listeners (usually plugin classes)
     * @var array
     */
    protected $plugins = [];

    /**
     * storing registered loops
     * @var array
     */
    private array $loops = [];

    /**
     * constructor
     */
    public function __construct() {
        $this->startTime = time();

        // store backend users
        $this->storeBackendUsers();

        // check registered plugins
        $this->refreshRegisteredPlugins();
    }

    /**
     * @param IoServer $srv
     * @return void
     * @author Peter Schulze | p.schulze[at]bitshifters.de
     * @created 20.09.2022
     */
    public function setServer(IoServer $srv) {
        $this->server = $srv;

        // clear stack
        foreach($this->loops as $loopName => $loopData) {
            if(gettype($loopData) == "array") {
                self::echo("clearing loop stack > adding loop with name '$loopName'");
                $this->loops[$loopName] = $this->server->loop->addPeriodicTimer($loopData[0], $loopData[1]);
            }
        };
    }

    /**
     * get current server
     * @return IoServer
     * @author Peter Schulze | p.schulze[at]bitshifters.de
     * @created 20.09.2022
     */
    public function getServer() {
        return $this->server;
    }

    /**
     * save/update backend users
     * @throws \rex_sql_exception
     * @author Peter Schulze | p.schulze[at]bitshifters.de
     */
    public function storeBackendUsers() {
        CollaborateSql::factory()->checkDatabaseIsAlive();
        $users = CollaborateSql::factory()->getArray("SELECT * FROM rex_user WHERE status = 1");

        foreach($users as $user) {
            $this->backendUsers[$user['login']] = array_merge($user, ['hash' => sha1("#".$user['login']."~".$user['createdate']."+".$user['createuser']."???")]);
        }
    }

    /**
     * @return void
     * @author Peter Schulze | p.schulze[at]bitshifters.de
     */
    public function refreshRegisteredPlugins() {
        // register plugins
        $collaboratePlugins = rex_addon::get("collaborate")->getInstalledPlugins();

        foreach($collaboratePlugins as $plugin) {
            $pluginClass = "\Collaborate\CollaboratePlugin".ucfirst($plugin->getName());

            if(class_exists($pluginClass)) {
                // destruct first
                $pluginAlreadyKnown = false;

                if(isset($this->plugins[$plugin->getName()])) {
                    if($pluginClass::PREVENT_RELOAD) {
                        continue;
                    }

                    unset($this->plugins[$plugin->getName()]);
                    $pluginAlreadyKnown = true;
                }

                $plugin = new $pluginClass($this, $plugin->getName());

                // skip unallowed plugins
                if(!($plugin instanceof CollaboratePlugin)) {
                    continue;
                }

                $this->plugins[$plugin->getName()] = $plugin;

                if(!$pluginAlreadyKnown) {
                    self::echo("plugin registered: {$plugin->getName()}");
                }
            }
        }
    }

    /**
     * output with timestamp and PHP_EOL at the end
     * @param string $msg
     * @param bool $lineBreak
     * @author Peter Schulze | p.schulze[at]bitshifters.de
     */
    public static function echo(string $msg, bool $lineBreak = true) {
        echo "[".((new DateTime())->format('Y-m-d H:i:s.u'))."] - ".$msg.($lineBreak ? PHP_EOL : '');
    }

    /**
     * on connection open
     * @param ConnectionInterface $conn
     * @author Peter Schulze | p.schulze[at]bitshifters.de
     */
    public function onOpen(ConnectionInterface $conn) {
        self::echo("new connection! ({$conn->resourceId}) - in total: ".count($this->clients));

        // publish event to plugins
        $data = null;
        $this->publish('open', $data, $conn);
    }

    /**
     * incoming messages handler
     * @param ConnectionInterface $from
     * @param string $msg
     * @author Peter Schulze | p.schulze[at]bitshifters.de
     */
    public function onMessage(ConnectionInterface $from, $msg) {
        $data = json_decode($msg);

        // prevent "fake permits" ... clients can never permit themselves as valid frontend connections (only plugins can!)
        if(isset($data->permitted)) {
            unset($data->permitted);
        }

        // let plugins handle the message object
        $this->publish('beforeMessage', $data, $from);

        try {
            $this->validateIncomingData($from, $data);
        } catch(RuntimeException $e) {
            // close connection if there are not "tabid" and "type" fields set or if no plugin permits frontend connections
            if($e->getCode() <= 2 || !(isset($data->permitted) && gettype($data->permitted) == 'array')) {
                self::echo($e->getMessage()." ({$e->getCode()})");
                // finally force close for unallowed clients
                $from->close();
                return;
            }

            // it's a permitted frontend connection we do leave open
            return;
        }

        $this->publish('message', $data, $from);

        // if some handler prevents data from beeing published > return
        if($data === null) {
            return;
        }

        if($data->type != "PING" && $data->type != "LOGIN") {
            self::echo("receiving action '{$data->type}' from connection {$from->resourceId} [user: {$data->user}]");
        }
        
        $userAlreadyInStack = false;

        // since we use our own logic to handle connections, we cannot use $this->clients->contains() at this point alone to be 100% sure the client is the same
        // we have to iterate through existing connections, check if there is a match of username and transfer their data to new connection and clear the old connection
        if(!isset($this->clients[spl_object_hash($from)])) {
            foreach($this->clients as $hash => $client) {
                // found old record > migrate
                if(isset($client['user']) && $client['user'] == $data->user && $data->tabid == $client['tabid']) {
                    $this->clients[spl_object_hash($from)] = [
                        "connection" => $from,
                        "user" => $client['user'],
                        "tabid" => $client['tabid'],
                        "data" => $client['data'],
                    ];

                    $userAlreadyInStack = true;
                    self::echo(sprintf("backend user '%s' already in stack (%s) > moved data to %s.", $data->user, $client['connection']->resourceId, $from->resourceId));

                    unset($this->clients[$hash]);
                    break;
                }
            }

            // try to search former clients
            if(!$userAlreadyInStack) {
                 foreach($this->formerClients as $hash => $client) {
                    // found old record > migrate
                    if(isset($client['user']) && $client['user'] == $data->user && $data->tabid == $client['tabid']) {
                        $this->clients[spl_object_hash($from)] = [
                            "connection" => $from,
                            "user" => $client['user'],
                            "tabid" => $client['tabid'],
                            "data" => $client['data'],
                        ];

                        $userAlreadyInStack = true;
                        self::echo(sprintf("backend user '%s' already in stack (%s) [former user] > moved data to %s.", $data->user, $client['connection']->resourceId, $from->resourceId));

                        unset($this->formerClients[$hash]);
                        break;
                    }
                }
            }

            // register new connection
            if(!$userAlreadyInStack) {
                $this->clients[spl_object_hash($from)] = [
                    "connection" => $from,
                    "user" => $data->user,
                    "tabid" => $data->tabid,
                    "data" => ['page' => []]
                ];
            }
        }

        // get current user data
//        echo "client hash: ".spl_object_hash($from)."\n";
        $clientData = $this->clients[spl_object_hash($from)]['data'];

        // merge custom data
        if(isset($data->data)) {
            $clientData = array_merge($clientData, $data->data);
        }

        // check for basic types
        switch($data->type) {
            // login
            case 'LOGIN':
                if(!isset($clientData['loginTimestamp'])) {
                    $clientData['loginTimestamp'] = microtime(true);
                    $clientData['lastAction'] = $data->type;
                }

                self::echo(sprintf("backend user '%s' (%s) connected.", $data->user, $from->resourceId));
                break;

            // logout
            case 'LOGOUT':
                if(isset($this->clients[spl_object_hash($from)])) {
                    unset($this->clients[spl_object_hash($from)]);
                    self::echo(sprintf("backend user '%s' (%s) disconnected.", $data->user, $from->resourceId));
                }

                break;

            // user pings
            case 'PING':
                // update page last action
                $clientData['page']['lastAction'] = microtime(true);
                // general last action timestamp saved below
                break;

            // send page
            case 'PAGE':
                $clientData['lastAction'] = $data->type;
                $params = [];

                if(isset($data->page->params)) {
                    parse_str(trim($data->page->params, '?'), $params);

                    // clear unwanted params
                    if(isset($params['_csrf_token'])) {
                        unset($params['_csrf_token']);
                    }
                    if(isset($params['page'])) {
                        unset($params['page']);
                    }
                }

                // save page
                $clientData['page'] = (array)$data->page;
                $clientData['page']['tabid'] = $data->tabid;

                if(!isset($clientData['page']['created'])) {
                    $clientData['page']['created'] = microtime(true);
                }

                $clientData['page']['lastAction'] = microtime(true);
                $this->publish('enteredPage', $data, $from);
                break;

            // tab/page/browser closed > delete client and broadcast
            case 'PAGE_CLOSE':
                // save former client
                $this->formerClients[spl_object_hash($from)] = $this->clients[spl_object_hash($from)];

                // delete
                unset($this->clients[spl_object_hash($from)]);
                $this->broadcastStatus(null);
                return;

            // check if user is registered on any other actions
            default:
                if(!isset($this->clients[spl_object_hash($from)])) {
                    self::echo("collaborate: non-registered client tried to send a '{$data->type}' message.");
                    throw new RuntimeException("collaborate: non-registered client tried to send a '{$data->type}' message.");
                }
        }

        // set user last action
        $this->setLastActionTimestamp(microtime(true));
        $clientData['lastActionTimestamp'] = microtime(true);

        // store current data
        $this->clients[spl_object_hash($from)]['data'] = $clientData;
        $this->broadcastStatus($from, ($data->type == 'LOGIN' || isset($data->forceupdate)), ($data->type != 'PING'));
    }

    /**
     * send current state to others
     * @param ConnectionInterface|null $initiatingConnection
     * @param bool $includeInitiatingConnection
     * @param bool $logAction echo broadcast result
     * @author Peter Schulze | p.schulze[at]bitshifters.de
     */
    public function broadcastStatus(?ConnectionInterface $initiatingConnection, bool $includeInitiatingConnection = false, bool $logAction = true) {
        $initiatingUser = (is_null($initiatingConnection) ? null : $this->getLoginForConnection($initiatingConnection));
        $receivers = 0;

        // sent to all other users connected
        foreach ($this->clients as $hash => $client) {
            // ignore client that triggered the action
            if(!is_null($initiatingUser) && $initiatingUser == $client['user'] && !$includeInitiatingConnection) {
                continue;
            }

            $data = $this->prepareData($client['connection']);

            // ignore empty data
            if((is_null($data) || !count($data))) {
                continue;
            }

            $this->publish('beforeSend', $data, $client['connection']);

            // ignore empty data after possible manipulation by plugins
            if(!count($data)) {
                continue;
            }

            $data = json_encode(["status" => (array)$data]);

            $client['connection']->send($data);
            $receivers++;
        }

        if($receivers > 0 && $logAction) {
            if(!is_null($initiatingConnection)) {
                self::echo(sprintf('connection %d sent status to %d other connection%s', $initiatingConnection->resourceId, $receivers, $receivers == 1 ? '' : 's'));
            } else {
                self::echo(sprintf('status broadcast to %d other connection%s', $receivers, $receivers == 1 ? '' : 's'));
            }
        }
    }

    /**
     * send custom data to all others
     * @param mixed $data
     * @param ConnectionInterface|null $initiatingConnection
     * @param bool $includeInitiatingConnection
     * @param bool $logAction echo broadcast result
     * @param bool $skipPlugins
     * @author Peter Schulze | p.schulze[at]bitshifters.de
     */
    public function broadcastData(mixed $data, ?ConnectionInterface $initiatingConnection = null, bool $includeInitiatingConnection = false, bool $logAction = true, bool $skipPlugins = false) {
        $initiatingUser = (is_null($initiatingConnection) ? null : $this->getLoginForConnection($initiatingConnection));
        $receivers = 0;

        // sent to all other users connected
        foreach ($this->clients as $hash => $client) {
            // ignore client that triggered the action
            if(!is_null($initiatingUser) && $initiatingUser == $client['user'] && !$includeInitiatingConnection) {
                continue;
            }

            // ignore empty data
            if(is_null($data)) {
                continue;
            }

            if(!$skipPlugins) {
                $this->publish('beforeSend', $data, $client['connection']);
            }

            // ignore empty data after possible manipulation by plugins
            if(is_null($data)) {
                continue;
            }

            $client['connection']->send(json_encode((array)$data));
            $receivers++;
        }

        if($receivers > 0 && $logAction) {
            if(!is_null($initiatingConnection)) {
                self::echo(sprintf('connection %d sent custom data to %d other connection%s', $initiatingConnection->resourceId, $receivers, $receivers == 1 ? '' : 's'));
            } else {
                self::echo(sprintf('custom data broadcast to %d connection%s', $receivers, $receivers == 1 ? '' : 's'));
            }
        }
    }

    /**
     * send current status to dedicated single client
     * @param ConnectionInterface $connection
     * @param bool $logAction
     * @return void
     * @author Peter Schulze | p.schulze[at]bitshifters.de
     * @created 27.09.2022
     */
    public function sendStatusDedicated(ConnectionInterface $connection, bool $logAction = true) {
        $data = $this->prepareData($connection);
        $this->publish('beforeSend', $data, $connection);

        // ignore empty data after possible manipulation by plugins
        if(is_null($data) || !count($data)) {
            return;
        }

        $connection->send(json_encode(["status" => (array)$data]));

        if($logAction) {
            self::echo(sprintf('send status dedicated to connection %d', $connection->resourceId));
        }
    }

    /**
     * send custom data to dedicated single client
     * @param ConnectionInterface $connection
     * @param mixed $data
     * @param bool $logAction
     * @param bool $skipPlugins
     * @return void
     * @author Peter Schulze | p.schulze[at]bitshifters.de
     * @created 27.09.2022
     */
    public function sendDataDedicated(ConnectionInterface $connection, mixed $data, bool $logAction = true, bool $skipPlugins = false) {
        if(!$skipPlugins) {
            $this->publish('beforeSend', $data, $connection);
        }

        // ignore empty data after possible manipulation by plugins
        if(is_null($data)) {
            return;
        }

        $connection->send(json_encode((array)$data));

        if($logAction) {
            self::echo(sprintf('send custom data dedicated to connection %d', $connection->resourceId));
        }
    }

    /**
     * get according login name for given connection
     * @param ConnectionInterface $connection
     * @return string|null
     * @author Peter Schulze | p.schulze[at]bitshifters.de
     */
    public function getLoginForConnection(ConnectionInterface $connection): ?string {
        foreach ($this->clients as $hash => $client) {
            if($hash == spl_object_hash($connection)) {
                return $client['user'];
            }
        }

        return null;
    }

    /**
     * prepare answer object for all connected clients except the provided one
     * @param ConnectionInterface|null $from
     * @return array
     */
    private function prepareData(?ConnectionInterface $from): array {
        $result = [];
        $fromUser = $this->getLoginForConnection($from);
        $fromUserObject = null;
        $collaboratePluginsEnabled = rex_addon::get("collaborate")->getAvailablePlugins();
        $collaboratePlugins = [];
        
        foreach($collaboratePluginsEnabled as $cpe) {
            $collaboratePlugins[] = $cpe->getName();
        }
    
        if(!is_null($fromUser)) {
            rex_user::clearInstance('login_' . $fromUser);
            $fromUserObject = rex_user::forLogin($fromUser);
        }

        // ensure db is alive
        CollaborateSql::factory()->checkDatabaseIsAlive();

        foreach($this->clients as $hash => $client) {
            // skip current user (by user name)
            if(!is_null($fromUser) && $fromUser == $client['user']) {
                //echo "don't send self: ".$client['user']."\n";
                continue;
            }

            // check incomplete data and ignore
            if(!isset($client['data']['lastAction'])) {
                //echo "no last action defined\n";
                continue;
            }

            $index = $this->backendUsers[$client['user']]['hash'];

            // expose username if target has permission
            if($fromUserObject->hasPerm("collaborate[users]") && !isset($result[$index]['username'])) {
                $client['data']['username'] = $this->backendUsers[$client['user']]['name'];
            } else {
                unset($client['data']['loginTimestamp']);
            }

            if(!isset($result[$index])) {
                $result[$index] = $client['data'];
    
                // check if target user is allowed to see page details (perm 'user-locations') OR page has plugin flag (overrides permission restrictions)
                if($fromUserObject->hasPerm("collaborate[user_locations]") || (isset($client['data']['page']['plugin']) && in_array($client['data']['page']['plugin'], $collaboratePlugins))) {
                    $result[$index]['pages'] = [$result[$index]['page']];
                    unset($result[$index]['page']);
                }
            }
            // multiple tabs/instances/browsers
            elseif(is_object($fromUserObject) && ($fromUserObject->hasPerm("collaborate[users]") || $fromUserObject->hasPerm("collaborate[user_locations]"))) {
                // check if target user is allowed to see page details (perm 'user-locations') OR page has plugin flag (overrides permission restrictions)
                if($fromUserObject->hasPerm("collaborate[user_locations]") || (isset($client['data']['page']['plugin']) && in_array($client['data']['page']['plugin'], $collaboratePlugins))) {
                    $result[$index]['pages'][] = $client['data']['page'];
                }

                // take oldest login timestamp
                if($fromUserObject->hasPerm("collaborate[users]") && isset($client['data']['loginTimestamp']) && $client['data']['loginTimestamp'] < $result[$index]['loginTimestamp']) {
                    $result[$index]['loginTimestamp'] = $client['data']['loginTimestamp'];
                }
            }
            
            // final cleanup > don't expose pages to unallowed users
            if(isset($result[$index]['page']) &&
                !($fromUserObject->hasPerm("collaborate[user_locations]") ||
                 (isset($result[$index]['page']['plugin']) && in_array($result[$index]['page']['plugin'], $collaboratePlugins))
                )
            ) {
                unset($result[$index]['page']);
            }
        }

        return $result;
    }

    /**
     * on connection close
     * @param ConnectionInterface $conn
     * @author Peter Schulze | p.schulze[at]bitshifters.de
     */
    public function onClose(ConnectionInterface $conn) {
        // publish event to plugins
        $data = null;
        $this->publish('close', $data, $conn);

        // The connection is closed, remove it, as we can no longer send it messages
        // $this->clients->detach($conn);
        $msg = "connection {$conn->resourceId} has disconnected";

        if(isset($this->clients[spl_object_hash($conn)])) {
            $msg .= " (user: '{$this->clients[spl_object_hash($conn)]['user']}')";
            self::echo($msg);

            // save former client
            // NOTICE: duplicate of PAGE_CLOSE event since FF does not send it when tab/window is closed
            $this->formerClients[spl_object_hash($conn)] = $this->clients[spl_object_hash($conn)];

            // delete
            unset($this->clients[spl_object_hash($conn)]);

            // being lonely is a sad thing ...
            if(count($this->clients) == 1) {
                $firstKey = array_key_first($this->clients);
                $this->sendDataDedicated($this->clients[$firstKey]["connection"], []);
            } else {
                $this->broadcastStatus(null);
            }

            return;
        }

        self::echo($msg);

        // being lonely is a sad thing ...
        if(count($this->clients) == 1) {
            $firstKey = array_key_first($this->clients);
            $this->sendDataDedicated($this->clients[$firstKey]["connection"], []);
            return;
        }
    }

    /**
     * on error
     * @param ConnectionInterface $conn
     * @param Exception $e
     * @author Peter Schulze | p.schulze[at]bitshifters.de
     */
    public function onError(ConnectionInterface $conn, Exception $e) {
        self::echo("ERROR: {$e->getMessage()}");
        $conn->close();
    }

    /**
     * forward event data to plugins
     * @param string $eventName
     * @param $data
     * @param ConnectionInterface $client
     * @author Peter Schulze | p.schulze[at]bitshifters.de
     */
    private function publish(string $eventName, &$data, ConnectionInterface $client) {
        foreach($this->plugins as $plugin) {
            if(method_exists($plugin, 'on'.strtoupper($eventName))) {
                $plugin->{"on".ucfirst($eventName)}($data, $client);
            }
        }
    }

    /**
     * check if all clients are alive and kick inactive ones
     * if atleast one it not > send update to all others
     * @author Peter Schulze | p.schulze[at]bitshifters.de
     */
    public function checkClientsAlive() {
        $foundInactives = 0;

        foreach ($this->clients as $hash => $client) {
            if((int)(microtime(true) - (int)$client['data']['lastActionTimestamp']) >= self::KICK_INACTIVE_CLIENTS_AFTER) {
                // ensure close
//                try {
//                    $client['data']['connection']->close();
//                } catch(\Exception $e) {}

                unset($this->clients[$hash]);

                self::echo(sprintf(
                    "inactive user '%s' kicked after %s seconds. (%s users left in stack)",
                    $client['user'],
                    (microtime(true) - $client['data']['lastActionTimestamp']),
                    count($this->clients)
                ));
                $foundInactives++;
            }
        }

        // clean up former clients
        foreach ($this->formerClients as $hash => $client) {
            if((int)(microtime(true) - $client['data']['lastActionTimestamp']) >= self::KICK_INACTIVE_CLIENTS_AFTER) {
                // ensure close
//                try {
//                    $client['data']['connection']->close();
//                } catch(\Exception $e) {}

                unset($this->formerClients[$hash]);

                self::echo(sprintf(
                    "inactive former user '%s' (%s) kicked after %s seconds. (%s former users left in stack)",
                    $client['user'],
                    $client["connection"]->resourceId,
                    (microtime(true) - $client['data']['lastActionTimestamp']),
                    count($this->formerClients)
                ));
            }
        }

        // tell all others
        if($foundInactives) {
            $this->broadcastStatus(null);
        }
    }

    /**
     * get array of active connections/tabs of user
     * @param string $userName
     * @return array
     * @author Peter Schulze | p.schulze[at]bitshifters.de
     */
    private function getUserTabs(string $userName): array {
        $tabs = [];

        foreach ($this->clients as $hash => $client) {
            if($client['user'] == $userName) {
                $tabs[] = $client['data']['page'];
            }
        }

        return $tabs;
    }

    /**
     * check incoming data for existence of necessary fields
     * @param ConnectionInterface $client
     * @param object $payload
     */
    public function validateIncomingData(ConnectionInterface $client, object $payload) {
        // identify type
        if(!isset($payload->type) || $payload->type == "") {
            throw new RuntimeException("collaborate: missing message type!", 1);
        }
        // identify tab
        elseif(!isset($payload->tabid) || $payload->tabid == "") {
            throw new RuntimeException("collaborate: missing tab id!", 2);
        }
        // identify user
        elseif(!isset($payload->user) || $payload->user == "") {
            throw new RuntimeException("collaborate: unidentified user connected!", 3);
        }
        // check if user is available
        elseif(!isset($this->backendUsers[$payload->user])) {
            throw new RuntimeException("collaborate: user could not be validated!", 4);
        }
        // check if user can identify with hash
        elseif($this->backendUsers[$payload->user]['hash'] !== $payload->userhash) {
            throw new RuntimeException("collaborate: user identification failed!", 5);
        }
    }

    /**
     * @return float
     * @author Peter Schulze | p.schulze[at]bitshifters.de
     */
    public function getLastActionTimestamp(): int {
        return $this->lastActionTimestamp;
    }

    /**
     * @param float $lastActionTimestamp
     * @author Peter Schulze | p.schulze[at]bitshifters.de
     */
    public function setLastActionTimestamp(float $lastActionTimestamp): void {
        $this->lastActionTimestamp = $lastActionTimestamp;
    }
    
    /**
     * returns current clients
     * @return array
     */
    public function getClients(): array {
        return $this->clients;
    }

    /**
     * @return array
     * @author Peter Schulze | p.schulze[at]bitshifters.de
     */
    public function getFormerClients(): array {
        return $this->formerClients;
    }

    /**
     * get backend users
     * @return array
     * @author Peter Schulze | p.schulze[at]bitshifters.de
     */
    public function getBackendUsers(): array {
        return $this->backendUsers;
    }

    /**
     * register loop at
     * @param callable $func
     * @param int $interval
     * @param string $name
     * @author Peter Schulze | p.schulze[at]bitshifters.de
     * @created 20.09.2022
     */
    public function registerLoop(callable $func, int $interval, string $name) {
        // kill existing before
        $this->unregisterLoop($name);

        // server already connected > set loop directly
        if(!is_null($this->server)) {
            $this->loops[$name] = $this->server->loop->addPeriodicTimer($interval, $func);
        }
        // if server is not yet connected (race condition in main thread) > store as array and when setting server later > register loops
        else {
            $this->loops[$name] = [$interval, $func];
        }
    }

    /**
     * unregister named loop
     * @param string $name
     * @return bool
     * @author Peter Schulze | p.schulze[at]bitshifters.de
     * @created 20.09.2022
     */
    public function unregisterLoop(string $name) {
        if(isset($this->loops[$name])) {
            unset($this->loops[$name]);
            return true;
        }

        return false;
    }
}
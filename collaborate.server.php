<?php
use Collaborate\CollaborateApplication;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Loop;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;

ignore_user_abort(TRUE);

// init redaxo
unset($REX);
$REX['REDAXO'] = true;
$REX['HTDOCS_PATH'] = __DIR__.'/../../../../'; // './';
$REX['BACKEND_FOLDER'] = 'redaxo';
$REX['LOAD_PAGE'] = false;

require $REX['HTDOCS_PATH'].$REX['BACKEND_FOLDER'].'/src/core/boot.php';
require rex_path::core('packages.php');

// init ratchet
require dirname(__DIR__) . '/collaborate/lib/ratchet/vendor/autoload.php';
require dirname(__DIR__) . '/collaborate/lib/collaborate.sql.php';

$yaml = Symfony\Component\Yaml\Yaml::parse(file_get_contents(dirname(__DIR__)."/collaborate/".rex_package::FILE_PACKAGE));
$port = $yaml['websocket-server-port'];

// interrupt redaxo output buffer handling
@ob_end_clean();

// SSL Attempt:
$application = new CollaborateApplication();
$httpServer = new HttpServer(
    new WsServer(
        $application
    )
);

$loop = Loop::get();
$server = new SocketServer('0.0.0.0:'.$port);
//$server = new SecureServer($server, $loop, array(
//    'local_cert' => $yaml['local-cert-path'],
//    'local_pk' => $yaml['local-private-key-path'],
//    'verify_peer' => false
//));

// show errors in console
$server->on('error', function (Exception $e) {
    CollaborateApplication::echo('Error' . $e->getMessage());
});

// say hello :)
// NOTE: IP is hidden if you route proxypass internally (than it's 127.0.0.1 or 0.0.0.0 for all clients)
$server->on('connection', function (ConnectionInterface $connection) {
    //var_dump(stream_context_get_params($connection->stream)); // ->httpRequest->getUri()->getQuery()
//    echo 'Secure connection from ' . $connection->getRemoteAddress() . " - PHP: ".phpversion().PHP_EOL;
});

$secureWebsocketsServer = new IoServer($httpServer, $server, $loop);
$application->setServer($secureWebsocketsServer);
CollaborateApplication::echo("--- collaborate websocket server is running on server port $port [".$server->getAddress()."] ---");

// keep alive check for all stored clients
$secureWebsocketsServer->loop->addPeriodicTimer(CollaborateApplication::KICK_INACTIVE_CLIENTS_AFTER, function() use ($application) {
    $application->checkClientsAlive();
});

// send ping to last active user to ensure he didn't miss pre-last users disconnect
$secureWebsocketsServer->loop->addPeriodicTimer(CollaborateApplication::SEND_FINAL_SYNC_AFTER, function() use ($application) {
    if(count($application->getClients()) <> 1) {
        return;
    }

    $firstKey = array_key_first($application->getClients());

    if(isset($application->getClients()[$firstKey]["connection"])) {
        $application->sendDataDedicated($application->getClients()[$firstKey]["connection"], []);
        $application::echo("sending ping to last active user (".$application->getClients()[$firstKey]["connection"]->resourceId.")");
    }
});

// refresh active plugins
$secureWebsocketsServer->loop->addPeriodicTimer(CollaborateApplication::REFRESH_ACTIVE_PLUGINS_AFTER, function() use ($application) {
    $application->refreshRegisteredPlugins();
});

// refresh users
$secureWebsocketsServer->loop->addPeriodicTimer(CollaborateApplication::REFRESH_BACKEND_USERS_AFTER, function() use ($application) {
    $formerUsers = count($application->getBackendUsers());
    $application->storeBackendUsers();

    if($formerUsers != count($application->getBackendUsers())) {
        $application::echo(sprintf("storing/updating backend user stack: found %s, before: %s", count($application->getBackendUsers()), $formerUsers));
    }
});

try {
    $secureWebsocketsServer->run();
} catch(RuntimeException $e) {
    echo '!!! collaborate server failed starting:'."\n";
    printf($e->getMessage());
}
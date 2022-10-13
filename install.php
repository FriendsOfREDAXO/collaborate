<?php
$addon = rex_addon::get('collaborate');

// write daemon config
$daemonConfigPath = $addon->getPath()."conf".DIRECTORY_SEPARATOR."collaborate-dummy.service";

if(!file_exists($daemonConfigPath)) {
    throw new rex_functional_exception($addon->i18n("error_install_daemon_config"));
}

$daemonConfigContent = rex_file::get($daemonConfigPath);
$daemonConfigContent = str_replace("##COLLABORATE_INIT_SCRIPT_PATH##", $addon->getPath()."collaborate.server.php", $daemonConfigContent);
$daemonConfigContent = str_replace("##COLLABORATE_SCRIPT_PATH##", $addon->getPath()."conf/collaborate.service", $daemonConfigContent);
$daemonConfigContent = str_replace("##COLLABORATE_LOG_PATH##", $addon->getDataPath("collaborate.log"), $daemonConfigContent);
$daemonConfigContent = str_replace("##COLLABORATE_PROJECT##", rex::getServerName(), $daemonConfigContent);
$daemonConfigContent = str_replace("##COLLABORATE_BASENAME##", 'collaborate_websocket_'.rex_string::normalize(rex::getServerName()), $daemonConfigContent);
$daemonConfigContent = str_replace("##COLLABORATE_LOGROTATE_PATH##", $addon->getPath("conf/collaborate-logrotate.conf"), $daemonConfigContent);
rex_file::put($addon->getPath()."conf".DIRECTORY_SEPARATOR."collaborate.service", $daemonConfigContent);

// set logrotate config
$dummyLogrotatePath = $addon->getPath()."conf".DIRECTORY_SEPARATOR."collaborate-logrotate-dummy.conf";

if(!file_exists($dummyLogrotatePath)) {
    throw new rex_functional_exception($addon->i18n("error_install_logrotate_config"));
}

$logrotateConfigContent = rex_file::get($dummyLogrotatePath);
$logrotateConfigFilePath = $addon->getPath()."conf".DIRECTORY_SEPARATOR."collaborate-logrotate.conf";

rex_file::put($logrotateConfigFilePath,
    str_replace("##COLLABORATE_LOG_PATH##", $addon->getDataPath("collaborate.log"), $logrotateConfigContent)
);

if(file_exists($logrotateConfigFilePath)) {
    try {
        chmod($logrotateConfigFilePath, 0644);
    } catch(Exception $e){
        echo $e->getMessage();
    }
}

// set supervisord config
$dummyConfigPath = $addon->getPath()."conf".DIRECTORY_SEPARATOR."collaborate-supervisor-dummy.conf";

if(!file_exists($dummyConfigPath)) {
    throw new rex_functional_exception($addon->i18n("error_install_supervisor_config"));
}

$svConfigContent = rex_file::get($dummyConfigPath);
rex_file::put($addon->getPath()."conf".DIRECTORY_SEPARATOR."collaborate-supervisor.conf",
    str_replace("##COLLABORATE_INIT_SCRIPT_PATH##", $addon->getPath()."collaborate.run.php", $svConfigContent)
);

// create log file if not existing
$logFile = $addon->getDataPath("collaborate.log");

if(!file_exists($logFile)) {
    rex_file::put($logFile, "");
}


// set port to config
if (!file_exists($addon->getPath()."package.yml")) {
    throw new Exception(rex_i18n::msg('package_missing_yml_file'));
}

try {
    $config = rex_file::getConfig($addon->getPath()."package.yml");

    $addon->setConfig("websocket-client-port", $config["websocket-client-port"]);
    $addon->setConfig("websocket-server-port", $config["websocket-server-port"]);
    $addon->setConfig("websocket-path", $config["websocket-path"]);
//    $addon->setConfig("local-cert-path", $config["local-cert-path"]);
//    $addon->setConfig("local-private-key-path", $config["local-private-key-path"]);
} catch (rex_yaml_parse_exception $e) {
    echo rex_view::error(rex_i18n::msg('package_invalid_yml_file').' '.$e->getMessage());
    return;
}

rex_config::set("collaborate", "port", (isset($config["websocket-port"]) ? $config["websocket-port"] : 8080));
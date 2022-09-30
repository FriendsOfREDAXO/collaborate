<?php

namespace Collaborate;

use \rex_sql;
/**
 * rex_sql with keep alive check
 */
class CollaborateSql extends rex_sql {
    /**
     * check if connection is alive, reconnect if failing
     * @author Peter Schulze | p.schulze[at]bitshifters.de
     */
    public function checkDatabaseIsAlive() {
        // ensure field entry `self::$pdo[$this->DBID]`
        try {
            $this->selectDB($this->DBID);
        } catch(\Throwable $e) {
            CollaborateApplication::echo("Keep alive check: selectDB() failed!");
        }

        // if this entry is missing, something else is wrong
        if(!isset(self::$pdo[$this->DBID])) {
            return;
        }

        try {
            // dummy statement
            $this->setQuery("SELECT 1");
        } catch (\Throwable $e) {
            CollaborateApplication::echo("Database maybe timed out -> reconnect ...");
            $dbconfig = rex::getDbConfig($this->DBID);

            if ($dbconfig->sslKey && $dbconfig->sslCert && $dbconfig->sslCa) {
                $options = [
                    PDO::MYSQL_ATTR_SSL_KEY => $dbconfig->sslKey,
                    PDO::MYSQL_ATTR_SSL_CERT => $dbconfig->sslCert,
                    PDO::MYSQL_ATTR_SSL_CA => $dbconfig->sslCa,
                ];
            }

            $conn = self::createConnection(
                $dbconfig->host,
                $dbconfig->name,
                $dbconfig->login,
                $dbconfig->password,
                $dbconfig->persistent,
                $options
            );
            self::$pdo[$this->DBID] = $conn;

            $this->setQuery('SET SESSION SQL_MODE="", NAMES utf8mb4');
        }
    }
}
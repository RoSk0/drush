<?php

namespace Drush\Sql;

define('PSQL_SHOW_TABLES', "SELECT tablename FROM pg_tables WHERE schemaname='%schema%';");

class SqlPgsql extends SqlBase
{

    public $queryExtra = "--no-align --field-separator=\"\t\" --pset tuples_only=on";

    public $queryFile = "--file";

    private $password_file = null;

    private function createPasswordFile()
    {
        $password_file = null;
        $dbSpec = $this->getDbSpec();
        if (null !== ($this->getPasswordFile()) && isset($dbSpec['password'])) {
            $pgpass_parts = array(
            empty($dbSpec['host']) ? 'localhost' : $dbSpec['host'],
            empty($dbSpec['port']) ? '5432' : $dbSpec['port'],
            // Database
            '*',
            $dbSpec['username'],
            $dbSpec['password']
            );
            // Escape colon and backslash characters in entries.
            // @see http://www.postgresql.org/docs/9.1/static/libpq-pgpass.html
            array_walk($pgpass_parts, function (&$part) {
                  // The order of the replacements is important so that backslashes are
                  // not replaced twice.
                  $part = str_replace(array('\\', ':'), array('\\\\', '\:'), $part);
            });
            $pgpass_contents = implode(':', $pgpass_parts);
            $password_file = drush_save_data_to_temp_file($pgpass_contents);
            chmod($password_file, 0600);
        }
        return $password_file;
    }

    public function command()
    {
        $environment = "";
        $pw_file = $this->createPasswordFile();
        if (isset($pw_file)) {
            $environment = "PGPASSFILE={$pw_file} ";
        }
        return "{$environment}psql -q";
    }

    /*
     * @param $hide_password
     *   Not used in postgres. Use .pgpass file instead. See http://drupal.org/node/438828.
     */
    public function creds($hide_password = true)
    {
        $dbSpec = $this->getDbSpec();
        // Some drush commands (e.g. site-install) want to connect to the
        // server, but not the database.  Connect to the built-in database.
        $parameters['dbname'] = empty($dbSpec['database']) ? 'template1' : $dbSpec['database'];

        // Host and port are optional but have defaults.
        $parameters['host'] = empty($dbSpec['host']) ? 'localhost' : $dbSpec['host'];
        $parameters['port'] = empty($dbSpec['port']) ? '5432' : $dbSpec['port'];

        // Username is required.
        $parameters['username'] = $dbSpec['username'];

        // Don't set the password.
        // @see http://drupal.org/node/438828

        return $this->paramsToOptions($parameters);
    }

    public function createdbSql($dbname, $quoted = false)
    {
        if ($quoted) {
            $dbname = '`' . $dbname . '`';
        }
        $sql[] = sprintf('drop database if exists %s;', $dbname);
        $sql[] = sprintf("create database %s ENCODING 'UTF8';", $dbname);
        return implode(' ', $sql);
    }

    public function dbExists()
    {
        $dbSpec = $this->getDbSpec();
        $database = $dbSpec['database'];
        // Get a new class instance that has no 'database'.
        $db_spec_no_db = $dbSpec;
        unset($db_spec_no_db['database']);
        $sql_no_db = new SqlPgsql($db_spec_no_db, $this->getOptions());
        $query = "SELECT 1 AS result FROM pg_database WHERE datname='$database'";
        drush_always_exec($sql_no_db->connect() . ' -t -c %s', $query);
        $output = drush_shell_exec_output();
        return (bool)$output[0];
    }

    public function queryFormat($query)
    {
        if ($query === PSQL_SHOW_TABLES) {
            $schema = $this->getSchemaName();
            return str_replace('%schema%', $schema, PSQL_SHOW_TABLES);
        }
        return $query;
    }

    public function listTables()
    {
        $this->alwaysQuery(PSQL_SHOW_TABLES);
        $tables = drush_shell_exec_output();
        if (!empty($tables)) {
            return $tables;
        }
        return array();
    }

    public function dumpCmd($table_selection)
    {
        $parens = false;
        $skip_tables = $table_selection['skip'];
        $structure_tables = $table_selection['structure'];
        $tables = $table_selection['tables'];

        $ignores = array();
        $skip_tables  = array_merge($structure_tables, $skip_tables);
        $data_only = $this->getOption('data-only');

        $create_db = $this->getOption('create-db');
        $exec = 'pg_dump ';
        // Unlike psql, pg_dump does not take a '--dbname=' before the database name.
        $extra = str_replace('--dbname=', ' ', $this->creds());
        if (isset($data_only)) {
            $extra .= ' --data-only';
        }
        if ($option = $this->getOption('extra-dump', $this->queryExtra)) {
            $extra .= " $option";
        }
        $exec .= $extra;
        $exec .= (!isset($create_db) && !isset($data_only) ? ' --clean' : '');

        if (!empty($tables)) {
            foreach ($tables as $table) {
                $exec .= " --table=$table";
            }
        } else {
            foreach ($skip_tables as $table) {
                $ignores[] = "--exclude-table=$table";
            }
            $exec .= ' '. implode(' ', $ignores);
            // Run pg_dump again and append output if we need some structure only tables.
            if (!empty($structure_tables)) {
                $parens = true;
                $schemaonlies = array();
                foreach ($structure_tables as $table) {
                    $schemaonlies[] = "--table=$table";
                }
                $exec .= " && pg_dump --schema-only " . implode(' ', $schemaonlies) . $extra;
                $exec .= (!isset($create_db) && !isset($data_only) ? ' --clean' : '');
            }
        }
        return $parens ? "($exec)" : $exec;
    }

    /**
     * @return string|null
     */
    public function getPasswordFile()
    {
        return $this->password_file;
    }

    /**
     * Get schema name.
     *
     * @return string
     */
    public function getSchemaName()
    {
        $schema = 'public';
        $dbSpec = $this->getDbSpec();
        if (!empty($dbSpec['prefix'])) {
            $prefix = $dbSpec['prefix'];

            if (is_array($dbSpec['prefix'])) {
                $prefix = $dbSpec['prefix']['default'];
            }

            if (strpos($prefix, '.') !== false) {
                list($schema,) = explode('.', $prefix);
            }
        }
        return $schema;
    }

    /**
     * {@inheritdoc}
     */
    public function drop($tables)
    {
        $return = true;

        if ($tables) {
            $schema = $this->getSchemaName();
            if ($schema !== 'public') {
                array_walk($tables, function (&$table, $index, $schema) {
                    $table = $schema . '.' . $table;
                }, $schema);
            }

            $set = array_chunk($tables, 100);
            foreach ($set as $tables_set) {
                $sql = 'DROP TABLE '. implode(', ', $tables_set);
                $return = $this->query($sql);
            }
        }

        return $return;
    }
}

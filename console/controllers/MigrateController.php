<?php
/**
 * @link http://www.diemeisterei.de/
 * @copyright Copyright (c) 2014 diemeisterei GmbH, Stuttgart
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace console\controllers;

use Yii;
use yii\console\Controller;
use yii\console\Exception;
use yii\db\Connection;
use yii\db\Query;
use yii\helpers\ArrayHelper;
use yii\helpers\FileHelper;

/**
 * Manages application and extension migrations (dmstr/yii2-migrate-command).
 *
 * Spin-off from https://github.com/yiisoft/yii2/pull/3273/files
 *
 * A migration means a set of persistent changes to the application environment
 * that is shared among different developers. For example, in an application
 * backed by a database, a migration may refer to a set of changes to
 * the database, such as creating a new table, adding a new table column.
 *
 * This command provides support for tracking the migration history, upgrading
 * or downloading with migrations, and creating new migration skeletons.
 *
 * The migration history is stored in a database table named
 * as [[migrationTable]]. The table will be automatically created the first time
 * this command is executed, if it does not exist. You may also manually
 * create it as follows:
 *
 * ~~~
 * CREATE TABLE migration (
 *     version varchar(180) PRIMARY KEY,
 *     version alias(180),
 *     apply_time integer
 * )
 * ~~~
 *
 * You may configure additional migration paths using the application param `yii.migrations`
 *
 * Below are some common usages of this command:
 *
 * ~~~
 * # creates a new migration named 'create_user_table'
 * yii migrate/create create_user_table
 *
 * # applies ALL new migrations
 * yii migrate
 *
 * # reverts the last applied migration
 * yii migrate/down
 * ~~~
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @author Tobias Munk <schmunk@usrbin.de>
 * @since 1.0
 */
class MigrateController extends Controller
{
    /**
     * The name of the dummy migration that marks the beginning of the whole migration history.
     */
    const BASE_MIGRATION = 'm000000_000000_base';

    /**
     * @var string the default command action.
     */
    public $defaultAction = 'up';
    /**
     * @var string the directory storing the migration classes. This can be either
     * a path alias or a directory.
     */
    public $migrationPath = '@app/migrations';
    /**
     * @var array additional aliases of migration directories
     */
    public $migrationLookup = [];
    /**
     * @var boolean lookup all application migration paths
     */
    public $disableLookup = false;
    /**
     * @var string the name of the table for keeping applied migration information.
     */
    public $migrationTable = '{{%migration}}';
    /**
     * @var string the template file for generating new migrations.
     * This can be either a path alias (e.g. "@app/migrations/template.php")
     * or a file path.
     */
    public $templateFile = '@yii/views/migration.php';
    /**
     * @var boolean whether to execute the migration in an interactive mode.
     */
    public $interactive = true;
    /**
     * @var Connection|string the DB connection object or the application
     * component ID of the DB connection.
     */
    public $db = 'db';

    /**
     * @inheritdoc
     */
    public function options($actionId)
    {
        return array_merge(
            parent::options($actionId),
            ['migrationPath', 'migrationLookup', 'disableLookup', 'migrationTable', 'db'], // global for all actions
            ($actionId == 'create') ? ['templateFile'] : [] // action create
        );
    }

    /**
     * This method is invoked right before an action is to be executed (after all possible filters.)
     * It checks the existence of the [[migrationPath]].
     *
     * @param \yii\base\Action $action the action to be executed.
     *
     * @throws Exception if db component isn't configured
     * @return boolean whether the action should continue to be executed.
     */
    public function beforeAction($action)
    {
        if (parent::beforeAction($action)) {
            $path = Yii::getAlias($this->migrationPath);

            if ($action->id !== 'create') {
                if (is_string($this->db)) {
                    $this->db = Yii::$app->get($this->db);
                }
                if (!$this->db instanceof Connection) {
                    throw new Exception("The 'db' option must refer to the application component ID of a DB connection.");
                }
            } else {
                if (!is_dir($path)) {
                    echo "\n$path does not exist, creating...";
                    FileHelper::createDirectory($path);
                }
            }

            $version = Yii::getVersion();
            echo "Yii Migration Tool (based on Yii v{$version})\n\n";
            if (isset($this->db->dsn)) {
                echo "Database Connection: " . $this->db->dsn . "\n";
            }
            return true;
        } else {
            return false;
        }
    }

    /**
     * Upgrades the application by applying new migrations.
     * For example,
     *
     * ~~~
     * yii migrate     # apply all new migrations
     * yii migrate 3   # apply the first 3 new migrations
     * ~~~
     *
     * @param integer $limit the number of new migrations to be applied. If 0, it means
     * applying all available new migrations.
     */
    public function actionUp($limit = 0)
    {
        $migrations = $this->getNewMigrations();
        if (empty($migrations)) {
            echo "No new migration found. Your system is up-to-date.\n";

            return;
        }

        $total = count($migrations);
        $limit = (int)$limit;
        if ($limit > 0) {
            $migrations = array_slice($migrations, 0, $limit);
        }

        $n = count($migrations);
        if ($n === $total) {
            echo "Total $n new " . ($n === 1 ? 'migration' : 'migrations') . " to be applied:\n";
        } else {
            echo "Total $n out of $total new " . ($total === 1 ? 'migration' : 'migrations') . " to be applied:\n";
        }

        echo "\nMigrations:\n";
        foreach ($migrations as $migration => $alias) {
            echo "    " . $migration . " (" . $alias . ")\n";
        }

        if ($this->confirm('Apply the above ' . ($n === 1 ? 'migration' : 'migrations') . "?")) {
            foreach ($migrations as $migration => $alias) {
                if (!$this->migrateUp($migration, $alias)) {
                    echo "\nMigration failed. The rest of the migrations are canceled.\n";

                    return;
                }
            }
            echo "\nMigrated up successfully.\n";
        }
    }

    /**
     * Downgrades the application by reverting old migrations.
     * For example,
     *
     * ~~~
     * yii migrate/down     # revert the last migration
     * yii migrate/down 3   # revert the last 3 migrations
     * ~~~
     *
     * @param integer $limit the number of migrations to be reverted. Defaults to 1,
     * meaning the last applied migration will be reverted.
     *
     * @throws Exception if the number of the steps specified is less than 1.
     */
    public function actionDown($limit = 1)
    {
        $limit = (int)$limit;
        if ($limit < 1) {
            throw new Exception("The step argument must be greater than 0.");
        }

        $migrations = $this->getMigrationHistory($limit);
        if (empty($migrations)) {
            echo "No migration has been done before.\n";

            return;
        }

        $n = count($migrations);
        echo "Total $n " . ($n === 1 ? 'migration' : 'migrations') . " to be reverted:\n";
        foreach ($migrations as $migration => $info) {
            echo "    $migration (" . $info['alias'] . ")\n";
        }
        echo "\n";

        if ($this->confirm('Revert the above ' . ($n === 1 ? 'migration' : 'migrations') . "?")) {
            foreach ($migrations as $migration => $info) {
                if (!$this->migrateDown($migration, $info['alias'])) {
                    echo "\nMigration failed. The rest of the migrations are canceled.\n";

                    return;
                }
            }
            echo "\nMigrated down successfully.\n";
        }
    }

    /**
     * Redoes the last few migrations.
     *
     * This command will first revert the specified migrations, and then apply
     * them again. For example,
     *
     * ~~~
     * yii migrate/redo     # redo the last applied migration
     * yii migrate/redo 3   # redo the last 3 applied migrations
     * ~~~
     *
     * @param integer $limit the number of migrations to be redone. Defaults to 1,
     * meaning the last applied migration will be redone.
     *
     * @throws Exception if the number of the steps specified is less than 1.
     */
    public function actionRedo($limit = 1)
    {
        $limit = (int)$limit;
        if ($limit < 1) {
            throw new Exception("The step argument must be greater than 0.");
        }

        $migrations = $this->getMigrationHistory($limit);
        if (empty($migrations)) {
            echo "No migration has been done before.\n";

            return;
        }

        $n = count($migrations);
        echo "Total $n " . ($n === 1 ? 'migration' : 'migrations') . " to be redone:\n";
        foreach ($migrations as $migration => $info) {
            echo "    $migration\n";
        }
        echo "\n";

        if ($this->confirm('Redo the above ' . ($n === 1 ? 'migration' : 'migrations') . "?")) {
            foreach ($migrations as $migration => $info) {
                if (!$this->migrateDown($migration, $info['alias'])) {
                    echo "\nMigration failed. The rest of the migrations are canceled.\n";

                    return;
                }
            }
            foreach (array_reverse($migrations) as $migration => $info) {
                if (!$this->migrateUp($migration, $info['alias'])) {
                    echo "\nMigration failed. The rest of the migrations migrations are canceled.\n";

                    return;
                }
            }
            echo "\nMigration redone successfully.\n";
        }
    }

    /**
     * Upgrades or downgrades till the specified version.
     *
     * Can also downgrade versions to the certain apply time in the past by providing
     * a UNIX timestamp or a string parseable by the strtotime() function. This means
     * that all the versions applied after the specified certain time would be reverted.
     *
     * This command will first revert the specified migrations, and then apply
     * them again. For example,
     *
     * ~~~
     * yii migrate/to 101129_185401                      # using timestamp
     * yii migrate/to m101129_185401_create_user_table   # using full name
     * yii migrate/to 1392853618                         # using UNIX timestamp
     * yii migrate/to "2014-02-15 13:00:50"              # using strtotime() parseable string
     * ~~~
     *
     * @param string $version either the version name or the certain time value in the past
     * that the application should be migrated to. This can be either the timestamp,
     * the full name of the migration, the UNIX timestamp, or the parseable datetime
     * string.
     *
     * @throws Exception if the version argument is invalid.
     */
    public function actionTo($version)
    {
        if (preg_match('/^m?(\d{6}_\d{6})(_.*?)?$/', $version, $matches)) {
            $this->migrateToVersion('m' . $matches[1]);
        } elseif ((string)(int)$version == $version) {
            $this->migrateToTime($version);
        } elseif (($time = strtotime($version)) !== false) {
            $this->migrateToTime($time);
        } else {
            throw new Exception("The version argument must be either a timestamp (e.g. 101129_185401),\n the full name of a migration (e.g. m101129_185401_create_user_table),\n a UNIX timestamp (e.g. 1392853000), or a datetime string parseable\nby the strtotime() function (e.g. 2014-02-15 13:00:50).");
        }
    }

    /**
     * Modifies the migration history to the specified version.
     *
     * No actual migration will be performed.
     *
     * ~~~
     * yii migrate/mark 101129_185401                      # using timestamp
     * yii migrate/mark m101129_185401_create_user_table   # using full name
     * ~~~
     *
     * @param string $version the version at which the migration history should be marked.
     * This can be either the timestamp or the full name of the migration.
     *
     * @throws Exception if the version argument is invalid or the version cannot be found.
     */
    public function actionMark($version)
    {
        $originalVersion = $version;
        if (preg_match('/^m?(\d{6}_\d{6})(_.*?)?$/', $version, $matches)) {
            $version = 'm' . $matches[1];
        } else {
            throw new Exception("The version argument must be either a timestamp (e.g. 101129_185401)\nor the full name of a migration (e.g. m101129_185401_create_user_table).");
        }

        // try mark up
        $migrations = $this->getNewMigrations();
        $i = 0;
        foreach ($migrations as $migration => $alias) {
            $stack[$migration] = $alias;
            if (strpos($migration, $version . '_') === 0) {
                if ($this->confirm("Set migration history at $originalVersion?")) {
                    $command = $this->db->createCommand();
                    foreach ($stack AS $applyMigration => $applyAlias) {
                        $command->insert(
                            $this->migrationTable,
                            [
                                'version' => $applyMigration,
                                'alias' => $applyAlias,
                                'apply_time' => time(),
                            ]
                        )->execute();
                    }
                    echo "The migration history is set at $originalVersion.\nNo actual migration was performed.\n";
                }

                return;
            }
            $i++;
        }

        // try mark down
        $migrations = array_keys($this->getMigrationHistory(-1));
        foreach ($migrations as $i => $migration) {
            if (strpos($migration, $version . '_') === 0) {
                if ($i === 0) {
                    echo "Already at '$originalVersion'. Nothing needs to be done.\n";
                } else {
                    if ($this->confirm("Set migration history at $originalVersion?")) {
                        $command = $this->db->createCommand();
                        for ($j = 0; $j < $i; ++$j) {
                            $command->delete(
                                $this->migrationTable,
                                [
                                    'version' => $migrations[$j],
                                ]
                            )->execute();
                        }
                        echo "The migration history is set at $originalVersion.\nNo actual migration was performed.\n";
                    }
                }

                return;
            }
        }

        throw new Exception("Unable to find the version '$originalVersion'.");
    }

    /**
     * Displays the migration history.
     *
     * This command will show the list of migrations that have been applied
     * so far. For example,
     *
     * ~~~
     * yii migrate/history     # showing the last 10 migrations
     * yii migrate/history 5   # showing the last 5 migrations
     * yii migrate/history 0   # showing the whole history
     * ~~~
     *
     * @param integer $limit the maximum number of migrations to be displayed.
     * If it is 0, the whole migration history will be displayed.
     */
    public function actionHistory($limit = 10)
    {
        $limit = (int)$limit;
        $migrations = $this->getMigrationHistory($limit);
        if (empty($migrations)) {
            echo "No migration has been done before.\n";
        } else {
            $n = count($migrations);
            if ($limit > 0) {
                echo "Showing the last $n applied " . ($n === 1 ? 'migration' : 'migrations') . ":\n";
            } else {
                echo "Total $n " . ($n === 1 ? 'migration has' : 'migrations have') . " been applied before:\n";
            }
            foreach ($migrations as $version => $info) {
                echo "    (" . date('Y-m-d H:i:s', $info['apply_time']) . ') ' . $version . "\n";
            }
        }
    }

    /**
     * Displays the un-applied new migrations.
     *
     * This command will show the new migrations that have not been applied.
     * For example,
     *
     * ~~~
     * yii migrate/new     # showing the first 10 new migrations
     * yii migrate/new 5   # showing the first 5 new migrations
     * yii migrate/new 0   # showing all new migrations
     * ~~~
     *
     * @param integer $limit the maximum number of new migrations to be displayed.
     * If it is 0, all available new migrations will be displayed.
     */
    public function actionNew($limit = 10)
    {
        $limit = (int)$limit;
        $migrations = $this->getNewMigrations();
        if (empty($migrations)) {
            echo "No new migrations found. Your system is up-to-date.\n";
        } else {
            $n = count($migrations);
            if ($limit > 0 && $n > $limit) {
                $migrations = array_slice($migrations, 0, $limit);
                echo "Showing $limit out of $n new " . ($n === 1 ? 'migration' : 'migrations') . ":\n";
            } else {
                echo "Found $n new " . ($n === 1 ? 'migration' : 'migrations') . ":\n";
            }

            foreach ($migrations as $migration => $alias) {
                echo "    " . $migration . " (" . $alias . ")" . "\n";
            }
        }
    }

    /**
     * Creates a new migration.
     *
     * This command creates a new migration using the available migration template.
     * After using this command, developers should modify the created migration
     * skeleton by filling up the actual migration logic.
     *
     * ~~~
     * yii migrate/create create_user_table
     * ~~~
     *
     * @param string $name the name of the new migration. This should only contain
     * letters, digits and/or underscores.
     *
     * @throws Exception if the name argument is invalid.
     */
    public function actionCreate($name)
    {
        if (!preg_match('/^\w+$/', $name)) {
            throw new Exception("The migration name should contain letters, digits and/or underscore characters only.");
        }

        $name = 'm' . gmdate('ymd_His') . '_' . $name;
        $file = Yii::getAlias($this->migrationPath) . DIRECTORY_SEPARATOR . $name . '.php';

        if ($this->confirm("Create new migration '$file'?")) {
            $content = $this->renderFile(Yii::getAlias($this->templateFile), ['className' => $name]);
            file_put_contents(Yii::getAlias($file), $content);
            echo "New migration created successfully.\n";
        }
    }

    /**
     * Upgrades with the specified migration class.
     *
     * @param string $class the migration class name
     *
     * @return boolean whether the migration is successful
     */
    protected function migrateUp($class, $alias)
    {
        if ($class === self::BASE_MIGRATION) {
            return true;
        }

        echo "*** applying $class\n";
        $start = microtime(true);
        $migration = $this->createMigration($class, $alias);
        if ($migration->up() !== false) {
            $this->db->createCommand()->insert(
                $this->migrationTable,
                [
                    'version' => $class,
                    'alias' => $alias,
                    'apply_time' => time(),
                ]
            )->execute();
            $time = microtime(true) - $start;
            echo "*** applied $class (time: " . sprintf("%.3f", $time) . "s)\n\n";

            return true;
        } else {
            $time = microtime(true) - $start;
            echo "*** failed to apply $class (time: " . sprintf("%.3f", $time) . "s)\n\n";

            return false;
        }
    }

    /**
     * Downgrades with the specified migration class.
     *
     * @param string $class the migration class name
     *
     * @return boolean whether the migration is successful
     */
    protected function migrateDown($class, $alias)
    {
        if ($class === self::BASE_MIGRATION) {
            return true;
        }

        echo "*** reverting $class\n";
        $start = microtime(true);
        $migration = $this->createMigration($class, $alias);
        if ($migration->down() !== false) {
            $this->db->createCommand()->delete(
                $this->migrationTable,
                [
                    'version' => $class,
                ]
            )->execute();
            $time = microtime(true) - $start;
            echo "*** reverted $class (time: " . sprintf("%.3f", $time) . "s)\n\n";

            return true;
        } else {
            $time = microtime(true) - $start;
            echo "*** failed to revert $class (time: " . sprintf("%.3f", $time) . "s)\n\n";

            return false;
        }
    }

    /**
     * Creates a new migration instance.
     *
     * @param string $class the migration class name
     *
     * @return \yii\db\Migration the migration instance
     */
    protected function createMigration($class, $alias)
    {
        $file = $class . '.php';
        require_once(\Yii::getAlias($alias) . '/' . $file);

        return new $class(['db' => $this->db]);
    }

    /**
     * Migrates to the specified apply time in the past.
     *
     * @param integer $time UNIX timestamp value.
     */
    protected function migrateToTime($time)
    {
        $count = 0;
        $migrations = array_values($this->getMigrationHistory(-1));
        while ($count < count($migrations) && $migrations[$count] > $time) {
            ++$count;
        }
        if ($count === 0) {
            echo "Nothing needs to be done.\n";
        } else {
            $this->actionDown($count);
        }
    }

    /**
     * Migrates to the certain version.
     *
     * @param string $version name in the full format.
     *
     * @throws Exception if the provided version cannot be found.
     */
    protected function migrateToVersion($version)
    {
        $originalVersion = $version;

        // try migrate up
        $migrations = $this->getNewMigrations();
        $i = 0;
        foreach ($migrations as $migration => $alias) {
            if (strpos($migration, $version . '_') === 0) {
                $this->actionUp($i + 1);

                return;
            }
            $i++;
        }

        // try migrate down
        $migrations = array_keys($this->getMigrationHistory(-1));
        foreach ($migrations as $i => $migration) {
            if (strpos($migration, $version . '_') === 0) {
                if ($i === 0) {
                    echo "Already at '$originalVersion'. Nothing needs to be done.\n";
                } else {
                    $this->actionDown($i);
                }

                return;
            }
        }

        throw new Exception("Unable to find the version '$originalVersion'.");
    }

    /**
     * Returns the migration history.
     *
     * @param integer $limit the maximum number of records in the history to be returned
     *
     * @return array the migration history
     */
    protected function getMigrationHistory($limit)
    {
        if ($this->db->schema->getTableSchema($this->migrationTable, true) === null) {
            $this->createMigrationHistoryTable();
        }
        $query = new Query;
        $rows = $query->select(['version', 'alias', 'apply_time'])
            ->from($this->migrationTable)
            ->orderBy('version DESC')
            ->limit($limit)
            ->createCommand($this->db)
            ->queryAll();
        $history = ArrayHelper::map($rows, 'version', 'apply_time');
        foreach ($rows AS $row) {
            $history[$row['version']] = ['apply_time' => $row['apply_time'], 'alias' => $row['alias']];
        }

        unset($history[self::BASE_MIGRATION]);
        return $history;
    }

    /**
     * Creates the migration history table.
     */
    protected function createMigrationHistoryTable()
    {
        $tableName = $this->db->schema->getRawTableName($this->migrationTable);
        echo "Creating migration history table \"$tableName\"...";
        $this->db->createCommand()->createTable(
            $this->migrationTable,
            [
                'version' => 'varchar(180) NOT NULL PRIMARY KEY',
                'alias' => 'varchar(180) NOT NULL',
                'apply_time' => 'integer',
            ]
        )->execute();
        $this->db->createCommand()->insert(
            $this->migrationTable,
            [
                'version' => self::BASE_MIGRATION,
                'alias' => $this->migrationPath,
                'apply_time' => time(),
            ]
        )->execute();
        echo "done.\n";
    }

    /**
     * Returns the migrations that are not applied.
     * @return array list of new migrations, (key: migration version; value: alias)
     */
    protected function getNewMigrations()
    {
        $applied = [];
        foreach ($this->getMigrationHistory(-1) as $version => $info) {
            $applied[substr($version, 1, 13)] = true;
        }

        if (isset(\Yii::$app->params['yii.migrations'])) {
            $this->migrationLookup = ArrayHelper::merge($this->migrationLookup, \Yii::$app->params['yii.migrations']);
        }

        if ($this->migrationPath && $this->disableLookup) {
            $directories = [$this->migrationPath];
        } else {
            $directories = ArrayHelper::merge([$this->migrationPath], $this->migrationLookup);
        }

        $migrations = [];

        echo "\nLookup:\n";

        foreach ($directories AS $alias) {
            $dir = Yii::getAlias($alias);
            $handle = opendir($dir);
            while (($file = readdir($handle)) !== false) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                $path = $dir . DIRECTORY_SEPARATOR . $file;
                if (preg_match('/^(m(\d{6}_\d{6})_.*?)\.php$/', $file, $matches) && is_file(
                        $path
                    ) && !isset($applied[$matches[2]])
                ) {
                    $migrations[$matches[1]] = $alias;
                }
            }
            closedir($handle);
            echo "    " . $alias . " (" . \Yii::getAlias($alias) . ")\n";
        }
        ksort($migrations);

        echo "\n";

        return $migrations;
    }
}

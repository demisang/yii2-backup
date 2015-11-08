<?php
/**
 * @link https://orlov.io/
 * @copyright Copyright (c) 2016 Ivan Orlov
 * @author Ivan Orlov <gnasimed@gmail.com>
 */

namespace demi\backup;

use Yii;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\helpers\FileHelper;

/**
 * Backup component
 *
 * @package demi\backup
 */
class Component extends \yii\base\Component
{
    /** @var string Path/Alias to folder for backups storing. e.g. "@app/backups" */
    public $backupsFolder;
    /**
     * if string - value will be used in date() function.
     * if callable:
     * function(\demi\backup\Component $component) {
     *     return date('Y_m_d-H_i_s');
     * }
     *
     * @var string|callable
     */
    public $backupFilename = 'Y_m_d-H_i_s';
    /**
     * Number of seconds after which the file is considered deprecated and will be deleted.
     * By default 1 month (2592000 seconds).
     *
     * To prevent deleting any files you can set this param as NULL/FALSE.
     *
     * @var int
     */
    public $expireTime = 2592000;
    /**
     * List of [filename => path] directories for backups.
     * e.g.:
     * [
     *     'images' => '@frontend/web/images',
     *     'png.images' => [
     *         'path' => '@frontend/web/images',
     *         'regex' => '/\.png$/', // for backup only *.png files
     *     ],
     * ];
     * It will generate:
     * "/images.tar" - dump for "/frontend/web/images" directory
     * "/png.images.tar" - dump for "/frontend/web/images" directory only with *.png files
     *
     * @var array
     */
    public $directories = [];
    /**
     * Name of Database component. By default Yii::$app->db.
     * If you do not want backup project database you can set this param as NULL/FALSE
     *
     * @var string
     */
    public $db = 'db';
    /**
     * List of databases connections config.
     * e.g.:
     * [
     *    'logs_table' => [
     *        'db' => 'logs', // database name. If not set, then will be used key 'logs_table'
     *        'host' => 'localhost', // connection host
     *        'username' => 'root', // database username
     *        'password' => 'BXw2DKyRbz', // user password
     *    ],
     * ];
     * It will generate "/sql/logs_table.sql.gz" with dump file "logs_table.sql" of database "logs"
     *
     * You can set custom 'mysqldump' command for each database, just add 'command' param.
     *
     * If you set $db param, then $databases automatically will be extended with params from Yii::$app->$db
     *
     * @var array
     */
    public $databases = [];
    /**
     * CLI command for creating each database backup.
     *
     * If $databases password is empty, then will be executed: str_replace('-p\'{password}\'', '', $command);
     * it helpful when mysql password is not set
     *
     * You can override this command with you custom params, just add them to $databases config.
     *
     * @var string
     */
    public $mysqldump = 'mysqldump --add-drop-table --allow-keywords -q -c -u "{username}" -h "{host}" -p\'{password}\' {db} | gzip -9';

    /**
     * @inheritdoc
     * @throws InvalidConfigException
     */
    public function init()
    {
        // Check backup folder
        if (!is_dir($this->backupsFolder)) {
            throw new InvalidConfigException('Directory for backups "' . $this->backupsFolder . '" does not exists');
        } elseif (!is_writable($this->backupsFolder)) {
            throw new InvalidConfigException('Directory for backups "' . $this->backupsFolder . '" is not writable');
        }

        // Add site database to primary databases list
        if (!empty($this->db) && Yii::$app->has($this->db)) {
            /** @var \yii\db\Connection $dbComponent */
            $dbComponent = Yii::$app->get($this->db);

            // Get default database name
            $dbName = $dbComponent->createCommand('select database()')->queryScalar();
            $this->databases[$dbName] = [
                'db' => $dbName,
                'host' => 'localhost',
                'username' => $dbComponent->username,
                'password' => addcslashes($dbComponent->password, '\''),
            ];
        }

        // Set db name if not exists in databases config array
        foreach ($this->databases as $name => $params) {
            if (!isset($params['db'])) {
                $this->databases[$name]['db'] = $name;
            }
        }
    }

    /**
     * Create dump of all directories and all databases and save result to bakup folder with timestamp named tar-archive
     *
     * @return string Full path to created backup file
     * @throws Exception
     */
    public function create()
    {
        $folder = $this->getBackupFolder();

        $files = $this->backupFiles($folder);
        $db = $this->backupDatabase($folder);

        $resultFilename = $this->getBackupFilename();
        $archiveFile = dirname($folder) . DIRECTORY_SEPARATOR . $resultFilename . '.tar';

        // Create new archive
        $archive = new \PharData($archiveFile);

        // add folder
        $archive->buildFromDirectory($folder);

        // Remove temp directory
        FileHelper::removeDirectory($folder);

        return $archiveFile;
    }

    /**
     * Create backups for $directories and save it to "<backups folder>"
     *
     * @param string $saveTo
     *
     * @return bool
     */
    public function backupFiles($saveTo)
    {
        foreach ($this->directories as $name => $value) {
            if (is_array($value)) {
                // if exists config, use it
                $folder = Yii::getAlias($value['path']);
                $regex = isset($value['regex']) ? $value['regex'] : null;
            } else {
                $regex = null;
                $folder = Yii::getAlias($value);
            }

            $archiveFile = $saveTo . DIRECTORY_SEPARATOR . $name . '.tar';

            // Create new archive
            $archive = new \PharData($archiveFile);

            // add folder
            $archive->buildFromDirectory($folder, $regex);
        }

        return true;
    }

    /**
     * Create backups for $databases and save it to "<backups folder>/sql"
     *
     * @param string $saveTo
     *
     * @return bool
     */
    public function backupDatabase($saveTo)
    {
        $saveTo .= DIRECTORY_SEPARATOR . 'sql';
        mkdir($saveTo);

        foreach ($this->databases as $name => $params) {
            // Get mysqldump command
            $command = isset($params['command']) && !empty($params['command']) ? $params['command'] : $this->mysqldump;

            if ((string)$params['password'] === '') {
                // Remove password option
                $command = str_replace('-p\'{password}\'', '', $command);
                unset($params['password']);
            }

            foreach ($params as $k => $v) {
                $command = str_replace('{' . $k . '}', $v, $command);
            }

            $file = $saveTo . DIRECTORY_SEPARATOR . $name . '.sql.gz';

            system($command . ' > ' . $file);
        }

        return true;
    }

    /**
     * Delete expired files
     *
     * @return bool
     */
    public function deleteJunk()
    {
        if (empty($this->expireTime)) {
            // Prevent deleting if expireTime is disabled
            return true;
        }

        $backupsFolder = Yii::getAlias($this->backupsFolder);
        // Calculate expire date
        $expireDate = time() - $this->expireTime;

        $filter = function ($path) use ($expireDate) {
            // Check extension
            if (substr($path, -4) !== '.tar') {
                return false;
            }

            if (is_file($path) && filemtime($path) <= $expireDate) {
                // if the time has come - delete file
                return true;
            }

            return false;
        };

        // Find expired backups files
        $files = FileHelper::findFiles($backupsFolder, ['recursive' => false, 'filter' => $filter]);

        foreach ($files as $file) {
            if (@unlink($file)) {
                Yii::info('Backup file was deleted: ' . $file, 'demi\backup\Component::deleteJunk()');
            } else {
                Yii::error('Cannot delete backup file: ' . $file, 'demi\backup\Component::deleteJunk()');
            }
        }

        return true;
    }

    /**
     * Generate backup filename
     *
     * @return string
     */
    public function getBackupFilename()
    {
        if (is_callable($this->backupFilename)) {
            return call_user_func($this->backupFilename, $this);
        } else {
            return date($this->backupFilename);
        }
    }

    /**
     * Get full path to backups folder.
     * Directory will be automatically created.
     *
     * @return string
     * @throws Exception
     */
    public function getBackupFolder()
    {
        // Base backups folder
        $base = Yii::getAlias($this->backupsFolder);

        // Temp directory for current backup
        $current = $this->getBackupFilename();

        $fullpath = $base . DIRECTORY_SEPARATOR . $current;

        // Try to create new directory
        if (!is_dir($fullpath) && !mkdir($fullpath)) {
            throw new Exception('Can not create folder for backup: "' . $fullpath . '"');
        }

        return $fullpath;
    }
}
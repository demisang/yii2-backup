<?php

namespace demi\backup;

use Yii;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\helpers\FileHelper;

class Component extends \yii\base\Component
{
    public $backupsFolder;
    public $directories = [];
    public $db = 'db';
    public $databases = [];
    public $mysqldump = 'mysqldump --add-drop-table --allow-keywords -q -c -u "{username}" -h "{host}" -p\'{password}\' {db} | gzip -9';

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
            $dbName = $dbComponent->pdo->query('select database()')->fetchColumn();
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

    public function create()
    {
        $folder = $this->getBackupFolder();

        $files = $this->backupFiles($folder);
        $db = $this->backupDatabase($folder);

        $archiveFile = dirname($folder) . DIRECTORY_SEPARATOR . basename($folder) . '.tar';

        // Create new archive
        $archive = new \PharData($archiveFile);

        // add folder
        $archive->buildFromDirectory($folder);

        FileHelper::removeDirectory($folder);

        return $archiveFile;
    }

    public function backupFiles($saveTo)
    {
        foreach ($this->directories as $name => $path) {
            $folder = Yii::getAlias($path);

            $archiveFile = $saveTo . DIRECTORY_SEPARATOR . $name . '.tar';

            // Create new archive
            $archive = new \PharData($archiveFile);

            // add folder
            $archive->buildFromDirectory($folder);
        }

        return true;
    }

    public function backupDatabase($saveTo)
    {
        $saveTo .= DIRECTORY_SEPARATOR . 'sql';
        mkdir($saveTo);

        foreach ($this->databases as $name => $params) {
            $command = $this->mysqldump;

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

    protected function getBackupFolder()
    {
        $base = Yii::getAlias($this->backupsFolder);

        $current = date('Y_m_d_H_i_s');

        $fullpath = $base . DIRECTORY_SEPARATOR . $current;

        if (!is_dir($fullpath)) {
            if (!mkdir($fullpath)) {
                throw new Exception('Can not create folder for backup: "' . $fullpath . '"');
            }
        }

        return $fullpath;
    }
}
Yii2-backup
===================
Basic Yii2 site backup methods.<br />
Also pay attention to [dropbox-backup](https://github.com/demisang/yii2-dropbox-backup).

Installation
---

Add to composer.json in your project
```json
{
	"require": {
  		"demi/backup": "~1.0"
	}
}
```

# Configurations

### Minimal config

Add to common/config/main.php:
```php
'components' => [
    'backup' => [
        'class' => 'demi\backup\Component',
        // The directory for storing backups files
        'backupsFolder' => dirname(dirname(__DIR__)) . '/backups', // <project-root>/backups
        // Directories that will be added to backup
        'directories' => [
            'images' => '@frontend/web/images',
            'uploads' => '@backend/uploads',
        ],
    ],
]
```

#### Will create backup for:
**directories:**<br />
_/frontend/web/images/\*_<br />
_/backend/uploads/\*_<br />
**database:**<br />
_Yii::$app->db_

#### Result:
**/backups/2015_08_11-05_45\_48.tar/**<br />
\>images.tar<br />
\>uploads.tar<br />
\>sql/blog.sql.gz


### Maximal config

```php
[
'class' => 'demi\backup\Component',

// The directory for storing backups files
'backupsFolder' => dirname(dirname(__DIR__)) . '/backups', // <project-root>/backups
// You can use alias:
'backupsFolder' => '@backend/backups', // <project-root>/backend/backups

// Name template for backup files.
// if string - return date('Y_m_d-H_i_s')
'backupFilename' => 'Y_m_d-H_i_s',
// also can be callable:
'backupFilename' => function (\demi\backup\Component $component) {
    return date('Y_m_d-H_i_s');
},

// Directories that will be added to backup
'directories' => [
    // format: <inner backup filename> => <path/to/dir>
    'images' => '@frontend/web/images',
    'uploads' => '@backend/uploads',
],

// Name of Database component. By default Yii::$app->db.
// If you don't want backup project database
// you can set this param as NULL/FALSE.
'db' => 'db',
// List of databases connections config.
// If you set $db param, then $databases automatically
// will be extended with params from Yii::$app->$db.
'databases' => [
    // It will generate "/sql/logs_table.sql.gz" with 
    // dump file "logs_table.sql" of database 'logs'.
    // You can set custom 'mysqldump' command for each database,
    // just add 'command' param.
    'logs_table' => [
        'db' => 'logs', // database name. If not set, then will be used key 'logs_table'
        'host' => 'localhost', // connection host
        'username' => 'root', // database username
        'password' => 'BXw2DKyRbz', // user password
        'command' => 'mysqldump --add-drop-table --allow-keywords -q -c -u "{username}" -h "{host}" -p\'{password}\' {db} | gzip -9', // custom `mysqldump` command
    ],
],
// CLI command for creating each database backup.
// If $databases password is empty,
// then will be executed: str_replace('-p\'{password}\'', '', $command);
// it helpful when mysql password is not set.
// You can override this command with you custom params,
// just add them to $databases config.
'mysqldump' => 'mysqldump --add-drop-table --allow-keywords -q -c -u "{username}" -h "{host}" -p\'{password}\' {db} | gzip -9',

// Number of seconds after which the file is considered deprecated and will be deleted.
// To prevent deleting any files you can set this param as NULL/FALSE/0.
'expireTime' => 2592000, // 1 month
],
```

# What's next

You can use this component anywhere.<br />
For example, you can create console command<br />
**/console/controllers/ToolsController.php:**
```php
<?php
namespace console\controllers;

class ToolsController extends \yii\console\Controller
{
    public function actionBackup()
    {
        /** @var \demi\backup\Component $backup */
        $backup = \Yii::$app->backup;
        
        $file = $backup->create();

        $this->stdout('Backup file created: ' . $file . PHP_EOL, \yii\helpers\Console::FG_GREEN);
    }
} 
```
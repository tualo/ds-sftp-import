<?php

namespace Tualo\Office\DSSFTPImport\Scopes;

use Tualo\Office\Basic\Scope;

class DSSFTPImportScope extends Scope
{
    protected static string $identifier = 'ds-sftp-import';
    protected static array $name = [
        'EN' => 'SFTP Import',
        'DE' => 'SFTP Import',
    ];
    protected static array $description = [
        'EN' => 'Access and manage SFTP import files.',
        'DE' => 'SFTP-Import-Dateien zugreifen und verwalten.',
    ];
}

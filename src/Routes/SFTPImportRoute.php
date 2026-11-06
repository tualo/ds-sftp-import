<?php

namespace Tualo\Office\DSSFTPImport\Routes;

use phpseclib4\Net\SFTP;
use Tualo\Office\Basic\Route as BasicRoute;
use Tualo\Office\Basic\TualoApplication as App;
use Tualo\Office\DS\DSExporterHelper;
use Tualo\Office\DSSFTPImport\Scopes\DSSFTPImportScope;

class SFTPImportRoute extends \Tualo\Office\Basic\RouteWrapper
{
    public static function scope(): string
    {
        return DSSFTPImportScope::identifier();
    }

    public static function register()
    {
        BasicRoute::add('/dssftp/import/(?P<tablename>[\w\-\_]+)', function ($matches) {
            $db = App::get('session')->getDB();
            $tablename = $matches['tablename'];
            $currentFilename = '';
            $currentMd5 = '';
            $debugFiles = [];
            App::contenttype('application/json');
            App::result('success', false);
            App::result('msg', '');

            try {
                $db->commit(false);
                set_time_limit(600);

                $server = $db->singleRow(
                    'select * from ds_import_file_transfer where table_name={tablename}',
                    $matches
                );
                if ($server === false) {
                    throw new \Exception('No SFTP configured');
                }

                $serverName = $server['server'];
                $serverPort = 22;
                if (substr_count($serverName, ':') === 1) {
                    [$serverName, $serverPort] = explode(':', $serverName, 2);
                    $serverPort = (int) $serverPort;
                }

                $sftp = new SFTP($serverName, $serverPort);
                if (!$sftp->login($server['username'], $server['password'])) {
                    throw new \Exception('Login Failed');
                }

                $temporaryFolder = rtrim((string) App::get('tempPath'), '/') . '/';
                if (!is_dir($temporaryFolder) && !mkdir($temporaryFolder, 0755, true) && !is_dir($temporaryFolder)) {
                    throw new \Exception('Unable to create temporary folder');
                }

                self::ensureImportLogTable($db, $tablename);
                $columns = DSExporterHelper::getExportColumns($db, $tablename);
                $importedFiles = $db->direct(
                    'select filename from `' . $tablename . '_imported` where imported=1',
                    [],
                    'filename'
                );

                $fileMatch = (string) ($server['filematch'] ?? '*');
                $fileMatchParts = array_map(
                    static fn($part) => preg_quote($part, '/'),
                    explode('*', $fileMatch)
                );
                $filePattern = '/^' . implode('.*', $fileMatchParts) . '$/i';

                $sftp->enableDatePreservation();
                $sftp->setListOrder('mtime', SORT_ASC);
                $files = $sftp->nlist($server['path']);

                foreach ($files as $filename) {
                    if (in_array($filename, ['.', '..'], true)) {
                        continue;
                    }
                    $debugFiles[$filename] = false;
                    if (!preg_match($filePattern, $filename) || isset($importedFiles[$filename])) {
                        continue;
                    }

                    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                    if (!in_array($extension, ['xlsx', 'csv', 'txt'], true)) {
                        continue;
                    }

                    $localFilename = $db->singleValue('select uuid() u', [], 'u') . '.' . $extension;
                    $localPath = $temporaryFolder . $localFilename;
                    $currentFilename = $filename;
                    try {
                        $sftp->get(rtrim($server['path'], '/') . '/' . $filename, $localPath);

                        if (in_array($extension, ['csv', 'txt'], true) && (int) ($server['encode_utf8'] ?? 0) === 1) {
                            $contents = file_get_contents($localPath);
                            if ($contents === false) {
                                throw new \Exception('Unable to read downloaded file');
                            }
                            $encodedContents = iconv('ISO-8859-1', 'UTF-8', $contents);
                            if ($encodedContents === false || file_put_contents($localPath, $encodedContents) === false) {
                                throw new \Exception('Unable to convert downloaded file to UTF-8');
                            }
                        }

                        $contents = file_get_contents($localPath);
                        if ($contents === false) {
                            throw new \Exception('Unable to read downloaded file');
                        }
                        if (strncmp($contents, "\xef\xbb\xbf", 3) === 0) {
                            if (file_put_contents($localPath, substr($contents, 3)) === false) {
                                throw new \Exception('Unable to remove UTF-8 BOM from downloaded file');
                            }
                        }

                        $currentMd5 = md5_file($localPath);
                        if ($currentMd5 === false) {
                            throw new \Exception('Unable to calculate file checksum');
                        }

                        $db->direct(
                            'insert into `' . $tablename . '_imported` (md5id,filename,importdatetime) '
                                . 'values ({md5id},{filename},now()) '
                                . 'on duplicate key update importdatetime=values(importdatetime)',
                            ['filename' => $currentFilename, 'md5id' => $currentMd5]
                        );

                        if (in_array($extension, ['csv', 'txt'], true)) {
                            self::importCsv($localPath, $filename, $tablename, $server, $columns, $db);
                        } else {
                            self::importSpreadsheet($localPath, $filename, $tablename, $server, $columns, $db);
                        }

                        $db->direct(
                            'update `' . $tablename . '_imported` set imported=1 where md5id={md5id}',
                            ['md5id' => $currentMd5]
                        );

                        if ((int) ($server['deleteafterimport'] ?? 0) === 1) {
                            $sftp->delete(rtrim($server['path'], '/') . '/' . $filename);
                        }
                        $debugFiles[$filename] = true;
                    } finally {
                        if (is_file($localPath) && !unlink($localPath)) {
                            error_log('Unable to remove temporary SFTP import file: ' . $localPath);
                        }
                    }
                }

                App::result('success', true);
                $db->commit(true);
            } catch (\Throwable $e) {
                $db->rollback();
                App::result('last_sql', $db->last_sql . '*');
                App::result('current_filename', $currentFilename);
                App::result('msg', $e->getMessage());

                if ($currentMd5 !== '') {
                    try {
                        $db->direct(
                            'update `' . $tablename . '_imported` set message={message} where md5id={md5id}',
                            [
                                'message' => $e->getMessage() . ' (' . $currentFilename . ')',
                                'md5id' => $currentMd5,
                            ]
                        );
                    } catch (\Throwable $updateError) {
                        App::result('message_update_error', $updateError->getMessage());
                    }
                }
            }

            App::result('debug_files', $debugFiles);
        }, ['get', 'post'], true, [], self::scope());
    }

    private static function ensureImportLogTable($db, string $tablename): void
    {
        $db->execute(
            'create table if not exists `' . $tablename . '_imported` ('
                . 'md5id varchar(36) primary key,'
                . 'filename varchar(255),'
                . 'importdatetime datetime,'
                . 'imported tinyint default 0,'
                . 'message longtext)'
        );
    }

    private static function importCsv(string $localPath, string $filename, string $tablename, array $server, array $columns, $db): void
    {
        $enclosure = (string) ($server['enclose'] ?? '"');
        if ($enclosure === '') {
            $enclosure = '"';
        }
        if (strlen($enclosure) !== 1) {
            throw new \InvalidArgumentException('CSV option "enclose" must contain exactly one single-byte character.');
        }
        $delimiter = self::normalizeCsvOption((string) ($server['delimiter'] ?? ';'));
        if (strlen($delimiter) !== 1) {
            throw new \InvalidArgumentException('CSV option "delimiter" must contain exactly one single-byte character.');
        }
        $lineDelimiter = self::normalizeCsvOption((string) (($server['line_delimiter'] ?? '') ?: "\n"));

        if ((int) ($server['use_load_file'] ?? 0) === 1) {
            self::importCsvWithLoadData(
                $localPath,
                $filename,
                $tablename,
                $server,
                $columns,
                $db,
                $enclosure,
                $delimiter,
                $lineDelimiter
            );
            return;
        }

        if ((int) ($server['use_load_csv'] ?? 0) === 1) {
            self::importCsvWithLeagueCsv(
                $localPath,
                $filename,
                $tablename,
                $db,
                $enclosure,
                $delimiter
            );
            return;
        }

        $handle = fopen($localPath, 'r');
        if ($handle === false) {
            throw new \Exception('Unable to read CSV file');
        }

        try {
            $header = fgetcsv($handle, 4000, $delimiter, $enclosure, '\\');
            if ($header === false) {
                throw new \Exception('CSV file has no header row');
            }
            $columnMap = self::mapColumns($columns, $header);
            while (($row = fgetcsv($handle, 4000, $delimiter, $enclosure, '\\')) !== false) {
                self::insertMappedRow($db, $tablename, $filename, $row, $columnMap);
            }
        } finally {
            fclose($handle);
        }
    }

    private static function importCsvWithLoadData(
        string $localPath,
        string $filename,
        string $tablename,
        array $server,
        array $columns,
        $db,
        string $enclosure,
        string $delimiter,
        string $lineDelimiter
    ): void {
        if (!filter_var(ini_get('mysqli.allow_local_infile'), FILTER_VALIDATE_BOOLEAN)) {
            throw new \RuntimeException(
                'LOAD DATA LOCAL INFILE requires mysqli.allow_local_infile=On in the PHP configuration.'
            );
        }

        $importColumns = $db->direct('select * from ds_column where table_name={t}', ['t' => $tablename], '');
        $db->execute('call addFieldIfNotExists("' . $tablename . '","import_line","integer")');
        $db->execute('set @import_line=0');

        $handle = fopen($localPath, 'r');
        if ($handle === false) {
            throw new \Exception('Unable to read CSV file');
        }
        try {
            $header = fgetcsv($handle, 4000, $delimiter, $enclosure, '\\');
        } finally {
            fclose($handle);
        }
        if ($header === false) {
            throw new \Exception('CSV file has no header row');
        }

        $columnMap = self::mapColumns($columns, $header, true);
        $fields = [];
        foreach ($columnMap as $index => $columnName) {
            $fields[$index] = '`' . str_replace('`', '``', $columnName) . '`';
        }
        ksort($fields);
        if ($fields === []) {
            throw new \Exception('No matching columns found in CSV file');
        }

        $quotedEnclosure = str_replace("'", "''", $enclosure);
        $quotedDelimiter = str_replace('"', '""', $delimiter);
        $quotedLineDelimiter = str_replace('"', '""', $lineDelimiter);
        $quotedFilename = str_replace('"', '""', $filename);
        $loadSql = 'LOAD DATA LOCAL INFILE "' . str_replace('"', '""', $localPath) . '" IGNORE '
            . 'INTO TABLE `' . $tablename . '` CHARACTER SET utf8 '
            . "FIELDS OPTIONALLY ENCLOSED BY '" . $quotedEnclosure . "' TERMINATED BY \"{$quotedDelimiter}\" "
            . "LINES TERMINATED BY \"{$quotedLineDelimiter}\" IGNORE 1 LINES ("
            . implode(',', $fields) . ') SET filename="' . $quotedFilename . '", '
            . 'import_line=@import_line:=@import_line+1';
        if ((int) ($server['addmd5id'] ?? 0) === 1) {
            $loadSql .= ', md5id=md5(concat(' . implode(',', $fields) . '))';
        }
        foreach ($importColumns as $importColumn) {
            if (($importColumn['column_name'] ?? '') === 'pkey') {
                $loadSql .= ', pkey=uuid()';
                break;
            }
        }
        $db->direct($loadSql);
    }

    private static function importCsvWithLeagueCsv(
        string $localPath,
        string $filename,
        string $tablename,
        $db,
        string $enclosure,
        string $delimiter
    ): void {
        $csv = \League\Csv\Reader::createFromPath($localPath, 'r');
        $csv->setHeaderOffset(0);
        $csv->setDelimiter($delimiter);
        $csv->setEnclosure($enclosure);
        $csv->skipInputBOM();

        $importData = [];
        foreach ($csv->getRecords() as $record) {
            $importRecord = [
                '__table_name' => $tablename,
                $tablename . '__filename' => $filename,
            ];
            $data = '';
            foreach ($record as $key => $value) {
                $importRecord[$tablename . '__' . $key] = $value;
                $data .= $value;
            }
            $importRecord[$tablename . '__md5id'] = md5($data);
            $importData[] = $importRecord;
        }

        $db->execute('SET @data={data}', ['data' => json_encode($importData)]);
        $db->execute('call ds_rest_api_set(@data,@result)');
        App::result('dataresult', json_decode($db->singleValue('select @result r', [], 'r'), true));
    }

    private static function importSpreadsheet(string $localPath, string $filename, string $tablename, array $server, array $columns, $db): void
    {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($localPath);
        $sheets = $spreadsheet->getSheetNames();
        if ($sheets === []) {
            throw new \Exception('No sheets found in spreadsheet');
        }

        $sheetIndex = max(0, (int) ($server['force_sheet'] ?? 0));
        if ($sheetIndex >= count($sheets)) {
            throw new \Exception('Configured spreadsheet sheet does not exist');
        }
        $sheet = $spreadsheet->getSheet($sheetIndex);
        $rowCount = $sheet->getHighestDataRow();
        $columnCount = $sheet->getHighestDataColumn();
        $header = $sheet->rangeToArray('A1:' . $columnCount . '1', null, false, true, true);
        $headerValues = array_values($header[1] ?? []);
        $columnMap = self::mapColumns($columns, $headerValues);

        if ($rowCount < 2) {
            return;
        }
        $data = $sheet->rangeToArray('A2:' . $columnCount . $rowCount, null, false, true, false);
        foreach ($data as $row) {
            self::insertMappedRow($db, $tablename, $filename, $row, $columnMap);
        }
    }

    private static function mapColumns(array $columns, array $header, bool $strict = false): array
    {
        $columnMap = [];
        foreach ($header as $index => $headerName) {
            foreach ($columns as $column) {
                if (($column['label'] ?? null) === $headerName || ($column['column_name'] ?? null) === $headerName) {
                    $columnMap[$index] = $column['column_name'];
                    break;
                }
            }
            if ($strict && !isset($columnMap[$index])) {
                throw new \Exception('Column ' . $headerName . ' is missing in the table');
            }
        }
        return $columnMap;
    }

    private static function insertMappedRow($db, string $tablename, string $filename, array $row, array $columnMap): void
    {
        $dataset = [$tablename . '__filename' => $filename];
        $rowMd5 = '';
        foreach ($columnMap as $index => $columnName) {
            $value = $row[$index] ?? null;
            if ($value !== null) {
                $dataset[$tablename . '__' . $columnName] = $value;
                $rowMd5 .= $value;
            }
        }
        $dataset[$tablename . '__row_md5'] = md5($rowMd5);
        $dataset['__table_name'] = $tablename;

        $db->execute('set @ds_insert_update_on_duplicate_key=true');
        $sql = $db->singleValue(
            'select ds_insert({record}) x',
            ['record' => json_encode(['data' => $dataset])],
            'x'
        );
        $db->execute($sql);
    }

    private static function normalizeCsvOption(string $value): string
    {
        return str_replace(
            ['\\t', '\\r', '\\n'],
            ["\t", "\r", "\n"],
            $value
        );
    }
}

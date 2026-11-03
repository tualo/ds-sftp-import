<?php


require_once 'loadfile_helper.php';


ORoute::add('/dssftp/import/(?P<tablename>[\w\-\_]+)',function($matches){
    $db = TualoApplication::get('session')->getDB();
    $tablename = $matches['tablename'];
    $current_filename = '';
    $current_md5 = '';
    $dfiles = [];
    TualoApplication::contenttype('application/json');
    try{
        
        $db->commit(false);
        set_time_limit(600);
        // alter table ds_import_file_transfer add  use_load_file tinyint default 0
        $server = $db->singleRow('select * from ds_import_file_transfer where table_name={tablename} ',$matches);
        if ($server === false) throw new Exception('No SFTP configured');

        $sftp_server = $server['server'];
        $sftp_port = 22;
        if (substr_count($server['server'],':')==1){
            list($sftp_server,$sftp_port) = explode(':',$server['server']);
        }
        $sftp_username = $server['username'];
        $sftp_password = $server['password'];
        $sftp_path = $server['path'];

        // alter table ds_import_file_transfer add filematch varchar(50) default '*';
        /*
 alter table ds_import_file_transfer add enclose varchar(50) default '"';
 alter table ds_import_file_transfer add delimiter varchar(50) default ';';
 alter table ds_import_file_transfer add line_delimiter varchar(50) default '';
*/
        $sftp_filematch = $server['filematch'];
        
        /*
        if (class_exists("phpseclib3\Net\SFTP")){ $cls = phpseclib3\Net\SFTP;}
        else if (class_exists("phpseclib\Net\SFTP")){ $cls = phpseclib\Net\SFTP; }
        else { throw new Exception("phpseclib missed "); }
        */
        
        $sftp = new phpseclib3\Net\SFTP($sftp_server,$sftp_port);
        if (!$sftp->login($sftp_username, $sftp_password)) {
            throw new Exception('Login Failed');
        }

        $temporary_folder = TualoApplication::get("tempPath") . '/';

        if (!file_exists( $temporary_folder )){
            mkdir($temporary_folder , 0755);
        }

        if (isset($request['usename'])&&(strlen($request['usename'])>0)){
            $fname = $request['usename'];
        }else{
            $fname = '';
        }
        error_reporting(E_ERROR);
        $columns = DSExporterHelper::getExportColumns($db,$tablename);
        

        $imported_files = $db->direct('select filename from `'.$tablename.'_imported` where imported=1',[],'filename');

        $sftp->enableDatePreservation();
        $sftp->setListOrder('mtime', SORT_ASC);


        $files = $sftp->nlist($sftp_path.'');
        foreach($files as $fname){
            if (!in_array($fname,['.','..'])){
                $dfiles[$fname] = false;

                $fmatches = explode('*',$sftp_filematch);
                foreach($fmatches as &$fm){
                    $fm = preg_quote($fm);
                }

                $reg_exp = "/^".implode(".*",$fmatches).'$/i';
                if (preg_match($reg_exp, $fname)) {

                    $extension = pathinfo($sftp_path.'/'.$fname ,PATHINFO_EXTENSION);
                    $localfname = $db->singleValue('select uuid() u',[],'u').'.'.$extension;
                    if ( (!isset($imported_files[$fname])) && ( ($extension=='xlsx')||( ($extension=='csv') || ($extension=='txt')) ) ){

                        $r = $sftp->get( $sftp_path.'/'.$fname , TualoApplication::get('tempPath') . '/' . $localfname  );

                        if ( ( ($extension=='csv') || ($extension=='txt')) && ($server['encode_utf8']==1))
                        file_put_contents( TualoApplication::get('tempPath') . '/' . $localfname , utf8_encode(file_get_contents( TualoApplication::get('tempPath') . '/' . $localfname)) );
                        
                        $string = file_get_contents( TualoApplication::get('tempPath') . '/' . $localfname);
                        if (substr($string,0,3) == "\xef\xbb\xbf"){
                            file_get_contents( TualoApplication::get('tempPath') . '/' . $localfname,substr($string, 3));
                        }

                        

                        $md5 = md5_file(TualoApplication::get('tempPath') . '/' . $localfname);

                        $current_filename = $fname;
                        $current_md5 = $md5;

                        if ($db->singleValue('select filename from `'.$tablename.'_imported` where filename = {filename} and imported=1',['filename'=>$fname],'filename')==false){
                            $db->direct('insert into `'.$tablename.'_imported` (md5id,filename,importdatetime) values  ({md5id},{filename},now()) on duplicate key update importdatetime=values(importdatetime)',['filename'=>$current_filename,'md5id'=>$current_md5]);


                            if ( ($extension=='csv') || ($extension=='txt')) {

                                if ((isset($server['use_load_file']))&&($server['use_load_file']==1)){

                                    SFTP_LOAD_FILE_HELPER::loadData(
                                        TualoApplication::get('tempPath') . '/' . $localfname,
                                        $fname,
                                        $tablename,
                                        $server,
                                        $columns,
                                        $db,
                                        $enclose=(isset( $server['enclose'] )?$server['enclose']:'"'),
                                        $delimiter=(isset( $server['delimiter'] )?$server['delimiter']:';'),
                                        $line_delimiter=(isset( $server['line_delimiter'] )&&( $server['line_delimiter'] !="" )?$server['line_delimiter']:"\n")
                                    );

                                }elseif ((isset($server['use_load_csv']))&&($server['use_load_csv']==1)){

                                    LOAD_FILE_CSV::loadData(
                                        TualoApplication::get('tempPath') . '/' . $localfname,
                                        $fname,
                                        $tablename,
                                        $server,
                                        $columns,
                                        $db,
                                        $enclose=(isset( $server['enclose'] )?$server['enclose']:'"'),
                                        $delimiter=(isset( $server['delimiter'] )?$server['delimiter']:';'),
                                        $line_delimiter=(isset( $server['line_delimiter'] )&&( $server['line_delimiter'] !="" )?$server['line_delimiter']:"\n")
                                    );

                                }else{
                                    $row=0;
                                    if (($handle = fopen(TualoApplication::get('tempPath') . '/' . $localfname, "r")) !== FALSE) {
                                        while (($data = fgetcsv($handle, 4000, ";")) !== FALSE) {
                                            //echo "<p> $num Felder in Zeile $row: <br /></p>\n";
                                            if ($row==0){

                                                $lcolumns=json_decode(json_encode($columns),true);
                                                $index=0;
                                                foreach($lcolumns as &$column){
                                                    foreach($data as $c=>$v){
                                                        if (($column['label']==$v)||$column['column_name']==$v){
                                                            $column['excel']=$c;
                                                            $column['excelindex'] = $c;
                                                        }
                                                    }
                                                }


                                            }else{
                                                $updateOnDuplicate = true;
                                                $dataset=[];
                                                $dataset[$tablename.'__filename']=$fname;
        
                                                $rowmd5='';
                                                if (isset($col['excelindex'])){
                                                    if (isset($data[$col['excelindex']])&&!is_null($data[$col['excelindex']])){
                                                        $rowmd5.=$data[$col['excelindex']];
                                                    }
                                                }
                                                $dataset[$tablename.'__row_md5']=md5($rowmd5);
                                                
                                                foreach($lcolumns as $col){
                                                    if (isset($col['excelindex'])){
                                                        if (isset($data[$col['excelindex']])&&!is_null($data[$col['excelindex']])){
                                                            $dataset[$tablename.'__'.$col['column_name']] = $data[$col['excelindex']];
                                                        }
                                                    }
                                                }
                                                $dataset['__table_name'] = $tablename;
                                                $db->execute('set @ds_insert_update_on_duplicate_key=true;');
                                                $sql = $db->singleValue('select ds_insert({record}) x',['record'=>json_encode(['data'=>$dataset])],'x');
                                                $db->execute($sql);
                                            }


                                            $row++;
                                            
                                        }
                                        fclose($handle);
                                    }
                                }
                            }else{

                                $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load(TualoApplication::get('tempPath') . '/' . $localfname);
                                $sheets = $spreadsheet->getSheetNames();
                                if (count($sheets)==0) throw new \Exception("No Sheets readed");
                                $index=0;
                                $forceSheet = -1;
                                if (isset($server['force_sheet'])&&$server['force_sheet']>=0){
                                    $forceSheet = $server['force_sheet'];
                                    $index = $forceSheet;
                                }
                                // foreach($sheets as $sheetName){
                                    
                                    $sheet = $spreadsheet->getSheet($index);
                                    $count=$sheet->getHighestDataRow();
                                    $columnCount=$sheet->getHighestColumn();
                                    $header=$sheet->rangeToArray(
                                        'A1:'.$columnCount.'1',     // The worksheet range that we want to retrieve
                                        NULL,        // Value that should be returned for empty cells
                                        FALSE,        // Should formulas be calculated (the equivalent of getCalculatedValue() for each cell)
                                        TRUE,        // Should values be formatted (the equivalent of getFormattedValue() for each cell)
                                        TRUE         // Should the array be indexed by cell row and cell column
                                    );
                                    
                                    $lcolumns=json_decode(json_encode($columns),true);
                                    $index=0;
                                    foreach($lcolumns as &$column){
                                        foreach($header[1] as $c=>$v){
                                            if (($column['label']==$v)||$column['column_name']==$v){
                                                $column['excel']=$c;
                                                $column['excelindex'] = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($c)-1;
                                            }
                                        }
                                    }


                                    $data = $sheet->rangeToArray(
                                        'A2'.':'.$columnCount.($count),     // The worksheet range that we want to retrieve
                                        NULL,        // Value that should be returned for empty cells
                                        FALSE,        // Should formulas be calculated (the equivalent of getCalculatedValue() for each cell)
                                        TRUE,        // Should values be formatted (the equivalent of getFormattedValue() for each cell)
                                        FALSE         // Should the array be indexed by cell row and cell column
                                    );
                                    foreach($data as $row){
                                        $updateOnDuplicate = true;
                                        $dataset=[];
                                        $dataset[$tablename.'__filename']=$fname;

                                        $rowmd5='';
                                        if (isset($col['excelindex'])){
                                            if (!is_null($row[$col['excelindex']])){
                                                $rowmd5.=$row[$col['excelindex']];
                                            }
                                        }
                                        $dataset[$tablename.'__row_md5']=md5($rowmd5);
                                        
                                        foreach($lcolumns as $col){
                                            if (isset($col['excelindex'])){
                                                if (!is_null($row[$col['excelindex']])){
                                                    $dataset[$tablename.'__'.$col['column_name']] = $row[$col['excelindex']];
                                                }
                                            }
                                        }
                                        $dataset['__table_name'] = $tablename;
                                        $db->execute('set @ds_insert_update_on_duplicate_key=true;');
                                        $sql = $db->singleValue('select ds_insert({record}) x',['record'=>json_encode(['data'=>$dataset])],'x');
                                        $db->execute($sql);
                                    }
                                // }
                            }
                            
                            $db->direct('update `'.$tablename.'_imported` set imported = 1 where md5id={md5id} ',['md5id'=>$current_md5]);

                            if(isset($server['deleteafterimport'])&&($server['deleteafterimport']==1)){
                                $sftp->delete( $sftp_path.'/'.$fname );
                            }

                            $dfiles[$fname] = true;
                            if(file_exists(TualoApplication::get('tempPath') . '/' . $localfname)){
                                unlink(TualoApplication::get('tempPath') . '/' . $localfname);
                            }
                        }
                    }

                }
            }
        }
        
        TualoApplication::result('success',true);
        $db->commit(true);
    }catch(\Exception $e){
        TualoApplication::result('last_sql', $db->last_sql.'*' );
        $db->rollback();
        try{
            $db->direct('update `'.$tablename.'_imported` set message = {message} where md5id={md5id} ',['message'=>$e->getMessage().' ('.$current_filename.')','md5id'=>$current_md5]);
        }catch(\Exception $e){
            echo  $db->last_sql;
        }
        TualoApplication::result('current_filename',$current_filename);
        TualoApplication::result('msg', $e->getMessage());
    }
    TualoApplication::result('debug_files',$dfiles);
},array('get','post'),true);

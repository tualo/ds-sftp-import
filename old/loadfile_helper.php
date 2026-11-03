<?php

if(!class_exists("SFTP_LOAD_FILE_HELPER")){
    class SFTP_LOAD_FILE_HELPER {
        static function loadData($localfname,$fname,$tablename,$server,$columns,$db,$enclose='"',$delimiter=";",$line_delimiter="\n"){

            $delimiter=str_replace("\\t","\t",$delimiter);
            $delimiter=str_replace("\\r","\r",$delimiter);
            $delimiter=str_replace("\\n","\n",$delimiter);

            $line_delimiter=str_replace("\\t","\t",$line_delimiter);
            $line_delimiter=str_replace("\\r","\r",$line_delimiter);
            $line_delimiter=str_replace("\\n","\n",$line_delimiter);
            

            $importcolumns = $db->direct('select * from ds_column where table_name={t}',['t'=>$tablename],'');
            $sql = 'create table if not exists `'.$tablename.'_imported`(
                md5id varchar(36) primary key,
                filename varchar(255),
                importdatetime datetime,
                imported tinyint default 0,
                message longtext
            )';
            $db->execute($sql);

$sql = 'call addFieldIfNotExists("'.$tablename.'","import_line","integer")';
            $db->execute($sql);
            $db->execute('set @import_line=0');

            $load_file = '
LOAD DATA LOCAL INFILE
             "'.$localfname.'"
                IGNORE
                INTO TABLE '.$tablename.'
                CHARACTER SET utf8
                FIELDS
                    OPTIONALLY ENCLOSED BY \''.$enclose.'\'
                    TERMINATED BY "'.$delimiter.'"
                LINES
                    TERMINATED BY "'.$line_delimiter.'"
                    IGNORE 1 LINES
                (__FLDS__)
                set
                    filename="'.$fname.'",
                    import_line = @import_line:=@import_line+1
            ';
            if(isset($server['addmd5id'])&&($server['addmd5id']==1)){
                $load_file .= ' ,md5id=md5(concat(__FLDS__))';
            }

            $use_pkey = false;
            foreach($importcolumns as $ci){
                if ($ci['column_name']=='pkey'){
                    $use_pkey = true;
                }
            }
            if( $use_pkey == true ){
                $load_file .= ' ,pkey=uuid()';
            }
            
            $row=0;
            ini_set('auto_detect_line_endings',TRUE);
            if (($handle = fopen($localfname, "r")) !== FALSE) {

                if (fgets($handle, 4) !== "\xef\xbb\xbf")  rewind($handle); //Or rewind pointer to start of file


                $FLDS = [];
                $FHASH = [];
                $col_num =0;
                //print_r($columns);exit();



                        
                while (($data = fgetcsv($handle, 4000, $delimiter)) !== FALSE) {
                    if ($row==0){
                        //$lcolumns=json_decode(json_encode($columns),true);

                        $lcolumns = [];
                        foreach($columns as $column){
                            $lcolumns[$column['label']]=$column;
                        }
                        $index=0;
                        foreach($columns as $column){
                            $col_num =count($data);
                            foreach($data as $c=>$v){



                                if (
                                    !isset($columns[ ($v)]) &&
                                    !isset($lcolumns[ ($v)])
                                ){
                                    throw new Exception('Column '.$v.' is missed in the table');
                                }

                                if (($column['label']==$v) || ($column['column_name']==$v) )
                                {
                                    $FHASH[$column['column_name']] = $c;
                                }
                            }
                        }
                    }
                    break;
                }
                fclose($handle);
                for($i=0;$i<$col_num;$i++){
                    foreach($FHASH as $fk=>$f_index){
                        if ($i==$f_index){
                            $FLDS[] = $fk;
                        }            
                    }
                }
                $load_file = str_replace('__FLDS__',implode(','."\n",$FLDS),$load_file);
                TualoApplication::result('flds',$FLDS);
                TualoApplication::result('load_file', $load_file); 
//echo $load_file;
//exit();
                $db->direct($load_file);
            }
        }
    }
}

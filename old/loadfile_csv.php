<?php

if(!class_exists("LOAD_FILE_CSV")){
    class LOAD_FILE_CSV {
        static function loadData($localfname,$fname,$tablename,$server,$columns,$db,$enclose='"',$delimiter=";",$line_delimiter="\n"){

            $delimiter=str_replace("\\t","\t",$delimiter);
            $delimiter=str_replace("\\r","\r",$delimiter);
            $delimiter=str_replace("\\n","\n",$delimiter);

            $line_delimiter=str_replace("\\t","\t",$line_delimiter);
            $line_delimiter=str_replace("\\r","\r",$line_delimiter);
            $line_delimiter=str_replace("\\n","\n",$line_delimiter);
            


            $csv = League\Csv\Reader::createFromPath($localfname, 'r');
            $csv->setHeaderOffset(0);
            $csv->setDelimiter($delimiter);
            $csv->setEnclosure($enclose);
            // $csv->setEscape("\\");
            $csv->skipInputBOM();
            $header = $csv->getHeader(); //returns the CSV header record
            $records = $csv->getRecords(); //returns all the CSV records as an Iterator object

            $importdata = [];
            foreach ($records as $record) {
                $import_record = [
                    '__table_name'=> $tablename,
                    $tablename.'__'.'filename'    => $fname
                ];
                
                $data = "";
                foreach($record as $key=>$value){
                    $import_record[$tablename.'__'.$key]=$value;
                    $data.=$value;
                }
                $import_record[$tablename.'__'.'md5id'] = md5($data);
                $importdata[] = $import_record;
            }


            $db->execute('SET @data={data}',['data'=>json_encode($importdata)]);
            $db->execute('call ds_rest_api_set(@data,@result)');
            TualoApplication::result('dataresult', json_decode( $db->singleValue('select @result r',[],'r'),true) ); 
        }
    }
}
<?php
declare(strict_types=1);

/** Shared upload primitives for Management Portal documents. */

function manage_document_upload_types(bool $includeText=false): array
{
    $types=[
        'pdf'=>'application/pdf',
        'xls'=>'application/vnd.ms-excel',
        'xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'csv'=>'text/csv',
        'doc'=>'application/msword',
        'docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'jpg'=>'image/jpeg',
        'jpeg'=>'image/jpeg',
        'png'=>'image/png',
        'webp'=>'image/webp',
    ];
    if($includeText)$types=['txt'=>'text/plain']+$types;
    return $types;
}

/**
 * Validate and store a group of uploads atomically at the storage layer.
 *
 * Required options: path (callable). Optional options: allowed, max_bytes,
 * upload_error, size_error, type_error, mismatch_error, validate and enrich.
 */
function manage_store_document_uploads(array $files,array $options): array
{
    manage_require_s4_storage();
    $allowed=(array)($options['allowed']??manage_document_upload_types());
    $maxBytes=(int)($options['max_bytes']??26214400);
    $pathBuilder=$options['path']??null;
    if(!is_callable($pathBuilder))throw new InvalidArgumentException('An upload storage path builder is required.');
    $lenientMimeExtensions=(array)($options['lenient_mime_extensions']??['xls','xlsx','doc','docx']);
    $stored=[];
    try{
        foreach(app_uploaded_file_entries($files) as $index=>$file){
            if($file['error']===UPLOAD_ERR_NO_FILE)continue;
            if($file['error']!==UPLOAD_ERR_OK)throw new RuntimeException((string)($options['upload_error']??'One of the files could not be uploaded.'));
            if($file['size']<1||$file['size']>$maxBytes)throw new RuntimeException((string)($options['size_error']??'Each file must be 25 MB or smaller.'));
            $original=basename($file['name']);
            $extension=strtolower(pathinfo($original,PATHINFO_EXTENSION));
            if(isset($options['validate_extension'])&&is_callable($options['validate_extension']))$options['validate_extension']($file,$extension);
            if(!isset($allowed[$extension]))throw new RuntimeException((string)($options['type_error']??'This file type is not supported.'));
            $temporaryPath=$file['tmp_name'];
            $mime=(string)(new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
            if(!in_array($extension,$lenientMimeExtensions,true)&&$mime!==$allowed[$extension])throw new RuntimeException((string)($options['mismatch_error']??'A file does not match its file extension.'));
            if(isset($options['validate'])&&is_callable($options['validate']))$options['validate']($file,$extension,$mime);
            $path=(string)$pathBuilder($extension,$index,$original);
            if($path==='')throw new RuntimeException('The upload storage path could not be prepared.');
            $extra=[];
            if(isset($options['enrich'])&&is_callable($options['enrich']))$extra=(array)$options['enrich']($file,$extension,$mime);
            znp_storage_store_uploaded_file($path,$temporaryPath,$extension,$mime);
            $stored[]=array_merge(['path'=>$path,'name'=>$original,'mime'=>$mime,'size'=>$file['size']],$extra);
        }
    }catch(Throwable $exception){
        foreach($stored as $storedFile){
            try{znp_storage_delete((string)$storedFile['path']);}
            catch(Throwable $cleanup){error_log('Management upload cleanup failed: '.$cleanup->getMessage());}
        }
        throw $exception;
    }
    return $stored;
}

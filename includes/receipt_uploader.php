<?php
declare(strict_types=1);

final class ZnpReceiptUploaderException extends RuntimeException {}

function znp_receipt_uploader_passcode_fingerprint(): string
{
    $storedHash=setting('receipt_uploader_passcode_hash');
    return $storedHash===''?'receipt-uploader-default-v1':hash('sha256',$storedHash);
}

function znp_receipt_uploader_passcode_valid(string $passcode): bool
{
    if(!preg_match('/^[1-5]{4}$/D',$passcode))return false;
    $storedHash=setting('receipt_uploader_passcode_hash');
    return $storedHash===''?hash_equals('1234',$passcode):password_verify($passcode,$storedHash);
}

function znp_receipt_uploader_is_unlocked(): bool
{
    if(session_status()!==PHP_SESSION_ACTIVE)session_start();
    $sessionFingerprint=(string)($_SESSION['receipt_uploader_access']??'');
    return $sessionFingerprint!==''&&hash_equals(znp_receipt_uploader_passcode_fingerprint(),$sessionFingerprint);
}

function znp_receipt_uploader_unlock(): void
{
    if(session_status()!==PHP_SESSION_ACTIVE)session_start();
    $_SESSION['receipt_uploader_access']=znp_receipt_uploader_passcode_fingerprint();
    unset($_SESSION['receipt_uploader_pin_failures'],$_SESSION['receipt_uploader_pin_locked_until']);
}

function znp_receipt_uploader_normalize_files(array $files): array
{
    $names=$files['name']??[];
    if(!is_array($names))$names=[$names];
    $normalized=[];
    foreach($names as $index=>$name){
        $normalized[]=[
            'name'=>(string)$name,
            'tmp_name'=>(string)($files['tmp_name'][$index]??''),
            'error'=>(int)($files['error'][$index]??UPLOAD_ERR_NO_FILE),
            'size'=>(int)($files['size'][$index]??0),
        ];
    }
    return $normalized;
}

function znp_receipt_uploader_jpeg_blob(string $source): array
{
    if(class_exists(Imagick::class)){
        $image=new Imagick();
        try{
            $image->readImage($source);
            $image->setIteratorIndex(0);
            if(method_exists($image,'autoOrientImage'))$image->autoOrientImage();
            $image->setImageBackgroundColor(new ImagickPixel('white'));
            $flattened=$image->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
            $flattened->thumbnailImage(2400,3200,true,true);
            $flattened->setImageFormat('jpeg');
            $flattened->setImageCompression(Imagick::COMPRESSION_JPEG);
            $flattened->setImageCompressionQuality(88);
            $flattened->stripImage();
            $width=$flattened->getImageWidth();$height=$flattened->getImageHeight();$jpeg=$flattened->getImagesBlob();
            $flattened->clear();
        }finally{$image->clear();}
        if($width<1||$height<1||$jpeg==='')throw new ZnpReceiptUploaderException('The photo could not be prepared.');
        return [$jpeg,$width,$height];
    }
    if(!function_exists('imagecreatefromstring'))throw new ZnpReceiptUploaderException('Photo conversion is not available on this server.');
    $contents=file_get_contents($source);$sourceImage=$contents===false?false:@imagecreatefromstring($contents);
    if($sourceImage===false)throw new ZnpReceiptUploaderException('The uploaded photo could not be read.');
    $sourceWidth=imagesx($sourceImage);$sourceHeight=imagesy($sourceImage);$scale=min(1,2400/max(1,$sourceWidth),3200/max(1,$sourceHeight));
    $width=max(1,(int)round($sourceWidth*$scale));$height=max(1,(int)round($sourceHeight*$scale));$canvas=imagecreatetruecolor($width,$height);
    if($canvas===false){imagedestroy($sourceImage);throw new ZnpReceiptUploaderException('The photo could not be prepared.');}
    $white=imagecolorallocate($canvas,255,255,255);imagefill($canvas,0,0,$white);imagecopyresampled($canvas,$sourceImage,0,0,0,0,$width,$height,$sourceWidth,$sourceHeight);
    ob_start();imagejpeg($canvas,null,88);$jpeg=(string)ob_get_clean();imagedestroy($canvas);imagedestroy($sourceImage);
    if($jpeg==='')throw new ZnpReceiptUploaderException('The photo could not be converted.');
    return [$jpeg,$width,$height];
}

function znp_receipt_uploader_pdf_from_jpeg(string $jpeg,int $imageWidth,int $imageHeight): string
{
    $landscape=$imageWidth>$imageHeight;$pageWidth=$landscape?792.0:612.0;$pageHeight=$landscape?612.0:792.0;$margin=24.0;
    $scale=min(($pageWidth-$margin*2)/$imageWidth,($pageHeight-$margin*2)/$imageHeight);$renderWidth=$imageWidth*$scale;$renderHeight=$imageHeight*$scale;
    $x=($pageWidth-$renderWidth)/2;$y=($pageHeight-$renderHeight)/2;
    $content=sprintf("q\n%.3F 0 0 %.3F %.3F %.3F cm\n/Im0 Do\nQ\n",$renderWidth,$renderHeight,$x,$y);
    $objects=[
        1=>'<< /Type /Catalog /Pages 2 0 R >>',
        2=>'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        3=>sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.0F %.0F] /Resources << /XObject << /Im0 5 0 R >> >> /Contents 4 0 R >>',$pageWidth,$pageHeight),
        4=>'<< /Length '.strlen($content).">>\nstream\n".$content.'endstream',
        5=>'<< /Type /XObject /Subtype /Image /Width '.$imageWidth.' /Height '.$imageHeight.' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length '.strlen($jpeg).">>\nstream\n".$jpeg."\nendstream",
    ];
    $pdf="%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";$offsets=[0];
    foreach($objects as $number=>$body){$offsets[$number]=strlen($pdf);$pdf.=$number." 0 obj\n".$body."\nendobj\n";}
    $xref=strlen($pdf);$pdf.="xref\n0 6\n0000000000 65535 f \n";
    for($number=1;$number<=5;$number++)$pdf.=sprintf("%010d 00000 n \n",$offsets[$number]);
    return $pdf."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
}

function znp_receipt_uploader_photo_to_pdf(string $source): string
{
    [$jpeg,$width,$height]=znp_receipt_uploader_jpeg_blob($source);
    return znp_receipt_uploader_pdf_from_jpeg($jpeg,$width,$height);
}

function znp_receipt_uploader_store_files(array $files,int $propertyId,int $year,int $month): array
{
    if(!znp_storage_uses_s4())throw new ZnpReceiptUploaderException('Receipt Uploader requires MEGA S4 storage.');
    $uploads=array_values(array_filter(znp_receipt_uploader_normalize_files($files),static fn(array $file):bool=>$file['error']!==UPLOAD_ERR_NO_FILE));
    if(!$uploads)throw new ZnpReceiptUploaderException('Choose a PDF or photo to upload.');
    if(count($uploads)>10)throw new ZnpReceiptUploaderException('Upload no more than 10 files at a time.');
    $allowedImages=['image/jpeg','image/png','image/webp'];$stored=[];
    try{
        foreach($uploads as $file){
            if($file['error']!==UPLOAD_ERR_OK)throw new ZnpReceiptUploaderException('One of the files could not be uploaded.');
            if($file['size']<1||$file['size']>26214400)throw new ZnpReceiptUploaderException('Each file must be 25 MB or smaller.');
            $tmp=$file['tmp_name'];$mime=(string)(new finfo(FILEINFO_MIME_TYPE))->file($tmp);$isPdf=$mime==='application/pdf';$isImage=in_array($mime,$allowedImages,true);
            if(!$isPdf&&!$isImage)throw new ZnpReceiptUploaderException('Upload PDF, JPG, PNG, or WebP files only.');
            if($isPdf){$handle=fopen($tmp,'rb');$signature=$handle?fread($handle,5):false;if(is_resource($handle))fclose($handle);if($signature!=='%PDF-')throw new ZnpReceiptUploaderException('A PDF file did not pass validation.');}
            else{$imageInfo=@getimagesize($tmp);if(!$imageInfo||!in_array((string)($imageInfo['mime']??''),$allowedImages,true))throw new ZnpReceiptUploaderException('A photo file did not pass validation.');}
            $path='uploads/manage/receipts/'.$propertyId.'/'.$year.'/'.str_pad((string)$month,2,'0',STR_PAD_LEFT).'/backup_invoices/'.bin2hex(random_bytes(16)).'.pdf';
            $original=basename($file['name']);
            if($isPdf){znp_storage_store_uploaded_file($path,$tmp,'pdf','application/pdf');$displayName=mb_substr($original,0,190);$size=$file['size'];}
            else{$pdf=znp_receipt_uploader_photo_to_pdf($tmp);znp_storage_store_generated_file($path,$pdf,'pdf','application/pdf');$base=trim((string)pathinfo($original,PATHINFO_FILENAME))?:'mobile-photo';$displayName=mb_substr($base,0,186).'.pdf';$size=strlen($pdf);}
            $stored[]=['path'=>$path,'name'=>$displayName,'mime'=>'application/pdf','size'=>$size];
        }
    }catch(Throwable $exception){foreach($stored as $storedFile)try{znp_storage_delete((string)$storedFile['path']);}catch(Throwable $cleanup){}throw $exception;}
    return $stored;
}

function znp_receipt_uploader_rate_limit(int $fileCount,bool $record=false): void
{
    if($fileCount<1)return;if(session_status()!==PHP_SESSION_ACTIVE)session_start();$now=time();$events=array_values(array_filter($_SESSION['public_receipt_uploads']??[],static fn($timestamp):bool=>(int)$timestamp>$now-3600));
    if(count($events)+$fileCount>40)throw new ZnpReceiptUploaderException('Upload limit reached. Please try again later.');
    $ipHash=hash('sha256',(string)($_SERVER['REMOTE_ADDR']??'unknown'));$rateFile=sys_get_temp_dir().'/znp-receipt-rate-'.$ipHash.'.json';$handle=@fopen($rateFile,'c+');
    if($handle!==false){try{if(flock($handle,LOCK_EX)){rewind($handle);$storedEvents=json_decode((string)stream_get_contents($handle),true);if(!is_array($storedEvents))$storedEvents=[];$storedEvents=array_values(array_filter($storedEvents,static fn($timestamp):bool=>(int)$timestamp>$now-3600));if(count($storedEvents)+$fileCount>80)throw new ZnpReceiptUploaderException('Upload limit reached. Please try again later.');if($record){for($index=0;$index<$fileCount;$index++)$storedEvents[]=$now;ftruncate($handle,0);rewind($handle);fwrite($handle,json_encode($storedEvents));fflush($handle);}flock($handle,LOCK_UN);}}finally{fclose($handle);}}
    if($record){for($index=0;$index<$fileCount;$index++)$events[]=$now;$_SESSION['public_receipt_uploads']=$events;}
}

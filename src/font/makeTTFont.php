<?php
require_once('./ttfonts.php');


$ttfFile = __DIR__.'/simhei.ttf'; // 字体文件

makeTTFFont($ttfFile);

function makeTTFFont($ttfFile){
    $ttf = new TTFontFile();
    $fontPath = dirname($ttfFile);
    $fontName = strtolower(basename($ttfFile,'.ttf'));
    $ttf->getMetrics($ttfFile);
    $cw = $ttf->charWidths;
    $name = preg_replace('/[ ()]/','',$ttf->fullName);

    $desc= [
        'Ascent'=>round($ttf->ascent),
        'Descent'=>round($ttf->descent),
        'CapHeight'=>round($ttf->capHeight),
        'Flags'=>$ttf->flags,
        'FontBBox'=>'['.round($ttf->bbox[0])." ".round($ttf->bbox[1])." ".round($ttf->bbox[2])." ".round($ttf->bbox[3]).']',
        'ItalicAngle'=>$ttf->italicAngle,
        'StemV'=>round($ttf->stemV),
        'MissingWidth'=>round($ttf->defaultWidth)
    ];
    $up = round($ttf->underlinePosition);
    $ut = round($ttf->underlineThickness);
    $ttfstat = stat($ttfFile);
    $originalsize = $ttfstat['size']+0;
    $type = 'TTF';
    // Generate metrics .php file
    $s='<?php'."\n";
    $s.='$name=\''.$name."';\n";
    $s.='$type=\''.$type."';\n";
    $s.='$desc='.var_export($desc,true).";\n";
    $s.='$up='.$up.";\n";
    $s.='$ut='.$ut.";\n";
    $s.='$ttffile=\''.$ttfFile."';\n";
    $s.='$originalsize='.$originalsize.";\n";
    $s.='$fontkey=\''.$fontName."';\n";
    $s.="?>";

    $mtx = sprintf('%s/%s.mtx.php', $fontPath, $fontName);
    $fh = fopen($mtx,"w");
    fwrite($fh,$s,strlen($s));
    fclose($fh);
    $cwFile = sprintf('%s/%s.cw.dat', $fontPath, $fontName);
    $fh = fopen($cwFile,"wb");
    fwrite($fh,$cw,strlen($cw));
    fclose($fh);
    unset($ttf);
}

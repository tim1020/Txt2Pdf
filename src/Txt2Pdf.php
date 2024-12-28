<?php

class Txt2Pdf {

    private $pdfVersion   = '1.5';
    private $fp           = null;            // 文件句柄
    private $fname        = '';              // 文件内容
    private $subset       = [];              // 字符集合 
    private $objs         = [];              // 所有的对象
    private $pageCnt      = 0;               // 页数
    private $offset       = 0;               // 当前偏移
    private $error        = '';
    private $pageLines    = [];              // 当前页面内容（按行)
    private $pageContents = [];              // 所有页面contents对象序号

    // 以下内容可通过构造参数修改
    private $metadata     = ['Producer'=>'MyPdf','Author'=>'Tim', 'Creator'=>'xiaohou'];              // 文件meta信息
    private $margins      = [15,15,15,15];   // 页边距(left,top,right,bottom)
    private $line_spacing = 10;              // 行距
    private $font_path    = __DIR__ .'/font'; // 字体路径（可写）
    private $font_family  = 'simhei';        // 字体   
    private $font_size    = 14;              // 字号
    private $h            = 841.89;          // 页面高
    private $w            = 595.28;          // 页面宽

    // 初始化设置
    function __construct($dest, $options = []){
        $setting = ['metadata','size','margins','line_spacing','font_family','font_size','font_path'];  // 可以修改的选项
        foreach($options as $k=>$v) {
            $k = strtolower($k);
            if(!in_array($k, $setting)) $this->_error("初始化失败，参数无效: {$k}");
            // todo:验证参数格式
            if($k == 'size') {
                $sizes = [
                    'a4' => [595.28, 841.89],
                ];
                $pagesize = $sizes[$v]??'';
                if(!$pagesize) $this->_error("初始化失败，参数值无效: {$k}");
                [$this->w, $this->h] = $pagesize;
            } else {
                $this->{$k} = $v;
            }
        }
        // 写入文件头
        $this->dest = $dest;
        $this->_put("%%PDF-%s", $this->pdfVersion);
    }

    // 关闭文件句柄，异常时删除文件
    function __destruct(){
        if($this->fp)    @fclose($this->fp);
        if($this->error) @unlink($this->dest);
    }

    // 设置文件信息
    function setMetadata($data){
        $keys = ['Title','Author','Subject','Keywords','Creator'];
        foreach($data as $k=>$v){
            if(!in_array($k,$keys)) $this->_error('metadata key invalid:'.$k);
            $this->metadata[$k] = $v;
        }

    }

    // 添加页面
    function addPage($txt=""){
        if($this->pageCnt > 0) { // 非第一页时，输出上一页内容
            $no = $this->_addContents($this->pageLines);
            $this->pageContents[] = $no; // 页面contents对象序号
            $this->pageLines      = [];
        }
        $this->pageCnt ++;
        if($txt) {
            $this->write($txt);
        }
    }
    
    // 添加一行文字 //TODO 自动换行
    function write($txt){
        // 记录所有用到的字符集
        foreach($this->UTF8StringToArray($txt) as $uni) {
            $this->subset[$uni] = $uni;
        }
        if($this->pageCnt == 0) {
            $this->addPage();
        }
        $lines = count($this->pageLines);
        [$l,$t,$r,$b] = $this->margins;
        $lineH = $this->font_size;       // 行高=字体大小
        $lineS = $this->line_spacing;    // 行距
        // 计算最大行数，并自动换页
        $maxLines = floor(($this->h - ($t+$b)) / ($lineH + $lineS));
        if($lines>=$maxLines) {
            return $this->addPage($txt);
        }
        $x = $l;
        $y = $this->h - $t - $lineH - $lines * ($lineH + $lineS); // 页高-tMargin-行高 - 行数*(行距+行高)
        $txt = $this->_escape($this->UTF8ToUTF16BE($txt, false));
        $this->pageLines[] = sprintf("BT %.2F %.2f Td (%s) Tj ET\n", $x, $y, $txt);
    }

    // 保存pdf文件
    function save($dest = ''){
        // 保存最后一个页面内容
        //if($this->pageLines) { 
            $nb = $this->_addContents($this->pageLines);
            $this->pageContents[] = $nb; // 页面contents对象序号
        //}
        // 生成资源对象
        $resNo  = $this->_addResources();
        // 生成pages
        $pagesNo = $this->_addPages($resNo, $this->pageContents);
        // 生成info和catalog
        $infoNo     = $this->_addInfo();
        $catalogNo  = $this->_addCatalog($pagesNo);

        $obsCnt   = count($this->objs)+1;
        $startref = $this->offset;
        // xref
        $this->_put("xref\n0 %s\n0000000000 65535 f", $obsCnt);
        foreach($this->objs as $pos){
            $this->_put("%s 00000 n", str_pad($pos,10,'0',STR_PAD_LEFT));
        }
        //trailer
        $this->_put("trailer\n<</Size %s /Root %s 0 R /Info %s 0 R>>", $obsCnt, $catalogNo, $infoNo);
        $this->_put("startxref\n%s", $startref);
        $this->_put("%%EOF");
        fclose($this->fp);
    }

    ########### 私有方法 #######

    private function _error($msg){
        $this->error = $msg;
        throw new Exception('[error]'.$msg);
    }

    // 输出内容到目标文件
    private function _put($s, ...$args){
        if(!$this->fp) {
            $this->fp = @fopen($this->dest, 'wb+');
            if(!$this->fp) return $this->_error('创建目标文件失败,请检查目录和权限');
        }
        if(count($args) > 0) {
            $s = sprintf($s,...$args);
        }
        fwrite($this->fp, $s);
        fwrite($this->fp, "\n");
        $this->offset += strlen($s)+1;
    }

    // 添加新对象
    private function _addObj($content, $no = ''){
        if(!$no) $no = count($this->objs)+1;
        $this->objs[] = $this->offset;
        $this->_put("%s 0 obj", $no);
        $this->_put($content);
        $this->_put("endobj");
        return $no;
    }

    // 添加文件信息对象
    private function _addInfo(){
        $date = @date('YmdHisO');
        $this->metadata['CreationDate'] = 'D:'.substr($date,0,-2)."'".substr($date,-2)."'";
        $str = "<<\n";
        foreach($this->metadata as $key=>$value) {
            $str.= sprintf("/%s %s\n", $key, $this->_textstring($value));
        }
        $str.=">>";
        return $this->_addObj($str);
    }

    // 添加资源声明
    private function _addResources(){
        $fontObjNo = $this->_addFonts();
        $str = "<</ProcSet [/PDF /Text]\n";
        $str.= "/Font <<";
        $str.= sprintf("/F1 %d 0 R", $fontObjNo);
        $str.= ">>\n>>";
        return $this->_addObj($str);
    }

    // 添加字体相关对象
    private function _addFonts(){
        $fontNo = count($this->objs) + 1;
        $dir     =  $this->font_path;
        $family  = $this->font_family;
        //TODO: 多字体
		$ttffile = sprintf('%s/%s.ttf', $dir, $family);
		$mtxFile = sprintf('%s/%s.mtx.php', $dir, $family);
		$cwFile  = sprintf('%s/%s.cw.dat',  $dir, $family);
		include($mtxFile);
		$cw = @file_get_contents($cwFile); 
		$sbarr = range(0,57);
		$font = [
			'type'=>$type, 'name'=>$name, 'desc'=>$desc, 'up'=>$up, 'ut'=>$ut, 'cw'=>$cw, 
			'ttffile'=>$ttffile, 'fontkey'=>$fontkey, 'subset'=>$sbarr, 'unifilename'=>$dir.'/'.$family
		];

        require $dir."/ttfonts.php";
        $ttf = new TTFontFile();
        $fontname = 'MPDFAA'.'+'.$font['name'];
        $font['subset'] += $this->subset;
        $subset = $font['subset'];

        unset($subset[0]);
        $ttfontstream = $ttf->makeSubset($font['ttffile'], $subset);
        $ttfontsize = strlen($ttfontstream);
        $fontstream = gzcompress($ttfontstream);
        $codeToGlyph = $ttf->codeToGlyph;
        unset($codeToGlyph[0]);

        $str = sprintf("<</Type /Font\n/Subtype /Type0 /BaseFont /%s\n", $fontname);
        $str.= sprintf("/Encoding /Identity-H\n/DescendantFonts [%d 0 R]\n", $fontNo+1);
        $str.= sprintf("/ToUnicode %d 0 R\n>>", $fontNo+2);
        $this->_addObj($str);

        // DescendantFonts
        $str = sprintf("<</Type /Font\n/Subtype /CIDFontType2 /BaseFont /%s\n", $fontname);
        $str.= sprintf("/CIDSystemInfo <</Registry (Adobe) /Ordering (UCS) /Supplement 0>>\n/FontDescriptor %d 0 R\n", $fontNo+3); 
        if (isset($font['desc']['MissingWidth'])){
           $str.= sprintf("/DW %s\n", $font['desc']['MissingWidth']); 
        }
        $str.=$this->_putTTfontwidths($font, $ttf->maxUni);
        $str.= sprintf("/CIDToGIDMap %d 0 R\n>>", $fontNo+4);
        $this->_addObj($str);

        // ToUnicode
        $toUni = "/CIDInit /ProcSet findresource begin\n";
        $toUni .= "12 dict begin\n";
        $toUni .= "begincmap\n";
        $toUni .= "/CIDSystemInfo\n";
        $toUni .= "<</Registry (Adobe)\n";
        $toUni .= "/Ordering (UCS)\n";
        $toUni .= "/Supplement 0\n";
        $toUni .= ">> def\n";
        $toUni .= "/CMapName /Adobe-Identity-UCS def\n";
        $toUni .= "/CMapType 2 def\n";
        $toUni .= "1 begincodespacerange\n";
        $toUni .= "<0000> <FFFF>\n";
        $toUni .= "endcodespacerange\n";
        $toUni .= "1 beginbfrange\n";
        $toUni .= "<0000> <FFFF> <0000>\n";
        $toUni .= "endbfrange\n";
        $toUni .= "endcmap\n";
        $toUni .= "CMapName currentdict /CMap defineresource pop\n";
        $toUni .= "end\n";
        $toUni .= "end";
        $str = sprintf("<</Length %s>>\nstream\n%s\nendstream", strlen($toUni), $toUni);
        //$toUni = gzcompress($toUni);
        //$str = sprintf("<</Length %d /Filter /FlateDecode>>\nstream\n%s\nendstrean", strlen($toUni), $toUni);
        $this->_addObj($str);

        // Font descriptor
        $str = sprintf("<</Type /FontDescriptor /FontName /%s\n",$fontname);
        foreach($font['desc'] as $kd=>$v) {
            if ($kd == 'Flags') { $v = $v | 4; $v = $v & ~32; }	// SYMBOLIC font flag
            $str.= sprintf("/%s %s\n", $kd, $v);
        }
        $str.= sprintf("/FontFile2 %d 0 R\n>>",  $fontNo+5);
        $this->_addObj($str);

        // Embed CIDToGIDMap
        // A specification of the mapping from CIDs to glyph indices
        $cidtogidmap = '';
        $cidtogidmap = str_pad('', 256*256*2, "\x00");
        foreach($codeToGlyph as $cc=>$glyph) {
            $cidtogidmap[$cc*2] = chr($glyph >> 8);
            $cidtogidmap[$cc*2 + 1] = chr($glyph & 0xFF);
        }
        $cidtogidmap = gzcompress($cidtogidmap);
        $str = sprintf("<</Length %d /Filter /FlateDecode>>\n", strlen($cidtogidmap));
        $str.= sprintf("stream\n%s\nendstream", $cidtogidmap) ;
        $this->_addObj($str);
 
        //Font file 
        $str = sprintf("<</Length %s /Filter /FlateDecode /Length1 %s>>\n", strlen($fontstream), $ttfontsize);
        $str.= sprintf("stream\n%s\nendstream", $fontstream) ;
        $this->_addObj($str);

        return $fontNo;
    }

    // 添加pages对象
    private function _addPages($resNo, $contents){
        $pagesNo = count($this->objs)+count($contents)+1; // 跳过page的序号
        // add page
        $kids = [];
        foreach($contents as $contentNo){
            $str = sprintf("<</Type /Page /Parent %s 0 R /Resources %s 0 R /Contents %s 0 R>>", $pagesNo, $resNo, $contentNo);
            $no = $this->_addObj($str);
            $kids[] = sprintf("%s 0 R", $no);
        }

        $str = "<</Type /Pages\n";
        $str.= sprintf("/Kids [%s]\n", implode(" ", $kids));
        $str.= sprintf("/Count %s /MediaBox [0 0 %.2F %.2F]>>", $this->pageCnt, $this->w, $this->h);
        return $this->_addObj($str, $pagesNo);
    }

    // 添加页面content
    private function _addContents($lines){
        $pageContent = sprintf("100 w\nBT /F1 %.2F Tf ET\n",  $this->font_size);
        $pageContent.= implode("\n", $lines);
        $s = gzcompress($pageContent);
        $result = sprintf("<</Filter /FlateDecode /Length %s>>\n", strlen($s));
        $result.= sprintf("stream\n%s\nendstream", $s);
        return $this->_addObj($result);
    }

    // 添加catalog对象
    private function _addCatalog($pagesNo){
        $str = sprintf("<</Type /Catalog /Pages %s 0 R>>", $pagesNo);
        return $this->_addObj($str);
    }

    private function _putTTfontwidths($font, $maxUni) {
        $rangeid = 0;
        $range = array();
        $prevcid = -2;
        $prevwidth = -1;
        $interval = false;
        $startcid = 1;
        
        $cwlen = $maxUni + 1; 

        // for each character
        for ($cid=$startcid; $cid<$cwlen; $cid++) {
            if ($cid==128 && (!file_exists($font['unifilename'].'.cw127.php'))) {
                if (is_writable(dirname(__DIR__.'/font/unifont/x'))) {
                    $fh = fopen($font['unifilename'].'.cw127.php',"wb");
                    $cw127='<?php'."\n";
                    $cw127.='$rangeid='.$rangeid.";\n";
                    $cw127.='$prevcid='.$prevcid.";\n";
                    $cw127.='$prevwidth='.$prevwidth.";\n";
                    if ($interval) { $cw127.='$interval=true'.";\n"; }
                    else { $cw127.='$interval=false'.";\n"; }
                    $cw127.='$range='.var_export($range,true).";\n";
                    $cw127.="?>";
                    fwrite($fh,$cw127,strlen($cw127));
                    fclose($fh);
                }
            }
            if ((!isset($font['cw'][$cid*2]) || !isset($font['cw'][$cid*2+1])) ||  ($font['cw'][$cid*2] == "\00" && $font['cw'][$cid*2+1] == "\00")) { continue; }

            $width = (ord($font['cw'][$cid*2]) << 8) + ord($font['cw'][$cid*2+1]);
            if ($width == 65535) { $width = 0; }
            if ($cid > 255 && (!isset($font['subset'][$cid]) || !$font['subset'][$cid])) { continue; }
            if (!isset($font['dw']) || (isset($font['dw']) && $width != $font['dw'])) {
                if ($cid == ($prevcid + 1)) {
                    if ($width == $prevwidth) {
                        if ($width == $range[$rangeid][0]) {
                            $range[$rangeid][] = $width;
                        } else {
                            array_pop($range[$rangeid]);
                            $rangeid = $prevcid;
                            $range[$rangeid] = array();
                            $range[$rangeid][] = $prevwidth;
                            $range[$rangeid][] = $width;
                        }
                        $interval = true;
                        $range[$rangeid]['interval'] = true;
                    } else {
                        if ($interval) {
                            $rangeid = $cid;
                            $range[$rangeid] = array();
                            $range[$rangeid][] = $width;
                        }else { $range[$rangeid][] = $width; }
                        $interval = false;
                    }
                } else {
                    $rangeid = $cid;
                    $range[$rangeid] = array();
                    $range[$rangeid][] = $width;
                    $interval = false;
                }
                $prevcid = $cid;
                $prevwidth = $width;
            }
        }
        $prevk = -1;
        $nextk = -1;
        $prevint = false;
        foreach ($range as $k => $ws) {
            $cws = count($ws);
            if (($k == $nextk) AND (!$prevint) AND ((!isset($ws['interval'])) OR ($cws < 4))) {
                if (isset($range[$k]['interval'])) { unset($range[$k]['interval']); }
                $range[$prevk] = array_merge($range[$prevk], $range[$k]);
                unset($range[$k]);
            }
            else { $prevk = $k; }
            $nextk = $k + $cws;
            if (isset($ws['interval'])) {
                if ($cws > 3) { $prevint = true; }
                else { $prevint = false; }
                unset($range[$k]['interval']);
                --$nextk;
            }
            else { $prevint = false; }
        }
        $w = '';
        foreach ($range as $k => $ws) {
            if (count(array_count_values($ws)) == 1) { $w .= ' '.$k.' '.($k + count($ws) - 1).' '.$ws[0]; }
            else { $w .= ' '.$k.' [ '.implode(' ', $ws).' ]' . "\n"; }
        }
        return sprintf("/W [%s]\n", $w);
       // $this->_out('/W ['.$w.' ]');
    }

    private function _escape($s){
        if(strpos($s,'(')!==false || strpos($s,')')!==false || strpos($s,'\\')!==false || strpos($s,"\r")!==false)	return str_replace(array('\\','(',')',"\r"), array('\\\\','\\(','\\)','\\r'), $s);
        else		return $s;
    }

    private function _textstring($s){
        if(!$this->_isascii($s))  $s = $this->UTF8ToUTF16BE($s);
        return '('.$this->_escape($s).')';
    }

    // Converts UTF-8 strings to UTF16-BE.
    private function UTF8ToUTF16BE($str, $setbom=true) {
        $outstr = "";
        if ($setbom) {
            $outstr .= "\xFE\xFF"; // Byte Order Mark (BOM)
        }
        $outstr .= mb_convert_encoding($str, 'UTF-16BE', 'UTF-8');
        return $outstr;
    }

    // Converts UTF-8 strings to codepoints array
    private function UTF8StringToArray($str) {
        $out = array();
        $len = strlen($str);
        for ($i = 0; $i < $len; $i++) {
            $uni = -1;
            $h = ord($str[$i]);
            if ( $h <= 0x7F )    $uni = $h;
            elseif ( $h >= 0xC2 ) {
                if ( ($h <= 0xDF) && ($i < $len -1) )            $uni = ($h & 0x1F) << 6 | (ord($str[++$i]) & 0x3F);
                elseif ( ($h <= 0xEF) && ($i < $len -2) )        $uni = ($h & 0x0F) << 12 | (ord($str[++$i]) & 0x3F) << 6  | (ord($str[++$i]) & 0x3F);
                elseif ( ($h <= 0xF4) && ($i < $len -3) )        $uni = ($h & 0x0F) << 18 | (ord($str[++$i]) & 0x3F) << 12 | (ord($str[++$i]) & 0x3F) << 6  | (ord($str[++$i]) & 0x3F);
            }
            if ($uni >= 0) {
                $out[] = $uni;
            }
        }
        return $out;
    }

    protected function _isascii($s)  {
        // Test if string is ASCII
        $nb = strlen($s);
        for($i=0;$i<$nb;$i++){
            if(ord($s[$i])>127)
                return false;
        }
        return true;
    }
}
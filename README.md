# TXT转pdf

用法：
1、生成字体,见 font/makeTTFont.php

2、生成pdf文件

```
<?php
require 'Txt2Pdf.php';

// 设置，不指定时使用缺省值
$options = [
    'margins'       => [20,20,20,20], // 边距
    'line_spacing'  => 20,            // 行距
    'font_family'   => 'simhei',      // 字体
    'font_size'     => 16             // 字号
];

$dest = __DIR__."/ex1.pdf"; // 目标文件

$pdf = new Txt2Pdf($dest, $options);

$txt = '合作社食品真多,运动场上课铁闸下了锁，';

$pdf->write($txt); // 输出一行内容

foreach(range(1,25) as $k) {
	$pdf->write("{$k} ".$txt3);
}

// $pdf->addPage(); // 手动换页

$pdf->save();
?>

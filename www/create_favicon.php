<?php

// Создаем простой favicon
$image = imagecreate(32, 32);
$bgColor = imagecolorallocate($image, 70, 130, 180);
$textColor = imagecolorallocate($image, 255, 255, 255);

imagestring($image, 1, 4, 2, 'BOOK', $textColor);

// Сохраняем как ICO (на самом деле PNG, но для простоты)
imagepng($image, __DIR__.'/favicon.ico');
imagedestroy($image);

echo 'Favicon created at: '.__DIR__."/favicon.ico\n";

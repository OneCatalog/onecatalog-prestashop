<?php
/** Офлайн-тест чистой логики Media (§2.3, §5.3). php tests/media-test.php */
require __DIR__ . '/../onecatalogimport/classes/Media.php';
$fail = 0;
function chk($l,$g,$w,&$fail){ $ok=((string)$g===(string)$w); echo ($ok?'  ok  ':'  FAIL ')."$l (got=$g want=$w)\n"; if(!$ok)$fail++; }

function seg($raw){ return strtr(base64_encode($raw), '+/', '-_'); }
$u1 = 'https://api/media_files/'.seg('max|images/foo/bar|TOKEN_AAA').'.SIGNAAA';
$u2 = 'https://api/media_files/'.seg('max|images/foo/bar|TOKEN_BBB').'.SIGNBBB'; // другой токен/подпись
$umid = 'https://api/media_files/'.seg('middle|images/foo/bar|T').'.S';

// Контент-ключ не зависит от токена/подписи (только path#size).
chk('fileKey стабилен между токенами', OneCatalogMedia::fileKey($u1), OneCatalogMedia::fileKey($u2), $fail);
chk('fileKey = sha1(path#size)', OneCatalogMedia::fileKey($u1), sha1('images/foo/bar#max'), $fail);
chk('fileKey разный для другого размера', (OneCatalogMedia::fileKey($umid)!==OneCatalogMedia::fileKey($u1))?'1':'0', '1', $fail);
chk('guessSizeFromUrl', OneCatalogMedia::guessSizeFromUrl($u1), 'max', $fail);

// Выбор размера.
$urls = array('min'=>'a','middle'=>'b','max'=>'c');
$p = OneCatalogMedia::pickSizeInfo($urls, true);  chk('pick с токеном → max', $p['size'], 'max', $fail);
$p = OneCatalogMedia::pickSizeInfo($urls, false); chk('pick без токена → middle', $p['size'], 'middle', $fail);
$p = OneCatalogMedia::pickSizeInfo(array('min'=>'a'), true); chk('pick только min', $p['size'], 'min', $fail);

// Ранги и MIME.
chk('rank max>middle', (OneCatalogMedia::sizeRank('max')>OneCatalogMedia::sizeRank('middle'))?'1':'0','1',$fail);
chk('mime jpeg→jpg', OneCatalogMedia::mimeToExt('image/jpeg'), 'jpg', $fail);
chk('mime с charset', OneCatalogMedia::mimeToExt('image/png; charset=binary'), 'png', $fail);
chk('mime неизвестный→null', var_export(OneCatalogMedia::mimeToExt('image/svg+xml'),true), 'NULL', $fail);

echo $fail===0 ? "\nALL PASS\n" : "\n$fail FAILED\n";
exit($fail===0?0:1);

<?php

// config

// set your language (en/ja/ru/ro/es/tr/fr/de/zh/pt_BR)
$language = 'zh';
$phpVersion = '8.0.1';

// set your chm-extract command
// ** must be 'sprintf() format'
// *** arg1: target chm file, arg2: extract dir

// Windows
// $cfg_chm  = 'hh -decompile %2$s %1$s';
// Mac, Linux
$chmCommand = 'extract_chmLib %1$s %2$s';

// set true, if you have font trouble with google open sans (e.g. Zeal on windows)
$noSans = true;

// PHP docset URL
// @link https://github.com/Kapeli/feeds/blob/master/PHP.xml
// ** select download url from above xml.
$originDocsetUri = 'http://tokyo.kapeli.com/feeds/PHP.tgz';

// PHP manual URL
// @link http://php.net/download-docs.php
// ** chm (manual or enhanced) only!
$customChmUri = "https://www.php.net/distributions/manual/php_manual_zh.chm";

// File path
$originDocset = __DIR__ . '/originDocset';
$newChm = __DIR__ . '/newChm';
$DocsetContentBase = __DIR__ . '/PHP.docset/Contents';
$DocsetResourceBase = "$DocsetContentBase/Resources";
$DocsetDocumentBase = "$DocsetResourceBase/Documents";

$guide = 'Guide';

// Exec with exception logic
function tryExec($cmd)
{
    exec($cmd, $out, $code);
    if ($code) {
        throw new RuntimeException("`$cmd` returned: $code");
    }
    return $out;
}

// main

echo "\nStart build PHP {$phpVersion}-{$language} docset ...\n";

foreach ([$DocsetResourceBase, $DocsetDocumentBase, $originDocset] as $directory) {
    tryExec("mkdir -p $directory/");
}

tryExec("rm -rf {$DocsetResourceBase}/*");

echo "\nDownload original docset (en) and 'CHM' help file ...\n\n";
$tgz = 'PHP.tgz';
$chm = 'php.chm';
try {
    if (!is_file($tgz)) {
        tryExec("curl {$originDocsetUri} -o $tgz");
    }
    if (!is_file($chm)) {
        tryExec("curl {$customChmUri} -o $chm");
    }
} catch (RuntimeException $exception) {
    tryExec("rm -f $tgz");
    tryExec("rm -f $chm");
    throw $exception;
}

echo "\ndownload done. Replace docset files for your language ...\n\n";

// extract
tryExec("tar xzf $tgz -C {$originDocset} --strip-components 1");
$baseDir = "{$originDocset}/Contents/Resources/Documents/www.php.net/manual/en";

// replace html
// ** note: Do not use 'rm' command.
// **       It will cause device busy or 'Argument list too long' error.
foreach ([
             'array*', 'book.*', 'class.*', 'function.*', 'imagick*',
             'intro.*', 'mongo*', 'mysql*', 'ref.*', 'yaf-*',
         ] as $val) {
    echo "Removing original {$val} ...\n";
    foreach (glob("{$baseDir}/{$val}") as $file) {
        if (!unlink($file)) {
            throw new RuntimeException("failed when removing $file");
        }
    }
}

echo "Removing original manual/en ...\n";
tryExec("rm -rf {$originDocset}/Contents/Resources/Documents/www.php.net/manual/en");
tryExec(sprintf($chmCommand, $chm, $newChm));
tryExec("rm -f {$newChm}/res/style.css");

// copy database
if (
    !copy("{$originDocset}/Contents/Resources/docSet.dsidx", "{$DocsetResourceBase}/docSet.dsidx") or
    !copy("{$originDocset}/Contents/Resources/docSet.dsidx", "{$DocsetResourceBase}/docSet.dsidx.orig")
) {
    throw new RuntimeException("failed when copying {$originDocset}/Contents/Resources/docSet.dsidx");
}

// copy & replace documents
tryExec("mkdir -p {$DocsetDocumentBase}/www.php.net/manual");
tryExec("mv {$originDocset}/Contents/Resources/Documents/www.php.net {$DocsetDocumentBase}/php.net");
tryExec("mv {$newChm}/res {$DocsetDocumentBase}/www.php.net/manual/en");

$cssFile = __DIR__ . sprintf('/%s', $noSans ? 'style-nosans.css' : 'style.css');
if (!copy($cssFile, "{$DocsetDocumentBase}/www.php.net/manual/en/style.css")) {
    throw new RuntimeException("failed when copying $cssFile");
}

// gen Info.plist
file_put_contents("{$DocsetContentBase}/Info.plist", <<<ENDE
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
	<key>CFBundleIdentifier</key>
	<string>phpdoc-{$language}</string>
	<key>CFBundleName</key>
	<string>PHP {$phpVersion}-{$language}</string>
	<key>DocSetPlatformFamily</key>
	<string>php</string>
	<key>dashIndexFilePath</key>
	<string>php.net/manual/en/index.html</string>
	<key>DashDocSetFamily</key>
	<string>dashtoc</string>
	<key>isDashDocset</key>
	<true/>
</dict>
</plist>
ENDE
);

// update db (add target language's indexes)
echo "\nAdd search indexes from Title ...\n\n";

$db = new PDO("sqlite:{$DocsetResourceBase}/docSet.dsidx");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$res = $db->query("PRAGMA table_info('searchIndex')");
$val = false;
foreach ($res as $row) {
    if ($row['name'] == 'lang') {
        $val = true;
        break;
    }
}
if (!$val) {
    $db->exec("ALTER TABLE searchIndex ADD COLUMN lang TEXT DEFAULT 'en'");
    $db->exec("UPDATE searchIndex SET lang = 'en'");
}

$stmt = $db->prepare('SELECT * FROM searchIndex WHERE type = ?');
$stmt->execute([$guide]);
$res = $stmt->fetchAll();

$dom = new DomDocument();
$list = [];

foreach ($res as $row) {
    $file = "{$DocsetDocumentBase}/{$row['path']}";
    if (!is_file($file)) {
        echo "\ncannot find $file\n";
    }
    $html = file_get_contents($file);
    if (!$html) {
        continue;
    }
    @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'SJIS-win'));
    $t = $dom->getElementsByTagName('title')->item(0);
    if (!$t) {
        continue;
    }
    $list[] = [$t->nodeValue, $guide, $row['path'], 'zh'];
}

$stmt = $db->prepare('INSERT OR IGNORE INTO searchIndex(name, type, path, lang) VALUES (?, ?, ?, ?)');
foreach ($list as $val) {
    $stmt->execute($val);
}

echo "\ncleaning up...\n\n";
tryExec("rm -rf {$tgz}");
tryExec("rm -rf {$chm}");
tryExec("rm -rf {$originDocset}");
tryExec("rm -rf {$newChm}");

echo "\nPHP.docset is ready! Have fun!\n\n";

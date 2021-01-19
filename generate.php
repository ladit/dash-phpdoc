<?php

set_time_limit(0);

// config

// origin PHP docset URL, select download url from https://github.com/Kapeli/feeds/blob/master/PHP.xml
$originDocsetUri = 'http://sanfrancisco.kapeli.com/feeds/PHP.tgz';

// File path
$compressedTgz = __DIR__ . '/PHP.tgz';
$sourceBase = __DIR__ . '/origin.docset';
$compressedChm = __DIR__ . '/php.chm';
$extractedChm = __DIR__ . '/extractedChm';
$targetBase = __DIR__ . '/PHP.docset';

// Exec with exception logic
function tryExec($cmd)
{
    exec($cmd, $out, $code);
    if ($code) {
        throw new RuntimeException("`$cmd` failed. code: $code, result: " . json_encode($out));
    }
    return $out;
}

// main

$usage = 'usage: --identifier=8.0.1-zh [--chm=url](manual or enhanced PHP chm help file url from http://php.net/download-docs.php, eg: https://www.php.net/distributions/manual/php_manual_zh.chm)';
$opt = getopt('', ['identifier:', 'chm:']);
$identifier = $opt['identifier'] ?? '';
if (empty($identifier)) {
    echo $usage;
    exit();
}
$customChmUri = $opt['chm'] ?? '';
if (empty($customChmUri)) {
    echo $usage;
    exit();
}

echo "Start build PHP $identifier docset ..." . PHP_EOL;

// clean up
foreach ([$sourceBase, $extractedChm, "$targetBase/Contents"] as $dir) {
    tryExec("mkdir -p '$dir'");
    tryExec("rm -rf '$dir/*'");
}

echo "Download original docset (en) and 'CHM' help file ..." . PHP_EOL;
foreach ([
             $originDocsetUri => $compressedTgz,
             $customChmUri => $compressedChm,
         ] as $remote => $local) {
    if (is_file($local)) {
        continue;
    }
    $fp = fopen($local, 'w+');
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $remote,
        CURLOPT_FILE => $fp,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 300,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_AUTOREFERER => true,
        CURLOPT_FAILONERROR => true,
    ]);
    $err = '';
    if (curl_exec($ch) === false) {
        $err = curl_error($ch);
    }
    curl_close($ch);
    fclose($fp);
    if ($err) {
        throw new RuntimeException($err);
    }
}

echo "download done. extracting..." . PHP_EOL;

tryExec("tar xzf $compressedTgz -C '$sourceBase' --strip-components 1");

$chmCommand = 'extract_chmLib %1$s %2$s';
if (PHP_OS_FAMILY === 'Windows') {
    $chmCommand = 'hh -decompile %2$s %1$s';
}
tryExec(sprintf($chmCommand, $compressedChm, "'$extractedChm'"));

echo "moving documents from chm to docset..." . PHP_EOL;
tryExec("mkdir -p '$targetBase/Contents/Resources/Documents/www.php.net/manual'");
rename("$extractedChm/res", "$targetBase/Contents/Resources/Documents/www.php.net/manual/en");

echo "copying index database..." . PHP_EOL;
copy("$sourceBase/Contents/Resources/docSet.dsidx", "$targetBase/Contents/Resources/docSet.dsidx");

echo "writing Info.plist..." . PHP_EOL;
file_put_contents("$targetBase/Contents/Info.plist", <<<ENDE
<?xml version="1.0" encoding="UTF-8"?>
<plist version="1.0">
    <dict>
        <key>CFBundleIdentifier</key>
        <string>php-$identifier</string>
        <key>CFBundleName</key>
        <string>PHP</string>
        <key>DocSetPlatformFamily</key>
        <string>php</string>
        <key>dashIndexFilePath</key>
        <string>www.php.net/manual/en/index.html</string>
        <key>DashDocSetFamily</key>
        <string>unsorteddashtoc</string>
        <key>isDashDocset</key>
        <true/>
    </dict>
</plist>
ENDE
);

echo "cleaning up..." . PHP_EOL;
tryExec("rm -rf {$compressedTgz}");
tryExec("rm -rf {$compressedChm}");
tryExec("rm -rf {$sourceBase}");
tryExec("rm -rf {$extractedChm}");

echo "PHP.docset is ready! Have fun!" . PHP_EOL;

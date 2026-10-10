<?php
$files = glob('sys/*.php');
foreach ($files as $file) {
    $content = file_get_contents($file);
    preg_match_all("/(INSERT INTO|UPDATE|SELECT)[^\"';]+(?=[\"';])/i", $content, $matches);
    foreach ($matches[0] as $match) {
        echo basename($file) . ": " . preg_replace("/\s+/", " ", $match) . "\n";
    }
}

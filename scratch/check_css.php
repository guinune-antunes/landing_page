<?php
$css = file_get_contents(__DIR__ . '/../sys/style.css');
$open = substr_count($css, '{');
$close = substr_count($css, '}');
echo "Open: $open, Close: $close\n";

// Also check unclosed comments
$open_comments = substr_count($css, '/*');
$close_comments = substr_count($css, '*/');
echo "Open comments: $open_comments, Close comments: $close_comments\n";


<?php

// A site answering badly on purpose, for BoundedSinkTest (served by php -S).
switch (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) {
    case '/big': // ~50 MB, streamed: far past any cap
        header('Content-Type: text/html; charset=utf-8');
        $chunk = str_repeat("<p>padding padding padding padding</p>\n", 1000);
        for ($i = 0; $i < 1300; $i++) {
            echo $chunk;
            flush();
        }
        break;
    case '/small':
        header('Content-Type: text/html');
        echo '<a href="/next">next</a>';
        break;
    case '/moved':
        header('Location: /small', true, 302);
        break;
    default:
        http_response_code(404);
}

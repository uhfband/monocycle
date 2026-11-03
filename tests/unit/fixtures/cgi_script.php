<?php

switch (getenv('QUERY_STRING')) {
    case 'env':
        header('Content-type: application/json');
        echo json_encode(getenv());
        break;
    case 'body':
        header('Content-type: text/plain');
        echo file_get_contents('php://input');
        break;
    case 'status':
        header('Status: 201 Created');
        header('Set-Cookie: a=1', false);
        header('Set-Cookie: b=2', false);
        echo 'created';
        break;
    case 'redirect':
        header('Location: /elsewhere');
        break;
    case 'sleep':
        sleep(10);
        break;
    case 'stderr':
        // php-cgi defines no STDERR constant
        fwrite(fopen('php://stderr', 'w'), "oops\n");
        header('Content-type: text/plain');
        echo 'with stderr';
        break;
    default:
        header('Content-type: text/html');
        echo 'Hello from CGI';
}

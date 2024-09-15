<?php

// Register the Composer autoloader...
use Codexdelta\App\App;
use Codexdelta\Libs\Exceptions\Handler;
use Codexdelta\Libs\Http\CdxRequest;
use Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;


    require __DIR__ . '/../vendor/autoload.php';

    Handler::setup();

    $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();

    $routesPath = __DIR__ . '/../config/routes.php';

    $loader = new FilesystemLoader(__DIR__ . '/../resources/views/');

    $twig = new Environment($loader, [
        'cache' => false// __DIR__ . '/storage/cache/views_cache',
    ]);

    $request = CdxRequest::capture();
    $response = App::getInstance($routesPath, $twig)->handle($request);
    /** @var Response $response */
    $response->sendContent();
